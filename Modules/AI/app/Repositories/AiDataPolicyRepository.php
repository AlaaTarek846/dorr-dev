<?php

namespace Modules\AI\Repositories;

use App\Repositories\TranslatableRepository;
use Modules\AI\Models\AiDataPolicy;

/**
 * AI data policies as a catalog (like CountryRepository): name / description synced from `translations[]`, status toggle,
 * bulk delete, trash / restore / force delete. Nothing points at a policy, so deleting it for good is always allowed
 * (the retention command and the log minimization then fall back to their defaults).
 */
class AiDataPolicyRepository extends TranslatableRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['translations', 'translation'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'id' => 'asc',
    ];

    public function __construct(AiDataPolicy $model)
    {
        $this->model = $model;
    }
}
