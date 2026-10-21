<?php

namespace Modules\Rides\Http\Controllers\Driver;

use App\Enums\VerificationType;
use App\Http\Controllers\Controller;
use App\Services\Auth\VerificationCodeService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Driver\Models\Driver;
use Modules\Rides\Services\Drivers\DriverPayouts;

/**
 * D-299: the phone is lost — freeze the captain's money from anywhere (another phone, the website), with a code
 * sent to their number: no sign-in needed. Every session ends and nothing leaves the wallet until the team lifts it.
 * The answer never says whether the number has an account.
 */
class EmergencyFreezeController extends Controller
{
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate(['dial_code' => ['required', 'string', 'max:6'], 'phone' => ['required', 'string', 'max:20']]);
        $driver = $this->driver($data['dial_code'], $data['phone']);
        if ($driver !== null) {
            rescue(fn () => $driver->sendPhoneOtp(true), report: false);
        }

        return ApiResponse::success(['sent' => true], __('rides.payouts.freeze_code_sent'));
    }

    public function confirm(Request $request, VerificationCodeService $codes, DriverPayouts $payouts): JsonResponse
    {
        $data = $request->validate(['dial_code' => ['required', 'string', 'max:6'], 'phone' => ['required', 'string', 'max:20'], 'code' => ['required', 'string', 'max:10']]);
        $driver = $this->driver($data['dial_code'], $data['phone']);
        abort_if($driver === null, 422, __('api.invalid_code'));
        $codes->verify($driver, VerificationType::Phone, $data['code']);
        $payouts->freeze($driver, 'lost_device');

        return ApiResponse::success(['frozen' => true], __('rides.payouts.frozen'));
    }

    private function driver(string $dial, string $phone): ?Driver
    {
        return Driver::query()->where('phone', '+'.ltrim($dial, '+').ltrim($phone, '0'))->first()
            ?? Driver::query()->where('phone', '+'.ltrim($dial, '+').$phone)->first();
    }
}
