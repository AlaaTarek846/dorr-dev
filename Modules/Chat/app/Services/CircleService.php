<?php

namespace Modules\Chat\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\ChatPrivacyCircle;
use Modules\Chat\Support\ParticipantType;

/**
 * Privacy circles (spec 98–103) — my own way of grouping and hiding chats; see ChatPrivacyCircle.
 */
class CircleService
{
    public const MAX_CIRCLES = 20;

    public function __construct(private readonly ConversationService $conversations) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Model $me, array $data): ChatPrivacyCircle
    {
        if (ChatPrivacyCircle::query()->ownedBy($me)->count() >= self::MAX_CIRCLES) {
            throw new ChatException('circle_limit', 422, ['max' => self::MAX_CIRCLES]);
        }

        return ChatPrivacyCircle::query()->create($this->clean($data) + [
            'uuid' => (string) Str::uuid(),
            'owner_type' => ParticipantType::aliasFor($me),
            'owner_id' => $me->getKey(),
            'sort_order' => ChatPrivacyCircle::query()->ownedBy($me)->max('sort_order') + 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Model $me, ChatPrivacyCircle $circle, array $data): ChatPrivacyCircle
    {
        $this->assertMine($me, $circle);
        $circle->update($this->clean($data));

        return $circle->refresh();
    }

    /** Its chats go back to the main list, as they were. */
    public function delete(Model $me, ChatPrivacyCircle $circle): void
    {
        $this->assertMine($me, $circle);
        $circle->delete();
    }

    /** Put one of my chats in a circle (or take it out with null). Only my side changes. */
    public function assign(Model $me, ChatConversation $conversation, ?string $circleId): ChatParticipant
    {
        $row = $this->conversations->participantOf($me, $conversation);
        $circle = null;

        if ($circleId !== null) {
            $circle = ChatPrivacyCircle::query()->ownedBy($me)->where('uuid', $circleId)->first() ?? throw new ChatException('circle_not_found', 404);
        }

        $row->update(['privacy_circle_id' => $circle?->id]);

        return $row->refresh();
    }

    /**
     * My circles, with how many chats each holds and their unread total. The real name is sent
     * too: the app shows the stand-in on the chip and the real one once the circle is open.
     *
     * @return list<array<string, mixed>>
     */
    public function list(Model $me): array
    {
        return ChatPrivacyCircle::query()->ownedBy($me)
            ->withCount(['participants as chats_count' => fn ($q) => $q->where('is_deleted', false)])
            ->withSum(['participants as unread_total' => fn ($q) => $q->where('is_deleted', false)], 'unread_count')
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (ChatPrivacyCircle $c) => $this->present($c))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ChatPrivacyCircle $c): array
    {
        return [
            'id' => $c->uuid,
            'name' => $c->name,
            'masked_name' => $c->masked_name,
            'shown_name' => $c->shownName(),
            'emoji' => $c->emoji,
            'color' => $c->color,
            'disclosure' => $c->disclosure,
            'hide_from_list' => $c->hide_from_list,
            'locked' => $c->locked,
            'chats_count' => (int) ($c->chats_count ?? $c->participants()->where('is_deleted', false)->count()),
            'unread' => (int) ($c->unread_total ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $out = collect($data)->only(['name', 'masked_name', 'emoji', 'color', 'disclosure', 'hide_from_list', 'locked', 'sort_order'])->all();
        foreach (['name', 'masked_name', 'emoji', 'color'] as $key) {
            if (array_key_exists($key, $out)) {
                $out[$key] = ($v = trim((string) $out[$key])) === '' ? null : $v;
            }
        }
        if (array_key_exists('name', $out) && $out['name'] === null) {
            unset($out['name']);
        }

        return $out;
    }

    private function assertMine(Model $me, ChatPrivacyCircle $circle): void
    {
        if (! $circle->isOwnedBy($me)) {
            throw new ChatException('circle_not_found', 404);
        }
    }
}
