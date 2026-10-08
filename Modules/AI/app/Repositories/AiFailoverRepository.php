<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiFailover;

class AiFailoverRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['primaryProvider', 'fallbackProvider', 'request'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiFailover $model)
    {
        $this->model = $model;
    }
}
