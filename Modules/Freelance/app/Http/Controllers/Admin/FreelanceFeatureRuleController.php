<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Features\FreelanceFeaturesGuide;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin → Freelance → Switches: the service and each feature on or off per country.
 * No rule = off; nothing works while `freelance` itself is off there.
 */
class FreelanceFeatureRuleController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-feature-rules', [
            ['view', ['index', 'options', 'guide']],
            ['update', ['store', 'copy']],
        ]);
    }

    public function options(): JsonResponse
    {
        $countries = Country::query()->with('translations')->where('status', true)->get()
            ->map(fn (Country $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code])->values();

        return ApiResponse::success(['features' => FreelanceFeatures::all(), 'countries' => $countries], __('api.retrieved'));
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['country_id' => ['nullable', 'integer']]);
        $rows = FreelanceFeatureRule::query()
            ->when($request->filled('country_id'), fn ($q) => $q->where('country_id', $request->integer('country_id')))
            ->orderBy('feature')->get()
            ->map(fn (FreelanceFeatureRule $r) => $this->present($r))
            ->values();

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    /** The Word file that explains every switch (Arabic), with the chosen country's state when one is given. */
    public function guide(Request $request, FreelanceFeaturesGuide $guide, FreelanceFeatures $features): BinaryFileResponse
    {
        $data = $request->validate(['country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')]]);
        $country = isset($data['country_id']) ? Country::query()->with('translations')->find($data['country_id']) : null;
        $state = $country ? collect(FreelanceFeatures::all())->mapWithKeys(fn (string $f) => [$f => $features->allows($country->id, $f)])->all() : null;

        $path = $guide->build($country ? ($country->translatedName() ?? $country->code) : null, $state);

        return response()->download($path, 'freelance-feature-switches.docx')->deleteFileAfterSend(true);
    }

    /** Turns one feature on or off for a country (creates the rule the first time). */
    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'feature' => ['required', 'string', Rule::in(FreelanceFeatures::all())],
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
            'enabled' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $rule = FreelanceFeatureRule::query()->updateOrCreate(
            ['feature' => $data['feature'], 'country_id' => $data['country_id']],
            ['enabled' => (bool) $data['enabled'], 'note' => $data['note'] ?? null],
        );
        $audit->record('freelance_feature_rule.saved', $rule, null, $data);

        return ApiResponse::success($this->present($rule), __('api.updated'));
    }

    /** Copies every switch of one country onto another (a new country starts like an existing one). */
    public function copy(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'from_country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
            'to_country_id' => ['required', 'integer', 'different:from_country_id', Rule::exists('countries', 'id')],
        ]);
        $count = 0;
        foreach (FreelanceFeatureRule::query()->where('country_id', $data['from_country_id'])->get() as $source) {
            FreelanceFeatureRule::query()->updateOrCreate(
                ['feature' => $source->feature, 'country_id' => $data['to_country_id']],
                ['enabled' => $source->enabled, 'note' => $source->note],
            );
            $count++;
        }
        $audit->record('freelance_feature_rule.copied', null, null, $data);

        return ApiResponse::success(['copied' => $count], __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function present(FreelanceFeatureRule $r): array
    {
        return ['id' => $r->id, 'feature' => $r->feature, 'country_id' => $r->country_id, 'enabled' => (bool) $r->enabled, 'note' => $r->note];
    }
}
