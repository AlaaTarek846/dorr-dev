<?php

namespace Modules\AI\Repositories;

use App\Repositories\BaseRepository;
use Modules\AI\Models\AiRoutingRule;

/**
 * AI routing rules as a catalog list (like countries, without translations - a rule has no text to translate):
 * status toggle, bulk delete, trash / restore / force delete. Nothing points at a rule, so deleting it for good is
 * always allowed.
 */
class AiRoutingRuleRepository extends BaseRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['policy', 'intent', 'provider'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'priority' => 'desc',
        'id' => 'asc',
    ];

    public function __construct(AiRoutingRule $model)
    {
        $this->model = $model;
    }
}
