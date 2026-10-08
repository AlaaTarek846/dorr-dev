<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiFailoverResource;
use Modules\AI\Repositories\AiFailoverRepository;

class AiFailoverService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiFailoverResource::class;

    public function __construct(AiFailoverRepository $repository)
    {
        parent::__construct($repository);
    }
}
