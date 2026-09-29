<?php

namespace Modules\Sms\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Sms\Exceptions\SmsException;
use Modules\Sms\Http\Resources\OtpResource;
use Modules\Sms\Services\Otp\OtpChannelRouter;
use Modules\Sms\Services\Otp\OtpSettingsService;
use Modules\Sms\Services\Sms\SmsAdapterRegistry;

/**
 * OTP endpoints — settings and sending through the preferred channel.
 */
class OtpController extends Controller implements HasMiddleware
{
    public function __construct(protected OtpChannelRouter $router, protected OtpSettingsService $settings) {}

    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('otp-settings', [
            ['view', ['index']],
            ['update', ['update']],
            ['send', ['send']],
        ]);
    }

    public function index(): \Illuminate\Http\JsonResponse
    {
        return ApiResponse::success(new OtpResource($this->settings->get()), __('sms.otp.fetched'));
    }

    public function update(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['boolean'],
            'preferred_channel' => ['required', Rule::in(['whatsapp', 'sms'])],
            'fallback_channel' => ['required', Rule::in(['sms', 'whatsapp'])],
            'otp_length' => ['integer', 'min:4', 'max:10'],
            'expiration_minutes' => ['integer', 'min:1'],
            'resend_cooldown_seconds' => ['integer', 'min:10'],
            'max_attempts' => ['integer', 'min:1'],
        ]);

        $setting = $this->settings->update($validated);

        return ApiResponse::success(new OtpResource($setting), __('sms.otp.updated'));
    }

    public function send(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
        ]);

        try {
            $result = $this->router->route(
                \App\Models\Country::findOrFail($validated['country_id']),
                $validated['phone'],
                str_pad((string) random_int(0, pow(10, $this->settings->otpLength() - 1)), $this->settings->otpLength(), '0', STR_PAD_LEFT),
                $this->settings->expirationMinutes() * 60,
            );

            return ApiResponse::success($result, __('sms.otp.sent'));
        } catch (SmsException $e) {
            return ApiResponse::error($e->getMessage(), $e->getCode(), $e->getErrorCode());
        }
    }
}
