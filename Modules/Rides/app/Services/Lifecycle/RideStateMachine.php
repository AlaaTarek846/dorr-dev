<?php

namespace Modules\Rides\Services\Lifecycle;

use Illuminate\Support\Facades\DB;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideEvent;

/**
 * The only door to a ride's status (docs/rides-structure.md §3.2): checks the move is allowed,
 * bumps `version` (optimistic lock, D-350) and writes the ride_events row in the same transaction.
 */
class RideStateMachine
{
    /**
     * @param  array<string, mixed>  $attributes  other columns to set with the move
     * @param  array{lat?: ?float, lng?: ?float, accuracy_m?: ?int}  $where
     * @param  array<string, mixed>  $payload
     */
    public function move(
        Ride $ride,
        RideStatus $to,
        string $event,
        string $actorType,
        ?int $actorId,
        array $attributes = [],
        array $where = [],
        array $payload = [],
        ?string $idempotencyKey = null,
    ): Ride {
        return DB::transaction(function () use ($ride, $to, $event, $actorType, $actorId, $attributes, $where, $payload, $idempotencyKey) {
            /** @var Ride $locked */
            $locked = Ride::query()->whereKey($ride->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canMoveTo($to)) {
                throw new RidesException('invalid_state', 409, ['status' => $from->value], ['status' => $from->value]);
            }

            $locked->fill($attributes);
            $locked->status = $to;
            $locked->version = $locked->version + 1;
            $locked->save();

            $this->record($locked, $event, $actorType, $actorId, $where, $payload, $from, $to, $idempotencyKey);

            return $locked;
        });
    }

    /**
     * The same command sent again (a weak network, D-68): the ride as it is now. A key already used
     * for another action or another ride is refused instead of being taken as a retry.
     */
    public function replayed(Ride $ride, ?string $idempotencyKey, string $event): ?Ride
    {
        if ($idempotencyKey === null) {
            return null;
        }
        $previous = RideEvent::query()->where('idempotency_key', $idempotencyKey)->first(['ride_id', 'type']);
        if ($previous === null) {
            return null;
        }
        if ((int) $previous->ride_id !== (int) $ride->id || $previous->type !== $event) {
            throw new RidesException('idempotency_conflict', 409);
        }

        return $ride->refresh();
    }

    /**
     * @param  array{lat?: ?float, lng?: ?float, accuracy_m?: ?int}  $where
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Ride $ride,
        string $event,
        string $actorType,
        ?int $actorId,
        array $where = [],
        array $payload = [],
        ?RideStatus $from = null,
        ?RideStatus $to = null,
        ?string $idempotencyKey = null,
    ): RideEvent {
        return RideEvent::query()->create([
            'ride_id' => $ride->id,
            'type' => $event,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'lat' => $where['lat'] ?? null,
            'lng' => $where['lng'] ?? null,
            'accuracy_m' => $where['accuracy_m'] ?? null,
            'payload' => $payload === [] ? null : $payload,
            'idempotency_key' => $idempotencyKey,
            'created_at' => now(),
        ]);
    }
}
