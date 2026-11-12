<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiIntentResource;
use Modules\AI\Repositories\AiIntentRepository;

/** AI intents catalog: list (+status counts), create/update with translations, status, bulk delete, trash, dropdown. */
class AiIntentService extends CatalogService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiIntentResource::class;

    public function __construct(AiIntentRepository $repository)
    {
        parent::__construct($repository);
    }
}
