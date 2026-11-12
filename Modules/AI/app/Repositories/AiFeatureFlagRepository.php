<?php

namespace Modules\AI\Repositories;

use App\Repositories\TranslatableRepository;
use Modules\AI\Models\AiFeatureFlag;

/**
 * AI feature flags as a catalog (like CountryRepository): the description synced from `translations[]`, status
 * toggle, bulk delete, trash / restore / force delete. Nothing points at a flag, so moving it to the trash (it stops
 * applying - its target is allowed again) and deleting it for good are always allowed.
 */
class AiFeatureFlagRepository extends TranslatableRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['provider', 'translations', 'translation'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'created_at' => 'desc',
        'id' => 'desc',
    ];

    public function __construct(AiFeatureFlag $model)
    {
        $this->model = $model;
    }
}
