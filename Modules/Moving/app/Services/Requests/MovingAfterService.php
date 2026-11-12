<?php

namespace Modules\Moving\Services\Requests;

use Illuminate\Support\Facades\DB;
use Modules\Moving\Enums\MovingStatus;
use Modules\Moving\Exceptions\MovingException;
use Modules\Moving\Models\MovingChangeOrder;
use Modules\Moving\Models\MovingRating;
use Modules\Moving\Models\MovingRequest;
use Modules\Moving\Models\MovingTicket;
use Modules\Provider\Models\Membership;
use Modules\Provider\Services\Establishments\EstablishmentAccess;
use Modules\User\Models\User;

/**
 * After the move (B-15, B-06): the customer's rating of the establishment and the driver (REQ-034), the tickets that
 * go to the establishment first and to Dorr only after, with a reason (REQ-062 → REQ-064, REQ-103), and the detailed
 * invoice built from the agreed numbers only (REQ-093, REQ-094).
 */
class MovingAfterService
{
    public function __construct(
        private readonly EstablishmentAccess $access,
        private readonly MovingJournal $journal,
    ) {}

    /**
     * The criteria the customer scores the establishment on, with their labels — what the rating card shows (as rides).
     *
     * @return list<array{key: string, label: string}>
     */
    public static function criteria(): array
    {
        return array_values(array_map(
            fn ($k) => ['key' => (string) $k, 'label' => __('moving.rating_criteria.'.$k)],
            array_filter((array) config('moving.rating_criteria', []), 'is_string'),
        ));
    }

    /**
     * The customer's rating after delivery: stars for the establishment (or, given only the criteria, their average),
     * a score per criterion, stars for the driver and a comment.
     *
     * @param  array<string, int>  $scores  criterion => 1..5
     */
    public function rate(User $user, MovingRequest $request, ?int $establishmentStars, ?int $driverStars, ?string $comment, array $scores = []): MovingRating
    {
        if ($request->user_id !== $user->id || ! in_array($request->status, [MovingStatus::Delivered, MovingStatus::Completed, MovingStatus::Disputed], true)) {
            throw new MovingException('cannot_rate', 409);
        }
        if (MovingRating::query()->where('request_id', $request->id)->exists()) {
            throw new MovingException('already_rated', 409);
        }
        // Only the criteria asked about, each 1..5; the overall stars default to their average.
        $scores = array_filter(
            array_intersect_key(array_map('intval', $scores), array_flip(array_column(self::criteria(), 'key'))),
            fn (int $s) => $s >= 1 && $s <= 5,
        );
        $establishmentStars ??= $scores === [] ? null : (int) round(array_sum($scores) / count($scores));
        if ($establishmentStars === null) {
            throw new MovingException('rating_stars_required', 422);
        }

        return DB::transaction(function () use ($user, $request, $establishmentStars, $driverStars, $comment, $scores): MovingRating {
            $rating = MovingRating::query()->create([
                'request_id' => $request->id,
                'user_id' => $user->id,
                'establishment_id' => $request->establishment_id,
                'driver_membership_id' => $request->driver_membership_id,
                'establishment_stars' => $establishmentStars,
                'driver_stars' => $request->driver_membership_id ? $driverStars : null,
                'comment' => $comment,
            ]);
            foreach ($scores as $criterion => $stars) {
                DB::table('moving_rating_scores')->insert(['rating_id' => $rating->id, 'criterion' => $criterion, 'stars' => $stars]);
            }

            return $rating;
        });
    }

    /**
     * An establishment's stars, its average on each criterion and its finished moves, shown with its offers.
     *
     * @return array{average: float|null, count: int, jobs: int, criteria: list<array{key: string, label: string, average: float|null, count: int}>}
     */
    public static function reputation(int $establishmentId): array
    {
        $row = MovingRating::query()->where('establishment_id', $establishmentId)
            ->selectRaw('AVG(establishment_stars) as avg_stars, COUNT(*) as total')->first();
        $scores = DB::table('moving_rating_scores')
            ->join('moving_ratings', 'moving_ratings.id', '=', 'moving_rating_scores.rating_id')
            ->where('moving_ratings.establishment_id', $establishmentId)
            ->groupBy('moving_rating_scores.criterion')
            ->selectRaw('moving_rating_scores.criterion as criterion, AVG(moving_rating_scores.stars) as a, COUNT(*) as c')
            ->get()->keyBy('criterion');

        return [
            'average' => $row?->avg_stars !== null ? round((float) $row->avg_stars, 1) : null,
            'count' => (int) ($row?->total ?? 0),
            'jobs' => MovingRequest::query()->where('establishment_id', $establishmentId)->where('status', MovingStatus::Completed->value)->count(),
            'criteria' => array_map(fn (array $c) => [
                ...$c,
                'average' => isset($scores[$c['key']]) ? round((float) $scores[$c['key']]->a, 1) : null,
                'count' => (int) ($scores[$c['key']]->c ?? 0),
            ], self::criteria()),
        ];
    }

    public function openTicket(User $user, MovingRequest $request, string $type, string $subject, string $body): MovingTicket
    {
        if ($request->user_id !== $user->id || ! in_array($type, MovingTicket::TYPES, true)) {
            throw new MovingException('request_not_found', 404);
        }

        return DB::transaction(function () use ($user, $request, $type, $subject, $body): MovingTicket {
            $ticket = MovingTicket::query()->create([
                'request_id' => $request->id,
                'establishment_id' => $request->establishment_id,
                'user_id' => $user->id,
                'type' => $type,
                'subject' => $subject,
                // A complaint goes to the establishment first (REQ-063); a request with no establishment yet goes to Dorr.
                'status' => $request->establishment_id ? 'awaiting_company' : 'escalated',
                'escalated_at' => $request->establishment_id ? null : now(),
            ]);
            $ticket->messages()->create(['author_type' => 'user', 'author_id' => $user->id, 'body' => $body]);
            $this->journal->move($request, null, 'ticket_opened', 'user', $user->id, [], ['ticket' => $ticket->uuid, 'type' => $type]);

            return $ticket;
        });
    }

    /** Someone with tickets.handle answers for the establishment; the answer alone never closes it (REQ-064). */
    public function establishmentReply(Membership $actor, MovingTicket $ticket, string $body): MovingTicket
    {
        if ($ticket->establishment_id !== $actor->establishment_id || $ticket->isClosed()) {
            throw new MovingException('ticket_not_found', 404);
        }
        $this->access->authorize($actor, 'tickets.handle', $ticket->request->branch);
        $ticket->messages()->create(['author_type' => 'provider', 'author_id' => $actor->provider_id, 'body' => $body]);
        if ($ticket->status !== 'escalated') {
            $ticket->update(['status' => 'company_replied']);
        }

        return $ticket;
    }

    /** The customer answers, accepts the outcome, or takes it to Dorr. */
    public function customerAct(User $user, MovingTicket $ticket, string $action, ?string $body): MovingTicket
    {
        if ($ticket->user_id !== $user->id || $ticket->isClosed()) {
            throw new MovingException('ticket_not_found', 404);
        }
        if ($body !== null && $body !== '') {
            $ticket->messages()->create(['author_type' => 'user', 'author_id' => $user->id, 'body' => $body]);
        }
        match ($action) {
            'reply' => $ticket->update(['status' => $ticket->status === 'escalated' ? 'escalated' : 'awaiting_company']),
            'resolve' => $ticket->update(['status' => 'resolved', 'closed_at' => now(), 'closing_reason' => 'accepted_by_customer']),
            'escalate' => $this->escalate($ticket, (string) $body),
            default => throw new MovingException('bad_step'),
        };

        return $ticket;
    }

    /** Dorr's answer, and closing it with a reason that can still be appealed by opening a new ticket (REQ-064). */
    public function adminAct(int $adminId, MovingTicket $ticket, ?string $body, ?string $close, ?string $reason): MovingTicket
    {
        if ($ticket->isClosed()) {
            throw new MovingException('ticket_not_found', 404);
        }
        if ($body !== null && $body !== '') {
            $ticket->messages()->create(['author_type' => 'admin', 'author_id' => $adminId, 'body' => $body]);
            $ticket->update(['status' => 'awaiting_customer']);
        }
        if ($close !== null) {
            if (blank($reason)) {
                throw new MovingException('closing_reason_required');
            }
            $ticket->update(['status' => $close === 'resolved' ? 'resolved' : 'unresolved', 'closed_at' => now(), 'closing_reason' => $reason]);
        }

        return $ticket;
    }

    /**
     * The invoice of a move, from the agreed numbers and the approved changes only (REQ-093). The establishment's
     * net and Dorr's commission are shown to the establishment, not to the customer.
     *
     * @return array<string, mixed>
     */
    public function invoice(MovingRequest $request, bool $forEstablishment): array
    {
        if ($request->price_minor === null) {
            throw new MovingException('no_invoice', 409);
        }
        $offer = $request->acceptedOffer;
        $changes = MovingChangeOrder::query()->where('request_id', $request->id)->where('status', 'approved')->where('kind', '!=', 'reschedule')->get();
        $base = (int) $request->price_minor - (int) $changes->sum('price_delta_minor');

        $lines = [['key' => 'moving', 'amount_minor' => $base]];
        foreach ($changes as $change) {
            $lines[] = ['key' => $change->kind === 'waiting' ? 'waiting' : 'change', 'amount_minor' => (int) $change->price_delta_minor, 'reason' => $change->reason];
        }

        return [
            'number' => 'MV-'.strtoupper(substr($request->uuid, 0, 8)),
            'issued_at' => ($request->settled_at ?? $request->delivered_at ?? $request->updated_at)?->toIso8601String(),
            'currency_code' => $request->currency_code,
            'establishment' => ['name' => $request->establishment?->name, 'tax_number' => $request->establishment?->tax_number],
            'includes' => $offer?->includes ?? (object) [],
            'workers_count' => $offer?->workers_count,
            'trips_count' => $offer?->trips_count,
            'lines' => $lines,
            'price_minor' => (int) $request->price_minor,
            'tax_minor' => (int) $request->tax_minor,
            'total_minor' => (int) $request->total_minor,
            'refunded_minor' => (int) $request->refunded_minor,
            'commission_minor' => $forEstablishment ? (int) $request->commission_minor : null,
            'provider_net_minor' => $forEstablishment ? (int) $request->provider_net_minor : null,
            'policy_version' => $request->policy_version,
        ];
    }

    private function escalate(MovingTicket $ticket, string $reason): void
    {
        $hours = $ticket->request->policy('tickets.escalate_hours');
        $waited = $hours === null || $ticket->created_at->copy()->addHours((int) $hours)->isPast();
        // To Dorr once the establishment answered and it isn't solved, or once it had its time (REQ-063).
        if (! $waited && $ticket->status !== 'company_replied') {
            throw new MovingException('escalate_too_soon', 409);
        }
        if (blank($reason)) {
            throw new MovingException('escalation_reason_required');
        }
        $ticket->update(['status' => 'escalated', 'escalated_at' => now(), 'escalation_reason' => $reason]);
    }
}
