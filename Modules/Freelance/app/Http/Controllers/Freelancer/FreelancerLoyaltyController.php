<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Offers\Loyalty;

/**
 * FL-L09 (part B), the freelancer's side: my loyalty program — the steps of discount a client earns by coming back (set within the
 * platform's limits), switching it on or off, the clients who earned a step and what the discounts came to.
 */
class FreelancerLoyaltyController extends Controller
{
    public function __construct(private readonly Loyalty $loyalty) {}

    /** GET loyalty — the program, the limits, ready-made ladders, my numbers and the clients on a step. */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->loyalty->dashboard($this->me($request)), __('api.retrieved'));
    }

    /** PUT loyalty {enabled, tiers: [{after, discount_bp}]} — always the whole ladder. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'tiers' => ['required', 'array', 'max:10'],
            'tiers.*.after' => ['required', 'integer'],
            'tiers.*.discount_bp' => ['required', 'integer'],
        ]);
        $me = $this->me($request);
        $this->loyalty->save($me, (bool) $data['enabled'], array_values($data['tiers']));

        return ApiResponse::success($this->loyalty->dashboard($me), __('freelance.loyalty_saved'));
    }

    /** POST loyalty/enable | loyalty/disable — switch my program without touching its steps. */
    public function toggle(Request $request, string $action): JsonResponse
    {
        $me = $this->me($request);
        $this->loyalty->toggle($me, $action === 'enable');

        return ApiResponse::success($this->loyalty->dashboard($me), __('api.updated'));
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
