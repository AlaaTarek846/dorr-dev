<?php

namespace Modules\Rides\Services\Chat;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Str;
use Modules\Chat\Support\LiveKitToken;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideCall;
use Modules\Rides\Services\Notifications\RideNotifier;

/**
 * In-app voice calls on a ride (docs/rides_tasks.md O-09; X-13 decided: LiveKit, like the chat's calls —
 * the same server, keys and token signer `chat.livekit.*`). The rider and the captain talk without either
 * seeing the other's number.
 *
 *  - start: a LiveKit room for this ride; the other side is rung by a high-priority push
 *    (`ride.call`, short ttl) so it reaches a closed phone.
 *  - accept / decline / end: each change is broadcast on the ride (`ride.changed` / `driver.changed` with
 *    `call_*`), so both apps follow it at once — nothing asks on a timer.
 *  - A ring nobody answers in `calls.ring_s` counts as missed; the apps stop ringing by their own clock
 *    and the server settles it the next time anyone touches the call.
 */
class RideCallService
{
    public function __construct(
        private readonly NotificationCenter $center,
        private readonly RideNotifier $notifier,
    ) {}

    /** @return array{call: RideCall, join: array{url: string, room: string, token: string}} */
    public function start(Ride $ride, string $side): array
    {
        $this->assertCallable($ride);
        $this->settleStale($ride);
        if (($live = RideCall::query()->where('ride_id', $ride->id)->whereIn('status', RideCall::LIVE)->first()) !== null) {
            // Both called at once: whoever comes second joins the call already ringing.
            return $live->caller === $side ? ['call' => $live, 'join' => $this->join($live, $side)] : $this->accept($ride, $live, $side);
        }

        $call = RideCall::query()->create(['ride_id' => $ride->id, 'caller' => $side, 'status' => 'ringing', 'room' => 'ride-'.$ride->uuid.'-'.Str::lower(Str::random(8))]);
        $ring = (int) $ride->policy('calls.ring_s', 45);
        $receiver = $side === 'user' ? $ride->driver : $ride->rider;
        $this->center->send($receiver, 'ride.call', 'ride_call_title', 'ride_call_body', [
            'name' => $this->firstName($side === 'user' ? $ride->rider?->name : $ride->driver?->name),
        ], ['type' => 'ride_call', 'ride_uuid' => $ride->uuid, 'call_uuid' => $call->uuid, 'expires_at' => now()->addSeconds($ring)->toIso8601String()], true, [
            'priority' => 10, 'ttl' => $ring, 'android_visibility' => 1,
        ]);
        $this->notifier->changed($ride, 'call_ringing');

        return ['call' => $call, 'join' => $this->join($call, $side)];
    }

    /** @return array{call: RideCall, join: array{url: string, room: string, token: string}} */
    public function accept(Ride $ride, RideCall $call, string $side): array
    {
        $this->settleStale($ride);
        $call->refresh();
        if ($call->status !== 'ringing' || $call->caller === $side) {
            throw new RidesException('call_over', 409);
        }
        $call->forceFill(['status' => 'active', 'answered_at' => now()])->save();
        $this->notifier->changed($ride, 'call_accepted');

        return ['call' => $call, 'join' => $this->join($call, $side)];
    }

    /** Declining (the one rung) or hanging up (either side, ringing or talking). */
    public function end(Ride $ride, RideCall $call, string $side): RideCall
    {
        if (! in_array($call->status, RideCall::LIVE, true)) {
            return $call;
        }
        $status = match (true) {
            $call->status === 'active' => 'ended',
            $call->caller === $side => 'canceled',
            default => 'declined',
        };
        $call->forceFill(['status' => $status, 'ended_at' => now(), 'ended_by' => $side])->save();
        $this->notifier->changed($ride, 'call_ended');

        return $call;
    }

    /** The call going on now, if any (for the ride's resources). */
    public static function current(Ride $ride): ?RideCall
    {
        $call = RideCall::query()->where('ride_id', $ride->id)->whereIn('status', RideCall::LIVE)->latest('id')->first();
        if ($call !== null && $call->status === 'ringing' && $call->created_at->lt(now()->subSeconds((int) $ride->policy('calls.ring_s', 45)))) {
            return null;
        }

        return $call;
    }

    /** @return array<string, mixed>|null */
    public static function present(Ride $ride): ?array
    {
        $call = self::current($ride);

        return $call === null ? null : [
            'uuid' => $call->uuid, 'status' => $call->status, 'caller' => $call->caller,
            'started_at' => $call->created_at->toIso8601String(), 'answered_at' => $call->answered_at?->toIso8601String(),
        ];
    }

    private function assertCallable(Ride $ride): void
    {
        if (! in_array($ride->status, [RideStatus::Accepted, RideStatus::Arrived, RideStatus::InProgress], true) || $ride->driver_id === null) {
            throw new RidesException('call_not_now', 409);
        }
        if (! (bool) $ride->policy('calls.in_app', true)) {
            throw new RidesException('call_not_now', 409);
        }
        if ((string) config('chat.livekit.url') === '' || (string) config('chat.livekit.api_key') === '' || (string) config('chat.livekit.api_secret') === '') {
            throw new RidesException('call_not_configured', 503);
        }
    }

    /** Rings nobody answered in time become missed. */
    private function settleStale(Ride $ride): void
    {
        RideCall::query()->where('ride_id', $ride->id)->where('status', 'ringing')
            ->where('created_at', '<', now()->subSeconds((int) $ride->policy('calls.ring_s', 45)))
            ->update(['status' => 'missed', 'ended_at' => now()]);
    }

    /** @return array{url: string, room: string, token: string} */
    private function join(RideCall $call, string $side): array
    {
        $ride = $call->ride;
        $me = $side === 'user' ? $ride->rider : $ride->driver;

        return [
            'url' => (string) config('chat.livekit.url'),
            'room' => $call->room,
            'token' => LiveKitToken::issue($call->room, $side.':'.$me->getKey(), $this->firstName($me?->name), ['side' => $side], ['canPublishData' => false]),
        ];
    }

    private function firstName(?string $name): string
    {
        return strtok(trim((string) $name), ' ') ?: '';
    }
}
