<?php

namespace Modules\Sms\Services\Sms;

use App\Models\Country;
use Modules\Sms\Models\SmsProvider;
use Modules\Sms\Exceptions\SmsException;
use Modules\Sms\Services\Sms\SmsAdapterRegistry;
use Modules\Sms\Services\Sms\SmsAvailabilityService;
use Modules\Sms\Services\Sms\SmsMessageHelper;
use Modules\Sms\Services\Sms\PhoneNumberNormalizer;

/**
 * SmsService — the gateway used to send an SMS through a provider
 * configuration.
 *
 * Scope is deliberately narrow: resolve the provider config, guard it,
 * normalize the recipient for the selected country, and call the provider
 * adapter. There is NO SmsSend persistence, NO queue, and NO activity log
 * here — the admin "send a test SMS" flow only needs an immediate
 * provider round-trip.
 */
class SmsService
{
    public function __construct(
        protected SmsAdapterRegistry $registry,
        protected SmsAvailabilityService $availability,
        protected SmsMessageHelper $messageHelper,
        protected PhoneNumberNormalizer $normalizer,
    ) {}

    /**
     * Send a single message through a provider configuration, synchronously.
     *
     * @param  array{to: string, country_id: int|string, message?: string, from?: string|null, test_only?: bool}  $payload
     * @return array{success: bool, message: string, provider_message_id?: string|null, segments?: int, test_only: bool}
     *
     * @throws SmsException
     */
    public function send(SmsProvider $provider, array $config, array $payload): array
    {
        $this->assertProviderReady($provider);

        $adapter = $this->registry->adapter($provider->key);

        if (! $adapter) {
            throw new SmsException(__('sms.providers.unsupported', ['key' => $provider->key]));
        }

        $message = trim((string) ($payload['message'] ?? ''));

        if ($message === '') {
            throw new SmsException(__('sms.providers.body_required'));
        }

        $to = $this->normalizer->normalize(
            (string) $payload['to'],
            $this->resolveCountry($payload),
        );

        $result = $adapter->send($config, [
            'to' => $to,
            'from' => $payload['from'] ?? null,
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
     * A provider may only be used when it is active, available, has passed
     * its connection test, and has configuration.
     *
     * @throws SmsException
     */
    public function assertProviderReady(SmsProvider $provider): void
    {
        if (! $this->availability->providerReady($provider)) {
            throw new SmsException(__('sms.providers.account_unavailable'));
        }
    }

    /**
     * Is a provider's destination expected in E.164? Most providers want it;
     * the registry in config/sms.php is authoritative.
     */
    public function wantsE164(SmsProvider $provider): bool
    {
        return in_array($provider->key(), config('sms.e164_providers', []), true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveCountry(array $payload): Country
    {
        $countryId = $payload['country_id'] ?? null;

        if (! $countryId) {
            throw new SmsException(__('sms.providers.country_required'));
        }

        $country = Country::find($countryId);

        if (! $country) {
            throw new SmsException(__('sms.providers.country_not_found'));
        }

        return $country;
    }
}
