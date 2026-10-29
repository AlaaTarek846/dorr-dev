<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatGroupAuditLog;
use Modules\Chat\Models\ChatGroupLesson;
use Modules\Chat\Models\ChatGroupSpace;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Services\Concerns\EducationAccess;
use Modules\Chat\Support\GroupScope;
use Modules\Chat\Support\SharedMedia;

/**
 * Lessons and materials in educational groups (docs/groups_tasks.md GR-013): for the whole group or
 * one term / subgroup; files on the private disk; a message (and its files) from the chat can be
 * added to a lesson. Outside its scope, nobody sees a lesson, its files or it in a search.
 * Managing takes `lessons.manage`.
 */
class GroupLessonService
{
    use EducationAccess;

    public const PER_PAGE = 15;

    /**
     * @return array{lessons: list<array<string, mixed>>, has_more: bool}
     */
    public function list(Model $me, ChatConversation $conversation, ?string $search, ?int $spaceId, int $page): array
    {
        $member = $this->educationMember($me, $conversation);
        $manager = in_array('lessons.manage', $this->perms($member, $conversation), true);

        $query = $this->scoped(ChatGroupLesson::query(), $me, $conversation, $manager);
        if (filled($search)) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
            $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('body', 'like', $like));
        }
        if ($spaceId !== null) {
            $query->where('space_id', $spaceId);
        }

        $rows = $query->latest('id')->forPage(max(1, $page), self::PER_PAGE)->limit(self::PER_PAGE + 1)->with('media')->get();

        return [
            'lessons' => $rows->take(self::PER_PAGE)->map(fn ($l) => $this->present($me, $l, $manager))->values()->all(),
            'has_more' => $rows->count() > self::PER_PAGE,
        ];
    }

    /** @return array<string, mixed> */
    public function show(Model $me, ChatConversation $conversation, string $uuid): array
    {
        $member = $this->educationMember($me, $conversation);

        return $this->present($me, $this->lesson($me, $conversation, $uuid), in_array('lessons.manage', $this->perms($member, $conversation), true));
    }

    /**
     * @param  array{title: string, body?: ?string, space_id?: ?int}  $data
     * @param  list<UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function create(Model $me, ChatConversation $conversation, array $data, array $files): array
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'lessons.manage');
        GroupLifecycleService::assertWritable($conversation);
        $space = GroupScope::space($conversation, $data['space_id'] ?? null);

        $lesson = DB::transaction(function () use ($me, $conversation, $member, $data, $space, $files) {
            $lesson = ChatGroupLesson::query()->create([
                'conversation_id' => $conversation->id,
                'space_id' => $space?->id,
                'title' => trim($data['title']),
                'body' => $data['body'] ?? null,
                'created_by_participant_id' => $member->id,
            ]);
            $this->storeFiles($lesson, $files, ChatGroupLesson::MATERIALS);
            ChatGroupAuditLog::record($conversation, $me, 'lesson_created', 'lesson:'.$lesson->uuid);

            return $lesson;
        });
        $this->educationChanged($conversation, 'lesson', $lesson->uuid);

        return $this->present($me, $lesson->refresh(), true);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files  added to the lesson's files
     * @return array<string, mixed>
     */
    public function update(Model $me, ChatConversation $conversation, string $uuid, array $data, array $files): array
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'lessons.manage');
        $lesson = $this->lesson($me, $conversation, $uuid);

        $changes = array_intersect_key($data, array_flip(['title', 'body']));
        if (array_key_exists('space_id', $data)) {
            $changes['space_id'] = GroupScope::space($conversation, $data['space_id'])?->id;
        }
        $lesson->update($changes);
        $this->storeFiles($lesson, $files, ChatGroupLesson::MATERIALS);
        foreach ((array) ($data['remove_files'] ?? []) as $fileId) {
            $lesson->getMedia(ChatGroupLesson::MATERIALS)->firstWhere('uuid', $fileId)?->delete();
        }
        ChatGroupAuditLog::record($conversation, $me, 'lesson_updated', 'lesson:'.$lesson->uuid);
        $this->educationChanged($conversation, 'lesson', $lesson->uuid);

        return $this->present($me, $lesson->refresh(), true);
    }

    public function delete(Model $me, ChatConversation $conversation, string $uuid): void
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'lessons.manage');
        $lesson = $this->lesson($me, $conversation, $uuid);
        ChatGroupAuditLog::record($conversation, $me, 'lesson_deleted', 'lesson:'.$lesson->uuid, ['title' => $lesson->title]);
        $lesson->delete();
        $this->educationChanged($conversation, 'lesson', $uuid);
    }

    /**
     * A message from the chat (of this group or a term / subgroup the teacher is in) into a lesson:
     * its words appended, its files added.
     *
     * @return array<string, mixed>
     */
    public function addMessage(Model $me, ChatConversation $conversation, string $uuid, string $messageUuid): array
    {
        $member = $this->educationMember($me, $conversation);
        $this->need($this->perms($member, $conversation), 'lessons.manage');
        $lesson = $this->lesson($me, $conversation, $uuid);

        $mineIn = ChatParticipant::query()->of($me)->whereNull('left_at')->pluck('conversation_id')->all();
        $allowed = array_intersect([$conversation->id, ...ChatGroupSpace::query()->where('parent_conversation_id', $conversation->id)->pluck('conversation_id')->all()], $mineIn);
        $message = ChatMessage::query()->where('uuid', $messageUuid)->whereIn('conversation_id', $allowed)->with('media')->first();
        if ($message === null || $message->isGone() || $message->sender_type === null) {
            throw new ChatException('message_not_found', 404);
        }

        DB::transaction(function () use ($lesson, $message) {
            if (filled($message->body)) {
                $lesson->update(['body' => trim(($lesson->body ? $lesson->body."\n\n" : '').$message->body)]);
            }
            foreach ($message->getMedia(ChatMessage::ATTACHMENTS) as $media) {
                SharedMedia::attach($media, $lesson, ChatGroupLesson::MATERIALS);
            }
        });
        $this->educationChanged($conversation, 'lesson', $lesson->uuid);

        return $this->present($me, $lesson->refresh(), true);
    }

    private function lesson(Model $me, ChatConversation $conversation, string $uuid): ChatGroupLesson
    {
        $lesson = ChatGroupLesson::query()->where('conversation_id', $conversation->id)->where('uuid', $uuid)->with('media')->first();

        if ($lesson === null || ! GroupScope::canSee($me, $conversation->id, $lesson->space_id, 'lessons.manage')) {
            throw new ChatException('lesson_not_found', 404);
        }

        return $lesson;
    }

    /** @return array<string, mixed> */
    private function present(Model $me, ChatGroupLesson $lesson, bool $manager): array
    {
        return [
            'id' => $lesson->uuid,
            'title' => $lesson->title,
            'body' => $lesson->body,
            'space' => $lesson->space_id === null ? null : ['id' => $lesson->space_id, 'name' => ChatGroupSpace::query()->find($lesson->space_id)?->conversation?->group?->name],
            'files' => $this->filesOf($lesson, ChatGroupLesson::MATERIALS),
            'created_by' => $this->person($me, $lesson->created_by_participant_id),
            'can_manage' => $manager,
            'created_at' => $lesson->created_at?->toIso8601String(),
            'updated_at' => $lesson->updated_at?->toIso8601String(),
        ];
    }
}
