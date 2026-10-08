<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiDocumentGenerationService;

class AiDocumentGenerationController extends Controller
{
    public function __construct(protected AiDocumentGenerationService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $generation)
    {
        return $this->service->find($generation);
    }
}
