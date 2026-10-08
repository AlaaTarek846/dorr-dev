<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiResponseResource;
use Modules\AI\Repositories\AiResponseRepository;

class AiResponseService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiResponseResource::class;

    public function __construct(AiResponseRepository $repository)
    {
        parent::__construct($repository);
    }
}
