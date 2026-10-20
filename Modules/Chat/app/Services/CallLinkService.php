<?php

namespace Modules\Chat\Services;

use App\Support\LocaleResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Modules\Chat\Enums\CallType;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatCallLink;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Support\LiveKitToken;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;

/**
 * Call links (WhatsApp list 72). I make a link for a voice or video call and send it anywhere
 * (https://…/call/{token}); whoever opens it in DORR joins the same LiveKit room — nobody is rung
 * and no chat is needed. It works until it expires (30 days) or I cancel it. When someone
 * joins, I get a push so I can hop in.
 *
 * The same rules as any call: calls on in both our countries, and nobody we blocked.
 */
class CallLinkService
{
    public const MAX_LINKS = 20;

    public const DAYS = 30;

    public function __construct(
        private readonly ChatPrivacy $privacy,
        private readonly ChatPushNotifier $push,
        private readonly ParticipantDirectory $directory,
    ) {}

    public function create(Model $me, CallType $type): ChatCallLink
    {
        $this->assertCallsFor($me);
        $mine = $this->mine($me);
        if ($mine->count() >= self::MAX_LINKS) {
            throw new ChatException('call_link_limit', 422, ['max' => self::MAX_LINKS], ['max' => self::MAX_LINKS]);
        }

        return ChatCallLink::query()->create([
            'token' => Str::random(24),
            'owner_type' => ParticipantType::aliasFor($me),
            'owner_id' => $me->getKey(),
            'type' => $type,
            'expires_at' => now()->addDays(self::DAYS),
        ]);
    }

    /** @return Collection<int, ChatCallLink> */
    public function mine(Model $me): Collection
    {
        return ChatCallLink::query()->usable()
            ->where('owner_type', ParticipantType::aliasFor($me))->where('owner_id', $me->getKey())
            ->latest('id')->get();
    }

    public function find(string $token): ChatCallLink
    {
        return ChatCallLink::query()->usable()->where('token', $token)->first()
            ?? throw new ChatException('call_link_invalid', 404);
    }

    public function revoke(Model $me, string $token): void
    {
        $link = $this->find($token);
        if (! $this->isOwner($me, $link)) {
            throw ChatException::ownerOnly();
        }
        $link->update(['revoked_at' => now()]);
    }

    /**
     * Into the room: the LiveKit address, the room and my token for it.
     *
     * @return array{url: string, room: string, token: string}
     */
    public function join(Model $me, ChatCallLink $link): array
    {
        $this->assertCallsFor($me);
        $owner = $this->owner($link);
        $isOwner = $this->isOwner($me, $link);
        if (! $isOwner && $owner !== null && $this->privacy->isBlockedBetween($me, $owner)) {
            throw ChatException::blocked();
        }

        $key = ParticipantType::key($me);
        $profile = $this->directory->profile($me, ParticipantType::aliasFor($me), $me->getKey());
        $join = [
            'url' => LiveKitToken::url() ?? throw ChatException::callsNotConfigured(),
            'room' => $link->roomName(),
            'token' => LiveKitToken::issue($link->roomName(), $key, (string) ($profile['account_name'] ?? ''), ['participant' => $key, 'avatar' => $profile['avatar'] ?? null]),
        ];
        $link->update(['last_joined_at' => now()]);

        // "Sara joined your call link" — once per person per 10 minutes, so I can hop in.
        if (! $isOwner && $owner !== null && Cache::add("chat:call-link:{$link->id}:{$key}", 1, now()->addMinutes(10))) {
            $name = (string) ($this->directory->profile($owner, ParticipantType::aliasFor($me), $me->getKey())['name'] ?? '');
            $headings = $contents = [];
            foreach (LocaleResolver::supported() as $locale) {
                $headings[$locale] = __('chat.push.call_link_joined_title', [], $locale);
                $contents[$locale] = __('chat.push.call_link_joined', ['name' => $name], $locale);
            }
            $this->push->toAccounts([[$link->owner_type, (int) $link->owner_id]], $headings, $contents, [
                'type' => 'chat', 'event' => 'chat.call_link.joined', 'token' => $link->token, 'call_type' => $link->type->value,
            ]);
        }

        return $join;
    }

    /** @return array<string, mixed> */
    public function payload(Model $viewer, ChatCallLink $link): array
    {
        return [
            'token' => $link->token,
            'type' => $link->type->value,
            'link' => url('/call/'.$link->token),
            'payload' => 'dorr://chat/call/'.$link->token,
            'owner' => $this->directory->profile($viewer, $link->owner_type, $link->owner_id),
            'is_mine' => $this->isOwner($viewer, $link),
            'expires_at' => $link->expires_at->toIso8601String(),
            'last_joined_at' => $link->last_joined_at?->toIso8601String(),
        ];
    }

    private function isOwner(Model $me, ChatCallLink $link): bool
    {
        return $link->owner_type === ParticipantType::aliasFor($me) && (int) $link->owner_id === (int) $me->getKey();
    }

    private function owner(ChatCallLink $link): ?Model
    {
        $class = ParticipantType::modelClassFor($link->owner_type);

        return $class::query()->find($link->owner_id);
    }

    private function assertCallsFor(Model $me): void
    {
        $settings = ChatSetting::current();
        if (! $settings->calls_enabled) {
            throw ChatException::callsDisabled();
        }
        if (! $settings->callsAllowedFor($me)) {
            throw new ChatException('calls_unavailable_country', 403);
        }
    }
}
