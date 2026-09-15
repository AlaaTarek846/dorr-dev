<?php

namespace Modules\User\Http\Controllers;

use App\Enums\AuthFlowPurpose;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Services\Auth\AuthFlowTokenService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Modules\User\Http\Requests\ForgotPasswordRequest;
use Modules\User\Http\Requests\ResetPasswordRequest;
use Modules\User\Http\Resources\UserResource;
use Modules\User\Models\User;
use RuntimeException;

class UserPasswordResetController extends Controller
{
    public function __construct(
        private readonly AuthFlowTokenService $flowTokens,
    ) {}

    public function sendResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if ($user && $user->status !== UserStatus::Blocked) {
            $flowToken = $this->flowTokens->issue($user, AuthFlowPurpose::PasswordReset);

            Mail::to($user->email)->send(new PasswordResetMail(
                $this->buildResetUrl($flowToken),
                $user,
            ));
        }

        return ApiResponse::success([], __('api.password_reset_link_sent'));
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $resolved = $this->flowTokens->resolve(
                $request->validated('flow_token'),
                AuthFlowPurpose::PasswordReset,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'flow_token' => [$exception->getMessage()],
            ]);
        }

        /** @var User $user */
        $user = $resolved['model'];

        if ($user->status === UserStatus::Blocked) {
            return ApiResponse::error(__('api.account_blocked'), 403);
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
        ], __('api.password_reset_success'));
    }

    private function buildResetUrl(string $flowToken): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        return $baseUrl.'/user/reset-password?flow_token='.urlencode($flowToken);
    }
}
