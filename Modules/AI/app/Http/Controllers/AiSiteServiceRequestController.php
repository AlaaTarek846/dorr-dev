<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Modules\AI\Exceptions\AiSiteException;
use Modules\AI\Http\Resources\AiSiteServiceRequestResource;
use Modules\AI\Models\AiSiteServiceRequest;
use Modules\AI\Models\AiSiteServiceSetting;
use Modules\AI\Services\Sites\AiSiteServiceRequestService;

/** Customer side of "build it with a person": the brief, the quote, instalments and the conversation. Ownership is always checked. */
class AiSiteServiceRequestController extends Controller
{
    public function __construct(protected AiSiteServiceRequestService $service) {}

    /** Whether the option is on, plus the intro text - the app hides the option when "enabled" is false. */
    public function info()
    {
        return ApiResponse::success([
            'enabled' => $this->service->enabled(),
            'intro' => AiSiteServiceSetting::current()->intro(),
        ]);
    }

    public function index(Request $request)
    {
        $owner = $this->owner($request);

        $query = AiSiteServiceRequest::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->latest('id');

        return ApiResponse::paginated($query, AiSiteServiceRequestResource::class);
    }

    public function store(Request $request)
    {
        $this->service->assertEnabled();

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:150'],
            'site_type' => ['nullable', 'string', 'max:40'],
            'business_description' => ['required', 'string', 'max:3000'],
            'target_audience' => ['nullable', 'string', 'max:500'],
            'goals' => ['nullable', 'array', 'max:12'],
            'goals.*' => ['string', 'max:60'],
            'pages' => ['nullable', 'array', 'max:20'],
            'pages.*' => ['string', 'max:60'],
            'features' => ['nullable', 'array', 'max:20'],
            'features.*' => ['string', 'max:60'],
            'languages' => ['nullable', 'array', 'max:6'],
            'languages.*' => ['string', 'max:30'],
            'style_notes' => ['nullable', 'string', 'max:1000'],
            'colors' => ['nullable', 'string', 'max:200'],
            'reference_links' => ['nullable', 'array', 'max:5'],
            'reference_links.*' => ['string', 'max:300'],
            'has_logo' => ['nullable', 'boolean'],
            'has_domain' => ['nullable', 'boolean'],
            'domain_name' => ['nullable', 'string', 'max:120'],
            'content_ready' => ['nullable', 'in:ready,partial,none'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0'],
            'deadline_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'contact_name' => ['required', 'string', 'max:100'],
            'contact_phone' => ['required', 'string', 'max:30'],
            'contact_method' => ['nullable', 'in:call,whatsapp,chat,email'],
            'contact_time' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'files' => ['nullable', 'array', 'max:6'],
            'files.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,zip'],
        ]);

        $files = $request->file('files', []);
        unset($data['files']);

        $created = $this->service->create($this->owner($request), $data, is_array($files) ? array_values($files) : []);

        return ApiResponse::success($this->service->present($created), __('ai.site_request_sent'), 201);
    }

    public function show(Request $request, int $id)
    {
        return ApiResponse::success($this->service->present($this->find($request, $id)));
    }

    public function cancel(Request $request, int $id)
    {
        return ApiResponse::success($this->service->present($this->service->cancel($this->find($request, $id))), __('ai.site_request_cancelled'));
    }

    public function decline(Request $request, int $id)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return ApiResponse::success($this->service->present($this->service->decline($this->find($request, $id), $data['reason'] ?? null)), __('ai.site_request_declined'));
    }

    /** PIN-protected at the route: accepting takes the first instalment from the wallet. */
    public function accept(Request $request, int $id)
    {
        $updated = $this->service->accept($this->find($request, $id), $this->owner($request));

        return ApiResponse::success($this->service->present($updated), __('ai.site_request_accepted'));
    }

    /** PIN-protected at the route. */
    public function pay(Request $request, int $id, int $installment)
    {
        $updated = $this->service->pay($this->find($request, $id), $installment, $this->owner($request));

        return ApiResponse::success($this->service->present($updated), __('ai.site_request_paid'));
    }

    public function messages(Request $request, int $id)
    {
        $model = $this->find($request, $id);
        $this->service->markMessagesRead($model);

        return ApiResponse::success($this->service->presentMessages($model->load('messages')));
    }

    public function sendMessage(Request $request, int $id)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $model = $this->find($request, $id);
        $this->service->customerMessage($model, trim($data['body']));

        return ApiResponse::success($this->service->presentMessages($model->load('messages')), 'OK', 201);
    }

    protected function find(Request $request, int $id): AiSiteServiceRequest
    {
        $owner = $this->owner($request);

        $model = AiSiteServiceRequest::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getAuthIdentifier())
            ->find($id);

        return $model ?? throw new AiSiteException('request_not_found', 404);
    }

    protected function owner(Request $request): Authenticatable
    {
        return $request->user('user_api') ?? $request->user('provider_api');
    }
}
