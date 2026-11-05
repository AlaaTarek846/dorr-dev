<?php

namespace Modules\Freelance\Services\Projects;

use Modules\Freelance\Enums\FreelanceMilestoneStatus as MStatus;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceChangeRequest as Change;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;

/**
 * FS-106: how a running project is doing - healthy, at risk, delayed or blocked - from what is already on record (dates, funding,
 * deliveries, reports, changes, disputes). Plain rules, no guessing: every state carries its reasons and one next step for each side.
 * It only informs - nothing here moves money or punishes anyone; a freelancer's or client's score never reads it.
 *
 * Levels, worst first: blocked (a person must step in) > delayed (a date has passed) > at_risk (a date is near, or someone is slow).
 */
class ProjectHealth
{
    public const HEALTHY = 'healthy';

    public const AT_RISK = 'at_risk';

    public const DELAYED = 'delayed';

    public const BLOCKED = 'blocked';

    private const RANK = [self::HEALTHY => 0, self::AT_RISK => 1, self::DELAYED => 2, self::BLOCKED => 3];

    /** A part due within this many hours is an early warning. */
    public const DUE_SOON_HOURS = 48;

    /** A part funded and still not started after this many hours. */
    public const NOT_STARTED_HOURS = 48;

    /** A delivery nobody looked at for this many hours. */
    public const REVIEW_WAITING_HOURS = 72;

    /** The next part not paid for, with nothing else going on, for this many hours. */
    public const UNFUNDED_HOURS = 72;

    /** A change request nobody answered for this many hours. */
    public const CHANGE_WAITING_HOURS = 48;

    public function __construct(private readonly FreelanceNotifier $notifier) {}

    public static function worse(string $a, string $than): bool
    {
        return (self::RANK[$a] ?? 0) > (self::RANK[$than] ?? 0);
    }

    /**
     * @return array{state: string, reasons: list<array{code: string, level: string, who: string, data: array<string, mixed>}>}|null null: the agreement is not running
     */
    public function evaluate(Agreement $a): ?array
    {
        if ($a->status !== Agreement::ACTIVE) {
            return null;
        }
        $parts = Milestone::query()->where('agreement_id', $a->id)->orderBy('position')->get();
        $open = $parts->filter(fn (Milestone $m) => ! $m->status->settled())->values();
        $reasons = [];
        $add = function (string $code, string $level, string $who, array $data = []) use (&$reasons): void {
            $reasons[] = ['code' => $code, 'level' => $level, 'who' => $who, 'data' => $data];
        };

        foreach ($open as $m) {
            $title = (string) $m->title;
            if ($m->status === MStatus::Disputed) {
                $add('dispute_open', self::BLOCKED, 'both', ['part' => $title]);

                continue;
            }
            if (in_array($m->status, [MStatus::Funded, MStatus::InProgress, MStatus::Revision], true)) {
                $due = $m->started_at !== null && $m->duration_days !== null ? $m->started_at->copy()->addDays((int) $m->duration_days) : null;
                if ($due !== null && $due->isPast()) {
                    $add('part_overdue', self::DELAYED, 'freelancer', ['part' => $title, 'due_at' => $due->toIso8601String(), 'days' => max(1, (int) $due->diffInDays(now()))]);
                } elseif ($due !== null && $due->lte(now()->addHours(self::DUE_SOON_HOURS))) {
                    $add('part_due_soon', self::AT_RISK, 'freelancer', ['part' => $title, 'due_at' => $due->toIso8601String()]);
                }
                if ($m->status === MStatus::Funded && $m->started_at === null && $m->funded_at !== null && $m->funded_at->copy()->addHours(self::NOT_STARTED_HOURS)->isPast()) {
                    $add('part_not_started', self::AT_RISK, 'freelancer', ['part' => $title]);
                }
            }
            if ($m->status === MStatus::Delivered && $m->delivered_at !== null && $m->delivered_at->copy()->addHours(self::REVIEW_WAITING_HOURS)->isPast()) {
                $add('review_waiting', self::AT_RISK, 'client', ['part' => $title, 'days' => (int) $m->delivered_at->diffInDays(now())]);
            }
        }

        // The next part waits for the client's money while nothing else is going on.
        $first = $open->first();
        if ($first !== null && $first->status === MStatus::Unfunded && $open->every(fn (Milestone $m) => $m->status === MStatus::Unfunded)) {
            $since = $parts->filter(fn (Milestone $m) => $m->approved_at !== null)->max('approved_at') ?? $a->activated_at;
            if ($since !== null && $since->copy()->addHours(self::UNFUNDED_HOURS)->isPast()) {
                $add('part_unfunded', self::BLOCKED, 'client', ['part' => (string) $first->title]);
            }
        }

        if ($a->report_every_days !== null && $a->next_report_due_at !== null && $a->next_report_due_at->isPast()) {
            $add('report_late', self::AT_RISK, 'freelancer', ['due_at' => $a->next_report_due_at->toIso8601String()]);
        }

        $change = Change::query()->where('agreement_id', $a->id)->where('status', Change::PENDING)->oldest('id')->first();
        if ($change !== null && $change->open() && $change->created_at !== null && $change->created_at->copy()->addHours(self::CHANGE_WAITING_HOURS)->isPast()) {
            $add('change_waiting', self::AT_RISK, $change->requested_by === 'client' ? 'freelancer' : 'client', []);
        }

        usort($reasons, fn (array $x, array $y) => self::RANK[$y['level']] <=> self::RANK[$x['level']]);
        $state = $reasons === [] ? self::HEALTHY : $reasons[0]['level'];

        return ['state' => $state, 'reasons' => $reasons];
    }

    /**
     * What an agreement shows (null when it is not running): the state, the reasons, and the one thing this side can do now - else what
     * it waits for.
     *
     * @return array<string, mixed>|null
     */
    public function present(Agreement $a, string $side): ?array
    {
        $health = $this->evaluate($a);
        if ($health === null) {
            return null;
        }
        $reasons = array_map(fn (array $r) => $r + ['mine' => $r['who'] === 'both' || $r['who'] === $side], $health['reasons']);
        $mine = collect($reasons)->firstWhere('mine', true);
        $step = $mine ?? ($reasons[0] ?? null);

        return [
            'state' => $health['state'],
            'reasons' => array_map(fn (array $r) => ['code' => $r['code'], 'level' => $r['level'], 'mine' => $r['mine']] + ($r['data'] === [] ? [] : ['data' => $r['data']]), $reasons),
            'next_step' => $step === null ? null : ['code' => $step['code'], 'mine' => $step['mine']],
        ];
    }

    /**
     * Hourly: tells who has to act, once, when a project gets worse than the alerts last saw it (a delayed or blocked one tells the other
     * side too, so nobody is surprised). A better state is only recorded, so a later setback is told again.
     *
     * @return int how many notifications were sent
     */
    public function alertWorse(): int
    {
        $sent = 0;
        Agreement::query()->where('status', Agreement::ACTIVE)->with(['project:id,uuid,title', 'client', 'freelancer'])->each(function (Agreement $a) use (&$sent): void {
            $health = $this->evaluate($a);
            $state = $health['state'] ?? self::HEALTHY;
            $before = (string) ($a->health_state ?: self::HEALTHY);
            if ($state === $before) {
                return;
            }
            $a->forceFill(['health_state' => $state] + (self::worse($state, $before) ? ['health_alerted_at' => now()] : []))->save();
            if (! self::worse($state, $before) || $health === null) {
                return;
            }
            $who = collect($health['reasons'])->where('level', $state)->pluck('who')->unique()->values()->all();
            $both = in_array('both', $who, true) || $state !== self::AT_RISK;
            $vars = ['project' => (string) $a->project->title];
            $data = ['project' => $a->project->uuid, 'agreement' => $a->uuid, 'health' => $state];
            if ($both || in_array('freelancer', $who, true)) {
                $this->notifier->notify($a->freelancer, 'health.'.$state, $vars, $data);
                $sent++;
            }
            if ($both || in_array('client', $who, true)) {
                $this->notifier->notify($a->client, 'health.'.$state, $vars, $data);
                $sent++;
            }
        });

        return $sent;
    }
}
