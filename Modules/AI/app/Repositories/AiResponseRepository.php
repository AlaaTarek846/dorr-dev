<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiResponse;

class AiResponseRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['request'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiResponse $model)
    {
        $this->model = $model;
    }
}
