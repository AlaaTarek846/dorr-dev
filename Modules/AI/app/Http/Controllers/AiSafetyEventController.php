<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiSafetyEventService;

class AiSafetyEventController extends Controller
{
    public function __construct(protected AiSafetyEventService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $safetyEvent)
    {
        return $this->service->find($safetyEvent);
    }
}
