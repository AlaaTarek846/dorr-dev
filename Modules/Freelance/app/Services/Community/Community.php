<?php

namespace Modules\Freelance\Services\Community;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceCommunityComment as Comment;
use Modules\Freelance\Models\FreelanceCommunityMute;
use Modules\Freelance\Models\FreelanceCommunityPost as Post;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerPortfolioItem;
use Modules\Freelance\Services\Chat\ContactGuard;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Plans\FreelancePerks;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The professional community (FL-L12): a feed of posts for freelancers only, per country. A member posts (a post, a question, or a
 * finished work from their portfolio), comments with one level of replies, reacts, saves and follows other members. Writing needs a
 * verified account, no mute from the staff, no contact details (the chat's rule) and stays within a daily limit.
 */
class Community
{
    public const REACTIONS = ['like', 'celebrate', 'insightful', 'support'];

    public const TABS = ['for_you', 'following', 'specialty', 'mine', 'saved'];

    public function __construct(
        private readonly ContactGuard $contact,
        private readonly FreelanceNotifier $notifier,
        private readonly FreelancePerks $perks,
    ) {}

    // -------------------------------------------------------------- reading

    /**
     * The feed. `tab`: for_you (everyone in my country) · following · specialty (my specialties, or `specialty_id`) · mine · saved; plus
     * `kind`, `search`, `member` (one member's posts) and the `cursor` of the next page.
     *
     * @param  array{tab?: ?string, kind?: ?string, specialty_id?: ?int, search?: ?string, member?: ?string}  $f
     */
    public function feed(Freelancer $me, array $f): CursorPaginator
    {
        $tab = $f['tab'] ?? 'for_you';
        $q = $this->visible($me)->with(['freelancer.media', 'specialty.translations', 'media', 'portfolioItem.media']);

        if (! empty($f['member'])) {
            $q->whereHas('freelancer', fn ($w) => $w->where('uuid', $f['member']));
        } elseif ($tab === 'following') {
            $q->whereIn('freelancer_id', DB::table('freelance_community_follows')->where('follower_id', $me->id)->select('followed_id'));
        } elseif ($tab === 'specialty') {
            $ids = ! empty($f['specialty_id']) ? [(int) $f['specialty_id']] : $me->specialties()->pluck('freelance_specialties.id')->all();
            $q->whereIn('freelance_specialty_id', $ids ?: [0]);
        } elseif ($tab === 'mine') {
            $q->where('freelancer_id', $me->id);
        } elseif ($tab === 'saved') {
            $q->whereIn('id', DB::table('freelance_community_saves')->where('freelancer_id', $me->id)->select('post_id'));
        }
        if (! empty($f['specialty_id']) && $tab !== 'specialty') {
            $q->where('freelance_specialty_id', (int) $f['specialty_id']);
        }
        if (! empty($f['kind'])) {
            $q->where('kind', $f['kind']);
        }
        if (($s = trim((string) ($f['search'] ?? ''))) !== '') {
            $q->where('body', 'like', '%'.addcslashes(mb_substr($s, 0, 80), '%_\\').'%');
        }

        return $q->orderByDesc('id')->cursorPaginate(min(max((int) request()->input('per_page', $this->cfg('per_page')), 1), 30));
    }

    /** One post the member may see (their own even when hidden). */
    public function post(Freelancer $me, string $uuid): Post
    {
        return $this->visible($me)->where('uuid', $uuid)->with(['freelancer.media', 'specialty.translations', 'media', 'portfolioItem.media'])->firstOrFail();
    }

    /**
     * The comments of a post: the best answer first (a question), then the oldest; each with its first replies.
     *
     * @return array{0: CursorPaginator, 1: Collection<int, Collection<int, Comment>>}
     */
    public function comments(Freelancer $me, Post $post): array
    {
        $page = $this->visibleComments($me)->where('post_id', $post->id)->whereNull('parent_id')->with('freelancer.media')
            ->orderByRaw('case when id = ? then 0 else 1 end', [(int) $post->accepted_comment_id])->orderBy('id')
            ->cursorPaginate(min(max((int) request()->input('per_page', 20), 1), 50));
        $replies = $this->visibleComments($me)->whereIn('parent_id', $page->getCollection()->pluck('id'))->with('freelancer.media')->orderBy('id')->get()
            ->groupBy('parent_id')->map(fn (Collection $rows) => $rows->take(3)->values());

        return [$page, $replies];
    }

    public function replies(Freelancer $me, Comment $comment): CursorPaginator
    {
        return $this->visibleComments($me)->where('parent_id', $comment->id)->with('freelancer.media')->orderBy('id')
            ->cursorPaginate(min(max((int) request()->input('per_page', 20), 1), 50));
    }

    public function comment(Freelancer $me, string $uuid): Comment
    {
        $c = $this->visibleComments($me)->where('uuid', $uuid)->with('post')->firstOrFail();
        // Its post must be one the member may see.
        $this->post($me, $c->post->uuid);

        return $c;
    }

    /** A member's card: who they are, their numbers and whether I follow them. */
    public function member(Freelancer $me, string $uuid): array
    {
        // Others are shown only once verified; my own card always.
        $f = Freelancer::query()->where('uuid', $uuid)->where(fn ($q) => $q->where('status', FreelancerStatus::Verified->value)->orWhere('id', $me->id))
            ->with(['media', 'specialties.translations'])->firstOrFail();

        return [
            'uuid' => $f->uuid, 'name' => $f->name, 'headline' => $f->headline, 'bio' => $f->bio, 'avatar_url' => $f->getFirstMediaUrl('avatar') ?: null,
            'badge' => $this->perks->badge($f), 'verified' => $f->status === FreelancerStatus::Verified,
            'specialties' => $f->specialties->map(fn (FreelanceSpecialty $s) => ['id' => $s->id, 'name' => $s->translatedName(), 'primary' => (bool) $s->pivot->is_primary])->values()->all(),
            'posts' => Post::query()->where('freelancer_id', $f->id)->where('status', Post::PUBLISHED)->count(),
            'followers' => DB::table('freelance_community_follows')->where('followed_id', $f->id)->count(),
            'following' => DB::table('freelance_community_follows')->where('follower_id', $f->id)->count(),
            'is_me' => $f->id === $me->id,
            'is_following' => DB::table('freelance_community_follows')->where('follower_id', $me->id)->where('followed_id', $f->id)->exists(),
        ];
    }

    /** What the screens need first: the limits, today's use, a mute, my numbers and the specialties to filter by. */
    public function home(Freelancer $me): array
    {
        $mute = $this->mute($me);

        return [
            'me' => $this->member($me, $me->uuid) + ['saved' => DB::table('freelance_community_saves')->where('freelancer_id', $me->id)->count()],
            'can_write' => $me->status === FreelancerStatus::Verified && $mute === null,
            'muted' => $mute === null ? null : ['until' => $mute->ends_at->toIso8601String(), 'reason' => $mute->reason],
            'limits' => [
                'body_min' => $this->cfg('body_min'), 'body_max' => $this->cfg('body_max'), 'comment_max' => $this->cfg('comment_max'), 'max_images' => $this->cfg('max_images'),
                'posts_per_day' => $this->cfg('posts_per_day'), 'posts_today' => $this->today(Post::query(), $me),
                'comments_per_day' => $this->cfg('comments_per_day'), 'comments_today' => $this->today(Comment::query(), $me),
            ],
            'kinds' => collect(Post::KINDS)->map(fn (string $k) => ['key' => $k, 'label' => __('freelance.community.kinds.'.$k)])->all(),
            'reactions' => collect(self::REACTIONS)->map(fn (string $k) => ['key' => $k, 'label' => __('freelance.community.reactions.'.$k)])->all(),
            'report_reasons' => collect(\Modules\Freelance\Models\FreelanceCommunityReport::REASONS)->map(fn (string $k) => ['key' => $k, 'label' => __('freelance.community.report_reasons.'.$k)])->all(),
            'rules' => __('freelance.community.rules'),
            'specialties' => FreelanceSpecialty::query()->where('status', true)->whereNull('parent_id')->with('translations')->orderBy('id')->get()
                ->map(fn (FreelanceSpecialty $s) => ['id' => $s->id, 'name' => $s->translatedName()])->values()->all(),
            'my_specialties' => $me->specialties()->pluck('freelance_specialties.id')->all(),
        ];
    }

    // -------------------------------------------------------------- writing

    /**
     * @param  array{kind?: ?string, body: string, specialty_id?: ?int, portfolio_item?: ?string}  $data
     * @param  list<UploadedFile>  $images
     */
    public function publish(Freelancer $me, array $data, array $images = []): Post
    {
        $this->mayWrite($me);
        $body = $this->clean($data['body'], (int) $this->cfg('body_max'));
        if (mb_strlen($body) < (int) $this->cfg('body_min')) {
            throw new FreelanceException('community_body_short', 422, ['min' => $this->cfg('body_min')]);
        }
        $kind = $data['kind'] ?? Post::POST;
        $item = null;
        if ($kind === Post::WORK) {
            $item = FreelancerPortfolioItem::query()->where('uuid', (string) ($data['portfolio_item'] ?? ''))->where('freelancer_id', $me->id)->first();
            if ($item === null || $item->isHidden() || $item->withdrawnByClient() || $item->visibility === FreelancerPortfolioItem::PRIVATE) {
                throw new FreelanceException('community_work_invalid', 422);
            }
        }
        if (count($images) > (int) $this->cfg('max_images')) {
            throw new FreelanceException('community_images_max', 422, ['max' => $this->cfg('max_images')]);
        }

        return DB::transaction(function () use ($me, $kind, $body, $data, $item, $images): Post {
            // One at a time per member, so two quick taps do not pass the daily limit together.
            Freelancer::query()->whereKey($me->id)->lockForUpdate()->first();
            if ($this->today(Post::query(), $me) >= (int) $this->cfg('posts_per_day')) {
                throw new FreelanceException('community_daily_posts', 429, ['max' => $this->cfg('posts_per_day')]);
            }
            $post = Post::query()->create([
                'freelancer_id' => $me->id, 'country_id' => $me->country_id, 'kind' => $kind, 'body' => $body,
                'freelance_specialty_id' => $this->specialty($data['specialty_id'] ?? null, $item?->freelance_specialty_id ?? $this->primarySpecialty($me)),
                'portfolio_item_id' => $item?->id, 'status' => Post::PUBLISHED,
            ]);
            foreach ($images as $i => $file) {
                $post->addImage($file, $i + 1);
            }

            return $post->load(['freelancer.media', 'specialty.translations', 'media', 'portfolioItem.media']);
        });
    }

    /** @param  array{body?: ?string, specialty_id?: ?int, remove_images?: list<int>}  $data */
    public function edit(Freelancer $me, Post $post, array $data): Post
    {
        $this->mayWrite($me);
        $this->own($me, $post->freelancer_id);
        if (array_key_exists('body', $data) && $data['body'] !== null) {
            $body = $this->clean($data['body'], (int) $this->cfg('body_max'));
            if (mb_strlen($body) < (int) $this->cfg('body_min')) {
                throw new FreelanceException('community_body_short', 422, ['min' => $this->cfg('body_min')]);
            }
            $post->body = $body;
        }
        if (array_key_exists('specialty_id', $data)) {
            $post->freelance_specialty_id = $this->specialty($data['specialty_id'], null);
        }
        foreach ($data['remove_images'] ?? [] as $id) {
            $post->getMedia('images')->firstWhere('id', (int) $id)?->delete();
        }
        if ($post->isDirty()) {
            $post->edited_at = now();
            $post->save();
        }

        return $post->fresh(['freelancer.media', 'specialty.translations', 'media', 'portfolioItem.media']);
    }

    public function delete(Freelancer $me, Post $post): void
    {
        $this->own($me, $post->freelancer_id);
        $post->delete();
    }

    public function addComment(Freelancer $me, Post $post, string $body, ?string $parentUuid): Comment
    {
        $this->mayWrite($me);
        if ($post->isHidden()) {
            throw new FreelanceException('community_post_hidden', 422);
        }
        $body = $this->clean($body, (int) $this->cfg('comment_max'));
        if ($body === '') {
            throw new FreelanceException('community_comment_empty', 422);
        }
        $parent = null;
        $answered = null;
        if ($parentUuid !== null && $parentUuid !== '') {
            $parent = $answered = Comment::query()->where('uuid', $parentUuid)->where('post_id', $post->id)->where('status', Post::PUBLISHED)->first();
            // A reply to a reply goes under the same comment: one level only (its writer still hears of the answer).
            if ($parent?->parent_id !== null) {
                $parent = Comment::query()->find($parent->parent_id);
            }
            if ($parent === null) {
                throw new FreelanceException('community_comment_parent_invalid', 422);
            }
        }

        $comment = DB::transaction(function () use ($me, $post, $body, $parent): Comment {
            Freelancer::query()->whereKey($me->id)->lockForUpdate()->first();
            if ($this->today(Comment::query(), $me) >= (int) $this->cfg('comments_per_day')) {
                throw new FreelanceException('community_daily_comments', 429, ['max' => $this->cfg('comments_per_day')]);
            }
            $c = Comment::query()->create(['post_id' => $post->id, 'freelancer_id' => $me->id, 'parent_id' => $parent?->id, 'body' => $body, 'status' => Post::PUBLISHED]);
            Post::query()->whereKey($post->id)->increment('comments_count');
            if ($parent !== null) {
                Comment::query()->whereKey($parent->id)->increment('replies_count');
            }

            return $c;
        });

        // The writer of the comment answered and the author of the post hear of it — once each, never of their own words.
        $told = [];
        foreach (array_filter([$answered, $parent]) as $c) {
            if ($c->freelancer_id !== $me->id && ! in_array($c->freelancer_id, $told, true)) {
                $this->tell($c->freelancer, 'community.replied', $me, $post, $comment);
                $told[] = $c->freelancer_id;
            }
        }
        if ($post->freelancer_id !== $me->id && ! in_array($post->freelancer_id, $told, true)) {
            $this->tell($post->freelancer, 'community.commented', $me, $post, $comment);
        }

        return $comment->load('freelancer.media');
    }

    public function editComment(Freelancer $me, Comment $comment, string $body): Comment
    {
        $this->mayWrite($me);
        $this->own($me, $comment->freelancer_id);
        $body = $this->clean($body, (int) $this->cfg('comment_max'));
        if ($body === '') {
            throw new FreelanceException('community_comment_empty', 422);
        }
        $comment->fill(['body' => $body, 'edited_at' => now()])->save();

        return $comment->load('freelancer.media');
    }

    public function deleteComment(Freelancer $me, Comment $comment): void
    {
        $this->own($me, $comment->freelancer_id);
        DB::transaction(function () use ($comment): void {
            $gone = 1 + ($comment->parent_id === null ? Comment::query()->where('parent_id', $comment->id)->count() : 0);
            Post::query()->whereKey($comment->post_id)->where('comments_count', '>=', $gone)->decrement('comments_count', $gone);
            if ($comment->parent_id !== null) {
                Comment::query()->whereKey($comment->parent_id)->where('replies_count', '>', 0)->decrement('replies_count');
            } else {
                Comment::query()->where('parent_id', $comment->id)->delete();
            }
            Post::query()->whereKey($comment->post_id)->where('accepted_comment_id', $comment->id)->update(['accepted_comment_id' => null]);
            $comment->delete();
        });
    }

    /** A question's author marks the best answer (a comment on it), or takes the mark off (null). */
    public function accept(Freelancer $me, Post $post, ?string $commentUuid): Post
    {
        $this->own($me, $post->freelancer_id);
        if ($post->kind !== Post::QUESTION) {
            throw new FreelanceException('community_not_question', 422);
        }
        $comment = null;
        if ($commentUuid !== null) {
            $comment = Comment::query()->where('uuid', $commentUuid)->where('post_id', $post->id)->whereNull('parent_id')->where('status', Post::PUBLISHED)->first()
                ?? throw new FreelanceException('community_answer_invalid', 422);
        }
        $post->forceFill(['accepted_comment_id' => $comment?->id])->save();
        if ($comment !== null && $comment->freelancer_id !== $me->id) {
            $this->notifier->notify($comment->freelancer, 'community.answer_accepted', ['name' => $me->name], ['community_post' => $post->uuid, 'community_comment' => $comment->uuid]);
        }

        return $post->fresh(['freelancer.media', 'specialty.translations', 'media', 'portfolioItem.media']);
    }

    /** Sets, changes or (null) takes back my reaction on a post or a comment; returns the new count and mine. */
    public function react(Freelancer $me, Post|Comment $subject, ?string $kind): array
    {
        $this->mayWrite($me);
        $type = $subject instanceof Post ? 'post' : 'comment';

        return DB::transaction(function () use ($me, $subject, $type, $kind): array {
            $row = DB::table('freelance_community_reactions')->where(['freelancer_id' => $me->id, 'subject_type' => $type, 'subject_id' => $subject->id])->lockForUpdate()->first();
            $model = $subject::query()->whereKey($subject->id);
            if ($kind === null) {
                if ($row !== null) {
                    DB::table('freelance_community_reactions')->where('id', $row->id)->delete();
                    (clone $model)->where('reactions_count', '>', 0)->decrement('reactions_count');
                }
            } elseif ($row === null) {
                DB::table('freelance_community_reactions')->insert(['freelancer_id' => $me->id, 'subject_type' => $type, 'subject_id' => $subject->id, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now()]);
                (clone $model)->increment('reactions_count');
            } else {
                DB::table('freelance_community_reactions')->where('id', $row->id)->update(['kind' => $kind, 'updated_at' => now()]);
            }

            return ['reactions' => (int) (clone $model)->value('reactions_count'), 'my_reaction' => $kind, 'top' => $this->topReactions($type, [$subject->id])[$subject->id] ?? []];
        });
    }

    public function save(Freelancer $me, Post $post, bool $on): void
    {
        $on
            ? DB::table('freelance_community_saves')->insertOrIgnore(['freelancer_id' => $me->id, 'post_id' => $post->id, 'created_at' => now(), 'updated_at' => now()])
            : DB::table('freelance_community_saves')->where(['freelancer_id' => $me->id, 'post_id' => $post->id])->delete();
    }

    public function follow(Freelancer $me, string $uuid, bool $on): array
    {
        $other = Freelancer::query()->where('uuid', $uuid)->where('status', FreelancerStatus::Verified->value)->firstOrFail();
        if ($other->id === $me->id) {
            throw new FreelanceException('community_follow_self', 422);
        }
        if (! $on) {
            DB::table('freelance_community_follows')->where(['follower_id' => $me->id, 'followed_id' => $other->id])->delete();
        } elseif (DB::table('freelance_community_follows')->insertOrIgnore(['follower_id' => $me->id, 'followed_id' => $other->id, 'created_at' => now(), 'updated_at' => now()]) > 0) {
            $this->notifier->notify($other, 'community.followed', ['name' => $me->name], ['community_member' => $me->uuid]);
        }

        return $this->member($me, $uuid);
    }

    /** The members who follow (`followers`) or are followed by (`following`) someone. */
    public function people(Freelancer $me, string $uuid, string $which): CursorPaginator
    {
        $f = Freelancer::query()->where('uuid', $uuid)->firstOrFail();
        [$mine, $theirs] = $which === 'followers' ? ['followed_id', 'follower_id'] : ['follower_id', 'followed_id'];

        return Freelancer::query()->where('status', FreelancerStatus::Verified->value)->with('media')
            ->whereIn('id', DB::table('freelance_community_follows')->where($mine, $f->id)->select($theirs))
            ->orderBy('id')->cursorPaginate(30);
    }

    // -------------------------------------------------------------- presenting

    /**
     * The posts as the member sees them; their reactions, saves and follows are read for the whole page at once.
     *
     * @param  iterable<Post>  $posts
     * @return list<array<string, mixed>>
     */
    public function presentPosts(Freelancer $me, iterable $posts): array
    {
        $posts = collect($posts);
        $ids = $posts->pluck('id')->all();
        $mine = DB::table('freelance_community_reactions')->where('freelancer_id', $me->id)->where('subject_type', 'post')->whereIn('subject_id', $ids)->pluck('kind', 'subject_id');
        $saved = DB::table('freelance_community_saves')->where('freelancer_id', $me->id)->whereIn('post_id', $ids)->pluck('post_id')->flip();
        $following = DB::table('freelance_community_follows')->where('follower_id', $me->id)->whereIn('followed_id', $posts->pluck('freelancer_id'))->pluck('followed_id')->flip();
        $top = $this->topReactions('post', $ids);

        return $posts->map(function (Post $p) use ($me, $mine, $saved, $following, $top): array {
            $own = $p->freelancer_id === $me->id;

            return [
                'uuid' => $p->uuid, 'kind' => $p->kind, 'kind_label' => __('freelance.community.kinds.'.$p->kind), 'body' => $p->body,
                'author' => $this->author($p->freelancer, $me, isset($following[$p->freelancer_id])),
                'specialty' => $p->specialty === null ? null : ['id' => $p->specialty->id, 'name' => $p->specialty->translatedName()],
                'images' => $this->images($p),
                'work' => $this->work($p),
                'reactions' => $p->reactions_count, 'top_reactions' => $top[$p->id] ?? [], 'my_reaction' => $mine[$p->id] ?? null,
                'comments' => $p->comments_count, 'saved' => isset($saved[$p->id]),
                'accepted_comment' => $p->accepted_comment_id === null ? null : Comment::query()->whereKey($p->accepted_comment_id)->value('uuid'),
                'hidden' => $p->isHidden() ? ['reason' => $p->hidden_reason, 'under_review' => $p->hidden_by_admin_id === null] : null,
                'edited' => $p->edited_at !== null, 'created_at' => $p->created_at?->toIso8601String(),
                'can' => ['edit' => $own && ! $p->isHidden(), 'delete' => $own, 'report' => ! $own, 'accept' => $own && $p->kind === Post::QUESTION],
            ];
        })->values()->all();
    }

    /**
     * @param  iterable<Comment>  $comments
     * @param  Collection<int, Collection<int, Comment>>|null  $replies  the first replies of each comment
     * @return list<array<string, mixed>>
     */
    public function presentComments(Freelancer $me, iterable $comments, ?Post $post = null, ?Collection $replies = null): array
    {
        $comments = collect($comments);
        $all = $comments->merge($replies?->flatten(1) ?? []);
        $mine = DB::table('freelance_community_reactions')->where('freelancer_id', $me->id)->where('subject_type', 'comment')->whereIn('subject_id', $all->pluck('id'))->pluck('kind', 'subject_id');
        $one = function (Comment $c) use ($me, $mine, $post): array {
            $own = $c->freelancer_id === $me->id;

            return [
                'uuid' => $c->uuid, 'body' => $c->body, 'author' => $this->author($c->freelancer, $me, null),
                'parent' => $c->parent_id === null ? null : Comment::query()->whereKey($c->parent_id)->value('uuid'),
                'reactions' => $c->reactions_count, 'my_reaction' => $mine[$c->id] ?? null, 'replies' => $c->replies_count,
                'accepted' => $post !== null && $post->accepted_comment_id === $c->id,
                'hidden' => $c->isHidden() ? ['reason' => $c->hidden_reason] : null,
                'edited' => $c->edited_at !== null, 'created_at' => $c->created_at?->toIso8601String(),
                'can' => ['edit' => $own && ! $c->isHidden(), 'delete' => $own, 'report' => ! $own],
            ];
        };

        return $comments->map(fn (Comment $c) => $one($c) + ($replies === null ? [] : ['first_replies' => ($replies[$c->id] ?? collect())->map($one)->values()->all()]))->values()->all();
    }

    /** @return array<string, mixed> */
    public function author(?Freelancer $f, Freelancer $me, ?bool $following): array
    {
        return [
            'uuid' => $f?->uuid, 'name' => $f?->name, 'headline' => $f?->headline, 'avatar_url' => $f?->getFirstMediaUrl('avatar') ?: null,
            'badge' => $this->perks->badge($f), 'is_me' => $f?->id === $me->id, 'is_following' => $following,
        ];
    }

    // -------------------------------------------------------------- inside

    /** Posts the member may see: published ones of verified members in their country, and their own whatever the state. */
    private function visible(Freelancer $me): Builder
    {
        return Post::query()->where(fn ($q) => $q
            ->where(fn ($w) => $w->where('country_id', $me->country_id)->where('status', Post::PUBLISHED)
                ->whereHas('freelancer', fn ($a) => $a->where('status', FreelancerStatus::Verified->value)))
            ->orWhere('freelancer_id', $me->id));
    }

    private function visibleComments(Freelancer $me): Builder
    {
        return Comment::query()->where(fn ($q) => $q->where('status', Post::PUBLISHED)->orWhere('freelancer_id', $me->id));
    }

    /** Verified, not muted. */
    private function mayWrite(Freelancer $me): void
    {
        if ($me->status !== FreelancerStatus::Verified) {
            throw new FreelanceException('community_not_verified', 403);
        }
        if (($mute = $this->mute($me)) !== null) {
            throw new FreelanceException('community_muted', 403, ['date' => $mute->ends_at->toDateString()], ['until' => $mute->ends_at->toIso8601String()]);
        }
    }

    public function mute(Freelancer $me): ?FreelanceCommunityMute
    {
        return FreelanceCommunityMute::query()->where('freelancer_id', $me->id)->whereNull('lifted_at')->where('ends_at', '>', now())->orderByDesc('ends_at')->first();
    }

    /** Plain text, trimmed and cut; contact details are refused (the chat's rule). */
    private function clean(string $text, int $max): string
    {
        $text = trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", strip_tags($text))) ?? '');
        $text = mb_substr($text, 0, $max);
        if (($found = $this->contact->find($text)) !== null) {
            throw new FreelanceException('community_contact_blocked', 422, ['what' => __('freelance.community.contact_kinds.'.$found)], ['found' => $found]);
        }

        return $text;
    }

    private function own(Freelancer $me, int $authorId): void
    {
        if ($authorId !== $me->id) {
            throw new FreelanceException('community_not_yours', 403);
        }
    }

    private function today(Builder $q, Freelancer $me): int
    {
        return $q->withTrashed()->where('freelancer_id', $me->id)->where('created_at', '>=', now()->subDay())->count();
    }

    private function specialty(mixed $id, ?int $fallback): ?int
    {
        if ($id === null || $id === '') {
            return $fallback;
        }
        if (! FreelanceSpecialty::query()->where('status', true)->whereKey((int) $id)->exists()) {
            throw new FreelanceException('community_specialty_invalid', 422);
        }

        return (int) $id;
    }

    private function primarySpecialty(Freelancer $me): ?int
    {
        $s = $me->specialties()->orderByDesc('freelancer_specialties.is_primary')->first();

        return $s?->id;
    }

    /** @return list<array{id: int, url: string}> */
    private function images(Post $p): array
    {
        return $p->getMedia('images')->sortBy('order_column')->map(fn (Media $m) => [
            'id' => $m->id,
            // Signed on the path only, so an app that swaps the host (a local server's name) still opens it.
            'url' => url(URL::temporarySignedRoute('api.freelance.community.image', now()->addMinutes((int) $this->cfg('image_link_minutes')), ['media' => $m->id], absolute: false)),
        ])->values()->all();
    }

    private function work(Post $p): ?array
    {
        $i = $p->portfolioItem;
        if ($i === null || $i->isHidden() || $i->withdrawnByClient() || $i->visibility === FreelancerPortfolioItem::PRIVATE) {
            return null;
        }
        $first = $i->getMedia('images')->sortBy('order_column')->first();

        return [
            'uuid' => $i->uuid, 'title' => $i->title,
            'image_url' => $first === null ? null : URL::temporarySignedRoute('api.freelance.portfolio.image', now()->addMinutes((int) config('freelance.portfolio.link_minutes', 30)), ['media' => $first->id]),
        ];
    }

    /** @return array<int, list<string>> the (up to three) most used reactions of each subject */
    private function topReactions(string $type, array $ids): array
    {
        return DB::table('freelance_community_reactions')->where('subject_type', $type)->whereIn('subject_id', $ids)
            ->selectRaw('subject_id, kind, count(*) as n')->groupBy('subject_id', 'kind')->get()
            ->groupBy('subject_id')->map(fn ($rows) => $rows->sortByDesc('n')->take(3)->pluck('kind')->values()->all())->all();
    }

    private function tell(?Freelancer $to, string $event, Freelancer $me, Post $post, Comment $comment): void
    {
        if ($to !== null) {
            $this->notifier->notify($to, $event, ['name' => $me->name], ['community_post' => $post->uuid, 'community_comment' => $comment->uuid]);
        }
    }

    private function cfg(string $key): mixed
    {
        return config('freelance.community.'.$key);
    }
}
