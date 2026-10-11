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
        return AdminPermissionMiddleware::fromActionMethodMap('discover-cities', [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update', 'status']],
            ['delete', ['destroy']],
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->validate(['country_id' => ['nullable', 'integer']]);
        $rows = DiscoverCity::query()->with(['translations', 'country.translations'])
            ->when($filters['country_id'] ?? null, fn ($q, $id) => $q->where('country_id', $id))
            ->orderBy('country_id')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (DiscoverCity $c) => $this->present($c));

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    public function show(DiscoverCity $discoverCity)
    {
        return ApiResponse::success($this->present($discoverCity->load(['translations', 'country.translations'])), __('api.retrieved'));
    }

    public function store(Request $request)
    {
        return ApiResponse::created($this->present($this->save(new DiscoverCity, $this->validated($request, true))), __('api.created'));
    }

    public function update(Request $request, DiscoverCity $discoverCity)
    {
        return ApiResponse::success($this->present($this->save($discoverCity, $this->validated($request, false))), __('api.updated'));
    }

    public function status(Request $request, DiscoverCity $discoverCity)
    {
        $discoverCity->update($request->validate(['status' => ['required', 'boolean']]));

        return ApiResponse::success($this->present($discoverCity->load(['translations', 'country.translations'])), __('api.updated'));
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
     * @param  array<string, mixed>  $data
     */
    private function save(DiscoverCity $city, array $data): DiscoverCity
    {
        DB::transaction(function () use ($city, $data) {
            $city->fill(collect($data)->only(['country_id', 'timezone', 'lat', 'lng', 'status', 'sort_order'])->all())->save();
            foreach ($data['translations'] ?? [] as $row) {
                $city->translations()->updateOrCreate(['locale' => $row['locale']], ['name' => $row['name']]);
            }
        });

        return $city->load(['translations', 'country.translations']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DiscoverCity $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->translatedName(), 'country_id' => $c->country_id, 'country' => $c->country?->translatedName(), 'country_code' => $c->country?->code,
            'timezone' => $c->timezone, 'lat' => $c->lat, 'lng' => $c->lng, 'status' => (bool) $c->status, 'sort_order' => (int) $c->sort_order,
            'translations' => $c->translations->map(fn ($t) => ['locale' => $t->locale, 'name' => $t->name])->values()->all(),
        ];
    }
}
