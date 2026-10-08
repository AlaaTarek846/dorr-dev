<?php

namespace Modules\Discover\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Discover\Models\DiscoverEvent;
use Modules\Discover\Services\DiscoverService;
use Modules\Discover\Services\EventStatusService;
use Modules\Discover\Services\OrganizerService;

/**
 * Events (spec 169, 174–176, 180): the review queue (organizers' submissions), events the admin
 * adds from official sources, and status changes — which reach the interested (AT-DISC-01).
 */
class DiscoverEventController extends Controller implements HasMiddleware
{
    private const WITH = ['category.translations', 'city.translations', 'country', 'organizer', 'media'];

    public function __construct(
        private readonly DiscoverService $discover,
        private readonly EventStatusService $statuses,
    ) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('discover-events', [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update', 'review', 'status']],
            ['delete', ['destroy']],
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'review_status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'status' => ['nullable', Rule::in(DiscoverEvent::STATUSES)],
            'country_id' => ['nullable', 'integer'],
            'city_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'organizer_id' => ['nullable', 'integer'],
            'when' => ['nullable', Rule::in(['upcoming', 'past'])],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = DiscoverEvent::query()->with(self::WITH)
            ->when($filters['review_status'] ?? null, fn ($q, $v) => $q->where('review_status', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['country_id'] ?? null, fn ($q, $v) => $q->where('country_id', $v))
            ->when($filters['city_id'] ?? null, fn ($q, $v) => $q->where('city_id', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->when($filters['organizer_id'] ?? null, fn ($q, $v) => $q->where('organizer_id', $v))
            ->when(($filters['when'] ?? null) === 'upcoming', fn ($q) => $q->where(fn ($w) => $w->where('starts_at', '>=', now())->orWhere('ends_at', '>=', now())))
            ->when(($filters['when'] ?? null) === 'past', fn ($q) => $q->where('starts_at', '<', now())->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '<', now())))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('title', 'like', '%'.$s.'%')->orWhere('venue', 'like', '%'.$s.'%')))
            ->orderByRaw("case when review_status = 'pending' then 0 else 1 end")->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::success($page->getCollection()->map(fn (DiscoverEvent $e) => $this->present($e))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page), [
            'pending_count' => DiscoverEvent::query()->where('review_status', 'pending')->count(),
        ]);
    }

    public function show(DiscoverEvent $event)
    {
        return ApiResponse::success($this->present($event->load(self::WITH), true), __('api.retrieved'));
    }

    /** An event the admin adds from an official source — public and trusted at once. */
    public function store(Request $request)
    {
        $fields = OrganizerService::fields($this->validated($request, true));
        OrganizerService::assertNotDuplicate($fields['dedupe_key']);
        $event = DB::transaction(function () use ($fields, $request) {
            $event = DiscoverEvent::query()->create($fields + [
                'uuid' => (string) Str::uuid(), 'source' => 'admin', 'status' => 'confirmed', 'review_status' => 'approved', 'last_verified_at' => now(),
            ]);
            $event->setSingleMedia(DiscoverEvent::COVER, $request->file('cover'));

            return $event;
        });

        return ApiResponse::created($this->present($event->refresh()->load(self::WITH), true), __('api.created'));
    }

    /** POST (multipart). A new time on a public event goes through the status history. */
    public function update(Request $request, DiscoverEvent $event)
    {
        $fields = OrganizerService::fields($this->validated($request, false), $event);
        OrganizerService::assertNotDuplicate($fields['dedupe_key'], $event->id);
        DB::transaction(function () use ($event, $fields, $request) {
            if (isset($fields['starts_at']) && $event->review_status === 'approved' && $event->starts_at !== null && ! $fields['starts_at']->equalTo($event->starts_at)) {
                $this->statuses->change($event, $event->status === 'postponed' ? 'confirmed' : $event->status, null, $fields['starts_at'], $fields['ends_at'] ?? null, 'admin');
                unset($fields['starts_at'], $fields['ends_at']);
            }
            $event->fill($fields + ['last_verified_at' => now()])->save();
            $event->setSingleMedia(DiscoverEvent::COVER, $request->file('cover'));
        });

        return ApiResponse::success($this->present($event->refresh()->load(self::WITH), true), __('api.updated'));
    }

    /** PATCH review {review_status: approved|rejected, note?} */
    public function review(Request $request, DiscoverEvent $event)
    {
        $data = $request->validate(['review_status' => ['required', Rule::in(['approved', 'rejected', 'pending'])], 'note' => ['nullable', 'string', 'max:500']]);
        $event->update([
            'review_status' => $data['review_status'],
            'review_note' => $data['note'] ?? null,
            'last_verified_at' => $data['review_status'] === 'approved' ? now() : $event->last_verified_at,
        ]);

        return ApiResponse::success($this->present($event->load(self::WITH), true), __('api.updated'));
    }

    /** PATCH status {status, note?, starts_at? (its local time)} (175). */
    public function status(Request $request, DiscoverEvent $event)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(DiscoverEvent::STATUSES)],
            'note' => ['nullable', 'string', 'max:300'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);
        $this->statuses->change(
            $event, $data['status'], $data['note'] ?? null,
            ! empty($data['starts_at']) ? CarbonImmutable::parse($data['starts_at'], $event->timezone) : null,
            ! empty($data['ends_at']) ? CarbonImmutable::parse($data['ends_at'], $event->timezone) : null,
            'admin',
        );

        return ApiResponse::success($this->present($event->refresh()->load(self::WITH), true), __('api.updated'));
    }

    public function destroy(DiscoverEvent $event)
    {
        $event->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$required, 'string', 'min:3', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'language' => ['nullable', 'string', 'max:10'],
            'category_id' => [$required, 'integer', 'exists:discover_categories,id'],
            'city_id' => [$required, 'integer', 'exists:discover_cities,id'],
            'venue' => ['nullable', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'starts_at' => [$required, 'date'],
            'ends_at' => ['nullable', 'date'],
            'is_free' => ['sometimes', 'boolean'],
            'price_text' => ['nullable', 'string', 'max:80'],
            'source_url' => ['nullable', 'url', 'max:500'],
            'booking_url' => ['nullable', 'url', 'max:500'],
            'family_friendly' => ['sometimes', 'boolean'],
            'cover' => ['nullable', 'image', 'max:5120'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DiscoverEvent $e, bool $full = false): array
    {
        $zone = $e->timezone;
        $row = ($full ? $this->discover->presentFull($e, $zone) : $this->discover->present($e, $zone)) + [
            'category_id' => $e->category_id,
            'city_id' => $e->city_id,
            'country_id' => $e->country_id,
            'review_status' => $e->review_status,
            'review_note' => $e->review_note,
            'organizer_id' => $e->organizer_id,
            'source' => $e->source,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
        if ($full) {
            // The form edits the event's own local time.
            $row['starts_local'] = $e->starts_at ? CarbonImmutable::instance($e->starts_at)->setTimezone($zone)->format('Y-m-d\TH:i') : null;
            $row['ends_local'] = $e->ends_at ? CarbonImmutable::instance($e->ends_at)->setTimezone($zone)->format('Y-m-d\TH:i') : null;
            $row['organizer'] = $e->organizer ? ['id' => $e->organizer->id, 'name' => $e->organizer->name, 'status' => $e->organizer->status, 'verified' => $e->organizer->isVerified()] : null;
        }

        return $row;
    }
}
