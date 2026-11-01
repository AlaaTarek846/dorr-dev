<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Offers\FreelancerOffers;
use Modules\Freelance\Services\Plans\FreelanceOfferPacks;

/**
 * Extra offers for sale (FL-L01, phase 2): the packs with their price in the freelancer's country and what they have left, and buying one
 * from the wallet. The app reads once when the screen opens and after a purchase.
 */
class FreelancerOfferPackController extends Controller
{
    public function __construct(private readonly FreelanceOfferPacks $packs, private readonly FreelancerOffers $offers) {}

    /** GET offer-packs — the packs here, my bought offers left (and when they end) and my last purchases, with my monthly quota. */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->body($this->me($request)), __('api.retrieved'));
    }

    /** POST offer-packs/{pack}/buy {request_id?} — buy a pack (`pack` is its slug); the same `request_id` again is the same purchase. */
    public function buy(Request $request, string $pack): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate(['request_id' => ['nullable', 'string', 'max:64']]);

        $this->packs->buy($me, $pack, $data['request_id'] ?? null);

        return ApiResponse::success($this->body($me), __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function body(Freelancer $me): array
    {
        return $this->packs->catalog($me) + ['quota' => $this->offers->quota($me)];
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
