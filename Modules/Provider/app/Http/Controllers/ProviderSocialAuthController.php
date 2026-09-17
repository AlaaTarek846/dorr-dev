<?php

namespace Modules\Provider\Http\Controllers;

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
use Modules\Provider\Models\Provider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ProviderSocialAuthController extends Controller
{
    public function __construct(
        private readonly SocialAuthService $socialAuth,
        private readonly AuthFlowTokenService $flowTokens,
        private readonly VerificationCodeService $verificationCodes,
    ) {}

    public function redirect(string $provider): RedirectResponse|Response
    {
        $driver = $this->resolveProvider($provider);

        session(['social_auth_panel' => 'provider']);

        return Socialite::driver($driver)->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        $driver = $this->resolveProvider($provider);

        try {
            $socialUser = Socialite::driver($driver)->user();

            $result = $this->socialAuth->authenticate(
                SocialProvider::from($provider),
                $socialUser,
                Provider::class,
            );

            /** @var Provider $providerModel */
            $providerModel = $result['user'];

            if (! $result['email_verified']) {
                $this->verificationCodes->send($providerModel, VerificationType::Email, $providerModel->email);
                $flowToken = $this->flowTokens->issue($providerModel, AuthFlowPurpose::EmailVerification);

                return $this->redirectToFrontend([
                    'status' => 'needs_verification',
                    'flow_token' => $flowToken,
                    'email' => $this->verificationCodes->maskEmail($providerModel->email),
                ]);
            }

            if ($result['needs_password']) {
                $flowToken = $this->flowTokens->issue($providerModel, AuthFlowPurpose::PasswordSetup);

                return $this->redirectToFrontend([
                    'status' => 'needs_password',
                    'flow_token' => $flowToken,
                    'email' => $providerModel->email,
                ]);
            }

            $providerModel->tokens()->delete();
            $token = $providerModel->createToken('provider-api')->plainTextToken;

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
            Log::error('Provider social auth failed', [
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

        return redirect('/provider/oauth/callback?'.$query);
    }
}
