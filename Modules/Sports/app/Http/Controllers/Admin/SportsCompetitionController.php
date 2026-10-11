<?php

namespace Modules\Sports\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Sports\Exceptions\SportsException;
use Modules\Sports\Models\SportsCompetition;
use Modules\Sports\Models\SportsSport;
use Modules\Sports\Services\SportsEngine;
use Modules\Sports\Services\SportsGovernor;
use Throwable;

/**
 * Competitions from the provider (184): import a sport's current ones, then give each a tier
 * (big = every minute in a match, normal = 3 min, minor = rarely, off = not followed), a scope and
 * a name per language.
 */
class SportsCompetitionController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('sports-competitions', [
            ['view', ['index']],
            ['update', ['update', 'bulk']],
            ['create', ['import']],
        ]);
    }

    public function index(Request $request)
    {
        $f = $request->validate([
            'sport' => ['nullable', 'string', 'max:30'],
            'tier' => ['nullable', Rule::in([...SportsCompetition::TIERS, 'active'])],
            'scope' => ['nullable', Rule::in(SportsCompetition::SCOPES)],
            'country' => ['nullable', 'string', 'max:80'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $page = SportsCompetition::query()->with(['translations', 'sport'])->withCount('matches')
            ->when($f['sport'] ?? null, fn ($q, $k) => $q->whereHas('sport', fn ($s) => $s->where('key', $k)))
            ->when(($f['tier'] ?? null) === 'active', fn ($q) => $q->active())
            ->when(in_array($f['tier'] ?? null, SportsCompetition::TIERS, true), fn ($q) => $q->where('tier', $f['tier']))
            ->when($f['scope'] ?? null, fn ($q, $v) => $q->where('scope', $v))
            ->when($f['country'] ?? null, fn ($q, $v) => $q->where('country_name', $v))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('country_name', 'like', "%{$s}%")))
            ->orderByRaw("case when tier = 'off' then 1 else 0 end")->orderByDesc('priority')->orderBy('country_name')->orderBy('name')
            ->paginate((int) ($f['per_page'] ?? 30));

        return ApiResponse::success($page->getCollection()->map(fn ($c) => $this->present($c))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page), [
            'counts' => SportsCompetition::query()->select('tier', DB::raw('count(*) as n'))->groupBy('tier')->pluck('n', 'tier'),
            'countries' => SportsCompetition::query()->whereNotNull('country_name')->distinct()->orderBy('country_name')->pluck('country_name'),
        ]);
    }

    /** PATCH {tier?, scope?, priority?, translations?[{locale, name}]} */
    public function update(Request $request, SportsCompetition $sportsCompetition, SportsGovernor $governor)
    {
        $data = $request->validate([
            'tier' => ['sometimes', Rule::in(SportsCompetition::TIERS)],
            'scope' => ['sometimes', 'nullable', Rule::in(SportsCompetition::SCOPES)],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'translations' => ['sometimes', 'array'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['nullable', 'string', 'max:160'],
        ]);
        DB::transaction(function () use ($sportsCompetition, $data) {
            $sportsCompetition->update(collect($data)->only(['tier', 'scope', 'priority'])->all());
            foreach ($data['translations'] ?? [] as $t) {
                if (blank($t['name'] ?? null)) {
                    $sportsCompetition->translations()->where('locale', $t['locale'])->delete();
                } else {
                    $sportsCompetition->translations()->updateOrCreate(['locale' => $t['locale']], ['name' => $t['name']]);
                }
            }
        });
        $governor->forget();

        return ApiResponse::success($this->present($sportsCompetition->refresh()->load(['translations', 'sport'])->loadCount('matches')), __('api.updated'));
    }

    /** PATCH bulk {ids[], tier} */
    public function bulk(Request $request, SportsGovernor $governor)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer'], 'tier' => ['required', Rule::in(SportsCompetition::TIERS)]]);
        $n = SportsCompetition::query()->whereIn('id', $data['ids'])->update(['tier' => $data['tier']]);
        $governor->forget();

        return ApiResponse::success(['updated' => $n], __('api.updated'));
    }

    /** POST import {sport} — the sport's current competitions (one provider request). */
    public function import(Request $request, SportsEngine $engine)
    {
        $data = $request->validate(['sport' => ['required', 'string', 'exists:sports_sports,key'], 'suggest' => ['sometimes', 'boolean']]);
        try {
            $result = $engine->importCompetitions(SportsSport::query()->where('key', $data['sport'])->firstOrFail(), (bool) ($data['suggest'] ?? true));
        } catch (Throwable $e) {
            throw new SportsException('provider', 502, [], ['detail' => $e->getMessage()]);
        }

        return ApiResponse::success($result, __('api.updated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SportsCompetition $c): array
    {
        return [
            'id' => $c->id, 'sport' => $c->sport?->key, 'provider_id' => $c->provider_id, 'name' => $c->name, 'display_name' => $c->displayName(),
            'type' => $c->type, 'scope' => $c->scope, 'country' => $c->country_name, 'country_code' => $c->country_code, 'logo' => $c->logo, 'flag' => $c->flag,
            'season' => $c->season, 'tier' => $c->tier, 'priority' => (int) $c->priority, 'coverage' => $c->coverage, 'matches_count' => (int) ($c->matches_count ?? 0),
            'standings_synced_at' => $c->standings_synced_at?->toIso8601String(),
            'translations' => $c->translations->map(fn ($t) => ['locale' => $t->locale, 'name' => $t->name])->values()->all(),
        ];
    }
}
