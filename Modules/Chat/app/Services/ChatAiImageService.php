<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\AI\Services\AiTaskRunner;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatSetting;

/**
 * Images with DORR AI inside the chat (spec 50 and 51): a new image from a description, or an
 * image in the chat changed as described — through the AI module's system (AiTaskRunner: the
 * person's AI plan and its daily images, the admin's safety rules, the registered image model).
 *
 * Nothing is sent by itself: the result is a draft only its maker can see, sent to the chat as an
 * ordinary image message — marked `meta.ai_generated` so everyone sees it was made by AI — only
 * when they press send. Drafts are kept for a day.
 */
class ChatAiImageService
{
    private const DRAFT_HOURS = 24;

    private const MAX_PROMPT = 1000;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly ChatAiService $ai,
        private readonly AiTaskRunner $runner,
    ) {}

    /**
     * @return array{draft: string, url: string, kind: string}
     */
    public function generate(Model $me, ChatConversation $conversation, string $prompt): array
    {
        $this->conversations->participantOf($me, $conversation, true);
        $this->assertOn();

        $result = $this->runner->generateImage($this->prompt($prompt), 'chat.image_generate', $me);

        return $this->draft($me, $conversation, $result, 'generate');
    }

    /**
     * An image message in the chat, changed as described.
     *
     * @return array{draft: string, url: string, kind: string}
     */
    public function edit(Model $me, ChatMessage $message, string $prompt): array
    {
        $this->ai->readable($me, $message);
        $this->assertOn();

        $media = $message->type === MessageType::Image ? $message->getFirstMedia(ChatMessage::ATTACHMENTS) : null;
        if ($media === null || ! str_starts_with((string) $media->mime_type, 'image/')) {
            throw new ChatException('ai_not_image', 422);
        }
        $bytes = @file_get_contents($media->getPath());
        if ($bytes === false) {
            throw new ChatException('ai_not_image', 422);
        }

        $result = $this->runner->editImage($bytes, (string) $media->mime_type, $this->prompt($prompt), 'chat.image_edit', $me);

        return $this->draft($me, $message->conversation, $result, 'edit');
    }

    /**
     * Sends a draft to the chat as an image message (with an optional caption), marked as made by AI.
     *
     * @param  array{body?: ?string, uuid?: ?string}  $data
     */
    public function send(Model $me, ChatConversation $conversation, string $draftId, array $data = []): ChatMessage
    {
        $draft = Cache::get($this->key($draftId));
        if (! is_array($draft) || $draft['owner'] !== $this->ownerKey($me) || $draft['conversation_id'] !== $conversation->id || ! Storage::disk('public')->exists($draft['path'])) {
            throw new ChatException('ai_draft_not_found', 404);
        }

        $file = new UploadedFile(Storage::disk('public')->path($draft['path']), basename($draft['path']), $draft['mime'], null, true);
        $message = $this->messages->send($me, $conversation, array_filter([
            'type' => MessageType::Image->value,
            'body' => $data['body'] ?? null,
            'uuid' => $data['uuid'] ?? null,
        ], fn ($v) => $v !== null), [$file]);

        // Everyone in the chat sees it was made with AI.
        $message->forceFill(['meta' => array_merge((array) $message->meta, ['ai_generated' => true, 'ai_kind' => $draft['kind']])])->save();

        Storage::disk('public')->delete($draft['path']);
        Cache::forget($this->key($draftId));

        return $message->refresh();
    }

    /**
     * @param  array{success: bool, reason: ?string, image: ?array{base64: string, mime: string}, message: ?string, detail?: array<string, mixed>}  $result
     * @return array{draft: string, url: string, kind: string}
     */
    private function draft(Model $me, ChatConversation $conversation, array $result, string $kind): array
    {
        if (! $result['success'] || empty($result['image']['base64'])) {
            throw $this->ai->failure($result);
        }

        $this->pruneOldDrafts($me);

        $id = (string) Str::uuid();
        $mime = (string) ($result['image']['mime'] ?? 'image/png');
        $path = $this->folder($me).'/'.$id.($mime === 'image/png' ? '.png' : '.jpg');
        Storage::disk('public')->put($path, base64_decode($result['image']['base64']));

        Cache::put($this->key($id), [
            'owner' => $this->ownerKey($me),
            'conversation_id' => $conversation->id,
            'path' => $path,
            'mime' => $mime,
            'kind' => $kind,
        ], now()->addHours(self::DRAFT_HOURS));

        return ['draft' => $id, 'url' => Storage::disk('public')->url($path), 'kind' => $kind];
    }

    private function prompt(string $prompt): string
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new ChatException('ai_image_prompt', 422);
        }

        return Str::limit($prompt, self::MAX_PROMPT, '');
    }

    private function assertOn(): void
    {
        if (! ChatSetting::current()->aiEnabled() || ! $this->runner->canMakeImages()) {
            throw new ChatException('ai_unavailable', 503);
        }
    }

    /** Drafts older than a day are removed the next time the same person makes one. */
    private function pruneOldDrafts(Model $me): void
    {
        $disk = Storage::disk('public');
        foreach ($disk->files($this->folder($me)) as $file) {
            if ($disk->lastModified($file) < now()->subHours(self::DRAFT_HOURS)->getTimestamp()) {
                $disk->delete($file);
            }
        }
    }

    private function folder(Model $me): string
    {
        return 'chat-ai-drafts/'.$me->getMorphClass().'-'.$me->getKey();
    }

    private function ownerKey(Model $me): string
    {
        return $me->getMorphClass().':'.$me->getKey();
    }

    private function key(string $draftId): string
    {
        return 'chat:ai:image-draft:'.$draftId;
    }
}
