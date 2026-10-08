<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiCodeExecution;

class AiCodeExecutionRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['request', 'conversation'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiCodeExecution $model)
    {
        $this->model = $model;
    }
}
