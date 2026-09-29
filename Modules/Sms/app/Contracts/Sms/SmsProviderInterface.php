<?php

namespace Modules\Sms\Contracts\Sms;

/**
 * SmsProviderInterface — the contract every SMS provider adapter implements.
 *
 * A provider adapter is the ONLY place that talks to a concrete SMS vendor's
 * HTTP API. Higher layers (SmsService, controllers, future CRM modules) depend
 * on this interface and never on Twilio/SMS Misr/... directly.
 *
 * Not every capability exists on every provider. Adapters advertise what they
 * support via capabilities(); callers MUST gate UI/behavior on those flags.
 *
 * @method array capabilities() List of supported capability keys.
 */
interface SmsProviderInterface
{
    /**
     * The stable adapter key (e.g. "twilio", "sms_misr").
     */
    public function key(): string;

    /**
     * Human-readable provider label (e.g. "Twilio").
     */
    public function label(): string;

    /**
     * Configuration field schema (metadata only — never secrets). Each field:
     * ['key','label','type','required','secret','options'?].
     */
    public function configurationSchema(): array;

    /**
     * Capabilities this provider supports, e.g.:
     *   send_sms, send_bulk, balance, test_mode,
     *   delivery_reports, sender_ids, otp, two_way, sender_approval.
     */
    public function capabilities(): array;

    /**
     * Real connection / credential test. Balance or account-status endpoint
     * is preferred. Returns ['success'=>bool,'message'=>string,'balance'?=>mixed].
     * MUST use the sandbox/test environment when configured.
     */
    public function testConnection(array $config): array;

    /**
     * Fetch the current account balance. Returns ['success'=>bool,'message'=>string,
     * 'balance'=>int|float|null,'currency'=>string|null]. Throws/admits not
     * supported if the provider lacks the 'balance' capability.
     */
    public function getBalance(array $config): array;

    /**
     * List approved Sender IDs / numbers. Returns ['success'=>bool,
     * 'message'=>string,'senders'=>array]. Empty when not supported.
     */
    public function getSenderIds(array $config): array;

    /**
     * Send a single SMS.
     *
     * @param  array  $config  Raw (plaintext, decrypted) provider config.
     * @param  array  $payload  ['to'=>E.164|raw,'from'=>senderId|number,
     *                          'message'=>text,'test_only'=>bool]
     * @return array ['success'=>bool,'message'=>string,'provider_message_id'=>?string,
     *               'segments'=>?int,'test_only'=>bool]
     */
    public function send(array $config, array $payload): array;

    /**
     * Normalize a raw vendor error into a stable, safe, user-facing message.
     * Never includes tokens/passwords/secrets or raw sensitive payloads.
     */
    public function normalizeError(\Throwable|\Exception|string|array $error): string;
}
