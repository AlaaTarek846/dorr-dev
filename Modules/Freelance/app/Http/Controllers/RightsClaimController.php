<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceRightsClaim;
use Modules\Freelance\Services\Rights\RightsClaims;

/**
 * FL-L13: anyone outside Dorr who finds a portfolio work on the website that is theirs may report it — no sign-in, a name and a way to
 * reach them, and the work's id from its page. The staff read it and decide.
 */
class RightsClaimController extends Controller
{
    public function __construct(private readonly RightsClaims $claims) {}

    /** POST public/rights-claims {subject, contact, name?, details} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'uuid'],
            'contact' => ['required', 'string', 'min:5', 'max:160'],
            'name' => ['nullable', 'string', 'max:120'],
            'details' => ['required', 'string', 'min:20', 'max:2000'],
        ]);
        $claim = $this->claims->report(FreelanceRightsClaim::PORTFOLIO_ITEM, $data['subject'], $data['details'], null, ['name' => $data['name'] ?? null, 'contact' => $data['contact']]);

        return ApiResponse::created(['uuid' => $claim->uuid], __('freelance.rights.claim_received'));
    }
}
