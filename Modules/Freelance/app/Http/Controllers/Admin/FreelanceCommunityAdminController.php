<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceCommunityComment as Comment;
use Modules\Freelance\Models\FreelanceCommunityMute as Mute;
use Modules\Freelance\Models\FreelanceCommunityPost as Post;
use Modules\Freelance\Models\FreelanceCommunityReport as Report;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Community\CommunityModeration;

/**
 * FL-L12, the staff's side of the professional community: the numbers, the reports (uphold hides and may mute the author, dismiss leaves
 * it), the posts with a search, hiding / showing a post or a comment, and the members who are muted. Permission group `freelance-community`.
 */
class FreelanceCommunityAdminController extends Controller implements HasMiddleware
{
    public function __construct(private readonly CommunityModeration $moderation) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-community', [
            ['view', ['overview', 'reports', 'report', 'posts', 'post', 'mutes']],
            ['update', ['decide', 'hide', 'unhide', 'mute', 'lift']],
        ]);
    }

    /** GET freelance-community/overview */
    public function overview(): JsonResponse
    {
        $reports = Report::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return ApiResponse::success([
            'posts' => ['total' => Post::query()->count(), 'week' => Post::query()->where('created_at', '>=', now()->subWeek())->count(), 'hidden' => Post::query()->where('status', Post::HIDDEN)->count()],
            'comments' => ['total' => Comment::query()->count(), 'week' => Comment::query()->where('created_at', '>=', now()->subWeek())->count()],
            'members' => ['posting_month' => Post::query()->where('created_at', '>=', now()->subMonth())->distinct()->count('freelancer_id'), 'muted' => Mute::query()->whereNull('lifted_at')->where('ends_at', '>', now())->distinct()->count('freelancer_id')],
            'reports' => collect(Report::STATUSES)->mapWithKeys(fn (string $s) => [$s => (int) ($reports[$s] ?? 0)]),
        ], __('api.retrieved'));
    }

    /** GET freelance-community/reports[?status=&per_page=] — open first; `counts` per status. */
    public function reports(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(Report::STATUSES)], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = $this->moderation->reports($f['status'] ?? null, (int) ($f['per_page'] ?? 20));
        $counts = Report::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return ApiResponse::success($page->getCollection()->map(fn (Report $r) => $this->moderation->presentReport($r))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page),
            ['counts' => collect(Report::STATUSES)->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])]);
    }

    /** GET freelance-community/reports/{uuid} */
    public function report(string $uuid): JsonResponse
    {
        return ApiResponse::success($this->moderation->presentReport(Report::query()->where('uuid', $uuid)->with(['reporter', 'author'])->firstOrFail()), __('api.retrieved'));
    }

    /** POST freelance-community/reports/{uuid}/decide {decision: uphold|dismiss, note?, mute_days? (uphold)} */
    public function decide(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['uphold', 'dismiss'])], 'note' => ['nullable', 'string', 'max:1000'],
            'mute_days' => ['nullable', 'integer', 'min:0', 'max:'.(int) config('freelance.community.max_mute_days')],
        ]);
        $report = $this->moderation->decide(Report::query()->where('uuid', $uuid)->firstOrFail(), $data['decision'], $data['note'] ?? null,
            $data['decision'] === 'uphold' ? (int) ($data['mute_days'] ?? 0) : 0, $this->adminId($request));

        return ApiResponse::success($this->moderation->presentReport($report->load(['reporter', 'author'])), __('freelance.community.report_decided'));
    }

    /** GET freelance-community/posts[?status=&kind=&search=&country_id=&per_page=] */
    public function posts(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in([Post::PUBLISHED, Post::HIDDEN])], 'kind' => ['nullable', Rule::in(Post::KINDS)],
            'search' => ['nullable', 'string', 'max:80'], 'country_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = Post::query()->with(['freelancer', 'specialty.translations'])
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['kind']), fn ($q) => $q->where('kind', $f['kind']))
            ->when(! empty($f['country_id']), fn ($q) => $q->where('country_id', (int) $f['country_id']))
            ->when(! empty($f['search']), fn ($q) => $q->where(fn ($w) => $w->where('body', 'like', '%'.addcslashes($f['search'], '%_\\').'%')
                ->orWhereHas('freelancer', fn ($a) => $a->where('name', 'like', '%'.addcslashes($f['search'], '%_\\').'%'))))
            ->orderByDesc('id')->paginate((int) ($f['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->map(fn (Post $p) => $this->presentPost($p))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** GET freelance-community/posts/{uuid} — the post and all its comments. */
    public function post(string $uuid): JsonResponse
    {
        $p = Post::withTrashed()->where('uuid', $uuid)->with(['freelancer', 'specialty.translations'])->firstOrFail();
        $comments = Comment::withTrashed()->where('post_id', $p->id)->with('freelancer')->orderBy('id')->get()->map(fn (Comment $c) => [
            'uuid' => $c->uuid, 'body' => $c->body, 'parent' => $c->parent_id, 'id' => $c->id, 'status' => $c->trashed() ? 'deleted' : $c->status,
            'hidden_reason' => $c->hidden_reason, 'author' => ['id' => $c->freelancer?->id, 'name' => $c->freelancer?->name], 'created_at' => $c->created_at?->toIso8601String(),
        ]);

        // `comments` is a count in the list row; here it is the comments themselves.
        return ApiResponse::success(array_merge($this->presentPost($p), ['comments' => $comments, 'comments_count' => $p->comments_count]), __('api.retrieved'));
    }

    /** POST freelance-community/{posts|comments}/{uuid}/hide {reason} */
    public function hide(Request $request, string $type, string $uuid): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $this->moderation->hide($this->subject($type, $uuid), $data['reason'], $this->adminId($request));

        return ApiResponse::success(null, __('freelance.community.hidden'));
    }

    /** POST freelance-community/{posts|comments}/{uuid}/unhide */
    public function unhide(Request $request, string $type, string $uuid): JsonResponse
    {
        $this->moderation->unhide($this->subject($type, $uuid), $this->adminId($request));

        return ApiResponse::success(null, __('freelance.community.unhidden'));
    }

    /** GET freelance-community/mutes — who is muted now. */
    public function mutes(): JsonResponse
    {
        $rows = Mute::query()->with('freelancer')->whereNull('lifted_at')->where('ends_at', '>', now())->orderBy('ends_at')->get()->map(fn (Mute $m) => [
            'id' => $m->id, 'freelancer' => ['id' => $m->freelancer?->id, 'name' => $m->freelancer?->name, 'phone' => $m->freelancer?->phone],
            'ends_at' => $m->ends_at->toIso8601String(), 'reason' => $m->reason, 'created_at' => $m->created_at?->toIso8601String(),
        ]);

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    /** POST freelance-community/mutes {freelancer_id, days, reason} */
    public function mute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'freelancer_id' => ['required', 'integer', 'exists:freelancers,id'],
            'days' => ['required', 'integer', 'min:1', 'max:'.(int) config('freelance.community.max_mute_days')], 'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $mute = $this->moderation->mute(Freelancer::query()->findOrFail($data['freelancer_id']), (int) $data['days'], $data['reason'], $this->adminId($request));

        return ApiResponse::success(['id' => $mute->id, 'ends_at' => $mute->ends_at->toIso8601String()], __('freelance.community.muted'));
    }

    /** DELETE freelance-community/mutes/{id} */
    public function lift(Request $request, int $id): JsonResponse
    {
        $this->moderation->lift(Mute::query()->findOrFail($id), $this->adminId($request));

        return ApiResponse::success(null, __('freelance.community.mute_lifted'));
    }

    // -------------------------------------------------------------- inside

    private function subject(string $type, string $uuid): Post|Comment
    {
        return $type === 'posts' ? Post::query()->where('uuid', $uuid)->firstOrFail() : Comment::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function presentPost(Post $p): array
    {
        return [
            'uuid' => $p->uuid, 'kind' => $p->kind, 'body' => $p->body, 'status' => $p->trashed() ? 'deleted' : $p->status, 'hidden_reason' => $p->hidden_reason,
            'hidden_by_reports' => $p->isHidden() && $p->hidden_by_admin_id === null,
            'author' => ['id' => $p->freelancer?->id, 'name' => $p->freelancer?->name], 'country_id' => $p->country_id,
            'specialty' => $p->specialty?->translatedName(), 'images' => $p->getMedia('images')->count(),
            'reactions' => $p->reactions_count, 'comments' => $p->comments_count,
            'reports' => Report::query()->where(['subject_type' => 'post', 'subject_id' => $p->id])->count(),
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    private function adminId(Request $request): int
    {
        return (int) $request->user('admin_api')->id;
    }
}
