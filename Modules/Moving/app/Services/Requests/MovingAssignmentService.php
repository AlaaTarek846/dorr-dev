<?php

namespace Modules\Moving\Services\Requests;

use Illuminate\Support\Facades\DB;
use Modules\Moving\Enums\MovingStatus;
use Modules\Moving\Exceptions\MovingException;
use Modules\Moving\Models\MovingRequest;
use Modules\Provider\Models\Membership;
use Modules\Provider\Models\Vehicle;
use Modules\Provider\Services\Establishments\EstablishmentAccess;
use Modules\Provider\Services\Establishments\WorkGate;

/**
 * Giving a confirmed job to a driver (B-07, §5): someone with jobs.assign in the job's branch picks a driver of the
 * establishment whose licence is approved. The vehicle is the one in the accepted offer; another one only through a
 * recorded replacement with its reason, and only an approved vehicle that fits the service (REQ-009). The driver
 * accepts the job in the app. For an individual the only driver is the owner, so the job is theirs at once.
 */
class MovingAssignmentService
{
    public function __construct(
        private readonly EstablishmentAccess $access,
        private readonly WorkGate $gate,
        private readonly MovingJournal $journal,
    ) {}

    public function assign(Membership $actor, MovingRequest $request, Membership $driver, ?Vehicle $vehicle = null, ?string $reason = null): MovingRequest
    {
        $this->assertOwnJob($actor, $request);
        $this->access->authorize($actor, 'jobs.assign', $request->branch);
        if (! in_array($request->status, [MovingStatus::Confirmed, MovingStatus::Assigned], true)) {
            throw new MovingException('cannot_assign', 409);
        }
        if ($driver->establishment_id !== $request->establishment_id) {
            throw new MovingException('driver_not_found', 404);
        }
        if ($problem = $this->gate->driverProblem($driver)) {
            throw new MovingException('cannot_assign_driver', 422, ['reason' => __('provider.errors.'.$problem)], ['reason' => $problem]);
        }

        return DB::transaction(function () use ($actor, $request, $driver, $vehicle, $reason): MovingRequest {
            $attributes = ['driver_membership_id' => $driver->id, 'driver_accepted_at' => null];
            $data = ['driver' => $driver->uuid];
            if ($vehicle !== null && $vehicle->id !== $request->vehicle_id) {
                if (blank($reason)) {
                    throw new MovingException('replacement_reason_required');
                }
                if ($vehicle->establishment_id !== $request->establishment_id
                    || ($problem = $this->gate->vehicleProblem($vehicle, $request->service_category_id))) {
                    throw new MovingException('cannot_replace_vehicle', 422, ['reason' => __('provider.errors.'.($problem ?? 'vehicle_not_found'))]);
                }
                $attributes['vehicle_id'] = $vehicle->id;
                $data += ['vehicle_from' => $request->vehicle?->uuid, 'vehicle_to' => $vehicle->uuid, 'reason' => $reason];
            }
            // The owner of an individual establishment is the driver: nothing to accept.
            if ($driver->provider_id === $actor->provider_id) {
                $attributes['driver_accepted_at'] = now();
            }

            return $this->journal->move($request, MovingStatus::Assigned, isset($data['vehicle_to']) ? 'vehicle_replaced' : 'assigned', 'provider', $actor->provider_id, $attributes, $data);
        });
    }

    /** The driver takes the job (it rang on their phone). */
    public function accept(Membership $driver, MovingRequest $request): MovingRequest
    {
        if ($request->driver_membership_id !== $driver->id || $request->status !== MovingStatus::Assigned) {
            throw new MovingException('request_not_found', 404);
        }
        if ($request->driver_accepted_at !== null) {
            return $request;
        }

        return $this->journal->move($request, null, 'driver_accepted', 'provider', $driver->provider_id, ['driver_accepted_at' => now()]);
    }

    /** The driver can't take it: back to the establishment to give someone else. */
    public function decline(Membership $driver, MovingRequest $request): MovingRequest
    {
        if ($request->driver_membership_id !== $driver->id || $request->status !== MovingStatus::Assigned || $request->driver_accepted_at !== null) {
            throw new MovingException('request_not_found', 404);
        }

        return $this->journal->move($request, MovingStatus::Confirmed, 'driver_declined', 'provider', $driver->provider_id, ['driver_membership_id' => null]);
    }

    /** Someone left the establishment (§14): their jobs that haven't started go back to it to hand out again. */
    public function releaseDriver(Membership $driver): int
    {
        $count = 0;
        MovingRequest::query()->where('driver_membership_id', $driver->id)
            ->whereIn('status', [MovingStatus::Confirmed->value, MovingStatus::Assigned->value])
            ->each(function (MovingRequest $request) use (&$count, $driver): void {
                $this->journal->move($request, MovingStatus::Confirmed, 'driver_left', 'provider', $driver->provider_id, ['driver_membership_id' => null, 'driver_accepted_at' => null]);
                $count++;
            });

        return $count;
    }

    private function assertOwnJob(Membership $actor, MovingRequest $request): void
    {
        if ($request->establishment_id !== $actor->establishment_id) {
            throw new MovingException('request_not_found', 404);
        }
    }
}
