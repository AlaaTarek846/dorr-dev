<?php

namespace Modules\Freelance\Services\Community;

use App\Services\General\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceCommunityComment as Comment;
use Modules\Freelance\Models\FreelanceCommunityMute as Mute;
use Modules\Freelance\Models\FreelanceCommunityPost as Post;
use Modules\Freelance\Models\FreelanceCommunityReport as Report;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;

/**
 * Keeping the community clean (FL-L12). Members report a post or a comment; when enough different members report one thing it is hidden
 * at once until the staff look. The staff uphold a report (the thing stays hidden, the other open reports on it close with it, and they
 * may mute the author for some days) or dismiss it (a thing hidden by the reports comes back once none is left open). They can also hide
 * or show a post or a comment themselves and mute or let a member speak again.
 */
class CommunityModeration
{
    public function __construct(private readonly FreelanceNotifier $notifier, private readonly AuditLogger $audit) {}

    public function report(Freelancer $me, string $type, string $uuid, string $reason, ?string $details, Community $community): Report
    {
        $subject = $type === 'post' ? $community->post($me, $uuid) : $community->comment($me, $uuid);
        if ($subject->freelancer_id === $me->id) {
            throw new FreelanceException('community_report_own', 422);
        }

        $report = DB::transaction(function () use ($me, $type, $subject, $reason, $details): Report {
            Freelancer::query()->whereKey($me->id)->lockForUpdate()->first();
            if (Report::query()->where('reporter_id', $me->id)->where('created_at', '>=', now()->subDay())->count() >= (int) config('freelance.community.reports_per_day')) {
                throw new FreelanceException('community_daily_reports', 429);
            }
            if (Report::query()->where(['reporter_id' => $me->id, 'subject_type' => $type, 'subject_id' => $subject->id, 'status' => Report::OPEN])->exists()) {
                throw new FreelanceException('community_report_exists', 409);
            }

            return Report::query()->create([
                'reporter_id' => $me->id, 'subject_type' => $type, 'subject_id' => $subject->id, 'author_id' => $subject->freelancer_id,
                'reason' => $reason, 'details' => $details === null ? null : (mb_substr(trim(strip_tags($details)), 0, 1000) ?: null), 'status' => Report::OPEN,
            ]);
        });

        // Enough different members: hidden until the staff look (the author is told it is being reviewed).
        $reporters = Report::query()->where(['subject_type' => $type, 'subject_id' => $subject->id, 'status' => Report::OPEN])->distinct()->count('reporter_id');
        if ($reporters >= (int) config('freelance.community.auto_hide_reports') && ! $subject->isHidden()) {
            $subject->forceFill(['status' => Post::HIDDEN, 'hidden_reason' => __('freelance.community.under_review'), 'hidden_by_admin_id' => null, 'hidden_at' => now()])->save();
            $this->tellHidden($subject, __('freelance.community.under_review'));
        }

        return $report;
    }

    /** The staff decide a report. `mute_days` (uphold only) stops the author writing for that many days. */
    public function decide(Report $report, string $decision, ?string $note, int $muteDays, int $adminId): Report
    {
        $note = $note === null ? '' : mb_substr(trim(strip_tags($note)), 0, 1000);

        $decided = DB::transaction(function () use ($report, $decision, $note, $adminId): Report {
            $r = Report::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== Report::OPEN) {
                throw new FreelanceException('community_report_closed', 409);
            }
            $status = $decision === 'uphold' ? Report::UPHELD : Report::DISMISSED;
            $r->fill(['status' => $status, 'decision_note' => $note ?: null, 'decided_by_admin_id' => $adminId, 'decided_at' => now()])->save();
            if ($status === Report::UPHELD) {
                // One decision closes every open report on the same thing.
                Report::query()->where(['subject_type' => $r->subject_type, 'subject_id' => $r->subject_id, 'status' => Report::OPEN])
                    ->update(['status' => Report::UPHELD, 'decision_note' => $note ?: null, 'decided_by_admin_id' => $adminId, 'decided_at' => now()]);
            }

            return $r;
        });

        $subject = $decided->subject();
        if ($subject !== null) {
            if ($decided->status === Report::UPHELD) {
                $reason = $note !== '' ? $note : __('freelance.community.report_reasons.'.$decided->reason);
                $subject->forceFill(['status' => Post::HIDDEN, 'hidden_reason' => mb_substr($reason, 0, 300), 'hidden_by_admin_id' => $adminId, 'hidden_at' => $subject->hidden_at ?? now()])->save();
                $this->tellHidden($subject, $reason);
            } elseif ($subject->isHidden() && $subject->hidden_by_admin_id === null
                && ! Report::query()->where(['subject_type' => $decided->subject_type, 'subject_id' => $decided->subject_id, 'status' => Report::OPEN])->exists()) {
                // Hidden by the reports alone and nothing left open: it comes back.
                $subject->forceFill(['status' => Post::PUBLISHED, 'hidden_reason' => null, 'hidden_at' => null])->save();
            }
        }
        if ($decided->status === Report::UPHELD && $muteDays > 0 && ($author = $decided->author) !== null) {
            $this->mute($author, $muteDays, $note !== '' ? $note : __('freelance.community.report_reasons.'.$decided->reason), $adminId);
        }
        $this->audit->record('freelance.community_report_decided', $decided, null, ['decision' => $decided->status, 'mute_days' => $muteDays, 'admin' => $adminId]);

        return $decided;
    }

    public function hide(Post|Comment $subject, string $reason, int $adminId): Post|Comment
    {
        $subject->forceFill(['status' => Post::HIDDEN, 'hidden_reason' => mb_substr($reason, 0, 300), 'hidden_by_admin_id' => $adminId, 'hidden_at' => now()])->save();
        $this->tellHidden($subject, $reason);
        $this->audit->record('freelance.community_hidden', $subject, null, ['reason' => $reason, 'admin' => $adminId]);

        return $subject;
    }

    public function unhide(Post|Comment $subject, int $adminId): Post|Comment
    {
        $subject->forceFill(['status' => Post::PUBLISHED, 'hidden_reason' => null, 'hidden_by_admin_id' => null, 'hidden_at' => null])->save();
        $this->audit->record('freelance.community_unhidden', $subject, null, ['admin' => $adminId]);

        return $subject;
    }

    public function mute(Freelancer $f, int $days, string $reason, int $adminId): Mute
    {
        $days = max(1, min($days, (int) config('freelance.community.max_mute_days')));
        $mute = Mute::query()->create(['freelancer_id' => $f->id, 'ends_at' => now()->addDays($days), 'reason' => mb_substr($reason, 0, 500), 'admin_id' => $adminId]);
        $this->notifier->notify($f, 'community.muted', ['date' => $mute->ends_at->toDateString(), 'reason' => $mute->reason]);
        $this->audit->record('freelance.community_muted', $mute, null, ['days' => $days, 'admin' => $adminId]);

        return $mute;
    }

    public function lift(Mute $mute, int $adminId): Mute
    {
        $mute->forceFill(['lifted_at' => now()])->save();
        $this->audit->record('freelance.community_mute_lifted', $mute, null, ['admin' => $adminId]);

        return $mute;
    }

    /** @return LengthAwarePaginator<Report> the open ones first, the oldest at the top */
    public function reports(?string $status, int $perPage): LengthAwarePaginator
    {
        return Report::query()->with(['reporter', 'author'])->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByRaw("case status when 'open' then 0 else 1 end")->orderBy('id')->paginate($perPage);
    }

    /** @return array<string, mixed> */
    public function presentReport(Report $r): array
    {
        $s = $r->subject();
        $post = $s instanceof Comment ? $s->post : $s;

        return [
            'uuid' => $r->uuid, 'status' => $r->status, 'reason' => $r->reason, 'reason_label' => __('freelance.community.report_reasons.'.$r->reason), 'details' => $r->details,
            'subject' => [
                'type' => $r->subject_type, 'uuid' => $s?->uuid, 'body' => $s?->body, 'status' => $s === null ? 'deleted' : ($s->trashed() ? 'deleted' : $s->status),
                'post' => $post?->uuid, 'reports' => Report::query()->where(['subject_type' => $r->subject_type, 'subject_id' => $r->subject_id])->count(),
            ],
            'reporter' => $r->reporter === null ? null : ['id' => $r->reporter->id, 'name' => $r->reporter->name],
            'author' => $r->author === null ? null : ['id' => $r->author->id, 'name' => $r->author->name, 'muted_until' => Mute::query()->where('freelancer_id', $r->author->id)->whereNull('lifted_at')->where('ends_at', '>', now())->max('ends_at')],
            'decision_note' => $r->decision_note, 'decided_at' => $r->decided_at?->toIso8601String(), 'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    private function tellHidden(Post|Comment $subject, string $reason): void
    {
        if (($author = $subject->freelancer) !== null) {
            $post = $subject instanceof Comment ? $subject->post : $subject;
            $this->notifier->notify($author, 'community.hidden', ['reason' => $reason], ['community_post' => $post?->uuid]);
        }
    }
}
