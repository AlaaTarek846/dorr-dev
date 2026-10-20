<?php

namespace Modules\Discover\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Modules\Discover\Models\DiscoverCity;

/** The cities events happen in — each with its country and time zone. */
class DiscoverCityController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('cities', [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update', 'status', 'reorder']],
            ['delete', ['destroy']],
            ['multiple-delete', ['deleteMultiple']],
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->validate(['country_id' => ['nullable', 'integer']]);
        $rows = DiscoverCity::query()->with(['translations', 'country.translations', 'region.translations'])
            ->when($filters['country_id'] ?? null, fn ($q, $id) => $q->where('country_id', $id))
            ->orderBy('country_id')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (DiscoverCity $c) => $this->present($c));

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    public function show(DiscoverCity $discoverCity)
    {
        return ApiResponse::success($this->present($discoverCity->load(['translations', 'country.translations', 'region.translations'])), __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $data = $this->withRegion($this->validated($request, true), null);
        $data['sort_order'] ??= (int) DiscoverCity::query()->where('country_id', $data['country_id'])->max('sort_order') + 1;

        return ApiResponse::created($this->present($this->save(new DiscoverCity, $data)), __('api.created'));
    }

    public function update(Request $request, DiscoverCity $discoverCity)
    {
        return ApiResponse::success($this->present($this->save($discoverCity, $this->withRegion($this->validated($request, false), $discoverCity))), __('api.updated'));
    }

    public function status(Request $request, DiscoverCity $discoverCity)
    {
        $discoverCity->update($request->validate(['status' => ['required', 'boolean']]));

        return ApiResponse::success($this->present($discoverCity->load(['translations', 'country.translations', 'region.translations'])), __('api.updated'));
    }

    /** PUT discover-cities/reorder — the ids in the new order become sort_order 1..n (the admin's drag-and-drop). */
    public function reorder(Request $request)
    {
        $data = $request->validate(['ordered_ids' => ['required', 'array', 'min:1'], 'ordered_ids.*' => ['integer', 'exists:discover_cities,id']]);
        DB::transaction(function () use ($data) {
            foreach (array_values($data['ordered_ids']) as $index => $id) {
                DiscoverCity::query()->whereKey($id)->update(['sort_order' => $index + 1]);
            }
        });

        return ApiResponse::success(null, __('api.updated'));
    }

    /** POST delete-multiple {ids[]} — deletes the ones that can go and skips the ones in use; says how many of each. */
    public function deleteMultiple(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']]);
        $deleted = 0;
        $skipped = 0;
        foreach (DiscoverCity::query()->whereIn('id', $data['ids'])->get() as $row) {
            if (\Modules\Discover\Models\DiscoverEvent::query()->where('city_id', $row->id)->exists()) {
                $skipped++;

                continue;
            }
            $row->delete();
            $deleted++;
        }

        return ApiResponse::success(['deleted' => $deleted, 'skipped' => $skipped], __('api.deleted'));
    }

    public function destroy(DiscoverCity $discoverCity)
    {
        if (\Modules\Discover\Models\DiscoverEvent::query()->where('city_id', $discoverCity->id)->exists()) {
            throw new \Modules\Discover\Exceptions\DiscoverException('in_use', 422);
        }
        $discoverCity->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'country_id' => [$required, 'integer', 'exists:countries,id'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
            'timezone' => [$required, 'timezone:all'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'translations' => [$required, 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'max:80'],
        ]);
    }

    /**
     * The governorate must belong to the city's country; changing the country without choosing a new one clears it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withRegion(array $data, ?DiscoverCity $current): array
    {
        $countryId = $data['country_id'] ?? $current?->country_id;

        if (! empty($data['region_id'])) {
            if (! \App\Models\Region::query()->whereKey($data['region_id'])->where('country_id', $countryId)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['region_id' => [__('validation.exists', ['attribute' => 'region'])]]);
            }
        } elseif ($current !== null && isset($data['country_id']) && (int) $data['country_id'] !== (int) $current->country_id && ! array_key_exists('region_id', $data)) {
            $data['region_id'] = null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(DiscoverCity $city, array $data): DiscoverCity
    {
        DB::transaction(function () use ($city, $data) {
            $city->fill(collect($data)->only(['country_id', 'region_id', 'timezone', 'lat', 'lng', 'status', 'sort_order'])->all())->save();
            foreach ($data['translations'] ?? [] as $row) {
                $city->translations()->updateOrCreate(['locale' => $row['locale']], ['name' => $row['name']]);
            }
        });

        return $city->load(['translations', 'country.translations', 'region.translations']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DiscoverCity $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->translatedName(), 'country_id' => $c->country_id, 'region_id' => $c->region_id, 'region' => $c->region?->translatedName(), 'country' => $c->country?->translatedName(), 'country_code' => $c->country?->code, 'country_is_default' => (bool) $c->country?->is_default,
            'timezone' => $c->timezone, 'lat' => $c->lat, 'lng' => $c->lng, 'status' => (bool) $c->status, 'sort_order' => (int) $c->sort_order,
            'created_at' => $c->created_at?->toIso8601String(), 'updated_at' => $c->updated_at?->toIso8601String(),
            'translations' => $c->translations->map(fn ($t) => ['locale' => $t->locale, 'name' => $t->name])->values()->all(),
        ];
    }
}
