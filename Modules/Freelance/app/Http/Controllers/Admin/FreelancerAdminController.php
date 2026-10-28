<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Freelance\Enums\FreelancerStatus;
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
            ['view', ['index', 'show']],
            ['update', ['status', 'hidePortfolio', 'unhidePortfolio']],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(FreelancerStatus::class)],
            'country_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = Freelancer::query()
            ->when($request->filled('search'), function ($q) use ($request): void {
                $term = '%'.$request->string('search').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('country_id'), fn ($q) => $q->where('country_id', $request->integer('country_id')))
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::fromPaginator($page, $page->getCollection()->map(fn (Freelancer $f) => $this->present($f))->values(), __('api.retrieved'));
    }

    public function show(int $freelancer): JsonResponse
    {
        $model = Freelancer::query()->withCount('devices')->findOrFail($freelancer);

        return ApiResponse::success($this->present($model) + [
            'email' => $model->email,
            'gender' => $model->gender?->value,
            'date_of_birth' => $model->date_of_birth?->toDateString(),
            'locale' => $model->locale,
            'terms_version' => $model->terms_version,
            'terms_accepted_at' => $model->terms_accepted_at?->toIso8601String(),
            'devices_count' => $model->devices_count,
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
            'status' => $f->status?->value,
            'status_reason' => $f->status_reason,
            'phone_verified_at' => $f->phone_verified_at?->toIso8601String(),
            'last_active_at' => $f->last_active_at?->toIso8601String(),
            'created_at' => $f->created_at?->toIso8601String(),
        ];
    }
}
