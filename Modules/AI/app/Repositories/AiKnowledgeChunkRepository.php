<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiKnowledgeChunk;

class AiKnowledgeChunkRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['knowledgeSource'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiKnowledgeChunk $model)
    {
        $this->model = $model;
    }
}
