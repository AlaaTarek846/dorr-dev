<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceService;
use Modules\Freelance\Models\FreelanceServiceOrder;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Market\FreelancerServices;
use Modules\Freelance\Services\Market\ServiceOrders;
use Modules\Freelance\Services\Plans\FreelancePerks;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * FL-L04, the freelancer's side: my ready-made services (levels, extras, images; sending for review, pausing, deleting) and the orders
 * clients placed on them (accepting or declining one — the same as the agreement's approve / decline).
 */
class FreelancerServiceController extends Controller
{
    public function __construct(private readonly FreelancerServices $services, private readonly ServiceOrders $orders) {}

    /** GET services[?status=] — mine, newest first, with `meta.counts` per status. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(FreelanceService::STATUSES)]]);
        $me = $this->me($request);
        $list = $me->services()->with(['tiers', 'addons', 'media', 'specialty.translations', 'country.currency'])
            ->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))->latest('id')->get();
        $counts = $me->services()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        // What the form needs before the first service exists: my currency, the price limits here, whether the staff look first.
        $country = \App\Models\Country::query()->with('currency')->find((int) $me->country_id);
        $policy = app(\Modules\Freelance\Services\Policy\FreelancePolicies::class)->for((int) $me->country_id, null);

        return ApiResponse::success([
            'services' => $list->map(fn (FreelanceService $s) => $this->services->present($s, 'owner'))->values(),
            'counts' => collect(FreelanceService::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
            'limits' => [
                'max_images' => (int) config('freelance.services.max_images', 5), 'max_addons' => (int) config('freelance.services.max_addons', 5),
                'max_features' => (int) config('freelance.services.max_features', 10),
                'max_active' => app(FreelancePerks::class)->servicesLimit($me, (int) ($policy->get('services.max_active') ?? 5)),
            ],
            'currency' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->code(),
            'decimals' => app(\Modules\Freelance\Services\Policy\FreelanceMoney::class)->decimals(),
            'price' => ['min_minor' => $policy->get('project.min_budget_minor') === null ? null : (int) $policy->get('project.min_budget_minor'), 'max_minor' => $policy->get('project.max_budget_minor') === null ? null : (int) $policy->get('project.max_budget_minor')],
            'review' => (bool) ($policy->get('services.require_review') ?? true),
            'answer_hours' => (int) ($policy->get('services.answer_hours') ?? 48),
        ], __('api.retrieved'));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return $this->answer($this->services->find($this->me($request), $uuid));
    }

    public function store(Request $request): JsonResponse
    {
        $service = $this->services->create($this->me($request), $request->validate($this->rules(true)));

        return $this->answer($service, __('api.created'), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $service = $this->services->find($this->me($request), $uuid);

        return $this->answer($this->services->update($service, $request->validate($this->rules(false))), __('api.updated'));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->services->delete($this->services->find($this->me($request), $uuid));

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** POST services/{uuid}/submit | pause | resume */
    public function action(Request $request, string $uuid, string $action): JsonResponse
    {
        $me = $this->me($request);
        $service = $this->services->find($me, $uuid);
        $done = match ($action) {
            'submit' => $this->services->submit($service, $me),
            'pause' => $this->services->pause($service),
            default => $this->services->resume($service, $me),
        };

        return $this->answer($done, __('api.updated'));
    }

    public function addImage(Request $request, string $uuid): JsonResponse
    {
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', new \Modules\Freelance\Rules\CleanUpload]]);
        $service = $this->services->find($this->me($request), $uuid);
        $this->services->addImage($service, $request->file('image'));

        return $this->answer($service->refresh(), __('api.created'), 201);
    }

    public function destroyImage(Request $request, string $uuid, int $media): JsonResponse
    {
        $service = $this->services->find($this->me($request), $uuid);
        $service->getMedia('images')->firstWhere('id', $media)?->delete() ?? abort(404);

        return $this->answer($service->refresh(), __('api.deleted'));
    }

    public function image(Request $request, string $uuid, int $media): BinaryFileResponse
    {
        $m = $this->services->find($this->me($request), $uuid)->getMedia('images')->firstWhere('id', $media) ?? abort(404);

        return FreelancerPortfolioController::file($m);
    }

    // -------------------------------------------------------------- orders

    /** GET service-orders[?status=awaiting|active|completed|ended&per_page=] */
    public function orders(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(array_keys(FreelanceServiceOrder::FILTERS))], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = $this->orders->page('freelancer', $this->me($request)->id, $f['status'] ?? null, (int) ($f['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->map(fn ($o) => $this->orders->present($o, 'freelancer'))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    public function order(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success($this->orders->present($this->orders->forFreelancer($this->me($request), $uuid), 'freelancer'), __('api.retrieved'));
    }

    /** POST service-orders/{uuid}/accept | decline {reason?} */
    public function answerOrder(Request $request, string $uuid, string $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $me = $this->me($request);
        $order = $this->orders->forFreelancer($me, $uuid);
        $done = $action === 'accept' ? $this->orders->accept($order, $me) : $this->orders->decline($order, $me, $data['reason'] ?? null);

        return ApiResponse::success($this->orders->present($done, 'freelancer'), $action === 'accept' ? __('freelance.agreement_active') : __('api.updated'));
    }

    // -------------------------------------------------------------- inside

    /** @return array<string, mixed> */
    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'title' => [$req, 'string', 'min:5', 'max:120'],
            'description' => [$req, 'string', 'min:30', 'max:5000'],
            'requirements' => ['nullable', 'string', 'max:1000'],
            'specialty_id' => ['nullable', 'integer'],
            // FL-L05: sold once or by the month.
            'billing' => ['sometimes', Rule::in(FreelanceService::BILLINGS)],
            'tiers' => [$req, 'array', 'min:1', 'max:3'],
            'tiers.*.level' => ['required', 'distinct', Rule::in(FreelanceService::LEVELS)],
            'tiers.*.name' => ['nullable', 'string', 'max:60'],
            'tiers.*.description' => ['nullable', 'string', 'max:500'],
            'tiers.*.price_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'tiers.*.delivery_days' => ['required', 'integer', 'min:1', 'max:365'],
            'tiers.*.revisions' => ['nullable', 'integer', 'min:0', 'max:50'],
            'tiers.*.features' => ['nullable', 'array', 'max:'.(int) config('freelance.services.max_features', 10)],
            'tiers.*.features.*' => ['string', 'max:80'],
            'addons' => ['sometimes', 'array', 'max:'.(int) config('freelance.services.max_addons', 5)],
            'addons.*.title' => ['required', 'string', 'min:2', 'max:80'],
            'addons.*.price_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'addons.*.extra_days' => ['nullable', 'integer', 'min:0', 'max:60'],
        ] + FreelancerOfferController::rightsRules();
    }

    private function answer(FreelanceService $s, ?string $message = null, int $status = 200): JsonResponse
    {
        return ApiResponse::success($this->services->present($s->load(['tiers', 'addons', 'media', 'specialty.translations']), 'owner'), $message ?? __('api.retrieved'), $status);
    }

    private function me(Request $request): Freelancer
    {
        /** @var Freelancer */
        return $request->user('freelance_api');
    }
}
