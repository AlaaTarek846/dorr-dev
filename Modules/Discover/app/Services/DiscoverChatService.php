<?php

namespace Modules\Discover\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\GroupService;
use Modules\Chat\Services\MessageService;
use Modules\Chat\Support\ParticipantType;
use Modules\Discover\Exceptions\DiscoverException;
use Modules\Discover\Models\DiscoverEvent;
use Modules\Discover\Models\DiscoverEventRoom;

/**
 * Discover in the chat: share an event as a card (and ask "who's going?" as a poll) (178), or make
 * a group to go together — named after it, its card first, and only its admins write once the
 * event is over (179).
 */
class DiscoverChatService
{
    public function __construct(
        private readonly DiscoverService $discover,
        private readonly MessageService $messages,
        private readonly GroupService $groups,
    ) {}

    /**
     * What the card in a chat shows — a snapshot; the app opens the live event by its id.
     *
     * @return array<string, mixed>
     */
    public function card(DiscoverEvent $e): array
    {
        $e->loadMissing(['city.translations', 'category.translations', 'organizer', 'media']);
        $start = $e->starts_at ? CarbonImmutable::instance($e->starts_at)->setTimezone($e->timezone) : null;

        return ['event' => [
            'id' => $e->uuid,
            'title' => $e->title,
            'starts_at' => $e->starts_at?->toIso8601String(),
            'timezone' => $e->timezone,
            'local_date' => $start?->toDateString(),
            'local_time' => $start?->format('H:i'),
            'city' => $e->city?->translatedName(),
            'venue' => $e->venue,
            'category' => $e->category?->translatedName(),
            'emoji' => $e->category?->emoji,
            'color' => $e->category?->color,
            'cover' => $e->coverUrl(),
            'is_free' => (bool) $e->is_free,
            'price_text' => $e->price_text,
            'status' => $e->status,
            'verified' => $e->isVerified(),
        ]];
    }

    /**
     * @return array{message: ChatMessage, poll: ?ChatMessage}
     */
    public function share(Model $me, DiscoverEvent $event, ChatConversation $conversation, ?string $comment, bool $poll): array
    {
        $this->discover->assertPublic($event);

        return DB::transaction(function () use ($me, $event, $conversation, $comment, $poll) {
            $card = $this->messages->send($me, $conversation, ['type' => MessageType::EventCard->value, 'body' => $comment, 'meta_raw' => $this->card($event)]);
            $question = $poll ? $this->messages->send($me, $conversation, [
                'type' => MessageType::Poll->value,
                'body' => __('discover.poll.question', ['title' => Str::limit($event->title, 120)]),
                'poll_options' => [__('discover.poll.yes'), __('discover.poll.maybe'), __('discover.poll.no')],
            ]) : null;

            return ['message' => $card, 'poll' => $question];
        });
    }

    /**
     * @param  list<int>  $memberIds
     * @return array{participant: \Modules\Chat\Models\ChatParticipant, not_added: list<string>}
     */
    public function room(Model $me, DiscoverEvent $event, array $memberIds): array
    {
        $this->discover->assertPublic($event);
        if (in_array($event->status, ['cancelled', 'ended'], true)) {
            throw new DiscoverException('event_over', 422);
        }
        $members = ParticipantType::modelClassFor('user')::query()->whereIn('id', $memberIds)->whereKeyNot($me->getKey())->get()->all();
        if ($members === []) {
            throw new DiscoverException('room_needs_members', 422);
        }

        return DB::transaction(function () use ($me, $event, $members) {
            $result = $this->groups->create($me, Str::limit('📍 '.$event->title, 100, ''), Str::limit((string) $event->venue, 2000, '') ?: null, $members);
            $conversation = $result['participant']->conversation;
            DiscoverEventRoom::query()->create([
                'event_id' => $event->id, 'conversation_id' => $conversation->id,
                'owner_type' => ParticipantType::aliasFor($me), 'owner_id' => $me->getKey(),
            ]);
            $this->messages->send($me, $conversation, ['type' => MessageType::EventCard->value, 'meta_raw' => $this->card($event)]);

            return $result;
        });
    }
}
