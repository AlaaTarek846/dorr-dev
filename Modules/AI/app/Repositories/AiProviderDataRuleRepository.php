<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiProviderDataRule;

class AiProviderDataRuleRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['provider'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'id' => 'asc',
    ];

    public function __construct(AiProviderDataRule $model)
    {
        $this->model = $model;
    }
}
