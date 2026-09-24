<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiReliabilityMetricService;

class AiReliabilityMetricController extends Controller
{
    public function __construct(protected AiReliabilityMetricService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $metric)
    {
        return $this->service->find($metric);
    }
}
