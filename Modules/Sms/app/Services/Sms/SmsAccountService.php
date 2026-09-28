<?php

namespace Modules\Sms\Services\Sms;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Sms\Exceptions\SmsException;
use Modules\Sms\Models\SmsAccount;
use Modules\Sms\Models\SmsProvider;

/**
 * Account management. The SmsAccount is the single source of truth for SMS
 * credentials: the provider row carries identity and status only. This service
 * owns persistence and the invariants around those credentials (required
 * fields, secret preservation on edit, single default) so the controller and
 * the sending pipeline agree on them.
 */
class SmsAccountService
{
    public function __construct(
        protected SmsAdapterRegistry $registry,
        protected SmsService $smsService,
    ) {}

    /**
     * See SmsProviderService::query() — pagination is handled by the envelope.
     */
    public function query(Request $request): Builder
    {
        return SmsAccount::with('provider')
            ->when($request->filled('search'), fn (Builder $q) => $q->searchAndFilter($request->input('search')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->orderBy('id', $request->input('sort_order') === 'asc' ? 'asc' : 'desc');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): SmsAccount
    {
        $provider = SmsProvider::findOrFail($data['provider_id']);

        $config = $data['configuration'] ?? [];

        $this->assertConfigurationComplete($provider, $config);

        $data['configuration'] = $this->registry->prepareConfiguration($provider->key, $config);
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['is_active'] = $data['is_active'] ?? true;
        $data['sender'] = $data['sender']
            ?? $config['sender']
            ?? $config['from']
            ?? $config['sender_name']
            ?? null;

        return DB::transaction(function () use ($data) {
            if ($data['is_default']) {
                $this->clearOtherDefaults();
            }

            return SmsAccount::create($data);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int|string $id, array $data): SmsAccount
    {
        $account = SmsAccount::with('provider')->findOrFail($id);

        // An account may be re-pointed at another provider, but the credential
        // blob must then be re-validated against the NEW adapter's schema —
        // otherwise Twilio field names could be stored against SMS Misr.
        $provider = isset($data['provider_id'])
            ? SmsProvider::findOrFail($data['provider_id'])
            : $account->provider;

        if (! empty($data['configuration'])) {
            $config = $data['configuration'];

            $this->assertConfigurationComplete($provider, $config, true);

            // On edit, blank/absent secret fields keep the stored value.
            $data['configuration'] = $this->registry->prepareConfiguration(
                $provider->key,
                $config,
                $account->configuration_plaintext,
            );

            // Editing credentials invalidates the last test result.
            $data['test_status'] = 'never_tested';
            $data['test_error'] = null;
        } else {
            unset($data['configuration']);
        }

        DB::transaction(function () use ($data, $account) {
            if (! empty($data['is_default'])) {
                $this->clearOtherDefaults($account->id);
            }

            $account->update($data);
        });

        return $account->refresh()->load('provider');
    }

    public function findOrFail(int|string $id): SmsAccount
    {
        return SmsAccount::with('provider')->findOrFail($id);
    }

    public function delete(int|string $id): void
    {
        SmsAccount::findOrFail($id)->delete();
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function deleteMultiple(array $ids): void
    {
        SmsAccount::whereIn('id', $ids)->delete();
    }

    /**
     * Set this account as the single default (uniqueness enforced here).
     */
    public function setDefault(int|string $id): void
    {
        $account = SmsAccount::findOrFail($id);

        DB::transaction(function () use ($account) {
            $this->clearOtherDefaults($account->id);

            $account->is_default = true;
            $account->save();
        });
    }

    public function toggleActive(int|string $id): bool
    {
        $account = SmsAccount::findOrFail($id);

        $account->is_active = ! $account->is_active;
        $account->save();

        return (bool) $account->is_active;
    }

    /**
     * Sender select options: only usable accounts (active + provider active).
     *
     * @return list<array<string, mixed>>
     */
    public function dropdown(): array
    {
        return SmsAccount::with('provider')
            ->where('is_active', true)
            ->whereHas('provider', fn (Builder $q) => $q->where('is_active', true))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (SmsAccount $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'sender' => $account->sender,
                'is_default' => (bool) $account->is_default,
                'provider_key' => $account->provider?->key,
                'provider_label' => $account->provider ? $this->registry->label($account->provider->key) : null,
                'supports_balance' => $account->provider ? $this->registry->supports($account->provider->key, 'balance') : false,
                'supports_sandbox' => $account->provider ? $this->registry->supports($account->provider->key, 'test_mode') : false,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function providersDropdown(): array
    {
        return SmsProvider::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (SmsProvider $provider) => [
                'id' => $provider->id,
                'name' => $provider->name,
                'key' => $provider->key,
                'label' => $this->registry->label($provider->key),
                'capabilities' => $this->registry->capabilities($provider->key),
            ])
            ->all();
    }

    /**
     * Real connection test through the account's provider adapter. Uses the
     * provider sandbox when configured; never sends a live SMS.
     *
     * @return array<string, mixed>
     */
    public function testConnection(int|string $id): array
    {
        $account = $this->findOrFail($id);
        $provider = $account->provider;

        if (! $provider || ! $provider->is_active) {
            throw SmsException::make(__('sms.accounts.provider_inactive'), 422, 'sms_provider_inactive');
        }

        $config = $account->configuration_plaintext;

        if (empty(array_filter($config))) {
            throw SmsException::make(__('sms.accounts.not_configured'), 422, 'sms_account_not_configured');
        }

        $result = $this->registry->testConnection($provider->key, $config);

        $account->update([
            'test_status' => $result['success'] ? 'passed' : 'failed',
            'test_error' => $result['success'] ? null : ($result['message'] ?? null),
            'last_tested_at' => now(),
        ]);

        if (! $result['success']) {
            throw SmsException::make(
                $result['message'] ?? __('sms.accounts.connection_failed'),
                422,
                'sms_account_connection_failed',
            );
        }

        return ['success' => true, 'message' => $result['message'] ?? null];
    }

    /**
     * Test DRAFT credentials before an account is saved. Never persists.
     *
     * When account_id is given (editing), the account's stored secrets are
     * merged in so unchanged secrets keep working.
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function testDraft(int|string $providerId, array $configuration, int|string|null $accountId = null): array
    {
        $provider = SmsProvider::findOrFail($providerId);
        $existing = $accountId ? SmsAccount::with('provider')->find($accountId) : null;

        $config = $existing?->configuration_plaintext ?? [];

        foreach ($configuration as $field => $value) {
            if (str_starts_with((string) $field, '__keep_') || $value === '' || $value === null) {
                continue;
            }

            $config[$field] = $value;
        }

        $this->assertConfigurationComplete($provider, $config);

        $result = $this->registry->testConnection($provider->key, $config);

        if (! $result['success']) {
            throw SmsException::make(
                $result['message'] ?? __('sms.accounts.connection_failed'),
                422,
                'sms_account_connection_failed',
            );
        }

        return ['success' => true, 'message' => $result['message'] ?? null];
    }

    /**
     * Current provider balance for the account (capability-gated).
     *
     * @return array<string, mixed>
     */
    public function balance(int|string $id): array
    {
        $account = $this->findOrFail($id);
        $provider = $account->provider;

        if (! $provider || ! $this->registry->supports($provider->key, 'balance')) {
            throw SmsException::make(__('sms.accounts.balance_not_supported'), 422, 'sms_balance_not_supported');
        }

        $result = $this->registry->getBalance($provider->key, $account->configuration_plaintext);

        if (! $result['success']) {
            throw SmsException::make(
                $result['message'] ?? __('sms.accounts.balance_failed'),
                422,
                'sms_balance_failed',
            );
        }

        return [
            'success' => true,
            'balance' => $result['balance'] ?? null,
            'currency' => $result['currency'] ?? null,
        ];
    }

    /**
     * Send a real Test SMS through the selected account. Uses the provider
     * sandbox when configured; otherwise a LIVE SMS is sent and the frontend
     * must warn the user first.
     *
     * @return array<string, mixed>
     */
    public function sendTest(int|string|null $accountId, string $to, int|string $countryId, string $message): array
    {
        $account = $accountId
            ? SmsAccount::with('provider')->findOrFail($accountId)
            : SmsAccount::with('provider')->where('is_active', true)->orderByDesc('is_default')->latest('id')->first();

        if (! $account) {
            throw SmsException::make(__('sms.accounts.none_active'), 422, 'sms_no_active_account');
        }

        $hasSandbox = $this->smsService->sandboxConfigured($account);

        // SmsException is ApiRenderable, so a send failure becomes the standard
        // error envelope without a try/catch here.
        $result = $this->smsService->sendTestMessage($account, [
            'to' => $to,
            'country_id' => $countryId,
            'message' => $message,
        ]);

        if (! ($result['success'] ?? false)) {
            throw SmsException::make(
                $result['message'] ?? __('sms.accounts.test_failed'),
                422,
                'sms_test_send_failed',
                ['to' => $result['to'] ?? null, 'test_environment' => $hasSandbox],
            );
        }

        $text = $result['message'] ?? __('sms.accounts.test_sent');

        if (! $hasSandbox) {
            $text .= ' — '.__('sms.accounts.live_test_sent');
        }

        return [
            'success' => true,
            'message' => $text,
            'to' => $result['to'] ?? null,
            'segments' => $result['segments'] ?? null,
            'test_environment' => $hasSandbox,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function assertConfigurationComplete(SmsProvider $provider, array $config, bool $isUpdate = false): void
    {
        [$valid, $missing] = $this->registry->validateConfiguration($provider->key, $config, $isUpdate);

        if (! $valid) {
            throw SmsException::make(
                __('sms.accounts.missing_configuration', ['fields' => implode(', ', $missing)]),
                422,
                'sms_account_missing_configuration',
                ['fields' => $missing],
            );
        }
    }

    /**
     * Enforce the single-default invariant by clearing every other account.
     */
    protected function clearOtherDefaults(int|string|null $exceptId = null): void
    {
        SmsAccount::where('is_default', true)
            ->when($exceptId, fn (Builder $q) => $q->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }
}
