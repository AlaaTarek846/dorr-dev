<?php

namespace Modules\Discover\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Discover\Models\DiscoverEvent;
use Modules\Discover\Services\DiscoverService;
use Modules\Discover\Services\OrganizerService;

/**
 * Organizers in the app (spec 180): ask to be one, add events (reviewed before they're public
 * unless the organizer is verified), keep them up to date — time, status — so the interested hear.
 */
class OrganizerController extends Controller
{
    public function __construct(
        private readonly OrganizerService $organizers,
        private readonly DiscoverService $discover,
    ) {}

    /** GET organizer — my organizer profile (or null) and my events. */
    public function show(Request $request)
    {
        return ApiResponse::success($this->organizers->dashboard($request->user(), $this->zone($request)), __('api.retrieved'));
    }

    public function apply(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'about' => ['nullable', 'string', 'max:1000'],
            'website' => ['nullable', 'url', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:191'],
        ]);
        $organizer = $this->organizers->apply($request->user(), $data);

        return ApiResponse::success($this->organizers->presentOrganizer($organizer), __('api.updated'));
    }

    public function store(Request $request)
    {
        $event = $this->organizers->submit($request->user(), $this->validated($request, true), $request->file('cover'));

        return ApiResponse::created($this->discover->presentFull($event->load(['category.translations', 'city.translations', 'country', 'organizer', 'media']), $this->zone($request), null, $request->user()), __('api.created'));
    }

    /** POST organizer/events/{event} (multipart: the cover can change). */
    public function update(Request $request, DiscoverEvent $event)
    {
        $event = $this->organizers->update($request->user(), $event, $this->validated($request, false), $request->file('cover'));

        return ApiResponse::success($this->discover->presentFull($event->load(['category.translations', 'city.translations', 'country', 'organizer', 'media']), $this->zone($request), null, $request->user()), __('api.updated'));
    }

    /** POST organizer/events/{event}/status {status, note?, starts_at?} (175). */
    public function status(Request $request, DiscoverEvent $event)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(DiscoverEvent::STATUSES)],
            'note' => ['nullable', 'string', 'max:300'],
            'starts_at' => ['nullable', 'date'],
        ]);
        $event = $this->organizers->changeStatus($request->user(), $event, $data['status'], $data['note'] ?? null, $data['starts_at'] ?? null);

        return ApiResponse::success($this->discover->presentFull($event->load(['category.translations', 'city.translations', 'country', 'organizer', 'media']), $this->zone($request), null, $request->user()), __('api.updated'));
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
            'category_id' => [$required, 'integer', Rule::exists('discover_categories', 'id')->where('status', true)],
            'city_id' => [$required, 'integer', Rule::exists('discover_cities', 'id')->where('status', true)],
            'venue' => ['nullable', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            // The event's own local time, where it happens.
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

    private function zone(Request $request): string
    {
        return $this->discover->zone($request->input('timezone'), $request->user());
    }
}
