<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiConversationContextResource;
use Modules\AI\Repositories\AiConversationContextRepository;

class AiConversationContextService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiConversationContextResource::class;

    public function __construct(AiConversationContextRepository $repository)
    {
        parent::__construct($repository);
    }
}
