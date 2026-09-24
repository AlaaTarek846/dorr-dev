<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiSecurityEventService;

class AiSecurityEventController extends Controller
{
    public function __construct(protected AiSecurityEventService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $securityEvent)
    {
        return $this->service->find($securityEvent);
    }
}
