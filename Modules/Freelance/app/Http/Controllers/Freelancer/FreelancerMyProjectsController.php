<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Enums\FreelanceMilestoneStatus;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceDispute;
use Modules\Freelance\Models\Freelancer;

/**
 * GET my-projects[?group=all|active|completed|closed|disputes&per_page=] - the projects I was chosen for, newest first: `active` is the ones I am
 * working on or that wait for my approval, `completed` the finished ones and `closed` the ones that ended without being done
 * (declined, cancelled, expired); `disputes` the ones with a dispute that is not settled yet; no group (or `all`) is all of them. A draft the client has not sent yet is never shown. Each row opens the project page (its agreement).
 */
class FreelancerMyProjectsController extends Controller
{
    private const GROUPS = [
        'active' => [Agreement::PENDING, Agreement::ACTIVE],
        'completed' => [Agreement::COMPLETED],
        'closed' => [Agreement::DECLINED, Agreement::CANCELLED, Agreement::EXPIRED],
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group' => ['nullable', 'in:all,active,completed,closed,disputes'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        /** @var Freelancer $me */
        $me = $request->user('freelance_api');
        $group = $data['group'] ?? 'all';
        $statuses = isset(self::GROUPS[$group]) ? self::GROUPS[$group] : array_merge(...array_values(self::GROUPS));
        $unsettled = [FreelanceDispute::OPEN, FreelanceDispute::DECIDED, FreelanceDispute::APPEALED];

        $page = Agreement::query()
            ->where('freelancer_id', $me->id)
            ->whereIn('status', $statuses)
            ->when($group === 'disputes', fn ($q) => $q->whereHas('disputes', fn ($d) => $d->whereIn('status', $unsettled)))
            ->withExists(['disputes as has_dispute' => fn ($d) => $d->whereIn('status', $unsettled)])
            ->with('project:id,uuid,title')
            ->withCount([
                'milestones as milestones_count' => fn ($q) => $q->where('status', '!=', FreelanceMilestoneStatus::Cancelled->value),
                'milestones as milestones_done' => fn ($q) => $q->where('status', FreelanceMilestoneStatus::Approved->value),
            ])
            ->latest('id')
            ->paginate((int) ($data['per_page'] ?? 15));

        return ApiResponse::fromPaginator($page, $page->getCollection()->map(fn (Agreement $a) => [
            'uuid' => $a->uuid,
            'status' => $a->status,
            'project' => ['uuid' => $a->project?->uuid, 'title' => $a->project?->title],
            'price_minor' => $a->price_minor,
            'currency' => $a->currency_code,
            'duration_days' => $a->duration_days,
            'milestones_count' => (int) $a->milestones_count,
            'milestones_done' => (int) $a->milestones_done,
            'has_dispute' => (bool) $a->has_dispute,
            'activated_at' => $a->activated_at?->toIso8601String(),
            'completed_at' => $a->completed_at?->toIso8601String(),
        ])->values(), __('api.retrieved'));
    }
}
