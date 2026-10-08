<?php

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\AI\Models\AiSiteHosting;
use Modules\AI\Models\AiSiteProject;
use Modules\AI\Services\Sites\AiSiteResponder;

/**
 * Serves a customer's generated site: the private preview link (unguessable slug)
 * and, when hosting runs in path mode, a hosted site at /sites/{name}.
 * Sub-domain hosting goes through ServeHostedSiteMiddleware instead.
 */
class AiSiteServeController extends Controller
{
    public function __construct(protected AiSiteResponder $responder) {}

    public function show(Request $request, string $slug, string $path = '')
    {
        $project = AiSiteProject::query()->where('slug', $slug)->with('currentVersion')->first();

        if ($project === null || $project->isDisabled() || $project->currentVersion === null) {
            abort(404);
        }

        return $this->responder->respond($request, $project, $project->currentVersion, $path, hosted: false, redirectRoot: true);
    }

    /** Hosted site, path mode (no wildcard DNS available). */
    public function hosted(Request $request, string $name, string $path = '')
    {
        $hosting = AiSiteHosting::query()->where('subdomain', strtolower($name))->with(['project', 'publishedVersion'])->first();

        if ($hosting === null || ! $hosting->isLive() || $hosting->publishedVersion === null
            || $hosting->project === null || $hosting->project->trashed() || $hosting->project->isDisabled()) {
            abort(404);
        }

        return $this->responder->respond($request, $hosting->project, $hosting->publishedVersion, $path, hosted: true, redirectRoot: true);
    }
}
