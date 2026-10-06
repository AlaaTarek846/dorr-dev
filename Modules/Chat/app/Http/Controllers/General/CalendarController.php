<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatCalendarItem;
use Modules\Chat\Models\ChatCalendarPreference;
use Modules\Chat\Services\CalendarService;

/**
 * DORR Calendar & DORR Today (spec 201–207). Every request may send `timezone` (else my saved one):
 * days are cut and times shown where I am now.
 */
class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $calendar) {}

    /** GET calendar?from=Y-m-d&to=Y-m-d&types[]=&timezone= — the week / month views (203). */
    public function index(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'types' => ['nullable', 'array'],
            'types.*' => [Rule::in(ChatCalendarPreference::SOURCES)],
            'timezone' => ['nullable', 'timezone:all'],
        ]);

        return ApiResponse::success($this->calendar->feed($request->user(), $data['from'], $data['to'], $this->zone($request), $data['types'] ?? null), __('api.retrieved'));
    }

    /** GET calendar/today — DORR Today (202) with "around my interests" (207). */
    public function today(Request $request)
    {
        $request->validate(['timezone' => ['nullable', 'timezone:all']]);

        return ApiResponse::success($this->calendar->today($request->user(), $this->zone($request)), __('api.retrieved'));
    }

    /** GET calendar/search?q= (206). */
    public function search(Request $request)
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100'], 'timezone' => ['nullable', 'timezone:all']]);

        return ApiResponse::success($this->calendar->search($request->user(), $data['q'], $this->zone($request)), __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, false);
        $result = $this->calendar->create($request->user(), $data);
        $row = $this->calendar->presentItem($result['item'], $this->zone($request)) + ['duplicate' => $result['duplicate']];

        return $result['duplicate'] ? ApiResponse::success($row, __('api.retrieved')) : ApiResponse::created($row, __('api.created'));
    }

    public function show(Request $request, ChatCalendarItem $item)
    {
        $this->calendar->assertMine($request->user(), $item);

        return ApiResponse::success($this->calendar->presentItem($item->load(['message', 'conversation']), $this->zone($request)), __('api.retrieved'));
    }

    public function update(Request $request, ChatCalendarItem $item)
    {
        $item = $this->calendar->update($request->user(), $item, $this->validated($request, true));

        return ApiResponse::success($this->calendar->presentItem($item->load(['message', 'conversation']), $this->zone($request)), __('api.updated'));
    }

    public function destroy(Request $request, ChatCalendarItem $item)
    {
        $this->calendar->assertMine($request->user(), $item);
        $item->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    public function preferences(Request $request)
    {
        return ApiResponse::success($this->calendar->presentPreferences($this->calendar->preferences($request->user())), __('api.retrieved'));
    }

    public function savePreferences(Request $request)
    {
        $data = $request->validate([
            'sources' => ['sometimes', 'array'],
            'sources.*' => ['boolean'],
            'default_reminders' => ['sometimes', 'array', 'max:5'],
            'default_reminders.*' => ['integer'],
            'all_day_reminders' => ['sometimes', 'array', 'max:5'],
            'all_day_reminders.*' => ['integer'],
            'respect_quiet' => ['sometimes', 'boolean'],
            'personalised' => ['sometimes', 'boolean'],
            'today_sections' => ['sometimes', 'array'],
            'today_sections.order' => ['sometimes', 'array'],
            'today_sections.hidden' => ['sometimes', 'array'],
        ]);

        return ApiResponse::success($this->calendar->presentPreferences($this->calendar->savePreferences($request->user(), $data)), __('api.updated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial): array
    {
        $sometimes = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$sometimes, 'string', 'max:160'],
            'all_day' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', 'timezone:all'],
            'location' => ['nullable', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:1000'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'reminders' => ['sometimes', 'array', 'max:5'],
            'reminders.*' => ['integer'],
            'message_id' => [$partial ? 'prohibited' : 'nullable', 'uuid'],
        ]);
    }

    private function zone(Request $request): string
    {
        return (string) ($request->input('timezone') ?: ($request->user()?->timezone ?? config('app.timezone', 'UTC')));
    }
}
