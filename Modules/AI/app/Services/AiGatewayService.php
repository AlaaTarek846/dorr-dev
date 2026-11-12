<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiGatewayResource;
use Modules\AI\Repositories\AiGatewayRepository;

/** AI gateways catalog: list (+status counts), create/update with translations, status, bulk delete, trash. */
class AiGatewayService extends CatalogService
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
