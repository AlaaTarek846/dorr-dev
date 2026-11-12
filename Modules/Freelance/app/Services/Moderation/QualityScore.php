<?php

namespace Modules\Freelance\Services\Moderation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceSanction as Sanction;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Chat\FreelanceChatService;
use Modules\Freelance\Services\Projects\FreelanceReviews;

/**
 * The internal quality score and risk level (FL-707), for staff only. Plain rules from `freelance.quality` and only numbers we already
 * keep: ratings, on-time and completed work, disputes lost, messages reported and upheld, sanctions, and cancellations that were this
 * person's responsibility (FL-607). Every rule that matched is returned as a reason, so a person can see why. It never acts on an
 * account: a sanction is always issued by staff (FL-708).
 */
class QualityScore
{
    public function __construct(private readonly FreelanceReviews $reviews) {}

    /** @return array{score: ?int, level: string, reasons: list<array{code: string, level: string, count: int|null}>, inputs: array<string, mixed>} */
    public function for(Model $person): array
    {
        $cfg = config('freelance.quality');
        $side = FreelanceChatService::side($person);
        $id = (int) $person->getKey();
        $since = now()->subDays((int) $cfg['window_days']);

        if ($person instanceof Freelancer) {
            $stats = $this->reviews->stats($person);
            $parts = ['rating' => $stats['rating'] === null ? null : $stats['rating'] * 20, 'on_time' => $stats['on_time_rate'], 'completion' => $stats['completion_rate']];
        } else {
            $parts = ['rating' => $this->clientRating($id), 'on_time' => null, 'completion' => $this->clientCompletion($id)];
        }

        $inputs = [
            'rating' => $parts['rating'] === null ? null : round($parts['rating'] / 20, 2),
            'on_time_rate' => $parts['on_time'],
            'completion_rate' => $parts['completion'],
            'disputes_lost' => DB::table('freelance_disputes as d')->join('freelance_agreements as a', 'a.id', '=', 'd.agreement_id')
                ->where($side === 'freelancer' ? 'a.freelancer_id' : 'a.client_id', $id)
                ->where('d.decision', $side === 'freelancer' ? 'refund' : 'release')->where('d.decided_at', '>=', $since)->count(),
            'reports_upheld' => DB::table('freelance_message_reports as r')->join('freelance_messages as m', 'm.id', '=', 'r.message_id')
                ->where('m.sender_type', $side)->where('m.sender_id', $id)->where('r.status', 'upheld')->where('r.decided_at', '>=', $since)->count(),
            // An appeal that lifted a sanction takes it out of everything.
            'sanctions' => Sanction::query()->for($side, $id)->where('starts_at', '>=', $since)->where(fn ($q) => $q->whereNull('appeal_status')->orWhere('appeal_status', '!=', 'lifted'))->count(),
            'suspensions' => Sanction::query()->for($side, $id)->where('type', Sanction::SUSPENSION)->where(fn ($q) => $q->whereNull('appeal_status')->orWhere('appeal_status', '!=', 'lifted'))->count(),
        ] + $this->cancellations($side, $id);

        // The score: the weighted parts that have data, scaled up, then points off.
        $weights = array_filter($cfg['weights'], fn ($w, $k) => $parts[$k] !== null, ARRAY_FILTER_USE_BOTH);
        $score = null;
        if ($weights !== []) {
            $sum = array_sum(array_map(fn ($k) => $parts[$k] * $weights[$k], array_keys($weights))) / array_sum($weights);
            $sum -= $inputs['disputes_lost'] * $cfg['penalties']['dispute_lost'] + $inputs['reports_upheld'] * $cfg['penalties']['report_upheld'] + $inputs['sanctions'] * $cfg['penalties']['sanction'];
            $score = (int) max(0, min(100, round($sum)));
        }

        $reasons = [];
        $rule = function (string $code, string $level, ?int $count = null) use (&$reasons): void {
            $reasons[] = ['code' => $code, 'level' => $level, 'count' => $count];
        };
        $high = $cfg['risk']['high'];
        $medium = $cfg['risk']['medium'];
        foreach (['reports_upheld', 'disputes_lost'] as $k) {
            if ($inputs[$k] >= $high[$k]) {
                $rule($k, 'high', $inputs[$k]);
            } elseif ($inputs[$k] >= $medium[$k]) {
                $rule($k, 'medium', $inputs[$k]);
            }
        }
        if ($inputs['suspensions'] >= $high['suspensions']) {
            $rule('suspensions', 'high', $inputs['suspensions']);
        } elseif ($inputs['sanctions'] >= $medium['sanctions']) {
            $rule('sanctions', 'medium', $inputs['sanctions']);
        }
        if ($inputs['cancel_rate'] !== null && $inputs['started'] >= $medium['cancel_min'] && $inputs['cancel_rate'] >= $medium['cancel_rate']) {
            $rule('cancel_rate', 'medium', $inputs['cancel_rate']);
        }
        if ($person instanceof Freelancer) {
            if (in_array($person->status, [FreelancerStatus::Suspended, FreelancerStatus::Blocked], true)) {
                $rule('account_status', 'high');
            } elseif ($person->status !== FreelancerStatus::Verified) {
                $rule('not_verified', 'low');
            }
        }
        $level = collect($reasons)->contains('level', 'high') ? 'high' : (collect($reasons)->contains('level', 'medium') ? 'medium' : 'low');

        return ['score' => $score, 'level' => $level, 'reasons' => $reasons, 'inputs' => $inputs];
    }

    /** Kept on the freelancer for the admin list and its filter: after each sanction change, when staff open the page, and daily. */
    public function refresh(Model $person): array
    {
        $r = $this->for($person);
        if ($person instanceof Freelancer) {
            $person->forceFill(['quality_score' => $r['score'], 'risk_level' => $r['level'], 'risk_checked_at' => now()])->saveQuietly();
        } else {
            DB::table('freelance_client_risk')->updateOrInsert(['user_id' => $person->getKey()], ['quality_score' => $r['score'], 'risk_level' => $r['level'], 'checked_at' => now()]);
        }

        return $r;
    }

    /** @return array{started: int, cancelled_by_me: int, cancel_rate: ?int} projects ended early that were this person's responsibility */
    private function cancellations(string $side, int $id): array
    {
        $col = $side === 'freelancer' ? 'freelancer_id' : 'client_id';
        $started = Agreement::query()->where($col, $id)->whereNotNull('activated_at')->count();
        $mine = DB::table('freelance_cancellation_reasons as c')->join('freelance_agreements as a', 'a.id', '=', 'c.agreement_id')
            ->where('a.'.$col, $id)->where('c.responsibility', $side)->distinct()->count('c.agreement_id');

        return ['started' => $started, 'cancelled_by_me' => $mine, 'cancel_rate' => $started === 0 ? null : (int) round($mine * 100 / $started)];
    }

    /** The freelancers' ratings of this client, out of 100, once there are enough of them. */
    private function clientRating(int $clientId): ?float
    {
        $q = DB::table('freelance_reviews')->join('ratings', 'ratings.id', '=', 'freelance_reviews.rating_id')
            ->where('freelance_reviews.client_id', $clientId)->where('freelance_reviews.side', 'freelancer')
            ->whereNotNull('freelance_reviews.published_at')->whereNull('freelance_reviews.excluded_at');

        return (clone $q)->count() >= FreelanceReviews::MIN_FOR_AVERAGE ? round((float) $q->avg('ratings.stars') * 20, 2) : null;
    }

    private function clientCompletion(int $clientId): ?int
    {
        $started = Agreement::query()->where('client_id', $clientId)->whereNotNull('activated_at');
        $n = (clone $started)->count();

        return $n === 0 ? null : (int) round((clone $started)->where('status', Agreement::COMPLETED)->count() * 100 / $n);
    }
}
