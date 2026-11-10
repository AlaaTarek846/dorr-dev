<?php

namespace Modules\Freelance\Services\FreelancerTeam;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Freelance\Events\FreelanceTeamChatChanged;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceDispute as Dispute;
use Modules\Freelance\Models\FreelanceInternalMessage as Message;
use Modules\Freelance\Models\FreelanceInternalRead as Read;
use Modules\Freelance\Models\FreelanceInternalThread as Thread;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\FreelanceTeamMember as Member;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Moderation\Sanctions;
use Throwable;

/**
 * A team's internal chat (FS-150d), apart from the chat with the client: the client never reads it and is never told of it.
 *
 *  - A team has one general thread (made the first time it is listed) and one thread per project it works on, opened by an Owner for a
 *    project whose agreement (active or completed) was signed by one of the team's Owners (D2: the Owner signs for the team).
 *  - The team's active people read and write the general thread; a project's thread is its Owners' and PMs', and a member's only when he
 *    works on that project (D9, FS-206a) - its live signal carries no text and its push goes only to them. Somebody who left reads
 *    nothing any more. A suspended / archived team, or the `teams` switch off in the team's country, leaves the threads to read only.
 *  - Text messages (files come with the team's own space, FS-207); a message sent twice (same `client_msg_id`) is stored once; its sender
 *    takes it back within a few minutes. The text of a message taken back is kept only while its project has a dispute being decided
 *    (open or appealed) - for the staff, never for the team.
 *  - Live to the team over `freelance-team.{team uuid}` and as a push to the others; no polling.
 *  - The staff read a project's threads (never the general one, never another project's) only while that project has a dispute being
 *    decided, with their own permission, every reading logged; the team's Owners are told the first time (FreelanceInternalChatController).
 */
class TeamChat
{
    public const DELETE_WINDOW_MINUTES = 5;

    public const BODY_MAX = 2000;

    /** The disputes the staff are deciding: the internal chat of their project is open to the staff while they are. */
    public const REVIEW_STATUSES = [Dispute::OPEN, Dispute::APPEALED];

    public function __construct(
        private readonly FreelancerTeams $teams,
        private readonly FreelanceFeatures $features,
        private readonly NotificationCenter $notifications,
    ) {}

    // -------------------------------------------------------------- the team and its threads

    /** The team by its uuid when the person is in it now; a team that is not yours looks like it does not exist. */
    public function team(Freelancer $me, string $uuid): FreelanceTeam
    {
        $team = FreelanceTeam::query()->where('uuid', $uuid)->firstOrFail();
        if (! $this->teams->isMember($team, $me)) {
            throw new ModelNotFoundException;
        }

        return $team;
    }

    /** Who hears the team's live channel `freelance-team.{uuid}`: its active people, nobody else (not a client, not somebody who left). */
    public function listens(mixed $person, string $teamUuid): bool
    {
        if (! $person instanceof Freelancer) {
            return false;
        }
        $team = FreelanceTeam::query()->where('uuid', $teamUuid)->first();

        return $team !== null && $this->teams->isMember($team, $person);
    }

    /**
     * @param  Freelancer|null  $viewer  FS-206a / D9: only the threads he may read (a member: the general one and his projects')
     * @return Collection<int, Thread> the general thread first, then the project threads with the newest message first
     */
    public function threads(FreelanceTeam $team, ?Freelancer $viewer = null): Collection
    {
        $this->general($team);

        return Thread::query()->where('team_id', $team->id)->with('project:id,uuid,title')
            ->orderByRaw('project_id IS NOT NULL')->orderByRaw('last_message_at IS NULL')->orderByDesc('last_message_at')->orderByDesc('id')->get()
            ->filter(fn (Thread $t) => $viewer === null || $this->canRead($team, $t, $viewer))->values();
    }

    /** A thread of the team; one the viewer may not read (D9) looks like it does not exist. */
    public function thread(FreelanceTeam $team, string $uuid, ?Freelancer $viewer = null): Thread
    {
        $thread = Thread::query()->where('team_id', $team->id)->where('uuid', $uuid)->with('project:id,uuid,title')->firstOrFail();
        if ($viewer !== null && ! $this->canRead($team, $thread, $viewer)) {
            throw new ModelNotFoundException;
        }

        return $thread;
    }

    /**
     * FS-206a / D9: who of the team reads a thread. The general one: everybody active. A project's: its Owners and PMs, and a member only
     * when he works on that project (one of the people of the team's agreement on it).
     */
    public function canRead(FreelanceTeam $team, Thread $thread, Freelancer $who): bool
    {
        $row = $this->teams->membership($team, $who);
        if ($row === null) {
            return false;
        }
        if ($thread->project_id === null || $row->role !== Member::MEMBER) {
            return true;
        }
        $agreement = Agreement::query()->where('project_id', $thread->project_id)->where('team_id', $team->id)
            ->where('status', '!=', Agreement::DRAFT)->latest('id')->first();

        return $agreement !== null && in_array($who->id, app(TeamProjects::class)->people($agreement), true);
    }

    /** The team's general thread, made the first time. */
    public function general(FreelanceTeam $team): Thread
    {
        $found = Thread::query()->where('team_id', $team->id)->whereNull('project_id')->first();
        if ($found !== null) {
            return $found;
        }

        return DB::transaction(function () use ($team): Thread {
            FreelanceTeam::query()->lockForUpdate()->find($team->id);

            return Thread::query()->where('team_id', $team->id)->whereNull('project_id')->first()
                ?? Thread::query()->create(['team_id' => $team->id, 'project_id' => null]);
        });
    }

    /**
     * The thread about one project, made the first time by an Owner. 403 freelancer_team_forbidden (not an Owner), 409 freelancer_team_inactive,
     * 422 internal_chat_project_invalid (no agreement of the team's Owners on it).
     */
    public function openProject(FreelanceTeam $team, Freelancer $me, string $projectUuid): Thread
    {
        $this->assertWritable($team);
        if (! $this->teams->isOwner($team, $me)) {
            throw new FreelanceException('freelancer_team_forbidden', 403);
        }
        $project = FreelanceProject::query()->where('uuid', $projectUuid)->first();
        $owners = Member::query()->where('team_id', $team->id)->where('status', Member::ACTIVE)->where('role', Member::OWNER)->pluck('freelancer_id');
        // FS-205: an agreement in the team's name, or (before it) one signed by one of its Owners.
        $signed = $project !== null && Agreement::query()->where('project_id', $project->id)
            ->where(fn ($q) => $q->where('team_id', $team->id)->orWhereIn('freelancer_id', $owners))
            ->whereIn('status', [Agreement::ACTIVE, Agreement::COMPLETED])->exists();
        if (! $signed) {
            throw new FreelanceException('internal_chat_project_invalid', 422);
        }

        return DB::transaction(function () use ($team, $me, $project): Thread {
            FreelanceTeam::query()->lockForUpdate()->find($team->id);

            return Thread::query()->where('team_id', $team->id)->where('project_id', $project->id)->first()
                ?? Thread::query()->create(['team_id' => $team->id, 'project_id' => $project->id, 'created_by_freelancer_id' => $me->id]);
        })->load('project:id,uuid,title');
    }

    /** People write while the team is active and the `teams` switch is on in its country. */
    public function canWrite(FreelanceTeam $team): bool
    {
        return $team->isActive() && ! $team->trashed() && $this->features->allows((int) $team->country_id, 'teams');
    }

    /** A project thread whose project has a dispute being decided: the staff may read it now (shown to the team, D: transparency). */
    public function underReview(Thread $thread): bool
    {
        return $thread->project_id !== null && self::reviewable((int) $thread->project_id);
    }

    public static function reviewable(int $projectId): bool
    {
        return Dispute::query()->where('project_id', $projectId)->whereIn('status', self::REVIEW_STATUSES)->exists();
    }

    // -------------------------------------------------------------- messages

    /** @return Collection<int, Message> newest first; `before` is the id to continue from */
    public function messages(Thread $thread, int $limit = 30, ?int $beforeId = null): Collection
    {
        return Message::query()->where('thread_id', $thread->id)->with('sender:id,name')
            ->when($beforeId !== null, fn ($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * 409 freelancer_team_inactive (the team or the switch is closed), 422 chat_empty, 403 from the sanctions (a chat limit stops sending).
     *
     * @return array<string, mixed> the message as the sender sees it
     */
    public function send(FreelanceTeam $team, Thread $thread, Freelancer $me, ?string $body, ?string $clientMsgId): array
    {
        app(Sanctions::class)->assertAllowed($me, 'chat');
        $this->assertWritable($team);
        $text = trim(strip_tags((string) $body));
        if ($text === '') {
            throw new FreelanceException('chat_empty', 422);
        }
        $clientMsgId = $clientMsgId === null || trim($clientMsgId) === '' ? null : mb_substr(trim($clientMsgId), 0, 64);

        try {
            $message = DB::transaction(function () use ($thread, $me, $text, $clientMsgId): Message {
                $message = Message::query()->create([
                    'thread_id' => $thread->id, 'sender_freelancer_id' => $me->id, 'kind' => 'text',
                    'body' => mb_substr($text, 0, self::BODY_MAX), 'client_msg_id' => $clientMsgId, 'created_at' => now(),
                ]);
                $thread->forceFill(['last_message_at' => $message->created_at, 'last_message_preview' => mb_substr($text, 0, 120)])->save();
                // What I wrote, I have read.
                $this->readUpTo($thread, $me, $message->id);

                return $message;
            });
        } catch (QueryException $e) {
            // The same message sent again (a weak network): the one already stored.
            $message = $clientMsgId === null ? null : Message::query()->where('thread_id', $thread->id)->where('sender_freelancer_id', $me->id)->where('client_msg_id', $clientMsgId)->first();
            if ($message === null) {
                throw $e;
            }

            return $this->present($message->load('sender:id,name'), $me);
        }

        $message->load('sender:id,name');
        // FS-206a / D9: the team's channel is heard by all its people, so a project's message goes out without its text - the ones who may
        // read the thread load it once (no polling); the general thread's goes out whole.
        $this->broadcast(new FreelanceTeamChatChanged($team->uuid, $thread->uuid, 'message', $thread->project_id === null ? $this->present($message, null) : null));
        $this->tell($team, $thread, $me, $text);

        return $this->present($message, $me);
    }

    /** Everything up to the newest message is read by this person; the others' screens hear it once. */
    public function markRead(FreelanceTeam $team, Thread $thread, Freelancer $me): int
    {
        $upTo = (int) Message::query()->where('thread_id', $thread->id)->max('id');
        $before = $this->unread($thread, $me);
        $this->readUpTo($thread, $me, $upTo);
        if ($before > 0) {
            $this->broadcast(new FreelanceTeamChatChanged($team->uuid, $thread->uuid, 'read', null, $me->id, $upTo));
        }

        return $before;
    }

    public function unread(Thread $thread, Freelancer $me): int
    {
        $last = (int) Read::query()->where('thread_id', $thread->id)->where('freelancer_id', $me->id)->value('last_read_message_id');

        return Message::query()->where('thread_id', $thread->id)->where('id', '>', $last)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('sender_freelancer_id')->orWhere('sender_freelancer_id', '!=', $me->id))->count();
    }

    /**
     * Its sender takes a message back within a few minutes; it stays in the thread as "deleted". 404 when it is not his (or not this
     * team's), 422 message_not_deletable when it is too late, already deleted, or the team can no longer be written to.
     */
    public function delete(FreelanceTeam $team, Freelancer $me, int $messageId): void
    {
        $message = Message::query()->whereKey($messageId)->whereHas('thread', fn ($q) => $q->where('team_id', $team->id))->with('thread')->first();
        if ($message === null || (int) $message->sender_freelancer_id !== (int) $me->id) {
            throw new ModelNotFoundException;
        }
        if ($message->deleted_at !== null || $message->created_at === null || $message->created_at->lt(now()->subMinutes(self::DELETE_WINDOW_MINUTES)) || ! $this->canWrite($team)) {
            throw new FreelanceException('message_not_deletable', 422);
        }

        $thread = $message->thread;
        // While the staff are deciding a dispute on this project, the text stays for them (never shown to the team again).
        $keep = $thread->project_id !== null && self::reviewable((int) $thread->project_id);
        $message->forceFill(['deleted_at' => now()] + ($keep ? [] : ['body' => null]))->save();
        $this->broadcast(new FreelanceTeamChatChanged($team->uuid, $thread->uuid, 'deleted', $thread->project_id === null ? $this->present($message->load('sender:id,name'), null) : null));
    }

    // -------------------------------------------------------------- the staff

    /**
     * Every internal thread about the dispute's project (of any team), oldest message first, as the staff read it: a message its sender took
     * back shows as deleted, with its text when it was kept. 403 internal_chat_not_open when the dispute is not being decided.
     *
     * @return list<array<string, mixed>>
     */
    public function forDispute(Dispute $dispute, int $perThread = 500): array
    {
        if (! in_array($dispute->status, self::REVIEW_STATUSES, true)) {
            throw new FreelanceException('internal_chat_not_open', 403);
        }

        return Thread::query()->where('project_id', $dispute->project_id)->with('team:id,uuid,name')->orderBy('id')->get()
            ->map(function (Thread $t) use ($perThread): array {
                $messages = Message::query()->where('thread_id', $t->id)->with('sender:id,name')->orderBy('id')->limit($perThread)->get();
                $roles = Member::query()->where('team_id', $t->team_id)->pluck('role', 'freelancer_id');

                return [
                    'uuid' => $t->uuid,
                    'team' => ['uuid' => $t->team?->uuid, 'name' => $t->team?->name],
                    'messages_count' => Message::query()->where('thread_id', $t->id)->count(),
                    'messages' => $messages->map(fn (Message $m): array => [
                        'id' => $m->id,
                        'from' => ['id' => $m->sender_freelancer_id, 'name' => $m->sender?->name, 'role' => $roles[$m->sender_freelancer_id] ?? null],
                        'kind' => $m->kind,
                        'body' => $m->body,
                        'deleted' => $m->deleted_at !== null,
                        'deleted_at' => $m->deleted_at?->toIso8601String(),
                        'at' => $m->created_at?->toIso8601String(),
                    ])->values()->all(),
                ];
            })->values()->all();
    }

    /** The first time the staff read a project's internal chat for a dispute, the team's Owners are told (transparency, TEAMS-ERD §4). */
    public function tellOwnersOfReview(Dispute $dispute): void
    {
        $teamIds = Thread::query()->where('project_id', $dispute->project_id)->pluck('team_id')->unique();
        if ($teamIds->isEmpty()) {
            return;
        }
        $owners = Freelancer::query()->whereIn('id', Member::query()->whereIn('team_id', $teamIds)->where('status', Member::ACTIVE)->where('role', Member::OWNER)->select('freelancer_id'))->get();
        $project = $dispute->project()->first(['id', 'uuid', 'title']);
        $this->notifications->send($owners, 'freelance.internal_chat_reviewed', 'freelance_internal_chat_reviewed_title', 'freelance_internal_chat_reviewed_body',
            ['project' => (string) $project?->title], ['type' => 'freelance_internal_chat_reviewed', 'project' => $project?->uuid, 'dispute' => $dispute->uuid]);
    }

    // -------------------------------------------------------------- shape

    /** @return array<string, mixed> */
    public function present(Message $m, ?Freelancer $viewer): array
    {
        $deleted = $m->deleted_at !== null;

        return [
            'id' => $m->id,
            'from' => ['id' => $m->sender_freelancer_id, 'name' => $m->sender?->name],
            'mine' => $viewer === null ? null : (int) $m->sender_freelancer_id === (int) $viewer->id,
            'kind' => $deleted ? 'deleted' : $m->kind,
            // The text of a message taken back never reaches the team, even when it was kept for the staff.
            'body' => $deleted ? null : $m->body,
            'client_msg_id' => $m->client_msg_id,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentThread(Thread $t, FreelanceTeam $team, Freelancer $viewer): array
    {
        return [
            'uuid' => $t->uuid,
            'general' => $t->isGeneral(),
            'project' => $t->project === null ? null : ['uuid' => $t->project->uuid, 'title' => $t->project->title],
            'unread' => $this->unread($t, $viewer),
            'last_message_preview' => $t->last_message_preview,
            'last_message_at' => $t->last_message_at?->toIso8601String(),
            'can_write' => $this->canWrite($team),
            'under_review' => $this->underReview($t),
        ];
    }

    // -------------------------------------------------------------- inside

    private function assertWritable(FreelanceTeam $team): void
    {
        if (! $this->canWrite($team)) {
            throw new FreelanceException('freelancer_team_inactive', 409);
        }
    }

    private function readUpTo(Thread $thread, Freelancer $me, int $upTo): void
    {
        $row = Read::query()->firstOrNew(['thread_id' => $thread->id, 'freelancer_id' => $me->id]);
        if ((int) $row->last_read_message_id < $upTo) {
            $row->last_read_message_id = $upTo;
            $row->save();
        } elseif (! $row->exists) {
            $row->save();
        }
    }

    /** A push to the team's other active people (not the client, never the text of another team). */
    private function tell(FreelanceTeam $team, Thread $thread, Freelancer $me, string $text): void
    {
        $others = Freelancer::query()->whereIn('id', Member::query()->where('team_id', $team->id)->where('status', Member::ACTIVE)->where('freelancer_id', '!=', $me->id)->select('freelancer_id'))->get()
            // FS-206a / D9: a project's message only to the ones who may read its thread.
            ->filter(fn (Freelancer $f) => $this->canRead($team, $thread, $f))->values();
        $this->notifications->send($others, 'freelance.internal_message', 'freelance_internal_message_title', 'freelance_internal_message_body',
            ['name' => strtok((string) $me->name, ' ') ?: (string) $me->name, 'team' => $team->name, 'text' => mb_substr($text, 0, 120)],
            ['type' => 'freelance_internal_message', 'team' => $team->uuid, 'thread' => $thread->uuid]);
    }

    private function broadcast(object $event): void
    {
        DB::afterCommit(function () use ($event): void {
            try {
                broadcast($event);
            } catch (Throwable $e) {
                Log::warning('[Freelance] team chat broadcast failed: '.$e->getMessage());
            }
        });
    }
}
