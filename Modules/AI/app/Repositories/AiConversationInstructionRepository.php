<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiConversationInstruction;

/**
 * AI conversation instructions as a catalog list (like countries, without translations - the instruction is a prompt
 * sent to the AI as it is): status toggle, bulk delete, trash / restore / force delete. Nothing points at an
 * instruction, so deleting it for good is always allowed.
 */
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
        'id' => 'asc',
    ];

    public function __construct(AiConversationInstruction $model)
    {
        $this->model = $model;
    }
}
