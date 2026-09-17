<?php

namespace Modules\Provider\Http\Controllers;

use App\Enums\AuthFlowPurpose;
use App\Enums\UserStatus;
use App\Enums\VerificationType;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthFlowTokenService;
use App\Services\Auth\VerificationCodeService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Provider\Http\Requests\ProviderCreatePasswordRequest;
use Modules\Provider\Http\Requests\ProviderRegisterRequest;
use Modules\Provider\Http\Requests\ProviderResendVerificationRequest;
use Modules\Provider\Http\Requests\ProviderVerifyEmailRequest;
use Modules\Provider\Http\Resources\ProviderResource;
use Modules\Provider\Models\Provider;
use RuntimeException;

class ProviderRegistrationController extends Controller
{
    public function __construct(
        private readonly AuthFlowTokenService $flowTokens,
        private readonly VerificationCodeService $verificationCodes,
    ) {}

    public function register(ProviderRegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $provider = Provider::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => null,
            'status' => UserStatus::Active,
            'email_verified_at' => null,
        ]);

        $this->verificationCodes->send($provider, VerificationType::Email, $provider->email);

        $flowToken = $this->flowTokens->issue($provider, AuthFlowPurpose::EmailVerification);

        return ApiResponse::created([
            'flow_token' => $flowToken,
            'email' => $this->verificationCodes->maskEmail($provider->email),
            'next_step' => 'verify_email',
            'resend_cooldown_seconds' => $this->resendCooldownSeconds(),
        ], __('api.registration_started'));
    }

    public function verifyEmail(ProviderVerifyEmailRequest $request): JsonResponse
    {
        try {
            $resolved = $this->flowTokens->resolve(
                $request->validated('flow_token'),
                AuthFlowPurpose::EmailVerification,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'flow_token' => [$exception->getMessage()],
            ]);
        }

        /** @var Provider $provider */
        $provider = $resolved['model'];

        if (! $provider instanceof Provider) {
            throw ValidationException::withMessages([
                'flow_token' => [__('api.flow_token_invalid')],
            ]);
        }

        $this->verificationCodes->verify($provider, VerificationType::Email, $request->validated('code'));

        $provider->forceFill(['email_verified_at' => now()])->save();

        $this->flowTokens->revoke($request->validated('flow_token'));

        $passwordFlowToken = $this->flowTokens->issue($provider, AuthFlowPurpose::PasswordSetup);

        return ApiResponse::success([
            'flow_token' => $passwordFlowToken,
            'next_step' => 'create_password',
        ], __('api.email_verified'));
    }

    public function resendVerification(ProviderResendVerificationRequest $request): JsonResponse
    {
        try {
            $resolved = $this->flowTokens->resolve(
                $request->validated('flow_token'),
                AuthFlowPurpose::EmailVerification,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'flow_token' => [$exception->getMessage()],
            ]);
        }

        /** @var Provider $provider */
        $provider = $resolved['model'];

        if (! $provider instanceof Provider) {
            throw ValidationException::withMessages([
                'flow_token' => [__('api.flow_token_invalid')],
            ]);
        }

        if ($provider->email_verified_at) {
            return ApiResponse::error(__('api.email_already_verified'), 409);
        }

        $this->verificationCodes->send($provider, VerificationType::Email, $provider->email);

        return ApiResponse::success([
            'email' => $this->verificationCodes->maskEmail($provider->email),
            'resend_cooldown_seconds' => $this->resendCooldownSeconds(),
        ], __('api.verification_code_sent'));
    }

    public function createPassword(ProviderCreatePasswordRequest $request): JsonResponse
    {
        try {
            $resolved = $this->flowTokens->resolve(
                $request->validated('flow_token'),
                AuthFlowPurpose::PasswordSetup,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'flow_token' => [$exception->getMessage()],
            ]);
        }

        /** @var Provider $provider */
        $provider = $resolved['model'];

        if (! $provider instanceof Provider) {
            throw ValidationException::withMessages([
                'flow_token' => [__('api.flow_token_invalid')],
            ]);
        }

        if (! $provider->email_verified_at) {
            return ApiResponse::error(__('api.email_not_verified'), 403);
        }

        if ($provider->password) {
            return ApiResponse::error(__('api.password_already_set'), 409);
        }

        $provider->update([
            'password' => $request->validated('password'),
        ]);

        $this->flowTokens->revoke($request->validated('flow_token'));
        $provider->tokens()->delete();

        $token = $provider->createToken('provider-api')->plainTextToken;

        return ApiResponse::success([
            'provider' => new ProviderResource($provider->fresh()),
            'token' => $token,
            'token_type' => 'Bearer',
        ], __('api.password_created'));
    }

    private function resendCooldownSeconds(): int
    {
        return (int) config('auth_flow.otp_resend_cooldown_seconds', 60);
    }
}
