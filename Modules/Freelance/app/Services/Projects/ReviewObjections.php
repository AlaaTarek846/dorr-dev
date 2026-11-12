<?php

namespace Modules\Freelance\Services\Projects;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;

/**
 * Objecting to a rating (FL-706). Once a rating about me is out, I may object once, within `APPEAL_DAYS`, with a reason and a few words
 * (abusive words, something untrue, unfair, other). Staff decide, with a note: keep it, hide its comment (the stars stay), or take
 * the whole rating out of the numbers and the profile. Nothing changes while it waits, and no one's account is touched by it. The person
 * who objected is told the outcome; the rater is told only when their rating was changed.
 */
class ReviewObjections
{
    public const APPEAL_DAYS = 14;

    public const REASONS = ['abusive', 'false', 'unfair', 'other'];

    public const OPEN = 'open';

    public const REJECTED = 'rejected';

    public const COMMENT_HIDDEN = 'comment_hidden';

    public const RATING_REMOVED = 'rating_removed';

    public function __construct(private readonly FreelanceNotifier $notifier) {}

    /** @param 'client'|'freelancer' $side the person the rating is about (the one objecting) */
    public function object(Agreement $a, Model $me, string $side, string $reason, string $note): array
    {
        abort_unless((int) ($side === 'client' ? $a->client_id : $a->freelancer_id) === (int) $me->getKey(), 404);
        $note = mb_substr(trim(strip_tags($note)), 0, 500);
        if (mb_strlen($note) < 5) {
            throw new FreelanceException('objection_note_required', 422);
        }
        $review = DB::table('freelance_reviews')->where('agreement_id', $a->id)->where('side', $side === 'client' ? 'freelancer' : 'client')->first();
        // Only a rating that is out (published) and not already taken out.
        if ($review === null || $review->published_at === null || $review->excluded_at !== null) {
            throw new FreelanceException('objection_not_allowed', 409);
        }
        if (Carbon::parse($review->published_at)->addDays(self::APPEAL_DAYS)->isPast()) {
            throw new FreelanceException('objection_closed', 409);
        }
        if (DB::table('freelance_review_objections')->where('review_id', $review->id)->exists()) {
            throw new FreelanceException('objection_exists', 409);
        }
        DB::table('freelance_review_objections')->insert([
            'uuid' => (string) Str::uuid(), 'review_id' => $review->id, 'agreement_id' => $a->id, 'objector_side' => $side,
            'reason' => in_array($reason, self::REASONS, true) ? $reason : 'other', 'note' => $note, 'status' => self::OPEN, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->forReview($review, $a);
    }

    /** Staff decide an open objection once. */
    public function decide(string $uuid, Admin $admin, string $decision, ?string $note): array
    {
        $status = ['reject' => self::REJECTED, 'hide_comment' => self::COMMENT_HIDDEN, 'remove_rating' => self::RATING_REMOVED][$decision] ?? null;
        abort_if($status === null, 422);
        $objection = DB::transaction(function () use ($uuid, $admin, $status, $note) {
            $o = DB::table('freelance_review_objections')->where('uuid', $uuid)->lockForUpdate()->first();
            abort_if($o === null, 404);
            if ($o->status !== self::OPEN) {
                throw new FreelanceException('objection_decided', 409);
            }
            if ($status === self::COMMENT_HIDDEN) {
                DB::table('freelance_reviews')->where('id', $o->review_id)->update(['comment_hidden_at' => now()]);
            } elseif ($status === self::RATING_REMOVED) {
                DB::table('freelance_reviews')->where('id', $o->review_id)->update(['excluded_at' => now()]);
            }
            DB::table('freelance_review_objections')->where('id', $o->id)->update([
                'status' => $status, 'decided_by' => $admin->id, 'decision_note' => $note === null ? null : (mb_substr(trim(strip_tags($note)), 0, 500) ?: null),
                'decided_at' => now(), 'updated_at' => now(),
            ]);

            return $o;
        });

        $a = Agreement::query()->with(['client', 'freelancer', 'project'])->findOrFail($objection->agreement_id);
        $vars = ['project' => (string) $a->project->title, 'outcome' => __('freelance.objection_outcomes.'.$status)];
        $data = ['project' => $a->project->uuid];
        $this->notifier->notify($objection->objector_side === 'client' ? $a->client : $a->freelancer, 'review.objection_decided', $vars, $data);
        if ($status !== self::REJECTED) {
            $this->notifier->notify($objection->objector_side === 'client' ? $a->freelancer : $a->client, 'review.moderated', $vars, $data);
        }

        return ['uuid' => $uuid, 'status' => $status];
    }

    /** @return array<string, mixed>|null the objection on this rating, as its two people see it */
    public function forReview(object $review, Agreement $a): ?array
    {
        $o = DB::table('freelance_review_objections')->where('review_id', $review->id)->first();

        return $o === null ? null : [
            'uuid' => $o->uuid, 'reason' => $o->reason, 'note' => $o->note, 'status' => $o->status,
            'decision_note' => $o->decision_note, 'decided_at' => $o->decided_at ? Carbon::parse($o->decided_at)->toIso8601String() : null,
        ];
    }

    /** Can the person this rating is about object to it now? */
    public function canObject(?object $review): bool
    {
        return $review !== null && $review->published_at !== null && $review->excluded_at === null
            && Carbon::parse($review->published_at)->addDays(self::APPEAL_DAYS)->isFuture()
            && ! DB::table('freelance_review_objections')->where('review_id', $review->id)->exists();
    }

    /** @return array<string, mixed> for staff */
    public function queue(?string $status, int $perPage): array
    {
        $q = DB::table('freelance_review_objections as o')
            ->join('freelance_reviews as r', 'r.id', '=', 'o.review_id')->join('ratings', 'ratings.id', '=', 'r.rating_id')
            ->join('freelance_projects as p', 'p.id', '=', 'r.project_id')->join('freelancers as f', 'f.id', '=', 'r.freelancer_id')->join('users as u', 'u.id', '=', 'r.client_id')
            ->when($status, fn ($q) => $q->where('o.status', $status));
        $counts = DB::table('freelance_review_objections')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $page = $q->orderByRaw("case o.status when 'open' then 0 else 1 end")->orderByDesc('o.id')->paginate($perPage, [
            'o.uuid', 'o.reason', 'o.note', 'o.status', 'o.decision_note', 'o.decided_at', 'o.created_at', 'o.objector_side', 'r.side as rater_side', 'r.id as review_id',
            'r.comment_hidden_at', 'r.excluded_at', 'ratings.stars', 'ratings.comment', 'ratings.id as rating_id', 'p.title as project', 'f.name as freelancer', 'u.name as client',
        ]);
        $items = collect($page->items())->map(fn ($o) => [
            'uuid' => $o->uuid, 'status' => $o->status, 'reason' => $o->reason, 'note' => $o->note, 'decision_note' => $o->decision_note,
            'objector_side' => $o->objector_side, 'rater_side' => $o->rater_side, 'project' => $o->project,
            'rater' => $o->rater_side === 'client' ? $o->client : $o->freelancer, 'about' => $o->rater_side === 'client' ? $o->freelancer : $o->client,
            'rating' => [
                'stars' => (float) $o->stars, 'comment' => $o->comment, 'comment_hidden' => $o->comment_hidden_at !== null, 'removed' => $o->excluded_at !== null,
                'scores' => DB::table('freelance_rating_scores')->where('rating_id', $o->rating_id)->pluck('stars', 'criterion')->map(fn ($v) => (int) $v)->all(),
            ],
            'created_at' => Carbon::parse($o->created_at)->toIso8601String(), 'decided_at' => $o->decided_at ? Carbon::parse($o->decided_at)->toIso8601String() : null,
        ])->values()->all();

        return ['items' => $items, 'page' => $page, 'counts' => $counts];
    }
}
