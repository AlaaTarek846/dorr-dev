<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiDataPolicy;

class AiDataPolicyRepository extends BaseRepository
{
    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'name' => 'asc',
    ];

    public function __construct(AiDataPolicy $model)
    {
        $this->model = $model;
    }
}
