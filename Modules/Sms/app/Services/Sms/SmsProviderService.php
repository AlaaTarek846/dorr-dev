<?php

namespace Modules\Sms\Services\Sms;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sms\Exceptions\SmsException;
use Modules\Sms\Models\SmsProvider;

/**
 * Provider management. A SmsProvider row is the identity + status of a provider
 * plus an optional default credential blob (encrypted). Every real per-account
 * secret still lives on the bound SmsAccount — the provider blot is an optional
 * default and is never exposed to the frontend. Adapter metadata comes from
 * SmsAdapterRegistry, so adding a provider never touches this class.
 */
class SmsProviderService
{
    public function __construct(protected SmsAdapterRegistry $registry) {}

    /**
     * Returns the query rather than a paginator: ApiPaginator::resolve (behind
     * ApiResponse::paginated) owns per_page/all/paginate handling for every
     * listing endpoint in the app.
     */
    public function query(Request $request): Builder
    {
        return SmsProvider::withCount('smsAccounts')
            ->when($request->filled('search'), fn (Builder $q) => $q->searchAndFilter($request->input('search')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->orderBy('id', $request->input('sort_order') === 'asc' ? 'asc' : 'desc');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): SmsProvider
    {
        $data['is_active'] = $data['is_active'] ?? true;
        $data['is_available'] = $data['is_available'] ?? true;

        $config = $data['configuration'] ?? null;
        unset($data['configuration']);

        if (! empty($config)) {
            $this->assertConfigurationComplete($data['key'], $config);
            $data['configuration'] = $this->registry->prepareConfiguration($data['key'], $config);
        }

        return SmsProvider::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int|string $id, array $data): SmsProvider
    {
        $provider = $this->findOrFail($id);

        if (! empty($data['configuration'])) {
            $config = $data['configuration'];
            unset($data['configuration']);

            $this->assertConfigurationComplete($provider->key, $config, true);

            // On edit, blank/absent secret fields keep the stored value.
            $data['configuration'] = $this->registry->prepareConfiguration(
                $provider->key,
                $config,
                $provider->configuration_plaintext,
            );
        } else {
            unset($data['configuration']);
        }

        $provider->update($data);

        return $provider->refresh();
    }

    public function findOrFail(int|string $id): SmsProvider
    {
        return SmsProvider::findOrFail($id);
    }

    public function delete(int|string $id): void
    {
        $provider = SmsProvider::findOrFail($id);

        $this->assertNotInUse($provider);

        $provider->delete();
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function deleteMultiple(array $ids): void
    {
        $blocked = SmsProvider::whereIn('id', $ids)
            ->whereHas('smsAccounts')
            ->pluck('name');

        if ($blocked->isNotEmpty()) {
            throw SmsException::make(
                __('sms.providers.in_use'),
                400,
                'sms_provider_in_use',
                ['providers' => $blocked],
            );
        }

        SmsProvider::whereIn('id', $ids)->delete();
    }

    public function toggleActive(int|string $id): bool
    {
        $provider = SmsProvider::findOrFail($id);

        $provider->is_active = ! $provider->is_active;
        $provider->save();

        return (bool) $provider->is_active;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dropdown(): array
    {
        return SmsProvider::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (SmsProvider $provider) => [
                'id' => $provider->id,
                'name' => $provider->name,
                'key' => $provider->key,
                'label' => $this->registry->label($provider->key),
            ])
            ->all();
    }

    /**
     * Registry metadata for the create form: every adapter's label, schema and
     * capabilities. No provider name is hard-coded here.
     *
     * @return list<array<string, mixed>>
     */
    public function types(): array
    {
        $types = [];

        foreach ($this->registry->registry() as $key => $meta) {
            $types[] = [
                'value' => $key,
                'label' => $meta['label'],
                'fields' => $this->registry->configurationSchema($key),
                'capabilities' => $this->registry->capabilities($key),
            ];
        }

        return $types;
    }

    /**
     * Provider readiness check.
     *
     * This confirms the adapter is registered and the provider is active and
     * available. A live credential test goes through testDraft()/the bound
     * account tests.
     *
     * @return array<string, mixed>
     */
    public function readiness(int|string $id): array
    {
        $provider = SmsProvider::findOrFail($id);

        $ready = $this->registry->adapter($provider->key) !== null
            && $provider->is_active
            && $provider->is_available;

        if (! $ready) {
            Log::channel(config('sms.log_channel', 'stack'))->warning('SMS provider not ready', [
                'provider_id' => $provider->id,
                'key' => $provider->key,
            ]);

            throw SmsException::make(__('sms.providers.not_ready'), 422, 'sms_provider_not_ready');
        }

        return ['success' => true, 'message' => __('sms.providers.ready')];
    }

    /**
     * Test DRAFT provider credentials before a provider is saved. Never
     * persists anything.
     *
     * When provider_id is given (editing), the provider's stored secrets are
     * merged in so unchanged secrets keep working.
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function testDraft(string $key, array $configuration, int|string|null $providerId = null): array
    {
        $existing = $providerId ? SmsProvider::find($providerId) : null;
        $config = $existing?->configuration_plaintext ?? [];

        foreach ($configuration as $field => $value) {
            if (str_starts_with((string) $field, '__keep_') || $value === '' || $value === null) {
                continue;
            }

            $config[$field] = $value;
        }

        $this->assertConfigurationComplete($key, $config);

        $result = $this->registry->testConnection($key, $config);

        if (! $result['success']) {
            throw SmsException::make(
                $result['message'] ?? __('sms.providers.connection_failed'),
                422,
                'sms_provider_connection_failed',
            );
        }

        return ['success' => true, 'message' => $result['message'] ?? __('sms.providers.connection_successful')];
    }

    protected function assertNotInUse(SmsProvider $provider): void
    {
        if ($provider->smsAccounts()->exists()) {
            throw SmsException::make(__('sms.providers.in_use'), 400, 'sms_provider_in_use');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function assertConfigurationComplete(string $key, array $config, bool $isUpdate = false): void
    {
        [$valid, $missing] = $this->registry->validateConfiguration($key, $config, $isUpdate);

        if (! $valid) {
            throw SmsException::make(
                __('sms.providers.missing_configuration', ['fields' => implode(', ', $missing)]),
                422,
                'sms_provider_missing_configuration',
                ['fields' => $missing],
            );
        }
    }
}
