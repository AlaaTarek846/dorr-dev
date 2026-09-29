<?php

namespace Modules\Sms\Services\Otp;

use App\Models\Language;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sms\Exceptions\SmsException;
use Modules\Sms\Models\WhatsApp;
use Modules\Sms\Models\WhatsAppTemplate;
use Modules\Sms\Services\Sms\Adapters\MetaWhatsAppAdapter;
use Modules\Sms\Support\WhatsAppTemplatePayload;

/**
 * WhatsAppTemplateService — the message template engine for the single
 * WhatsApp configuration.
 *
 * Owns the Meta review lifecycle:
 *  - submitToMeta(): push a local template to Meta via
 *    `upsert_message_templates`, store the returned Meta template id and move
 *    the template to `pending`.
 *  - syncWithMeta(): refresh one template from Meta (by Meta id when it has
 *    one, otherwise by name + language) and degrade `is_active` when the
 *    template is no longer approved.
 *  - syncAll()/importFromMeta(): one-way import of the account's Meta
 *    templates, upserted by Meta template id so duplicates are impossible.
 *
 * Every Meta HTTP call lives in MetaWhatsAppAdapter; this service only
 * orchestrates state.
 */
class WhatsAppTemplateService
{
    public function __construct(protected WhatsAppTemplatePayload $payload) {}

    /* --------------------------------------------------------------------- *
     | CRUD
     * --------------------------------------------------------------------- */

    public function list(): Collection
    {
        return WhatsAppTemplate::with('language')->orderBy('template_name')->orderBy('language_id')->get();
    }

    public function find(int|string $id): WhatsAppTemplate
    {
        return WhatsAppTemplate::with('language')->findOrFail($id);
    }

    public function create(array $data): WhatsAppTemplate
    {
        $data['whatsapp_id'] = $this->resolveWhatsapp()->id;
        $data['meta_status'] = $data['meta_status'] ?? 'unknown';
        $data['is_active'] = $data['is_active'] ?? false;

        return WhatsAppTemplate::create($data);
    }

    public function update(int|string $id, array $data): WhatsAppTemplate
    {
        $template = $this->find($id);
        $template->update($data);

        return $template->refresh();
    }

    public function delete(int|string $id): void
    {
        $this->find($id)->delete();
    }

    /* --------------------------------------------------------------------- *
     | Meta lifecycle
     * --------------------------------------------------------------------- */

    /**
     * Push a local template to Meta for review.
     *
     * @return array{success: bool, message: string, template?: WhatsAppTemplate}
     */
    public function submitToMeta(int|string $id): array
    {
        $template = $this->find($id);
        $whatsapp = $this->resolveWhatsapp();

        if (! $whatsapp->isReadyForTemplates()) {
            return ['success' => false, 'message' => __('sms.whatsapp.account_not_ready')];
        }

        $authError = $this->payload->authenticationError($template);

        if ($authError) {
            return ['success' => false, 'message' => $authError];
        }

        $adapter = $this->adapter($whatsapp);
        $result = $adapter->upsertTemplate(
            $this->payload->build($template, $adapter->mapLanguage($template->metaLanguageCode())),
        );

        if (! $result['success']) {
            $template->update([
                'last_synced_at' => now(),
                'last_sync_error' => $adapter->normalizeSyncError($result['error'] ?? null, $result['message'] ?? null),
            ]);

            return ['success' => false, 'message' => $result['message'], 'template' => $template->refresh()];
        }

        $template->update([
            'meta_template_id' => $result['meta_template_id'] ?: $template->meta_template_id,
            'meta_status' => 'pending',
            'last_synced_at' => now(),
            'last_sync_error' => null,
        ]);

        return ['success' => true, 'message' => __('sms.whatsapp.template_submitted'), 'template' => $template->refresh()];
    }

    /**
     * Refresh one template's status from Meta.
     *
     * @return array{success: bool, message: string, meta_status?: string}
     */
    public function syncWithMeta(int|string $id): array
    {
        $template = $this->find($id);
        $whatsapp = $this->resolveWhatsapp();
        $adapter = $this->adapter($whatsapp);

        $result = $template->meta_template_id
            ? $adapter->fetchTemplate((string) $template->meta_template_id)
            : $adapter->validateTemplate((string) $template->template_name, $template->metaLanguageCode());

        if (! $result['success']) {
            $template->update([
                'last_synced_at' => now(),
                'last_sync_error' => $adapter->normalizeSyncError($result['error'] ?? null, $result['message'] ?? null),
            ]);

            return ['success' => false, 'message' => $result['message'], 'meta_status' => $template->meta_status];
        }

        $meta = $result['template'] ?? [];
        $metaStatus = $result['meta_status'] ?? $this->mapMetaStatus((string) ($meta['status'] ?? 'unknown'));

        $update = [
            'meta_template_id' => $template->meta_template_id ?: (string) ($meta['id'] ?? '') ?: null,
            'meta_status' => $metaStatus,
            'last_synced_at' => now(),
            'last_sync_error' => null,
        ];

        // The active flag degrades with Meta (a template that is not approved
        // cannot be sent) but a manual deactivation is never overridden.
        $update['is_active'] = $template->is_active && $metaStatus === 'approved';

        if (filled($meta['rejected_reason'] ?? null)) {
            $update['last_sync_error'] = (string) $meta['rejected_reason'];
        }

        $template->update($update);

        return [
            'success' => true,
            'message' => __('sms.whatsapp.template_status_synced'),
            'meta_status' => $template->meta_status,
        ];
    }

    /**
     * Refresh every locally stored template from Meta.
     *
     * @return list<array{success: bool, message: string, meta_status?: string}>
     */
    public function syncAll(): array
    {
        $results = [];

        foreach (WhatsAppTemplate::all() as $template) {
            $results[] = $this->syncWithMeta($template->id);
        }

        return $results;
    }

    /**
     * One-way import of the account's Meta templates. Local templates are
     * matched by `meta_template_id`, unknown ones are created — so a template
     * created directly on Meta shows up here and duplicates are impossible.
     *
     * @return array{success: bool, message: string, synced?: int}
     */
    public function importFromMeta(): array
    {
        $whatsapp = $this->resolveWhatsapp();
        $adapter = $this->adapter($whatsapp);
        $result = $adapter->fetchTemplates();

        if (! $result['success']) {
            return ['success' => false, 'message' => $result['message']];
        }

        $synced = 0;

        foreach ($result['templates'] as $meta) {
            $metaId = (string) ($meta['id'] ?? '');

            if ($metaId === '') {
                continue;
            }

            $metaStatus = $this->mapMetaStatus((string) ($meta['status'] ?? 'unknown'));
            $languageCode = (string) ($meta['language'] ?? 'en');

            $template = $whatsapp->templates()->where('meta_template_id', $metaId)->first();

            $update = [
                'category' => $this->normalizeCategory($meta['category'] ?? null),
                'meta_language' => $languageCode,
                'meta_status' => $metaStatus,
                'last_synced_at' => now(),
                'last_sync_error' => filled($meta['rejected_reason'] ?? null) ? (string) $meta['rejected_reason'] : null,
            ];

            if ($template) {
                $update['is_active'] = $template->is_active && $metaStatus === 'approved';
                $template->update($update);
            } else {
                WhatsAppTemplate::create($update + [
                    'whatsapp_id' => $whatsapp->id,
                    'meta_template_id' => $metaId,
                    'template_name' => $meta['name'] ?? 'Untitled',
                    'language_id' => $this->resolveLanguageId($languageCode),
                    'body' => $this->payload->bodyFromComponents((array) ($meta['components'] ?? [])),
                    'components' => $meta['components'] ?? null,
                    'is_active' => $metaStatus === 'approved',
                ]);
            }

            $synced++;
        }

        return ['success' => true, 'message' => __('sms.whatsapp.templates_imported', ['count' => $synced]), 'synced' => $synced];
    }

    /* --------------------------------------------------------------------- *
     | Internals
     * --------------------------------------------------------------------- */

    protected function adapter(WhatsApp $whatsapp): MetaWhatsAppAdapter
    {
        return new MetaWhatsAppAdapter($whatsapp->configuration_plaintext);
    }

    protected function resolveWhatsapp(): WhatsApp
    {
        $whatsapp = WhatsApp::query()->first();

        if (! $whatsapp) {
            throw new SmsException(__('sms.whatsapp.not_configured'), 422, 'whatsapp_not_configured');
        }

        return $whatsapp;
    }

    /**
     * Map a Meta locale ("en_US") onto a local language row, falling back to
     * the first configured language.
     */
    protected function resolveLanguageId(string $metaLanguage): ?int
    {
        $code = strtolower(trim($metaLanguage));
        $prefix = explode('_', $code)[0];

        return Language::query()
            ->where('code', $code)
            ->orWhere('code', $prefix)
            ->value('id');
    }

    protected function mapMetaStatus(string $status): string
    {
        return match (strtolower($status)) {
            'approved' => 'approved',
            'pending', 'in_review' => 'pending',
            'rejected' => 'rejected',
            'paused' => 'paused',
            'disabled' => 'disabled',
            default => 'unknown',
        };
    }

    protected function normalizeCategory(?string $category): string
    {
        $normalized = strtoupper((string) $category);

        return in_array($normalized, ['AUTHENTICATION', 'MARKETING', 'UTILITY'], true)
            ? $normalized
            : 'UTILITY';
    }
}
