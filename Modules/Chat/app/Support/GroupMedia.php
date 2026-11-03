<?php

namespace Modules\Chat\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Modules\Chat\Enums\GroupKind;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Contracts\ProtectedMediaOwner;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatParticipant;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Files in educational and work groups (and their terms / projects) — docs/groups_tasks.md GR-008:
 * kept on the private disk, never at a public address. Each link is signed for one viewer and
 * lasts an hour or two; every download checks again that the viewer is still a member, so a link
 * saved earlier stops working the moment they lose access (removed, left, group deleted).
 */
final class GroupMedia
{
    public const DISK = 'local';

    /** Files sent here go to the private disk. */
    public static function protects(ChatConversation $conversation): bool
    {
        $group = $conversation->group;

        return $group !== null && (($group->kind ?? GroupKind::Normal) !== GroupKind::Normal || $group->space_of_conversation_id !== null);
    }

    /** The disk for a file sent to this chat ('' = the default public one). */
    public static function diskFor(ChatConversation $conversation): string
    {
        return self::protects($conversation) ? self::DISK : '';
    }

    /**
     * A chat file on the private disk: a signed link for the signed-in viewer (an hour or two —
     * the same link all hour, so the app's image cache still works). Null for public files.
     */
    public static function signedUrl(Media $media): ?string
    {
        if ($media->disk !== self::DISK || ! self::isOurs($media)) {
            return null;
        }

        $viewer = auth()->user();

        return URL::temporarySignedRoute('api.chat.media.protected', now()->startOfHour()->addHours(2), [
            'media' => $media->uuid,
            'v' => $viewer instanceof Model ? ParticipantType::key($viewer) : 'none',
        ]);
    }

    /** A chat message's file, or a group item's (anything that says who may open it). */
    private static function isOurs(Media $media): bool
    {
        if ($media->model_type === (new ChatMessage)->getMorphClass()) {
            return true;
        }
        $class = \Illuminate\Database\Eloquent\Relations\Relation::getMorphedModel($media->model_type) ?? $media->model_type;

        return is_string($class) && class_exists($class) && is_subclass_of($class, ProtectedMediaOwner::class);
    }

    /** Still allowed: an active member of the (not deleted) chat, and the message is still there. */
    public static function viewerMay(Media $media, string $viewerKey): bool
    {
        if (! str_contains($viewerKey, ':')) {
            return false;
        }
        [$type, $id] = explode(':', $viewerKey, 2);

        // A group item's files (task proof, lesson, homework, hand-in): the item decides.
        if ($media->model_type !== (new ChatMessage)->getMorphClass()) {
            $owner = $media->model;
            $account = ParticipantType::modelClassFor($type)::query()->find((int) $id);

            return $owner instanceof ProtectedMediaOwner && $account !== null && $owner->protectedMediaAllows($account);
        } else {
            $message = ChatMessage::query()->find($media->model_id);
            if ($message === null || $message->isGone()) {
                return false;
            }
            $conversationId = $message->conversation_id;
        }

        return $conversationId !== null && ChatConversation::query()->whereKey($conversationId)->exists()
            && ChatParticipant::query()->where('conversation_id', $conversationId)
                ->where('participant_type', $type)->where('participant_id', (int) $id)->whereNull('left_at')->exists();
    }
}
