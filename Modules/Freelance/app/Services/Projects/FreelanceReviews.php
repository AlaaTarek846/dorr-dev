<?php

namespace Modules\Freelance\Services\Projects;

use Modules\Freelance\Models\FreelanceProjectApproval;
use App\Models\Rating;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceMilestoneStatus as Status;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;

/**
 * Rating each other after the work (FL-701) and the freelancer's numbers (FL-703).
 *
 * Once an agreement is completed each side rates the other within `ratings.window_days` (the policy frozen in the agreement, default 14):
 * the client the freelancer on quality, time, communication and professionalism; the freelancer the client on clarity, answers, payment
 * and conduct; each 1–5, with a comment. The overall stars (their average) and the comment go to the shared `ratings` table, the
 * criteria to `freelance_rating_scores`. Nothing shows until both rated or the days are over — then both at once
 * (`freelance:publish-reviews`). A rating can't change. The freelancer's average shows once they have three ratings; the client's ratings
 * stay inside DORR (the client sees the one about them).
 */
class FreelanceReviews
{
    public const CRITERIA = [
        // What the client rates the freelancer on …
        'client' => ['quality', 'timeliness', 'communication', 'professionalism'],
        // … and the freelancer the client.
        'freelancer' => ['clarity', 'responsiveness', 'payment', 'conduct'],
    ];

    /** An average shows from this many ratings. */
    public const MIN_FOR_AVERAGE = 3;

    public function __construct(private readonly FreelanceNotifier $notifier) {}

    /**
     * @param  'client'|'freelancer'  $side
     * @param  array<string, int>  $scores  criterion => 1..5, one for each of the side's criteria
     */
    public function rate(Agreement $a, Model $author, string $side, array $scores, ?string $comment): void
    {
        abort_unless((int) ($side === 'client' ? $a->client_id : $a->freelancer_id) === (int) $author->getKey(), 404);
        $criteria = self::CRITERIA[$side];
        if (array_diff($criteria, array_keys($scores)) !== [] || collect($scores)->contains(fn ($v) => (int) $v < 1 || (int) $v > 5)) {
            throw new FreelanceException('review_scores', 422, ['list' => implode(', ', $criteria)], ['criteria' => $criteria]);
        }
        $published = DB::transaction(function () use ($a, $author, $side, $scores, $comment, $criteria) {
            $agreement = Agreement::query()->whereKey($a->id)->lockForUpdate()->firstOrFail();
            if (! $this->open($agreement)) {
                throw new FreelanceException('review_closed', 409);
            }
            $key = Rating::uniqueKeyFor($author, $agreement);
            if (Rating::query()->where('unique_key', $key)->exists()) {
                throw new FreelanceException('review_exists', 409);
            }
            $stars = round(array_sum(array_map(fn ($c) => (int) $scores[$c], $criteria)) / count($criteria), 2);
            $rating = Rating::query()->create([
                'author_type' => $author->getMorphClass(), 'author_id' => $author->getKey(),
                'rateable_type' => $agreement->getMorphClass(), 'rateable_id' => $agreement->id,
                'unique_key' => $key, 'stars' => $stars, 'type' => Rating::typeForStars($stars),
                'comment' => $comment === null ? null : (trim(strip_tags($comment)) ?: null),
            ]);
            foreach ($criteria as $c) {
                DB::table('freelance_rating_scores')->insert(['rating_id' => $rating->id, 'criterion' => $c, 'stars' => (int) $scores[$c]]);
            }
            DB::table('freelance_reviews')->insert([
                'rating_id' => $rating->id, 'agreement_id' => $agreement->id, 'project_id' => $agreement->project_id, 'side' => $side,
                'freelancer_id' => $agreement->freelancer_id, 'client_id' => $agreement->client_id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            FreelanceProjectApproval::forAgreement($agreement, 'review.rated', $side, $author->getKey());
            // Both rated: both show now.
            if (DB::table('freelance_reviews')->where('agreement_id', $agreement->id)->count() === 2) {
                DB::table('freelance_reviews')->where('agreement_id', $agreement->id)->whereNull('published_at')->update(['published_at' => now()]);

                return true;
            }

            return false;
        });

        $a->loadMissing('client', 'freelancer', 'project');
        $other = $side === 'client' ? $a->freelancer : $a->client;
        $name = (string) ($side === 'client' ? $a->client?->name : $a->freelancer?->name);
        $this->notifier->notify($other, $published ? 'review.published' : 'review.received', ['project' => (string) $a->project->title, 'name' => $name], ['project' => $a->project->uuid]);
    }

    /** Hourly: the ratings of agreements whose rating days are over show, even when only one side rated. */
    public function publishDue(): int
    {
        $ids = DB::table('freelance_reviews')->whereNull('published_at')->pluck('agreement_id')->unique();

        return Agreement::query()->whereIn('id', $ids)->get()->filter(fn (Agreement $a) => ! $this->open($a))
            ->sum(fn (Agreement $a) => DB::table('freelance_reviews')->where('agreement_id', $a->id)->whereNull('published_at')->update(['published_at' => now()]));
    }

    /** Rating is possible: the agreement is completed and its days are not over. */
    public function open(Agreement $a): bool
    {
        return $a->status === Agreement::COMPLETED && $a->completed_at !== null && $a->completed_at->copy()->addDays($this->days($a))->isFuture();
    }

    /** @return array<string, mixed> the rating screen of one agreement, for one side */
    public function forAgreement(Agreement $a, string $side): array
    {
        $rows = DB::table('freelance_reviews')->where('agreement_id', $a->id)->get()->keyBy('side');
        $mine = $rows->get($side);
        $theirs = $rows->get($side === 'client' ? 'freelancer' : 'client');

        return [
            'criteria' => self::CRITERIA[$side],
            'can_rate' => $mine === null && $this->open($a),
            'rate_until' => $a->completed_at?->copy()->addDays($this->days($a))->toIso8601String(),
            'mine' => $mine === null ? null : $this->presentRating((int) $mine->rating_id),
            // The other side's rating shows once both are out.
            'theirs' => $theirs === null || $theirs->published_at === null ? null : $this->presentRating((int) $theirs->rating_id),
            'theirs_waiting' => $theirs !== null && $theirs->published_at === null,
        ];
    }

    /**
     * FL-703: what helps a client choose this freelancer. `specialtyId` counts the completed projects like the one at hand.
     *
     * @return array<string, mixed>
     */
    public function stats(Freelancer $f, ?int $specialtyId = null): array
    {
        $published = DB::table('freelance_reviews')->join('ratings', 'ratings.id', '=', 'freelance_reviews.rating_id')
            ->where('freelance_reviews.freelancer_id', $f->id)->where('freelance_reviews.side', 'client')->whereNotNull('freelance_reviews.published_at');
        $count = (clone $published)->count();
        $average = $count >= self::MIN_FOR_AVERAGE ? round((float) (clone $published)->avg('ratings.stars'), 2) : null;
        $criteria = $count >= self::MIN_FOR_AVERAGE
            ? DB::table('freelance_rating_scores')->whereIn('rating_id', (clone $published)->pluck('ratings.id'))->groupBy('criterion')
                ->selectRaw('criterion, avg(stars) as avg')->pluck('avg', 'criterion')->map(fn ($v) => round((float) $v, 2))->all()
            : [];

        $started = Agreement::query()->where('freelancer_id', $f->id)->whereNotNull('activated_at');
        $startedCount = (clone $started)->count();
        $completed = (clone $started)->where('status', Agreement::COMPLETED)->count();

        // On time: an approved part delivered within its days (more days accepted on it count — FL-404).
        $timed = Milestone::query()->where('status', Status::Approved->value)->whereNotNull('duration_days')->whereNotNull('started_at')->whereNotNull('delivered_at')
            ->whereHas('agreement', fn ($q) => $q->where('freelancer_id', $f->id))->get(['started_at', 'delivered_at', 'duration_days']);
        $onTime = $timed->filter(fn (Milestone $m) => $m->delivered_at->lte($m->started_at->copy()->addDays($m->duration_days)))->count();

        return [
            'rating' => $average,
            'reviews_count' => $count,
            'criteria' => $criteria,
            'completed_projects' => $completed,
            'completion_rate' => $startedCount === 0 ? null : (int) round($completed * 100 / $startedCount),
            'on_time_rate' => $timed->isEmpty() ? null : (int) round($onTime * 100 / $timed->count()),
            'similar_projects' => $specialtyId === null ? null : Agreement::query()->where('freelancer_id', $f->id)->where('status', Agreement::COMPLETED)
                ->whereHas('project', fn ($q) => $q->where('freelance_specialty_id', $specialtyId))->count(),
        ];
    }

    /** @return list<array<string, mixed>> the latest ratings clients gave this freelancer, once out (for the profile a client reads) */
    public function latestAbout(Freelancer $f, int $limit = 10): array
    {
        return DB::table('freelance_reviews')->join('ratings', 'ratings.id', '=', 'freelance_reviews.rating_id')
            ->join('freelance_projects', 'freelance_projects.id', '=', 'freelance_reviews.project_id')
            ->where('freelance_reviews.freelancer_id', $f->id)->where('freelance_reviews.side', 'client')->whereNotNull('freelance_reviews.published_at')
            ->orderByDesc('freelance_reviews.published_at')->limit($limit)
            ->get(['ratings.stars', 'ratings.comment', 'freelance_projects.title', 'freelance_reviews.published_at'])
            ->map(fn ($r) => ['stars' => (float) $r->stars, 'comment' => $r->comment, 'project' => $r->title, 'at' => \Illuminate\Support\Carbon::parse($r->published_at)->toIso8601String()])->all();
    }

    /** @return array<string, mixed> */
    private function presentRating(int $ratingId): array
    {
        $r = Rating::query()->findOrFail($ratingId);

        return [
            'stars' => (float) $r->stars,
            'comment' => $r->comment,
            'scores' => DB::table('freelance_rating_scores')->where('rating_id', $ratingId)->pluck('stars', 'criterion')->map(fn ($v) => (int) $v)->all(),
            'at' => $r->created_at?->toIso8601String(),
        ];
    }

    private function days(Agreement $a): int
    {
        return max(1, (int) (((array) $a->policy)['ratings.window_days'] ?? config('freelance.policy_defaults.ratings.window_days') ?? 14));
    }
}
