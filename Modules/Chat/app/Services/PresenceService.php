<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Modules\Chat\Enums\ConversationType;
use Modules\Chat\Enums\PrivacyAudience;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;

/**
 * "Online" / "last seen". The app says `online` when it comes to the foreground and every
 * ~60 seconds while it stays there (a heartbeat), and `offline` when it goes to the background;
 * a missed heartbeat expires the online flag by itself (a phone that died never says goodbye).
 *
 * Only people I have a direct chat with are told in real time, and only those my `last_seen`
 * privacy allows — and someone who hides their own last seen can't see anyone else's.
 */
class PresenceService
{
    private const ONLINE_TTL_SECONDS = 90;

    public function __construct(
        private readonly ChatPrivacy $privacy,
        private readonly ChatBroadcaster $broadcaster,
        private readonly ParticipantDirectory $directory,
    ) {}

    public function set(Model $me, bool $online): void
    {
        $key = $this->cacheKey($me);
        $wasOnline = Cache::has($key);

        if ($online) {
            Cache::put($key, true, self::ONLINE_TTL_SECONDS);
        } else {
            Cache::forget($key);
        }

        $settings = $this->privacy->settingsFor($me);
        $settings->update(['last_seen_at' => now()]);

        if ($wasOnline === $online) {
            return; // a heartbeat — nothing changed for anybody
        }

        $peers = $this->directPeers($me);

        if ($peers === []) {
            return;
        }

        $this->directory->prime($me, $peers);
        $myKey = ParticipantType::key($me);

        $allowed = array_values(array_filter($peers, fn ($peer) => $this->directory->mayView($myKey, "{$peer[0]}:{$peer[1]}", 'last_seen')));

        $this->broadcaster->toAccounts($allowed, 'chat.presence', [
            'participant' => $myKey,
            'online' => $online,
            'last_seen_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array{online: bool, last_seen_at: string|null}|null null = hidden by their privacy
     */
    public function presenceFor(Model $viewer, Model $other): ?array
    {
        $viewerSettings = $this->privacy->peek($viewer);
        $otherSettings = $this->privacy->peek($other);

        // Reciprocal, like WhatsApp: hide yours and you can't see theirs.
        if ($viewerSettings->last_seen === PrivacyAudience::Nobody
            || ! $this->privacy->audienceAllows($otherSettings->last_seen, $other, $viewer)) {
            return null;
        }

        return [
            'online' => Cache::has($this->cacheKey($other)),
            'last_seen_at' => $otherSettings->last_seen_at?->toIso8601String(),
        ];
    }

    public function isOnline(Model $account): bool
    {
        return Cache::has($this->cacheKey($account));
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function directPeers(Model $me): array
    {
        $conversationIds = ChatParticipant::query()->of($me)->whereNull('left_at')
            ->whereHas('conversation', fn ($q) => $q->where('type', ConversationType::Direct->value)->whereNotNull('last_message_id'))
            ->pluck('conversation_id');

        return ChatParticipant::query()->whereIn('conversation_id', $conversationIds)
            ->whereNot(fn ($q) => $q->where('participant_type', ParticipantType::aliasFor($me))->where('participant_id', $me->getKey()))
            ->get(['participant_type', 'participant_id'])
            ->map(fn ($p) => [$p->participant_type, (int) $p->participant_id])
            ->all();
    }

    private function cacheKey(Model $account): string
    {
        return 'chat.online.'.ParticipantType::key($account);
    }
}
