<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Http\Requests\AiTrialControlUpdateRequest;
use Modules\AI\Services\AiTrialControlService;

/**
 * Trial records are created automatically by the system the first time an
 * owner becomes eligible for a trial (not built yet - see Phase 7/8
 * runtime). The admin can only review and flag/unflag abuse here.
 */
class AiTrialControlController extends Controller
{
    public function __construct(protected AiTrialControlService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $trialControl)
    {
        return $this->service->find($trialControl);
    }

    public function update(AiTrialControlUpdateRequest $request, int $trialControl)
    {
        return $this->service->updateRecord($trialControl, $request->validated());
    }
}
