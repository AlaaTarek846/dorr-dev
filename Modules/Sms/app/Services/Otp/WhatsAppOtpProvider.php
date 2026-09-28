<?php

namespace Modules\Sms\Services\Otp;

use App\Models\Country;
use Modules\Sms\Exceptions\SmsException;
use Modules\Sms\Models\WhatsApp;
use Modules\Sms\Models\WhatsAppTemplate;
use Modules\Sms\Services\Sms\Adapters\MetaWhatsAppAdapter;

/**
 * WhatsApp OTP provider — preferred global channel.
 *
 * Availability requires:
 *   1. WhatsApp config is active.
 *   2. Target country is supported.
 *   3. An OTP template is configured and approved by Meta.
 */
class WhatsAppOtpProvider
{
    public function __construct(protected MetaWhatsAppAdapter $metaAdapter) {}

    /**
     * Determine whether WhatsApp is available for the country.
     *
     * @return array{available: bool, whatsapp?: WhatsApp, reason?: string}
     */
    public function resolve(Country $country): array
    {
        $whatsapp = $this->activeWhatsApp();

        if (! $whatsapp) {
            return ['available' => false, 'reason' => 'no_active_whatsapp'];
        }

        if (! $this->supportsCountry($whatsapp, $country)) {
            return ['available' => false, 'reason' => 'country_not_supported'];
        }

        $template = $this->approvedTemplate($whatsapp);

        if (! $template) {
            return ['available' => false, 'reason' => 'no_approved_template'];
        }

        return ['available' => true, 'whatsapp' => $whatsapp];
    }

    /**
     * Send an OTP through WhatsApp using the approved template.
     *
     * @return array{success: bool, message: string, provider_message_id?: string}
     *
     * @throws SmsException
     */
    public function send(WhatsApp $whatsapp, string $to, string $otp, int $expiresIn): array
    {
        $template = $this->approvedTemplate($whatsapp);

        if (! $template) {
            throw new SmsException(__('sms.whatsapp.no_approved_template'), 422, 'whatsapp_no_approved_template');
        }

        $config = $whatsapp->configuration_plaintext;
        $adapter = new MetaWhatsAppAdapter($config);

        return $adapter->sendOtp($to, $template->template_name, [
            'otp' => $otp,
            'expiration' => (string) $expiresIn,
        ]);
    }

    /**
     * The active WhatsApp configuration, or null.
     */
    protected function activeWhatsApp(): ?WhatsApp
    {
        return WhatsApp::query()
            ->where('is_active', true)
            ->where('is_available', true)
            ->first();
    }

    /**
     * Does the WhatsApp configuration support the given country?
     */
    protected function supportsCountry(WhatsApp $whatsapp, Country $country): bool
    {
        return $whatsapp->countries()
            ->where('country_id', $country->id)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * The approved (Meta-approved) template, or null.
     */
    protected function approvedTemplate(WhatsApp $whatsapp): ?WhatsAppTemplate
    {
        return $whatsapp->templates()
            ->where('is_active', true)
            ->where('meta_status', 'approved')
            ->first();
    }
}
