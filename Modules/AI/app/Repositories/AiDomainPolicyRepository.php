<?php

namespace Modules\AI\Repositories;

use App\Repositories\TranslatableRepository;
use Modules\AI\Models\AiDomainPolicy;

/**
 * AI domain policies as a catalog (like CountryRepository): name / description synced from `translations[]`, status
 * toggle, bulk delete, trash / restore / force delete. Nothing points at a policy (benchmark cases keep the domain
 * key as text), so moving it to the trash (the classifier stops using it) and deleting it for good are always allowed.
 */
class AiDomainPolicyRepository extends TranslatableRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['translations', 'translation'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'risk_level' => 'desc',
        'id' => 'asc',
    ];

    public function __construct(AiDomainPolicy $model)
    {
        $this->model = $model;
    }
}
