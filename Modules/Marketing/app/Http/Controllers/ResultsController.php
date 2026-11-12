<?php

namespace Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Marketing\Http\Resources\MetricResource;
use Modules\Marketing\Models\MarketingContentItem;
use Modules\Marketing\Models\MarketingMetric;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\Results\ExecutiveReport;
use Modules\Marketing\Services\Results\KpiService;
use Modules\Marketing\Services\Results\MetricsService;

/** Results entered by hand or from a file, the KPI board (U11) and the executive report (U12). */
class ResultsController extends Controller
{
    public function __construct(
        private readonly MetricsService $metrics,
        private readonly KpiService $kpis,
        private readonly ExecutiveReport $report,
        private readonly MarketingAccess $access,
    ) {}

    /** GET marketing/projects/{project}/metrics?from=&to=&channel= */
    public function index(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'channel' => ['nullable', Rule::in(MarketingContentItem::CHANNELS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = MarketingMetric::query()->where('project_id', $project->id)->with('contentItem')
            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('metric_date', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->whereDate('metric_date', '<=', $d))
            ->when($data['channel'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->orderByDesc('metric_date')->orderBy('channel')->paginate($data['per_page'] ?? 50);

        return ApiResponse::fromPaginator($page, MetricResource::collection($page->getCollection()), __('api.retrieved'));
    }

    /** POST marketing/projects/{project}/metrics  {rows: [{date, channel, basis?, impressions?, clicks?, spend_minor?, conversions?, revenue_minor?, content_id?, note?}]} */
    public function store(Request $request, MarketingProject $project)
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:'.MetricsService::MAX_ROWS],
            'rows.*.date' => ['required', 'date_format:Y-m-d', 'after:'.now()->subYears(3)->toDateString()],
            'rows.*.channel' => ['required', Rule::in(MarketingContentItem::CHANNELS)],
            'rows.*.basis' => ['nullable', Rule::in(MarketingMetric::BASES)],
            'rows.*.impressions' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'rows.*.clicks' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'rows.*.spend_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'rows.*.conversions' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'rows.*.revenue_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'rows.*.content_id' => ['nullable', 'uuid'],
            'rows.*.note' => ['nullable', 'string', 'max:300'],
        ]);

        return ApiResponse::success($this->metrics->save($project, $request->user(), $data['rows']), __('marketing.messages.results_saved'));
    }

    /** POST marketing/projects/{project}/metrics/import  (multipart `file`, CSV) */
    public function import(Request $request, MarketingProject $project)
    {
        $request->validate(['file' => ['required', 'file', 'max:1024', 'mimes:csv,txt']]);

        return ApiResponse::success($this->metrics->import($project, $request->user(), $request->file('file')), __('marketing.messages.results_imported'));
    }

    /** DELETE marketing/metrics/{metric} */
    public function destroy(Request $request, MarketingMetric $metric)
    {
        $this->metrics->delete($metric, $request->user());

        return ApiResponse::success(null, __('marketing.messages.results_deleted'));
    }

    /** GET marketing/projects/{project}/kpis?from=&to= — default the last 30 days */
    public function kpis(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $to = isset($data['to']) ? Carbon::parse($data['to']) : now()->startOfDay();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subDays(29);
        if ($from->diffInDays($to) > 366) {
            $from = $to->copy()->subDays(366);
        }

        return ApiResponse::success($this->kpis->board($project, $from, $to), __('api.retrieved'));
    }

    /** GET marketing/projects/{project}/executive-report?period=day|week|month */
    public function report(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());
        $data = $request->validate(['period' => ['nullable', Rule::in(array_keys(ExecutiveReport::PERIODS))]]);

        return ApiResponse::success($this->report->build($project, $data['period'] ?? 'week'), __('api.retrieved'));
    }
}
