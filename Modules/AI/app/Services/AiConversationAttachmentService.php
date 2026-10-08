<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiConversationAttachmentResource;
use Modules\AI\Repositories\AiConversationAttachmentRepository;

class AiConversationAttachmentService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiConversationAttachmentResource::class;

    public function __construct(AiConversationAttachmentRepository $repository)
    {
        parent::__construct($repository);
    }
}
