<?php

namespace Modules\Marketing\Services\Platforms;

use Illuminate\Support\Carbon;

/**
 * One ad platform behind the adapter layer (spec 03). Every call takes the token from the vault and returns plain
 * data; failures are PlatformException. Nothing here decides anything — approvals and limits live in the services.
 */
interface AdPlatform
{
    public function key(): string;

    /** Can it connect at all (app keys set, or a sandbox token)? */
    public function configured(): bool;

    /** Connect with the sandbox token from the configuration instead of OAuth (testing without real spend). */
    public function sandboxToken(): ?string;

    public function sandboxAccount(): ?string;

    public function authorizeUrl(string $state, string $redirect): string;

    /** @return array{token: string, expires_at: ?Carbon, scopes: list<string>} */
    public function exchangeCode(string $code, string $redirect): array;

    /** @return list<array{id: string, name: string, currency: ?string, active: bool}> */
    public function adAccounts(string $token): array;

    /**
     * Creates the campaign **paused**, with `marker` in its name so an unknown result can be found again.
     *
     * @param  array<string, mixed>  $payload  MarketingCampaign::payload() plus the creative text
     * @return array{id: string, status: string, adset_id: ?string, warnings: list<string>}
     */
    public function createCampaign(string $token, string $account, array $payload, string $marker): array;

    /** @return array{id: string, status: string}|null */
    public function findByMarker(string $token, string $account, string $marker): ?array;

    /** @return array{id: string, status: string}|null */
    public function campaign(string $token, string $ref): ?array;

    /** @return list<array{date: string, impressions: ?int, clicks: ?int, spend_minor: ?int, conversions: ?int, revenue_minor: ?int}> */
    public function insights(string $token, string $ref, string $from, string $to): array;

    public function revoke(string $token): void;

    /** Checks a webhook's signature over the raw body. */
    public function verifySignature(string $raw, ?string $signature): bool;
}
