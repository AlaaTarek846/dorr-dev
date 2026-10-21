<?php

namespace Modules\Rides\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Driver\Models\Driver;
use Modules\Rides\Services\Drivers\DriverInvoices;
use Modules\Rides\Services\Drivers\DriverPayouts;

/** The captain's payout safeguards (D-216..D-224) and monthly statements with their notes (D-227, D-228). */
class DriverPayoutController extends Controller
{
    public function status(Request $request, DriverPayouts $payouts): JsonResponse
    {
        return ApiResponse::success($payouts->status($this->driver($request)), __('api.retrieved'));
    }

    /** D-329..D-344: the out-of-the-ordinary states the app shows. */
    public function serviceStatus(Request $request, \Modules\Rides\Services\Drivers\DriverServiceStatus $states): JsonResponse
    {
        return ApiResponse::success($states->show($this->driver($request)), __('api.retrieved'));
    }

    public function international(Request $request, DriverPayouts $payouts): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $payouts->requestInternational($this->driver($request), $data['reason']);

        return ApiResponse::success($payouts->status($this->driver($request)), __('rides.payouts.international_sent'));
    }

    public function invoices(Request $request, DriverInvoices $invoices): JsonResponse
    {
        return ApiResponse::success($invoices->months($this->driver($request)), __('api.retrieved'));
    }

    public function invoice(Request $request, string $month, DriverInvoices $invoices): JsonResponse
    {
        return ApiResponse::success($invoices->month($this->driver($request), $month), __('api.retrieved'));
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
