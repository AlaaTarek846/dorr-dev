<?php

namespace Modules\Freelance\Services\Team;

use Illuminate\Routing\Route;
use Modules\Freelance\Models\FreelanceProjectMember as Member;

/**
 * FS-104 / FS-003: what each role of a client's project team may do (docs/modules/freelance/PERMISSIONS.md) and which ability each
 * project route needs. A route that is not listed here is the owner's alone — the default is "no".
 */
final class ClientTeamAbilities
{
    /** Seeing the project, its agreement, parts, deliveries, reports, health and record. */
    public const VIEW = 'view';

    /** The amounts: prices, fees, what is held, the budget. */
    public const MONEY = 'money';

    public const OFFERS = 'offers';

    public const SHORTLIST = 'shortlist';

    public const REVIEW = 'review';

    public const CHECKLIST_EDIT = 'checklist_edit';

    public const REVISION = 'revision';

    public const CHANGE_REQUEST = 'change_request';

    /** FS-150a / FS-150b: adding and changing the Workroom's tasks, files and notes for the client side (reading them is `view`). */
    public const WORKROOM = 'workroom';

    /** The three a delegate does within his limit and time. */
    public const CHANGE_ANSWER = 'change_answer';

    public const FUND = 'fund';

    public const RELEASE = 'release';

    public const DELEGATED = [self::CHANGE_ANSWER, self::FUND, self::RELEASE];

    public const ROLES = [
        'manager' => [self::VIEW, self::OFFERS, self::SHORTLIST, self::REVIEW, self::CHECKLIST_EDIT, self::REVISION, self::CHANGE_REQUEST, self::WORKROOM],
        'reviewer' => [self::VIEW, self::REVIEW, self::CHECKLIST_EDIT, self::REVISION],
        'collaborator' => [self::VIEW],
        'finance' => [self::VIEW, self::MONEY, self::OFFERS],
        'delegate' => [self::VIEW, self::MONEY, self::OFFERS, self::REVIEW, self::CHANGE_ANSWER, self::FUND, self::RELEASE],
    ];

    /**
     * Controller@method => ability, or [route parameter => [value => ability]] for one method doing several things.
     *
     * @var array<string, string|array<string, array<string, string>>>
     */
    private const ROUTES = [
        'ClientProjectController@show' => self::VIEW,
        'ClientProjectController@preview' => self::VIEW,
        'ClientProjectController@attachment' => self::VIEW,
        'ClientProjectController@offers' => self::OFFERS,
        'ClientProjectController@compareOffers' => self::OFFERS,
        'ClientProjectController@showOffer' => self::OFFERS,
        'ClientProjectController@offerFreelancer' => self::OFFERS,
        'ClientProjectController@offerAttachment' => self::OFFERS,
        'ClientProjectController@decideOffer' => ['action' => ['shortlist' => self::SHORTLIST, 'unshortlist' => self::SHORTLIST]],
        'ClientAgreementController@show' => self::VIEW,
        'ClientAgreementController@milestoneAction' => ['action' => ['fund' => self::FUND, 'approve' => self::RELEASE, 'revision' => self::REVISION]],
        'MilestoneDeliveryController@index' => self::VIEW,
        'MilestoneDeliveryController@file' => self::VIEW,
        'MilestoneDeliveryController@setChecklist' => self::CHECKLIST_EDIT,
        'MilestoneDeliveryController@tick' => self::REVIEW,
        'DisputeController@show' => self::VIEW,
        'DisputeController@file' => self::VIEW,
        'ReviewController@show' => self::VIEW,
        'ApprovalTrailController@index' => self::VIEW,
        'ChangeRequestController@index' => self::VIEW,
        'ChangeRequestController@file' => self::VIEW,
        'ChangeRequestController@store' => self::CHANGE_REQUEST,
        'ChangeRequestController@answer' => ['action' => ['accept' => self::CHANGE_ANSWER, 'reject' => self::CHANGE_ANSWER, 'withdraw' => self::CHANGE_REQUEST]],
        'ProgressReportController@index' => self::VIEW,
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
     * The `can` flags the apps draw buttons from, and the ability each needs for a member. A flag not listed is off for every member.
     *
     * @var array<string, string>
     */
    public const CAN = [
        'fund' => self::FUND,
        'approve' => self::RELEASE,
        'revision' => self::REVISION,
        'checklist_edit' => self::CHECKLIST_EDIT,
        'checklist_tick' => self::REVIEW,
        'accept' => self::CHANGE_ANSWER,
        'reject' => self::CHANGE_ANSWER,
        'withdraw' => self::CHANGE_REQUEST,
        'shortlist' => self::SHORTLIST,
        'unshortlist' => self::SHORTLIST,
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

    /** The ability a project route needs from a member, or null when only the owner may use it. */
    public static function forRoute(Route $route): ?string
    {
        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            return null;
        }
        [$class, $method] = explode('@', $action, 2);
        $rule = self::ROUTES[class_basename($class).'@'.$method] ?? null;
        if (is_array($rule)) {
            foreach ($rule as $param => $map) {
                $value = (string) ($route->parameter($param) ?? $route->defaults[$param] ?? '');

                return $map[$value] ?? null;
            }

            return null;
        }

        return $rule;
    }

    /** @return list<string> what a member may do now (a delegate past his date keeps the rest) */
    public static function of(Member $member): array
    {
        $abilities = self::ROLES[$member->role] ?? [];

        return $member->delegationOver() ? array_values(array_diff($abilities, self::DELEGATED)) : $abilities;
    }

    public static function allows(Member $member, string $ability): bool
    {
        return in_array($ability, self::of($member), true);
    }

    /** @return list<string> every ability, for the owner */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::ROLES))));
    }
}
