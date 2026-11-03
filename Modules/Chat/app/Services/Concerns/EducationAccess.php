<?php

namespace Modules\Chat\Services\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Modules\Chat\Enums\GroupKind;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Services\ChatBroadcaster;
use Modules\Chat\Services\GroupLifecycleService;
use Modules\Chat\Support\GroupMedia;
use Modules\Chat\Support\GroupPermissions;
use Modules\Chat\Support\GroupScope;
use Modules\Chat\Support\ParticipantDirectory;
use Spatie\MediaLibrary\HasMedia;

/**
 * Shared by the educational group services (docs/groups_tasks.md GR-013..017): only educational
 * groups; a member of it; items for the whole group or a term / subgroup the viewer is in (all of
 * them for whoever manages that kind of item); permissions checked on the server.
 */
trait EducationAccess
{
    protected function educationMember(Model $me, ChatConversation $conversation): ChatParticipant
    {
        if (! GroupLifecycleService::isKindGroup($conversation) || $conversation->group->kind !== GroupKind::Education) {
            throw new ChatException('education_group_only', 422);
        }

        return GroupScope::member($me, $conversation->id) ?? throw ChatException::notParticipant();
    }

    /** @return list<string> */
    protected function perms(ChatParticipant $member, ChatConversation $conversation): array
    {
        return GroupPermissions::ofParticipant($member, $conversation);
    }

    /** @param list<string> $perms */
    protected function need(array $perms, string $permission): void
    {
        if (! in_array($permission, $perms, true)) {
            throw new ChatException('group_permission_denied', 403);
        }
    }

    /** Only what the viewer may see: the whole group's, their spaces' — everything for a manager. */
    protected function scoped(Builder $query, Model $me, ChatConversation $conversation, bool $manager): Builder
    {
        $query->where('conversation_id', $conversation->id);

        return $manager ? $query : $query->where(fn ($q) => $q->whereNull('space_id')->orWhereIn('space_id', GroupScope::spaceIds($me, $conversation->id)));
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    protected function storeFiles(HasMedia $model, array $files, string $collection): void
    {
        foreach ($files as $file) {
            $model->addMedia($file)->usingName($file->getClientOriginalName())->toMediaCollection($collection, GroupMedia::DISK);
        }
    }

    /** @return list<array<string, mixed>> */
    protected function filesOf(HasMedia $model, string $collection): array
    {
        return $model->getMedia($collection)->map(fn ($m) => ['id' => $m->uuid, 'url' => $m->getUrl(), 'name' => $m->name, 'mime_type' => $m->mime_type, 'size' => $m->size])->values()->all();
    }

    /** @return array{participant_id: int, profile: ?array<string, mixed>}|null */
    protected function person(Model $me, ?int $participantId): ?array
    {
        $row = $participantId === null ? null : ChatParticipant::query()->find($participantId);

        return $row === null ? null : ['participant_id' => $row->id, 'profile' => app(ParticipantDirectory::class)->profile($me, $row->participant_type, $row->participant_id)];
    }

    /** The open apps reload once (never on a timer). */
    protected function educationChanged(ChatConversation $conversation, string $what, string $id): void
    {
        app(ChatBroadcaster::class)->toParticipants($conversation->activeParticipants()->get(), 'chat.group.education', [
            'conversation_id' => $conversation->uuid, 'what' => $what, 'id' => $id,
        ]);
    }
}
