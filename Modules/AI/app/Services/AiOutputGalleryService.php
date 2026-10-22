<?php

namespace Modules\AI\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Models\AiConversationAttachment;
use Modules\AI\Models\AiMessage;

/**
 * The outputs gallery (spec 344): everything DORR AI made for the person — images, videos, voice
 * replies and generated files — in one place, newest first, each with the conversation and message
 * it came from (to jump back to). Only the person's own conversations; only what the assistant made
 * (their own uploads are not "outputs").
 */
class AiOutputGalleryService
{
    public const KINDS = ['image', 'video', 'audio', 'document'];

    private const LIMIT = 60;

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Authenticatable $owner, ?string $kind = null): array
    {
        $mine = fn ($q) => $q->where('owner_type', $owner->getMorphClass())->where('owner_id', $owner->getAuthIdentifier());
        $items = collect();

        if ($kind !== 'document') {
            $attachments = AiConversationAttachment::query()
                ->whereHas('conversation', $mine)
                ->whereHas('message', fn ($q) => $q->where('role', AiMessage::ROLE_ASSISTANT))
                ->with('conversation:id,title')
                ->latest('id')->limit(self::LIMIT)->get();

            foreach ($attachments as $attachment) {
                $type = $this->kindOf((string) $attachment->mime_type);
                if ($type === null || ($kind !== null && $kind !== $type)) {
                    continue;
                }
                $items->push([
                    'kind' => $type,
                    'name' => $attachment->file_name,
                    'mime_type' => $attachment->mime_type,
                    'url' => $attachment->file_path ? Storage::disk('public')->url($attachment->file_path) : null,
                    'conversation' => ['id' => $attachment->conversation?->id, 'title' => $attachment->conversation?->title],
                    'message_id' => $attachment->message_id,
                    'created_at' => $attachment->created_at?->toIso8601String(),
                ]);
            }
        }

        if ($kind === null || $kind === 'document') {
            $messages = AiMessage::query()
                ->where('role', AiMessage::ROLE_ASSISTANT)
                ->whereNotNull('generated_file')
                ->whereHas('conversation', $mine)
                ->with('conversation:id,title')
                ->latest('id')->limit(self::LIMIT)->get();

            foreach ($messages as $message) {
                $file = (array) $message->generated_file;
                if (empty($file['url'])) {
                    continue;
                }
                $items->push([
                    'kind' => 'document',
                    'name' => $file['name'] ?? null,
                    'mime_type' => null,
                    'url' => $file['url'],
                    'conversation' => ['id' => $message->conversation?->id, 'title' => $message->conversation?->title],
                    'message_id' => $message->id,
                    'created_at' => $message->created_at?->toIso8601String(),
                ]);
            }
        }

        return $items->sortByDesc('created_at')->take(self::LIMIT)->values()->all();
    }

    protected function kindOf(string $mime): ?string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            $mime !== '' => 'document',
            default => null,
        };
    }
}
