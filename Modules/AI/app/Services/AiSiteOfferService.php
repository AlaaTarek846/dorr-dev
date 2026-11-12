<?php

namespace Modules\AI\Services;

use App\Services\CatalogService;
use Modules\AI\Http\Resources\AiSiteOfferResource;
use Modules\AI\Models\AiSiteOffer;
use Modules\AI\Repositories\AiSiteOfferRepository;

/** Website-builder offers / edit packs catalog: list (+status counts), create/update with translations, status, bulk delete, trash. */
class AiSiteOfferService extends CatalogService
{
    /**
     * @var class-string
     */
    protected ?string $resource = AiSiteOfferResource::class;

    public function __construct(AiSiteOfferRepository $repository)
    {
        parent::__construct($repository);
    }

    /**
     * Counts of the screen that asks (`?kind=site|edit_pack`), never the two screens added together.
     *
     * @return array{total: int, active: int, inactive: int}
     */
    protected function statusCounts(): array
    {
        $kind = request()->query('kind');
        $query = fn () => $this->repository->query()
            ->when(in_array($kind, [AiSiteOffer::KIND_SITE, AiSiteOffer::KIND_EDIT_PACK], true), fn ($q) => $q->where('kind', $kind));

        $total = $query()->count();
        $active = $query()->where('status', true)->count();

        return ['total' => $total, 'active' => $active, 'inactive' => $total - $active];
    }
}
