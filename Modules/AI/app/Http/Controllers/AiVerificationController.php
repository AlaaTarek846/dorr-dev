<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiVerificationRecordService;

class AiVerificationController extends Controller
{
    public function __construct(protected AiVerificationRecordService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $verification)
    {
        return $this->service->find($verification);
    }
}
