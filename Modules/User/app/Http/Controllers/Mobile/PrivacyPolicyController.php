<?php

namespace Modules\User\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\User\Services\MobilePrivacyPolicyService;

class PrivacyPolicyController extends Controller
{
    public function __construct(
        protected MobilePrivacyPolicyService $service,
    ) {}

    /**
     * The general (service-less) privacy policy for the mobile app.
     */
    public function show(): JsonResponse
    {
        return $this->service->generalPolicy();
    }
}
