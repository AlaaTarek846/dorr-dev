<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiProviderDataRule;

/**
 * AI provider data rules as a catalog list (like countries, without translations - a rule has no text to translate):
 * status toggle, bulk delete, trash / restore / force delete. Nothing points at a rule, so deleting it for good is
 * always allowed.
 */
class AiProviderDataRuleRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['provider'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'id' => 'asc',
    ];

    public function __construct(AiProviderDataRule $model)
    {
        $this->model = $model;
    }
}
