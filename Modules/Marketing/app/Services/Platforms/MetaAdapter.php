<?php

namespace Modules\Marketing\Services\Platforms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Meta Marketing API (D6: Meta first; a sandbox ad account has no funding source, delivers nothing and spends
 * nothing). Campaign + ad set are created PAUSED; the person turns them on in Ads Manager.
 */
class MetaAdapter implements AdPlatform
{
    private const OBJECTIVES = [
        'awareness' => 'OUTCOME_AWARENESS', 'traffic' => 'OUTCOME_TRAFFIC', 'engagement' => 'OUTCOME_ENGAGEMENT',
        'leads' => 'OUTCOME_LEADS', 'sales' => 'OUTCOME_SALES',
    ];

    private const GOALS = ['awareness' => 'REACH', 'traffic' => 'LINK_CLICKS', 'engagement' => 'POST_ENGAGEMENT', 'leads' => 'LINK_CLICKS', 'sales' => 'LINK_CLICKS'];

    private const CONVERSIONS = ['purchase', 'lead', 'complete_registration', 'offsite_conversion.fb_pixel_purchase', 'offsite_conversion.fb_pixel_lead'];

    public function key(): string
    {
        return 'meta';
    }

    public function configured(): bool
    {
        return (filled($this->cfg('app_id')) && filled($this->cfg('app_secret'))) || $this->sandboxToken() !== null;
    }

    public function sandboxToken(): ?string
    {
        return $this->cfg('sandbox') ? (filled($this->cfg('sandbox_token')) ? (string) $this->cfg('sandbox_token') : null) : null;
    }

    public function sandboxAccount(): ?string
    {
        $account = (string) $this->cfg('sandbox_account');

        return $account === '' ? null : (str_starts_with($account, 'act_') ? $account : 'act_'.$account);
    }

    public function authorizeUrl(string $state, string $redirect): string
    {
        return 'https://www.facebook.com/'.$this->cfg('version').'/dialog/oauth?'.http_build_query([
            'client_id' => $this->cfg('app_id'),
            'redirect_uri' => $redirect,
            'state' => $state,
            'response_type' => 'code',
            // Least scopes (POL-04): create and read ads, nothing else.
            'scope' => 'ads_management,ads_read',
        ]);
    }

    public function exchangeCode(string $code, string $redirect): array
    {
        $short = $this->call('get', 'oauth/access_token', null, [
            'client_id' => $this->cfg('app_id'), 'client_secret' => $this->cfg('app_secret'), 'redirect_uri' => $redirect, 'code' => $code,
        ]);
        $long = $this->call('get', 'oauth/access_token', null, [
            'grant_type' => 'fb_exchange_token', 'client_id' => $this->cfg('app_id'), 'client_secret' => $this->cfg('app_secret'),
            'fb_exchange_token' => (string) ($short['access_token'] ?? ''),
        ]);
        $token = (string) ($long['access_token'] ?? $short['access_token'] ?? '');
        if ($token === '') {
            throw new PlatformException(PlatformException::REJECTED, 'no token');
        }
        $expires = (int) ($long['expires_in'] ?? $short['expires_in'] ?? 0);

        return ['token' => $token, 'expires_at' => $expires > 0 ? now()->addSeconds($expires) : null, 'scopes' => ['ads_management', 'ads_read']];
    }

    public function adAccounts(string $token): array
    {
        $data = $this->call('get', 'me/adaccounts', $token, ['fields' => 'id,name,currency,account_status', 'limit' => 50]);

        return collect($data['data'] ?? [])->map(fn ($a) => [
            'id' => (string) ($a['id'] ?? ''),
            'name' => (string) ($a['name'] ?? $a['id'] ?? ''),
            'currency' => isset($a['currency']) ? (string) $a['currency'] : null,
            'active' => (int) ($a['account_status'] ?? 1) === 1,
        ])->filter(fn ($a) => $a['id'] !== '')->values()->all();
    }

    public function createCampaign(string $token, string $account, array $payload, string $marker): array
    {
        $name = mb_substr((string) $payload['name'], 0, 120).' ['.$marker.']';
        $campaign = $this->call('post', $account.'/campaigns', $token, [
            'name' => $name,
            'objective' => self::OBJECTIVES[$payload['objective']] ?? 'OUTCOME_TRAFFIC',
            'status' => 'PAUSED',
            'special_ad_categories' => '[]',
            'buying_type' => 'AUCTION',
            'daily_budget' => (int) $payload['daily_budget_minor'],
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
        ]);
        $id = (string) ($campaign['id'] ?? '');
        if ($id === '') {
            throw new PlatformException(PlatformException::UNREACHABLE, 'no id');
        }

        $warnings = [];
        $adset = null;
        try {
            $targeting = $payload['targeting'] ?? [];
            $adset = $this->call('post', $account.'/adsets', $token, [
                'name' => $name,
                'campaign_id' => $id,
                'billing_event' => 'IMPRESSIONS',
                'optimization_goal' => self::GOALS[$payload['objective']] ?? 'LINK_CLICKS',
                'targeting' => json_encode(array_filter([
                    'geo_locations' => ['countries' => array_values($targeting['countries'] ?? [])],
                    'age_min' => $targeting['age_min'] ?? null,
                    'age_max' => $targeting['age_max'] ?? null,
                ], fn ($v) => $v !== null)),
                'start_time' => $payload['start_at'],
                'end_time' => $payload['end_at'],
                'status' => 'PAUSED',
            ]);
        } catch (PlatformException $e) {
            // The campaign exists (paused, no spend possible without an ad set); say so instead of hiding it.
            $warnings[] = 'adset_not_created: '.mb_substr($e->getMessage(), 0, 160);
        }

        return ['id' => $id, 'status' => 'PAUSED', 'adset_id' => isset($adset['id']) ? (string) $adset['id'] : null, 'warnings' => $warnings];
    }

    public function findByMarker(string $token, string $account, string $marker): ?array
    {
        $data = $this->call('get', $account.'/campaigns', $token, [
            'fields' => 'id,name,status',
            'filtering' => json_encode([['field' => 'name', 'operator' => 'CONTAIN', 'value' => $marker]]),
            'limit' => 5,
        ]);
        $row = collect($data['data'] ?? [])->first(fn ($c) => str_contains((string) ($c['name'] ?? ''), $marker));

        return $row === null ? null : ['id' => (string) $row['id'], 'status' => (string) ($row['status'] ?? 'PAUSED')];
    }

    public function campaign(string $token, string $ref): ?array
    {
        $data = $this->call('get', $ref, $token, ['fields' => 'id,status,effective_status']);

        return isset($data['id']) ? ['id' => (string) $data['id'], 'status' => (string) ($data['effective_status'] ?? $data['status'] ?? '')] : null;
    }

    public function insights(string $token, string $ref, string $from, string $to): array
    {
        $data = $this->call('get', $ref.'/insights', $token, [
            'fields' => 'impressions,clicks,spend,actions,action_values',
            'time_increment' => 1,
            'time_range' => json_encode(['since' => $from, 'until' => $to]),
            'limit' => 100,
        ]);

        return collect($data['data'] ?? [])->map(function ($row) {
            $actions = collect($row['actions'] ?? [])->whereIn('action_type', self::CONVERSIONS);
            $values = collect($row['action_values'] ?? [])->whereIn('action_type', self::CONVERSIONS);

            return [
                'date' => (string) ($row['date_start'] ?? ''),
                'impressions' => isset($row['impressions']) ? (int) $row['impressions'] : null,
                'clicks' => isset($row['clicks']) ? (int) $row['clicks'] : null,
                'spend_minor' => isset($row['spend']) ? (int) round(((float) $row['spend']) * 100) : null,
                'conversions' => $actions->isEmpty() ? null : (int) $actions->sum(fn ($a) => (float) ($a['value'] ?? 0)),
                'revenue_minor' => $values->isEmpty() ? null : (int) round($values->sum(fn ($a) => (float) ($a['value'] ?? 0)) * 100),
            ];
        })->filter(fn ($r) => $r['date'] !== '')->values()->all();
    }

    public function revoke(string $token): void
    {
        try {
            $this->call('delete', 'me/permissions', $token, []);
        } catch (PlatformException) {
            // Best effort: the token is wiped on our side anyway.
        }
    }

    public function verifySignature(string $raw, ?string $signature): bool
    {
        $secret = (string) $this->cfg('app_secret');
        if ($secret === '' || $signature === null || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature);
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $path, ?string $token, array $params): array
    {
        $url = rtrim((string) $this->cfg('graph_url'), '/').'/'.$this->cfg('version').'/'.ltrim($path, '/');
        $request = Http::timeout((int) $this->cfg('timeout'))->acceptJson();
        if ($token !== null) {
            $request = $request->withToken($token);
        }

        try {
            /** @var Response $response */
            $response = $method === 'get' ? $request->get($url, $params) : ($method === 'delete' ? $request->delete($url, $params) : $request->asForm()->post($url, $params));
        } catch (ConnectionException $e) {
            throw new PlatformException(PlatformException::UNREACHABLE, 'timeout');
        }

        if ($response->successful()) {
            return (array) $response->json();
        }
        $error = (array) $response->json('error', []);
        $code = (int) ($error['code'] ?? 0);
        $message = (string) ($error['error_user_msg'] ?? $error['message'] ?? 'error '.$response->status());
        if ($code === 190 || $response->status() === 401) {
            throw new PlatformException(PlatformException::EXPIRED, $message);
        }
        if ($response->serverError() || in_array($code, [1, 2, 4, 17, 32, 341, 613], true)) {
            throw new PlatformException(PlatformException::UNREACHABLE, $message);
        }

        throw new PlatformException(PlatformException::REJECTED, $message);
    }

    private function cfg(string $key): mixed
    {
        return config('marketing.platforms.meta.'.$key);
    }
}
