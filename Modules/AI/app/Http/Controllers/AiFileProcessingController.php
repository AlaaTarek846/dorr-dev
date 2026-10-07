<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiFileProcessingService;

class AiFileProcessingController extends Controller
{
    public function __construct(protected AiFileProcessingService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $processing)
    {
        return $this->service->find($processing);
    }
}
