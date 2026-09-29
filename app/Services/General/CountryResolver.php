<?php

namespace App\Services\General;

use App\Models\Country;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * "What country is this request for?" — used well beyond the wallet (any
 * context-dependent catalog/pricing/eligibility check), so it lives in
 * app/Services/General next to CountryService, not inside Modules/Wallet.
 *
 * Priority (docs/wallet-plan.md §8 — decided this way specifically because
 * IP is spoofable and must never be trusted to *gate* anything, only to
 * guess a sensible default):
 *   1. Explicit choice (X-Country header or ?country= query param).
 *   2. The authenticated user's/provider's own country_id — deliberately
 *      NOT admins, who work across countries rather than being scoped to one.
 *   3. IP-based best guess, via the existing getCountryCodeByIp() helper
 *      (app/Support/helpers.php — already has its own cache/timeout/fallback).
 *   4. countries.is_default — never a hardcoded literal.
 *
 * Always returns a real, active Country; throws only if the seed data itself
 * is missing a default country (a deployment bug, not a request-time one).
 */
class CountryResolver
{
    public function resolve(Request $request): Country
    {
        return $this->fromExplicitChoice($request)
            ?? $this->fromAuthenticatedProfile()
            ?? $this->fromIp()
            ?? $this->fallbackDefault();
    }

    private function fromExplicitChoice(Request $request): ?Country
    {
        $code = $request->header('X-Country') ?? $request->query('country');

        if (! is_string($code) || $code === '') {
            return null;
        }

        return $this->activeCountryByCode($code);
    }

    private function fromAuthenticatedProfile(): ?Country
    {
        foreach (['user_api', 'provider_api'] as $guard) {
            $owner = auth($guard)->user();

            if ($owner?->country_id === null) {
                continue;
            }

            $country = Country::query()->where('status', true)->find($owner->country_id);

            if ($country !== null) {
                return $country;
            }
        }

        return null;
    }

    private function fromIp(): ?Country
    {
        return $this->activeCountryByCode(getCountryCodeByIp());
    }

    private function fallbackDefault(): Country
    {
        return Country::query()->where('is_default', true)->where('status', true)->first()
            ?? throw new RuntimeException('No default country configured — seed at least one country with is_default=true.');
    }

    private function activeCountryByCode(string $code): ?Country
    {
        return Country::query()->where('code', $code)->where('status', true)->first();
    }
}
