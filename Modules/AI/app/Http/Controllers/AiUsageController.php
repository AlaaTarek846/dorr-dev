<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiUsageService;

class AiUsageController extends Controller
{
    public function __construct(protected AiUsageService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $usage)
    {
        return $this->service->find($usage);
    }
}
