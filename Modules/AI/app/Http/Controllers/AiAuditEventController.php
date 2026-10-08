<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AI\Services\AiAuditEventService;

/**
 * Read-only by design, matching the model itself (AiAuditEvent::update()/
 * delete() throw): this controller exposes only index/show, never a
 * store/update/destroy - the append-only audit ledger (v2.0 requirements
 * doc S17.6) must stay tamper-proof from the admin panel too, not just
 * from the ORM.
 */
class AiAuditEventController extends Controller
{
    public function __construct(protected AiAuditEventService $service) {}

    public function index()
    {
        return $this->service->list();
    }

    public function show(int $event)
    {
        return $this->service->find($event);
    }
}
