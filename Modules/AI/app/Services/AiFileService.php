<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiFileResource;
use Modules\AI\Repositories\AiFileRepository;

class AiFileService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiFileResource::class;

    public function __construct(AiFileRepository $repository)
    {
        parent::__construct($repository);
    }
}
