<?php

namespace Modules\WatchEarn\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\WatchEarn\Models\CommentReport;
use Modules\WatchEarn\Services\CommentReportService;

/** D21 — advertisers' reports on comments under their ads: remove the comment or keep it; both sides are told. */
class WatchEarnCommentReportController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('watch-earn-ads', [
            ['view', ['index']],
            ['update', ['decide']],
        ]);
    }

    public function __construct(private readonly CommentReportService $reports) {}

    /** GET watch-earn/comment-reports?status=pending */
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in([CommentReport::PENDING, CommentReport::REMOVED, CommentReport::KEPT])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = CommentReport::query()
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->with(['comment.user:id,name,phone', 'ad:id,uuid,title', 'reporter:id,name,phone'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 20));

        return ApiResponse::fromPaginator($page, collect($page->items())->map(fn (CommentReport $r) => $this->present($r))->all(), __('api.retrieved'));
    }

    /** POST watch-earn/comment-reports/{report}/decide — {action: removed|kept, note?} */
    public function decide(Request $request, CommentReport $report)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in([CommentReport::REMOVED, CommentReport::KEPT])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $report = $this->reports->decide($report, $data['action'], (int) $request->user()->id, $data['note'] ?? null);

        return ApiResponse::success($this->present($report->load(['comment.user:id,name,phone', 'ad:id,uuid,title', 'reporter:id,name,phone'])), __('api.updated'));
    }

    private function present(CommentReport $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'reason' => $r->reason,
            'note' => $r->note,
            'decision_note' => $r->decision_note,
            'decided_at' => $r->decided_at?->toISOString(),
            'created_at' => $r->created_at?->toISOString(),
            'ad' => $r->ad ? ['id' => $r->ad->uuid, 'title' => $r->ad->title] : null,
            'reporter' => $r->reporter ? ['id' => $r->reporter->id, 'name' => $r->reporter->name, 'phone' => $r->reporter->phone] : null,
            'comment' => $r->comment ? [
                'id' => $r->comment->id,
                'body' => $r->comment->body,
                'deleted' => $r->comment->trashed(),
                'author' => $r->comment->user ? ['id' => $r->comment->user->id, 'name' => $r->comment->user->name, 'phone' => $r->comment->user->phone] : null,
            ] : null,
        ];
    }
}
