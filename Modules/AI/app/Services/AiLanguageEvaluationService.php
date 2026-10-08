<?php

namespace Modules\AI\Services;

use App\Services\BaseService;
use Modules\AI\Http\Resources\AiLanguageEvaluationResource;
use Modules\AI\Repositories\AiLanguageEvaluationRepository;

class AiLanguageEvaluationService extends BaseService
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
