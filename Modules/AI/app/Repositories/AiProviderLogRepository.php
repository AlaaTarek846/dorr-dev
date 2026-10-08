<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiProviderLog;

class AiProviderLogRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['provider', 'request'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiProviderLog $model)
    {
        $this->model = $model;
    }
}
