<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Offers\Loyalty;
use Modules\User\Models\User;

/**
 * FL-L09 (part B), the client's side (a Dorr user): the freelancers I keep working with who give a discount to returning clients — the step I
 * hold with each, what is next and how far — and one freelancer's program before I buy from them. The discount itself is taken by itself
 * when I order, book or accept an offer; there is nothing to claim.
 */
class ClientLoyaltyController extends Controller
{
    public function __construct(private readonly Loyalty $loyalty) {}

    /** GET loyalty — every freelancer I finished work with who runs a program, the best discount first. */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->loyalty->mine($this->me($request)), __('api.retrieved'));
    }

    /** GET freelancers/{uuid}/loyalty — `{loyalty: null}` when they run none (the envelope would turn a bare null into []). */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $f = Freelancer::query()->where('uuid', $uuid)->where('status', FreelancerStatus::Verified->value)->firstOrFail();

        return ApiResponse::success(['loyalty' => $this->loyalty->view($f, $this->me($request))], __('api.retrieved'));
    }

    private function me(Request $request): User
    {
        /** @var User */
        return $request->user('user_api');
    }
}
