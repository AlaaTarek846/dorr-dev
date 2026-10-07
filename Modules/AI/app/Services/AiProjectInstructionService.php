<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiProjectInstructionResource;
use Modules\AI\Repositories\AiProjectInstructionRepository;

class AiProjectInstructionService extends BaseService
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
