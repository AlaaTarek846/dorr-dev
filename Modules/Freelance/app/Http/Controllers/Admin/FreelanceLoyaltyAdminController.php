<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Freelance\Models\FreelanceLoyaltyProgram;
use Modules\Freelance\Services\Offers\Loyalty;

/**
 * FL-L09 (part B): the staff's read-only view of the loyalty programs freelancers run — who gives what discount from how many works — and
 * what they came to. The limits are policy (Prices & rules → Loyalty) and the switch is `loyalty`.
 */
class FreelanceLoyaltyAdminController extends Controller implements HasMiddleware
{
    public function __construct(private readonly Loyalty $loyalty) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-loyalty', [
            ['view', ['index']],
        ]);
    }

    /** GET freelance-loyalty[?search=&enabled=&per_page=] — `totals`: programs on, discounts taken, what they came to. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'enabled' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = FreelanceLoyaltyProgram::query()->with('freelancer')
            ->when(isset($f['enabled']), fn ($q) => $q->where('enabled', (bool) $f['enabled']))
            ->when(isset($f['search']), function ($q) use ($f) {
                $term = '%'.addcslashes($f['search'], '%_\\').'%';
                $q->whereHas('freelancer', fn ($w) => $w->where('name', 'like', $term)->orWhere('phone', 'like', $term));
            })
            ->latest('id')->paginate((int) ($f['per_page'] ?? 20));

        $usage = $this->loyalty->usageOf($page->getCollection()->pluck('freelancer_id')->map(fn ($id) => (int) $id)->all());

        return ApiResponse::success(
            $page->getCollection()->map(fn (FreelanceLoyaltyProgram $p) => [
                'id' => $p->id, 'freelancer' => ['id' => $p->freelancer_id, 'name' => $p->freelancer?->name, 'phone' => $p->freelancer?->phone],
                'enabled' => (bool) $p->enabled, 'tiers' => array_values((array) $p->tiers),
                'uses' => $usage[$p->freelancer_id]['uses'] ?? 0, 'saved_minor' => $usage[$p->freelancer_id]['saved_minor'] ?? 0, 'currency' => $usage[$p->freelancer_id]['currency'] ?? null,
                'updated_at' => $p->updated_at?->toIso8601String(),
            ])->values(), __('api.retrieved'), 200, ApiPaginator::meta($page), ['totals' => $this->loyalty->totals()],
        );
    }
}
