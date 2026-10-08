<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiRequestService;

class AiRequestController extends Controller
{
    public function __construct(protected AiRequestService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $aiRequest)
    {
        return $this->service->find($aiRequest);
    }
}
