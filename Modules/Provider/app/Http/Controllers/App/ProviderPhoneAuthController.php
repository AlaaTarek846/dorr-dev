<?php

namespace Modules\Provider\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Provider\Http\Requests\App\ProviderOtpRequest;
use Modules\Provider\Http\Requests\App\ProviderVerifyRequest;
use Modules\Provider\Http\Resources\ProviderResource;
use Modules\Provider\Services\Account\ProviderPhoneAuthService;

/** Phone + OTP sign-in for the providers app (A-02). */
class ProviderPhoneAuthController extends Controller
{
    public function __construct(private readonly ProviderPhoneAuthService $auth) {}

    public function requestOtp(ProviderOtpRequest $request): JsonResponse
    {
        ['provider' => $provider, 'is_new' => $isNew] = $this->auth->requestOtp($request->validated('dial_code'), $request->validated('phone'));

        return ApiResponse::success([
            'masked_phone' => $this->auth->maskPhone($provider->phone),
            'is_new_provider' => $isNew,
            'resend_cooldown_seconds' => $provider->phoneOtpCooldownSeconds(),
        ], __('api.phone_otp_sent'));
    }

    public function resendOtp(ProviderOtpRequest $request): JsonResponse
    {
        $provider = $this->auth->resendOtp($request->validated('dial_code'), $request->validated('phone'));

        return ApiResponse::success([
            'masked_phone' => $this->auth->maskPhone($provider->phone),
            'resend_cooldown_seconds' => $provider->phoneOtpCooldownSeconds(),
        ], __('api.phone_otp_sent'));
    }

    public function verifyOtp(ProviderVerifyRequest $request): JsonResponse
    {
        $result = $this->auth->verify(
            $request->validated('dial_code'),
            $request->validated('phone'),
            $request->validated('code'),
            $request->validated('platform'),
        );

        return ApiResponse::success([
            'provider' => new ProviderResource($result['provider']),
            'token' => $result['token'],
            'token_type' => 'Bearer',
        ], __('api.phone_verified'));
    }

    public function logout(Request $request): JsonResponse
    {
        $playerId = $request->input('player_id');
        $this->auth->logout($request->user('provider_api'), is_string($playerId) ? $playerId : null);

        return ApiResponse::success([], __('api.logout_success'));
    }
}
