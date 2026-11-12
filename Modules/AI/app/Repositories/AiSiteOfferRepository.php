<?php

namespace Modules\AI\Repositories;

use App\Repositories\TranslatableRepository;
use Illuminate\Database\Eloquent\Builder;
use Modules\AI\Models\AiSiteOffer;

/**
 * Website-builder offers and edit packs as a catalog (like CountryRepository): translations synced from
 * `translations[]`, status toggle, bulk delete, trash / restore / force delete.
 *
 * The source-download switch is a row of the same table but has its own screen (AiSiteDownloadSettingController),
 * so every query here leaves it out - it can't be listed, counted, edited or deleted from this screen.
 * `?kind=site|edit_pack` narrows the list (and so the filter counts) to the screen that asks.
 */
class AiSiteOfferRepository extends TranslatableRepository
{
    /**
     * @var array<string, string>
     */
    protected array $orderBy = [
        'sort_order' => 'asc',
        'id' => 'asc',
    ];

    protected array $with = ['translations', 'translation'];

    protected array $withCount = ['prices'];

    /**
     * Moving an offer to the trash is allowed even after it was sold: it stops new sales (AiSiteOffer::isAvailable(),
     * the customer lists only show active offers) while what was bought keeps working. Deleting it for good is
     * blocked while any purchase still points at it.
     *
     * @var list<string>
     */
    protected array $deleteBlockRelations = [];

    /** @var list<string> */
    protected array $forceDeleteBlockRelations = ['purchases'];

    public function __construct(AiSiteOffer $model)
    {
        $this->model = $model;
    }

    public function query(): Builder
    {
        return parent::query()->where('kind', '!=', AiSiteOffer::KIND_SOURCE_DOWNLOAD);
    }

    protected function buildIndexQuery(): Builder
    {
        $kind = request()->query('kind');

        return parent::buildIndexQuery()
            ->where('kind', '!=', AiSiteOffer::KIND_SOURCE_DOWNLOAD)
            ->when(in_array($kind, [AiSiteOffer::KIND_SITE, AiSiteOffer::KIND_EDIT_PACK], true), fn (Builder $q) => $q->where('kind', $kind));
    }

    /**
     * @param  list<string>  $relations
     */
    public function forceDestroy(int|string $id, array $relations = []): bool
    {
        return parent::forceDestroy($id, $relations === [] ? $this->forceDeleteBlockRelations : $relations);
    }
}
