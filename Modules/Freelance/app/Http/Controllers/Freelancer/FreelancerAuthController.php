<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Http\Requests\FreelancerOtpRequest;
use Modules\Freelance\Http\Requests\FreelancerVerifyRequest;
use Modules\Freelance\Http\Resources\FreelancerResource;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Account\FreelancerAuthService;

/** Phone + OTP sign-in for the freelancer app, website and dashboard (FL-003). */
class FreelancerAuthController extends Controller
{
    public function __construct(private readonly FreelancerAuthService $auth) {}

    public function requestOtp(FreelancerOtpRequest $request): JsonResponse
    {
        ['freelancer' => $freelancer, 'is_new' => $isNew] = $this->auth->requestOtp($request->validated('dial_code'), $request->validated('phone'));

        return ApiResponse::success([
            'masked_phone' => $this->auth->maskPhone($freelancer->phone),
            'is_new_freelancer' => $isNew,
            'resend_cooldown_seconds' => $freelancer->phoneOtpCooldownSeconds(),
        ], __('api.phone_otp_sent'));
    }

    public function resendOtp(FreelancerOtpRequest $request): JsonResponse
    {
        $freelancer = $this->auth->resendOtp($request->validated('dial_code'), $request->validated('phone'));

        return ApiResponse::success([
            'masked_phone' => $this->auth->maskPhone($freelancer->phone),
            'resend_cooldown_seconds' => $freelancer->phoneOtpCooldownSeconds(),
        ], __('api.phone_otp_sent'));
    }

    public function verifyOtp(FreelancerVerifyRequest $request): JsonResponse
    {
        $result = $this->auth->verify(
            $request->validated('dial_code'),
            $request->validated('phone'),
            $request->validated('code'),
            $request->safe()->only(['device_id', 'device_name', 'platform']),
            $request->ip(),
            $request->userAgent(),
        );

        return ApiResponse::success([
            'freelancer' => new FreelancerResource($result['freelancer']),
            'token' => $result['token'],
            'token_type' => 'Bearer',
        ], __('api.phone_verified'));
    }

    public function me(Request $request): JsonResponse
    {
        $freelancer = $this->freelancer($request);
        $freelancer->forceFill(['last_active_at' => now()])->saveQuietly();
        $freelancer->devices()->where('token_id', $freelancer->currentAccessToken()?->id)->update(['last_active_at' => now()]);

        return ApiResponse::success(new FreelancerResource($freelancer), __('api.retrieved'));
    }

    public function logout(Request $request): JsonResponse
    {
        $freelancer = $this->freelancer($request);

        // The phone's push id stops pointing at this freelancer, or the next person on the phone would get their alerts.
        $playerId = $request->input('player_id');
        if (is_string($playerId) && $playerId !== '') {
            $freelancer->notificationDevices()->where('player_id', $playerId)->delete();
        }

        $this->auth->logout($freelancer);

        return ApiResponse::success([], __('api.logout_success'));
    }

    private function freelancer(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
