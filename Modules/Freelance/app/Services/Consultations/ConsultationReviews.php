<?php

namespace Modules\Freelance\Services\Consultations;

use App\Models\Rating;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceConsultation as Consultation;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\User\Models\User;

/**
 * The client rates a consultation once it is over (FL-L07): 1–5 stars and a comment, once, within `ratings.window_days` of the
 * freelancer being paid. Unlike a project there is nothing to rate back, so it shows at once. It lives in `freelance_reviews` with the
 * project ratings (`consultation_id` instead of the agreement), so the freelancer's average, count and profile count it too.
 */
class ConsultationReviews
{
    public function __construct(private readonly FreelanceNotifier $notifier) {}

    public function rate(Consultation $consultation, User $client, int $stars, ?string $comment): void
    {
        abort_unless((int) $consultation->client_id === (int) $client->id, 404);
        if ($stars < 1 || $stars > 5) {
            throw new FreelanceException('review_scores', 422, ['list' => 'stars'], ['criteria' => ['stars']]);
        }
        $comment = $comment === null ? null : (trim(strip_tags($comment)) ?: null);

        $c = DB::transaction(function () use ($consultation, $client, $stars, $comment) {
            $c = Consultation::query()->whereKey($consultation->id)->lockForUpdate()->firstOrFail();
            if (! $this->open($c)) {
                throw new FreelanceException('consultation_review_closed', 409);
            }
            $key = Rating::uniqueKeyFor($client, $c);
            if (Rating::query()->where('unique_key', $key)->exists()) {
                throw new FreelanceException('consultation_review_exists', 409);
            }
            $rating = Rating::query()->create([
                'author_type' => $client->getMorphClass(), 'author_id' => $client->getKey(),
                'rateable_type' => $c->getMorphClass(), 'rateable_id' => $c->id,
                'unique_key' => $key, 'stars' => $stars, 'type' => Rating::typeForStars($stars), 'comment' => $comment,
            ]);
            DB::table('freelance_reviews')->insert([
                'rating_id' => $rating->id, 'consultation_id' => $c->id, 'side' => 'client',
                'freelancer_id' => $c->freelancer_id, 'client_id' => $c->client_id,
                'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $c;
        });

        $c->loadMissing('freelancer');
        if ($c->freelancer !== null) {
            $this->notifier->notify($c->freelancer, 'consultation.rated', ['name' => (string) $client->name, 'stars' => (string) $stars, 'title' => $c->label()], ['consultation' => $c->uuid]);
        }
    }

    /** It can be rated: the freelancer was paid for it and the days are not over. */
    public function open(Consultation $c): bool
    {
        $until = $this->until($c);

        return $c->status === Consultation::COMPLETED && $until !== null && $until->isFuture();
    }

    /** @return array<string, mixed> the rating part of one consultation (the freelancer reads the client's) */
    public function present(Consultation $c, string $side): array
    {
        $row = DB::table('freelance_reviews')->join('ratings', 'ratings.id', '=', 'freelance_reviews.rating_id')
            ->where('freelance_reviews.consultation_id', $c->id)->where('freelance_reviews.side', 'client')
            ->first(['ratings.stars', 'ratings.comment', 'ratings.created_at', 'freelance_reviews.comment_hidden_at', 'freelance_reviews.excluded_at']);

        return [
            'can_rate' => $side === 'client' && $row === null && $this->open($c),
            'rate_until' => $row === null ? $this->until($c)?->toIso8601String() : null,
            'rating' => $row === null ? null : [
                'stars' => (float) $row->stars,
                'comment' => $row->comment_hidden_at === null ? $row->comment : null,
                'removed' => $row->excluded_at !== null,
                'at' => Carbon::parse($row->created_at)->toIso8601String(),
            ],
        ];
    }

    private function until(Consultation $c): ?Carbon
    {
        $from = $c->settled_at ?? $c->confirmed_at;
        $days = max(1, (int) config('freelance.policy_defaults.ratings.window_days', 14));

        return $from === null ? null : Carbon::parse($from)->addDays($days);
    }
}
