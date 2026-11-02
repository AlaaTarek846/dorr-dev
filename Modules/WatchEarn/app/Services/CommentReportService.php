<?php

namespace Modules\WatchEarn\Services;

use Illuminate\Support\Facades\DB;
use Modules\User\Models\User;
use Modules\WatchEarn\Exceptions\WatchEarnException;
use Modules\WatchEarn\Models\Ad;
use Modules\WatchEarn\Models\AdComment;
use Modules\WatchEarn\Models\CommentReport;

/**
 * D21 — the advertiser reports a comment under their own ad (they can't delete it); the admin removes it or keeps
 * it, and the advertiser and the comment's author are both told what was decided.
 */
class CommentReportService
{
    public function __construct(private readonly WatchEarnNotifier $notify) {}

    public function report(User $advertiser, Ad $ad, AdComment $comment, string $reason, ?string $note): CommentReport
    {
        if ($ad->user_id !== $advertiser->id || $comment->ad_id !== $ad->id) {
            throw new WatchEarnException('not_found', 404);
        }
        if (CommentReport::query()->where('comment_id', $comment->id)->where('reporter_id', $advertiser->id)->exists()) {
            throw new WatchEarnException('already_reported', 409);
        }

        return CommentReport::query()->create([
            'comment_id' => $comment->id,
            'ad_id' => $ad->id,
            'reporter_id' => $advertiser->id,
            'reason' => $reason,
            'note' => $note,
        ]);
    }

    /** The admin's answer: `remove` deletes the comment for everyone; `keep` leaves it. Both sides get a push. */
    public function decide(CommentReport $report, string $action, int $adminId, ?string $note): CommentReport
    {
        return DB::transaction(function () use ($report, $action, $adminId, $note) {
            $report = CommentReport::query()->lockForUpdate()->findOrFail($report->id);
            if ($report->status !== CommentReport::PENDING) {
                throw new WatchEarnException('wrong_status', 409);
            }

            $comment = AdComment::withTrashed()->find($report->comment_id);
            $ad = Ad::query()->findOrFail($report->ad_id);

            if ($action === CommentReport::REMOVED && $comment !== null && ! $comment->trashed()) {
                $comment->delete();
                Ad::query()->whereKey($ad->id)->where('comments_count', '>', 0)->decrement('comments_count');
                $this->notify->toViewers($ad, 'watch_earn.comment_deleted', ['ad_id' => $ad->uuid, 'comment_id' => $comment->id]);
            }

            $report->forceFill(['status' => $action, 'decision_note' => $note, 'decided_by' => $adminId, 'decided_at' => now()])->save();

            // The same comment reported again (it can't be by the same advertiser) — one answer closes them all.
            CommentReport::query()->where('comment_id', $report->comment_id)->where('status', CommentReport::PENDING)
                ->update(['status' => $action, 'decision_note' => $note, 'decided_by' => $adminId, 'decided_at' => now()]);

            $vars = ['title' => $ad->title, 'note' => $note ?: '—'];
            $data = ['ad_id' => $ad->uuid, 'comment_id' => $report->comment_id, 'decision' => $action];
            $this->notify->user($report->reporter_id, "comment_report_{$action}_reporter", $vars, $data + ['role' => 'reporter']);
            if ($comment !== null) {
                $this->notify->user((int) $comment->user_id, "comment_report_{$action}_author", $vars, $data + ['role' => 'author']);
            }

            return $report;
        });
    }
}
