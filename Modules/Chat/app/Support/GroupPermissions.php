<?php

namespace Modules\Chat\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Chat\Enums\GroupKind;
use Modules\Chat\Enums\ParticipantRole;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupMemberRole;
use Modules\Chat\Models\ChatParticipant;

/**
 * What a member of an educational or work group may do (docs/groups_tasks.md GR-004, spec §07):
 * only through the roles they hold, in the whole group or in one space — never given to one
 * person directly — and checked on the server for every action. The owner may do everything.
 *
 * The keys cover the scenarios in the concept doc; the final list comes with the Requirements.
 */
final class GroupPermissions
{
    /** Both kinds. */
    public const COMMON = ['members.manage', 'roles.manage', 'spaces.manage', 'announcements.post', 'files.upload', 'reports.view', 'audit.view'];

    public const EDUCATION = [
        'lessons.manage', 'assignments.manage', 'assignments.submit', 'grading',
        'quizzes.manage', 'quizzes.take', 'attendance.manage', 'parents.link',
    ];

    public const WORK = ['tasks.create', 'tasks.assign', 'tasks.approve', 'tasks.work', 'projects.manage', 'shifts.manage'];

    /**
     * The roles a new group starts with: [template => permissions]. The first is the leader (the
     * group's maker gets it); `default` is what everyone who joins gets.
     *
     * @return array{leader: string, default: string, roles: array<string, list<string>>}
     */
    public static function templates(GroupKind $kind): array
    {
        return match ($kind) {
            GroupKind::Education => [
                'leader' => 'teacher',
                'default' => 'student',
                'roles' => [
                    'teacher' => self::catalogue($kind),
                    'assistant' => ['announcements.post', 'files.upload', 'lessons.manage', 'attendance.manage', 'grading'],
                    'grader' => ['grading'],
                    'moderator' => ['members.manage', 'announcements.post'],
                    'student' => ['files.upload', 'assignments.submit', 'quizzes.take'],
                ],
            ],
            GroupKind::Work => [
                'leader' => 'manager',
                'default' => 'member',
                'roles' => [
                    'manager' => self::catalogue($kind),
                    'supervisor' => ['announcements.post', 'files.upload', 'reports.view', 'tasks.create', 'tasks.assign', 'tasks.approve'],
                    'moderator' => ['members.manage', 'announcements.post'],
                    'member' => ['files.upload', 'tasks.create', 'tasks.work'],
                ],
            ],
            GroupKind::Normal => ['leader' => '', 'default' => '', 'roles' => []],
        };
    }

    /** @return list<string> every permission a role in this kind of group may hold */
    public static function catalogue(GroupKind $kind): array
    {
        return match ($kind) {
            GroupKind::Education => [...self::COMMON, ...self::EDUCATION],
            GroupKind::Work => [...self::COMMON, ...self::WORK],
            GroupKind::Normal => [],
        };
    }

    /**
     * Everything I may do here — in the whole group, plus in `$spaceId` when given. Nothing when
     * I'm not an active member (left, removed): access stops at once.
     *
     * @return list<string>
     */
    public static function of(Model $account, ChatConversation $conversation, ?int $spaceId = null): array
    {
        $participant = ChatParticipant::query()->where('conversation_id', $conversation->id)->of($account)->whereNull('left_at')->first();

        return $participant === null ? [] : self::ofParticipant($participant, $conversation, $spaceId);
    }

    /** @return list<string> */
    public static function ofParticipant(ChatParticipant $participant, ChatConversation $conversation, ?int $spaceId = null): array
    {
        $kind = $conversation->group?->kind ?? GroupKind::Normal;

        if ($participant->left_at !== null || $kind === GroupKind::Normal) {
            return [];
        }
        if ($participant->role === ParticipantRole::Owner) {
            return self::catalogue($kind);
        }

        return ChatGroupMemberRole::query()->where('participant_id', $participant->id)
            ->where(fn ($q) => $q->whereNull('space_id')->when($spaceId !== null, fn ($q) => $q->orWhere('space_id', $spaceId)))
            ->with('role')->get()
            ->flatMap(fn (ChatGroupMemberRole $m) => (array) ($m->role?->permissions ?? []))
            ->unique()->values()->all();
    }

    public static function allows(Model $account, ChatConversation $conversation, string $permission, ?int $spaceId = null): bool
    {
        return in_array($permission, self::of($account, $conversation, $spaceId), true);
    }
}
