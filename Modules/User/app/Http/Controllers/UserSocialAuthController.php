<?php

namespace Modules\User\Http\Controllers;

use App\Enums\AuthFlowPurpose;
use App\Enums\SocialProvider;
use App\Enums\VerificationType;
use App\Http\Controllers\Controller;
use App\Services\Auth\AuthFlowTokenService;
use App\Services\Auth\SocialAuthService;
use App\Services\Auth\VerificationCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Modules\Provider\Http\Controllers\ProviderSocialAuthController;
use Modules\User\Models\User;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class UserSocialAuthController extends Controller
{
    public function __construct(
        private readonly SocialAuthService $socialAuth,
        private readonly AuthFlowTokenService $flowTokens,
        private readonly VerificationCodeService $verificationCodes,
    ) {}

    public function redirect(string $provider): RedirectResponse|Response
    {
        $driver = $this->resolveProvider($provider);

        session(['social_auth_panel' => 'user']);

        return Socialite::driver($driver)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        if (session()->pull('social_auth_panel') === 'provider') {
            return app(ProviderSocialAuthController::class)->callback($provider);
        }

        $driver = $this->resolveProvider($provider);

        try {
            $socialUser = Socialite::driver($driver)->user();
            $result = $this->socialAuth->authenticate(
                SocialProvider::from($provider),
                $socialUser,
                User::class,
            );

            /** @var User $user */
            $user = $result['user'];

            if (! $result['email_verified']) {
                $this->verificationCodes->send($user, VerificationType::Email, $user->email);
                $flowToken = $this->flowTokens->issue($user, AuthFlowPurpose::EmailVerification);

                return $this->redirectToFrontend([
                    'status' => 'needs_verification',
                    'flow_token' => $flowToken,
                    'email' => $this->verificationCodes->maskEmail($user->email),
                ]);
            }

            if ($result['needs_password']) {
                $flowToken = $this->flowTokens->issue($user, AuthFlowPurpose::PasswordSetup);

                return $this->redirectToFrontend([
                    'status' => 'needs_password',
                    'flow_token' => $flowToken,
                    'email' => $user->email,
                ]);
            }

            $user->tokens()->delete();
            $token = $user->createToken('user-api')->plainTextToken;

            return $this->redirectToFrontend([
                'status' => 'success',
                'token' => $token,
            ]);
        } catch (RuntimeException $exception) {
            return $this->redirectToFrontend([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ]);
        } catch (\Throwable $exception) {
            Log::error('User social auth failed', [
                'provider' => $provider,
                'message' => $exception->getMessage(),
            ]);

            return $this->redirectToFrontend([
                'status' => 'error',
                'message' => __('api.social_auth_failed'),
            ]);
        }
    }

    private function resolveProvider(string $provider): string
    {
        if (! in_array($provider, [SocialProvider::Google->value, SocialProvider::Apple->value], true)) {
            abort(404);
        }

        return $provider;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function redirectToFrontend(array $params): RedirectResponse
    {
        $query = http_build_query($params);

        return redirect('/user/oauth/callback?'.$query);
    }
}
