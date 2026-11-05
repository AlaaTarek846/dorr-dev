<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceRightsClaim;
use Modules\Freelance\Services\Projects\Agreements;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Rights\Rights;
use Modules\Freelance\Services\Rights\RightsClaims;
use Modules\User\Models\User;

/**
 * FL-L13, the client's side (a Dorr user): letting the freelancer show a finished work in their portfolio (and taking that back), and
 * reporting a work shown on Dorr that is not the freelancer's own. The terms themselves come with the offer, the service and the agreement.
 */
class ClientRightsController extends Controller
{
    public function __construct(
        private readonly Rights $rights,
        private readonly RightsClaims $claims,
        private readonly Agreements $agreements,
        private readonly ClientProjects $projects,
    ) {}

    /** PUT projects/{uuid}/agreement/rights/portfolio {allowed: bool} — the agreement as it is now. */
    public function portfolio(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['allowed' => ['required', 'boolean']]);
        $me = $this->me($request);
        $agreement = $this->agreements->current($this->projects->find($me, $uuid)) ?? abort(404);
        $this->rights->setPortfolio($agreement, $me, (bool) $data['allowed']);

        return ApiResponse::success($this->agreements->present($agreement->refresh()->load('freelancer'), 'client'), __('freelance.rights.portfolio_updated'));
    }

    /** POST rights/claims {subject_type, subject, details} — a work that is not the freelancer's own. */
    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(FreelanceRightsClaim::SUBJECTS)],
            'subject' => ['required', 'uuid'],
            'details' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $claim = $this->claims->report($data['subject_type'], $data['subject'], $data['details'], $this->me($request));

        return ApiResponse::created(['uuid' => $claim->uuid, 'status' => $claim->status], __('freelance.rights.claim_received'));
    }

    private function me(Request $request): User
    {
        /** @var User */
        return $request->user('user_api');
    }
}
