<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiSafetyScanService;

class AiSafetyScanController extends Controller
{
    public function __construct(protected AiSafetyScanService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $safetyScan)
    {
        return $this->service->find($safetyScan);
    }
}
