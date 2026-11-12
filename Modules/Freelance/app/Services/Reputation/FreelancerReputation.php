<?php

namespace Modules\Freelance\Services\Reputation;

use App\Services\General\AuditLogger;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceMilestoneStatus as Status;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerSkillBadge as Badge;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Projects\FreelanceReviews;
use Modules\Freelance\Services\Skills\FreelanceSkillBadges;

/**
 * FS-604 (owner 2026-11-12): a freelancer's reputation as six figures, each with where it comes from, shown to clients and to the freelancer
 * (the internal QualityScore stays staff-only, FL-707). Only the person's own work counts — a team's work is the team's (D3).
 *
 *  - quality, communication: the clients' scores for those criteria (published ratings, 3 at least), out of 100.
 *  - delivery: the share of approved parts delivered within their days (days added by an accepted change count — FL-404; a part only starts
 *    once the client funds it, so a client's slow funding never makes it late).
 *  - completion: of the projects that ended, the share finished — a project the client ended, or nobody's fault, is left out; only an end that
 *    was the freelancer's responsibility (FL-607) counts against them. A project still running or disputed and undecided never counts.
 *  - repeat_clients: of the clients the freelancer finished a project for, the share who came back for another.
 *  - technical: the levels of the skill certifications held (FS-602), the best three — with the verified works (FS-601) named as its source.
 *
 * A figure with too little behind it says «new» (null). A freelancer may ask staff to correct the data of one project behind delivery or
 * completion (a reason and a note); an accepted request takes the project out of that figure. Ratings are corrected the way they already are
 * (FL-706 objections), so they are not asked about here.
 */
class FreelancerReputation
{
    public const INDICATORS = ['quality', 'delivery', 'communication', 'completion', 'repeat_clients', 'technical'];

    /** The figures that may be corrected one project at a time. */
    public const CORRECTABLE = ['delivery', 'completion'];

    public const REASONS = ['client_delay', 'client_ended', 'scope_changed', 'wrong_data', 'other'];

    /** The fewest pieces of data behind a figure. */
    public const MIN = 3;

    public const OPEN_MAX = 5;

    public const ASKED = 'freelance.reputation.correction_asked';

    public const DECIDED = 'freelance.reputation.correction_decided';

    private const LEVEL_POINTS = [Badge::BEGINNER => 40, Badge::INTERMEDIATE => 60, Badge::ADVANCED => 80, Badge::EXPERT => 100];

    public function __construct(
        private readonly FreelanceReviews $reviews,
        private readonly FreelanceSkillBadges $badges,
        private readonly FreelanceNotifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The six figures. The freelancer (`$owner`) also gets, for delivery and completion, the projects behind them with their outcome and any
     * correction asked, so they can point at one that is wrong.
     *
     * @return array{indicators: list<array<string, mixed>>, overall: ?int}
     */
    public function of(Freelancer $f, bool $owner = false): array
    {
        $stats = $this->reviews->stats($f);
        $criteria = $stats['criteria'] ?? [];
        $excluded = $this->excluded($f);
        $delivery = $this->delivery($f, $excluded['delivery']);
        $completion = $this->completion($f, $excluded['completion']);
        $repeat = $this->repeatClients($f);
        $technical = $this->technical($f);

        $fromRatings = fn (string $criterion) => [
            'value' => isset($criteria[$criterion]) ? (int) round($criteria[$criterion] * 20) : null,
            'source' => ['kind' => 'ratings', 'count' => (int) $stats['reviews_count']],
        ];
        $rows = [
            'quality' => $fromRatings('quality'),
            'delivery' => ['value' => $delivery['value'], 'source' => ['kind' => 'parts', 'count' => $delivery['count'], 'hits' => $delivery['hits'], 'excluded' => count($excluded['delivery'])]],
            'communication' => $fromRatings('communication'),
            'completion' => ['value' => $completion['value'], 'source' => ['kind' => 'projects', 'count' => $completion['count'], 'hits' => $completion['hits'], 'excluded' => $completion['left_out'] + count($excluded['completion'])]],
            'repeat_clients' => ['value' => $repeat['value'], 'source' => ['kind' => 'clients', 'count' => $repeat['count'], 'hits' => $repeat['hits']]],
            'technical' => ['value' => $technical['value'], 'source' => ['kind' => 'certifications', 'count' => $technical['count'], 'best' => $technical['best'], 'verified_works' => $technical['verified_works']]],
        ];

        $out = [];
        foreach (self::INDICATORS as $key) {
            $row = ['key' => $key, 'value' => $rows[$key]['value'], 'level' => self::level($rows[$key]['value']), 'source' => $rows[$key]['source']];
            if ($owner && in_array($key, self::CORRECTABLE, true)) {
                $row['projects'] = $key === 'delivery' ? $delivery['projects'] : $completion['projects'];
            }
            $out[] = $row;
        }
        $known = array_filter(array_column($out, 'value'), fn ($v) => $v !== null);

        return ['indicators' => $out, 'overall' => count($known) >= 3 ? (int) round(array_sum($known) / count($known)) : null];
    }

    /** excellent (85+) · good (70+) · fair (50+) · low · new (not enough data). */
    public static function level(?int $value): string
    {
        return match (true) {
            $value === null => 'new',
            $value >= 85 => 'excellent',
            $value >= 70 => 'good',
            $value >= 50 => 'fair',
            default => 'low',
        };
    }

    // -------------------------------------------------------------- corrections

    /** @param  array{agreement: string, indicator: string, reason: string, note: string}  $data */
    public function ask(Freelancer $f, array $data): object
    {
        $agreement = Agreement::query()->where('uuid', $data['agreement'])->where('freelancer_id', $f->id)->whereNull('team_id')->first();
        $counted = $agreement === null ? [] : (collect($this->of($f, true)['indicators'])->firstWhere('key', $data['indicator'])['projects'] ?? []);
        if ($agreement === null || ! collect($counted)->contains(fn ($p) => $p['agreement'] === $agreement->uuid && ! $p['excluded'])) {
            throw new FreelanceException('reputation_not_yours', 422);
        }

        return DB::transaction(function () use ($f, $agreement, $data) {
            Freelancer::query()->whereKey($f->id)->lockForUpdate()->first();
            $open = DB::table('freelance_reputation_corrections')->where('freelancer_id', $f->id)->where('status', 'open');
            if ((clone $open)->where('agreement_id', $agreement->id)->where('indicator', $data['indicator'])->exists()) {
                throw new FreelanceException('reputation_open', 409);
            }
            if ((clone $open)->count() >= self::OPEN_MAX) {
                throw new FreelanceException('reputation_limit', 422, ['max' => self::OPEN_MAX], ['max' => self::OPEN_MAX]);
            }
            $uuid = (string) \Illuminate\Support\Str::uuid();
            $id = DB::table('freelance_reputation_corrections')->insertGetId([
                'uuid' => $uuid, 'freelancer_id' => $f->id, 'agreement_id' => $agreement->id, 'indicator' => $data['indicator'], 'reason' => $data['reason'],
                'note' => mb_substr(trim(strip_tags($data['note'])), 0, 1000), 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('freelance_reputation_corrections')->find($id);
            $this->audit->record(self::ASKED, $f, null, ['correction' => $uuid, 'agreement' => $agreement->uuid, 'indicator' => $data['indicator']]);

            return $row;
        });
    }

    /** @return list<array<string, mixed>> my correction requests, newest first */
    public function mine(Freelancer $f): array
    {
        return $this->rows(DB::table('freelance_reputation_corrections as c')->where('c.freelancer_id', $f->id)->orderByDesc('c.id')->limit(50)->get(), false);
    }

    /** @return array{0: LengthAwarePaginator, 1: array<string, int>} staff's queue (open first, oldest first) and the counts by state */
    public function queue(?string $status, ?string $search, int $perPage): array
    {
        $q = DB::table('freelance_reputation_corrections as c')->join('freelancers as f', 'f.id', '=', 'c.freelancer_id')
            ->when($status !== null, fn ($b) => $b->where('c.status', $status))
            ->when($search !== null && trim($search) !== '', fn ($b) => $b->where(fn ($w) => $w->where('f.name', 'like', '%'.addcslashes(trim((string) $search), '%_\\').'%')
                ->orWhere('f.phone', 'like', '%'.addcslashes(trim((string) $search), '%_\\').'%')))
            ->orderByRaw("case c.status when 'open' then 0 else 1 end")->orderByRaw("case when c.status = 'open' then c.created_at end asc")
            ->orderByDesc('c.decided_at')->orderByDesc('c.id')->select('c.*');
        $page = $q->paginate($perPage);
        $page->setCollection(collect($this->rows($page->getCollection(), true)));
        $counts = DB::table('freelance_reputation_corrections')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return [$page, ['open' => (int) ($counts['open'] ?? 0), 'accepted' => (int) ($counts['accepted'] ?? 0), 'rejected' => (int) ($counts['rejected'] ?? 0), 'all' => (int) $counts->sum()]];
    }

    /** Staff decide: `accept` takes the project out of the figure; `reject` needs a note. 409 when already decided. */
    public function decide(string $uuid, int $adminId, string $decision, ?string $note): array
    {
        $row = DB::transaction(function () use ($uuid, $adminId, $decision, $note) {
            $row = DB::table('freelance_reputation_corrections')->where('uuid', $uuid)->lockForUpdate()->first() ?? throw new \Illuminate\Database\Eloquent\ModelNotFoundException;
            if ($row->status !== 'open') {
                throw new FreelanceException('reputation_decided', 409);
            }
            $note = $note === null ? null : (trim(strip_tags($note)) ?: null);
            if ($decision === 'reject' && $note === null) {
                throw new FreelanceException('reputation_note_required', 422);
            }
            DB::table('freelance_reputation_corrections')->where('id', $row->id)->update([
                'status' => $decision === 'accept' ? 'accepted' : 'rejected', 'decision_note' => $note === null ? null : mb_substr($note, 0, 1000),
                'decided_by_admin_id' => $adminId, 'decided_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record(self::DECIDED, Freelancer::query()->withTrashed()->findOrFail($row->freelancer_id), null, ['correction' => $uuid, 'decision' => $decision, 'by_admin_id' => $adminId]);

            return DB::table('freelance_reputation_corrections')->find($row->id);
        });
        $f = Freelancer::query()->find($row->freelancer_id);
        if ($f !== null) {
            $this->notifier->notify($f, $row->status === 'accepted' ? 'reputation.corrected' : 'reputation.kept', [], ['screen' => 'reputation']);
        }

        return $this->rows(collect([$row]), true)[0];
    }

    // -------------------------------------------------------------- the figures

    /** @return array{delivery: list<int>, completion: list<int>} the agreements staff took out of each figure */
    private function excluded(Freelancer $f): array
    {
        $rows = DB::table('freelance_reputation_corrections')->where('freelancer_id', $f->id)->where('status', 'accepted')->get(['agreement_id', 'indicator']);

        return [
            'delivery' => $rows->where('indicator', 'delivery')->pluck('agreement_id')->map(fn ($v) => (int) $v)->unique()->values()->all(),
            'completion' => $rows->where('indicator', 'completion')->pluck('agreement_id')->map(fn ($v) => (int) $v)->unique()->values()->all(),
        ];
    }

    /** @param  list<int>  $excluded */
    private function delivery(Freelancer $f, array $excluded): array
    {
        $parts = Milestone::query()->where('status', Status::Approved->value)->whereNotNull('duration_days')->whereNotNull('started_at')->whereNotNull('delivered_at')
            ->whereHas('agreement', fn ($q) => $q->where('freelancer_id', $f->id)->whereNull('team_id'))
            ->with('agreement:id,uuid,project_id', 'agreement.project:id,title')->orderByDesc('delivered_at')->get();
        $counted = $parts->reject(fn (Milestone $m) => in_array((int) $m->agreement_id, $excluded, true));
        $onTime = fn (Milestone $m) => $m->delivered_at->lte($m->started_at->copy()->addDays((int) $m->duration_days));
        $hits = $counted->filter($onTime)->count();

        $projects = $parts->groupBy('agreement_id')->map(function (Collection $ms) use ($onTime, $excluded) {
            $a = $ms->first()->agreement;

            return [
                'agreement' => $a?->uuid, 'title' => (string) $a?->project?->title, 'parts' => $ms->count(), 'late' => $ms->reject($onTime)->count(),
                'outcome' => $ms->reject($onTime)->isEmpty() ? 'on_time' : 'late', 'excluded' => in_array((int) $ms->first()->agreement_id, $excluded, true),
            ];
        })->values()->take(30)->all();

        return ['value' => $counted->count() >= self::MIN ? (int) round($hits * 100 / $counted->count()) : null, 'count' => $counted->count(), 'hits' => $hits, 'projects' => $projects];
    }

    /** @param  list<int>  $excluded */
    private function completion(Freelancer $f, array $excluded): array
    {
        $ended = Agreement::query()->where('freelancer_id', $f->id)->whereNull('team_id')->whereNotNull('activated_at')
            ->whereIn('status', [Agreement::COMPLETED, Agreement::CANCELLED])->with('project:id,title')->latest('id')->get();
        // Who an early end was on (FL-607): only the freelancer's own counts against them.
        $mine = DB::table('freelance_cancellation_reasons')->whereIn('agreement_id', $ended->pluck('id'))->whereNull('milestone_id')
            ->where('responsibility', 'freelancer')->pluck('agreement_id')->map(fn ($v) => (int) $v)->all();
        $counted = $ended->filter(fn (Agreement $a) => ! in_array((int) $a->id, $excluded, true)
            && ($a->status === Agreement::COMPLETED || in_array((int) $a->id, $mine, true)));
        $hits = $counted->where('status', Agreement::COMPLETED)->count();
        $leftOut = $ended->count() - $counted->count() - count(array_intersect($ended->pluck('id')->map(fn ($v) => (int) $v)->all(), $excluded));

        $projects = $ended->filter(fn (Agreement $a) => $a->status === Agreement::COMPLETED || in_array((int) $a->id, $mine, true))->take(30)->map(fn (Agreement $a) => [
            'agreement' => $a->uuid, 'title' => (string) $a->project?->title,
            'outcome' => $a->status === Agreement::COMPLETED ? 'completed' : 'ended_by_me', 'excluded' => in_array((int) $a->id, $excluded, true),
        ])->values()->all();

        return ['value' => $counted->count() >= self::MIN ? (int) round($hits * 100 / $counted->count()) : null, 'count' => $counted->count(), 'hits' => $hits, 'left_out' => max(0, $leftOut), 'projects' => $projects];
    }

    private function repeatClients(Freelancer $f): array
    {
        $byClient = Agreement::query()->where('freelancer_id', $f->id)->whereNull('team_id')->where('status', Agreement::COMPLETED)
            ->selectRaw('client_id, count(*) as n')->groupBy('client_id')->pluck('n', 'client_id');
        $clients = $byClient->count();
        $back = $byClient->filter(fn ($n) => (int) $n >= 2)->count();

        return ['value' => $clients >= self::MIN ? (int) round($back * 100 / $clients) : null, 'count' => $clients, 'hits' => $back];
    }

    private function technical(Freelancer $f): array
    {
        $badges = $this->badges->of($f, PHP_INT_MAX);
        $best = array_slice($badges, 0, 3);
        $verified = $f->portfolioItems()->whereNull('team_id')->whereNotNull('agreement_id')->whereNull('verified_revoked_at')->whereNull('hidden_by_admin_at')
            ->whereHas('agreement', fn ($a) => $a->where('status', Agreement::COMPLETED)->where('portfolio_allowed', true))->count();

        return [
            'value' => $best === [] ? null : (int) round(array_sum(array_map(fn ($b) => self::LEVEL_POINTS[$b['level']] ?? 40, $best)) / count($best)),
            'count' => count($badges),
            'best' => array_map(fn ($b) => ['name' => $b['name'], 'level' => $b['level']], $best),
            'verified_works' => $verified,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rows(Collection $rows, bool $staff): array
    {
        $agreements = Agreement::query()->whereIn('id', $rows->pluck('agreement_id'))->with('project:id,uuid,title')->get()->keyBy('id');
        $people = $staff ? Freelancer::query()->withTrashed()->whereIn('id', $rows->pluck('freelancer_id'))->get(['id', 'uuid', 'name', 'phone'])->keyBy('id') : collect();

        return $rows->map(function ($r) use ($agreements, $people, $staff) {
            $a = $agreements->get($r->agreement_id);

            return [
                'uuid' => $r->uuid, 'indicator' => $r->indicator, 'reason' => $r->reason, 'note' => $r->note, 'status' => $r->status,
                'decision_note' => $r->decision_note, 'sent_at' => \Illuminate\Support\Carbon::parse($r->created_at)->toIso8601String(),
                'decided_at' => $r->decided_at === null ? null : \Illuminate\Support\Carbon::parse($r->decided_at)->toIso8601String(),
                'project' => $a === null ? null : ['agreement' => $a->uuid, 'uuid' => $a->project?->uuid, 'title' => (string) $a->project?->title],
            ] + ($staff ? ['freelancer' => ($p = $people->get($r->freelancer_id)) === null ? null : ['id' => $p->id, 'uuid' => $p->uuid, 'name' => $p->name, 'phone' => $p->phone]] : []);
        })->values()->all();
    }
}
