<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiFailoverService;

class AiFailoverController extends Controller
{
    public function __construct(protected AiFailoverService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $failover)
    {
        return $this->service->find($failover);
    }
}
