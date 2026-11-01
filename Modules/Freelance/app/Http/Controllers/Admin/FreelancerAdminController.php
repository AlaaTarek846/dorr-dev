<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceVerificationRequest;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerPortfolioItem;
use Modules\Freelance\Services\Account\FreelancerPortfolio;
use Modules\Freelance\Services\Account\FreelancerAuthService;

/**
 * Admin → Freelance → Freelancers, the minimum to run accounts (FL-003): list, details, and
 * activate / suspend / block with a reason. The full screen comes with FL-801.
 */
class FreelancerAdminController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelancers', [
            ['view', ['index', 'show', 'options']],
            ['update', ['status', 'hidePortfolio', 'unhidePortfolio']],
        ]);
    }

    /** GET freelancers/options - what the filters offer. */
    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'countries' => \App\Models\Country::query()->with('translations')->where('status', true)->get()->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code])->values(),
            'specialties' => \Modules\Freelance\Models\FreelanceSpecialty::query()->with('translations')->where('status', true)->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName()])->values(),
            'statuses' => array_map(fn (FreelancerStatus $s) => $s->value, FreelancerStatus::cases()),
        ], __('api.retrieved'));
    }

    /**
     * GET freelancers[?search=&status=&country_id=&specialty_id=&per_page=] (FL-109) - `counts` per status, for the tabs.
     * A specialty matches the ones on the profile.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(FreelancerStatus::class)],
            'country_id' => ['nullable', 'integer'],
            'specialty_id' => ['nullable', 'integer'],
            'risk' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $base = Freelancer::query()
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->filled('country_id'), fn ($q) => $q->where('country_id', $request->integer('country_id')))
            ->when($request->filled('risk'), fn ($q) => $q->where('risk_level', $request->string('risk')))
            ->when($request->filled('specialty_id'), fn ($q) => $q->whereHas('specialties', fn ($s) => $s->where('freelance_specialties.id', $request->integer('specialty_id'))));
        $counts = (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $page = (clone $base)->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with(['country', 'specialties.translations'])->withCount(['offers'])->latest('id')->paginate($request->integer('per_page', 20));

        return ApiResponse::success($page->getCollection()->map(fn (Freelancer $f) => $this->present($f) + [
            'offers_count' => $f->offers_count,
            'specialties' => $f->specialties->map(fn ($sp) => $sp->translatedName())->values()->all(),
        ])->values(), __('api.retrieved'), 200, \App\Support\Api\ApiPaginator::meta($page) + ['counts' => $counts]);
    }

    /** GET freelancers/{id} - the page of one freelancer: profile, verification, works, offers, projects, wallet, and what staff did to the account. */
    public function show(int $freelancer): JsonResponse
    {
        $model = Freelancer::query()->withCount('devices')->with(['country', 'specialties.translations', 'skills.translations'])->findOrFail($freelancer);
        $latest = FreelanceVerificationRequest::query()->where('freelancer_id', $model->id)->withCount('documents')->latest('id')->first();
        $agreements = FreelanceAgreement::query()->where('freelancer_id', $model->id)->with('project:id,uuid,title')->latest('id')->limit(20)->get();
        $audit = AuditLog::query()->where('subject_type', $model->getMorphClass())->where('subject_id', $model->id)->latest('id')->limit(30)->get();
        $admins = Admin::query()->whereIn('id', $audit->where('actor_type', 'admin')->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        return ApiResponse::success($this->present($model) + [
            'email' => $model->email,
            'gender' => $model->gender?->value,
            'date_of_birth' => $model->date_of_birth?->toDateString(),
            'locale' => $model->locale,
            'terms_version' => $model->terms_version,
            'terms_accepted_at' => $model->terms_accepted_at?->toIso8601String(),
            'devices_count' => $model->devices_count,
            'profile' => [
                'headline' => $model->headline, 'bio' => $model->bio, 'years_of_experience' => $model->years_of_experience, 'residency' => $model->residency,
                'contact_languages' => $model->contact_languages,
                'specialties' => $model->specialties->map(fn ($sp) => ['id' => $sp->id, 'name' => $sp->translatedName(), 'primary' => (bool) $sp->pivot->is_primary])->values()->all(),
                'skills' => $model->skills->map(fn ($sk) => $sk->translatedName())->values()->all(),
            ],
            'verification' => $latest === null ? null : [
                'uuid' => $latest->uuid, 'status' => $latest->status, 'submitted_at' => $latest->submitted_at?->toIso8601String(),
                'decided_at' => $latest->decided_at?->toIso8601String(), 'reason' => $latest->reason, 'documents_count' => $latest->documents_count,
            ],
            'portfolio' => FreelancerPortfolioItem::query()->where('freelancer_id', $model->id)->latest('id')->limit(30)->get()->map(fn (FreelancerPortfolioItem $i) => [
                'id' => $i->id, 'title' => $i->title, 'visibility' => $i->visibility, 'hidden' => $i->hidden_by_admin_at !== null, 'hidden_reason' => $i->hidden_reason,
            ])->values()->all(),
            'offers' => FreelanceOffer::query()->where('freelancer_id', $model->id)->with('project:id,uuid,title')->latest('id')->limit(20)->get()->map(fn (FreelanceOffer $o) => [
                'uuid' => $o->uuid, 'project' => ['uuid' => $o->project?->uuid, 'title' => $o->project?->title], 'price_minor' => (int) $o->price_minor,
                'currency' => $o->currency_code, 'status' => $o->status instanceof \BackedEnum ? $o->status->value : $o->status, 'created_at' => $o->created_at?->toIso8601String(),
            ])->values()->all(),
            'projects' => $agreements->map(fn (FreelanceAgreement $a) => [
                'uuid' => $a->uuid, 'project' => ['uuid' => $a->project?->uuid, 'title' => $a->project?->title], 'status' => $a->status,
                'price_minor' => (int) ($a->money['price_minor'] ?? 0), 'currency' => $a->currency_code, 'created_at' => $a->created_at?->toIso8601String(),
            ])->values()->all(),
            'stats' => [
                'offers' => FreelanceOffer::query()->where('freelancer_id', $model->id)->count(),
                'agreements_active' => FreelanceAgreement::query()->where('freelancer_id', $model->id)->where('status', FreelanceAgreement::ACTIVE)->count(),
                'agreements_completed' => FreelanceAgreement::query()->where('freelancer_id', $model->id)->where('status', FreelanceAgreement::COMPLETED)->count(),
            ],
            'wallets' => $model->wallets()->with('country', 'currency')->get()->map(fn ($w) => [
                'country' => $w->country?->code, 'currency' => $w->currency?->code, 'withdrawable_minor' => (int) $w->withdrawable_minor, 'spend_only_minor' => (int) $w->spend_only_minor,
                'held_minor' => (int) $w->held_withdrawable_minor + (int) $w->held_spend_only_minor, 'status' => $w->status instanceof \BackedEnum ? $w->status->value : $w->status,
            ])->values()->all(),
            'audit' => $audit->map(fn (AuditLog $l) => [
                'action' => $l->action, 'by' => $l->actor_type === 'admin' ? ($admins[$l->actor_id] ?? '#'.$l->actor_id) : 'system',
                'before' => $l->before, 'after' => $l->after, 'at' => $l->created_at?->toIso8601String(),
            ])->values()->all(),
        ], __('api.retrieved'));
    }

    /** A blocked account loses every session and can't sign in again; a suspended one signs in and sees why. */
    public function status(Request $request, int $freelancer, FreelancerAuthService $auth, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(FreelancerStatus::adminSettable())],
            'reason' => ['nullable', 'required_if:status,suspended,blocked', 'string', 'max:255'],
        ]);
        $model = Freelancer::query()->findOrFail($freelancer);
        $before = ['status' => $model->status?->value, 'status_reason' => $model->status_reason];

        $model->forceFill(['status' => $data['status'], 'status_reason' => $data['reason'] ?? null])->save();
        if ($data['status'] === FreelancerStatus::Verified->value && $model->verified_at === null) {
            $model->forceFill(['verified_at' => now()])->save();
        }
        if ($data['status'] === FreelancerStatus::Blocked->value) {
            $auth->endAllSessions($model);
        }
        $audit->record('freelancer.status_changed', $model, $before, $data);

        return ApiResponse::success($this->present($model->refresh()), __('api.updated'));
    }

    /** POST freelancers/{id}/portfolio/{item}/hide {reason} — a work that breaks the rules leaves every other view; its owner is told why. */
    public function hidePortfolio(Request $request, int $freelancer, int $item, FreelancerPortfolio $portfolio): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $model = FreelancerPortfolioItem::query()->where('freelancer_id', $freelancer)->findOrFail($item);

        return ApiResponse::success(['uuid' => $portfolio->hide($model, $data['reason'], (int) $request->user('admin_api')->getKey())->uuid, 'hidden' => true], __('api.updated'));
    }

    public function unhidePortfolio(Request $request, int $freelancer, int $item, FreelancerPortfolio $portfolio): JsonResponse
    {
        $model = FreelancerPortfolioItem::query()->where('freelancer_id', $freelancer)->findOrFail($item);

        return ApiResponse::success(['uuid' => $portfolio->unhide($model, (int) $request->user('admin_api')->getKey())->uuid, 'hidden' => false], __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function present(Freelancer $f): array
    {
        return [
            'id' => $f->id,
            'uuid' => $f->uuid,
            'name' => $f->name,
            'phone' => $f->phone,
            'country_id' => $f->country_id,
            'country' => $f->country?->code,
            'status' => $f->status?->value,
            'status_reason' => $f->status_reason,
            // FL-707: kept daily and whenever staff open the person or a sanction changes.
            'quality_score' => $f->quality_score,
            'risk_level' => $f->risk_level,
            'phone_verified_at' => $f->phone_verified_at?->toIso8601String(),
            'last_active_at' => $f->last_active_at?->toIso8601String(),
            'created_at' => $f->created_at?->toIso8601String(),
        ];
    }
}
