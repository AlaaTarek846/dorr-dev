<?php

namespace Modules\Freelance\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceChangeRequest;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelanceProjectMember as Member;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Moderation\Sanctions;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Policy\FreelanceMoney;
use Modules\Freelance\Services\Team\ClientTeam;
use Modules\Freelance\Services\Team\ClientTeamAbilities as Abilities;
use Modules\Freelance\Services\Team\ClientTeamContext;
use Modules\User\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * FS-104: a project route used by someone of the owner's team (docs/modules/freelance/PERMISSIONS.md). The owner passes as before.
 * A member passes only where his role allows it (and a delegate within his limit and time); the request then runs as the owner — his
 * project, his wallet, his checks — while {@see ClientTeamContext} keeps who really acted. The answer hides what the role may not see:
 * the amounts without `money`, the owner's wallet always, and every button the role may not press.
 */
class ProjectTeamAccess
{
    /** The answers that are a project or an agreement page and carry the viewer's `access`. */
    private const PAGES = ['ClientProjectController@show', 'ClientAgreementController@show', 'ClientAgreementController@milestoneAction'];

    public function __construct(
        private readonly ClientTeam $team,
        private readonly ClientTeamContext $context,
        private readonly FreelanceFeatures $features,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $uuid = $request->route('uuid');
        $me = $request->user('user_api');
        if (! is_string($uuid) || ! $me instanceof User) {
            return $next($request);
        }
        $project = Project::query()->where('uuid', $uuid)->first();
        if ($project === null) {
            return $next($request);
        }
        if ((int) $project->client_id === (int) $me->id) {
            return $this->decorate($next($request), $request, $project, null);
        }

        $member = $this->team->activeMember($me, $project);
        if ($member === null || ! $this->features->allows((int) $project->country_id, 'client_team')) {
            return $next($request); // not his: the project is not found, as before
        }
        $ability = Abilities::forRoute($request->route());
        if ($ability === null || ! Abilities::allows($member, $ability)) {
            $over = $ability !== null && in_array($ability, Abilities::DELEGATED, true) && $member->delegationOver();
            throw new FreelanceException($over ? 'team_delegation_over' : 'team_forbidden', 403);
        }
        if (! in_array($ability, [Abilities::VIEW, Abilities::MONEY, Abilities::OFFERS], true)) {
            app(Sanctions::class)->assertAllowed($me, 'work');
        }
        if (in_array($ability, Abilities::DELEGATED, true)) {
            $this->assertWithinLimit($member, $ability, $project, $request);
        }

        $owner = $project->client()->firstOrFail();
        $resolver = $request->getUserResolver();
        Auth::guard('user_api')->setUser($owner);
        $request->setUserResolver(fn ($guard = null) => $guard === null || $guard === 'user_api' ? $owner : $resolver($guard));
        $this->context->set($member, $me);
        try {
            $response = $next($request);
        } finally {
            $this->context->clear();
            Auth::guard('user_api')->setUser($me);
            $request->setUserResolver($resolver);
        }

        // The pipeline already turned a refusal into an answer. The owner's wallet is his: a member is told it falls short,
        // never what it holds or in which currency.
        if ($response instanceof JsonResponse && in_array($response->getData()->error_code ?? null, ['freelance_wallet_insufficient', 'freelance_wallet_currency'], true)) {
            throw new FreelanceException('team_owner_wallet', 422);
        }

        if ($response->isSuccessful() && in_array($ability, Abilities::DELEGATED, true)) {
            $this->tellOwner($project, $owner, $me, $ability, $request);
        }

        return $this->decorate($response, $request, $project, $member);
    }

    /** A delegate approves or funds at most his limit at once (the money of the step, the client fee included when funding). */
    private function assertWithinLimit(Member $member, string $ability, Project $project, Request $request): void
    {
        $amount = match ($ability) {
            Abilities::FUND => (function () use ($project, $request) {
                $m = $this->milestone($project, $request);

                return (int) $m->amount_minor + (int) $m->client_fee_minor;
            })(),
            Abilities::RELEASE => (int) $this->milestone($project, $request)->amount_minor,
            default => $this->changeIncrease($project, $request),
        };
        if ($member->max_amount_minor !== null && $amount > (int) $member->max_amount_minor) {
            $max = app(FreelanceMoney::class)->format((int) $member->max_amount_minor);
            throw new FreelanceException('team_over_limit', 403, ['max' => $max], ['max_amount_minor' => (int) $member->max_amount_minor, 'amount_minor' => $amount]);
        }
    }

    private function milestone(Project $project, Request $request): FreelanceMilestone
    {
        return FreelanceMilestone::query()->where('uuid', (string) $request->route('milestone'))
            ->whereHas('agreement', fn ($q) => $q->where('project_id', $project->id))->firstOrFail();
    }

    /** What accepting a change adds to the money (a higher part price or a new part); rejecting adds nothing. */
    private function changeIncrease(Project $project, Request $request): int
    {
        if ($request->route('action') !== 'accept') {
            return 0;
        }
        $c = FreelanceChangeRequest::query()->where('project_id', $project->id)->where('uuid', (string) $request->route('change'))->firstOrFail();
        $p = (array) $c->payload;

        return match ($c->type) {
            'milestone_price' => max(0, (int) ($p['to'] ?? 0) - (int) ($p['from'] ?? 0)),
            'add_milestone' => (int) ($p['amount_minor'] ?? 0),
            default => 0,
        };
    }

    /** The owner hears of every step a delegate took for him. */
    private function tellOwner(Project $project, User $owner, User $actor, string $ability, Request $request): void
    {
        $event = match ($ability) {
            Abilities::FUND => 'team.acted_fund',
            Abilities::RELEASE => 'team.acted_release',
            default => $request->route('action') === 'accept' ? 'team.acted_change_accept' : 'team.acted_change_reject',
        };
        app(FreelanceNotifier::class)->notify($owner, $event, ['project' => (string) $project->title, 'name' => (string) $actor->name], ['project' => $project->uuid]);
    }

    private function decorate(Response $response, Request $request, Project $project, ?Member $member): Response
    {
        if (! $response instanceof JsonResponse || ! $response->isSuccessful()) {
            return $response;
        }
        $page = in_array($this->routeKey($request), self::PAGES, true);
        if ($member === null && ! $page) {
            return $response;
        }
        // Decoded as objects, so an empty map (`"can": {}`) goes back out as a map.
        $body = $response->getData();
        if (! is_object($body) || ! property_exists($body, 'data')) {
            return $response;
        }
        if ($member !== null) {
            $body->data = \Modules\Freelance\Services\Team\ClientTeamView::mask($body->data, Abilities::allows($member, Abilities::MONEY), Abilities::of($member));
            if ($member->role === 'delegate' && $member->max_amount_minor !== null && is_object($body->data)) {
                $this->limitButtons($body->data, (int) $member->max_amount_minor);
            }
        }
        if ($page && is_object($body->data)) {
            $body->data->access = $this->team->access($project, $member);
            if ($member === null && $this->routeKey($request) === 'ClientProjectController@show') {
                $body->data->team = $this->summary($project);
            }
        }

        return $response->setData($body);
    }

    /**
     * A delegate's buttons follow his limit: funding (the part with its client fee), approving (the part) and accepting a change (what it adds)
     * are off when the amount is over it, and `over_limit` tells the app why.
     */
    private function limitButtons(object $agreement, int $max): void
    {
        foreach ((array) ($agreement->milestones ?? []) as $m) {
            if (! is_object($m) || ! isset($m->can) || ! is_object($m->can)) {
                continue;
            }
            $fund = (int) ($m->amount_minor ?? 0) + (int) ($m->client_fee_minor ?? 0);
            $amount = (int) ($m->amount_minor ?? 0);
            if ($fund > $max) {
                $m->can->fund = false;
            }
            if ($amount > $max) {
                $m->can->approve = false;
            }
            // Still to fund: the part with its fee; past that, approving the part.
            $m->over_limit = in_array($m->status ?? null, ['approved', 'cancelled'], true) ? false : (($m->status ?? null) === 'unfunded' ? $fund : $amount) > $max;
        }
        foreach ((array) ($agreement->changes ?? []) as $c) {
            if (! is_object($c) || ! isset($c->can) || ! is_object($c->can) || ($c->can->accept ?? false) !== true) {
                continue;
            }
            $p = (array) ($c->payload ?? []);
            $adds = match ($c->type ?? null) {
                'milestone_price' => max(0, (int) ($p['to'] ?? 0) - (int) ($p['from'] ?? 0)),
                'add_milestone' => (int) ($p['amount_minor'] ?? 0),
                default => 0,
            };
            if ($adds > $max) {
                $c->can->accept = false;
                $c->over_limit = true;
            }
        }
    }

    /** @return array{members: int, invited: int, max: int} the owner's team at a glance */
    private function summary(Project $project): array
    {
        $rows = collect($this->team->members($project));

        return [
            'members' => $rows->where('status', Member::ACTIVE)->count(),
            'invited' => $rows->where('status', Member::INVITED)->reject(fn (Member $m) => $this->team->invitationOver($m))->count(),
            'max' => ClientTeam::maxMembers(),
        ];
    }

    private function routeKey(Request $request): string
    {
        $action = (string) $request->route()?->getActionName();

        return str_contains($action, '@') ? class_basename(explode('@', $action)[0]).'@'.explode('@', $action)[1] : '';
    }
}
