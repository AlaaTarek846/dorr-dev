<?php

namespace Modules\Freelance\Services\Reports;

use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceDispute;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceProject;

/**
 * What is late (FL-802), worked out from the dates already kept: parts past their days, delivered parts waiting for the client,
 * agreements nobody answered, disputes past their response time, projects waiting for review. Nothing is changed.
 */
class FreelanceLateWork
{
    private const REVIEW_WAIT_DAYS = 2;

    private const DELIVERY_WAIT_DAYS = 5;

    private static function value(mixed $status): string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function groups(): array
    {
        $now = now();

        $parts = FreelanceMilestone::query()->whereIn('status', ['funded', 'in_progress', 'revision'])->whereNotNull('started_at')->whereNotNull('duration_days')
            ->with('project:id,uuid,title')->get()
            ->filter(fn (FreelanceMilestone $m) => $m->started_at->copy()->addDays((int) $m->duration_days)->isPast())
            ->map(fn (FreelanceMilestone $m) => [
                'project' => $m->project?->uuid, 'title' => $m->project?->title, 'part' => $m->title, 'status' => self::value($m->status),
                'due_at' => $m->started_at->copy()->addDays((int) $m->duration_days)->toIso8601String(),
                'days_late' => (int) $m->started_at->copy()->addDays((int) $m->duration_days)->diffInDays($now),
            ])->sortByDesc('days_late')->values()->all();

        $waiting = FreelanceMilestone::query()->where('status', 'delivered')->whereNotNull('delivered_at')->where('delivered_at', '<', $now->copy()->subDays(self::DELIVERY_WAIT_DAYS))
            ->with('project:id,uuid,title')->orderBy('delivered_at')->get()
            ->map(fn (FreelanceMilestone $m) => [
                'project' => $m->project?->uuid, 'title' => $m->project?->title, 'part' => $m->title,
                'delivered_at' => $m->delivered_at->toIso8601String(), 'days_waiting' => (int) $m->delivered_at->diffInDays($now),
            ])->values()->all();

        $agreements = FreelanceAgreement::query()->where('status', 'pending_approval')->whereNotNull('expires_at')->where('expires_at', '<', $now)
            ->with('project:id,uuid,title')->orderBy('expires_at')->get()
            ->map(fn (FreelanceAgreement $a) => ['project' => $a->project?->uuid, 'title' => $a->project?->title, 'agreement' => $a->uuid, 'expired_at' => $a->expires_at->toIso8601String()])->values()->all();

        $disputes = FreelanceDispute::query()->where('status', FreelanceDispute::OPEN)->whereNotNull('respond_by')->where('respond_by', '<', $now)
            ->with('project:id,uuid,title')->orderBy('respond_by')->get()
            ->map(fn (FreelanceDispute $d) => ['project' => $d->project?->uuid, 'title' => $d->project?->title, 'dispute' => $d->uuid, 'respond_by' => $d->respond_by->toIso8601String()])->values()->all();

        $review = FreelanceProject::query()->where('status', 'pending_review')->whereNotNull('submitted_at')->where('submitted_at', '<', $now->copy()->subDays(self::REVIEW_WAIT_DAYS))
            ->orderBy('submitted_at')->get()
            ->map(fn (FreelanceProject $p) => ['project' => $p->uuid, 'title' => $p->title, 'submitted_at' => $p->submitted_at->toIso8601String(), 'days_waiting' => (int) $p->submitted_at->diffInDays($now)])->values()->all();

        return ['parts_late' => $parts, 'deliveries_waiting' => $waiting, 'agreements_unanswered' => $agreements, 'disputes_overdue' => $disputes, 'projects_waiting_review' => $review];
    }
}
