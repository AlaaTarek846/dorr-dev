<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiSecurityPolicy;

class AiSecurityPolicyRepository extends BaseRepository
{
    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'name' => 'asc',
    ];

    public function __construct(AiSecurityPolicy $model)
    {
        $this->model = $model;
    }
}
