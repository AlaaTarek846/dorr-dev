<?php

namespace Modules\Sms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Validation\Rule;
use Modules\Sms\Http\Resources\WhatsAppTemplateResource;
use Modules\Sms\Services\Otp\WhatsAppTemplateService;

class WhatsAppTemplateController extends Controller implements HasMiddleware
{
    public function __construct(protected WhatsAppTemplateService $service) {}

    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('whatsapp', [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update', 'submit']],
            ['delete', ['destroy']],
            ['test', ['sync', 'syncAll', 'importFromMeta']],
        ]);
    }

    public function index(): JsonResponse
    {
        $templates = $this->service->list();

        return ApiResponse::success(
            WhatsAppTemplateResource::collection($templates),
            __('sms.whatsapp.templates_fetched'),
        );
    }

    public function show(int|string $id): JsonResponse
    {
        $template = $this->service->find($id);

        return ApiResponse::success(
            new WhatsAppTemplateResource($template),
            __('sms.whatsapp.template_fetched'),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_name' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'language_id' => ['required', 'integer', 'exists:languages,id'],
            'category' => ['required', Rule::in(['AUTHENTICATION', 'MARKETING', 'UTILITY'])],
            'body' => ['required', 'string', 'max:1024', $this->otpVariableRule($request->input('category'))],
            'components' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);

        $template = $this->service->create($validated);

        return ApiResponse::created(
            new WhatsAppTemplateResource($template),
            __('sms.whatsapp.template_created'),
        );
    }

    public function update(Request $request, int|string $id): JsonResponse
    {
        $category = $request->input('category') ?? $this->service->find($id)->category;

        $validated = $request->validate([
            'template_name' => ['sometimes', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'language_id' => ['sometimes', 'integer', 'exists:languages,id'],
            'category' => ['sometimes', Rule::in(['AUTHENTICATION', 'MARKETING', 'UTILITY'])],
            'body' => ['sometimes', 'string', 'max:1024', $this->otpVariableRule($category)],
            'components' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);

        $template = $this->service->update($id, $validated);

        return ApiResponse::success(
            new WhatsAppTemplateResource($template),
            __('sms.whatsapp.template_updated'),
        );
    }

    public function destroy(int|string $id): JsonResponse
    {
        $this->service->delete($id);

        return ApiResponse::success([], __('sms.whatsapp.template_deleted'));
    }

    /**
     * Push a local template to Meta for review.
     */
    public function submit(int|string $id): JsonResponse
    {
        $result = $this->service->submitToMeta($id);

        if (! $result['success']) {
            return ApiResponse::error($result['message'], 422, 'whatsapp_template_submit_failed');
        }
        return ApiResponse::success(
            ['result' => $result, 'template' => new WhatsAppTemplateResource($result['template'])],
            __('sms.whatsapp.template_submitted'),
        );
    }

    public function sync(int|string $id): JsonResponse
    {
        $result = $this->service->syncWithMeta($id);

        return ApiResponse::success(
            $result,
            $result['success'] ? __('sms.whatsapp.template_synced') : __('sms.whatsapp.sync_failed'),
        );
    }

    public function syncAll(): JsonResponse
    {
        $results = $this->service->syncAll();

        return ApiResponse::success($results, __('sms.whatsapp.templates_synced'));
    }

    /**
     * One-way import of every template that exists on the Meta account.
     */
    public function importFromMeta(): JsonResponse
    {
        $result = $this->service->importFromMeta();

        if (! $result['success']) {
            return ApiResponse::error($result['message'], 422, 'whatsapp_template_import_failed');
        }

        return ApiResponse::success($result, __('sms.whatsapp.templates_imported', ['count' => $result['synced'] ?? 0]));
    }

    /**
     * Meta rejects AUTHENTICATION templates whose body lacks the {{1}} OTP
     * variable, so require it up front instead of failing at submit time.
     */
    private function otpVariableRule(?string $category): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($category): void {
            if ($category === 'AUTHENTICATION' && is_string($value) && ! str_contains($value, '{{1}}')) {
                $fail(__('sms.whatsapp.template_otp_variable_required'));
            }
        };
    }
}
