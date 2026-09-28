<?php

namespace Modules\Sms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Sms\Exceptions\SmsException;
use Modules\Sms\Http\Resources\WhatsAppResource;
use Modules\Sms\Models\WhatsApp;
use Modules\Sms\Models\WhatsAppTemplate;
use Modules\Sms\Services\Sms\Adapters\MetaWhatsAppAdapter;

/**
 * WhatsApp configuration and template management endpoints.
 */
class WhatsAppController extends Controller implements HasMiddleware
{
    public function __construct() {}

    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('whatsapp', [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update']],
            ['test', ['testConnection', 'syncTemplate']],
        ]);
    }

    public function index(): \Illuminate\Http\JsonResponse
    {
        $whatsapp = WhatsApp::query()->first();

        return ApiResponse::success(
            new WhatsAppResource($whatsapp),
            __('sms.whatsapp.fetched'),
        );
    }

    public function show(): \Illuminate\Http\JsonResponse
    {
        $whatsapp = WhatsApp::query()->first();

        return ApiResponse::success(
            new WhatsAppResource($whatsapp),
            __('sms.whatsapp.fetched'),
        );
    }

    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'access_token' => ['required', 'string'],
            'phone_number_id' => ['required', 'string'],
            'business_account_id' => ['required', 'string'],
            'api_version' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        // Only one WhatsApp instance is allowed.
        $whatsapp = WhatsApp::query()->first();

        if ($whatsapp) {
            $whatsapp->update($validated);
        } else {
            $whatsapp = WhatsApp::create($validated);
        }

        return ApiResponse::success(new WhatsAppResource($whatsapp), __('sms.whatsapp.updated'));
    }

    public function update(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'access_token' => ['sometimes', 'string'],
            'phone_number_id' => ['sometimes', 'string'],
            'business_account_id' => ['sometimes', 'string'],
            'api_version' => ['sometimes', 'string'],
            'is_active' => ['boolean'],
        ]);

        $whatsapp = WhatsApp::query()->first();

        if (! $whatsapp) {
            throw new SmsException(__('sms.whatsapp.not_configured'), 422, 'whatsapp_not_configured');
        }

        $whatsapp->update($validated);

        return ApiResponse::success(new WhatsAppResource($whatsapp), __('sms.whatsapp.updated'));
    }

    public function testConnection(): \Illuminate\Http\JsonResponse
    {
        $whatsapp = WhatsApp::query()->first();

        if (! $whatsapp) {
            throw new SmsException(__('sms.whatsapp.not_configured'), 422, 'whatsapp_not_configured');
        }

        $adapter = new MetaWhatsAppAdapter($whatsapp->configuration_plaintext);
        $result = $adapter->testConnection();

        $whatsapp->update([
            'last_tested_at' => now(),
            'test_status' => $result['success'] ? 'passed' : 'failed',
            'test_error' => $result['success'] ? null : ($result['message'] ?? null),
        ]);

        return ApiResponse::success($result, $result['success'] ? __('sms.whatsapp.connection_successful') : __('sms.whatsapp.connection_failed'));
    }

    public function syncTemplate(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'template_name' => ['required', 'string', 'max:120'],
            'language' => ['required', 'string', 'max:10'],
        ]);

        $whatsapp = WhatsApp::query()->first();

        if (! $whatsapp) {
            throw new SmsException(__('sms.whatsapp.not_configured'), 422, 'whatsapp_not_configured');
        }

        $adapter = new MetaWhatsAppAdapter($whatsapp->configuration_plaintext);
        $result = $adapter->validateTemplate($validated['template_name']);

        // Find or create the template.
        $template = $whatsapp->templates()->where('template_name', $validated['template_name'])
            ->where('language', $validated['language'])
            ->first();

        if (! $template) {
            $template = WhatsAppTemplate::create([
                'whatsapp_id' => $whatsapp->id,
                'template_name' => $validated['template_name'],
                'language' => $validated['language'],
                'meta_status' => 'unknown',
                'is_active' => false,
            ]);
        }

        // Meta approval status: use Meta result if available, otherwise keep 'pending' locally.
        if ($result['success']) {
            $template->update([
                'meta_status' => 'approved',
                'last_synced_at' => now(),
                'is_active' => true,
            ]);
        } else {
            $template->update([
                'meta_status' => 'pending',
                'last_synced_at' => now(),
                'test_error' => $result['message'] ?? null,
            ]);
        }

        return ApiResponse::success(['template' => new WhatsAppTemplateResource($template)], __('sms.whatsapp.template_synced'));
    }
}
