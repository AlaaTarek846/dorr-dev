<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceSpecialOffer as SpecialOffer;
use Modules\Freelance\Services\Offers\SpecialOffers;

/**
 * FL-L09: the staff's read-only view of special offers — who offered whom what, at which price, and what came of it — to spot a freelancer
 * pressing clients. The limits themselves are policy (Prices & rules → Special offers) and the switch is `special_offers`.
 */
class FreelanceSpecialOfferAdminController extends Controller implements HasMiddleware
{
    public function __construct(private readonly SpecialOffers $offers) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-special-offers', [
            ['view', ['index', 'show']],
        ]);
    }

    /** GET freelance-special-offers[?status=&kind=&search=&per_page=] — `counts` per status. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(SpecialOffer::STATUSES)],
            'kind' => ['nullable', Rule::in(SpecialOffer::KINDS)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $base = SpecialOffer::query()
            ->when(isset($f['kind']), fn ($q) => $q->where('kind', $f['kind']))
            ->when(isset($f['search']), function ($q) use ($f) {
                $term = '%'.addcslashes($f['search'], '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $term)
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term)->orWhere('phone', 'like', $term))
                    ->orWhereHas('freelancer', fn ($c) => $c->where('name', 'like', $term)->orWhere('phone', 'like', $term)));
            });
        $counts = (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $page = (clone $base)->with(['freelancer.media', 'client.media', 'service', 'consultationOffer', 'specialty.translations', 'project', 'serviceOrder', 'consultation'])
            ->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->latest('id')->paginate((int) ($f['per_page'] ?? 20));

        return ApiResponse::success(
            $page->getCollection()->map(fn ($o) => $this->offers->present($o, 'staff'))->values(), __('api.retrieved'), 200,
            ApiPaginator::meta($page), ['counts' => collect(SpecialOffer::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all()],
        );
    }

    public function show(string $uuid): JsonResponse
    {
        $o = SpecialOffer::query()->where('uuid', $uuid)->with(['freelancer.media', 'client.media', 'service', 'consultationOffer', 'specialty.translations', 'project', 'serviceOrder', 'consultation'])->firstOrFail();

        return ApiResponse::success($this->offers->present($o, 'staff'), __('api.retrieved'));
    }
}
