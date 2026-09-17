<?php

namespace Modules\Provider\Http\Controllers;

use App\Enums\AuthFlowPurpose;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Services\Auth\AuthFlowTokenService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Modules\Provider\Http\Requests\ProviderForgotPasswordRequest;
use Modules\Provider\Http\Requests\ProviderResetPasswordRequest;
use Modules\Provider\Http\Resources\ProviderResource;
use Modules\Provider\Models\Provider;
use RuntimeException;

class ProviderPasswordResetController extends Controller
{
    public function __construct(
        private readonly AuthFlowTokenService $flowTokens,
    ) {}

    public function sendResetLink(ProviderForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');

        /** @var Provider|null $provider */
        $provider = Provider::query()->where('email', $email)->first();

        if ($provider && $provider->status !== UserStatus::Blocked) {
            $flowToken = $this->flowTokens->issue($provider, AuthFlowPurpose::PasswordReset);

            Mail::to($provider->email)->send(new PasswordResetMail(
                $this->buildResetUrl($flowToken),
                $provider,
            ));
        }

        return ApiResponse::success([], __('api.password_reset_link_sent'));
    }

    public function resetPassword(ProviderResetPasswordRequest $request): JsonResponse
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

        /** @var Provider $provider */
        $provider = $resolved['model'];

        if ($provider->status === UserStatus::Blocked) {
            return ApiResponse::error(__('api.account_blocked'), 403);
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
        ], __('api.password_reset_success'));
    }

    private function buildResetUrl(string $flowToken): string
    {
        $baseUrl = rtrim((string) config('app.url'), '/');

        return $baseUrl.'/provider/reset-password?flow_token='.urlencode($flowToken);
    }
}
