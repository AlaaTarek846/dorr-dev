<?php

namespace Modules\Sms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Modules\Sms\Exceptions\SmsException;
use Modules\Sms\Http\Resources\WhatsAppResource;
use Modules\Sms\Http\Resources\WhatsAppTemplateResource;
use Modules\Sms\Models\WhatsApp;
use Modules\Sms\Models\WhatsAppCountry;
use Modules\Sms\Services\Otp\WhatsAppTemplateService;
use Modules\Sms\Services\Sms\Adapters\MetaWhatsAppAdapter;

/**
 * WhatsApp configuration and template management endpoints.
 *
 * Dorr keeps ONE WhatsApp configuration, so store() upserts that single row.
 */
class WhatsAppController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('whatsapp', [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update']],
            ['test', ['testConnection', 'syncTemplate']],
        ]);
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            new WhatsAppResource($this->first()),
            __('sms.whatsapp.fetched'),
        );
    }

    public function show(): JsonResponse
    {
        return ApiResponse::success(
            new WhatsAppResource($this->first()),
            __('sms.whatsapp.fetched'),
        );
    }

    public function store(Request $request): JsonResponse
    {
        // Only one WhatsApp instance exists, so this endpoint is an upsert:
        // credentials stay optional once the row has been created.
        $exists = $this->first() !== null;
        $validated = $request->validate($this->rules(required: ! $exists));

        $whatsapp = $this->first();

        if ($whatsapp) {
            $whatsapp->update($this->credentials($validated));
            $whatsapp = $whatsapp->refresh();
        } else {
            $whatsapp = WhatsApp::create($this->credentials($validated));
        }

        $this->syncCountries($whatsapp, $validated['countries'] ?? null);

        return ApiResponse::success(new WhatsAppResource($whatsapp->refresh()), __('sms.whatsapp.updated'));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(required: false));

        $whatsapp = $this->first();

        if (! $whatsapp) {
            throw new SmsException(__('sms.whatsapp.not_configured'), 422, 'whatsapp_not_configured');
        }

        $whatsapp->update($this->credentials($validated));
        $this->syncCountries($whatsapp, $validated['countries'] ?? null);

        return ApiResponse::success(new WhatsAppResource($whatsapp->refresh()), __('sms.whatsapp.updated'));
    }

    public function testConnection(): JsonResponse
    {
        $whatsapp = $this->first();

        if (! $whatsapp) {
            throw new SmsException(__('sms.whatsapp.not_configured'), 422, 'whatsapp_not_configured');
        }

        $result = (new MetaWhatsAppAdapter($whatsapp->configuration_plaintext))->testConnection();

        $whatsapp->update([
            'last_tested_at' => now(),
            'test_status' => $result['success'] ? 'passed' : 'failed',
            'test_error' => $result['success'] ? null : ($result['message'] ?? null),
            'is_available' => $result['success'],
        ]);

        return ApiResponse::success(
            ['result' => $result, 'whatsapp' => new WhatsAppResource($whatsapp->refresh())],
            $result['success'] ? __('sms.whatsapp.connection_successful') : __('sms.whatsapp.connection_failed'),
        );
    }

    /**
     * Find-or-create a template from a name + language pair and refresh its
     * status from Meta. Used to adopt a template that was created directly in
     * the Meta dashboard.
     */
    public function syncTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_name' => ['required', 'string', 'max:120'],
            'language_id' => ['required', 'integer', 'exists:languages,id'],
        ]);

        $whatsapp = $this->first();

        if (! $whatsapp) {
            throw new SmsException(__('sms.whatsapp.not_configured'), 422, 'whatsapp_not_configured');
        }

        $template = $whatsapp->templates()->firstOrNew([
            'template_name' => $validated['template_name'],
            'language_id' => $validated['language_id'],
        ]);

        if (! $template->exists) {
            $template->fill(['meta_status' => 'unknown', 'is_active' => false])->save();
        }

        $service = app(WhatsAppTemplateService::class);
        $result = $service->syncWithMeta($template->id);

        return ApiResponse::success(
            ['result' => $result, 'template' => new WhatsAppTemplateResource($template->refresh())],
            $result['success'] ? __('sms.whatsapp.template_synced') : __('sms.whatsapp.sync_failed'),
        );
    }

    /* --------------------------------------------------------------------- *
     | Internals
     * --------------------------------------------------------------------- */

    protected function first(): ?WhatsApp
    {
        return WhatsApp::query()->first();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:150'],
            'access_token' => [$presence, 'string', 'max:2000'],
            'phone_number_id' => [$presence, 'string', 'max:100'],
            'phone_number' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9]{6,20}$/'],
            'phone_country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'business_account_id' => [$presence, 'string', 'max:100'],
            'api_version' => ['nullable', 'string', 'max:20', 'regex:/^v\d+\.\d+$/'],
            'is_active' => ['boolean'],
            'countries' => ['nullable', 'array'],
            'countries.*' => ['integer', 'exists:countries,id'],
        ];
    }

    /**
     * Keep only the model attributes — `countries` is a relation.
     *
     * @return array<string, mixed>
     */
    protected function credentials(array $validated): array
    {
        return array_intersect_key($validated, array_flip([
            'name', 'access_token', 'phone_number_id', 'phone_number',
            'phone_country_id', 'business_account_id', 'api_version', 'is_active',
        ]));
    }

    /**
     * @param  array<int, int>|null  $countryIds  null leaves the relation untouched
     */
    protected function syncCountries(WhatsApp $whatsapp, ?array $countryIds): void
    {
        if ($countryIds === null) {
            return;
        }

        $countryIds = array_values(array_unique(array_map('intval', $countryIds)));

        $whatsapp->countries()->whereNotIn('country_id', $countryIds ?: [0])->delete();

        foreach ($countryIds as $countryId) {
            WhatsAppCountry::firstOrCreate(
                ['whatsapp_id' => $whatsapp->id, 'country_id' => $countryId],
                ['is_active' => true],
            );
        }
    }
}
