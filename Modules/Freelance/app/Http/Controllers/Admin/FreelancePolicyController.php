<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelancePolicy;
use Modules\Freelance\Services\Policy\FreelanceFees;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Policy\FreelancePoliciesGuide;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Modules\Freelance\Services\Policy\FreelancePolicyData;

/**
 * Admin → Freelance → Prices & rules (FL-008): one scope at a time (a country, or a country + specialty) —
 * what it inherits, the keys it changes, a live preview of the money on an amount, and copying a
 * country's numbers onto another. Money travels in the minor unit of the country's own currency.
 */
class FreelancePolicyController extends Controller implements HasMiddleware
{
    public function __construct(private readonly FreelancePolicies $policies) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-policies', [
            ['view', ['options', 'scopes', 'show', 'preview', 'guide']],
            ['update', ['update', 'copy']],
        ]);
    }

    public function options(): JsonResponse
    {
        $countries = Country::query()->with(['translations', 'currency'])->where('status', true)->get()
            ->map(fn (Country $c) => [
                'id' => $c->id,
                'code' => $c->code,
                'name' => $c->translatedName() ?? $c->code,
                'currency' => $this->currency($c),
            ])->values();

        return ApiResponse::success([
            'countries' => $countries,
            'specialties' => \Modules\Freelance\Models\FreelanceSpecialty::query()->with(['translations', 'parent.translations'])->where('status', true)->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => trim(($s->parent ? $s->parent->translatedName().' › ' : '').$s->translatedName())])->values(),
            'keys' => FreelancePolicyData::KEYS,
            'defaults' => FreelancePolicyData::defaults(),
        ], __('api.retrieved'));
    }

    /** Every scope that has its own numbers, to jump to. */
    public function scopes(): JsonResponse
    {
        $rows = FreelancePolicy::query()->orderBy('country_id')->orderBy('specialty_id')->get()
            ->map(fn (FreelancePolicy $p) => ['id' => $p->id, 'country_id' => $p->country_id, 'specialty_id' => $p->specialty_id, 'keys' => array_keys((array) $p->data)])->values();

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    public function show(Request $request): JsonResponse
    {
        ['country_id' => $countryId, 'specialty_id' => $specialtyId] = $this->scope($request);

        return ApiResponse::success($this->present($countryId, $specialtyId), __('api.retrieved'));
    }

    public function update(Request $request): JsonResponse
    {
        ['country_id' => $countryId, 'specialty_id' => $specialtyId] = $this->scope($request);
        $data = $request->validate(['data' => ['present', 'array']])['data'];

        $this->policies->save($countryId, $specialtyId, $data, $request->user('admin_api')?->getKey());

        return ApiResponse::success($this->present($countryId, $specialtyId), __('api.updated'));
    }

    /**
     * The money on an amount with the numbers as they stand in the form (not saved yet): what the
     * client pays, the commission and what the freelancer keeps.
     */
    public function preview(Request $request): JsonResponse
    {
        ['country_id' => $countryId, 'specialty_id' => $specialtyId] = $this->scope($request);
        $input = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'data' => ['nullable', 'array'],
        ]);
        $data = $input['data'] ?? [];
        FreelancePolicyData::validate($data);

        $own = $this->policies->own($countryId, $specialtyId);
        $merged = FreelancePolicyData::merge([$this->policies->for($countryId, $specialtyId, $own?->id)->all(), $data]);
        $merged->assertConsistent();

        return ApiResponse::success(
            FreelanceFees::breakdown((int) $input['amount_minor'], $merged) + ['currency' => $this->currency(Country::query()->with('currency')->find($countryId))],
            __('api.retrieved'),
        );
    }

    /** The Word file that explains the page (Arabic), with the chosen country's numbers and a worked example. */
    public function guide(Request $request, FreelancePoliciesGuide $guide): BinaryFileResponse
    {
        $data = $request->validate(['country_id' => ['required', 'integer', Rule::exists('countries', 'id')]]);
        $path = $guide->build(Country::query()->with(['translations', 'currency'])->findOrFail($data['country_id']));

        return response()->download($path, 'freelance-prices-and-rules.docx')->deleteFileAfterSend(true);
    }

    public function copy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
            'to_country_id' => ['required', 'integer', 'different:from_country_id', Rule::exists('countries', 'id')],
        ]);

        return ApiResponse::success(
            $this->policies->copy((int) $data['from_country_id'], (int) $data['to_country_id'], $request->user('admin_api')?->getKey()),
            __('api.updated'),
        );
    }

    /** @return array{country_id: int, specialty_id: ?int} */
    private function scope(Request $request): array
    {
        $data = $request->validate([
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
            'specialty_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return ['country_id' => (int) $data['country_id'], 'specialty_id' => isset($data['specialty_id']) ? (int) $data['specialty_id'] : null];
    }

    /** @return array<string, mixed> */
    private function present(int $countryId, ?int $specialtyId): array
    {
        $own = $this->policies->own($countryId, $specialtyId);
        $inherited = $this->policies->for($countryId, $specialtyId, $own?->id);

        return [
            'country_id' => $countryId,
            'specialty_id' => $specialtyId,
            'currency' => $this->currency(Country::query()->with('currency')->find($countryId)),
            // What this scope gets from the broader ones (or the defaults), and the keys it sets itself.
            'inherited' => $inherited->all(),
            'own' => (object) ((array) ($own?->data ?? [])),
            'effective' => $this->policies->for($countryId, $specialtyId)->all(),
        ];
    }

    /** @return array{code: ?string, symbol: ?string, decimal_places: int} */
    private function currency(?Country $country): array
    {
        $currency = $country?->currency;

        return ['code' => $currency?->code, 'symbol' => $currency?->symbol, 'decimal_places' => (int) ($currency?->decimal_places ?? 2)];
    }
}
