<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiCodeExecutionRecordService;

class AiCodeExecutionController extends Controller
{
    public function __construct(protected AiCodeExecutionRecordService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $execution)
    {
        return $this->service->find($execution);
    }
}
