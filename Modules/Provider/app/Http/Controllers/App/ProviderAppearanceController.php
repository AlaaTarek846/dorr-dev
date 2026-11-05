<?php

namespace Modules\Provider\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\User\Services\MobileAppearanceService;

/**
 * The providers app's colours and font (A-02): the same payload as `mobile/v1/appearance`, from the admin's
 * appearance settings, so the app looks exactly like the other Dorr apps (owner's call, docs/moving_business.md §12).
 */
class ProviderAppearanceController extends Controller
{
    public function __invoke(MobileAppearanceService $appearance): JsonResponse
    {
        return ApiResponse::success($appearance->payload(null), __('api.retrieved'));
    }
}
