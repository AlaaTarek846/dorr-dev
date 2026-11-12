<?php

namespace Modules\Moving\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Moving\Enums\MovingStatus;
use Modules\Moving\Enums\OfferStatus;
use Modules\Moving\Exceptions\MovingException;
use Modules\Moving\Http\Requests\MovingOfferRequest;
use Modules\Moving\Http\Resources\MovingInventoryResource;
use Modules\Moving\Http\Resources\MovingOfferResource;
use Modules\Moving\Http\Resources\MovingRequestResource;
use Modules\Moving\Models\MovingCase;
use Modules\Moving\Models\MovingChangeOrder;
use Modules\Moving\Models\MovingInventoryItem;
use Modules\Moving\Models\MovingOffer;
use Modules\Moving\Models\MovingRequest;
use Modules\Moving\Models\MovingTicket;
use Modules\Moving\Services\Offers\MovingOfferService;
use Modules\Moving\Services\Requests\MovingAssignmentService;
use Modules\Moving\Services\Requests\MovingCancellationService;
use Modules\Moving\Services\Requests\MovingExecutionService;
use Modules\Provider\Http\Resources\App\VehicleResource;
use Modules\Provider\Models\Branch;
use Modules\Provider\Models\Membership;
use Modules\Provider\Models\Vehicle;
use Modules\Provider\Services\Establishments\EstablishmentAccess;
use Modules\Provider\Services\Establishments\WorkGate;

/**
 * The providers app's moving screens (B-03, B-04, B-07): the requests that reached the member's branches (the
 * neighbourhood only), the establishment's offers, its jobs and handing them to drivers; and a driver's own jobs.
 */
class MovingProviderController extends Controller
{
    public function __construct(
        private readonly EstablishmentAccess $access,
        private readonly MovingOfferService $offers,
        private readonly MovingAssignmentService $assignments,
        private readonly WorkGate $gate,
    ) {}

    /**
     * The numbers on the app's home (docs/moving_business.md §17 — what each role sees): new requests in the member's
     * branches, offers waiting for approval, running and today's jobs, the driver's own jobs and open tickets. A number
     * the member's permissions don't cover is null, so the app hides its card.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $actor = $this->access->current($request);
        $branchIds = $this->access->branchIds($actor);
        $inBranches = fn ($q) => $branchIds === null ? $q : $q->whereIn('branch_id', $branchIds);
        $active = array_map(fn ($s) => $s->value, array_filter(MovingStatus::cases(), fn ($s) => $s->isActiveJob()));

        $newRequests = null;
        if ($this->access->can($actor, 'requests.view')) {
            $ids = DB::table('moving_request_branches')->where('establishment_id', $actor->establishment_id)
                ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))->pluck('request_id');
            $answered = MovingOffer::query()->where('establishment_id', $actor->establishment_id)->whereIn('request_id', $ids)->pluck('request_id');
            $newRequests = MovingRequest::query()->whereIn('id', $ids)->whereNotIn('id', $answered)->where('status', MovingStatus::Open->value)
                ->where(fn ($q) => $q->whereNull('offers_deadline_at')->orWhere('offers_deadline_at', '>', now()))->count();
        }
        $jobs = MovingRequest::query()->where('establishment_id', $actor->establishment_id)->tap($inBranches);

        return ApiResponse::success([
            'new_requests' => $newRequests,
            'offers_awaiting_approval' => $this->access->can($actor, 'offers.approve')
                ? MovingOffer::query()->where('establishment_id', $actor->establishment_id)->tap($inBranches)->where('status', OfferStatus::AwaitingApproval->value)->count() : null,
            'jobs_running' => $this->access->can($actor, 'jobs.track') ? (clone $jobs)->whereIn('status', $active)->count() : null,
            'jobs_unassigned' => $this->access->can($actor, 'jobs.assign') ? (clone $jobs)->where('status', MovingStatus::Confirmed->value)->count() : null,
            'jobs_today' => $this->access->can($actor, 'jobs.track') ? (clone $jobs)->whereIn('status', $active)->whereDate('scheduled_at', today())->count() : null,
            'my_jobs' => MovingRequest::query()->where('driver_membership_id', $actor->id)->whereIn('status', $active)->count(),
            'open_tickets' => $this->access->can($actor, 'tickets.handle')
                ? MovingTicket::query()->where('establishment_id', $actor->establishment_id)->whereIn('status', ['awaiting_company', 'new'])->count() : null,
        ], __('api.retrieved'));
    }

    /** Open requests in the member's branches, newest first, with the establishment's own offer if it sent one. */
    public function requests(Request $request): JsonResponse
    {
        $actor = $this->access->current($request);
        $this->access->authorize($actor, 'requests.view');
        $branchIds = $this->access->branchIds($actor);

        $ids = DB::table('moving_request_branches')->where('establishment_id', $actor->establishment_id)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->pluck('request_id')->unique();
        $rows = MovingRequest::query()->whereIn('id', $ids)->where('status', MovingStatus::Open->value)
            ->where(fn ($q) => $q->whereNull('offers_deadline_at')->orWhere('offers_deadline_at', '>', now()))
            ->with('vehicleType.translations')->orderByDesc('published_at')->paginate(20);
        $mine = MovingOffer::query()->where('establishment_id', $actor->establishment_id)->whereIn('request_id', collect($rows->items())->pluck('id'))
            ->get()->keyBy('request_id');

        return ApiResponse::fromPaginator($rows, collect($rows->items())->map(fn (MovingRequest $r) => [
            ...(new MovingRequestResource($r))->masked()->resolve(),
            'my_offer' => isset($mine[$r->id]) ? ['uuid' => $mine[$r->id]->uuid, 'status' => $mine[$r->id]->status->value, 'price_minor' => $mine[$r->id]->price_minor] : null,
        ])->values(), __('api.retrieved'));
    }

    public function showRequest(Request $request, MovingRequest $move): JsonResponse
    {
        $actor = $this->access->current($request);
        $ownJob = $move->establishment_id === $actor->establishment_id;
        if (! $ownJob) {
            $this->offers->reachedBranch($actor, $move);
        }

        $move->load($ownJob ? ['vehicleType.translations', 'vehicle', 'driver.provider'] : ['vehicleType.translations']);
        $data = (new MovingRequestResource($move))->masked(! $ownJob)->withoutMoney(! $this->access->can($actor, 'jobs.track', $move->branch))->resolve();
        if (! $ownJob) {
            $data['my_offer'] = ($offer = MovingOffer::query()->where('request_id', $move->id)->where('establishment_id', $actor->establishment_id)
                ->latest('id')->with(['establishment', 'branch', 'vehicle.type.translations'])->first()) ? (new MovingOfferResource($offer))->resolve() : null;
            $data['my_vehicles'] = $this->vehiclesFor($actor, $move);
            // The values kept with the request, so the offer form shows as the price is typed what the customer pays
            // (price + tax) and what the establishment gets (price − Dorr's commission) — MovingPricing::forPrice.
            $data['pricing'] = [
                'tax_bp' => (int) ($move->policy('tax.bp') ?? 0),
                'commission_bp' => (int) ($move->policy('commission.bp') ?? 0),
                'commission_min_minor' => $move->policy('commission.min_minor'),
                'commission_max_minor' => $move->policy('commission.max_minor'),
            ];
        }

        return ApiResponse::success($data, __('api.retrieved'));
    }

    /**
     * The establishment's vehicles for the offer form, the ones that can answer first: `fits` = a type the customer
     * asked for, `problem` = why it can't be offered now (not approved, papers…) — null when it can.
     *
     * @return list<array<string, mixed>>
     */
    private function vehiclesFor(Membership $actor, MovingRequest $move): array
    {
        $types = $move->vehicleTypeIds();
        $branch = $this->offers->reachedBranch($actor, $move);

        return Vehicle::query()->where('establishment_id', $actor->establishment_id)->with('type.translations')->get()
            ->map(function (Vehicle $v) use ($actor, $move, $types, $branch) {
                $fits = $types === [] || in_array((int) $v->vehicle_type_id, $types, true);
                $problem = $fits ? $this->gate->offerProblem($actor, $v, $move->service_category_id, $branch) : 'vehicle_type_not_asked';

                return [
                    ...(new VehicleResource($v))->resolve(),
                    'fits' => $fits,
                    'problem' => $problem,
                    'problem_text' => $problem === null ? null : ($problem === 'vehicle_type_not_asked' ? __('moving.errors.vehicle_type_not_asked') : __('provider.errors.'.$problem)),
                ];
            })
            ->sortBy(fn (array $v) => [$v['problem'] !== null, ! $v['fits']])->values()->all();
    }

    public function sendOffer(MovingOfferRequest $request, MovingRequest $move): JsonResponse
    {
        $offer = $this->offers->send($this->access->current($request), $move, $request->validated());

        return ApiResponse::created(new MovingOfferResource($offer->load(['establishment', 'branch', 'vehicle.type.translations'])), __('moving.offer_'.$offer->status->value));
    }

    public function updateOffer(MovingOfferRequest $request, MovingOffer $offer): JsonResponse
    {
        $offer = $this->offers->update($this->access->current($request), $offer, $request->validated());

        return ApiResponse::success(new MovingOfferResource($offer->load(['establishment', 'branch', 'vehicle.type.translations'])), __('api.updated'));
    }

    public function withdrawOffer(Request $request, MovingOffer $offer): JsonResponse
    {
        $this->offers->withdraw($this->access->current($request), $offer);

        return ApiResponse::success([], __('moving.offer_withdrawn'));
    }

    public function approveOffer(Request $request, MovingOffer $offer): JsonResponse
    {
        $offer = $this->offers->approve($this->access->current($request), $offer);

        return ApiResponse::success(new MovingOfferResource($offer->load(['establishment', 'branch', 'vehicle.type.translations'])), __('moving.offer_sent'));
    }

    /** The establishment's offers; `status=awaiting_approval` is what waits for the approver. */
    public function myOffers(Request $request): JsonResponse
    {
        $actor = $this->access->current($request);
        $this->access->authorize($actor, 'requests.view');
        $branchIds = $this->access->branchIds($actor);
        $rows = MovingOffer::query()->where('establishment_id', $actor->establishment_id)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->with(['establishment', 'branch', 'vehicle.type.translations'])->orderByDesc('id')->paginate(20);

        return ApiResponse::fromPaginator($rows, MovingOfferResource::collection($rows->items()), __('api.retrieved'));
    }

    /** The establishment's accepted jobs in the member's branches. */
    public function jobs(Request $request): JsonResponse
    {
        $actor = $this->access->current($request);
        $this->access->authorize($actor, 'jobs.track');
        $branchIds = $this->access->branchIds($actor);
        $rows = MovingRequest::query()->where('establishment_id', $actor->establishment_id)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->whereIn('status', array_map(fn ($s) => $s->value, array_filter(MovingStatus::cases(), fn ($s) => $s->isActiveJob()))))
            ->with(['vehicleType.translations', 'vehicle', 'driver.provider'])->orderBy('scheduled_at')->paginate(20);

        return ApiResponse::fromPaginator($rows, MovingRequestResource::collection($rows->items()), __('api.retrieved'));
    }

    public function assign(Request $request, MovingRequest $move): JsonResponse
    {
        $data = $request->validate([
            'driver_uuid' => ['required', 'uuid'],
            'vehicle_uuid' => ['nullable', 'uuid'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $actor = $this->access->current($request);
        $driver = Membership::query()->where('uuid', $data['driver_uuid'])->firstOr(fn () => throw new MovingException('driver_not_found', 404));
        $vehicle = isset($data['vehicle_uuid']) ? Vehicle::query()->where('uuid', $data['vehicle_uuid'])->first() : null;
        $move = $this->assignments->assign($actor, $move, $driver, $vehicle, $data['reason'] ?? null);

        return ApiResponse::success(new MovingRequestResource($move->load(['vehicle', 'driver.provider'])), __('moving.assigned'));
    }

    /** «مهامي»: the jobs given to this member as a driver. */
    public function myJobs(Request $request): JsonResponse
    {
        $actor = $this->access->current($request);
        $rows = MovingRequest::query()->where('driver_membership_id', $actor->id)
            ->whereIn('status', array_map(fn ($s) => $s->value, array_filter(MovingStatus::cases(), fn ($s) => $s->isActiveJob() || $s === MovingStatus::Delivered)))
            ->with(['vehicleType.translations', 'vehicle', 'driver.provider'])->orderBy('scheduled_at')->get();

        $canSeeMoney = $this->access->can($actor, 'jobs.track');

        return ApiResponse::success($rows->map(fn (MovingRequest $r) => (new MovingRequestResource($r))->withoutMoney(! $canSeeMoney))->values(), __('api.retrieved'));
    }

    public function acceptJob(Request $request, MovingRequest $move): JsonResponse
    {
        $move = $this->assignments->accept($this->access->current($request), $move);

        return ApiResponse::success(new MovingRequestResource($move), __('moving.job_accepted'));
    }

    public function declineJob(Request $request, MovingRequest $move): JsonResponse
    {
        $this->assignments->decline($this->access->current($request), $move);

        return ApiResponse::success([], __('moving.job_declined'));
    }

    /** The driver's next step on moving day (set_out, arrived, loading, departed, unloading). */
    public function step(Request $request, MovingRequest $move, MovingExecutionService $execution): JsonResponse
    {
        $data = $request->validate([
            'step' => ['required', 'in:set_out,arrived,loading,departed,unloading'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $move = $execution->step($this->access->current($request), $move, $data['step'], $data['lat'] ?? null, $data['lng'] ?? null, $data['note'] ?? null);

        return ApiResponse::success(new MovingRequestResource($move), __('moving.step_saved'));
    }

    /** Positions from the driver's app, newest last (sent in batches, like the captain app). */
    public function locations(Request $request, MovingRequest $move, MovingExecutionService $execution): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'array', 'min:1', 'max:100'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'points.*.heading' => ['nullable', 'integer', 'between:0,360'],
        ]);
        $last = end($data['points']);
        $execution->location($this->access->current($request), $move, (float) $last['lat'], (float) $last['lng'], $last['heading'] ?? null);

        return ApiResponse::success([], __('api.updated'));
    }

    public function addItem(Request $request, MovingRequest $move, MovingExecutionService $execution): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:150'],
            'photo' => ['required', 'image', 'max:10240'],
            'trip_no' => ['nullable', 'integer', 'between:1,50'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $item = $execution->addItem($this->access->current($request), $move, $data['label'], $request->file('photo'), (int) ($data['trip_no'] ?? 1), $data['note'] ?? null);

        return ApiResponse::created(new MovingInventoryResource($item), __('api.created'));
    }

    public function deliverItem(Request $request, MovingRequest $move, MovingInventoryItem $item, MovingExecutionService $execution): JsonResponse
    {
        $data = $request->validate(['photo' => ['nullable', 'image', 'max:10240'], 'note' => ['nullable', 'string', 'max:500']]);
        $item = $execution->deliverItem($this->access->current($request), $move, $item, $request->file('photo'), $data['note'] ?? null);

        return ApiResponse::success(new MovingInventoryResource($item), __('api.updated'));
    }

    public function inventory(Request $request, MovingRequest $move): JsonResponse
    {
        $actor = $this->access->current($request);
        if ($move->establishment_id !== $actor->establishment_id) {
            throw new MovingException('request_not_found', 404);
        }

        return ApiResponse::success(MovingInventoryResource::collection($move->inventory), __('api.retrieved'));
    }

    /** Delivery by the customer's code (or the QR); without it, the reason (refused / absent) and a note. */
    public function deliver(Request $request, MovingRequest $move, MovingExecutionService $execution): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:8'],
            'recipient_name' => ['nullable', 'string', 'max:120'],
            'without_code' => ['nullable', 'in:refused,absent'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $move = $execution->deliver($this->access->current($request), $move, $data, $data['lat'] ?? null, $data['lng'] ?? null);

        return ApiResponse::success(new MovingRequestResource($move), __('moving.delivered'));
    }

    public function cancel(Request $request, MovingRequest $move, MovingCancellationService $cancellations): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:40'], 'details' => ['nullable', 'string', 'max:1000']]);
        $result = $cancellations->byEstablishment($this->access->current($request), $move, $data['reason'], $data['details'] ?? null);

        return $result instanceof MovingCase
            ? ApiResponse::created(['case' => $result->uuid, 'kind' => $result->kind], __('moving.exception_requested'))
            : ApiResponse::success(new MovingRequestResource($result), __('moving.cancelled'));
    }

    /** A job's changes (price differences, waiting, new times) with the ones waiting for an answer first. */
    public function changes(Request $request, MovingRequest $move): JsonResponse
    {
        $actor = $this->access->current($request);
        if ($move->establishment_id !== $actor->establishment_id) {
            throw new MovingException('request_not_found', 404);
        }

        return ApiResponse::success(MovingChangeOrder::query()->where('request_id', $move->id)
            ->orderByRaw("status = 'pending' desc")->orderByDesc('id')->get()
            ->map(fn (MovingChangeOrder $c) => [
                'uuid' => $c->uuid, 'kind' => $c->kind, 'proposed_by' => $c->proposed_by, 'status' => $c->status,
                'price_delta_minor' => $c->price_delta_minor, 'workers_count' => $c->workers_count, 'trips_count' => $c->trips_count,
                'new_scheduled_at' => $c->new_scheduled_at?->toIso8601String(), 'reason' => $c->reason,
                'before' => $c->before, 'after' => $c->after, 'created_at' => $c->created_at?->toIso8601String(),
            ])->values(), __('api.retrieved'));
    }

    /** The drivers a job can be given to: active members of the job's branch who may drive, with whether their licence is valid. */
    public function drivers(Request $request, WorkGate $gate): JsonResponse
    {
        $actor = $this->access->current($request);
        $this->access->authorize($actor, 'jobs.assign');
        $branch = $request->query('branch_id') ? Branch::query()->where('establishment_id', $actor->establishment_id)->find($request->query('branch_id')) : null;

        $drivers = Membership::query()->with('provider')->where('establishment_id', $actor->establishment_id)->where('status', 'active')->get()
            ->filter(fn (Membership $m) => $this->access->can($m, 'driving.work') && ($branch === null || $this->access->coversBranch($m, $branch)))
            ->map(fn (Membership $m) => [
                'uuid' => $m->uuid,
                'name' => $m->provider?->name,
                'phone' => $m->provider?->phone,
                'avatar' => $m->provider?->getSingleMediaUrl('avatar') ?: null,
                'problem' => $gate->driverProblem($m),
                'busy_jobs' => MovingRequest::query()->where('driver_membership_id', $m->id)->whereIn('status', ['assigned', 'driver_on_way', 'arrived', 'loading', 'in_transit', 'unloading'])->count(),
            ])->values();

        return ApiResponse::success($drivers, __('api.retrieved'));
    }
}
