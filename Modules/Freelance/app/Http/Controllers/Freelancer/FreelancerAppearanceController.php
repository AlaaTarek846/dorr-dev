<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Http\Requests\FreelancerAppearanceRequest;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerAppearance;
use Modules\User\Services\MobileAppearanceService;

/** The freelancer's colours, dark mode and font - the DORR app's appearance settings, kept per freelancer (same payload as `mobile/v1/appearance`). */
class FreelancerAppearanceController extends Controller
{
    public function __construct(private readonly MobileAppearanceService $appearance) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->appearance->payload($this->saved($this->me($request))), __('api.retrieved'));
    }

    public function update(FreelancerAppearanceRequest $request): JsonResponse
    {
        $me = $this->me($request);
        $attributes = $this->appearance->attributesFrom($request->validated());
        if ($attributes !== []) {
            FreelancerAppearance::query()->updateOrCreate(['freelancer_id' => $me->id], $attributes);
        }

        return ApiResponse::success($this->appearance->payload($this->saved($me)), __('api.updated'));
    }

    private function saved(Freelancer $me): ?FreelancerAppearance
    {
        return FreelancerAppearance::query()->where('freelancer_id', $me->id)->first();
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
