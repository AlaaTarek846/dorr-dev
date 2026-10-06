<?php

namespace Modules\Chat\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatMoment;
use Modules\Chat\Models\ChatScheduledMessage;
use Modules\Chat\Support\ParticipantType;
use Modules\User\Models\User;
use Modules\Wallet\Services\PinService;

/**
 * Greeting cards (DORR Moments, spec 161–167): a card in the occasion's look (or one of my own
 * dates' looks) with my words, my own recorded voice (never cloned), photos, a gift from the
 * wallet, and — for a surprise — a time it opens. Sent now, or scheduled for a time in the
 * recipient's own zone.
 */
class MomentCardService
{
    /** A surprise can wait this long at most. */
    private const MAX_REVEAL_DAYS = 365;

    public function __construct(
        private readonly MessageService $messages,
        private readonly ScheduledMessageService $scheduled,
        private readonly MoneyRequestService $money,
        private readonly ConversationService $conversations,
    ) {}

    /**
     * @param  array<string, mixed>  $data  moment_id | personal_kind, title, text, reveal_at, send_at (local), schedule_zone, gift_amount_minor, pin
     * @param  list<UploadedFile>  $files  voice and photos
     * @return array{message: ChatMessage|null, scheduled: ChatScheduledMessage|null}
     */
    public function send(Model $me, ChatConversation $conversation, array $data, array $files): array
    {
        $this->conversations->participantOf($me, $conversation, true);
        $card = $this->look($data);

        $revealAt = ! empty($data['reveal_at']) ? CarbonImmutable::parse($data['reveal_at'])->utc() : null;
        if ($revealAt !== null && ($revealAt->isPast() || $revealAt->gt(now()->addDays(self::MAX_REVEAL_DAYS)))) {
            throw new ChatException('card_reveal_invalid', 422);
        }
        $card['reveal_at'] = $revealAt?->toIso8601String();

        $text = trim((string) ($data['text'] ?? ''));
        $gift = (int) ($data['gift_amount_minor'] ?? 0);

        // Later — at that time where they are (or where I am).
        if (! empty($data['send_at'])) {
            if ($gift > 0) {
                // Money moves only when I confirm it, not at some later time on its own.
                throw new ChatException('card_gift_now_only', 422);
            }
            $zone = $this->zoneFor($me, $conversation, ($data['schedule_zone'] ?? 'recipient') === 'recipient');
            $sendAt = CarbonImmutable::parse($data['send_at'], $zone)->utc();

            $row = DB::transaction(function () use ($me, $conversation, $text, $card, $sendAt, $zone, $files, $data) {
                $row = $this->scheduled->schedule($me, $conversation, $text, $sendAt, (bool) ($data['silent'] ?? false), MessageType::MomentCard->value, ['card' => $card], $zone);
                foreach (array_values($files) as $i => $file) {
                    $row->addMedia($file)->usingFileName(Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin'))
                        ->setOrder($i + 1)->toMediaCollection(ChatMessage::ATTACHMENTS);
                }

                return $row;
            });

            return ['message' => null, 'scheduled' => $row];
        }

        // A gift (spec 167): through the wallet only, PIN first — then the card says it came with one.
        if ($gift > 0) {
            if (! $me instanceof User) {
                throw new ChatException('money_request_direct_only', 422);
            }
            app(PinService::class)->verify($me, (string) ($data['pin'] ?? ''));
            $transfer = $this->money->sendMoney($me, $conversation, $gift, $card['title'] ?: null, (string) Str::uuid());
            $card['gift'] = [
                'amount_minor' => $gift,
                'currency_code' => data_get($transfer->meta, 'currency_code') ?? data_get($transfer->meta, 'currency'),
                'message_id' => $transfer->uuid,
            ];
        }

        $message = $this->messages->send($me, $conversation, [
            'type' => MessageType::MomentCard->value,
            'body' => $text,
            'meta_raw' => ['card' => $card],
            'uuid' => $data['uuid'] ?? null,
        ], $files);

        return ['message' => $message, 'scheduled' => null];
    }

    /**
     * The card's look: a snapshot of the occasion (or of one of my own dates' kinds), so later
     * changes to the catalog don't change a card already sent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function look(array $data): array
    {
        if (! empty($data['moment_id'])) {
            $moment = ChatMoment::query()->active()->with(['translations', 'media'])->find($data['moment_id']) ?? throw new ChatException('moment_not_found', 404);

            return [
                'moment_id' => $moment->id,
                'moment_key' => $moment->key,
                'kind' => $moment->kind,
                'theme' => $moment->theme,
                'title' => trim((string) ($data['title'] ?? '')) ?: $moment->translatedName(),
                'primary_color' => $moment->primary_color,
                'secondary_color' => $moment->secondary_color,
                'emoji' => $moment->emoji,
                'animation' => $moment->animation,
                'card_image' => $moment->cardUrl(),
            ];
        }

        $kind = in_array($data['personal_kind'] ?? null, array_keys(MomentService::PERSONAL_LOOKS), true) ? $data['personal_kind'] : 'other';
        $look = MomentService::PERSONAL_LOOKS[$kind];

        return [
            'moment_id' => null,
            'moment_key' => null,
            'kind' => 'personal',
            'theme' => $kind,
            'title' => trim((string) ($data['title'] ?? '')) ?: __('chat.card.titles.'.$kind),
        ] + $look + ['card_image' => null];
    }

    /** The zone a scheduled card is planned in: the other person's (a direct chat), else mine. */
    private function zoneFor(Model $me, ChatConversation $conversation, bool $recipient): string
    {
        $mine = $me->timezone ?? 'UTC';
        if (! $recipient || $conversation->isGroup()) {
            return $mine;
        }

        $other = $conversation->activeParticipants()->get()->first(fn ($p) => $p->participant_type !== ParticipantType::aliasFor($me) || (int) $p->participant_id !== (int) $me->getKey())?->participant();

        return $other?->timezone ?: $mine;
    }
}
