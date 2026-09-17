<?php

namespace Modules\Provider\Http\Controllers;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Provider\Http\Requests\ProviderLoginRequest;
use Modules\Provider\Http\Resources\ProviderResource;
use Modules\Provider\Models\Provider;

class ProviderAuthController extends Controller
{
    public function login(ProviderLoginRequest $request)
    {
        $provider = Provider::query()
            ->where('email', $request->validated('email'))
            ->first();

        if (! $provider || ! $provider->password || ! Hash::check($request->validated('password'), $provider->password)) {
            throw ValidationException::withMessages([
                'email' => [__('api.invalid_credentials')],
            ]);
        }

        if ($provider->status === UserStatus::Blocked) {
            return ApiResponse::error(__('api.account_blocked'), 403);
        }

        if ($provider->status === UserStatus::Inactive) {
            return ApiResponse::error(__('api.account_inactive'), 403);
        }

        $provider->tokens()->delete();

        $provider->load('services.category.translations');

        $token = $provider->createToken('provider-api')->plainTextToken;

        return ApiResponse::success([
            'provider' => new ProviderResource($provider),
            'token' => $token,
            'token_type' => 'Bearer',
        ], __('api.login_success'));
    }

    public function logout()
    {
        request()->user('provider_api')?->currentAccessToken()?->delete();

        return ApiResponse::success([], __('api.logout_success'));
    }

    public function me()
    {
        /** @var Provider $provider */
        $provider = request()->user('provider_api');
        $provider->load(['country.flag', 'services.category.translations']);

        return ApiResponse::success(
            new ProviderResource($provider),
            __('api.retrieved'),
        );
    }

    public function checkToken(): JsonResponse
    {
        $token = request()->bearerToken() ?? request()->input('token');

        if (! $token) {
            return ApiResponse::error(__('api.token_invalid'), 401);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken || ! $accessToken->tokenable instanceof Provider) {
            return ApiResponse::error(__('api.token_invalid'), 401);
        }

        /** @var Provider $provider */
        $provider = $accessToken->tokenable;

        if ($provider->status !== UserStatus::Active) {
            return ApiResponse::error(
                $provider->status === UserStatus::Blocked
                    ? __('api.account_blocked')
                    : __('api.account_inactive'),
                403,
            );
        }

        return ApiResponse::success([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'valid' => true,
            'provider' => new ProviderResource($provider->load([
                'country.flag',
                'services.category.translations',
            ])),
        ], __('api.token_valid'));
    }
}
