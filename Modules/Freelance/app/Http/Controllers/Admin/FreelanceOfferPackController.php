<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Currency;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Models\FreelanceOfferCredit;
use Modules\Freelance\Models\FreelanceOfferPack;
use Modules\Freelance\Models\FreelanceOfferPackPrice;

/**
 * The extra-offer packs desk (FL-L01, phase 2), on the plans permission: the packs and what each adds, their price in every country, and
 * who bought what. Editing a pack never changes a purchase already made (a purchase keeps its own quantity and price).
 */
class FreelanceOfferPackController extends Controller implements HasMiddleware
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-plans', [
            ['view', ['index', 'prices', 'purchases']],
            ['update', ['update', 'setPrice']],
        ]);
    }

    /** GET freelance-plans/packs — the packs with how many were sold in the last 30 days, and that month's money per currency. */
    public function index(): JsonResponse
    {
        $since = now()->subDays(30);
        $sold = FreelanceOfferCredit::query()->where('created_at', '>=', $since)->select('pack_id', DB::raw('count(*) as n'))->groupBy('pack_id')->pluck('n', 'pack_id');
        $revenue = FreelanceOfferCredit::query()->where('created_at', '>=', $since)->select('currency_code', DB::raw('sum(price_minor) as total'), DB::raw('count(*) as n'))
            ->groupBy('currency_code')->get()->map(fn ($r) => ['currency' => $r->currency_code, 'decimals' => $this->decimals($r->currency_code), 'total_minor' => (int) $r->total, 'purchases' => (int) $r->n])->values();

        return ApiResponse::success([
            'packs' => FreelanceOfferPack::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (FreelanceOfferPack $p) => $this->pack($p) + ['sold_30d' => (int) ($sold[$p->id] ?? 0)])->values(),
            'revenue_30d' => $revenue,
            'valid_days' => (int) config('freelance.offer_credit_valid_days', 0),
        ], __('api.retrieved'));
    }

    /** PUT freelance-plans/packs/{pack} — name, how many offers it adds, on / off. */
    public function update(Request $request, int $pack): JsonResponse
    {
        $model = FreelanceOfferPack::query()->findOrFail($pack);
        $data = $request->validate([
            'name' => ['sometimes', 'array'], 'name.ar' => ['required_with:name', 'string', 'max:60'], 'name.en' => ['required_with:name', 'string', 'max:60'],
            'offers' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'], 'status' => ['sometimes', 'boolean'],
        ]);
        $before = $model->only(array_keys($data));
        $model->update($data);
        $this->audit->record('freelance_offer_pack.updated', $model, $before, $model->only(array_keys($data)));

        return ApiResponse::success($this->pack($model->refresh()), __('api.updated'));
    }

    /** GET freelance-plans/packs/{pack}/prices[?search=&missing=1&per_page=] — every country with this pack's price (empty where not set). */
    public function prices(Request $request, int $pack): JsonResponse
    {
        $model = FreelanceOfferPack::query()->findOrFail($pack);
        $f = $request->validate(['search' => ['nullable', 'string', 'max:60'], 'missing' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $set = FreelanceOfferPackPrice::query()->where('pack_id', $model->id)->get()->keyBy('country_id');

        $page = Country::query()->with(['currency', 'translations'])->orderBy('code')
            ->when(! empty($f['search']), fn ($q) => $q->where(fn ($w) => $w->where('code', 'like', '%'.$f['search'].'%')->orWhereHas('translations', fn ($t) => $t->where('name', 'like', '%'.$f['search'].'%'))))
            ->when(! empty($f['missing']), fn ($q) => $q->whereNotIn('id', FreelanceOfferPackPrice::query()->where('pack_id', $model->id)->select('country_id')))
            ->paginate((int) ($f['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->map(fn (Country $c) => [
            'country_id' => $c->id, 'code' => $c->code, 'name' => $c->translations->firstWhere('locale', app()->getLocale())?->name ?? $c->translations->first()?->name ?? $c->code,
            'currency' => $c->currency?->code, 'decimals' => (int) ($c->currency?->decimal_places ?? 2),
            'price' => isset($set[$c->id]) ? ['price_minor' => $set[$c->id]->price_minor, 'status' => $set[$c->id]->status] : null,
        ])->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** PUT freelance-plans/packs/{pack}/prices/{country} {price_minor, status?} */
    public function setPrice(Request $request, int $pack, int $country): JsonResponse
    {
        $model = FreelanceOfferPack::query()->findOrFail($pack);
        $c = Country::query()->findOrFail($country);
        $data = $request->validate(['price_minor' => ['required', 'integer', 'min:1', 'max:100000000000'], 'status' => ['sometimes', 'boolean']]);

        $before = FreelanceOfferPackPrice::query()->where('pack_id', $model->id)->where('country_id', $c->id)->value('price_minor');
        FreelanceOfferPackPrice::query()->updateOrCreate(['pack_id' => $model->id, 'country_id' => $c->id], ['price_minor' => $data['price_minor'], 'status' => $data['status'] ?? true]);
        $this->audit->record('freelance_offer_pack.price_set', $model, ['price_minor' => $before], ['country_id' => $c->id, 'price_minor' => $data['price_minor']]);

        return ApiResponse::success(['country_id' => $c->id, 'price_minor' => $data['price_minor']], __('api.updated'));
    }

    /** GET freelance-plans/pack-purchases[?search=&pack=&per_page=] — who bought which pack, and how many of its offers are left. */
    public function purchases(Request $request): JsonResponse
    {
        $f = $request->validate(['search' => ['nullable', 'string', 'max:60'], 'pack' => ['nullable', 'string', 'max:40'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = FreelanceOfferCredit::query()->with(['pack', 'freelancer:id,name,phone'])
            ->withCount(['offers as spent' => fn ($q) => $q->where(fn ($w) => $w->where('status', '!=', 'withdrawn')->orWhereNotNull('viewed_at'))])
            ->when(! empty($f['pack']), fn ($q) => $q->whereHas('pack', fn ($p) => $p->where('slug', $f['pack'])))
            ->when(! empty($f['search']), fn ($q) => $q->whereHas('freelancer', fn ($p) => $p->where('name', 'like', '%'.$f['search'].'%')->orWhere('phone', 'like', '%'.$f['search'].'%')))
            ->latest('id')->paginate((int) ($f['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->map(fn (FreelanceOfferCredit $c) => [
            'uuid' => $c->uuid, 'pack' => $c->pack?->text(), 'quantity' => $c->quantity, 'remaining' => max(0, $c->quantity - (int) $c->spent),
            'price_minor' => $c->price_minor, 'currency' => $c->currency_code, 'decimals' => $this->decimals($c->currency_code),
            'freelancer' => ['id' => $c->freelancer?->id, 'name' => $c->freelancer?->name, 'phone' => $c->freelancer?->phone],
            'expires_at' => $c->expires_at?->toIso8601String(), 'created_at' => $c->created_at?->toIso8601String(),
        ])->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** @return array<string, mixed> */
    private function pack(FreelanceOfferPack $p): array
    {
        return ['id' => $p->id, 'slug' => $p->slug, 'name' => $p->name, 'offers' => $p->offers, 'sort_order' => $p->sort_order, 'status' => $p->status];
    }

    private function decimals(?string $code): int
    {
        static $places = null;
        $places ??= Currency::query()->pluck('decimal_places', 'code')->all();

        return (int) ($places[$code] ?? 2);
    }
}
