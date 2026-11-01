<?php

namespace Modules\Freelance\Services\Projects;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceCancelReason as Reason;
use Modules\Freelance\Models\FreelanceCancellationReason as Row;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceProject;

/**
 * Why work was called off, and who that points at (FL-607). The reason is the canceller's own word ("as stated"); the responsibility is
 * derived from it (see the enum) and is for staff only. Nothing is charged, refused or changed because of it. A call-off without a
 * code is kept as "other" (staff look at it).
 */
class CancelReasons
{
    /** Records the reason for a project (milestone null) or a part. */
    public function record(FreelanceProject $project, ?FreelanceMilestone $milestone, string $actorType, int $actorId, ?string $code, ?string $note): Row
    {
        $reason = ($code === null ? null : Reason::tryFrom($code)) ?? Reason::Other;
        $note = $note === null ? null : (mb_substr(trim(strip_tags($note)), 0, 500) ?: null);

        return Row::query()->create([
            'project_id' => $project->id, 'agreement_id' => $milestone?->agreement_id, 'milestone_id' => $milestone?->id,
            'actor_type' => $actorType, 'actor_id' => $actorId, 'reason_code' => $reason->value, 'responsibility' => $reason->responsibility(), 'note' => $note,
        ]);
    }

    /**
     * For staff: how many call-offs per responsibility and per reason in the period, and the latest ones.
     *
     * @return array<string, mixed>
     */
    public function summary(Carbon $from, Carbon $to, ?string $responsibility, int $limit = 50): array
    {
        $base = Row::query()->whereBetween('created_at', [$from, $to]);
        $by = fn (string $col) => (clone $base)->select($col, DB::raw('count(*) as total'))->groupBy($col)->pluck('total', $col)->map(fn ($n) => (int) $n)->all();
        $byResponsibility = $by('responsibility');
        $byReason = $by('reason_code');

        $rows = (clone $base)->when($responsibility, fn ($q) => $q->where('responsibility', $responsibility))->with(['project:id,uuid,title', 'milestone:id,uuid,title'])
            ->orderByDesc('id')->limit(max(1, min(200, $limit)))->get()->map(fn (Row $r) => [
                'id' => $r->id, 'project' => $r->project ? ['uuid' => $r->project->uuid, 'title' => (string) $r->project->title] : null,
                'milestone' => $r->milestone ? ['uuid' => $r->milestone->uuid, 'title' => (string) $r->milestone->title] : null,
                'by' => $r->actor_type, 'reason_code' => $r->reason_code, 'responsibility' => $r->responsibility, 'note' => $r->note,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->all();

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'total' => array_sum($byResponsibility),
            'by_responsibility' => array_map(fn (string $k) => ['key' => $k, 'count' => $byResponsibility[$k] ?? 0], Reason::responsibilities()),
            'by_reason' => array_map(fn (Reason $c) => ['key' => $c->value, 'responsibility' => $c->responsibility(), 'count' => $byReason[$c->value] ?? 0], Reason::cases()),
            'rows' => $rows,
        ];
    }
}
