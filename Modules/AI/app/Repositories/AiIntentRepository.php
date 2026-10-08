<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiIntent;

class AiIntentRepository extends BaseRepository
{
    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'name' => 'asc',
    ];

    public function __construct(AiIntent $model)
    {
        $this->model = $model;
    }
}
