<?php

namespace Modules\Chat\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Chat\Models\ChatFeatureRule;

/**
 * Chat features on or off for a country or one of its cities (part of the chat settings): a
 * city's rule beats its country's, and no rule means on.
 */
class ChatFeatureRuleController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('chat-settings', [
            ['view', ['index', 'options']],
            ['update', ['store', 'update', 'destroy']],
        ]);
    }

    public function index()
    {
        $cities = $this->cities()->keyBy('id');
        $rules = ChatFeatureRule::query()->with('country.translations')->orderBy('feature')->orderBy('country_id')->orderByRaw('city_id is not null')->get()
            ->map(fn (ChatFeatureRule $rule) => $this->present($rule, $cities))->values();

        return ApiResponse::success($rules, __('api.retrieved'));
    }

    /** The features, countries and (Discover's) cities to pick from. */
    public function options()
    {
        return ApiResponse::success([
            'features' => ChatFeatureRule::FEATURES,
            'countries' => Country::query()->with('translations')->where('status', true)->get()->map(fn (Country $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code])->values(),
            'cities' => $this->cities()->values(),
        ], __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $rule = ChatFeatureRule::query()->updateOrCreate(
            ['feature' => $data['feature'], 'country_id' => $data['country_id'], 'city_id' => $data['city_id'] ?? null],
            ['enabled' => (bool) $data['enabled'], 'note' => $data['note'] ?? null],
        );

        return ApiResponse::success($this->present($rule->load('country.translations'), $this->cities()->keyBy('id')), __('api.created'), 201);
    }

    public function update(Request $request, ChatFeatureRule $chatFeatureRule)
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:255']]);
        $chatFeatureRule->update($data);

        return ApiResponse::success($this->present($chatFeatureRule->load('country.translations'), $this->cities()->keyBy('id')), __('api.updated'));
    }

    public function destroy(ChatFeatureRule $chatFeatureRule)
    {
        $chatFeatureRule->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'feature' => ['required', Rule::in(ChatFeatureRule::FEATURES)],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'city_id' => ['nullable', 'integer'],
            'enabled' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        // The city must be one of this country's cities.
        if (! empty($data['city_id'])) {
            $city = $this->cities()->firstWhere('id', (int) $data['city_id']);
            if ($city === null || $city['country_id'] !== (int) $data['country_id']) {
                throw ValidationException::withMessages(['city_id' => __('validation.exists', ['attribute' => 'city'])]);
            }
        }

        return $data;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $cities
     * @return array<string, mixed>
     */
    private function present(ChatFeatureRule $rule, $cities): array
    {
        return [
            'id' => $rule->id,
            'feature' => $rule->feature,
            'country' => $rule->country ? ['id' => $rule->country->id, 'code' => $rule->country->code, 'name' => $rule->country->translatedName() ?? $rule->country->code] : null,
            'city' => $rule->city_id ? ($cities->get($rule->city_id) ?? ['id' => $rule->city_id, 'name' => null]) : null,
            'enabled' => $rule->enabled,
            'note' => $rule->note,
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ];
    }

    /** @return Collection<int, array{id: int, country_id: int, name: ?string}> */
    private function cities()
    {
        $class = 'Modules\\Discover\\Models\\DiscoverCity';
        if (! class_exists($class)) {
            return collect();
        }

        return $class::query()->with('translations')->orderBy('sort_order')->get()
            ->map(fn ($c) => ['id' => (int) $c->id, 'country_id' => (int) $c->country_id, 'name' => $c->translatedName()]);
    }
}
