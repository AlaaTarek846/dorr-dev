<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Offers\OfferChecks;
use Modules\Freelance\Services\Projects\FreelancerProjects;

/** FL-304: my offer texts to start from, and the check of an offer before it is sent. */
class FreelancerOfferTemplateController extends Controller
{
    public function __construct(private readonly OfferChecks $checks) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->checks->templates($this->me($request))->map(fn ($t) => OfferChecks::present($t))->values(), __('api.retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::created(OfferChecks::present($this->checks->saveTemplate($this->me($request), $request->validate($this->rules()))), __('api.created'));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $me = $this->me($request);

        return ApiResponse::success(OfferChecks::present($this->checks->saveTemplate($me, $request->validate($this->rules()), $this->checks->findTemplate($me, $id))), __('api.updated'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->checks->findTemplate($this->me($request), $id)->delete();

        return ApiResponse::success(['deleted' => true], __('api.deleted'));
    }

    /** POST projects/{uuid}/offer-check {price_minor?, duration_days?, details?, files?} — what to fix or think about before sending. */
    public function check(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'price_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'duration_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'details' => ['nullable', 'string', 'max:'.(int) config('freelance.offers.details_max', 3000)],
            'files' => ['nullable', 'integer', 'min:0', 'max:50'],
        ]);
        $project = app(FreelancerProjects::class)->find($this->me($request), $uuid);

        return ApiResponse::success(['checks' => $this->checks->check($project, $data)], __('api.retrieved'));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:80'],
            'details' => ['required', 'string', 'min:'.(int) config('freelance.offers.details_min', 30), 'max:'.(int) config('freelance.offers.details_max', 3000)],
        ];
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
