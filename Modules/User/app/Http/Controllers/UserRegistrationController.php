<?php

namespace Modules\User\Http\Controllers;

use App\Enums\AuthFlowPurpose;
use App\Enums\UserStatus;
use App\Enums\VerificationType;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthFlowTokenService;
use App\Services\Auth\VerificationCodeService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\User\Http\Requests\CreatePasswordRequest;
use Modules\User\Http\Requests\ResendVerificationRequest;
use Modules\User\Http\Requests\UserRegisterRequest;
use Modules\User\Http\Requests\VerifyEmailRequest;
use Modules\User\Http\Resources\UserResource;
use Modules\User\Models\User;
use RuntimeException;

class UserRegistrationController extends Controller
{
    public function __construct(
        private readonly AuthFlowTokenService $flowTokens,
        private readonly VerificationCodeService $verificationCodes,
    ) {}

    public function register(UserRegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => null,
            'status' => UserStatus::Active,
            'email_verified_at' => null,
        ]);

        $this->verificationCodes->send($user, VerificationType::Email, $user->email);

        $flowToken = $this->flowTokens->issue($user, AuthFlowPurpose::EmailVerification);

        return ApiResponse::created([
            'flow_token' => $flowToken,
            'email' => $this->verificationCodes->maskEmail($user->email),
            'next_step' => 'verify_email',
            'resend_cooldown_seconds' => $this->resendCooldownSeconds(),
        ], __('api.registration_started'));
    }

    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
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

        /** @var User $user */
        $user = $resolved['model'];

        $this->verificationCodes->verify($user, VerificationType::Email, $request->validated('code'));

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->flowTokens->revoke($request->validated('flow_token'));

        $passwordFlowToken = $this->flowTokens->issue($user, AuthFlowPurpose::PasswordSetup);

        return ApiResponse::success([
            'flow_token' => $passwordFlowToken,
            'next_step' => 'create_password',
        ], __('api.email_verified'));
    }

    public function resendVerification(ResendVerificationRequest $request): JsonResponse
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

        /** @var User $user */
        $user = $resolved['model'];

        if ($user->email_verified_at) {
            return ApiResponse::error(__('api.email_already_verified'), 409);
        }

        $this->verificationCodes->send($user, VerificationType::Email, $user->email);

        return ApiResponse::success([
            'email' => $this->verificationCodes->maskEmail($user->email),
            'resend_cooldown_seconds' => $this->resendCooldownSeconds(),
        ], __('api.verification_code_sent'));
    }

    public function createPassword(CreatePasswordRequest $request): JsonResponse
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

        /** @var User $user */
        $user = $resolved['model'];

        if (! $user->email_verified_at) {
            return ApiResponse::error(__('api.email_not_verified'), 403);
        }

        if ($user->password) {
            return ApiResponse::error(__('api.password_already_set'), 409);
        }

        $user->update([
            'password' => $request->validated('password'),
        ]);

        $this->flowTokens->revoke($request->validated('flow_token'));
        $user->tokens()->delete();

        $token = $user->createToken('user-api')->plainTextToken;

        return ApiResponse::success([
            'user' => new UserResource($user->fresh()),
            'token' => $token,
            'token_type' => 'Bearer',
        ], __('api.password_created'));
    }

    private function resendCooldownSeconds(): int
    {
        return (int) config('auth_flow.otp_resend_cooldown_seconds', 60);
    }
}
