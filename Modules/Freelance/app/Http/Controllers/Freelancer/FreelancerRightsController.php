<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Projects\Agreements;
use Modules\Freelance\Services\Projects\FreelancerProjects;
use Modules\Freelance\Services\Rights\Rights;

/**
 * FL-L13, the freelancer's side: what the offer and service forms need (the three ways to hand over the work, with the standard text of
 * their country), asking a client's leave to show a finished work, and the finished works they may put in their portfolio.
 */
class FreelancerRightsController extends Controller
{
    public function __construct(
        private readonly Rights $rights,
        private readonly Agreements $agreements,
        private readonly FreelancerProjects $projects,
    ) {}

    /** GET rights — `enabled` says whether the terms apply in my country at all. */
    public function options(Request $request): JsonResponse
    {
        return ApiResponse::success($this->rights->options((int) $this->me($request)->country_id), __('api.retrieved'));
    }

    /** GET rights/portfolio-sources — my finished works whose client allows showing them. */
    public function portfolioSources(Request $request): JsonResponse
    {
        return ApiResponse::success($this->rights->portfolioSources($this->me($request)), __('api.retrieved'));
    }

    /** POST projects/{uuid}/agreement/rights/portfolio-request — ask the client's leave (not again for a few days). */
    public function askPortfolio(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $agreement = $this->agreements->forFreelancer($this->projects->find($me, $uuid), $me);
        abort_if($agreement->status === 'draft', 404);
        $this->rights->askPortfolio($agreement, $me);

        return ApiResponse::success($this->agreements->present($agreement->refresh(), 'freelancer'), __('freelance.rights.portfolio_asked'));
    }

    private function me(Request $request): Freelancer
    {
        /** @var Freelancer */
        return $request->user('freelance_api');
    }
}
