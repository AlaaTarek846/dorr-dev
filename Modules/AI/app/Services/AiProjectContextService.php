<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiProjectContextResource;
use Modules\AI\Repositories\AiProjectContextRepository;

class AiProjectContextService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiProjectContextResource::class;

    public function __construct(AiProjectContextRepository $repository)
    {
        parent::__construct($repository);
    }
}
