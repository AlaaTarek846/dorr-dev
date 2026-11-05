<?php

namespace Modules\Moving\Services\Requests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Moving\Enums\MovingStatus;
use Modules\Moving\Events\MovingDriverMoved;
use Modules\Moving\Exceptions\MovingException;
use Modules\Moving\Models\MovingInventoryItem;
use Modules\Moving\Models\MovingRequest;
use Modules\Provider\Models\Membership;

/**
 * Moving day (B-08 → B-10), from the driver's «مهامي»: set out, at the door (with the place and time, REQ-028),
 * loading with the numbered inventory and its photos (REQ-029), on the way, unloading, and delivery. Delivery is
 * confirmed by the code the customer gives at the door (or its QR); without it the driver records why — refused or
 * nobody there — with photos, and that is never taken as the customer's consent (REQ-033, owner's call). Then the
 * objection window starts.
 */
class MovingExecutionService
{
    /** The driver's steps, each from the one before it. */
    private const STEPS = [
        'set_out' => [MovingStatus::Assigned, MovingStatus::DriverOnWay],
        'arrived' => [MovingStatus::DriverOnWay, MovingStatus::Arrived],
        'loading' => [MovingStatus::Arrived, MovingStatus::Loading],
        'departed' => [MovingStatus::Loading, MovingStatus::InTransit],
        'unloading' => [MovingStatus::InTransit, MovingStatus::Unloading],
    ];

    public function __construct(
        private readonly MovingJournal $journal,
        private readonly MovingChangeService $changes,
    ) {}

    public function step(Membership $driver, MovingRequest $request, string $step, ?float $lat = null, ?float $lng = null, ?string $note = null): MovingRequest
    {
        $this->assertDriver($driver, $request);
        [$from, $to] = self::STEPS[$step] ?? throw new MovingException('bad_step');
        if ($request->status !== $from) {
            throw new MovingException('wrong_step', 409);
        }
        if ($step === 'set_out' && $request->driver_accepted_at === null) {
            throw new MovingException('job_not_accepted', 409);
        }
        // Loading starts only once every piece taken is numbered and photographed (REQ-029).
        if ($step === 'departed' && ! $request->inventory()->exists()) {
            throw new MovingException('inventory_empty');
        }

        $attributes = $lat !== null && $lng !== null ? ['driver_lat' => $lat, 'driver_lng' => $lng, 'driver_location_at' => now()] : [];

        // GPS missing at the door: the note is the other proof, and a person looks at it (REQ-028, REQ-044).
        $request = $this->journal->move($request, $to, $step, 'provider', $driver->provider_id, $attributes, array_filter(['note' => $note, 'no_gps' => $step === 'arrived' && $lat === null]), $lat, $lng);
        // The waiting past the free minutes is asked from the customer, never taken (REQ-040).
        if ($step === 'loading') {
            $this->changes->waitingFee($driver, $request);
        }

        return $request;
    }

    /** The truck's position while the job runs; sent by the driver's app in batches like the captain app. */
    public function location(Membership $driver, MovingRequest $request, float $lat, float $lng, ?int $heading): void
    {
        $this->assertDriver($driver, $request);
        if (! in_array($request->status, [MovingStatus::DriverOnWay, MovingStatus::Arrived, MovingStatus::Loading, MovingStatus::InTransit, MovingStatus::Unloading], true)) {
            return;
        }
        $request->forceFill(['driver_lat' => $lat, 'driver_lng' => $lng, 'driver_location_at' => now()])->save();
        event(new MovingDriverMoved($request->uuid, $lat, $lng, $heading));
    }

    public function addItem(Membership $driver, MovingRequest $request, string $label, UploadedFile $photo, int $tripNo = 1, ?string $note = null): MovingInventoryItem
    {
        $this->assertDriver($driver, $request);
        if (! in_array($request->status, [MovingStatus::Arrived, MovingStatus::Loading], true)) {
            throw new MovingException('wrong_step', 409);
        }

        return DB::transaction(function () use ($request, $label, $photo, $tripNo, $note): MovingInventoryItem {
            MovingRequest::query()->whereKey($request->id)->lockForUpdate()->first();
            $item = $request->inventory()->create([
                'number' => ((int) $request->inventory()->max('number')) + 1,
                'label' => $label,
                'trip_no' => $tripNo,
                'condition_note' => $note,
            ]);
            $item->setSingleMedia('before', $photo);

            return $item;
        });
    }

    /** A delivered piece's photo, and a reservation if any. */
    public function deliverItem(Membership $driver, MovingRequest $request, MovingInventoryItem $item, ?UploadedFile $photo, ?string $note): MovingInventoryItem
    {
        $this->assertDriver($driver, $request);
        if ($item->request_id !== $request->id || $request->status !== MovingStatus::Unloading) {
            throw new MovingException('wrong_step', 409);
        }
        $item->update(['delivered_note' => $note]);
        if ($photo !== null) {
            $item->setSingleMedia('after', $photo);
        }

        return $item;
    }

    /**
     * `without_code` is refused or absent, and then the note saying what happened is needed.
     *
     * @param  array{code?: string|null, recipient_name?: string|null, without_code?: string|null, note?: string|null}  $data
     */
    public function deliver(Membership $driver, MovingRequest $request, array $data, ?float $lat, ?float $lng): MovingRequest
    {
        $this->assertDriver($driver, $request);
        if ($request->status !== MovingStatus::Unloading) {
            throw new MovingException('wrong_step', 409);
        }

        $code = $data['code'] ?? null;
        if ($code !== null) {
            if (! hash_equals((string) $request->delivery_code, (string) $code)) {
                throw new MovingException('wrong_code');
            }
            $ack = 'code';
        } else {
            $ack = $data['without_code'] ?? null;
            if (! in_array($ack, ['refused', 'absent'], true) || blank($data['note'] ?? null)) {
                throw new MovingException('code_or_reason_required');
            }
        }

        $hours = $request->policy('delivery.objection_hours');

        return $this->journal->move($request, MovingStatus::Delivered, 'delivered', 'provider', $driver->provider_id, [
            'delivered_at' => now(),
            'delivery_ack' => $ack,
            'recipient_name' => $data['recipient_name'] ?? null,
            // No window set yet: the money waits for the customer's confirmation or a person (DEC-020).
            'objection_until' => $hours !== null ? now()->addHours((int) $hours) : null,
        ], array_filter(['ack' => $ack, 'note' => $data['note'] ?? null]), $lat, $lng);
    }

    private function assertDriver(Membership $driver, MovingRequest $request): void
    {
        if ($request->driver_membership_id !== $driver->id || ! $driver->isActive()) {
            throw new MovingException('request_not_found', 404);
        }
    }
}
