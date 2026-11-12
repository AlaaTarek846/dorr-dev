<?php

namespace Modules\AI\Repositories;

use App\Repositories\TranslatableRepository;
use Illuminate\Support\Collection;
use Modules\AI\Models\AiIntent;

/**
 * AI intents as a catalog (like CountryRepository): translations synced from `translations[]`, status toggle,
 * bulk delete, trash / restore / force delete.
 */
class AiIntentRepository extends TranslatableRepository
{
    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'key' => 'asc',
        'id' => 'asc',
    ];

    protected array $with = ['translations', 'translation'];

    /**
     * Moving an intent to the trash is allowed: it is no longer classified, so the rules aimed at it stop applying
     * (like switching it off). Deleting it for good is blocked while a routing rule (in the trash too) points at it -
     * the database would turn those rules into "any intent" rules - or chat requests were classified with it.
     *
     * @var list<string>
     */
    protected array $deleteBlockRelations = [];

    /** @var list<string> */
    protected array $forceDeleteBlockRelations = ['routingRules', 'requests'];

    public function __construct(AiIntent $model)
    {
        $this->model = $model;
    }

    /**
     * Intent picker of the routing rules form: every intent not in the trash - switched-off ones too, so a rule
     * aimed at a paused intent still shows it.
     */
    public function dropdown(): Collection
    {
        return $this->query()
            ->orderBy('key')
            ->orderBy('id')
            ->get()
            ->map(fn (AiIntent $intent) => [
                'id' => $intent->id,
                'key' => $intent->key,
                'name' => $intent->name,
                'status' => (bool) $intent->status,
            ])
            ->values();
    }

    /**
     * @param  list<string>  $relations
     */
    public function forceDestroy(int|string $id, array $relations = []): bool
    {
        return parent::forceDestroy($id, $relations === [] ? $this->forceDeleteBlockRelations : $relations);
    }
}
