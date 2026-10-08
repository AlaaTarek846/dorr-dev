<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiProviderLogService;

class AiProviderLogController extends Controller
{
    public function __construct(protected AiProviderLogService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $log)
    {
        return $this->service->find($log);
    }
}
