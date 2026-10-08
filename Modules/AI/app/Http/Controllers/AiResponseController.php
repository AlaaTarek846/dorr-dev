<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiResponseService;

class AiResponseController extends Controller
{
    public function __construct(protected AiResponseService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $response)
    {
        return $this->service->find($response);
    }
}
