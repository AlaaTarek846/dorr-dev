<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiConversationInstructionResource;
use Modules\AI\Repositories\AiConversationInstructionRepository;

/** AI conversation instructions catalog, no translations: list (+status counts), create/update, status, bulk delete, trash. */
class AiConversationInstructionService extends CatalogService
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
