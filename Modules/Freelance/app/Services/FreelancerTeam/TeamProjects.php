<?php

namespace Modules\Freelance\Services\FreelancerTeam;

use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceAgreementTeamMember;
use Modules\Freelance\Models\FreelanceOfferTeamMember;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;

/**
 * FS-206a: who of a freelancers' team reaches a team's project, and what each may do on it (PERMISSIONS.md §2). The project stays the
 * signing Owner's (D2): somebody of the team who uses one of its routes acts for him (FreelancerProjectTeamAccess), and every record row
 * keeps who really acted.
 *
 *  - Any active Owner of the team: everything the signing Owner does on the work - the delivery included (D11: the Owner delivers; any of
 *    the team's Owners) - except what is the signer's own: approving or declining the agreement (his signature) and his rating.
 *  - A PM: every project of the team; reads it, works in its Workroom, starts a part, sends a progress report.
 *  - A member: only a project he works on (D9) - he is one of the agreement's people (FS-205; his parts and tasks come with FS-206b); reads
 *    it and works in its Workroom.
 *  - Amounts only with the `view_price` switch (a PM has it by default, a member not); the commission and the net are the Owners' only.
 *  - Nobody but an Owner delivers, takes a part back, answers a cancellation or a change, opens a dispute - whatever is switched on (D11).
 *
 * A team that is suspended or archived, or where the `teams` switch is off, lets nobody in but the signer.
 */
class TeamProjects
{
    public const OWNER = 'owner';

    public const PM = 'pm';

    public const MEMBER = 'member';

    // What a route needs.
    public const VIEW = 'view';

    /** The amounts: prices, what is held, the money lines. */
    public const MONEY = 'money';

    /** The commission and the net (what the signing Owner is paid). */
    public const NET = 'net';

    public const WORKROOM = 'workroom';

    public const START = 'start';

    public const REPORT = 'report';

    public const DELIVER = 'deliver';

    /** FS-206b: putting people of the team on a part or a task. */
    public const ASSIGN = 'assign';

    /** Taking a part back, answering a cancellation, a change or a dispute, the portfolio request - decisions on the work and its money. */
    public const MANAGE = 'manage';

    /** A route anybody uses for himself (his own offer, his favourites): never run for somebody else. */
    public const PERSONAL = 'personal';

    public const ROLES = [
        self::OWNER => [self::VIEW, self::MONEY, self::NET, self::WORKROOM, self::START, self::REPORT, self::DELIVER, self::MANAGE, self::ASSIGN],
        self::PM => [self::VIEW, self::WORKROOM, self::START, self::REPORT, self::ASSIGN],
        self::MEMBER => [self::VIEW, self::WORKROOM],
    ];

    /**
     * Controller@method => what it needs, or [route parameter => [value => what it needs]] for one method doing several things. A project
     * route not listed is the signer's alone (approving the agreement, his rating): nobody else may use it - the default is "no".
     *
     * @var array<string, string|array<string, array<string, string>>>
     */
    private const ROUTES = [
        'FreelancerProjectController@show' => self::VIEW,
        'FreelancerProjectController@favorite' => self::PERSONAL,
        'FreelancerProjectController@unfavorite' => self::PERSONAL,
        'FreelancerOfferTemplateController@check' => self::PERSONAL,
        'FreelancerOfferController@preview' => self::PERSONAL,
        'FreelancerOfferController@mine' => self::PERSONAL,
        'FreelancerOfferController@store' => self::PERSONAL,
        'FreelancerAgreementController@show' => self::VIEW,
        'FreelancerAgreementController@milestoneAction' => ['action' => [
            'start' => self::START, 'deliver' => self::DELIVER, 'withdraw' => self::MANAGE, 'cancel-accept' => self::MANAGE, 'cancel-reject' => self::MANAGE,
        ]],
        'MilestoneAssigneeController@index' => self::VIEW,
        'MilestoneAssigneeController@update' => self::ASSIGN,
        'FreelancerRightsController@askPortfolio' => self::MANAGE,
        'MilestoneDeliveryController@index' => self::VIEW,
        'MilestoneDeliveryController@file' => self::VIEW,
        'DisputeController@open' => self::MANAGE,
        'DisputeController@show' => self::VIEW,
        'DisputeController@file' => self::VIEW,
        'DisputeController@evidence' => self::MANAGE,
        'DisputeController@appeal' => self::MANAGE,
        'ReviewController@show' => self::VIEW,
        'ApprovalTrailController@index' => self::VIEW,
        'ChangeRequestController@index' => self::VIEW,
        'ChangeRequestController@file' => self::VIEW,
        'ChangeRequestController@store' => self::MANAGE,
        'ChangeRequestController@answer' => ['action' => ['accept' => self::MANAGE, 'reject' => self::MANAGE, 'withdraw' => self::MANAGE]],
        'ProgressReportController@index' => self::VIEW,
        'ProgressReportController@store' => self::REPORT,
        'WorkroomTaskController@index' => self::VIEW,
        'WorkroomTaskController@store' => self::WORKROOM,
        'WorkroomTaskController@update' => self::WORKROOM,
        'WorkroomTaskController@status' => self::WORKROOM,
        'WorkroomTaskController@destroy' => self::WORKROOM,
        'WorkroomFileController@index' => self::VIEW,
        'WorkroomFileController@show' => self::VIEW,
        'WorkroomFileController@download' => self::VIEW,
        'WorkroomFileController@store' => self::WORKROOM,
        'WorkroomFileController@update' => self::WORKROOM,
        'WorkroomFileController@addVersion' => self::WORKROOM,
        'WorkroomFileController@destroy' => self::WORKROOM,
        'WorkroomCommentController@index' => self::VIEW,
        'WorkroomCommentController@store' => self::WORKROOM,
        'WorkroomCommentController@update' => self::WORKROOM,
        'WorkroomCommentController@destroy' => self::WORKROOM,
        'WorkroomDecisionController@index' => self::VIEW,
        'WorkroomDecisionController@show' => self::VIEW,
        'WorkroomDecisionController@store' => self::WORKROOM,
        'WorkroomDecisionController@update' => self::WORKROOM,
        'WorkroomDecisionController@agree' => self::WORKROOM,
        'WorkroomDecisionController@decline' => self::WORKROOM,
        'WorkroomDecisionController@destroy' => self::WORKROOM,
        'WorkroomTimelineController@index' => self::VIEW,
    ];

    /**
     * The `can` flags the apps draw buttons from, and what each needs. A flag not listed (approving the agreement, the rating) is off for
     * everybody but the signer.
     *
     * @var array<string, string>
     */
    public const CAN = [
        'start' => self::START,
        'deliver' => self::DELIVER,
        'withdraw' => self::MANAGE,
        'cancel_accept' => self::MANAGE,
        'cancel_reject' => self::MANAGE,
        'dispute' => self::MANAGE,
        'accept' => self::MANAGE,
        'reject' => self::MANAGE,
        'evidence' => self::MANAGE,
        'appeal' => self::MANAGE,
        'report' => self::REPORT,
        'assign' => self::ASSIGN,
        'task_create' => self::WORKROOM,
        'task_edit' => self::WORKROOM,
        'task_status' => self::WORKROOM,
        'task_delete' => self::WORKROOM,
        'file_upload' => self::WORKROOM,
        'file_edit' => self::WORKROOM,
        'file_delete' => self::WORKROOM,
        'version_add' => self::WORKROOM,
        'comment_add' => self::WORKROOM,
        'comment_edit' => self::WORKROOM,
        'comment_delete' => self::WORKROOM,
        'decision_create' => self::WORKROOM,
        'decision_edit' => self::WORKROOM,
        'decision_withdraw' => self::WORKROOM,
        'decision_agree' => self::WORKROOM,
        'decision_decline' => self::WORKROOM,
        'decision_supersede' => self::WORKROOM,
    ];

    public function __construct(
        private readonly FreelancerTeams $teams,
        private readonly TeamAbilities $abilities,
        private readonly FreelanceFeatures $features,
    ) {}

    /** What a project route needs from somebody of the team: an ability, PERSONAL, or null (the signer's alone). */
    public static function forRoute(?Route $route): ?string
    {
        $action = (string) $route?->getActionName();
        if (! str_contains($action, '@')) {
            return null;
        }
        [$class, $method] = explode('@', $action, 2);
        $rule = self::ROUTES[class_basename($class).'@'.$method] ?? null;
        if (is_array($rule)) {
            foreach ($rule as $param => $map) {
                return $map[(string) ($route->parameter($param) ?? $route->defaults[$param] ?? '')] ?? null;
            }

            return null;
        }

        return $rule;
    }

    /** @return list<string> every Controller@method the map knows (for the test that every project route is listed) */
    public static function mapped(): array
    {
        return array_keys(self::ROUTES);
    }

    /** The project's agreement when it is a team's and past the draft (a draft is the client's still), else null. */
    public function agreementOf(Project $project): ?Agreement
    {
        $a = Agreement::query()->where('project_id', $project->id)->latest('id')->first();

        return $a !== null && $a->team_id !== null && $a->status !== Agreement::DRAFT ? $a : null;
    }

    /**
     * The place of somebody of the team on a team's agreement: his role, his row and what he may do - or null when he may not reach it
     * (not in the team, a member who does not work on it, a team not active, `teams` off). The signer is not asked here: it is his.
     *
     * @return array{role: string, member: Member, abilities: list<string>, team: FreelanceTeam}|null
     */
    public function access(Agreement $agreement, Freelancer $who): ?array
    {
        $team = $agreement->team_id === null ? null : FreelanceTeam::query()->find($agreement->team_id);
        if ($team === null || ! $team->isActive() || ! $this->features->allows((int) $team->country_id, 'teams')) {
            return null;
        }
        $row = $this->teams->membership($team, $who);
        if ($row === null) {
            return null;
        }
        $role = match ($row->role) {
            Member::OWNER => self::OWNER,
            Member::PM => self::PM,
            default => self::MEMBER,
        };
        // D9: a member reaches only the projects he works on.
        if ($role === self::MEMBER && ! in_array($who->id, $this->people($agreement), true)) {
            return null;
        }
        $abilities = self::ROLES[$role];
        if ($role !== self::OWNER && ($this->abilities->switches($row)[TeamAbilities::VIEW_PRICE] ?? false)) {
            $abilities[] = self::MONEY;
        }

        return ['role' => $role, 'member' => $row, 'abilities' => $abilities, 'team' => $team];
    }

    /** FS-206b: a member starts the part he is put on, though a plain member may not start a part. */
    public function startsOwn(Agreement $agreement, Freelancer $who, ?string $milestoneUuid): bool
    {
        return $milestoneUuid !== null && app(TeamAssignments::class)->isOnMilestone($agreement, $who, $milestoneUuid);
    }

    /** May this person (the signer, or somebody of the team with this ability) use the project? */
    public function allows(Agreement $agreement, Freelancer $who, string $ability = self::VIEW): bool
    {
        if ((int) $agreement->freelancer_id === (int) $who->id) {
            return true;
        }
        $access = $this->access($agreement, $who);

        return $access !== null && in_array($ability, $access['abilities'], true);
    }

    /**
     * The people who work on an agreement: frozen when it started, the offer's until then (FS-205).
     *
     * @return list<int> their freelancer ids
     */
    public function people(Agreement $agreement): array
    {
        $ids = FreelanceAgreementTeamMember::query()->where('agreement_id', $agreement->id)->pluck('freelancer_id');
        if ($ids->isEmpty() && $agreement->offer_id !== null && $agreement->status !== Agreement::ACTIVE && $agreement->status !== Agreement::COMPLETED) {
            $ids = FreelanceOfferTeamMember::query()->where('offer_id', $agreement->offer_id)->pluck('freelancer_id');
        }

        // FS-206b: whoever is put on a part or a task reaches the project too.
        return $ids->map(fn ($id) => (int) $id)->merge(app(TeamAssignments::class)->involved($agreement))->unique()->values()->all();
    }

    /**
     * The team's projects this person reaches (Owner and PM: all of them; a member: the ones he works on - D9), newest first.
     *
     * @return Collection<int, array{agreement: Agreement, role: string, abilities: list<string>}>
     */
    public function of(FreelanceTeam $team, Freelancer $who): Collection
    {
        return Agreement::query()->where('team_id', $team->id)->where('status', '!=', Agreement::DRAFT)
            ->with(['project:id,uuid,title,status,currency_code', 'freelancer:id,uuid,name'])->latest('id')->get()
            // The project's latest agreement only (one the client ended and gave to somebody else is not the team's any more).
            ->filter(fn (Agreement $a) => $this->agreementOf($a->project)?->id === $a->id)
            ->map(function (Agreement $a) use ($who) {
                if ((int) $a->freelancer_id === (int) $who->id) {
                    return ['agreement' => $a, 'role' => self::OWNER, 'abilities' => self::ROLES[self::OWNER], 'signer' => true];
                }
                $access = $this->access($a, $who);

                return $access === null ? null : ['agreement' => $a, 'role' => $access['role'], 'abilities' => $access['abilities'], 'signer' => false];
            })->filter()->values();
    }

    /**
     * What the apps are told of the viewer's place on a team's project (`team_access` on its pages).
     *
     * @param  list<string>  $abilities
     * @return array<string, mixed>
     */
    public static function describe(FreelanceTeam $team, string $role, array $abilities, bool $signer): array
    {
        return ['team' => ['uuid' => $team->uuid, 'name' => $team->name], 'role' => $role, 'signer' => $signer, 'abilities' => array_values($abilities)];
    }
}
