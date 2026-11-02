<?php

namespace Modules\Freelance\Services\Plans;

use Illuminate\Support\Collection;
use Modules\Freelance\Enums\FreelanceOfferStatus as OfferStatus;
use Modules\Freelance\Models\FreelanceOfferCredit;
use Modules\Freelance\Models\Freelancer;

/**
 * The extra offers a freelancer bought and has left (FL-L01, phase 2). Each purchase is a batch; the one that ends first is spent first.
 * What is left of a batch is its quantity minus the offers sent on it that still count — the same rule as the monthly free offers: an
 * offer taken back before the client saw it gives its place back — so there is no counter to drift.
 */
class FreelanceOfferCredits
{
    /**
     * The batches that have not ended and still have offers, the one ending first at the front (no end date last). Each carries `remaining`.
     *
     * @return Collection<int, FreelanceOfferCredit>
     */
    public function batches(Freelancer $freelancer): Collection
    {
        return FreelanceOfferCredit::query()->where('freelancer_id', $freelancer->id)->usable()
            ->withCount(['offers as spent' => fn ($q) => $q->where(fn ($w) => $w->where('status', '!=', OfferStatus::Withdrawn->value)->orWhereNotNull('viewed_at'))])
            ->orderByRaw('expires_at is null')->orderBy('expires_at')->orderBy('id')->get()
            ->each(fn (FreelanceOfferCredit $c) => $c->setAttribute('remaining', max(0, $c->quantity - (int) $c->spent)))
            ->filter(fn (FreelanceOfferCredit $c) => $c->remaining > 0)->values();
    }

    /** How many bought offers can still be sent. */
    public function available(Freelancer $freelancer): int
    {
        return (int) $this->batches($freelancer)->sum('remaining');
    }

    /** The batch the next offer is spent on, else null. */
    public function pick(Freelancer $freelancer): ?FreelanceOfferCredit
    {
        return $this->batches($freelancer)->first();
    }

    /** @return array{available: int, next_expires_at: ?string, batches: list<array<string, mixed>>} for the app */
    public function summary(Freelancer $freelancer): array
    {
        $batches = $this->batches($freelancer);

        return [
            'available' => (int) $batches->sum('remaining'),
            'next_expires_at' => $batches->first(fn (FreelanceOfferCredit $c) => $c->expires_at !== null)?->expires_at?->toIso8601String(),
            'batches' => $batches->map(fn (FreelanceOfferCredit $c) => [
                'uuid' => $c->uuid, 'quantity' => $c->quantity, 'remaining' => $c->remaining, 'expires_at' => $c->expires_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
