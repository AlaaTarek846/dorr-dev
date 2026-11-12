<?php

namespace Modules\AI\Repositories;

use App\Repositories\TranslatableRepository;
use Modules\AI\Models\AiLanguageEvaluation;

/**
 * AI language evaluations as a catalog (like CountryRepository): name synced from `translations[]`, status toggle,
 * bulk delete, trash / restore / force delete. Nothing points at an evaluation, so deleting it for good is always
 * allowed.
 */
class AiLanguageEvaluationRepository extends TranslatableRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['language.translations', 'variant', 'translations', 'translation'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
        'id' => 'desc',
    ];

    public function __construct(AiLanguageEvaluation $model)
    {
        $this->model = $model;
    }
}
