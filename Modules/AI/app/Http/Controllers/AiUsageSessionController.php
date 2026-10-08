<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiUsageSessionService;

/**
 * Read-only for the admin: usage sessions are system-generated actual
 * usage records (written by the runtime/gateway layer once it exists),
 * not something an admin creates by hand.
 */
class AiUsageSessionController extends Controller
{
    public function __construct(protected AiUsageSessionService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $usageSession)
    {
        return $this->service->find($usageSession);
    }
}
