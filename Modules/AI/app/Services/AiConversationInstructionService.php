<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiConversationInstructionResource;
use Modules\AI\Repositories\AiConversationInstructionRepository;

class AiConversationInstructionService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiConversationInstructionResource::class;

    public function __construct(AiConversationInstructionRepository $repository)
    {
        parent::__construct($repository);
    }
}
