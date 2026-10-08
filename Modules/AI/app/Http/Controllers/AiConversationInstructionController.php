<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiConversationInstructionRequest;
use Modules\AI\Services\AiConversationInstructionService;

class AiConversationInstructionController extends Controller
{
    public function __construct(protected AiConversationInstructionService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiConversationInstructionRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $instruction)
    {
        return $this->service->find($instruction);
    }

    public function update(AiConversationInstructionRequest $request, int $instruction)
    {
        return $this->service->updateRecord($instruction, $request->validated());
    }

    public function destroy(int $instruction)
    {
        return $this->service->delete($instruction);
    }
}
