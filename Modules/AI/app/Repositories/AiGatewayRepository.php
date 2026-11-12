<?php

namespace Modules\AI\Repositories;

use App\Repositories\TranslatableRepository;
use Modules\AI\Models\AiGateway;

/**
 * AI gateways as a catalog (like CountryRepository): translations synced from `translations[]`, status toggle,
 * bulk delete, trash / restore / force delete.
 */
class AiGatewayRepository extends TranslatableRepository
{
    /**
     * @var array<int, string>
     */
    protected array $with = ['defaultPolicy', 'translations', 'translation'];

    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'id' => 'asc',
    ];

    /**
     * Moving a gateway to the trash is allowed: the engine stops using it, like switching it off. Deleting it for
     * good is blocked once chat requests went through it (ai_requests keeps that history).
     *
     * @var list<string>
     */
    protected array $deleteBlockRelations = [];

    /** @var list<string> */
    protected array $forceDeleteBlockRelations = ['requests'];

    public function __construct(AiGateway $model)
    {
        $this->model = $model;
    }

    /**
     * @param  list<string>  $relations
     */
    public function forceDestroy(int|string $id, array $relations = []): bool
    {
        return parent::forceDestroy($id, $relations === [] ? $this->forceDeleteBlockRelations : $relations);
    }
}
