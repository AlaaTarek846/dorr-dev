<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerPortfolioController;
use Modules\Freelance\Models\FreelanceService;
use Modules\Freelance\Models\FreelanceServiceOrder;
use Modules\Freelance\Services\Market\FreelanceCapacity;
use Modules\Freelance\Services\Market\ServiceCatalog;
use Modules\Freelance\Services\Market\ServiceOrders;
use Modules\Freelance\Services\Plans\FreelanceProfileViews;
use Modules\User\Models\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * FL-L04, the client's side (a Dorr user): finding ready-made services in my country, what a level with extras comes to, ordering one,
 * my orders and taking one back before the freelancer accepted it. After that the order is a project like any other.
 */
class ClientServiceController extends Controller
{
    public function __construct(private readonly ServiceCatalog $catalog, private readonly ServiceOrders $orders) {}

    /** GET services[?search=&specialty_id=&min_price=&max_price=&max_days=&sort=&freelancer=&per_page=] */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'specialty_id' => ['nullable', 'integer'],
            'freelancer' => ['nullable', 'uuid'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'max_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'sort' => ['nullable', Rule::in(ServiceCatalog::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $page = $this->catalog->browse($this->me($request), $f, (int) ($f['per_page'] ?? 20));
        $this->catalog->warm($page->getCollection());

        return ApiResponse::success($page->getCollection()->map(fn (FreelanceService $s) => $this->catalog->card($s))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $service = $this->catalog->find($me, $uuid);
        app(FreelanceProfileViews::class)->record((int) $service->freelancer_id, FreelanceProfileViews::SERVICE, 'market', $me->getKey(), (int) $service->getKey());

        // FL-L10: whether there is room for me with this freelancer, and my place in line.
        return ApiResponse::success($this->catalog->detail($service) + ['capacity' => app(FreelanceCapacity::class)->forClient($me, $service)], __('api.retrieved'));
    }

    /** GET services/{uuid}/quote?level=&addons[]= — the price, the fee and the total before ordering. */
    public function quote(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['level' => ['required', Rule::in(FreelanceService::LEVELS)], 'addons' => ['nullable', 'array', 'max:20'], 'addons.*' => ['integer']]);
        $service = $this->catalog->find($this->me($request), $uuid);

        return ApiResponse::success($this->catalog->quote($service, $data['level'], $data['addons'] ?? []), __('api.retrieved'));
    }

    /** POST services/{uuid}/order {level, addons?, note?, request_id?} */
    public function order(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'level' => ['required', Rule::in(FreelanceService::LEVELS)],
            'addons' => ['nullable', 'array', 'max:20'], 'addons.*' => ['integer'],
            'note' => ['nullable', 'string', 'max:3000'],
            'request_id' => ['nullable', 'string', 'max:64'],
        ]);
        $me = $this->me($request);
        $order = $this->orders->order($me, $this->catalog->find($me, $uuid), $data);

        return ApiResponse::success($this->orders->present($order, 'client'), __('freelance.service_ordered'), $order->wasRecentlyCreated ? 201 : 200);
    }

    /** GET service-orders[?status=awaiting|active|completed|ended&per_page=] */
    public function orders(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(array_keys(FreelanceServiceOrder::FILTERS))], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = $this->orders->page('client', $this->me($request)->id, $f['status'] ?? null, (int) ($f['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->map(fn ($o) => $this->orders->present($o, 'client'))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    public function showOrder(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success($this->orders->present($this->orders->forClient($this->me($request), $uuid), 'client'), __('api.retrieved'));
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);

        return ApiResponse::success($this->orders->present($this->orders->cancel($this->orders->forClient($me, $uuid), $me), 'client'), __('api.updated'));
    }

    /** GET service-images/{media} — signed and short-lived; still checks the service is on offer. */
    public function image(Media $media): BinaryFileResponse
    {
        $service = $media->model;
        abort_unless($service instanceof FreelanceService && $media->collection_name === 'images' && $service->live() && $service->freelancer?->status?->value === 'verified', 404);

        return FreelancerPortfolioController::file($media);
    }

    /** POST services/{uuid}/waitlist — wait in line while the freelancer is at their cap (FL-L10). */
    public function joinWaitlist(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);

        return ApiResponse::success(app(FreelanceCapacity::class)->join($me, $this->catalog->find($me, $uuid)), __('api.created'));
    }

    /** DELETE services/{uuid}/waitlist — step out of the line (a place kept for me goes to the next). */
    public function leaveWaitlist(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);

        return ApiResponse::success(app(FreelanceCapacity::class)->leave($me, $this->catalog->find($me, $uuid)), __('api.updated'));
    }

    private function me(Request $request): User
    {
        /** @var User */
        return $request->user('user_api');
    }
}
