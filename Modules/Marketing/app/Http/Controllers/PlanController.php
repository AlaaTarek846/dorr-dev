<?php

namespace Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Marketing\Http\Resources\JobResource;
use Modules\Marketing\Http\Resources\PlanResource;
use Modules\Marketing\Http\Resources\PlanVersionResource;
use Modules\Marketing\Models\MarketingPlan;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\Finance\FinancialCalculator;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\MarketingAudit;
use Modules\Marketing\Services\Plans\PlanExporter;
use Modules\Marketing\Services\Plans\PlanService;

class PlanController extends Controller
{
    public function __construct(
        private readonly PlanService $plans,
        private readonly MarketingAccess $access,
        private readonly PlanExporter $exporter,
        private readonly MarketingAudit $audit,
    ) {}

    /** GET marketing/projects/{project}/plans */
    public function index(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());
        $plans = $project->plans()->with('currentVersion')->withCount('versions')->orderBy('id')->get();

        return ApiResponse::success(PlanResource::collection($plans), __('api.retrieved'));
    }

    /** POST marketing/projects/{project}/plans  {type, instructions?} — 202 + job */
    public function store(Request $request, MarketingProject $project)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(MarketingPlan::TYPES)],
            'instructions' => ['nullable', 'string', 'max:1000'],
        ]);
        $started = $this->plans->generate($project, $request->user(), $data['type'], $data['instructions'] ?? null);

        return ApiResponse::success([
            'plan' => new PlanResource($started['plan']->load('currentVersion')),
            'job' => new JobResource($started['job']),
        ], __('marketing.messages.plan_started'), 202);
    }

    /** GET marketing/plans/{plan} */
    public function show(Request $request, MarketingPlan $plan)
    {
        $this->access->assert($plan->project, $request->user());

        return ApiResponse::success(new PlanResource($plan->load('currentVersion')->loadCount('versions')), __('api.retrieved'));
    }

    /** GET marketing/plans/{plan}/versions */
    public function versions(Request $request, MarketingPlan $plan)
    {
        $this->access->assert($plan->project, $request->user());

        return ApiResponse::success($plan->versions()->get()->map(fn ($v) => [
            'version' => $v->version_no,
            'origin' => $v->origin,
            'content_hash' => $v->content_hash,
            'created_at' => $v->created_at?->toIso8601String(),
        ])->values()->all(), __('api.retrieved'));
    }

    /** GET marketing/plans/{plan}/versions/{version} */
    public function version(Request $request, MarketingPlan $plan, int $version)
    {
        $this->access->assert($plan->project, $request->user());

        return ApiResponse::success(new PlanVersionResource($plan->versions()->where('version_no', $version)->firstOrFail()), __('api.retrieved'));
    }

    /** PUT marketing/plans/{plan}  {version, content} — a new version. */
    public function update(Request $request, MarketingPlan $plan)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'content' => ['required', 'array']]);
        $this->plans->edit($plan, $request->user(), (int) $data['version'], $data['content']);

        return ApiResponse::success(new PlanResource($plan->fresh()->load('currentVersion')->loadCount('versions')), __('marketing.messages.plan_saved'));
    }

    /** PUT marketing/plans/{plan}/finance  {version, inputs} — a new version with computed scenarios. */
    public function finance(Request $request, MarketingPlan $plan)
    {
        $rules = ['version' => ['required', 'integer', 'min:1'], 'inputs' => ['required', 'array']];
        foreach (FinancialCalculator::INPUTS as $key) {
            $rules["inputs.$key"] = $key === 'monthly_churn_pct'
                ? ['nullable', 'numeric', 'min:0', 'max:100']
                : ['nullable', 'integer', 'min:0', 'max:100000000000'];
        }
        $data = $request->validate($rules);
        $inputs = collect($data['inputs'])->only(FinancialCalculator::INPUTS)
            ->map(fn ($v, $k) => $v === null ? null : ($k === 'monthly_churn_pct' ? (float) $v : (int) $v))->all();
        $this->plans->setFinance($plan, $request->user(), (int) $data['version'], $inputs);

        return ApiResponse::success(new PlanResource($plan->fresh()->load('currentVersion')->loadCount('versions')), __('marketing.messages.finance_saved'));
    }

    /** POST marketing/plans/{plan}/submit  {version} */
    public function submit(Request $request, MarketingPlan $plan)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->plans->submit($plan, $request->user(), (int) $data['version']);

        return ApiResponse::success(new PlanResource($plan->fresh()->load('currentVersion')), __('marketing.messages.plan_submitted'));
    }

    /** POST marketing/plans/{plan}/approve  {version} */
    public function approve(Request $request, MarketingPlan $plan)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->plans->approve($plan, $request->user(), (int) $data['version']);

        return ApiResponse::success(new PlanResource($plan->fresh()->load('currentVersion')), __('marketing.messages.plan_approved'));
    }

    /** POST marketing/plans/{plan}/return  {version, note?} */
    public function reject(Request $request, MarketingPlan $plan)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'note' => ['nullable', 'string', 'max:500']]);
        $this->plans->reject($plan, $request->user(), (int) $data['version'], $data['note'] ?? null);

        return ApiResponse::success(new PlanResource($plan->fresh()->load('currentVersion')), __('marketing.messages.plan_returned'));
    }

    /** POST marketing/plans/{plan}/archive */
    public function archive(Request $request, MarketingPlan $plan)
    {
        $this->plans->archive($plan, $request->user());

        return ApiResponse::success(new PlanResource($plan->fresh()->load('currentVersion')), __('marketing.messages.plan_archived'));
    }

    /** GET marketing/plans/{plan}/versions/{version}/export?format=pdf|docx */
    public function export(Request $request, MarketingPlan $plan, int $version)
    {
        $this->access->assert($plan->project, $request->user());
        $data = $request->validate(['format' => ['required', 'in:pdf,docx']]);
        $row = $plan->versions()->where('version_no', $version)->firstOrFail();
        $file = $this->exporter->export($plan, $row, $data['format']);
        $this->audit->record($plan->project, $request->user(), 'plan.exported', $row, meta: ['format' => $data['format'], 'version' => $version]);

        return response($file['bytes'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
