<?php

namespace Modules\Sms\Services\Otp;

use App\Models\Country;
use Illuminate\Support\Facades\Log;
use Modules\Sms\Exceptions\SmsException;
use Modules\Sms\Models\WhatsApp;
use Modules\Sms\Services\Sms\Adapters\MetaWhatsAppAdapter;
use Modules\Sms\Services\Sms\SmsAvailabilityService;
use Modules\Sms\Services\Sms\SmsProviderService;

/**
 * Central OTP channel router.
 *
 * WhatsApp is the preferred global channel whenever it is available
 * for the target country and has an approved template.
 * SMS is the fallback mechanism, selected by country + priority.
 */
class OtpChannelRouter
{
    public function __construct(
        protected OtpSettingsService $settings,
        protected SmsOtpProvider $smsProvider,
        protected SmsProviderService $smsProviderService,
        protected SmsAvailabilityService $smsAvailability,
    ) {}

    /**
     * Route an OTP to the appropriate channel.
     *
     * @return array{channel: string, result: array, delivery: string}
     *
     * @throws SmsException
     */
    public function route(Country $country, string $phone, string $otp, int $expiresIn): array
    {
        if (! $this->settings->isEnabled()) {
            throw new SmsException(__('sms.otp.disabled'));
        }

        Log::channel(config('sms.log_channel', 'stack'))->info('OTP routing decision', [
            'country' => $country->code,
            'phone' => $phone,
        ]);

        // 1. Prefer WhatsApp when available.
        $whatsapp = WhatsApp::query()->where('is_active', true)->where('is_available', true)->first();

        if ($whatsapp) {
            $metaAdapter = new MetaWhatsAppAdapter($whatsapp->configuration_plaintext);
            $whatsappProvider = new WhatsAppOtpProvider($metaAdapter);
            $whatsappResult = $whatsappProvider->resolve($country);

            if ($whatsappResult['available']) {
                try {
                    $result = $whatsappProvider->send($whatsapp, $phone, $otp, $expiresIn);
                    Log::channel(config('sms.log_channel', 'stack'))->info('OTP sent via WhatsApp', ['country' => $country->code]);
                    return ['channel' => 'whatsapp', 'result' => $result, 'delivery' => 'sent'];
                } catch (\Throwable $e) {
                    Log::channel(config('sms.log_channel', 'stack'))->warning('WhatsApp OTP send failed, falling back to SMS', [
                        'country' => $country->code,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // 2. WhatsApp unavailable/configuration invalid/not approved → SMS fallback.
        $smsResult = $this->smsProvider->resolveAndSend($country, $phone, $otp);

        if ($smsResult['success'] ?? false) {
            Log::channel(config('sms.log_channel', 'stack'))->info('OTP sent via SMS fallback', ['country' => $country->code]);
            return ['channel' => 'sms', 'result' => $smsResult, 'delivery' => 'fallback_sent'];
        }

        // 3. No channel available.
        Log::channel(config('sms.log_channel', 'stack'))->warning('No OTP channel available', ['country' => $country->code]);
        throw new SmsException(__('sms.otp.no_channel_available'), 422, 'otp_no_channel_available');
    }
}
