<?php

namespace Modules\Sms\Support;

use Modules\Sms\Models\WhatsAppTemplate;

/**
 * WhatsAppTemplatePayload — pure translation between a stored template and
 * the Meta Graph `create/upsert message template` contract.
 *
 * It holds NO state and performs NO HTTP calls, so the Meta orchestration in
 * WhatsAppTemplateService stays the only thing that talks to the adapter.
 */
class WhatsAppTemplatePayload
{
    /**
     * The OTP button Meta requires on every AUTHENTICATION template. `text`
     * is intentionally omitted: Meta supplies the default "Copy code" label.
     */
    public const AUTH_OTP_BUTTON = ['type' => 'OTP', 'otp_type' => 'COPY_CODE'];

    /**
     * Sample values Meta requires for every variable used in a component.
     */
    protected const SAMPLE_VALUES = [
        1 => '123456',
        2 => 'Sample value 2',
    ];

    /**
     * Build the Meta payload for a template.
     *
     * `text` keeps the raw {{n}} placeholders (never pre-rendered values);
     * `example` carries the positional sample values Meta requires.
     *
     * @return array{name: string, language: string, category: string, components: array}
     */
    public function build(WhatsAppTemplate $template, string $languageCode): array
    {
        $body = (string) ($template->body ?? '');
        $components = [];

        if (trim($body) !== '') {
            $bodyComponent = ['type' => 'BODY', 'text' => $body];

            $positions = $this->positions($body);
            if ($positions !== []) {
                $bodyComponent['example'] = ['body_text' => $this->sampleValues($positions)];
            }

            $components[] = $bodyComponent;
        }

        // AUTHENTICATION templates are rejected by Meta without an OTP button,
        // so it is always injected for that category.
        if ($this->isAuthentication($template)) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => [self::AUTH_OTP_BUTTON]];
        }

        if ($components === []) {
            $components[] = ['type' => 'BODY', 'text' => ''];
        }

        return [
            'name' => (string) $template->template_name,
            'language' => $languageCode,
            'category' => $this->category($template),
            'components' => $components,
        ];
    }

    /**
     * Enforce Meta's fixed rules for AUTHENTICATION templates before they
     * reach the API — without them Meta rejects the payload with code 100:
     *  - exactly one variable is allowed (the one-time code),
     *  - the code must sit at position {{1}}.
     *
     * @return string|null localized error, or null when eligible
     */
    public function authenticationError(WhatsAppTemplate $template): ?string
    {
        if (! $this->isAuthentication($template)) {
            return null;
        }

        $positions = $this->positions((string) ($template->body ?? ''));

        if ($positions === []) {
            return __('sms.whatsapp.auth_otp_variable_required');
        }

        if ($positions !== [1]) {
            return __('sms.whatsapp.auth_single_variable_required');
        }

        return null;
    }

    public function isAuthentication(WhatsAppTemplate $template): bool
    {
        return strtoupper((string) $template->category) === 'AUTHENTICATION';
    }

    public function category(WhatsAppTemplate $template): string
    {
        $category = strtoupper((string) $template->category);

        return in_array($category, ['AUTHENTICATION', 'MARKETING', 'UTILITY'], true)
            ? $category
            : 'UTILITY';
    }

    /**
     * The {{n}} positions used by the given texts, sorted ascending.
     *
     * @return list<int>
     */
    public function positions(string ...$texts): array
    {
        $found = [];

        foreach ($texts as $text) {
            preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $matches);

            foreach ($matches[1] as $position) {
                $found[(int) $position] = true;
            }
        }

        $positions = array_keys($found);
        sort($positions);

        return $positions;
    }

    /**
     * Map the used positions onto their sample values, in order.
     *
     * @param  list<int>  $positions
     * @return list<string>
     */
    public function sampleValues(array $positions): array
    {
        return array_map(
            fn (int $position) => self::SAMPLE_VALUES[$position] ?? 'Sample value '.$position,
            $positions,
        );
    }

    /**
     * Convert a Meta component list into a displayable body text.
     */
    public function bodyFromComponents(array $components): string
    {
        foreach ($components as $component) {
            if (($component['type'] ?? null) === 'BODY') {
                return (string) ($component['text'] ?? '');
            }
        }

        return '';
    }
}
