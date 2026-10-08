<?php

namespace Modules\Sports\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Enums\MessageType;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Services\GroupService;
use Modules\Chat\Services\MessageService;
use Modules\Chat\Support\ParticipantType;
use Modules\Sports\Exceptions\SportsException;
use Modules\Sports\Models\SportsMatch;

/**
 * Sports in the chat: share a match as a live card (with "who wins?" as a poll), or a match room —
 * a group to watch it together, the match card first (195). Nothing here changes official data.
 */
class SportsChatService
{
    public function __construct(private readonly MessageService $messages, private readonly GroupService $groups) {}

    /** @return array<string, mixed> */
    public function card(SportsMatch $m): array
    {
        $m->loadMissing(['home.translations', 'away.translations', 'competition.translations', 'sport']);

        return ['match' => [
            'id' => $m->uuid,
            'sport' => $m->sport?->key,
            'competition' => $m->competition?->displayName(),
            'home' => ['id' => $m->home_team_id, 'name' => $m->home?->displayName(), 'logo' => $m->home?->logo, 'color' => data_get($m->home?->colors, 'primary')],
            'away' => ['id' => $m->away_team_id, 'name' => $m->away?->displayName(), 'logo' => $m->away?->logo, 'color' => data_get($m->away?->colors, 'primary')],
            'starts_at' => $m->starts_at?->toIso8601String(),
        ]];
    }

    /**
     * @return array{message: ChatMessage, poll: ?ChatMessage}
     */
    public function share(Model $me, SportsMatch $match, ChatConversation $conversation, bool $poll): array
    {
        return DB::transaction(function () use ($me, $match, $conversation, $poll) {
            $card = $this->messages->send($me, $conversation, ['type' => MessageType::MatchCard->value, 'meta_raw' => $this->card($match)]);
            $question = $poll && $match->status === 'scheduled' ? $this->messages->send($me, $conversation, [
                'type' => MessageType::Poll->value,
                'body' => __('sports.poll.question'),
                'poll_options' => [$match->home?->displayName() ?? '1', __('sports.poll.draw'), $match->away?->displayName() ?? '2'],
            ]) : null;

            return ['message' => $card, 'poll' => $question];
        });
    }

    /**
     * @param  list<int>  $memberIds
     * @return array{participant: \Modules\Chat\Models\ChatParticipant, not_added: list<string>}
     */
    public function room(Model $me, SportsMatch $match, array $memberIds): array
    {
        $members = ParticipantType::modelClassFor('user')::query()->whereIn('id', $memberIds)->whereKeyNot($me->getKey())->get()->all();
        if ($members === []) {
            throw new SportsException('room_needs_members', 422);
        }
        $match->loadMissing(['home.translations', 'away.translations']);

        return DB::transaction(function () use ($me, $match, $members) {
            $name = Str::limit('⚽ '.($match->home?->displayName() ?? '?').' – '.($match->away?->displayName() ?? '?'), 100, '');
            $result = $this->groups->create($me, $name, null, $members);
            $conversation = $result['participant']->conversation;
            DB::table('sports_match_rooms')->insert([
                'match_id' => $match->id, 'conversation_id' => $conversation->id, 'owner_type' => ParticipantType::aliasFor($me), 'owner_id' => $me->getKey(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->messages->send($me, $conversation, ['type' => MessageType::MatchCard->value, 'meta_raw' => $this->card($match)]);

            return $result;
        });
    }
}
