<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiProviderHealthService;

class AiProviderHealthController extends Controller
{
    public function __construct(protected AiProviderHealthService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $health)
    {
        return $this->service->find($health);
    }
}
