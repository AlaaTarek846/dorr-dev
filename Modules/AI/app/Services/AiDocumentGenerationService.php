<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiDocumentGenerationResource;
use Modules\AI\Repositories\AiDocumentGenerationRepository;

class AiDocumentGenerationService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiDocumentGenerationResource::class;

    public function __construct(AiDocumentGenerationRepository $repository)
    {
        parent::__construct($repository);
    }
}
