<?php

namespace Modules\Rides\Services\Safety;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\Admin;
use Modules\Driver\Models\Driver;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideSafetyIncident;
use Modules\Rides\Services\Lifecycle\RideStateMachine;

/**
 * SOS (R-110, D-141, D-156): either side, while the ride runs and for a while after it. The app
 * calls the country's emergency number itself (`safety.emergency_number`, fixed with the ride); the
 * server keeps where and when, and tells the safety team at once. The other side is never told —
 * that could make things worse. Pressing again within a minute is the same alert.
 */
class SafetyIncidentService
{
    /** An SOS stays possible this long after the ride ended. */
    private const AFTER_END_S = 3600;

    public function __construct(
        private readonly RideStateMachine $machine,
        private readonly NotificationCenter $center,
    ) {}

    /** What can be reported, by whom (R-117, R-224, D-124, D-130, D-152..154). */
    public const REPORTS = [
        'user' => ['unsafe_driving', 'abuse', 'harassment', 'vehicle_issue', 'other'],
        'driver' => ['rider_mismatch', 'abuse', 'threat', 'accident', 'road_hazard', 'other'],
    ];

    /** Reports the safety team must see at once, like an SOS. */
    private const URGENT = ['sos', 'threat', 'harassment', 'accident', 'ride_check', 'checkin_missed', 'vehicle_mismatch'];

    public static function emergencyNumber(Ride $ride): string
    {
        return (string) $ride->policy('safety.emergency_number', config('rides.policy_defaults')['safety.emergency_number'] ?? '911');
    }

    /**
     * A report from either side (not an emergency button): kept with where and when, urgent kinds
     * also alert the safety team now.
     */
    public function report(Ride $ride, Model $reporter, string $type, ?float $lat, ?float $lng, ?int $accuracy, ?string $note): RideSafetyIncident
    {
        $this->assertOpen($ride);
        $who = $reporter instanceof Driver ? 'driver' : 'user';
        if (! in_array($type, self::REPORTS[$who], true) && ! in_array($type, ['vehicle_mismatch', 'ride_check', 'checkin_missed'], true)) {
            throw new RidesException('report_type_invalid', 422);
        }

        return $this->open($ride, $who, $reporter->getKey(), $type, $lat, $lng, $accuracy, $note);
    }

    /** A safety check nobody answered (R-222, R-223): an incident for the team, raised by the system. */
    public function unanswered(Ride $ride, string $targetType, int $targetId, string $type, ?float $lat, ?float $lng): RideSafetyIncident
    {
        return $this->open($ride, $targetType, $targetId, $type, $lat, $lng, null, null);
    }

    /**
     * @return array{incident: RideSafetyIncident, emergency_number: string}
     */
    public function sos(Ride $ride, Model $reporter, ?float $lat, ?float $lng, ?int $accuracy, ?string $note): array
    {
        $this->assertOpen($ride);
        $type = $reporter instanceof Driver ? 'driver' : 'user';
        $number = self::emergencyNumber($ride);

        $recent = RideSafetyIncident::query()->where('ride_id', $ride->id)->where('reporter_type', $type)->where('reporter_id', $reporter->getKey())
            ->where('status', 'open')->where('created_at', '>=', now()->subMinute())->latest('id')->first();
        if ($recent !== null) {
            if ($lat !== null && $lng !== null) {
                $recent->update(['lat' => $lat, 'lng' => $lng, 'accuracy_m' => $accuracy]);
            }

            return ['incident' => $recent, 'emergency_number' => $number];
        }

        $incident = $this->open($ride, $type, $reporter->getKey(), 'sos', $lat, $lng, $accuracy, $note);

        return ['incident' => $incident, 'emergency_number' => $number];
    }

    private function assertOpen(Ride $ride): void
    {
        $endedAt = $ride->completed_at ?? $ride->canceled_at ?? $ride->expired_at;
        if ($ride->status->isFinal() && ($endedAt === null || $endedAt->copy()->addSeconds(self::AFTER_END_S)->isPast())) {
            throw new RidesException('sos_unavailable', 409);
        }
    }

    private function open(Ride $ride, string $who, int $id, string $type, ?float $lat, ?float $lng, ?int $accuracy, ?string $note): RideSafetyIncident
    {
        $incident = DB::transaction(function () use ($ride, $who, $id, $type, $lat, $lng, $accuracy, $note) {
            $incident = RideSafetyIncident::query()->create([
                'uuid' => (string) Str::uuid(),
                'ride_id' => $ride->id,
                'type' => $type,
                'reporter_type' => $who,
                'reporter_id' => $id,
                'lat' => $lat,
                'lng' => $lng,
                'accuracy_m' => $accuracy,
                'ride_status' => $ride->status->value,
                'note' => $note,
            ]);
            $this->machine->record($ride, $type === 'sos' ? 'sos' : 'safety_report', $who, $id, ['lat' => $lat, 'lng' => $lng, 'accuracy_m' => $accuracy], ['incident' => $incident->uuid, 'type' => $type]);

            return $incident;
        });

        $admins = $this->center->adminsWith('ride-safety.view');
        if ($admins->isNotEmpty()) {
            $urgent = in_array($type, self::URGENT, true);
            $this->center->send($admins, $urgent ? 'ride.sos' : 'ride.safety_report', $urgent ? 'ride_sos_admin_title' : 'ride_report_admin_title', $urgent ? 'ride_sos_admin_body' : 'ride_report_admin_body', [
                'number' => $ride->number,
                'who' => __('rides.share.reporter.'.$who),
                'type' => __('rides.safety_types.'.$type),
            ], ['type' => 'ride_sos', 'incident_id' => $incident->id, 'ride_id' => $ride->id], $urgent);
        }

        return $incident;
    }

    public function acknowledge(RideSafetyIncident $incident, Admin $admin): RideSafetyIncident
    {
        if ($incident->status === 'open') {
            $incident->update(['status' => 'acknowledged', 'acknowledged_at' => now(), 'handled_by_admin_id' => $admin->id]);
        }

        return $incident;
    }

    public function resolve(RideSafetyIncident $incident, Admin $admin, ?string $note): RideSafetyIncident
    {
        $incident->update([
            'status' => 'resolved',
            'acknowledged_at' => $incident->acknowledged_at ?? now(),
            'resolved_at' => now(),
            'handled_by_admin_id' => $admin->id,
            'resolution_note' => $note,
        ]);

        return $incident;
    }
}
