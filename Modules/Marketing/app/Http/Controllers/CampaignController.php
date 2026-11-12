<?php

namespace Modules\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Http\Resources\CampaignResource;
use Modules\Marketing\Http\Resources\DelegationResource;
use Modules\Marketing\Http\Resources\MetricResource;
use Modules\Marketing\Models\MarketingApproval;
use Modules\Marketing\Models\MarketingCampaign;
use Modules\Marketing\Models\MarketingMetric;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\Platforms\CampaignService;
use Modules\Marketing\Services\Platforms\DelegationService;

/** Campaigns (U09) and spend delegations (D7). */
class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly DelegationService $delegations,
        private readonly MarketingAccess $access,
    ) {}

    /** GET marketing/projects/{project}/campaigns */
    public function index(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());
        $rows = MarketingCampaign::query()->where('project_id', $project->id)->with(['integration', 'contentItem', 'project'])->latest('id')->get();

        return ApiResponse::success(CampaignResource::collection($rows), __('api.retrieved'));
    }

    /** POST marketing/projects/{project}/campaigns — a draft; nothing is sent anywhere */
    public function store(Request $request, MarketingProject $project)
    {
        $data = $request->validate($this->rules(true));

        return ApiResponse::created(new CampaignResource($this->campaigns->create($project, $request->user(), $data)), __('marketing.messages.campaign_saved'));
    }

    /** GET marketing/campaigns/{campaign} */
    public function show(Request $request, MarketingCampaign $campaign)
    {
        $this->access->assert($campaign->project, $request->user());

        return ApiResponse::success(new CampaignResource($campaign), __('api.retrieved'));
    }

    /** PATCH marketing/campaigns/{campaign}  {version, …} — a new version; the old approval stops counting */
    public function update(Request $request, MarketingCampaign $campaign)
    {
        $data = $request->validate(['version' => ['required', 'integer']] + $this->rules(false));

        return ApiResponse::success(new CampaignResource($this->campaigns->update($campaign, $request->user(), $data)), __('marketing.messages.campaign_saved'));
    }

    /** DELETE marketing/campaigns/{campaign} */
    public function destroy(Request $request, MarketingCampaign $campaign)
    {
        $this->campaigns->delete($campaign, $request->user());

        return ApiResponse::success(null, __('marketing.messages.content_deleted'));
    }

    /** POST marketing/campaigns/{campaign}/validate — 422 `campaign_invalid` with `data.issues` */
    public function validateCampaign(Request $request, MarketingCampaign $campaign)
    {
        return ApiResponse::success(new CampaignResource($this->campaigns->validate($campaign, $request->user())), __('marketing.messages.campaign_valid'));
    }

    /** POST marketing/campaigns/{campaign}/approval-requests */
    public function requestApproval(Request $request, MarketingCampaign $campaign)
    {
        return ApiResponse::success(new CampaignResource($this->campaigns->requestApproval($campaign, $request->user())), __('marketing.messages.campaign_approval_requested'));
    }

    /** POST marketing/campaigns/{campaign}/approve  {version} — owner, or a manager within a delegation */
    public function approve(Request $request, MarketingCampaign $campaign)
    {
        $data = $request->validate(['version' => ['required', 'integer']]);
        $this->campaigns->approve($campaign, $request->user(), (int) $data['version']);

        return ApiResponse::success(new CampaignResource($campaign->fresh()), __('marketing.messages.campaign_approved'));
    }

    /** POST marketing/campaigns/{campaign}/reject  {note?} */
    public function reject(Request $request, MarketingCampaign $campaign)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        return ApiResponse::success(new CampaignResource($this->campaigns->reject($campaign, $request->user(), $data['note'] ?? null)), __('marketing.messages.campaign_rejected'));
    }

    /** POST marketing/campaigns/{campaign}/publish — header Idempotency-Key; {approval_id, campaign_version, expected_budget_minor, currency} */
    public function publish(Request $request, MarketingCampaign $campaign)
    {
        $key = (string) $request->header('Idempotency-Key', '');
        if (! preg_match('/^[A-Za-z0-9_\-]{8,80}$/', $key)) {
            throw new MarketingException('idempotency_required', 422);
        }
        $data = $request->validate([
            'approval_id' => ['required', 'uuid'],
            'campaign_version' => ['required', 'integer'],
            'expected_budget_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
        ]);
        $result = $this->campaigns->publish($campaign, $request->user(), $key, $data);
        $action = $result['action'];

        return ApiResponse::success([
            'campaign' => new CampaignResource($result['campaign']),
            'action' => ['id' => $action->uuid, 'status' => $action->status, 'error_code' => $action->error_code, 'external_ref' => $action->external_ref],
            'replayed' => $result['replayed'],
        ], __('marketing.messages.campaign_publish_'.strtolower($action->status)));
    }

    /** POST marketing/campaigns/{campaign}/reconcile */
    public function reconcile(Request $request, MarketingCampaign $campaign)
    {
        return ApiResponse::success(new CampaignResource($this->campaigns->reconcile($campaign, $request->user())), __('marketing.messages.campaign_reconciled'));
    }

    /** POST marketing/campaigns/{campaign}/metrics/sync */
    public function sync(Request $request, MarketingCampaign $campaign)
    {
        return ApiResponse::success($this->campaigns->syncMetrics($campaign, $request->user()), __('marketing.messages.results_saved'));
    }

    /** GET marketing/campaigns/{campaign}/metrics — the platform's numbers with when they were read */
    public function metrics(Request $request, MarketingCampaign $campaign)
    {
        $this->access->assert($campaign->project, $request->user());
        $rows = MarketingMetric::query()->where('campaign_id', $campaign->id)->orderByDesc('metric_date')->limit(120)->get();

        return ApiResponse::success([
            'synced_at' => $campaign->metrics_synced_at?->toIso8601String(),
            'rows' => MetricResource::collection($rows),
        ], __('api.retrieved'));
    }

    /** GET marketing/projects/{project}/delegations */
    public function delegations(Request $request, MarketingProject $project)
    {
        $this->access->assert($project, $request->user());
        $rows = MarketingApproval::query()->where('project_id', $project->id)->where('scope', 'delegation')->latest('id')->get();

        return ApiResponse::success(DelegationResource::collection($rows), __('api.retrieved'));
    }

    /** POST marketing/projects/{project}/delegations  {member_id, cap_minor, expires_at} — owner */
    public function delegate(Request $request, MarketingProject $project)
    {
        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'cap_minor' => ['required', 'integer', 'min:100', 'max:100000000000'],
            'expires_at' => ['required', 'date', 'after:now', 'before:'.now()->addDays(91)->toDateString()],
        ]);
        $delegation = $this->delegations->create($project, $request->user(), (int) $data['member_id'], (int) $data['cap_minor'], Carbon::parse($data['expires_at']));

        return ApiResponse::created(new DelegationResource($delegation), __('marketing.messages.delegation_saved'));
    }

    /** DELETE marketing/delegations/{delegation} — owner; campaigns approved under it and not published stop */
    public function revokeDelegation(Request $request, string $delegation)
    {
        $row = MarketingApproval::query()->where('uuid', $delegation)->where('scope', 'delegation')->firstOrFail();

        return ApiResponse::success(new DelegationResource($this->delegations->revoke($row, $request->user())), __('marketing.messages.delegation_revoked'));
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'integration' => ['nullable', 'uuid'],
            'content' => ['nullable', 'uuid'],
            'name' => [$req, 'string', 'min:2', 'max:120'],
            'objective' => [$req, Rule::in(MarketingCampaign::OBJECTIVES)],
            'daily_budget_minor' => [$req, 'integer', 'min:1', 'max:10000000000'],
            'start_at' => [$req, 'date'],
            'end_at' => [$req, 'date', 'after:start_at'],
            'targeting' => [$req, 'array'],
            'targeting.countries' => ['nullable', 'array', 'max:25'],
            'targeting.countries.*' => ['string', 'size:2'],
            'targeting.age_min' => ['nullable', 'integer', 'min:13', 'max:65'],
            'targeting.age_max' => ['nullable', 'integer', 'min:13', 'max:65', 'gte:targeting.age_min'],
        ];
    }
}
