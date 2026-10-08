<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiConversationInstruction;

class AiConversationInstructionRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['conversation'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'priority' => 'desc',
    ];

    public function __construct(AiConversationInstruction $model)
    {
        $this->model = $model;
    }
}
