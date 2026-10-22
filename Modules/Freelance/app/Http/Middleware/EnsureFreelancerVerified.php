<?php

namespace Modules\Freelance\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Verification\FreelancerGate;
use Symfony\Component\HttpFoundation\Response;

/**
 * `freelance.verified` — on every freelancer route that does the work (applying, accepting an invitation, starting, being
 * paid): only a verified freelancer gets through; anyone else gets `freelance_verification_required` (403) with the step
 * they are missing (FL-110). Browsing and the profile stay open.
 */
class EnsureFreelancerVerified
{
    public function __construct(private readonly FreelancerGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $freelancer = $request->user('freelance_api');
        abort_unless($freelancer instanceof Freelancer, 401);

        $this->gate->assertCanApply($freelancer);

        return $next($request);
    }
}
