<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatPrivacySetting;
use Modules\Chat\Services\AttentionPolicy;
use Modules\Chat\Services\ChatPrivacy;
use Modules\Chat\Support\ParticipantDirectory;

/**
 * My communication rules (spec 146, 151, 155), all in one place: what interrupts me (per source),
 * how often the summary comes, my privacy context now and what each context does, the people
 * who always get through, and the hours calls may ring. Notifications only — what anyone may
 * send me stays the backend's own rules (who can message / call / send urgent).
 */
class AttentionController extends Controller
{
    public function __construct(
        private readonly ChatPrivacy $privacy,
        private readonly ParticipantDirectory $directory,
    ) {}

    /** GET attention */
    public function show(Request $request)
    {
        return ApiResponse::success($this->present($request->user(), $this->privacy->settingsFor($request->user())), __('api.retrieved'));
    }

    /**
     * PUT attention — `{attention?{source: now|digest|later}, digest_minutes?, always_through?[keys],
     * call_hours?{from, to, days[], timezone}|null, contexts?{key: {attention?, disclosure?, calls?}}}`
     */
    public function update(Request $request)
    {
        $modes = AttentionPolicy::MODES;
        $data = $request->validate([
            'attention' => ['sometimes', 'array'],
            'attention.*' => [Rule::in($modes)],
            'digest_minutes' => ['sometimes', 'integer', Rule::in([15, 30, 60, 120, 240])],
            'always_through' => ['sometimes', 'array', 'max:50'],
            'always_through.*' => ['string', 'regex:/^[a-z_]+:\d+$/'],
            'call_hours' => ['sometimes', 'nullable', 'array'],
            'call_hours.from' => ['required_with:call_hours', 'date_format:H:i'],
            'call_hours.to' => ['required_with:call_hours', 'date_format:H:i'],
            'call_hours.days' => ['nullable', 'array'],
            'call_hours.days.*' => ['integer', 'between:0,6'],
            'call_hours.timezone' => ['nullable', 'timezone:all'],
            'contexts' => ['sometimes', 'array'],
            'contexts.*.attention' => ['sometimes', 'array'],
            'contexts.*.attention.*' => [Rule::in($modes)],
            'contexts.*.disclosure' => ['sometimes', 'nullable', Rule::in(['all', 'name', 'none'])],
            'contexts.*.calls' => ['sometimes', Rule::in(['everyone', 'through', 'nobody'])],
        ]);
        $me = $request->user();
        $s = $this->privacy->settingsFor($me);
        $changes = [];
        if (array_key_exists('attention', $data)) {
            $changes['attention'] = array_intersect_key((array) $data['attention'], array_flip(AttentionPolicy::SOURCES));
        }
        if (isset($data['digest_minutes'])) {
            $changes['digest_minutes'] = (int) $data['digest_minutes'];
        }
        // An empty list clears it (validated() drops an empty array, so read the request itself).
        if ($request->has('always_through')) {
            $changes['always_through'] = array_values(array_unique((array) ($data['always_through'] ?? $request->input('always_through', []))));
        }
        if (array_key_exists('call_hours', $data)) {
            $changes['call_hours'] = $data['call_hours'] === null ? null : [
                'from' => $data['call_hours']['from'], 'to' => $data['call_hours']['to'],
                'days' => array_values(array_map('intval', $data['call_hours']['days'] ?? [0, 1, 2, 3, 4, 5, 6])),
                'timezone' => $data['call_hours']['timezone'] ?? ($me->timezone ?? config('app.timezone', 'UTC')),
            ];
        }
        if (array_key_exists('contexts', $data)) {
            $changes['contexts'] = array_intersect_key(array_merge((array) $s->contexts, (array) $data['contexts']), array_flip(AttentionPolicy::CONTEXTS));
        }
        $s->update($changes);

        return ApiResponse::success($this->present($me, $s->refresh()), __('api.updated'));
    }

    /** PUT attention/context — `{context: home|work|meeting|travel|private|null, minutes?}` (no minutes = until I change it). */
    public function context(Request $request)
    {
        $data = $request->validate(['context' => ['nullable', Rule::in(AttentionPolicy::CONTEXTS)], 'minutes' => ['nullable', 'integer', 'min:15', 'max:10080']]);
        $me = $request->user();
        $s = $this->privacy->settingsFor($me);
        $s->update(['context' => $data['context'] ?? null, 'context_until' => ! empty($data['context']) && ! empty($data['minutes']) ? now()->addMinutes((int) $data['minutes']) : null]);

        return ApiResponse::success($this->present($me, $s->refresh()), __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function present($me, ChatPrivacySetting $s): array
    {
        $through = collect((array) ($s->always_through ?? []))->map(function (string $key) use ($me) {
            [$type, $id] = explode(':', $key, 2);
            $p = $this->directory->profile($me, $type, (int) $id);

            return ['key' => $key, 'name' => $p['name'] ?? null, 'avatar' => $p['avatar'] ?? null];
        })->values()->all();
        $active = $s->activeContext();

        return [
            'attention' => array_merge(AttentionPolicy::DEFAULT, array_intersect_key((array) ($s->attention ?? []), array_flip(AttentionPolicy::SOURCES))),
            'digest_minutes' => (int) ($s->digest_minutes ?: 60),
            'context' => $active === null ? null : ['key' => $active, 'until' => $s->context_until?->toIso8601String()],
            'contexts' => collect(AttentionPolicy::CONTEXTS)->map(fn ($k) => ['key' => $k] + AttentionPolicy::context($s, $k) + ['custom' => isset(((array) $s->contexts)[$k])])->values()->all(),
            'always_through' => $through,
            'call_hours' => $s->call_hours,
            'who_can_call' => $s->who_can_call?->value,
            'who_can_urgent' => $s->who_can_urgent?->value,
            'quiet' => ['schedule' => $s->quiet_schedule, 'scope' => $s->quiet_scope ?: 'all', 'on' => $s->quietOn()],
            'sources' => AttentionPolicy::SOURCES,
        ];
    }
}
