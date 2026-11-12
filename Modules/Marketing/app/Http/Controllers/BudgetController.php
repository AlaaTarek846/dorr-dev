<?php

namespace Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Marketing\Http\Resources\BudgetResource;
use Modules\Marketing\Models\MarketingBudget;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\Budgets\BudgetService;
use Modules\Marketing\Services\MarketingAccess;

/** Budgets (U08): an ad-spend cap in money and an AI cap in calls. */
class BudgetController extends Controller
{
    public function __construct(private readonly BudgetService $budgets, private readonly MarketingAccess $access) {}

    /** GET marketing/projects/{project}/budgets */
    public function index(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());
        $rows = MarketingBudget::query()->where('project_id', $project->id)
            ->orderByRaw("CASE WHEN approval_status = 'CLOSED' THEN 1 ELSE 0 END")->orderByDesc('period_start')->get();

        return ApiResponse::success(BudgetResource::collection($rows), __('api.retrieved'));
    }

    /** POST marketing/projects/{project}/budgets  {kind, period_start, period_end, cap_minor | cap_calls, alert_pct?, note?} */
    public function store(Request $request, MarketingProject $project)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(MarketingBudget::KINDS)],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start', function ($attr, $value, $fail) use ($request) {
                if (strtotime($value) - strtotime((string) $request->input('period_start')) > 366 * 86400) {
                    $fail(__('marketing.errors.period_too_long'));
                }
            }],
            'cap_minor' => ['required_if:kind,ads', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'cap_calls' => ['required_if:kind,ai', 'nullable', 'integer', 'min:1', 'max:100000'],
            'alert_pct' => ['nullable', 'integer', 'min:50', 'max:100'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        return ApiResponse::created(new BudgetResource($this->budgets->create($project, $request->user(), $data)), __('marketing.messages.budget_saved'));
    }

    /** PATCH marketing/budgets/{budget}  {lock_version, cap_minor?|cap_calls?, period_end?, alert_pct?, note?} */
    public function update(Request $request, MarketingBudget $budget)
    {
        $data = $request->validate([
            'lock_version' => ['required', 'integer'],
            'period_end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$budget->period_start->toDateString()],
            'cap_minor' => ['nullable', 'integer', 'min:1', 'max:100000000000'],
            'cap_calls' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'alert_pct' => ['nullable', 'integer', 'min:50', 'max:100'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        return ApiResponse::success(new BudgetResource($this->budgets->update($budget, $request->user(), $data)), __('marketing.messages.budget_saved'));
    }

    /** POST marketing/budgets/{budget}/approve  {lock_version} — owner */
    public function approve(Request $request, MarketingBudget $budget)
    {
        $data = $request->validate(['lock_version' => ['required', 'integer']]);

        return ApiResponse::success(new BudgetResource($this->budgets->approve($budget, $request->user(), (int) $data['lock_version'])), __('marketing.messages.budget_approved'));
    }

    /** POST marketing/budgets/{budget}/close — owner */
    public function close(Request $request, MarketingBudget $budget)
    {
        return ApiResponse::success(new BudgetResource($this->budgets->close($budget, $request->user())), __('marketing.messages.budget_closed'));
    }
}
