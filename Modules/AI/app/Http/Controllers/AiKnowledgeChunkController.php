<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiKnowledgeChunkService;

class AiKnowledgeChunkController extends Controller
{
    public function __construct(protected AiKnowledgeChunkService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $chunk)
    {
        return $this->service->find($chunk);
    }
}
