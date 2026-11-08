<?php

namespace Modules\Freelance\Services\Workroom;

use Illuminate\Http\Request;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelanceWorkroomTask as Task;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Projects\FreelancerProjects;
use Modules\Freelance\Services\Team\ClientTeam;
use Modules\User\Models\User;

/**
 * FS-150a: finds out who is calling a Workroom route and which agreement it is about - one answer for both prefixes, like the progress
 * reports. A client reaches it as the owner (a team member of the owner's is let in, and runs as the owner, by `freelance.project-access`);
 * a freelancer reaches it only for a project they are working on. The Workroom exists while the agreement is active or completed.
 */
class WorkroomAccess
{
    public function __construct(
        private readonly FreelanceFeatures $features,
        private readonly ClientProjects $clientProjects,
        private readonly FreelancerProjects $freelancerProjects,
    ) {}

    public function context(Request $request, string $uuid): WorkroomContext
    {
        $freelancer = $request->user('freelance_api');
        if ($freelancer instanceof Freelancer) {
            $project = $this->freelancerProjects->find($freelancer, $uuid);
            $column = 'freelancer_id';
            $side = Task::FREELANCER;
            $actor = $freelancer;
        } else {
            $client = $request->user('user_api');
            $project = $this->clientProjects->find($client, $uuid);
            $column = 'client_id';
            $side = Task::CLIENT;
            $actor = $client;
        }

        // The switch follows the project's country (its owner's), so the two sides always see the same state.
        if (! $this->features->allows((int) $project->country_id, 'workroom')) {
            throw new FreelanceException('feature_off', 403, ['feature' => 'workroom'], ['feature' => 'workroom']);
        }

        $agreement = Agreement::query()->where('project_id', $project->id)->where($column, $actor->getKey())
            ->whereIn('status', [Agreement::ACTIVE, Agreement::COMPLETED])->latest('id')->firstOrFail();

        return new WorkroomContext($project, $agreement, $side, $actor);
    }

    /**
     * May this person listen to a project's live Workroom channel? Its client, the people of the client's team, the freelancer of its
     * agreement (active or completed), and the people of his team who reach the project (FS-206a). Used by the channel's authorization (routes/channels.php).
     */
    public function listens(mixed $person, string $uuid): bool
    {
        $project = Project::query()->where('uuid', $uuid)->first();
        if ($project === null) {
            return false;
        }
        if ($person instanceof Freelancer) {
            if (Agreement::query()->where('project_id', $project->id)->where('freelancer_id', $person->id)
                ->whereIn('status', [Agreement::ACTIVE, Agreement::COMPLETED])->exists()) {
                return true;
            }
            // FS-206a: somebody of the freelancers' team who reaches the team's project (an Owner, a PM, a member who works on it - D9).
            $teams = app(\Modules\Freelance\Services\FreelancerTeam\TeamProjects::class);
            $agreement = $teams->agreementOf($project);

            return $agreement !== null && in_array($agreement->status, [Agreement::ACTIVE, Agreement::COMPLETED], true) && $teams->access($agreement, $person) !== null;
        }

        return $person instanceof User
            && ((int) $project->client_id === (int) $person->id || app(ClientTeam::class)->activeMember($person, $project) !== null);
    }
}
