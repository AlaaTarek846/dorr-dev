<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiProjectInstruction;

class AiProjectInstructionRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['project'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'priority' => 'desc',
    ];

    public function __construct(AiProjectInstruction $model)
    {
        $this->model = $model;
    }
}
