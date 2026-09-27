<?php

namespace Modules\Wallet\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Wallet\Services\DeviceTrustService;

/**
 * A device that unlocked the wallet with the right PIN but has never opened it before still has to
 * prove the phone on file is reachable from it — see DeviceTrustService.
 */
class DeviceTrustController extends Controller
{
    public function __construct(private readonly DeviceTrustService $devices) {}

    public function sendCode(Request $request)
    {
        $this->devices->sendCode($request->user());

        return ApiResponse::success(['sent' => true], __('api.updated'));
    }

    public function confirm(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:'.max(4, (int) config('auth_flow.otp_length', 4))]]);

        $this->devices->confirm($request->user(), $data['code'], $request->header('X-Device-Id'));

        return ApiResponse::success(['trusted' => true], __('api.updated'));
    }
}
