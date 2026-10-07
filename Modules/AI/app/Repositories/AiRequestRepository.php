<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiRequest;

class AiRequestRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['owner', 'gateway', 'intent', 'provider', 'response', 'usage', 'citations.knowledgeSource'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiRequest $model)
    {
        $this->model = $model;
    }
}
