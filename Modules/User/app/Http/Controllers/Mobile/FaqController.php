<?php

namespace Modules\User\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\User\Services\MobileFaqService;

class FaqController extends Controller
{
    public function __construct(
        protected MobileFaqService $service,
    ) {}

    /**
     * General (service-less) FAQs for the mobile app.
     */
    public function index(): JsonResponse
    {
        return $this->service->generalFaqs();
    }
}
