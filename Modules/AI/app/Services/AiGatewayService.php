<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiGatewayResource;
use Modules\AI\Repositories\AiGatewayRepository;

class AiGatewayService extends BaseService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiGatewayResource::class;

    public function __construct(AiGatewayRepository $repository)
    {
        parent::__construct($repository);
    }
}
