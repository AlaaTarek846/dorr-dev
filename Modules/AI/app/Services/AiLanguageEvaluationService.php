<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiLanguageEvaluationResource;
use Modules\AI\Repositories\AiLanguageEvaluationRepository;

/** AI language evaluations catalog: list (+status counts), create/update with translations, status, bulk delete, trash. */
class AiLanguageEvaluationService extends CatalogService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiLanguageEvaluationResource::class;

    public function __construct(AiLanguageEvaluationRepository $repository)
    {
        parent::__construct($repository);
    }
}
