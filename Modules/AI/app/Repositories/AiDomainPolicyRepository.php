<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiDomainPolicy;

class AiDomainPolicyRepository extends BaseRepository
{
    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'risk_level' => 'desc',
    ];

    public function __construct(AiDomainPolicy $model)
    {
        $this->model = $model;
    }
}
