<?php

namespace Modules\Sms\Services\Sms;

use App\Models\Country;
use Modules\Sms\Contracts\Sms\SmsProviderInterface;
use Modules\Sms\Exceptions\SmsException;
use Modules\Sms\Models\SmsAccount;

/**
 * SmsService — the gateway used to send an SMS through a configured account.
 *
 * Scope is deliberately narrow: resolve the account, guard it, normalize the
 * recipient for the selected country, and call the provider adapter. There is
 * NO SmsSend persistence, NO queue, and NO activity log here — the admin
 * "send a test SMS" flow only needs an immediate provider round-trip.
 */
class SmsService
{
    public function __construct(
        protected SmsAdapterRegistry $providerService,
        protected SmsAvailabilityService $availability,
        protected SmsMessageHelper $messageHelper,
        protected PhoneNumberNormalizer $normalizer,
    ) {}

    /**
     * Send a single message through the account's provider, synchronously.
     *
     * @param  array{to: string, country_id: int|string, message?: string, from?: string|null, test_only?: bool}  $payload
     * @return array{success: bool, message: string, provider_message_id?: string|null, segments?: int, test_only: bool}
     *
     * @throws SmsException
     */
    public function send(SmsAccount $account, array $payload): array
    {
        $this->assertUsable($account);

        $adapter = $this->providerService->adapter($account->provider?->key);

        if (! $adapter) {
            throw new SmsException(__('sms.accounts.unsupported_provider', [
                'key' => (string) $account->provider?->key,
            ]));
        }

        $message = trim((string) ($payload['message'] ?? ''));

        if ($message === '') {
            throw new SmsException(__('sms.accounts.body_required'));
        }

        $to = $this->normalizer->normalize(
            (string) $payload['to'],
            $this->resolveCountry($payload),
        );

        $result = $adapter->send($account->configuration_plaintext, [
            'to' => $to,
            'from' => $payload['from'] ?? $account->sender,
            'message' => $message,
            'test_only' => (bool) ($payload['test_only'] ?? false),
        ]);

        if (($result['success'] ?? false) && ! isset($result['segments'])) {
            $result['segments'] = $this->messageHelper->estimateSegments($message);
        }

        $result['test_only'] = (bool) ($payload['test_only'] ?? false);
        $result['to'] = $to;

        return $result;
    }

    /**
     * Send a test message from the admin SMS settings screen. Honours the
     * provider sandbox when the stored configuration selects one.
     *
     * @param  array{to: string, country_id: int|string, message?: string}  $payload
     * @return array<string, mixed>
     *
     * @throws SmsException
     */
    public function sendTestMessage(SmsAccount $account, array $payload): array
    {
        return $this->send($account, [
            'to' => $payload['to'],
            'country_id' => $payload['country_id'],
            'message' => $payload['message'] ?: __('sms.accounts.default_test_message'),
            'test_only' => true,
        ]);
    }

    /**
     * An account may only be used when account active + test passed + provider
     * active.
     *
     * @throws SmsException
     */
    public function assertUsable(SmsAccount $account): void
    {
        if (! $this->availability->accountReady($account)) {
            throw new SmsException(__('sms.accounts.account_unavailable'));
        }
    }

    /**
     * True when the account's stored configuration selects a sandbox/test env.
     */
    public function sandboxConfigured(SmsAccount $account): bool
    {
        $config = $account->configuration_plaintext;

        return ! empty($config['test_mode'])
            || (($config['environment'] ?? 'live') === 'test')
            || ! empty($config['test_api_key']);
    }

    /**
     * Is a provider's destination expected in E.164? Most providers want it;
     * the registry in config/sms.php is authoritative.
     */
    public function wantsE164(SmsProviderInterface $adapter): bool
    {
        return in_array($adapter->key(), config('sms.e164_providers', []), true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveCountry(array $payload): Country
    {
        $countryId = $payload['country_id'] ?? null;

        if (! $countryId) {
            throw new SmsException(__('sms.accounts.country_required'));
        }

        $country = Country::find($countryId);

        if (! $country) {
            throw new SmsException(__('sms.accounts.country_not_found'));
        }

        return $country;
    }
}
