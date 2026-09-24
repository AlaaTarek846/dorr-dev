<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiProjectContext;

class AiProjectContextRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['project'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiProjectContext $model)
    {
        $this->model = $model;
    }
}
