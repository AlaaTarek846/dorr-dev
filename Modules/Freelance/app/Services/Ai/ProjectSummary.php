<?php

namespace Modules\Freelance\Services\Ai;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceChangeRequest as Change;
use Modules\Freelance\Models\FreelanceConversation;
use Modules\Freelance\Models\FreelanceMessage;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Models\FreelanceProgressReport as Report;
use Modules\Freelance\Models\FreelanceWorkroomDecision as Decision;
use Modules\Freelance\Models\FreelanceWorkroomFile as File;
use Modules\Freelance\Models\FreelanceWorkroomFileVersion as Version;
use Modules\Freelance\Models\FreelanceWorkroomTask as Task;
use Modules\Freelance\Services\Chat\OfferChat;
use Modules\Freelance\Services\Projects\ProjectHealth;
use Modules\User\Models\User;

/**
 * AI Project Manager — the summary (FS-801, light): what happened on a running project in the last days, in a few plain lines for the
 * client — the work done, the decisions, the changes, what is at risk and the next step for each side. DORR AI on the client's DORR AI
 * plan, reading only what the two already share (shared tasks, shared files, agreed decisions, reports, changes, the project chat).
 *
 * It only reads and suggests (FS-806): nothing here writes a task, a decision, an agreement or money. Names, phones and e-mails never
 * reach the model (the sides are "client" and "freelancer"). The numbers are counted by DORR, never by the model; a quiet period costs
 * no AI call, and the same activity is answered from the cache.
 */
class ProjectSummary
{
    public const PERIODS = [1, 7, 30];

    private const MAX_MESSAGES = 40;

    private const MAX_LIST = 5;

    public function __construct(private readonly FreelanceAi $ai, private readonly ProjectHealth $health) {}

    /** @return array<string, mixed> */
    public function for(Agreement $agreement, User $client, int $days, bool $fresh = false): array
    {
        $this->ai->refuseUnlessEntitled($client);
        $agreement->loadMissing('project');
        $since = now()->subDays($days);
        $facts = $this->facts($agreement, $since);
        $base = [
            'days' => $days,
            'facts' => $facts['counts'],
            'health' => $this->health->present($agreement, Task::CLIENT),
        ];
        if ($facts['activity'] === 0) {
            return $base + ['quiet' => true, 'summary' => null, 'generated_at' => null];
        }

        $input = $this->input($agreement, $facts);
        $key = 'freelance:pm-summary:'.$agreement->id.':'.md5($input).':'.app()->getLocale();
        if ($fresh) {
            Cache::forget($key);
        }
        $summary = Cache::remember($key, now()->addHours(6), fn () => $this->ask($client, $input) + ['generated_at' => now()->toIso8601String()]);
        $at = $summary['generated_at'];
        unset($summary['generated_at']);

        return $base + ['quiet' => false, 'summary' => $summary, 'generated_at' => $at];
    }

    /**
     * What the two share on this project since a moment: counts for the screen, and the plain lines the model reads.
     *
     * @return array{counts: array<string, int|null>, activity: int, lines: array<string, list<string>>}
     */
    private function facts(Agreement $a, Carbon $since): array
    {
        $tasks = Task::query()->where('agreement_id', $a->id)->where('scope', Task::SHARED)->get(['title', 'status', 'due_on', 'assignee_side', 'completed_at', 'created_at']);
        $open = $tasks->whereIn('status', [Task::TODO, Task::DOING, Task::BLOCKED]);
        $overdue = $open->filter(fn (Task $t) => $t->due_on !== null && $t->due_on->lt(today()));
        $doneNow = $tasks->filter(fn (Task $t) => $t->status === Task::DONE && $t->completed_at !== null && $t->completed_at->gte($since));
        $newTasks = $tasks->filter(fn (Task $t) => $t->created_at !== null && $t->created_at->gte($since));

        $decisions = Decision::query()->where('agreement_id', $a->id)->where(fn ($q) => $q->where('created_at', '>=', $since)->orWhere('answered_at', '>=', $since))
            ->latest('id')->limit(10)->get(['title', 'body', 'status', 'proposed_by_side']);
        $fileIds = File::query()->where('agreement_id', $a->id)->where('scope', Task::SHARED)->pluck('title', 'id');
        $versions = Version::query()->whereIn('file_id', $fileIds->keys())->where('created_at', '>=', $since)->latest('id')->limit(10)->get(['file_id', 'number', 'note', 'created_by_side']);
        $reports = Report::query()->where('agreement_id', $a->id)->where('created_at', '>=', $since)->latest('id')->limit(5)->get(['note', 'percent', 'created_at']);
        $lastPercent = Report::query()->where('agreement_id', $a->id)->whereNotNull('percent')->latest('id')->value('percent');
        $changes = Change::query()->where('agreement_id', $a->id)->where(fn ($q) => $q->where('created_at', '>=', $since)->orWhere('answered_at', '>=', $since))
            ->latest('id')->limit(5)->get(['type', 'requested_by', 'reason', 'status']);
        $parts = Milestone::query()->where('agreement_id', $a->id)->orderBy('position')->get(['title', 'status', 'started_at', 'duration_days', 'approved_at', 'delivered_at']);

        $room = FreelanceConversation::query()->where('context_type', OfferChat::CONTEXT)->where('context_id', $a->project_id)->where('freelancer_id', $a->freelancer_id)->first();
        $messages = $room === null ? collect() : FreelanceMessage::query()->where('conversation_id', $room->id)->where('created_at', '>=', $since)
            ->whereNull('deleted_at')->where('kind', 'text')->latest('id')->limit(self::MAX_MESSAGES)->get(['sender_type', 'body'])->reverse()->values();

        $line = fn (?string $text, int $max = 300) => Str::limit(FreelanceAi::scrub(trim(strip_tags((string) $text))), $max, '…');
        $partDone = $parts->filter(fn (Milestone $m) => $m->approved_at !== null)->count();

        return [
            'counts' => [
                'tasks_open' => $open->count(),
                'tasks_overdue' => $overdue->count(),
                'tasks_done' => $doneNow->count(),
                'tasks_new' => $newTasks->count(),
                'decisions' => $decisions->count(),
                'files' => $versions->count(),
                'reports' => $reports->count(),
                'percent' => $lastPercent === null ? null : (int) $lastPercent,
                'changes' => $changes->count(),
                'messages' => $messages->count(),
                'parts' => $parts->count(),
                'parts_done' => $partDone,
            ],
            'activity' => $doneNow->count() + $newTasks->count() + $decisions->count() + $versions->count() + $reports->count() + $changes->count() + $messages->count(),
            'lines' => [
                'parts' => $parts->map(fn (Milestone $m) => $line($m->title, 120).' — '.$m->status->value)->all(),
                'tasks_done' => $doneNow->take(10)->map(fn (Task $t) => $line($t->title, 150))->values()->all(),
                'tasks_overdue' => $overdue->take(10)->map(fn (Task $t) => $line($t->title, 150).' (due '.$t->due_on->toDateString().', on the '.$t->assignee_side.')')->values()->all(),
                'tasks_blocked' => $open->where('status', Task::BLOCKED)->take(5)->map(fn (Task $t) => $line($t->title, 150))->values()->all(),
                'decisions' => $decisions->map(fn (Decision $d) => $line($d->title, 150).' ['.$d->status.', proposed by the '.$d->proposed_by_side.']'.($d->body ? ': '.$line($d->body, 200) : ''))->all(),
                'files' => $versions->map(fn (Version $v) => $line((string) ($fileIds[$v->file_id] ?? ''), 120).' v'.$v->number.' (by the '.$v->created_by_side.')'.($v->note ? ': '.$line($v->note, 150) : ''))->all(),
                'reports' => $reports->map(fn (Report $r) => ($r->percent !== null ? $r->percent.'% — ' : '').$line($r->note, 400))->all(),
                'changes' => $changes->map(fn (Change $c) => $c->type.' asked by the '.$c->requested_by.' ['.$c->status.']'.($c->reason ? ': '.$line($c->reason, 200) : ''))->all(),
                'chat' => $messages->map(fn (FreelanceMessage $m) => $m->sender_type.': '.$line($m->body, 300))->all(),
            ],
        ];
    }

    private function input(Agreement $a, array $facts): string
    {
        $out = ['Project: '.Str::limit(FreelanceAi::scrub((string) $a->project->title), 150)];
        $health = $this->health->evaluate($a);
        if ($health !== null) {
            $out[] = 'Health (DORR rules): '.$health['state'].($health['reasons'] === [] ? '' : ' — '.implode(', ', array_column($health['reasons'], 'code')));
        }
        if (($facts['counts']['percent'] ?? null) !== null) {
            $out[] = 'Last progress the freelancer reported: '.$facts['counts']['percent'].'%';
        }
        foreach ($facts['lines'] as $title => $lines) {
            if ($lines !== []) {
                $out[] = "\n".strtoupper(str_replace('_', ' ', $title)).":\n- ".implode("\n- ", $lines);
            }
        }

        return implode("\n", $out);
    }

    /** @return array<string, mixed> */
    private function ask(User $client, string $input): array
    {
        $lang = app()->getLocale() === 'ar' ? 'Arabic (simple, friendly, Egyptian-leaning wording is fine)' : 'English';
        $system = <<<PROMPT
You are DORR AI, the project manager's assistant on DORR's freelance platform. The client of a running project asks what happened lately.
Write in {$lang}. Be short, concrete and neutral: a summary, never a judgement of a person. Use only the facts given; never invent work,
dates or amounts. You only suggest: never say money will be released, a dispute decided, or the agreement changed.
If the chat asks for something outside the agreed parts, mention it under "changes" as a possible change request (the client decides).
Answer with JSON only:
{"headline": "one sentence", "progress": ["what got done"], "decisions": ["what was agreed or is waiting"],
 "changes": ["changes asked or possible out-of-scope asks"], "risks": ["what may slip and why"],
 "next_client": ["the client's next steps"], "next_freelancer": ["what the client waits for from the freelancer"]}
Each list has at most 5 short items; an empty list when there is nothing.
PROMPT;
        $out = $this->ai->ask($client, $system, $input, 'freelance.project_summary');
        $text = fn ($v, int $max) => is_string($v) && trim($v) !== '' ? Str::limit(trim(strip_tags($v)), $max, '') : null;
        $list = fn ($v) => collect(is_array($v) ? $v : [])->map(fn ($i) => $text($i, 300))->filter()->take(self::MAX_LIST)->values()->all();

        return [
            'headline' => $text($out['headline'] ?? null, 300),
            'progress' => $list($out['progress'] ?? null),
            'decisions' => $list($out['decisions'] ?? null),
            'changes' => $list($out['changes'] ?? null),
            'risks' => $list($out['risks'] ?? null),
            'next_client' => $list($out['next_client'] ?? null),
            'next_freelancer' => $list($out['next_freelancer'] ?? null),
        ];
    }
}
