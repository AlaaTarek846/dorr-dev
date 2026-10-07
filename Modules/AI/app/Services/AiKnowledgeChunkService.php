<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiKnowledgeChunkResource;
use Modules\AI\Repositories\AiKnowledgeChunkRepository;

class AiKnowledgeChunkService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiKnowledgeChunkResource::class;

    public function __construct(AiKnowledgeChunkRepository $repository)
    {
        parent::__construct($repository);
    }
}
