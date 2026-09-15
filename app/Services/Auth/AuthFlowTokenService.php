<?php

namespace App\Services\Auth;

use App\Enums\AuthFlowPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class AuthFlowTokenService
{
    public function issue(Model $authenticatable, AuthFlowPurpose $purpose): string
    {
        $token = Str::random(64);
        $ttl = now()->addMinutes((int) config('auth_flow.flow_token_expiry_minutes', 60));

        Cache::put($this->cacheKey($token), [
            'authenticatable_type' => $authenticatable->getMorphClass(),
            'authenticatable_id' => $authenticatable->getKey(),
            'purpose' => $purpose->value,
        ], $ttl);

        return $token;
    }

    /**
     * @return array{model: Model, purpose: AuthFlowPurpose}
     */
    public function resolve(string $token, AuthFlowPurpose $expectedPurpose): array
    {
        $payload = Cache::get($this->cacheKey($token));

        if (! is_array($payload)) {
            throw new RuntimeException(__('api.flow_token_invalid'));
        }

        if (($payload['purpose'] ?? null) !== $expectedPurpose->value) {
            throw new RuntimeException(__('api.flow_token_invalid'));
        }

        $modelClass = $payload['authenticatable_type'] ?? null;
        $modelId = $payload['authenticatable_id'] ?? null;

        if (! is_string($modelClass) || ! class_exists($modelClass)) {
            throw new RuntimeException(__('api.flow_token_invalid'));
        }

        /** @var Model|null $model */
        $model = $modelClass::query()->find($modelId);

        if (! $model) {
            throw new RuntimeException(__('api.flow_token_invalid'));
        }

        return [
            'model' => $model,
            'purpose' => $expectedPurpose,
        ];
    }

    public function revoke(string $token): void
    {
        Cache::forget($this->cacheKey($token));
    }

    public function rotate(string $token, Model $authenticatable, AuthFlowPurpose $purpose): string
    {
        $this->revoke($token);

        return $this->issue($authenticatable, $purpose);
    }

    private function cacheKey(string $token): string
    {
        return 'auth_flow:'.$token;
    }
}
