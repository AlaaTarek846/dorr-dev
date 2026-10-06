<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatCollabCard;
use Modules\Chat\Models\ChatCollabCardMember;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;
use Modules\User\Models\User;

/**
 * A group card (DORR Moments, spec 163): I start it for someone, invite people, each adds their
 * words / voice / photo; I send it when it's ready — one card, every part in it. Only the
 * organiser sends or calls it off, each person changes only their own part, and the recipient
 * knows nothing until it arrives.
 */
class CollabCardService
{
    public const MAX_MEMBERS = 50;

    public function __construct(
        private readonly MomentCardService $cards,
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
        private readonly ParticipantDirectory $directory,
        private readonly ChatPushNotifier $push,
    ) {}

    /**
     * @param  array<string, mixed>  $data  recipient_id, moment_id | personal_kind, title, deadline_at, members[]
     */
    public function create(Model $me, array $data): ChatCollabCard
    {
        $recipient = User::query()->find($data['recipient_id']) ?? throw ChatException::userNotFound();
        if ($recipient->is($me)) {
            throw ChatException::toSelf();
        }
        $look = $this->cards->look($data);

        $card = DB::transaction(function () use ($me, $data, $recipient, $look) {
            $card = ChatCollabCard::query()->create([
                'uuid' => (string) Str::uuid(),
                'owner_type' => ParticipantType::aliasFor($me),
                'owner_id' => $me->getKey(),
                'recipient_type' => ParticipantType::aliasFor($recipient),
                'recipient_id' => $recipient->id,
                'chat_moment_id' => $look['moment_id'],
                'personal_kind' => $look['moment_id'] ? null : ($data['personal_kind'] ?? 'other'),
                'title' => mb_substr($look['title'], 0, 120),
                'deadline_at' => $data['deadline_at'] ?? null,
                'status' => 'collecting',
            ]);
            // The organiser signs it too.
            $card->members()->create(['member_type' => ParticipantType::aliasFor($me), 'member_id' => $me->getKey()]);

            return $card;
        });

        $this->invite($me, $card, $data['members'] ?? []);

        return $card->refresh();
    }

    /**
     * Invite more people (organiser) — anyone but the recipient. They get a notification.
     *
     * @param  list<int>  $userIds
     */
    public function invite(Model $me, ChatCollabCard $card, array $userIds): ChatCollabCard
    {
        $this->assertOrganiser($me, $card);
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->unique()
            ->reject(fn ($id) => $id === (int) $card->recipient_id || $id === (int) $me->getKey())->values();

        if ($card->members()->count() + $ids->count() > self::MAX_MEMBERS) {
            throw new ChatException('collab_too_many', 422, ['max' => self::MAX_MEMBERS]);
        }

        $new = collect();
        foreach (User::query()->whereIn('id', $ids)->get() as $user) {
            $row = $card->members()->firstOrCreate(['member_type' => 'user', 'member_id' => $user->id]);
            if ($row->wasRecentlyCreated) {
                $new->push($row);
            }
        }

        if ($new->isNotEmpty()) {
            $this->push->collabInvite((string) ($me->name ?? ''), $card->title, $new, $card->uuid);
        }

        return $card->refresh();
    }

    public function removeMember(Model $me, ChatCollabCard $card, int $userId): ChatCollabCard
    {
        $this->assertOrganiser($me, $card);
        if ($userId === (int) $me->getKey()) {
            throw new ChatException('collab_not_allowed', 422);
        }
        $row = $card->members()->where('member_type', 'user')->where('member_id', $userId)->first();
        $row?->clearMediaCollection(ChatMessage::ATTACHMENTS);
        $row?->delete();

        return $card->refresh();
    }

    /**
     * My part: words, my voice, a photo — added or changed until the card goes out.
     *
     * @param  list<UploadedFile>  $files
     */
    public function contribute(Model $me, ChatCollabCard $card, ?string $text, array $files, bool $clearFiles): ChatCollabCardMember
    {
        $this->assertOpen($card);
        $row = $card->memberRow($me) ?? throw new ChatException('collab_not_found', 404);

        DB::transaction(function () use ($row, $text, $files, $clearFiles) {
            if ($clearFiles || $files !== []) {
                $row->clearMediaCollection(ChatMessage::ATTACHMENTS);
            }
            foreach (array_values($files) as $i => $file) {
                $row->addMedia($file)->usingFileName(Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin'))
                    ->setOrder($i + 1)->toMediaCollection(ChatMessage::ATTACHMENTS);
            }
            $row->update(['text' => $text !== null ? (trim($text) ?: null) : $row->text, 'contributed_at' => now()]);
        });

        return $row->refresh();
    }

    /** The organiser sends it: one card in our direct chat, every part in it, in order. */
    public function send(Model $me, ChatCollabCard $card): ChatMessage
    {
        $this->assertOrganiser($me, $card);
        $this->assertOpen($card);

        $parts = $card->members()->whereNotNull('contributed_at')->with('media')->orderBy('contributed_at')->get();
        if ($parts->isEmpty()) {
            throw new ChatException('collab_empty', 422);
        }

        $recipient = ParticipantType::modelClassFor($card->recipient_type)::query()->find($card->recipient_id) ?? throw ChatException::userNotFound();
        $chat = $this->conversations->openDirect($me, $recipient)->conversation;
        $look = $this->cards->look(['moment_id' => $card->chat_moment_id, 'personal_kind' => $card->personal_kind, 'title' => $card->title]);

        $this->directory->prime($me, $parts->map(fn ($p) => [$p->member_type, $p->member_id]));
        $index = 0;
        $contributions = $parts->map(function (ChatCollabCardMember $p) use (&$index, $me) {
            $files = $p->getMedia(ChatMessage::ATTACHMENTS)->map(function ($m) use (&$index) {
                $index++;

                return ['attachment' => $index - 1, 'kind' => str_starts_with((string) $m->mime_type, 'image/') ? 'photo' : 'voice'];
            })->values()->all();

            return [
                'name' => $this->directory->profile($me, $p->member_type, $p->member_id)['account_name'] ?? null,
                'text' => $p->text,
                'files' => $files,
            ];
        })->values()->all();

        return DB::transaction(function () use ($me, $card, $chat, $look, $contributions, $parts) {
            $message = $this->messages->send($me, $chat, [
                'type' => MessageType::MomentCard->value,
                'body' => '',
                'meta_raw' => ['card' => $look + ['reveal_at' => null, 'collab' => true, 'contributions' => $contributions]],
                'copy_media_from' => $parts->all(),
            ]);
            $card->update(['status' => 'sent', 'message_id' => $message->id]);

            return $message;
        });
    }

    public function cancel(Model $me, ChatCollabCard $card): void
    {
        $this->assertOrganiser($me, $card);
        $this->assertOpen($card);
        $card->members->each(fn ($m) => $m->clearMediaCollection(ChatMessage::ATTACHMENTS));
        $card->update(['status' => 'cancelled']);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Model $me, ChatCollabCard $card): array
    {
        $card->loadMissing(['members.media']);
        $this->directory->prime($me, $card->members->map(fn ($m) => [$m->member_type, $m->member_id])->push([$card->recipient_type, $card->recipient_id])->push([$card->owner_type, $card->owner_id]));
        $look = $this->cards->look(['moment_id' => $card->chat_moment_id, 'personal_kind' => $card->personal_kind, 'title' => $card->title]);
        $mine = $card->memberRow($me);

        return [
            'id' => $card->uuid,
            'title' => $card->title,
            'look' => $look,
            'status' => $card->status,
            'deadline_at' => $card->deadline_at?->toIso8601String(),
            'organiser' => $this->directory->profile($me, $card->owner_type, $card->owner_id),
            'recipient' => $this->directory->profile($me, $card->recipient_type, $card->recipient_id),
            'is_organiser' => $card->isOwnedBy($me),
            'members' => $card->members->map(fn (ChatCollabCardMember $m) => [
                'profile' => $this->directory->profile($me, $m->member_type, $m->member_id),
                'signed' => $m->contributed_at !== null,
                'text' => $m->text,
                'files' => $m->getMedia(ChatMessage::ATTACHMENTS)->map(fn ($f) => ['url' => $f->getUrl(), 'mime_type' => $f->mime_type])->values()->all(),
            ])->values()->all(),
            'mine' => $mine ? ['text' => $mine->text, 'signed' => $mine->contributed_at !== null] : null,
        ];
    }

    private function assertOrganiser(Model $me, ChatCollabCard $card): void
    {
        if (! $card->isOwnedBy($me)) {
            throw new ChatException('collab_not_allowed', 403);
        }
    }

    private function assertOpen(ChatCollabCard $card): void
    {
        if ($card->status !== 'collecting') {
            throw new ChatException('collab_closed', 409);
        }
    }
}
