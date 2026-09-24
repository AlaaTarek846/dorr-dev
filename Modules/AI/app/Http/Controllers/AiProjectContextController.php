<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiProjectContextService;

class AiProjectContextController extends Controller
{
    public function __construct(protected AiProjectContextService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $context)
    {
        return $this->service->find($context);
    }
}
