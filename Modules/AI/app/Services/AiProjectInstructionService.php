<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiProjectInstructionResource;
use Modules\AI\Repositories\AiProjectInstructionRepository;

/** AI project instructions catalog, no translations: list (+status counts), create/update, status, bulk delete, trash. */
class AiProjectInstructionService extends CatalogService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiProjectInstructionResource::class;

    public function __construct(AiProjectInstructionRepository $repository)
    {
        parent::__construct($repository);
    }
}
