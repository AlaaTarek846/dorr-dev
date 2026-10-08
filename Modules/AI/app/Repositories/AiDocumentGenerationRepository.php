<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiDocumentGeneration;

class AiDocumentGenerationRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['owner', 'conversation'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiDocumentGeneration $model)
    {
        $this->model = $model;
    }
}
