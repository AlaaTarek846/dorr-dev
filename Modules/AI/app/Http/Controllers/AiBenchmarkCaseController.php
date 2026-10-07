<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiBenchmarkCaseRequest;
use Modules\AI\Services\AiBenchmarkCaseService;

class AiBenchmarkCaseController extends Controller
{
    public function __construct(protected AiBenchmarkCaseService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function store(AiBenchmarkCaseRequest $request)
    {
        return $this->service->create($request->validated());
    }

    public function show(int $case)
    {
        return $this->service->find($case);
    }

    public function update(AiBenchmarkCaseRequest $request, int $case)
    {
        return $this->service->updateRecord($case, $request->validated());
    }

    public function destroy(int $case)
    {
        return $this->service->delete($case);
    }
}
