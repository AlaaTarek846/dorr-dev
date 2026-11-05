<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceDispute;
use Modules\Freelance\Models\FreelanceRightsClaim as Claim;
use Modules\Freelance\Models\FreelanceRightsTerm;
use Modules\Freelance\Models\FreelancerPortfolioItem;
use Modules\Freelance\Services\Rights\Rights;
use Modules\Freelance\Services\Rights\RightsClaims;
use Modules\Freelance\Services\Rights\RightsTerms;

/**
 * FL-L13, the staff's side of ownership and licences: the numbers (how agreements split over the three models, how many clients let a work
 * be shown, disputes and reports about rights), the standard text of each model per country, and the reports of works someone says are
 * theirs. The switch is `rights` (Features) and the permission group `freelance-rights`.
 */
class FreelanceRightsAdminController extends Controller implements HasMiddleware
{
    public function __construct(private readonly RightsTerms $terms, private readonly RightsClaims $claims) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-rights', [
            ['view', ['overview', 'terms', 'claims', 'claim']],
            ['update', ['saveTerms', 'resetTerms', 'decide']],
        ]);
    }

    /** GET freelance-rights/overview — agreements by model, portfolio leave, disputes and reports about rights. */
    public function overview(): JsonResponse
    {
        $byModel = collect(Rights::MODELS)->mapWithKeys(fn (string $m) => [$m => FreelanceAgreement::query()->where('rights->model', $m)->count()]);
        $disputes = FreelanceDispute::query()->where('reason', 'rights')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $claims = Claim::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return ApiResponse::success([
            'agreements' => ['with_terms' => $byModel->sum(), 'by_model' => $byModel],
            'portfolio' => [
                'wished' => FreelanceAgreement::query()->whereNotNull('rights')->where('rights->portfolio', true)->count(),
                'allowed' => FreelanceAgreement::query()->where('portfolio_allowed', true)->count(),
                'items' => FreelancerPortfolioItem::query()->whereNotNull('agreement_id')->count(),
            ],
            'disputes' => ['total' => (int) $disputes->sum(), 'open' => (int) ($disputes[FreelanceDispute::OPEN] ?? 0) + (int) ($disputes[FreelanceDispute::APPEALED] ?? 0)],
            'claims' => collect(Claim::STATUSES)->mapWithKeys(fn (string $s) => [$s => (int) ($claims[$s] ?? 0)]),
        ], __('api.retrieved'));
    }

    /** GET freelance-rights/terms[?country_id=] — the three models for one scope (no country = every country without its own text). */
    public function terms(Request $request): JsonResponse
    {
        $f = $request->validate(['country_id' => ['nullable', 'integer', 'exists:countries,id']]);
        $countries = Country::query()->with('translations')->where('status', true)->get()
            ->map(fn (Country $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code])->values();

        return ApiResponse::success($this->terms->forScope(isset($f['country_id']) ? (int) $f['country_id'] : null), __('api.retrieved'), 200, null, ['countries' => $countries]);
    }

    /** PUT freelance-rights/terms {country_id?, model, title_ar, title_en, body_ar, body_en, active?} */
    public function saveTerms(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'model' => ['required', Rule::in(Rights::MODELS)],
            'title_ar' => ['required', 'string', 'min:3', 'max:120'],
            'title_en' => ['required', 'string', 'min:3', 'max:120'],
            'body_ar' => ['required', 'string', 'min:30', 'max:6000'],
            'body_en' => ['required', 'string', 'min:30', 'max:6000'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $row = $this->terms->save(isset($data['country_id']) ? (int) $data['country_id'] : null, $data['model'], $data, (int) $request->user('admin_api')->id);

        return ApiResponse::success(['id' => $row->id, 'version' => $row->version], __('freelance.rights.terms_saved'));
    }

    /** DELETE freelance-rights/terms/{id} — back to the platform's text for that scope. */
    public function resetTerms(Request $request, int $id): JsonResponse
    {
        $this->terms->reset(FreelanceRightsTerm::query()->findOrFail($id), (int) $request->user('admin_api')->id);

        return ApiResponse::success(null, __('freelance.rights.terms_reset'));
    }

    /** GET freelance-rights/claims[?status=&per_page=] — the open ones first; `counts` per status. */
    public function claims(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(Claim::STATUSES)], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = $this->claims->page($f['status'] ?? null, (int) ($f['per_page'] ?? 20));
        $counts = Claim::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return ApiResponse::success($page->getCollection()->map(fn (Claim $c) => $this->claims->present($c))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page),
            ['counts' => collect(Claim::STATUSES)->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])]);
    }

    /** GET freelance-rights/claims/{uuid} */
    public function claim(string $uuid): JsonResponse
    {
        return ApiResponse::success($this->claims->present(Claim::query()->where('uuid', $uuid)->with('freelancer')->firstOrFail()), __('api.retrieved'));
    }

    /** POST freelance-rights/claims/{uuid}/decide {decision: uphold|dismiss, note?} */
    public function decide(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['uphold', 'dismiss'])], 'note' => ['nullable', 'string', 'max:1000']]);
        $claim = $this->claims->decide(Claim::query()->where('uuid', $uuid)->firstOrFail(), $data['decision'], (string) ($data['note'] ?? ''), (int) $request->user('admin_api')->id);

        return ApiResponse::success($this->claims->present($claim->load('freelancer')), __('freelance.rights.claim_decided'));
    }
}
