<?php

namespace Modules\Freelance\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeamContext;
use Modules\Freelance\Services\FreelancerTeam\FreelancerTeamView;
use Modules\Freelance\Services\FreelancerTeam\TeamAssignments;
use Modules\Freelance\Services\FreelancerTeam\TeamProjects;
use Modules\Freelance\Services\Moderation\Sanctions;
use Symfony\Component\HttpFoundation\Response;

/**
 * FS-206a: a project route of the freelancer's app used by somebody of the freelancers' team whose project it is (the twin of
 * ProjectTeamAccess on the client's side). The signing Owner passes as before. Somebody else of the team passes only where his place allows
 * it (TeamProjects); the request then runs as the signing Owner - his agreement, his wallet, his checks - while FreelancerTeamContext keeps
 * who really acted. The answer hides what his place may not see: the amounts without `money`, the commission and the net but to an Owner,
 * and every button he may not press. A route the map does not know is the signer's alone (403). Anybody else is not let in: not found,
 * as before.
 */
class FreelancerProjectTeamAccess
{
    /** The answers that are a project's or an agreement's page and carry the viewer's `team_access`. */
    private const PAGES = ['FreelancerProjectController@show', 'FreelancerAgreementController@show', 'FreelancerAgreementController@milestoneAction'];

    public function __construct(
        private readonly TeamProjects $projects,
        private readonly FreelancerTeamContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $uuid = $request->route('uuid');
        $me = $request->user('freelance_api');
        $need = TeamProjects::forRoute($request->route());
        if (! is_string($uuid) || ! $me instanceof Freelancer || $need === TeamProjects::PERSONAL) {
            return $next($request);
        }
        $project = Project::query()->where('uuid', $uuid)->first();
        $agreement = $project === null ? null : $this->projects->agreementOf($project);
        if ($agreement === null) {
            return $next($request); // not a team's project: as before
        }
        if ((int) $agreement->freelancer_id === (int) $me->id) {
            $team = $agreement->team()->first();

            return $team === null ? $next($request)
                : $this->page($next($request), $request, TeamProjects::describe($team, TeamProjects::OWNER, TeamProjects::ROLES[TeamProjects::OWNER], true));
        }

        $access = $this->projects->access($agreement, $me);
        if ($access === null) {
            return $next($request); // not his: the project is not found, as before
        }
        // FS-206b: a member starts the part he is put on.
        $startsOwn = $need === TeamProjects::START && $this->projects->startsOwn($agreement, $me, (string) $request->route('milestone'));
        if ($need === null || (! in_array($need, $access['abilities'], true) && ! $startsOwn)) {
            throw new FreelanceException('team_project_forbidden', 403, [], ['ability' => $need]);
        }
        if ($need !== TeamProjects::VIEW) {
            app(Sanctions::class)->assertAllowed($me, 'work');
        }

        $signer = Freelancer::query()->findOrFail($agreement->freelancer_id);
        $resolver = $request->getUserResolver();
        Auth::guard('freelance_api')->setUser($signer);
        $request->setUserResolver(fn ($guard = null) => $guard === null || $guard === 'freelance_api' ? $signer : $resolver($guard));
        $this->context->set($access['member'], $me, $access['role']);
        try {
            $response = $next($request);
        } finally {
            $this->context->clear();
            Auth::guard('freelance_api')->setUser($me);
            $request->setUserResolver($resolver);
        }

        if (! $response instanceof JsonResponse || ! $response->isSuccessful()) {
            return $response;
        }
        $body = $response->getData();
        if (is_object($body) && property_exists($body, 'data')) {
            $body->data = FreelancerTeamView::maskFor($body->data, $access['abilities'], app(TeamAssignments::class)->ownParts($agreement, $me));
            $response->setData($body);
        }

        return $this->page($response, $request, TeamProjects::describe($access['team'], $access['role'], $access['abilities'], false));
    }

    /** @param  array<string, mixed>  $access */
    private function page(Response $response, Request $request, array $access): Response
    {
        if (! $response instanceof JsonResponse || ! $response->isSuccessful() || ! in_array($this->routeKey($request), self::PAGES, true)) {
            return $response;
        }
        $body = $response->getData();
        if (is_object($body) && isset($body->data) && is_object($body->data)) {
            $body->data->team_access = $access;
            $response->setData($body);
        }

        return $response;
    }

    private function routeKey(Request $request): string
    {
        $action = (string) $request->route()?->getActionName();

        return str_contains($action, '@') ? class_basename(explode('@', $action)[0]).'@'.explode('@', $action)[1] : '';
    }
}
