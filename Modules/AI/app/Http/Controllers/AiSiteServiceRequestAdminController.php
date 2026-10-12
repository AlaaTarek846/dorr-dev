<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\AI\Exceptions\AiSiteException;
use Modules\AI\Http\Resources\AiSiteServiceRequestResource;
use Modules\AI\Models\AiSiteRequestInstallment;
use Modules\AI\Models\AiSiteRequestMessage;
use Modules\AI\Models\AiSiteRequestMilestone;
use Modules\AI\Models\AiSiteRequestStatus;
use Modules\AI\Models\AiSiteServiceRequest;
use Modules\AI\Models\AiSiteServiceSetting;
use Modules\AI\Services\Sites\AiSiteServiceRequestService;

/** Admin: review incoming requests, answer with a quote, run the work (status, deliveries, instalments) and talk to the customer. */
class AiSiteServiceRequestAdminController extends Controller
{
    public function __construct(protected AiSiteServiceRequestService $service) {}

    public function index(Request $request)
    {
        $query = AiSiteServiceRequest::query()->with('owner')->latest('id')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $term)->orWhere('reference', 'like', $term));
            });

        return ApiResponse::paginated($query, AiSiteServiceRequestResource::class);
    }

    /** Counts per status for the inbox tabs, plus the status list. */
    public function summary()
    {
        return ApiResponse::success([
            'counts' => AiSiteServiceRequest::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'statuses' => AiSiteRequestStatus::query()->orderBy('sort_order')->get()->map(fn ($s) => $this->statusRow($s)),
        ]);
    }

    public function show(int $id)
    {
        $model = $this->find($id);
        $model->forceFill(['admin_seen_at' => $model->admin_seen_at ?? now()])->save();

        return ApiResponse::success($this->service->present($model, forAdmin: true));
    }

    public function quote(Request $request, int $id)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:730'],
            'valid_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'milestones' => ['nullable', 'array', 'max:20'],
            'milestones.*.title' => ['required', 'string', 'max:150'],
            'milestones.*.description' => ['nullable', 'string', 'max:1000'],
            'milestones.*.due_days' => ['required', 'integer', 'min:0', 'max:730'],
            'installments' => ['required', 'array', 'min:1', 'max:10'],
            'installments.*.title' => ['required', 'string', 'max:150'],
            'installments.*.amount' => ['required', 'numeric', 'min:0.01'],
            'installments.*.trigger' => ['required', Rule::in([AiSiteRequestInstallment::ON_ACCEPT, AiSiteRequestInstallment::ON_MILESTONE, AiSiteRequestInstallment::ON_DELIVERY])],
            'installments.*.milestone_index' => ['nullable', 'integer', 'min:0'],
        ]);

        $updated = $this->service->sendQuote($this->find($id), $data);

        return ApiResponse::success($this->service->present($updated, forAdmin: true), __('ai.site_request_quote_sent'));
    }

    public function changeStatus(Request $request, int $id)
    {
        $data = $request->validate(['status' => ['required', 'string', 'max:40'], 'note' => ['nullable', 'string', 'max:1000']]);
        $updated = $this->service->changeStatus($this->find($id), $data['status'], $data['note'] ?? null);

        return ApiResponse::success($this->service->present($updated, forAdmin: true), __('ai.site_request_updated'));
    }

    public function milestone(Request $request, int $id, int $milestone)
    {
        $data = $request->validate(['status' => ['required', Rule::in([AiSiteRequestMilestone::PENDING, AiSiteRequestMilestone::IN_PROGRESS, AiSiteRequestMilestone::DELIVERED])]]);
        $model = $this->find($id);
        $item = $model->milestones()->whereKey($milestone)->first() ?? throw new AiSiteException('request_not_found', 404);

        return ApiResponse::success($this->service->present($this->service->setMilestoneStatus($model, $item, $data['status']), forAdmin: true), __('ai.site_request_updated'));
    }

    public function releaseInstallment(int $id, int $installment)
    {
        return ApiResponse::success($this->service->present($this->service->releaseInstallment($this->find($id), $installment), forAdmin: true), __('ai.site_request_updated'));
    }

    public function extend(Request $request, int $id)
    {
        $data = $request->validate(['due_at' => ['required', 'date', 'after:now'], 'reason' => ['nullable', 'string', 'max:500']]);
        $updated = $this->service->extend($this->find($id), new \DateTimeImmutable($data['due_at']), $data['reason'] ?? null);

        return ApiResponse::success($this->service->present($updated, forAdmin: true), __('ai.site_request_updated'));
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:5000'],
            'delivery_url' => ['nullable', 'string', 'max:500'],
            'delivery_notes' => ['nullable', 'string', 'max:3000'],
        ]);

        return ApiResponse::success($this->service->present($this->service->updateInternal($this->find($id), $data), forAdmin: true), __('ai.site_request_updated'));
    }

    public function messages(int $id)
    {
        $model = $this->find($id);
        $model->messages()->where('sender', AiSiteRequestMessage::FROM_CUSTOMER)->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::success($this->service->presentMessages($model->load('messages')));
    }

    public function sendMessage(Request $request, int $id)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $model = $this->find($id);
        $this->service->adminMessage($model, $request->user('admin_api')?->getAuthIdentifier(), trim($data['body']));

        return ApiResponse::success($this->service->presentMessages($model->load('messages')), 'OK', 201);
    }

    public function file(int $id, int $index)
    {
        $path = $this->service->filePath($this->find($id), $index);

        return $path ? response()->download($path) : throw new AiSiteException('request_not_found', 404);
    }

    // ------------------------------------------------------------------ settings

    public function settings()
    {
        return ApiResponse::success($this->settingPayload());
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'quote_valid_days' => ['required', 'integer', 'min:1', 'max:90'],
            'intro_ar' => ['nullable', 'string', 'max:2000'],
            'intro_en' => ['nullable', 'string', 'max:2000'],
        ]);

        AiSiteServiceSetting::current()->update($data);

        return ApiResponse::success($this->settingPayload(), __('ai.site_request_updated'));
    }

    // ------------------------------------------------------------------ statuses

    public function statuses()
    {
        AiSiteRequestStatus::ensureDefaults();

        return ApiResponse::success(AiSiteRequestStatus::query()->orderBy('sort_order')->get()->map(fn ($s) => $this->statusRow($s)));
    }

    public function storeStatus(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', 'unique:ai_site_request_statuses,code'],
            'name_ar' => ['required', 'string', 'max:80'],
            'name_en' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:20'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $status = AiSiteRequestStatus::query()->create($data + ['color' => 'secondary', 'sort_order' => 100, 'is_system' => false]);

        return ApiResponse::success($this->statusRow($status), __('ai.site_request_updated'), 201);
    }

    public function updateStatus(Request $request, AiSiteRequestStatus $status)
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:80'],
            'name_en' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'max:20'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // The workflow codes can be relabelled but must stay switched on, or requests would get stuck.
        if ($status->is_system) {
            $data['is_active'] = true;
        }

        $status->update($data);

        return ApiResponse::success($this->statusRow($status), __('ai.site_request_updated'));
    }

    public function destroyStatus(AiSiteRequestStatus $status)
    {
        if ($status->is_system || AiSiteServiceRequest::query()->where('status', $status->code)->exists()) {
            throw new AiSiteException('status_in_use', 409);
        }

        $status->delete();

        return ApiResponse::success(null, __('ai.site_request_updated'));
    }

    /** @return array<string, mixed> */
    protected function statusRow(AiSiteRequestStatus $s): array
    {
        return ['id' => $s->id, 'code' => $s->code, 'name_ar' => $s->name_ar, 'name_en' => $s->name_en, 'color' => $s->color,
            'sort_order' => $s->sort_order, 'is_active' => $s->is_active, 'is_system' => $s->is_system];
    }

    /** @return array<string, mixed> */
    protected function settingPayload(): array
    {
        $s = AiSiteServiceSetting::current();

        return ['is_enabled' => $s->is_enabled, 'quote_valid_days' => $s->quote_valid_days, 'intro_ar' => $s->intro_ar, 'intro_en' => $s->intro_en];
    }

    protected function find(int $id): AiSiteServiceRequest
    {
        return AiSiteServiceRequest::query()->with('owner')->find($id) ?? throw new AiSiteException('request_not_found', 404);
    }
}
