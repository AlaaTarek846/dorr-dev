<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiAuditEventResource;
use Modules\AI\Repositories\AiAuditEventRepository;

class AiAuditEventService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiAuditEventResource::class;

    public function __construct(AiAuditEventRepository $repository)
    {
        parent::__construct($repository);
    }
}
