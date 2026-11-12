<?php

namespace Modules\Freelance\Services\Market;

use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceService as Service;
use Modules\Freelance\Models\FreelanceWaitlistEntry as Entry;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\User\Models\User;

/**
 * A freelancer's capacity and the waiting list on their ready-made services (FL-L10).
 *
 * The freelancer may cap how many projects run at once (`capacity_max`; empty = no cap). **Only running work counts** — an active agreement,
 * and an order of a ready-made service is one too; offers and orders waiting for an answer do not. A place kept for the first in line
 * (`offered`, until `hold_until`) counts as taken for everyone else.
 *
 * At the cap, a client cannot order the freelancer's services but can wait in line. When a place frees (an agreement stops running, the cap
 * is raised, someone leaves or a hold runs out) the oldest in line is offered it and holds it for `freelance.capacity.hold_hours`; ordering
 * uses the hold. Starting work (the freelancer approving an agreement) checks the cap under a lock on the freelancer, so two approvals at
 * the same moment can never pass it.
 */
class FreelanceCapacity
{
    public function __construct(private readonly FreelanceNotifier $notifier) {}

    public static function maxChoice(): int
    {
        return (int) config('freelance.capacity.max_choice', 20);
    }

    /** FL-L05: an active monthly subscription is one running project (between its months too); its months are not counted again. */
    public function running(int $freelancerId): int
    {
        $work = Agreement::query()->where('freelancer_id', $freelancerId)->where('status', Agreement::ACTIVE)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('freelance_service_orders as o')
                ->join('freelance_service_subscriptions as s', 's.id', '=', 'o.subscription_id')
                ->whereColumn('o.project_id', 'freelance_agreements.project_id')->where('s.status', 'active'))
            ->count();

        return $work + app(ServiceSubscriptions::class)->activeCount($freelancerId);
    }

    /** Places kept for someone in line, but not for `$exceptClientId` (their own hold is theirs to use). */
    public function holds(int $freelancerId, ?int $exceptClientId = null): int
    {
        return Entry::query()->where('freelancer_id', $freelancerId)->where('status', Entry::OFFERED)->where('hold_until', '>', now())
            ->when($exceptClientId !== null, fn ($q) => $q->where('client_id', '!=', $exceptClientId))->count();
    }

    /** No place for this client: the cap is reached by running work and the places kept for others. */
    public function isFull(Freelancer $f, ?int $clientId = null): bool
    {
        $max = $f->capacity_max;

        return $max !== null && $this->running($f->id) + $this->holds($f->id, $clientId) >= (int) $max;
    }

    /** @return array<string, mixed> what the freelancer sees of their own capacity */
    public function summary(Freelancer $f): array
    {
        $max = $f->capacity_max === null ? null : (int) $f->capacity_max;
        $running = $this->running($f->id);
        $holds = $this->holds($f->id);

        return [
            'max' => $max,
            'max_choice' => self::maxChoice(),
            'running' => $running,
            'holds' => $holds,
            'waiting' => Entry::query()->where('freelancer_id', $f->id)->where('status', Entry::WAITING)->count(),
            'full' => $max !== null && $running + $holds >= $max,
        ];
    }

    public function setMax(Freelancer $f, ?int $max): array
    {
        if ($max !== null && $max > self::maxChoice()) {
            throw new FreelanceException('capacity_too_high', 422, ['max' => self::maxChoice()]);
        }
        $f->forceFill(['capacity_max' => $max])->save();
        // A higher cap (or none) may free places for those in line.
        $this->promote($f->id);

        return $this->summary($f->refresh());
    }

    /** Ordering a service: refused at the cap unless this client holds the place offered to them. */
    public function assertCanOrder(User $client, Service $service): void
    {
        $f = $service->freelancer;
        if ($f !== null && $this->isFull($f, $client->id)) {
            throw new FreelanceException('at_capacity', 409, [], ['waitlist' => true]);
        }
    }

    /** The client ordered: their place in line (kept or still waiting) for this freelancer is used. */
    public function ordered(User $client, Service $service): void
    {
        Entry::query()->where('freelancer_id', $service->freelancer_id)->where('client_id', $client->id)->whereIn('status', Entry::OPEN)
            ->update(['status' => Entry::ORDERED, 'hold_until' => null]);
    }

    /** Called inside the transaction that starts an agreement: the freelancer row is locked so the count cannot change under us. */
    public function assertRoomToStart(Agreement $a): void
    {
        // FL-L05: a subscription's next month starts in the place the subscription already holds.
        if ((int) \Modules\Freelance\Models\FreelanceServiceOrder::query()->where('project_id', $a->project_id)->value('cycle') > 1) {
            return;
        }
        $f = Freelancer::query()->whereKey($a->freelancer_id)->lockForUpdate()->first();
        $max = $f?->capacity_max;
        if ($max !== null && $this->running($f->id) + $this->holds($f->id, (int) $a->client_id) >= (int) $max) {
            throw new FreelanceException('capacity_full', 409, ['max' => (int) $max]);
        }
    }

    // -------------------------------------------------------------- the line

    /** @return array<string, mixed> */
    public function join(User $client, Service $service): array
    {
        $f = $service->freelancer ?? abort(404);
        if (! $this->isFull($f, $client->id)) {
            throw new FreelanceException('waitlist_not_full', 422);
        }
        $created = DB::transaction(function () use ($client, $service) {
            $open = Entry::query()->where('freelancer_id', $service->freelancer_id)->where('client_id', $client->id)->whereIn('status', Entry::OPEN)->lockForUpdate()->exists();
            if ($open) {
                throw new FreelanceException('waitlist_already', 422);
            }

            return Entry::query()->create(['service_id' => $service->id, 'freelancer_id' => $service->freelancer_id, 'client_id' => $client->id, 'status' => Entry::WAITING]);
        });
        $this->notifier->notify($f, 'waitlist.joined', ['service' => (string) $service->title, 'name' => (string) $client->name], ['service' => $service->uuid, 'waitlist' => $created->id]);

        return $this->forClient($client, $service);
    }

    /** @return array<string, mixed> */
    public function leave(User $client, Service $service): array
    {
        $entry = Entry::query()->where('freelancer_id', $service->freelancer_id)->where('client_id', $client->id)->whereIn('status', Entry::OPEN)->first()
            ?? throw new FreelanceException('waitlist_none', 404);
        $wasHolding = $entry->status === Entry::OFFERED;
        $entry->update(['status' => Entry::LEFT, 'hold_until' => null]);
        if ($wasHolding) {
            $this->promote((int) $service->freelancer_id);
        }

        return $this->forClient($client, $service);
    }

    /**
     * Where the client stands with this service's freelancer: whether there is room for them, how many wait, and their own place.
     *
     * @return array<string, mixed>
     */
    public function forClient(User $client, Service $service): array
    {
        $f = $service->freelancer;
        if ($f === null || $f->capacity_max === null) {
            return ['full' => false, 'waiting' => 0, 'mine' => null];
        }
        $mine = Entry::query()->where('freelancer_id', $f->id)->where('client_id', $client->id)->whereIn('status', Entry::OPEN)->first();
        if ($mine !== null && $mine->status === Entry::OFFERED && ! $mine->holds()) {
            $mine = null; // its hold ran out; the hourly sweep will mark it
        }

        return [
            'full' => $this->isFull($f, $client->id),
            'waiting' => Entry::query()->where('freelancer_id', $f->id)->where('status', Entry::WAITING)->count(),
            'mine' => $mine === null ? null : [
                'status' => $mine->status,
                'position' => $mine->status === Entry::WAITING ? Entry::query()->where('freelancer_id', $f->id)->where('status', Entry::WAITING)->where('id', '<=', $mine->id)->count() : null,
                'hold_until' => $mine->hold_until?->toIso8601String(),
                'service' => $mine->service_id === $service->id ? null : $mine->service?->uuid,
            ],
        ];
    }

    /** @return list<array<string, mixed>> the freelancer's line, oldest first: holding a place, then waiting */
    public function line(Freelancer $f): array
    {
        $position = 0;

        return Entry::query()->where('freelancer_id', $f->id)->whereIn('status', Entry::OPEN)->with(['service', 'client'])
            ->orderByRaw("status = 'offered' desc")->orderBy('id')->get()
            ->map(function (Entry $e) use (&$position) {
                return [
                    'id' => $e->id,
                    'status' => $e->status,
                    'position' => $e->status === Entry::WAITING ? ++$position : null,
                    'hold_until' => $e->hold_until?->toIso8601String(),
                    'joined_at' => $e->created_at?->toIso8601String(),
                    'service' => ['uuid' => $e->service?->uuid, 'title' => $e->service?->title],
                    'client' => ['name' => $e->client?->name],
                ];
            })->values()->all();
    }

    /**
     * Offers the freed places to the oldest in line, each kept for `hold_hours`. Holds that ran out end first. Runs under a lock on the
     * freelancer, so two places freeing at once never offer the same place twice.
     */
    public function promote(int $freelancerId): int
    {
        $hours = (int) config('freelance.capacity.hold_hours', 24);
        $offered = DB::transaction(function () use ($freelancerId, $hours) {
            $f = Freelancer::query()->whereKey($freelancerId)->lockForUpdate()->first();
            if ($f === null) {
                return collect();
            }
            Entry::query()->where('freelancer_id', $f->id)->where('status', Entry::OFFERED)->where('hold_until', '<=', now())
                ->update(['status' => Entry::EXPIRED]);

            $line = Entry::query()->where('freelancer_id', $f->id)->where('status', Entry::WAITING)->orderBy('id');
            if ($f->capacity_max !== null) {
                $free = (int) $f->capacity_max - $this->running($f->id) - $this->holds($f->id);
                if ($free <= 0) {
                    return collect();
                }
                $line->limit($free);
            }
            $next = $line->lockForUpdate()->get();
            foreach ($next as $e) {
                $e->update(['status' => Entry::OFFERED, 'offered_at' => now(), 'hold_until' => now()->addHours($hours)]);
            }

            return $next;
        });

        foreach ($offered as $e) {
            $e->loadMissing(['client', 'service']);
            if ($e->client !== null) {
                $this->notifier->notify($e->client, 'waitlist.turn', ['service' => (string) $e->service?->title, 'hours' => $hours], ['service' => $e->service?->uuid]);
            }
        }

        return $offered->count();
    }

    /** Hourly: the holds that ran out end and their places go to the next in line. @return int places offered */
    public function sweep(): int
    {
        $ids = Entry::query()->where('status', Entry::OFFERED)->where('hold_until', '<=', now())->distinct()->pluck('freelancer_id');

        return $ids->sum(fn ($id) => $this->promote((int) $id));
    }
}
