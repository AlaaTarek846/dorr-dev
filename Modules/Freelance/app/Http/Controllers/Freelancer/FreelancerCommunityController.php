<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceCommunityPost as Post;
use Modules\Freelance\Models\FreelanceCommunityReport;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Rules\CleanUpload;
use Modules\Freelance\Services\Community\Community;
use Modules\Freelance\Services\Community\CommunityModeration;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The professional community in the freelancer app (FL-L12): the feed, a post with its comments, writing, reactions, saves, members and
 * follows, and reports. Freelancers only; the switch is `community`. Lists page with a `cursor` (`meta.next_cursor`).
 */
class FreelancerCommunityController extends Controller
{
    public function __construct(private readonly Community $community, private readonly CommunityModeration $moderation) {}

    /** GET community — limits, today's use, a mute, my card, the kinds / reactions / report reasons and the specialties. */
    public function home(Request $request): JsonResponse
    {
        return ApiResponse::success($this->community->home($this->me($request)), __('api.retrieved'));
    }

    /** GET community/feed?tab=&kind=&specialty_id=&search=&member=&cursor= */
    public function feed(Request $request): JsonResponse
    {
        $f = $request->validate([
            'tab' => ['nullable', Rule::in(Community::TABS)], 'kind' => ['nullable', Rule::in(Post::KINDS)],
            'specialty_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:80'], 'member' => ['nullable', 'uuid'],
        ]);
        $me = $this->me($request);
        $page = $this->community->feed($me, $f);

        return $this->page($this->community->presentPosts($me, $page->items()), $page);
    }

    /** GET community/posts/{uuid} — the post, its comments (each with its first replies). */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $post = $this->community->post($me, $uuid);
        [$page, $replies] = $this->community->comments($me, $post);

        return ApiResponse::success([
            'post' => $this->community->presentPosts($me, [$post])[0],
            'comments' => $this->community->presentComments($me, $page->items(), $post, $replies),
        ], __('api.retrieved'), 200, null, ['next_cursor' => $page->nextCursor()?->encode()]);
    }

    /** GET community/posts/{uuid}/comments?cursor= — more comments. */
    public function comments(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $post = $this->community->post($me, $uuid);
        [$page, $replies] = $this->community->comments($me, $post);

        return $this->page($this->community->presentComments($me, $page->items(), $post, $replies), $page);
    }

    /** GET community/comments/{uuid}/replies?cursor= */
    public function replies(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $comment = $this->community->comment($me, $uuid);
        $page = $this->community->replies($me, $comment);

        return $this->page($this->community->presentComments($me, $page->items(), $comment->post), $page);
    }

    /** POST community/posts (multipart) {kind, body, specialty_id?, portfolio_item? (kind work), images[]?} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', Rule::in(Post::KINDS)],
            'body' => ['required', 'string', 'max:'.(int) config('freelance.community.body_max') * 2],
            'specialty_id' => ['nullable', 'integer'],
            'portfolio_item' => ['nullable', 'required_if:kind,work', 'uuid'],
            'images' => ['nullable', 'array'],
            'images.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=8000,max_height=8000', new CleanUpload],
        ]);
        $me = $this->me($request);
        $post = $this->community->publish($me, $data, $request->file('images', []));

        return ApiResponse::success($this->community->presentPosts($me, [$post])[0], __('freelance.community.posted'), 201);
    }

    /** PUT community/posts/{uuid} {body?, specialty_id?, remove_images[]?} */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:'.(int) config('freelance.community.body_max') * 2],
            'specialty_id' => ['nullable', 'integer'],
            'remove_images' => ['nullable', 'array'], 'remove_images.*' => ['integer'],
        ]);
        $me = $this->me($request);
        $post = $this->community->edit($me, $this->community->post($me, $uuid), $data);

        return ApiResponse::success($this->community->presentPosts($me, [$post])[0], __('freelance.community.saved'));
    }

    /** DELETE community/posts/{uuid} */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $this->community->delete($me, $this->community->post($me, $uuid));

        return ApiResponse::success(null, __('freelance.community.deleted'));
    }

    /** POST community/posts/{uuid}/comments {body, parent?} */
    public function comment(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.(int) config('freelance.community.comment_max') * 2], 'parent' => ['nullable', 'uuid']]);
        $me = $this->me($request);
        $post = $this->community->post($me, $uuid);
        $comment = $this->community->addComment($me, $post, $data['body'], $data['parent'] ?? null);

        return ApiResponse::success($this->community->presentComments($me, [$comment], $post->refresh())[0], __('freelance.community.commented'), 201);
    }

    /** PUT community/comments/{uuid} {body} */
    public function updateComment(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.(int) config('freelance.community.comment_max') * 2]]);
        $me = $this->me($request);
        $comment = $this->community->editComment($me, $this->community->comment($me, $uuid), $data['body']);

        return ApiResponse::success($this->community->presentComments($me, [$comment], $comment->post)[0], __('freelance.community.saved'));
    }

    /** DELETE community/comments/{uuid} */
    public function destroyComment(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $this->community->deleteComment($me, $this->community->comment($me, $uuid));

        return ApiResponse::success(null, __('freelance.community.deleted'));
    }

    /** POST community/posts/{uuid}/accept {comment: uuid|null} — a question's best answer. */
    public function accept(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'uuid']]);
        $me = $this->me($request);
        $post = $this->community->accept($me, $this->community->post($me, $uuid), $data['comment'] ?? null);

        return ApiResponse::success($this->community->presentPosts($me, [$post])[0], __('freelance.community.saved'));
    }

    /** PUT community/{type}/{uuid}/reaction {kind: like|celebrate|insightful|support|null} — type posts|comments. */
    public function react(Request $request, string $type, string $uuid): JsonResponse
    {
        $data = $request->validate(['kind' => ['nullable', Rule::in(Community::REACTIONS)]]);
        $me = $this->me($request);
        $subject = $type === 'posts' ? $this->community->post($me, $uuid) : $this->community->comment($me, $uuid);

        return ApiResponse::success($this->community->react($me, $subject, $data['kind'] ?? null), __('api.retrieved'));
    }

    /** POST|DELETE community/posts/{uuid}/save */
    public function save(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $on = $request->isMethod('post');
        $this->community->save($me, $this->community->post($me, $uuid), $on);

        return ApiResponse::success(['saved' => $on], __($on ? 'freelance.community.saved_post' : 'freelance.community.unsaved_post'));
    }

    /** GET community/members/{uuid} — a member's card. */
    public function member(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success($this->community->member($this->me($request), $uuid), __('api.retrieved'));
    }

    /** POST|DELETE community/members/{uuid}/follow */
    public function follow(Request $request, string $uuid): JsonResponse
    {
        $on = $request->isMethod('post');

        return ApiResponse::success($this->community->follow($this->me($request), $uuid, $on), __($on ? 'freelance.community.followed' : 'freelance.community.unfollowed'));
    }

    /** GET community/members/{uuid}/{followers|following}?cursor= */
    public function people(Request $request, string $uuid, string $which): JsonResponse
    {
        $me = $this->me($request);
        $page = $this->community->people($me, $uuid, $which);

        return $this->page(collect($page->items())->map(fn (Freelancer $f) => $this->community->author($f, $me, null))->values()->all(), $page);
    }

    /** POST community/reports {subject_type: post|comment, subject: uuid, reason, details?} */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['post', 'comment'])], 'subject' => ['required', 'uuid'],
            'reason' => ['required', Rule::in(FreelanceCommunityReport::REASONS)], 'details' => ['nullable', 'string', 'max:1000'],
        ]);
        $report = $this->moderation->report($this->me($request), $data['subject_type'], $data['subject'], $data['reason'], $data['details'] ?? null, $this->community);

        return ApiResponse::success(['uuid' => $report->uuid, 'status' => $report->status], __('freelance.community.reported'), 201);
    }

    /** GET community-images/{media} — signed and short-lived; the post must still be shown. */
    public static function image(Media $media): BinaryFileResponse
    {
        $post = $media->model;
        abort_unless($post instanceof Post && $media->collection_name === 'images' && ! $post->trashed() && ! $post->isHidden(), 404);

        return FreelancerPortfolioController::file($media);
    }

    // -------------------------------------------------------------- inside

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }

    private function page(array $rows, CursorPaginator $page): JsonResponse
    {
        return ApiResponse::success($rows, __('api.retrieved'), 200, null, ['next_cursor' => $page->nextCursor()?->encode(), 'has_more' => $page->hasMorePages()]);
    }
}
