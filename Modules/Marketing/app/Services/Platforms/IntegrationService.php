<?php

namespace Modules\Marketing\Services\Platforms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Marketing\Events\MarketingChanged;
use Modules\Marketing\Exceptions\MarketingException;
use Modules\Marketing\Models\MarketingIntegration;
use Modules\Marketing\Models\MarketingProject;
use Modules\Marketing\Services\MarketingAccess;
use Modules\Marketing\Services\MarketingAudit;
use Throwable;

/**
 * Connecting an ad account (U10, MK-C01). The owner connects (it is their money): OAuth with a one-time `state`
 * (10 minutes, stored hashed; Meta has no PKCE for this flow), or — for testing together — the sandbox token from the
 * configuration. Disconnecting wipes the token and asks the platform to revoke it.
 */
class IntegrationService
{
    public function __construct(
        private readonly PlatformRegistry $platforms,
        private readonly MarketingAccess $access,
        private readonly MarketingAudit $audit,
    ) {}

    /** @return array{integration: MarketingIntegration, authorize_url: ?string} */
    public function connect(MarketingProject $project, Model $actor, string $provider): array
    {
        $this->access->assert($project, $actor, 'owner');
        $platform = $this->platforms->get($provider);
        if (! $platform->configured()) {
            throw new MarketingException('platform_not_configured', 422);
        }

        if (($token = $platform->sandboxToken()) !== null) {
            $accounts = $this->call(fn () => $platform->adAccounts($token));
            $wanted = $platform->sandboxAccount();
            $account = collect($accounts)->firstWhere('id', $wanted) ?? ($wanted === null ? ($accounts[0] ?? null) : ['id' => $wanted, 'name' => $wanted, 'currency' => null, 'active' => true]);
            if ($account === null) {
                throw new MarketingException('oauth_failed', 422);
            }
            $integration = MarketingIntegration::query()->updateOrCreate(
                ['project_id' => $project->id, 'provider' => $provider, 'sandbox' => true],
                [
                    'external_account_ref' => $account['id'], 'external_account_name' => $account['name'], 'account_currency' => $account['currency'],
                    'credential_ref' => $token, 'token_expires_at' => null, 'status' => MarketingIntegration::CONNECTED, 'scopes' => ['ads_management', 'ads_read'],
                    'available_accounts' => null, 'last_error' => null, 'connected_by_type' => $actor->getMorphClass(), 'connected_by_id' => $actor->getKey(),
                ],
            );
            $this->audit->record($project, $actor, 'integration.connected', $integration, meta: ['provider' => $provider, 'sandbox' => true]);
            $this->changed($integration);

            return ['integration' => $integration->fresh(), 'authorize_url' => null];
        }

        $state = Str::random(48);
        $integration = MarketingIntegration::query()->where('project_id', $project->id)->where('provider', $provider)->where('status', MarketingIntegration::PENDING)->first()
            ?? new MarketingIntegration(['project_id' => $project->id, 'provider' => $provider, 'status' => MarketingIntegration::PENDING]);
        $integration->fill([
            'oauth_state_hash' => hash('sha256', $state),
            'oauth_state_expires_at' => now()->addMinutes(10),
            'connected_by_type' => $actor->getMorphClass(),
            'connected_by_id' => $actor->getKey(),
        ])->save();
        $this->audit->record($project, $actor, 'integration.oauth_started', $integration, meta: ['provider' => $provider]);

        return ['integration' => $integration->fresh(), 'authorize_url' => $platform->authorizeUrl($state, $this->redirect($provider))];
    }

    /** The platform's redirect back (no session: the one-time `state` says which integration it is). */
    public function callback(string $provider, ?string $state, ?string $code, ?string $error): ?MarketingIntegration
    {
        if ($state === null || $state === '') {
            return null;
        }
        $integration = MarketingIntegration::query()->where('provider', $provider)->where('status', MarketingIntegration::PENDING)
            ->where('oauth_state_hash', hash('sha256', $state))->where('oauth_state_expires_at', '>', now())->first();
        if ($integration === null) {
            return null;
        }
        $integration->fill(['oauth_state_hash' => null, 'oauth_state_expires_at' => null]);
        if ($error !== null || $code === null || $code === '') {
            $integration->fill(['status' => MarketingIntegration::ERROR, 'last_error' => mb_substr((string) ($error ?? 'no code'), 0, 300)])->save();
            $this->changed($integration);

            return $integration;
        }

        $platform = $this->platforms->get($provider);
        try {
            $grant = $platform->exchangeCode($code, $this->redirect($provider));
            $accounts = collect($platform->adAccounts($grant['token']))->where('active', true)->values()->all();
        } catch (PlatformException $e) {
            $integration->fill(['status' => MarketingIntegration::ERROR, 'last_error' => mb_substr($e->getMessage(), 0, 300)])->save();
            $this->changed($integration);

            return $integration;
        }
        $integration->fill([
            'credential_ref' => $grant['token'],
            'token_expires_at' => $grant['expires_at'],
            'scopes' => $grant['scopes'],
            'last_error' => null,
        ]);
        if (count($accounts) === 1) {
            $integration->fill([
                'status' => MarketingIntegration::CONNECTED, 'external_account_ref' => $accounts[0]['id'],
                'external_account_name' => $accounts[0]['name'], 'account_currency' => $accounts[0]['currency'], 'available_accounts' => null,
            ]);
        } else {
            // None or several: the owner picks in the app (none = nothing to pick, shown as such).
            $integration->fill(['available_accounts' => $accounts]);
        }
        $integration->save();
        $this->audit->record($integration->project, null, 'integration.oauth_completed', $integration, meta: ['accounts' => count($accounts)]);
        $this->changed($integration);

        return $integration;
    }

    public function selectAccount(MarketingIntegration $integration, Model $actor, string $accountId): MarketingIntegration
    {
        $this->access->assert($integration->project, $actor, 'owner');
        $account = collect($integration->available_accounts ?? [])->firstWhere('id', $accountId);
        if ($account === null || $integration->credential_ref === null) {
            throw new MarketingException('invalid_answer', 422);
        }
        $integration->update([
            'status' => MarketingIntegration::CONNECTED, 'external_account_ref' => $account['id'], 'external_account_name' => $account['name'],
            'account_currency' => $account['currency'], 'available_accounts' => null,
        ]);
        $this->audit->record($integration->project, $actor, 'integration.connected', $integration, meta: ['provider' => $integration->provider, 'account' => $account['id']]);
        $this->changed($integration);

        return $integration->fresh();
    }

    public function disconnect(MarketingIntegration $integration, Model $actor): MarketingIntegration
    {
        $this->access->assert($integration->project, $actor, 'owner');
        $token = $integration->credential_ref;
        if ($token !== null && ! $integration->sandbox) {
            $this->platforms->get($integration->provider)->revoke($token);
        }
        $integration->update(['credential_ref' => null, 'token_expires_at' => null, 'status' => MarketingIntegration::REVOKED, 'available_accounts' => null]);
        $this->audit->record($integration->project, $actor, 'integration.disconnected', $integration);
        $this->changed($integration);

        return $integration->fresh();
    }

    /** AT-08: a token the platform no longer accepts. */
    public function markExpired(MarketingIntegration $integration): void
    {
        $integration->update(['status' => MarketingIntegration::EXPIRED, 'last_error' => 'token_expired']);
        $this->audit->record($integration->project, null, 'integration.expired', $integration);
        $this->changed($integration);
    }

    public function redirect(string $provider): string
    {
        return route('api.marketing.oauth.callback', ['provider' => $provider]);
    }

    private function call(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (PlatformException $e) {
            throw new MarketingException($e->kind === PlatformException::EXPIRED ? 'integration_expired' : 'oauth_failed', 422);
        }
    }

    private function changed(MarketingIntegration $integration): void
    {
        try {
            event(new MarketingChanged($integration->project_id, 'integration', [
                'project_id' => $integration->project?->uuid, 'integration_id' => $integration->uuid, 'status' => $integration->status,
            ]));
        } catch (Throwable $e) {
            Log::warning('[Marketing] integration push failed: '.$e->getMessage());
        }
    }
}
