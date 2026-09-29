<?php

namespace Modules\Sms\Services\Otp;

use App\Models\Country;
use Modules\Sms\Exceptions\SmsException;
use Modules\Sms\Models\SmsProvider;
use Modules\Sms\Services\Sms\SmsAvailabilityService;
use Modules\Sms\Services\Sms\SmsProviderService;
use Modules\Sms\Services\Sms\SmsService;

/**
 * SMS OTP provider — fallback channel.
 *
 * Selects the best active SMS provider for the country by priority,
 * then sends through SmsService.
 */
class SmsOtpProvider
{
    public function __construct(
        protected SmsProviderService $smsProviderService,
        protected SmsAvailabilityService $smsAvailability,
        protected SmsService $smsService,
    ) {}

    /**
     * Resolve an available SMS provider for the country and send the OTP.
     *
     * @return array{success: bool, message?: string, provider?: string}
     */
    public function resolveAndSend(Country $country, string $to, string $otp): array
    {
        $providers = $this->smsProviderService->activeProvidersForCountry($country);

        if ($providers->isEmpty()) {
            return ['success' => false, 'message' => 'No SMS provider available for this country'];
        }

        $provider = $providers->first();

        try {
            $result = $this->smsService->send($provider, $provider->configuration_plaintext, [
                'to' => $to,
                'country_id' => $country->id,
                'message' => "Your verification code is {$otp}.",
            ]);

            return ['success' => $result['success'] ?? false, 'provider' => $provider->key, 'message' => $result['message'] ?? 'SMS OTP sent'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Check whether any active SMS provider supports the given country.
     */
    public function isAvailable(Country $country): bool
    {
        return $this->smsProviderService->activeProvidersForCountry($country)->isNotEmpty();
    }
}
