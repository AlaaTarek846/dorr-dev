<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiCodeExecutionResource;
use Modules\AI\Repositories\AiCodeExecutionRepository;

/**
 * Read-only admin listing for ai_code_executions - the real, audited
 * result of every sandbox run the "code" domain pipeline performed
 * (v2.0 requirements doc, §10.3/§17.5).
 */
class AiCodeExecutionRecordService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiCodeExecutionResource::class;

    public function __construct(AiCodeExecutionRepository $repository)
    {
        parent::__construct($repository);
    }
}
