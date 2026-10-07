<?php

namespace Modules\Chat\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatMoment;
use Modules\Chat\Models\ChatMomentDate;
use Modules\Chat\Services\MomentService;

/**
 * The occasions catalog (DORR Moments, spec 157–159): names per language, countries, the date rule,
 * how long it shows, its look (colours, emoji, animation, a card picture), and the date
 * corrections per year and country (Umm al-Qura is computed; the admin corrects it when a country's
 * sighting differs).
 */
class ChatMomentController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MomentService $moments) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('chat-moments', [
            ['view', ['index', 'show', 'dates']],
            ['create', ['store']],
            ['update', ['update', 'status', 'setDate']],
            ['delete', ['destroy']],
        ]);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['kind' => ['nullable', Rule::in(ChatMoment::KINDS)], 'search' => ['nullable', 'string', 'max:100']]);
        $rows = ChatMoment::query()->with(['translations', 'media'])
            ->when($data['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('key', 'like', "%{$s}%")->orWhereHas('translations', fn ($t) => $t->where('name', 'like', "%{$s}%"))))
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (ChatMoment $m) => $this->present($m));

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    public function show(ChatMoment $chatMoment)
    {
        return ApiResponse::success($this->present($chatMoment->load(['translations', 'media'])), __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $moment = $this->save(new ChatMoment, $this->validated($request, null), $request);

        return ApiResponse::created($this->present($moment), __('api.created'));
    }

    /** POST (multipart: the card picture can change). */
    public function update(Request $request, ChatMoment $chatMoment)
    {
        return ApiResponse::success($this->present($this->save($chatMoment, $this->validated($request, $chatMoment), $request)), __('api.updated'));
    }

    public function status(Request $request, ChatMoment $chatMoment)
    {
        $chatMoment->update($request->validate(['status' => ['required', 'boolean']]));

        return ApiResponse::success($this->present($chatMoment->load(['translations', 'media'])), __('api.updated'));
    }

    public function destroy(ChatMoment $chatMoment)
    {
        $chatMoment->clearMediaCollection(ChatMoment::CARD);
        $chatMoment->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    /**
     * GET chat-moments/{id}/dates?year= — this year's and the next two years' dates: computed, and
     * the corrections (everywhere / per country).
     */
    public function dates(Request $request, ChatMoment $chatMoment)
    {
        $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);
        $year = $request->integer('year') ?: now()->year;
        $countries = Country::query()->where('status', true)->get()->filter(fn ($c) => $chatMoment->isIn($c->code))->values();
        $corrections = ChatMomentDate::query()->where('chat_moment_id', $chatMoment->id)->whereBetween('year', [$year, $year + 2])->get();

        $years = collect(range($year, $year + 2))->map(fn (int $y) => [
            'year' => $y,
            // What the rule gives (before any correction), and what everyone actually sees.
            'computed' => collect($chatMoment->date_rule === 'hijri' ? $this->moments->hijriDatesIn($chatMoment->month, $chatMoment->day, $y)
                : ($chatMoment->date_rule === 'gregorian' && $chatMoment->month && $chatMoment->day && checkdate($chatMoment->month, $chatMoment->day, $y) ? [CarbonImmutable::create($y, $chatMoment->month, $chatMoment->day)] : []))
                ->map->toDateString()->values()->all(),
            'everywhere' => $corrections->first(fn ($c) => $c->year === $y && $c->country_id === null)?->date?->toDateString(),
            'countries' => $countries->map(fn ($c) => [
                'country_id' => $c->id,
                'code' => $c->code,
                'name' => $c->translatedName(),
                'correction' => $corrections->first(fn ($r) => $r->year === $y && $r->country_id === $c->id)?->date?->toDateString(),
                'effective' => collect($this->moments->datesIn($chatMoment, $c, $y))->map->toDateString()->values()->all(),
            ])->all(),
        ])->all();

        return ApiResponse::success(['moment' => $this->present($chatMoment->load(['translations', 'media'])), 'years' => $years], __('api.retrieved'));
    }

    /** PUT chat-moments/{id}/dates — `{year, country_id|null, date|null}` (null date removes the correction). */
    public function setDate(Request $request, ChatMoment $chatMoment)
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'date' => ['present', 'nullable', 'date'],
        ]);
        $keys = ['chat_moment_id' => $chatMoment->id, 'country_id' => $data['country_id'] ?? null, 'year' => $data['year']];

        if ($data['date'] === null) {
            ChatMomentDate::query()->where($keys)->delete();
        } else {
            ChatMomentDate::query()->updateOrCreate($keys, ['date' => CarbonImmutable::parse($data['date'])->toDateString()]);
        }
        $chatMoment->touch(); // the app's cache key moves

        return $this->dates($request->merge(['year' => $data['year']]), $chatMoment);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?ChatMoment $moment): array
    {
        $rule = $request->input('date_rule', $moment?->date_rule);

        return $request->validate([
            'key' => [$moment ? 'sometimes' : 'nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('chat_moments', 'key')->ignore($moment?->id)],
            'kind' => [$moment ? 'sometimes' : 'required', Rule::in(ChatMoment::KINDS)],
            'date_rule' => [$moment ? 'sometimes' : 'required', Rule::in(ChatMoment::RULES)],
            'month' => [$rule === 'manual' ? 'nullable' : 'required_with:date_rule', 'nullable', 'integer', 'between:1,12'],
            'day' => [$rule === 'manual' ? 'nullable' : 'required_with:date_rule', 'nullable', 'integer', 'between:1,31'],
            'duration_days' => ['sometimes', 'integer', 'between:1,60'],
            'show_before_days' => ['sometimes', 'integer', 'between:0,60'],
            'show_after_days' => ['sometimes', 'integer', 'between:0,30'],
            'countries' => ['sometimes', 'nullable', 'array'],
            'countries.*' => ['string', 'size:2'],
            'default_on' => ['sometimes', 'boolean'],
            'theme' => ['sometimes', 'string', 'max:30'],
            'primary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/'],
            'secondary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/'],
            'emoji' => ['sometimes', 'nullable', 'string', 'max:16'],
            'animation' => ['sometimes', 'nullable', Rule::in(ChatMoment::ANIMATIONS)],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'translations' => [$moment ? 'sometimes' : 'required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'max:120'],
            'translations.*.greeting' => ['nullable', 'string', 'max:300'],
            'card' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
            'remove_card' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(ChatMoment $moment, array $data, Request $request): ChatMoment
    {
        DB::transaction(function () use ($moment, $data, $request) {
            $fields = collect($data)->except(['translations', 'card', 'remove_card'])->all();
            if (isset($fields['countries'])) {
                $fields['countries'] = array_values(array_unique(array_map('strtoupper', $fields['countries']))) ?: null;
            }
            if (! $moment->exists && empty($fields['key'])) {
                $fields['key'] = Str::slug($data['translations'][0]['name'] ?? 'moment', '_').'_'.Str::lower(Str::random(4));
            }
            $moment->fill($fields)->save();

            foreach ($data['translations'] ?? [] as $row) {
                $moment->translations()->updateOrCreate(['locale' => $row['locale']], ['name' => $row['name'], 'greeting' => $row['greeting'] ?? null]);
            }
            if (! empty($data['remove_card'])) {
                $moment->clearMediaCollection(ChatMoment::CARD);
            }
            if ($request->hasFile('card')) {
                $moment->setSingleMedia(ChatMoment::CARD, $request->file('card'));
            }
        });

        return $moment->refresh()->load(['translations', 'media']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ChatMoment $m): array
    {
        $next = $this->moments->occurrence($m, null);

        return [
            'id' => $m->id,
            'key' => $m->key,
            'name' => $m->translatedName(),
            'kind' => $m->kind,
            'date_rule' => $m->date_rule,
            'month' => $m->month,
            'day' => $m->day,
            'duration_days' => $m->duration_days,
            'show_before_days' => $m->show_before_days,
            'show_after_days' => $m->show_after_days,
            'countries' => $m->countries ?? [],
            'default_on' => $m->default_on,
            'theme' => $m->theme,
            'primary_color' => $m->primary_color,
            'secondary_color' => $m->secondary_color,
            'emoji' => $m->emoji,
            'animation' => $m->animation,
            'card_image' => $m->cardUrl(),
            'status' => $m->status,
            'sort_order' => $m->sort_order,
            'next' => $next ? $next['start']->toDateString() : null,
            'translations' => $m->translations->map(fn ($t) => ['locale' => $t->locale, 'name' => $t->name, 'greeting' => $t->greeting])->values(),
        ];
    }
}
