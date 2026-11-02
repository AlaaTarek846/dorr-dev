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
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerPortfolioController;
use Modules\Freelance\Models\FreelanceService as Service;
use Modules\Freelance\Models\FreelanceServiceOrder as Order;
use Modules\Freelance\Services\Market\FreelancerServices;
use Modules\Freelance\Services\Market\ServiceOrders;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin → Freelance → Ready-made services (FL-L04): the queue (the oldest waiting first), one service with its images (opened through
 * this signed-in endpoint), the decision — publish, send back with a reason, stop one that breaks the rules or bring it back — and the
 * orders placed on services. How strict it is (review first, how many per freelancer, the hours to accept) is in the policies page.
 */
class FreelanceServiceAdminController extends Controller implements HasMiddleware
{
    public function __construct(private readonly FreelancerServices $services, private readonly ServiceOrders $orders) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-services', [
            ['view', ['index', 'show', 'image', 'orders', 'subscriptions']],
            ['update', ['approve', 'reject', 'suspend', 'unsuspend']],
        ]);
    }

    /** GET freelance-services[?status=&country_id=&search=&per_page=] — no drafts; `counts` per status. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(Service::STATUSES)],
            'country_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $base = Service::query()->where('status', '!=', Service::DRAFT)
            ->when(isset($f['country_id']), fn ($q) => $q->where('country_id', $f['country_id']))
            ->when(isset($f['search']), function ($q) use ($f) {
                $term = '%'.addcslashes($f['search'], '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $term)->orWhereHas('freelancer', fn ($p) => $p->where('name', 'like', $term)->orWhere('phone', 'like', $term)));
            });
        $counts = (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $page = (clone $base)->with(['freelancer', 'country.currency', 'tiers', 'specialty.translations'])
            ->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->orderByRaw("status = 'pending_review' desc")->orderBy('submitted_at')->orderBy('id')
            ->paginate((int) ($f['per_page'] ?? 20));

        return ApiResponse::success([
            'services' => $page->getCollection()->map(fn (Service $s) => [
                'uuid' => $s->uuid, 'title' => $s->title, 'status' => $s->status, 'status_label' => __('freelance.service_statuses.'.$s->status),
                'starting_price_minor' => $s->tiers->first()?->price_minor, 'currency' => $s->currency_code, 'decimals' => (int) ($s->country?->currency?->decimal_places ?? 2),
                'levels' => $s->tiers->count(), 'country' => $s->country?->code, 'billing' => $s->billing,
                'specialty' => $s->specialty?->translations->firstWhere('locale', app()->getLocale())?->name ?? $s->specialty?->translations->first()?->name,
                'freelancer' => ['id' => $s->freelancer?->id, 'name' => $s->freelancer?->name, 'phone' => $s->freelancer?->phone],
                'submitted_at' => $s->submitted_at?->toIso8601String(), 'published_at' => $s->published_at?->toIso8601String(),
            ])->values(),
            'counts' => collect(Service::STATUSES)->reject(fn ($s) => $s === Service::DRAFT)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
        ], __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    public function show(string $uuid): JsonResponse
    {
        $s = $this->find($uuid);

        return ApiResponse::success($this->services->present($s, 'staff') + [
            'freelancer' => ['id' => $s->freelancer?->id, 'uuid' => $s->freelancer?->uuid, 'name' => $s->freelancer?->name, 'phone' => $s->freelancer?->phone, 'status' => $s->freelancer?->status?->value],
            'country' => $s->country?->code,
        ], __('api.retrieved'));
    }

    public function image(string $uuid, int $media): BinaryFileResponse
    {
        $m = $this->find($uuid)->getMedia('images')->firstWhere('id', $media) ?? abort(404);

        return FreelancerPortfolioController::file($m);
    }

    public function approve(Request $request, string $uuid): JsonResponse
    {
        return $this->decided($this->services->approve($this->find($uuid), (int) $request->user('admin_api')->id));
    }

    public function reject(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->decided($this->services->reject($this->find($uuid), $data['reason'], (int) $request->user('admin_api')->id));
    }

    public function suspend(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->decided($this->services->suspend($this->find($uuid), $data['reason'], (int) $request->user('admin_api')->id));
    }

    public function unsuspend(Request $request, string $uuid): JsonResponse
    {
        return $this->decided($this->services->unsuspend($this->find($uuid), (int) $request->user('admin_api')->id));
    }

    /** GET freelance-services/orders[?status=&search=&per_page=] */
    public function orders(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(Order::FILTERS))],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = Order::query()->with(['service', 'project', 'agreement', 'freelancer', 'client'])
            ->when(isset($f['status']), fn ($q) => $q->inStatus($f['status']))
            ->when(isset($f['search']), function ($q) use ($f) {
                $term = '%'.addcslashes($f['search'], '%_\\').'%';
                $q->where(fn ($w) => $w->whereHas('service', fn ($s) => $s->withTrashed()->where('title', 'like', $term))
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term)->orWhere('phone', 'like', $term))
                    ->orWhereHas('freelancer', fn ($c) => $c->where('name', 'like', $term)->orWhere('phone', 'like', $term)));
            })
            ->latest('id')->paginate((int) ($f['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->map(fn (Order $o) => $this->orders->present($o, 'staff'))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** FL-L05: GET freelance-services/subscriptions[?status=pending|active|ended&search=&per_page=] - the monthly subscriptions. */
    public function subscriptions(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'active', 'ended'])],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $subs = app(\Modules\Freelance\Services\Market\ServiceSubscriptions::class);
        $page = $subs->page('staff', null, $f['status'] ?? null, (int) ($f['per_page'] ?? 20), $f['search'] ?? null);

        return ApiResponse::success($page->getCollection()->map(fn ($s) => $subs->present($s, 'staff'))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    private function find(string $uuid): Service
    {
        return Service::query()->where('uuid', $uuid)->where('status', '!=', Service::DRAFT)->with(['freelancer', 'tiers', 'addons', 'media', 'specialty.translations', 'country.currency'])->firstOrFail();
    }

    private function decided(Service $s): JsonResponse
    {
        return ApiResponse::success($this->services->present($s, 'staff'), __('api.updated'));
    }
}
