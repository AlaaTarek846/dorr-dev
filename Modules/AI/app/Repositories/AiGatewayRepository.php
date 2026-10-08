<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiGateway;

class AiGatewayRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['defaultPolicy'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'name' => 'asc',
    ];

    public function __construct(AiGateway $model)
    {
        $this->model = $model;
    }
}
