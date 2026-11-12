<?php

namespace Modules\Marketing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Modules\Marketing\Models\MarketingAuditEvent;
use Modules\Marketing\Models\MarketingJob;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Models\MarketingSubscription;

/**
 * DORR operations (U14, least privilege): subscriptions, projects and jobs as numbers and states — never the
 * content of a customer's plans or research (POL-13).
 */
class OverviewController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('marketing-operations', [
            ['view', ['subscriptions', 'projects', 'jobs', 'audit']],
        ]);
    }

    /** GET marketing/subscriptions?active=1 */
    public function subscriptions(Request $request)
    {
        $page = MarketingSubscription::query()->with(['package', 'owner'])
            ->when($request->boolean('active'), fn ($q) => $q->current())
            ->latest('id')->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::fromPaginator($page, collect($page->items())->map(fn (MarketingSubscription $s) => [
            'id' => $s->id,
            'owner' => ['type' => $s->owner_type, 'id' => $s->owner_id, 'name' => $s->owner?->name, 'phone' => $s->owner?->phone],
            'package' => $s->package?->code,
            'status' => $s->status,
            'starts_at' => $s->starts_at?->toIso8601String(),
            'ends_at' => $s->ends_at?->toIso8601String(),
            'price_minor' => $s->price_minor,
            'currency_code' => $s->currency_code,
        ])->all());
    }

    /** GET marketing/projects?status= */
    public function projects(Request $request)
    {
        $page = MarketingProject::query()->with('owner')->withCount(['members', 'researchRuns', 'plans'])
            ->when($request->string('status')->toString() !== '', fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->latest('id')->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::fromPaginator($page, collect($page->items())->map(fn (MarketingProject $p) => [
            'id' => $p->uuid,
            'name' => $p->name,
            'industry' => $p->industry,
            'country_id' => $p->country_id,
            'status' => $p->status,
            'owner' => ['type' => $p->owner_type, 'id' => $p->owner_id, 'name' => $p->owner?->name],
            'members' => $p->members_count,
            'research_runs' => $p->research_runs_count,
            'plans' => $p->plans_count,
            'created_at' => $p->created_at?->toIso8601String(),
        ])->all());
    }

    /** GET marketing/jobs?status= — failures and stuck work. */
    public function jobs(Request $request)
    {
        $page = MarketingJob::query()->with('project:id,uuid,name')
            ->when($request->string('status')->toString() !== '', fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->latest('id')->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::fromPaginator($page, collect($page->items())->map(fn (MarketingJob $j) => [
            'id' => $j->uuid,
            'project' => $j->project?->uuid,
            'type' => $j->job_type,
            'status' => $j->status,
            'error_code' => $j->error_code,
            'retry_count' => $j->retry_count,
            'created_at' => $j->created_at?->toIso8601String(),
            'finished_at' => $j->finished_at?->toIso8601String(),
        ])->all());
    }

    /** GET marketing/audit?project= */
    public function audit(Request $request)
    {
        $projectId = $request->string('project')->toString() === '' ? null
            : MarketingProject::withTrashed()->where('uuid', $request->string('project')->toString())->value('id');
        $page = MarketingAuditEvent::query()->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))
            ->latest('id')->paginate((int) $request->integer('per_page', 30));

        return ApiResponse::fromPaginator($page, collect($page->items())->map(fn (MarketingAuditEvent $e) => $e->only([
            'id', 'project_id', 'actor_type', 'actor_id', 'action', 'resource_type', 'resource_id', 'request_id', 'created_at',
        ]))->all());
    }
}
