<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiLanguageEvaluation;

class AiLanguageEvaluationRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['language.translations', 'variant'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
    ];

    public function __construct(AiLanguageEvaluation $model)
    {
        $this->model = $model;
    }
}
