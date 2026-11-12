<?php

namespace Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Marketing\Models\MarketingAlertRule;
use Modules\Marketing\Models\MarketingContentItem;
use Modules\Marketing\Models\MarketingExperiment;
use Modules\Marketing\Models\MarketingPlan;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\Finance\FinancialCalculator;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\MarketingAudit;
use Modules\Marketing\Services\Optimisation\AlertRuleService;
use Modules\Marketing\Services\Optimisation\ExperimentService;
use Modules\Marketing\Services\Optimisation\OptimisationAdvisor;
use Modules\Marketing\Services\Optimisation\Simulator;

/** Phase D: experiments, alert rules, suggested optimisations, simulation. Nothing here changes spending. */
class OptimisationController extends Controller
{
    public function __construct(
        private readonly ExperimentService $experiments,
        private readonly AlertRuleService $alerts,
        private readonly OptimisationAdvisor $advisor,
        private readonly Simulator $simulator,
        private readonly MarketingAccess $access,
        private readonly MarketingAudit $audit,
    ) {}

    private function experiment(MarketingExperiment $e): array
    {
        return [
            'id' => $e->uuid, 'name' => $e->name, 'hypothesis' => $e->hypothesis, 'success_metric' => $e->success_metric,
            'variants' => $e->variants, 'results' => (object) ($e->results ?? []), 'status' => $e->status,
            'conclusion' => $e->conclusion, 'preview' => $e->status === 'CONCLUDED' ? null : $this->experiments->evaluate($e),
            'content_id' => $e->content_item_id === null ? null : MarketingContentItem::query()->whereKey($e->content_item_id)->value('uuid'),
            'started_at' => $e->started_at?->toIso8601String(), 'ended_at' => $e->ended_at?->toIso8601String(),
        ];
    }

    private function rule(MarketingAlertRule $r): array
    {
        return [
            'id' => $r->uuid, 'metric' => $r->metric, 'comparison' => $r->comparison, 'threshold' => $r->threshold, 'window_days' => $r->window_days,
            'channel' => $r->channel, 'active' => $r->active, 'current' => $this->alerts->value($r), 'last_triggered_at' => $r->last_triggered_at?->toIso8601String(),
        ];
    }

    public function experiments(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());

        return ApiResponse::success(MarketingExperiment::query()->where('project_id', $project->id)->latest('id')->get()->map(fn ($e) => $this->experiment($e))->values(), __('api.retrieved'));
    }

    public function storeExperiment(Request $request, MarketingProject $project)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'hypothesis' => ['required', 'string', 'min:5', 'max:500'],
            'success_metric' => ['required', Rule::in(MarketingExperiment::METRICS)],
            'content' => ['nullable', 'uuid'],
            'labels' => ['nullable', 'array', 'size:2'],
            'labels.*' => ['string', 'max:60'],
        ]);

        return ApiResponse::created($this->experiment($this->experiments->create($project, $request->user(), $data)), __('marketing.messages.content_saved'));
    }

    public function startExperiment(Request $request, MarketingExperiment $experiment)
    {
        return ApiResponse::success($this->experiment($this->experiments->start($experiment, $request->user())), __('marketing.messages.content_saved'));
    }

    public function recordExperiment(Request $request, MarketingExperiment $experiment)
    {
        $data = $request->validate([
            'variant' => ['required', 'string', 'max:2'],
            'impressions' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'clicks' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'conversions' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'spend_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
        ]);

        return ApiResponse::success($this->experiment($this->experiments->record($experiment, $request->user(), $data['variant'], $data)), __('marketing.messages.results_saved'));
    }

    public function concludeExperiment(Request $request, MarketingExperiment $experiment)
    {
        return ApiResponse::success($this->experiment($this->experiments->conclude($experiment, $request->user())), __('marketing.messages.content_saved'));
    }

    public function alertRules(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());

        return ApiResponse::success(MarketingAlertRule::query()->where('project_id', $project->id)->latest('id')->get()->map(fn ($r) => $this->rule($r))->values(), __('api.retrieved'));
    }

    public function storeAlertRule(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user(), 'edit');
        $data = $request->validate([
            'metric' => ['required', Rule::in(MarketingAlertRule::METRICS)],
            'comparison' => ['required', Rule::in(['above', 'below'])],
            'threshold' => ['required', 'numeric', 'min:0', 'max:100000000000'],
            'window_days' => ['nullable', Rule::in([1, 7, 14, 30])],
            'channel' => ['nullable', Rule::in(MarketingContentItem::CHANNELS)],
        ]);
        $rule = MarketingAlertRule::query()->create($data + [
            'project_id' => $project->id, 'window_days' => $data['window_days'] ?? 7, 'active' => true,
            'created_by_type' => $request->user()->getMorphClass(), 'created_by_id' => $request->user()->getKey(),
        ]);
        $this->audit->record($project, $request->user(), 'alert.created', $rule, after: $data);

        return ApiResponse::created($this->rule($rule), __('marketing.messages.content_saved'));
    }

    public function updateAlertRule(Request $request, MarketingAlertRule $rule)
    {
        $this->access->assert($rule->project, $request->user(), 'edit');
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $rule->update($data);

        return ApiResponse::success($this->rule($rule), __('marketing.messages.content_saved'));
    }

    public function destroyAlertRule(Request $request, MarketingAlertRule $rule)
    {
        $this->access->assert($rule->project, $request->user(), 'edit');
        $this->audit->record($rule->project, $request->user(), 'alert.deleted', $rule);
        $rule->delete();

        return ApiResponse::success(null, __('marketing.messages.content_deleted'));
    }

    public function optimisations(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());

        return ApiResponse::success(['suggestions' => $this->advisor->suggest($project), 'note' => __('marketing.optimisations.note')], __('api.retrieved'));
    }

    /** POST marketing/plans/{plan}/simulate  {swing_pct?, inputs?} — from the plan's base inputs unless given */
    public function simulate(Request $request, MarketingPlan $plan)
    {
        $this->access->assert($plan->project, $request->user());
        $data = $request->validate([
            'swing_pct' => ['nullable', 'integer', Rule::in([10, 20, 30, 50])],
            'inputs' => ['nullable', 'array'],
            'inputs.*' => ['nullable', 'numeric', 'min:0', 'max:100000000000'],
        ]);
        $base = $data['inputs'] ?? ($plan->currentVersion?->scenarios()->where('scenario', 'base')->first()?->inputs ?? []);
        $base = array_intersect_key((array) $base, array_flip(FinancialCalculator::INPUTS));

        return ApiResponse::success($this->simulator->run($base, (int) ($data['swing_pct'] ?? 20)), __('api.retrieved'));
    }
}
