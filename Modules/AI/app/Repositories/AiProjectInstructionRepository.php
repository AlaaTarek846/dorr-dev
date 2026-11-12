<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiProjectInstruction;

/**
 * AI project instructions as a catalog list (like countries, without translations - the instruction is a prompt sent
 * to the AI as it is): status toggle, bulk delete, trash / restore / force delete. Nothing points at an instruction,
 * so deleting it for good is always allowed.
 */
class AiProjectInstructionRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['project'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'priority' => 'desc',
        'id' => 'asc',
    ];

    public function __construct(AiProjectInstruction $model)
    {
        $this->model = $model;
    }
}
