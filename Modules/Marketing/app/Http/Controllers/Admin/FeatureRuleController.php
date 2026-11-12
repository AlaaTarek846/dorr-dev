<?php

namespace Modules\Marketing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Validation\Rule;
use Modules\Marketing\Models\MarketingFeatureRule;
use Modules\Marketing\Services\Features\MarketingFeatures;

/** The marketing switches per country (D4). No rule means off. */
class FeatureRuleController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('marketing-feature-rules', [
            ['view', ['options', 'index']],
            ['update', ['store', 'copy']],
        ]);
    }

    /** GET marketing/feature-rules/options */
    public function options()
    {
        return ApiResponse::success([
            'features' => MarketingFeatures::all(),
            'countries' => Country::query()->where('status', true)->with('translations')->orderBy('id')->get()
                ->map(fn (Country $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code])->values()->all(),
        ], __('api.retrieved'));
    }

    /** GET marketing/feature-rules?country_id= */
    public function index(Request $request)
    {
        $data = $request->validate(['country_id' => ['required', 'integer', 'exists:countries,id']]);
        $rules = MarketingFeatureRule::query()->where('country_id', $data['country_id'])->get()->keyBy('feature');

        return ApiResponse::success(collect(MarketingFeatures::all())->map(fn (string $f) => [
            'feature' => $f,
            'enabled' => (bool) ($rules[$f]->enabled ?? false),
            'note' => $rules[$f]->note ?? null,
        ])->values()->all(), __('api.retrieved'));
    }

    /** POST marketing/feature-rules  {feature, country_id, enabled, note?} */
    public function store(Request $request)
    {
        $data = $request->validate([
            'feature' => ['required', Rule::in(MarketingFeatures::all())],
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'enabled' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $rule = MarketingFeatureRule::query()->updateOrCreate(
            ['feature' => $data['feature'], 'country_id' => $data['country_id']],
            ['enabled' => $data['enabled'], 'note' => $data['note'] ?? null],
        );

        return ApiResponse::success(['feature' => $rule->feature, 'enabled' => $rule->enabled, 'note' => $rule->note], __('api.updated'));
    }

    /** POST marketing/feature-rules/copy  {from_country_id, to_country_id} */
    public function copy(Request $request)
    {
        $data = $request->validate([
            'from_country_id' => ['required', 'integer', 'exists:countries,id'],
            'to_country_id' => ['required', 'integer', 'exists:countries,id', 'different:from_country_id'],
        ]);
        foreach (MarketingFeatureRule::query()->where('country_id', $data['from_country_id'])->get() as $rule) {
            MarketingFeatureRule::query()->updateOrCreate(
                ['feature' => $rule->feature, 'country_id' => $data['to_country_id']],
                ['enabled' => $rule->enabled, 'note' => $rule->note],
            );
        }

        return ApiResponse::success(null, __('api.updated'));
    }
}
