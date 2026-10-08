<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiProjectInstructionRequest;
use Modules\AI\Services\AiProjectInstructionService;

class AiProjectInstructionController extends Controller
{
    public function __construct(protected AiProjectInstructionService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiProjectInstructionRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $instruction)
    {
        return $this->service->find($instruction);
    }

    public function update(AiProjectInstructionRequest $request, int $instruction)
    {
        return $this->service->updateRecord($instruction, $request->validated());
    }

    public function destroy(int $instruction)
    {
        return $this->service->delete($instruction);
    }
}
