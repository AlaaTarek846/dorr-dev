<?php

namespace Modules\Moving\Http\Controllers\Admin;

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
use Modules\Moving\Models\MovingPolicy;
use Modules\Moving\Services\Policy\MovingPolicies;

/**
 * Admin → Moving → Values (B-01, B-17): offer time, commission, tax, objection window… for every country, or the
 * default for all. An empty value is "not decided yet" and its rule is not applied (DEC-020). A request keeps the
 * values in force when it was published, so a change never reaches requests already out (REQ-003).
 */
class MovingPolicyController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('moving-policies', [
            ['view', ['index']],
            ['update', ['update']],
        ]);
    }

    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'keys' => (array) config('moving.policy_keys', []),
            'countries' => Country::query()->with('translations')->where('status', true)->get()
                ->map(fn (Country $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code])->values(),
            'rows' => MovingPolicy::query()->get()->map(fn (MovingPolicy $p) => [
                'country_id' => $p->country_id, 'values' => $p->values, 'version' => $p->version, 'updated_at' => $p->updated_at?->toIso8601String(),
            ])->values(),
        ], __('api.retrieved'));
    }

    public function update(Request $request, MovingPolicies $policies, AuditLogger $audit): JsonResponse
    {
        $keys = (array) config('moving.policy_keys', []);
        $data = $request->validate([
            'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')],
            'values' => ['required', 'array'],
            ...collect($keys)->mapWithKeys(fn ($k) => ['values.'.str_replace('.', '\.', $k) => ['nullable', 'integer', 'min:0']])->all(),
        ]);
        $row = $policies->save($data['country_id'] ?? null, $data['values'], $request->user('admin_api')->id);
        $audit->record('moving_policy.updated', $row, null, $data);

        return ApiResponse::success(['country_id' => $row->country_id, 'values' => $row->values, 'version' => $row->version], __('api.updated'));
    }
}
