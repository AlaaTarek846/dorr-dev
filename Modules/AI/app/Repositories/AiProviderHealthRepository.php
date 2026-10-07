<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiProviderHealth;

class AiProviderHealthRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['provider'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiProviderHealth $model)
    {
        $this->model = $model;
    }
}
