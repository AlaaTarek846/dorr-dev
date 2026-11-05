<?php

namespace Modules\Moving\Services\Requests;

use App\Models\Country;
use App\Models\ServiceCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Moving\Enums\MovingStatus;
use Modules\Moving\Enums\OfferStatus;
use Modules\Moving\Exceptions\MovingException;
use Modules\Moving\Models\MovingRequest;
use Modules\Moving\Services\Policy\MovingPolicies;
use Modules\Provider\Enums\EstablishmentStatus;
use Modules\Provider\Models\Branch;
use Modules\User\Models\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The customer's side of a request (B-02, B-03): a draft that survives a lost connection (REQ-014), its photos and
 * videos, publishing it to every branch whose area holds the pickup point — of approved establishments that offer
 * moving — and taking it back before choosing an offer (REQ-015). Requests that got no chosen offer in time expire
 * and are never given to anyone without the customer (REQ-020).
 */
class MovingRequestService
{
    public const SERVICE = 'moving';

    private const FIELDS = [
        'load_type', 'description', 'pieces_count', 'dimensions', 'vehicle_type_id', 'workers_count', 'packing', 'disassembly',
        'crane', 'technician', 'scheduled_at', 'notes',
        'pickup_lat', 'pickup_lng', 'pickup_address', 'pickup_area', 'pickup_floor', 'pickup_elevator', 'pickup_entrance',
        'dropoff_lat', 'dropoff_lng', 'dropoff_address', 'dropoff_area', 'dropoff_floor', 'dropoff_elevator', 'dropoff_entrance',
    ];

    public function __construct(
        private readonly MovingPolicies $policies,
        private readonly MovingJournal $journal,
    ) {}

    public static function service(): ServiceCategory
    {
        return ServiceCategory::query()->where('module_name', self::SERVICE)->firstOr(fn () => throw new MovingException('service_off', 403));
    }

    /** @param array<string, mixed> $data */
    public function create(User $user, Country $country, array $data): MovingRequest
    {
        $service = self::service();
        $this->assertServiceOpen($country);

        $request = new MovingRequest([
            ...array_intersect_key($data, array_flip(self::FIELDS)),
            'user_id' => $user->id,
            'country_id' => $country->id,
            'currency_code' => (string) $country->currency?->code,
            'service_category_id' => $service->id,
            'status' => MovingStatus::Draft,
        ]);

        return $this->journal->move($request, MovingStatus::Draft, 'drafted', 'user', $user->id);
    }

    /** @param array<string, mixed> $data */
    public function update(MovingRequest $request, array $data): MovingRequest
    {
        $this->assertDraft($request);
        $request->fill(array_intersect_key($data, array_flip(self::FIELDS)))->save();

        return $request;
    }

    public function addMedia(MovingRequest $request, UploadedFile $file): Media
    {
        $this->assertDraft($request);
        $limit = $this->policies->for($request->country_id)['values']['media.max_mb'] ?? null;
        if ($limit !== null && $file->getSize() > (float) $limit * 1024 * 1024) {
            throw new MovingException('media_too_large', 422, ['mb' => $limit]);
        }
        $collection = str_starts_with((string) $file->getMimeType(), 'video/') ? 'videos' : 'photos';

        return $request->addMedia($file)->toMediaCollection($collection);
    }

    public function removeMedia(MovingRequest $request, int $mediaId): void
    {
        $this->assertDraft($request);
        $request->media()->whereKey($mediaId)->first()?->delete();
    }

    /** Checks the fields a request needs (DEC-037), keeps the country's values with it and sends it to the branches. */
    public function publish(MovingRequest $request): MovingRequest
    {
        $this->assertDraft($request);
        $missing = $this->missing($request);
        if ($missing !== []) {
            throw new MovingException('fields_missing', 422, [], ['missing' => $missing]);
        }
        $this->assertServiceOpen($request->country);
        if ($request->scheduled_at->isPast()) {
            throw new MovingException('time_passed');
        }

        return DB::transaction(function () use ($request): MovingRequest {
            $policy = $this->policies->for($request->country_id);
            $window = $policy['values']['offers.window_minutes'];
            $deadline = $window !== null ? now()->addMinutes((int) $window) : null;
            if ($deadline === null || $deadline->gt($request->scheduled_at)) {
                $deadline = $request->scheduled_at->copy();
            }

            $branches = $this->reachingBranches($request, $policy['values']['broadcast.max_radius_km']);
            foreach ($branches as [$branch, $km]) {
                DB::table('moving_request_branches')->insert([
                    'request_id' => $request->id, 'branch_id' => $branch->id, 'establishment_id' => $branch->establishment_id, 'distance_km' => round($km, 2),
                ]);
            }

            return $this->journal->move($request, MovingStatus::Open, 'published', 'user', $request->user_id, [
                'policy' => $policy['values'],
                'policy_version' => $policy['version'],
                'published_at' => now(),
                'offers_deadline_at' => $deadline,
            ], ['branches' => count($branches)]);
        });
    }

    /** Taken back before an offer is chosen (REQ-015); after that it is the cancellation path. */
    public function withdraw(MovingRequest $request): MovingRequest
    {
        if (! in_array($request->status, [MovingStatus::Draft, MovingStatus::Open], true)) {
            throw new MovingException('cannot_withdraw', 409);
        }

        return DB::transaction(function () use ($request): MovingRequest {
            $request->offers()->whereIn('status', [OfferStatus::Sent->value, OfferStatus::AwaitingApproval->value])->update(['status' => OfferStatus::Declined->value]);

            return $this->journal->move($request, MovingStatus::Withdrawn, 'withdrawn', 'user', $request->user_id, ['closed_at' => now()]);
        });
    }

    /** A new draft with the details and photos of a request that expired or was withdrawn; its time is asked again if it passed. */
    public function republish(MovingRequest $request): MovingRequest
    {
        if (! in_array($request->status, [MovingStatus::Expired, MovingStatus::Withdrawn], true)) {
            throw new MovingException('cannot_republish', 409);
        }

        return DB::transaction(function () use ($request): MovingRequest {
            $draft = new MovingRequest([
                ...$request->only(self::FIELDS),
                'user_id' => $request->user_id,
                'country_id' => $request->country_id,
                'currency_code' => $request->currency_code,
                'service_category_id' => $request->service_category_id,
                'status' => MovingStatus::Draft,
            ]);
            if ($draft->scheduled_at?->isPast()) {
                $draft->scheduled_at = null;
            }
            $draft = $this->journal->move($draft, MovingStatus::Draft, 'drafted', 'user', $request->user_id, [], ['from' => $request->uuid]);
            foreach (['photos', 'videos'] as $collection) {
                foreach ($request->getMedia($collection) as $media) {
                    $media->copy($draft, $collection);
                }
            }

            return $draft;
        });
    }

    /** Open requests whose offer time ran out without a choice (REQ-020). The customer can post them again. */
    public function expireDue(): int
    {
        $count = 0;
        MovingRequest::query()->where('status', MovingStatus::Open->value)->where('offers_deadline_at', '<=', now())
            ->chunkById(100, function ($requests) use (&$count): void {
                foreach ($requests as $request) {
                    DB::transaction(function () use ($request): void {
                        $request->offers()->whereIn('status', [OfferStatus::Sent->value, OfferStatus::AwaitingApproval->value])->update(['status' => OfferStatus::Expired->value]);
                        $this->journal->move($request, MovingStatus::Expired, 'expired', 'system', null, ['closed_at' => now()]);
                    });
                    $count++;
                }
            });

        return $count;
    }

    /** @return list<string> */
    public function missing(MovingRequest $request): array
    {
        $missing = [];
        if ($request->load_type === null) {
            $missing[] = 'load_type';
        }
        if (blank($request->description) && $request->getMedia('photos')->isEmpty()) {
            $missing[] = 'description_or_photo';
        }
        foreach (['pickup', 'dropoff'] as $end) {
            if ($request->{"{$end}_lat"} === null || $request->{"{$end}_lng"} === null) {
                $missing[] = $end;
            }
        }
        if ($request->scheduled_at === null) {
            $missing[] = 'scheduled_at';
        }
        if ($request->vehicle_type_id === null) {
            $missing[] = 'vehicle_type_id';
        }

        return $missing;
    }

    /**
     * Branches of approved establishments offering moving in the country, whose area (its radius, capped by the
     * country's widest) holds the pickup point.
     *
     * @return list<array{0: Branch, 1: float}>
     */
    public function reachingBranches(MovingRequest $request, mixed $maxRadius): array
    {
        $serviceId = $request->service_category_id;
        $branches = Branch::query()
            ->where('status', true)
            ->whereNotNull('lat')->whereNotNull('lng')->whereNotNull('radius_km')
            ->whereHas('establishment', fn ($q) => $q->where('status', EstablishmentStatus::Approved->value)
                ->where('country_id', $request->country_id)
                ->whereHas('services', fn ($q) => $q->where('service_categories.id', $serviceId)->where('provider_establishment_services.status', true)))
            ->get();

        $reached = [];
        foreach ($branches as $branch) {
            $radius = $maxRadius !== null ? min((float) $branch->radius_km, (float) $maxRadius) : (float) $branch->radius_km;
            $km = self::km($branch->lat, $branch->lng, $request->pickup_lat, $request->pickup_lng);
            if ($km <= $radius) {
                $reached[] = [$branch, $km];
            }
        }

        return $reached;
    }

    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function assertDraft(MovingRequest $request): void
    {
        if ($request->status !== MovingStatus::Draft) {
            throw new MovingException('not_draft', 409);
        }
    }

    public function isServiceOpen(Country $country): bool
    {
        $service = ServiceCategory::query()->where('module_name', self::SERVICE)->first();

        return $service !== null && $service->status && DB::table('country_service_category')
            ->where('country_id', $country->id)->where('service_category_id', $service->id)->exists();
    }

    /** The service is switched on for the country (REQ-001). */
    private function assertServiceOpen(Country $country): void
    {
        if (! $this->isServiceOpen($country)) {
            throw new MovingException('service_off', 403);
        }
    }
}
