<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiConversationContext;

class AiConversationContextRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['conversation'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiConversationContext $model)
    {
        $this->model = $model;
    }
}
