<?php

namespace Modules\Rides\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Rides\Http\Controllers\Rider\LoyaltyController;
use Modules\Rides\Models\LoyaltyBadge;
use Modules\Rides\Models\LoyaltyChallenge;
use Modules\Rides\Models\LoyaltyEntry;
use Modules\Rides\Models\LoyaltyReward;
use Modules\Rides\Models\LoyaltyTier;
use Modules\Rides\Services\Loyalty\LoyaltyService;
use Modules\User\Models\User;

/**
 * Admin → Rides → DORR points (R-146..R-155): the tiers, the badges, the challenges and the rewards
 * (`{list}` = tiers / badges / challenges / rewards), and one rider's points (look up, add or take with a reason).
 * How many points a currency unit gives and the committed rider's bonus are in the rules (`loyalty.*`).
 */
class LoyaltyAdminController extends Controller implements HasMiddleware
{
    private const LISTS = ['tiers' => LoyaltyTier::class, 'badges' => LoyaltyBadge::class, 'challenges' => LoyaltyChallenge::class, 'rewards' => LoyaltyReward::class];

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('ride-loyalty', [
            ['view', ['index', 'options', 'member']],
            ['create', ['store']],
            ['update', ['update', 'adjust']],
            ['delete', ['destroy']],
        ]);
    }

    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'countries' => Country::query()->with('currency')->get()->map(fn (Country $c) => ['id' => $c->id, 'name' => $c->translated('name') ?? $c->code, 'currency' => $c->currency?->code])->values(),
            'tiers' => LoyaltyTier::query()->orderBy('min_points')->get()->map(fn (LoyaltyTier $t) => ['id' => $t->id, 'name' => $t->localized('name')])->values(),
            'rules' => ['badges' => ['rides', 'category_rides', 'services', 'spend_minor'], 'challenges' => ['rides', 'category_rides', 'services', 'spend_minor', 'clean_rides']],
            'icons' => ['star', 'rocket', 'crown', 'heart', 'leaf', 'moon', 'bolt', 'trophy', 'map', 'car', 'bus', 'boat'],
        ], __('api.retrieved'));
    }

    public function index(string $list): JsonResponse
    {
        $class = $this->model($list);
        $query = $class::query();
        $query = match ($list) {
            'tiers' => $query->orderBy('min_points'),
            'challenges' => $query->orderByDesc('starts_at'),
            default => $query->orderBy('sort_order')->orderBy('id'),
        };

        return ApiResponse::success($query->get()->map(fn (Model $m) => $m->toArray())->values(), __('api.retrieved'));
    }

    public function store(Request $request, string $list, AuditLogger $audit): JsonResponse
    {
        $class = $this->model($list);
        $model = $class::query()->create($this->validated($request, $list));
        $audit->record("ride_loyalty_{$list}.created", $model, null, $model->toArray());

        return ApiResponse::success($model->refresh()->toArray(), __('api.created'), 201);
    }

    public function update(Request $request, string $list, int $id, AuditLogger $audit): JsonResponse
    {
        $model = $this->model($list)::query()->findOrFail($id);
        $before = $model->toArray();
        $model->fill($this->validated($request, $list))->save();
        $audit->record("ride_loyalty_{$list}.updated", $model, $before, $model->toArray());

        return ApiResponse::success($model->refresh()->toArray(), __('api.updated'));
    }

    public function destroy(string $list, int $id, AuditLogger $audit): JsonResponse
    {
        $model = $this->model($list)::query()->findOrFail($id);
        $model->delete();
        $audit->record("ride_loyalty_{$list}.deleted", $model);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** One rider by phone: balance, tier and the last movements. */
    public function member(Request $request, LoyaltyService $loyalty): JsonResponse
    {
        $phone = trim((string) $request->query('phone', ''));
        $user = $phone === '' ? null : User::query()->where('phone', 'like', '%'.ltrim($phone, '+0').'%')->first();
        if ($user === null) {
            return ApiResponse::success(null, __('api.retrieved'));
        }
        $account = $loyalty->account($user);

        return ApiResponse::success([
            'user' => ['id' => $user->id, 'name' => $user->name, 'phone' => $user->phone],
            'balance' => (int) $account->balance, 'lifetime' => (int) $account->lifetime, 'tier' => $account->tier?->localized('name'),
            'entries' => LoyaltyEntry::query()->where('user_id', $user->id)->orderByDesc('id')->limit(50)->get()->map(fn (LoyaltyEntry $e) => LoyaltyController::entry($e))->values(),
        ], __('api.retrieved'));
    }

    public function adjust(Request $request, int $user, LoyaltyService $loyalty, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['points' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'], 'reason' => ['required', 'string', 'max:160']]);
        $rider = User::query()->findOrFail($user);
        $entry = $loyalty->adjust($rider, (int) $data['points'], $data['reason'], (int) $request->user()->getKey());
        $audit->record('ride_loyalty.adjusted', $rider, null, $data);

        return ApiResponse::success(LoyaltyController::entry($entry), __('api.updated'));
    }

    /** @return class-string<Model> */
    private function model(string $list): string
    {
        return self::LISTS[$list] ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $list): array
    {
        $words = fn (string $k, bool $required = true) => [
            $k => [$required ? 'required' : 'nullable', 'array'],
            "$k.ar" => [$required ? 'required' : 'nullable', 'string', 'max:500'],
            "$k.en" => [$required ? 'required' : 'nullable', 'string', 'max:500'],
        ];

        $data = $request->validate(match ($list) {
            'tiers' => [
                ...$words('name'),
                'benefits' => ['nullable', 'array'], 'benefits.ar' => ['nullable', 'array'], 'benefits.ar.*' => ['string', 'max:160'],
                'benefits.en' => ['nullable', 'array'], 'benefits.en.*' => ['string', 'max:160'],
                'min_points' => ['required', 'integer', 'min:0'], 'multiplier_bp' => ['required', 'integer', 'between:0,100000'],
                'color' => ['nullable', 'string', 'max:9'], 'sort_order' => ['nullable', 'integer', 'min:0'], 'status' => ['required', 'boolean'],
            ],
            'badges' => [
                ...$words('name'), ...$words('description', false),
                'icon' => ['required', 'string', 'max:40'], 'rule' => ['required', 'in:rides,category_rides,services,spend_minor'],
                'category' => ['nullable', 'required_if:rule,category_rides', 'string', 'max:32'],
                'threshold' => ['required', 'integer', 'min:1'], 'reward_points' => ['required', 'integer', 'min:0'],
                'sort_order' => ['nullable', 'integer', 'min:0'], 'status' => ['required', 'boolean'],
            ],
            'challenges' => [
                ...$words('title'), ...$words('description', false),
                'rule' => ['required', 'in:rides,category_rides,services,spend_minor,clean_rides'],
                'category' => ['nullable', 'required_if:rule,category_rides', 'string', 'max:32'],
                'target' => ['required', 'integer', 'min:1'], 'reward_points' => ['required', 'integer', 'min:1'],
                'country_id' => ['nullable', 'integer', 'exists:countries,id'],
                'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'], 'status' => ['required', 'boolean'],
            ],
            'rewards' => [
                ...$words('title'), ...$words('description', false),
                'cost_points' => ['required', 'integer', 'min:1'], 'kind' => ['required', 'in:wallet_credit,coupon'],
                'country_id' => ['required', 'integer', 'exists:countries,id'],
                'value_minor' => ['required_if:kind,wallet_credit', 'nullable', 'integer', 'min:0'],
                'coupon_percent' => ['nullable', 'integer', 'between:1,100'], 'coupon_max_minor' => ['nullable', 'integer', 'min:0'],
                'coupon_days' => ['nullable', 'integer', 'between:1,365'], 'min_tier_id' => ['nullable', 'integer', 'exists:loyalty_tiers,id'],
                'stock' => ['nullable', 'integer', 'min:0'], 'per_user_limit' => ['nullable', 'integer', 'min:1'],
                'sort_order' => ['nullable', 'integer', 'min:0'], 'status' => ['required', 'boolean'],
            ],
        });
        if ($list === 'rewards') {
            $data['value_minor'] = (int) ($data['value_minor'] ?? 0);
            $data['coupon_days'] = (int) ($data['coupon_days'] ?? 30);
        }
        if (array_key_exists('sort_order', $data)) {
            $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        }

        return $data;
    }
}
