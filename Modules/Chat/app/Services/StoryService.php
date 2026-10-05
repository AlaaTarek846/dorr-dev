<?php

namespace Modules\Chat\Services;

use App\Support\Media\WebpUploadConverter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatBlock;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Models\ChatStory;
use Modules\Chat\Models\ChatStoryView;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\ParticipantType;

/**
 * Stories / Status (docs/chat-plan.md §10.1).
 *
 * Who sees a story is decided once, when it is posted, from the owner's story privacy
 * (`contacts` = everyone I saved who is on Dorr, `except` = the same minus a list, `only` = just a
 * list), minus anyone blocked either way — and frozen in chat_story_recipients. Views respect
 * read receipts both ways (off = you watch unseen, and you don't see who watched yours), replies
 * land in the direct chat as a `story_reply` message, and everything disappears after
 * `story_duration_hours`.
 */
class StoryService
{
    public function __construct(
        private readonly ChatPrivacy $privacy,
        private readonly ChatBroadcaster $broadcaster,
        private readonly ParticipantDirectory $directory,
    ) {}

    // ---------------------------------------------------------------- posting

    /**
     * @param  array<string, mixed>  $data  type, body, style, duration_ms, allow_replies
     */
    public function create(Model $owner, array $data, ?UploadedFile $file): ChatStory
    {
        $settings = $this->assertEnabled();
        $type = $data['type'];

        if ($type === 'text' && trim((string) ($data['body'] ?? '')) === '') {
            throw ChatException::emptyMessage();
        }
        if ($type !== 'text' && $file === null) {
            throw new ChatException('attachment_required', 422);
        }
        if ($type === 'video' && (int) ($data['duration_ms'] ?? 0) > $settings->story_video_max_seconds * 1000 + 500) {
            throw ChatException::storyVideoTooLong($settings->story_video_max_seconds);
        }

        return DB::transaction(function () use ($owner, $data, $type, $file, $settings) {
            $story = ChatStory::query()->create([
                'owner_type' => ParticipantType::aliasFor($owner),
                'owner_id' => $owner->getKey(),
                'type' => $type,
                'body' => isset($data['body']) ? trim((string) $data['body']) ?: null : null,
                'style' => $type === 'text' ? ($data['style'] ?? null) : null,
                'duration_ms' => $type === 'video' ? ($data['duration_ms'] ?? null) : null,
                'allow_replies' => (bool) ($data['allow_replies'] ?? true),
                'expires_at' => now()->addHours($settings->story_duration_hours),
            ]);

            if ($file !== null) {
                config(['media-library.max_file_size' => $settings->max_file_size_mb * 1024 * 1024]);
                $file = WebpUploadConverter::convert($file);
                $story->addMedia($file)
                    ->usingFileName(Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin'))
                    ->toMediaCollection(ChatStory::MEDIA);
            }

            $recipients = $this->audienceOf($owner);
            foreach (array_chunk($recipients, 500) as $chunk) {
                DB::table('chat_story_recipients')->insert(array_map(fn ($r) => [
                    'story_id' => $story->id, 'participant_type' => $r[0], 'participant_id' => $r[1],
                ], $chunk));
            }

            $this->broadcaster->toAccounts(
                [...$recipients, [ParticipantType::aliasFor($owner), (int) $owner->getKey()]],
                'chat.story.posted',
                ['owner' => ParticipantType::key($owner), 'story_id' => $story->uuid],
            );

            return $story;
        });
    }

    public function delete(Model $owner, ChatStory $story): void
    {
        $this->assertOwner($owner, $story);
        $recipients = $this->recipientsOf($story);

        $story->clearMediaCollection(ChatStory::MEDIA);
        $story->delete();

        $this->broadcaster->toAccounts(
            [...$recipients, [$story->owner_type, (int) $story->owner_id]],
            'chat.story.deleted',
            ['owner' => $story->ownerKey(), 'story_id' => $story->uuid],
        );
    }

    /**
     * Everyone this owner's next story goes to: [alias, id] pairs.
     *
     * @return list<array{0: string, 1: int}>
     */
    public function audienceOf(Model $owner): array
    {
        $ownerType = ParticipantType::aliasFor($owner);
        $mode = $this->privacy->peek($owner)->story_audience ?? 'contacts';

        $list = fn (string $name) => DB::table('chat_story_privacy_members')
            ->where(['owner_type' => $ownerType, 'owner_id' => $owner->getKey(), 'list' => $name])
            ->get(['member_type', 'member_id'])
            ->map(fn ($r) => $r->member_type.':'.$r->member_id)->all();

        if ($mode === 'only') {
            $keys = $list('only');
        } else {
            $keys = ChatContact::query()->ownedBy($owner)->whereNotNull('contact_id')
                ->get(['contact_type', 'contact_id'])
                ->map(fn ($c) => $c->contact_type.':'.$c->contact_id)->unique()->all();
            if ($mode === 'except') {
                $keys = array_diff($keys, $list('except'));
            }
        }

        $blocked = ChatBlock::query()
            ->where(fn ($q) => $q->where('blocker_type', $ownerType)->where('blocker_id', $owner->getKey()))
            ->orWhere(fn ($q) => $q->where('blocked_type', $ownerType)->where('blocked_id', $owner->getKey()))
            ->get()
            ->map(fn ($b) => $b->blocker_type === $ownerType && (int) $b->blocker_id === (int) $owner->getKey()
                ? $b->blocked_type.':'.$b->blocked_id
                : $b->blocker_type.':'.$b->blocker_id)
            ->all();

        return collect(array_diff($keys, $blocked, [ParticipantType::key($owner)]))
            ->map(function (string $key) {
                [$type, $id] = explode(':', $key, 2);

                return [$type, (int) $id];
            })
            ->filter(fn ($r) => ParticipantType::isEnabled($r[0]))
            ->values()->all();
    }

    // ---------------------------------------------------------------- reading

    /**
     * The stories bar: me first, then everyone whose stories I may see — unseen first, newest
     * first. Muted people come in a separate list at the end.
     *
     * @return array{mine: array<string, mixed>|null, recent: list<array<string, mixed>>, muted: list<array<string, mixed>>}
     */
    public function feed(Model $viewer): array
    {
        $this->assertEnabled();
        $viewerType = ParticipantType::aliasFor($viewer);
        $viewerId = (int) $viewer->getKey();

        $stories = ChatStory::query()->active()
            ->where(function ($q) use ($viewerType, $viewerId) {
                $q->where(fn ($q) => $q->where('owner_type', $viewerType)->where('owner_id', $viewerId))
                    ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('chat_story_recipients')
                        ->whereColumn('chat_story_recipients.story_id', 'chat_stories.id')
                        ->where('chat_story_recipients.participant_type', $viewerType)
                        ->where('chat_story_recipients.participant_id', $viewerId));
            })
            ->with('media')
            ->orderBy('created_at')
            ->get()
            // A block after posting still hides it.
            ->reject(fn (ChatStory $s) => ! $s->isOwnedBy($viewerType, $viewerId) && $this->blockedBetween($viewer, $s));

        $views = ChatStoryView::query()->whereIn('story_id', $stories->pluck('id'))
            ->where('viewer_type', $viewerType)->where('viewer_id', $viewerId)
            ->get()->keyBy('story_id');

        $muted = DB::table('chat_story_mutes')->where('owner_type', $viewerType)->where('owner_id', $viewerId)
            ->get()->map(fn ($m) => $m->muted_type.':'.$m->muted_id)->flip();

        $this->directory->prime($viewer, $stories->map(fn ($s) => [$s->owner_type, $s->owner_id]));

        $groups = $stories->groupBy(fn (ChatStory $s) => $s->ownerKey());
        $mineKey = ParticipantType::key($viewer);
        $out = ['mine' => null, 'recent' => [], 'muted' => []];

        foreach ($groups as $ownerKey => $list) {
            $isMine = $ownerKey === $mineKey;
            $items = $list->map(fn (ChatStory $s) => $this->present($s, $views->get($s->id), $isMine, $isMine ? $this->viewCounts($list) : []))->values()->all();
            [$type, $id] = explode(':', $ownerKey, 2);
            $row = [
                'owner' => $this->directory->profile($viewer, $type, $id),
                'stories' => $items,
                'all_seen' => $isMine || collect($items)->every(fn ($i) => $i['seen']),
                'last_at' => $list->last()->created_at?->toIso8601String(),
                'block_screenshots' => (bool) ($this->directory->privacyOf($ownerKey)?->block_screenshots ?? false),
            ];

            if ($isMine) {
                $out['mine'] = $row;
            } elseif (isset($muted[$ownerKey])) {
                $out['muted'][] = $row;
            } else {
                $out['recent'][] = $row;
            }
        }

        $order = fn ($a, $b) => [$a['all_seen'], $b['last_at']] <=> [$b['all_seen'], $a['last_at']];
        usort($out['recent'], $order);
        usort($out['muted'], $order);

        return $out;
    }

    /**
     * I watched it. Idempotent. With my read receipts off, the owner isn't told.
     */
    public function view(Model $viewer, ChatStory $story): void
    {
        $this->assertCanSee($viewer, $story);

        if ($story->isOwnedBy(ParticipantType::aliasFor($viewer), (int) $viewer->getKey())) {
            return;
        }

        $view = $this->viewRow($viewer, $story);

        if ($view->wasRecentlyCreated && ! $view->hidden) {
            $this->broadcaster->toAccounts([[$story->owner_type, (int) $story->owner_id]], 'chat.story.viewed', [
                'story_id' => $story->uuid,
                'views' => ChatStoryView::query()->where('story_id', $story->id)->where('hidden', false)->count(),
            ]);
        }
    }

    public function react(Model $viewer, ChatStory $story, ?string $emoji): void
    {
        $this->assertCanSee($viewer, $story);
        $view = $this->viewRow($viewer, $story);
        $view->update(['reaction' => $emoji]);

        // A reaction is a deliberate answer, so the owner hears about it even with receipts off.
        $this->broadcaster->toAccounts([[$story->owner_type, (int) $story->owner_id]], 'chat.story.reaction', [
            'story_id' => $story->uuid,
            'viewer' => ParticipantType::key($viewer),
            'emoji' => $emoji,
        ]);
    }

    /**
     * Reply → a `story_reply` message in our direct chat, carrying a snapshot of the story (it
     * still reads right after the story itself is gone).
     */
    public function reply(Model $viewer, ChatStory $story, string $body): ChatMessage
    {
        $this->assertCanSee($viewer, $story);

        if (! $story->allow_replies) {
            throw ChatException::storyRepliesOff();
        }

        $owner = ParticipantType::modelClassFor($story->owner_type)::query()->find($story->owner_id) ?? throw ChatException::userNotFound();
        $conversations = app(ConversationService::class);
        $participant = $conversations->openDirect($viewer, $owner);
        $media = $story->getFirstMedia(ChatStory::MEDIA);

        return app(MessageService::class)->send($viewer, $participant->conversation, [
            'type' => 'story_reply',
            'body' => $body,
            'story_meta' => [
                'story_id' => $story->uuid,
                'story_type' => $story->type,
                'text' => $story->type === 'text' ? Str::limit((string) $story->body, 200) : $story->body,
                'style' => $story->style,
                'thumbnail' => $media !== null && str_starts_with((string) $media->mime_type, 'image/') ? $media->getUrl() : null,
                'owner' => $story->ownerKey(),
            ],
        ]);
    }

    /**
     * Who watched my story (and how they reacted) — hidden viewers left out.
     *
     * @return list<array<string, mixed>>
     */
    public function viewers(Model $owner, ChatStory $story): array
    {
        $this->assertOwner($owner, $story);

        // Read receipts are mutual: turn yours off and you don't see who watched either.
        if (! $this->privacy->peek($owner)->read_receipts) {
            return [];
        }

        $views = ChatStoryView::query()->where('story_id', $story->id)->where('hidden', false)->latest('viewed_at')->get();
        $this->directory->prime($owner, $views->map(fn ($v) => [$v->viewer_type, $v->viewer_id]));

        return $views->map(fn (ChatStoryView $v) => [
            'viewer' => $this->directory->profile($owner, $v->viewer_type, $v->viewer_id),
            'viewed_at' => $v->viewed_at?->toIso8601String(),
            'reaction' => $v->reaction,
        ])->all();
    }

    // ---------------------------------------------------------------- privacy & mutes

    /**
     * @return array{audience: string, except: list<array<string, mixed>>, only: list<array<string, mixed>>}
     */
    public function privacyOf(Model $owner): array
    {
        $rows = DB::table('chat_story_privacy_members')
            ->where('owner_type', ParticipantType::aliasFor($owner))->where('owner_id', $owner->getKey())->get();
        $this->directory->prime($owner, $rows->map(fn ($r) => [$r->member_type, $r->member_id]));

        $list = fn (string $name) => $rows->where('list', $name)
            ->map(fn ($r) => $this->directory->profile($owner, $r->member_type, $r->member_id))->filter()->values()->all();

        return [
            'audience' => $this->privacy->peek($owner)->story_audience ?? 'contacts',
            'except' => $list('except'),
            'only' => $list('only'),
        ];
    }

    /**
     * @param  list<int>|null  $except  user ids (null = unchanged)
     * @param  list<int>|null  $only  user ids (null = unchanged)
     */
    public function updatePrivacy(Model $owner, string $audience, ?array $except, ?array $only): array
    {
        $ownerType = ParticipantType::aliasFor($owner);

        DB::transaction(function () use ($owner, $ownerType, $audience, $except, $only) {
            $this->privacy->settingsFor($owner)->update(['story_audience' => $audience]);

            foreach (['except' => $except, 'only' => $only] as $name => $ids) {
                if ($ids === null) {
                    continue;
                }
                DB::table('chat_story_privacy_members')->where(['owner_type' => $ownerType, 'owner_id' => $owner->getKey(), 'list' => $name])->delete();
                DB::table('chat_story_privacy_members')->insert(array_map(fn ($id) => [
                    'owner_type' => $ownerType, 'owner_id' => $owner->getKey(), 'list' => $name, 'member_type' => 'user', 'member_id' => (int) $id,
                ], array_values(array_unique($ids))));
            }
        });

        return $this->privacyOf($owner);
    }

    public function mute(Model $me, Model $other, bool $muted): void
    {
        $keys = [
            'owner_type' => ParticipantType::aliasFor($me), 'owner_id' => $me->getKey(),
            'muted_type' => ParticipantType::aliasFor($other), 'muted_id' => $other->getKey(),
        ];

        if ($muted) {
            DB::table('chat_story_mutes')->updateOrInsert($keys, ['created_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('chat_story_mutes')->where($keys)->delete();
        }
    }

    /**
     * Expired stories are deleted with their files (scheduled via chat:purge).
     */
    public function purgeExpired(): int
    {
        $count = 0;
        ChatStory::query()->where('expires_at', '<=', now())->chunkById(200, function ($stories) use (&$count) {
            foreach ($stories as $story) {
                $story->clearMediaCollection(ChatStory::MEDIA);
                $story->delete();
                $count++;
            }
        });

        return $count;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<int, array{views: int, reactions: int}>  $counts
     * @return array<string, mixed>
     */
    private function present(ChatStory $s, ?ChatStoryView $myView, bool $isMine, array $counts): array
    {
        $media = $s->getFirstMedia(ChatStory::MEDIA);

        return [
            'id' => $s->uuid,
            'type' => $s->type,
            'body' => $s->body,
            'style' => $s->style,
            'media' => $media === null ? null : [
                'url' => $media->getUrl(),
                'mime_type' => $media->mime_type,
            ],
            'duration_ms' => $s->duration_ms,
            'allow_replies' => $s->allow_replies,
            'is_mine' => $isMine,
            'seen' => $isMine || $myView !== null,
            'my_reaction' => $myView?->reaction,
            'views' => $isMine ? ($counts[$s->id]['views'] ?? 0) : null,
            'reactions' => $isMine ? ($counts[$s->id]['reactions'] ?? 0) : null,
            'created_at' => $s->created_at?->toIso8601String(),
            'expires_at' => $s->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, ChatStory>  $stories
     * @return array<int, array{views: int, reactions: int}>
     */
    private function viewCounts(Collection $stories): array
    {
        return ChatStoryView::query()->whereIn('story_id', $stories->pluck('id'))->where('hidden', false)
            ->selectRaw('story_id, count(*) as views, sum(case when reaction is not null then 1 else 0 end) as reactions')
            ->groupBy('story_id')->get()
            ->mapWithKeys(fn ($r) => [$r->story_id => ['views' => (int) $r->views, 'reactions' => (int) $r->reactions]])
            ->all();
    }

    private function viewRow(Model $viewer, ChatStory $story): ChatStoryView
    {
        return ChatStoryView::query()->firstOrCreate(
            ['story_id' => $story->id, 'viewer_type' => ParticipantType::aliasFor($viewer), 'viewer_id' => $viewer->getKey()],
            ['viewed_at' => now(), 'hidden' => ! $this->privacy->peek($viewer)->read_receipts],
        );
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    private function recipientsOf(ChatStory $story): array
    {
        return DB::table('chat_story_recipients')->where('story_id', $story->id)->get()
            ->map(fn ($r) => [$r->participant_type, (int) $r->participant_id])->all();
    }

    private function assertEnabled(): ChatSetting
    {
        $settings = ChatSetting::current();

        if (! $settings->stories_enabled) {
            throw ChatException::storiesDisabled();
        }

        return $settings;
    }

    private function assertOwner(Model $owner, ChatStory $story): void
    {
        if (! $story->isOwnedBy(ParticipantType::aliasFor($owner), (int) $owner->getKey())) {
            throw ChatException::storyNotFound();
        }
    }

    private function assertCanSee(Model $viewer, ChatStory $story): void
    {
        $this->assertEnabled();
        $type = ParticipantType::aliasFor($viewer);

        if ($story->expires_at->isPast()) {
            throw ChatException::storyNotFound();
        }

        if ($story->isOwnedBy($type, (int) $viewer->getKey())) {
            return;
        }

        $isRecipient = DB::table('chat_story_recipients')
            ->where(['story_id' => $story->id, 'participant_type' => $type, 'participant_id' => $viewer->getKey()])->exists();

        if (! $isRecipient || $this->blockedBetween($viewer, $story)) {
            throw ChatException::storyNotFound();
        }
    }

    private function blockedBetween(Model $viewer, ChatStory $story): bool
    {
        $owner = ParticipantType::modelClassFor($story->owner_type)::query()->find($story->owner_id);

        return $owner === null || $this->privacy->isBlockedBetween($viewer, $owner);
    }
}
