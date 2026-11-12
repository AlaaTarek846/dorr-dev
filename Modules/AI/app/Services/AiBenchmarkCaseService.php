<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiBenchmarkCaseResource;
use Modules\AI\Repositories\AiBenchmarkCaseRepository;

/** AI benchmark cases catalog (no translations): list (+status counts), create/update, status, bulk delete, trash. */
class AiBenchmarkCaseService extends CatalogService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiBenchmarkCaseResource::class;

    public function __construct(AiBenchmarkCaseRepository $repository)
    {
        parent::__construct($repository);
    }
}
