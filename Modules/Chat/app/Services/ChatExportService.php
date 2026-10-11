<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Http\Resources\MessageResource;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Support\MessageViewContext;
use ZipArchive;

/**
 * A chat exported (backlog 25): everything I can still see, one line each, as a .txt — or a
 * .zip with the .txt and the files. View-once and sensitive messages never leave as content.
 */
class ChatExportService
{
    /** The files that go in a .zip, at most (the rest are named in the text). */
    public const MEDIA_BYTES = 300 * 1024 * 1024;

    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
    ) {}

    /**
     * @return array{path: string, name: string, mime: string}
     */
    public function export(Model $me, ChatConversation $conversation, bool $withMedia): array
    {
        $participant = $this->conversations->participantOf($me, $conversation);
        $timezone = $me->getAttribute('timezone') ?: config('app.timezone');
        $lines = [];
        $files = [];
        $budget = self::MEDIA_BYTES;

        $this->messages->visibleTo($participant, $conversation->messages()->getQuery())
            ->with('media')->orderBy('id')
            ->chunkById(200, function ($chunk) use ($me, $participant, $conversation, $timezone, $withMedia, &$lines, &$files, &$budget) {
                $context = MessageViewContext::build($me, $participant, $conversation->loadViewParticipants($participant), $chunk);
                foreach ($chunk as $message) {
                    $row = (new MessageResource($message, $context))->resolve();
                    $when = Carbon::parse($message->created_at)->timezone($timezone)->format('Y-m-d H:i');
                    $who = $row['system'] !== null ? '' : (($row['sender']['name'] ?? '') ?: '?').': ';
                    $text = $this->textOf($message, $row);

                    if ($withMedia && ! $message->view_once && ! $message->is_sensitive && ! $message->isGone()) {
                        foreach ($message->getMedia(ChatMessage::ATTACHMENTS) as $media) {
                            $path = $media->getPath();
                            if ($budget - $media->size >= 0 && is_file($path)) {
                                $name = $message->id.'-'.Str::slug(pathinfo((string) $media->name, PATHINFO_FILENAME) ?: 'file').'.'.pathinfo($path, PATHINFO_EXTENSION);
                                $files[$name] = $path;
                                $budget -= $media->size;
                                $text .= ' <'.__('chat.export.attached', ['name' => $name]).'>';
                            }
                        }
                    }
                    $lines[] = "[{$when}] {$who}{$text}";
                }
            });

        if ($lines === []) {
            throw new ChatException('export_empty', 422);
        }

        $title = Str::slug((string) ($conversation->group?->name ?? 'chat')) ?: 'chat';
        $base = 'DORR-'.$title.'-'.now()->format('Y-m-d');
        $dir = storage_path('app/chat-exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $txt = $dir.'/'.Str::uuid().'.txt';
        file_put_contents($txt, "\u{FEFF}".implode("\n", $lines)."\n");

        if (! $withMedia) {
            return ['path' => $txt, 'name' => $base.'.txt', 'mime' => 'text/plain; charset=utf-8'];
        }

        $zipPath = $dir.'/'.Str::uuid().'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFile($txt, $base.'.txt');
        foreach ($files as $name => $path) {
            $zip->addFile($path, $name);
        }
        $zip->close();
        @unlink($txt);

        return ['path' => $zipPath, 'name' => $base.'.zip', 'mime' => 'application/zip'];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function textOf(ChatMessage $message, array $row): string
    {
        if ($row['system'] !== null) {
            return (string) ($row['system']['text'] ?? '');
        }
        if ($message->isGone()) {
            return __('chat.export.deleted');
        }
        if ($message->view_once) {
            return __('chat.preview.view_once');
        }
        if ($message->is_sensitive) {
            return __('chat.export.sensitive');
        }

        $body = trim((string) ($row['body'] ?? ''));
        $meta = (array) ($message->meta ?? []);
        $label = match ($message->type) {
            MessageType::Text => null,
            MessageType::Location => '📍 https://maps.google.com/?q='.($meta['latitude'] ?? '').','.($meta['longitude'] ?? ''),
            MessageType::Contact => '👤 '.($meta['name'] ?? '').' '.implode(', ', (array) ($meta['phones'] ?? [])),
            MessageType::Poll => '📊 '.$body.' ('.collect($meta['options'] ?? [])->pluck('text')->implode(' / ').')',
            default => trim(__('chat.preview.'.$message->type->value)),
        };
        if ($message->type === MessageType::Poll) {
            return (string) $label;
        }

        return trim(implode(' ', array_filter([$label, $body])));
    }
}
