<?php

namespace Modules\Rides\Services\Bus;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Chat\Support\LiveKitToken;
use Modules\Rides\Events\BusTalkChanged;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\BusBooking;
use Modules\Rides\Models\BusBookingCall;
use Modules\Rides\Models\BusBookingMessage;
use Throwable;

/**
 * O-68: a bus passenger and the captain talk inside the app — a chat per ticket and in-app voice calls
 * (LiveKit, the same as a ride's: O-09). Open from the booking until the trip is over; neither sees the
 * other's number.
 *
 *  - A message reaches the other side live and by push (`bus.message`).
 *  - A call rings the other side by a high-priority push (`bus.call`); each change after that (answered,
 *    declined, hung up) is broadcast to both (`bus.call_changed`) — nothing asks on a timer.
 *
 * `$side` is who acts: `user` (the passenger) or `driver` (the captain).
 */
class BusTalkService
{
    /** Ready-made messages, by who sends them (texts in lang/{locale}/rides.php → quick). */
    public const QUICK = [
        'user' => ['coming', 'where_are_you', 'wait_please', 'at_pickup', 'call_me'],
        'driver' => ['on_my_way', 'arrived', 'traffic', 'where_exactly', 'waiting'],
    ];

    public function __construct(
        private readonly NotificationCenter $center,
        private readonly BusService $bus,
    ) {}

    public function isOpen(BusBooking $booking): bool
    {
        return $booking->status === 'confirmed' && in_array($booking->trip?->status, ['scheduled', 'departed', 'returning'], true);
    }

    /** @return Collection<int, array<string, mixed>> oldest first; the other side's messages are marked read */
    public function messages(BusBooking $booking, string $side): Collection
    {
        BusBookingMessage::query()->where('bus_booking_id', $booking->id)->where('sender_type', '!=', $side)->whereNull('read_at')->update(['read_at' => now()]);

        return BusBookingMessage::query()->where('bus_booking_id', $booking->id)->orderBy('id')->limit(500)->get()
            ->map(fn (BusBookingMessage $m) => self::presentMessage($m, $side));
    }

    /** @return array<string, mixed> */
    public function send(BusBooking $booking, string $side, ?string $body, ?string $quick, ?string $clientId): array
    {
        if (! $this->isOpen($booking)) {
            throw new RidesException('chat_closed', 409);
        }
        if ($quick !== null && ! in_array($quick, self::QUICK[$side], true)) {
            throw new RidesException('chat_unknown_quick', 422);
        }
        $sender = $side === 'user' ? $booking->user : $booking->trip->driver;
        $text = $quick !== null ? __('rides.quick.'.$quick, [], $sender?->locale ?? app()->getLocale()) : trim((string) $body);
        if ($text === '') {
            throw new RidesException('chat_empty', 422);
        }

        try {
            $message = BusBookingMessage::query()->create([
                'bus_booking_id' => $booking->id, 'sender_type' => $side, 'sender_id' => $sender->getKey(),
                'kind' => $quick !== null ? 'quick' : 'text', 'quick_key' => $quick, 'body' => mb_substr($text, 0, 1000),
                'client_id' => $clientId, 'created_at' => now(),
            ]);
        } catch (QueryException) {
            // The same message sent again (a weak network): the one already stored.
            $message = BusBookingMessage::query()->where('bus_booking_id', $booking->id)->where('sender_type', $side)->where('client_id', $clientId)->firstOrFail();

            return self::presentMessage($message, $side);
        }

        $receiver = $side === 'user' ? $booking->trip->driver : $booking->user;
        $this->center->send($receiver, 'bus.message', 'ride_message_title', 'ride_message_body', [
            'name' => $this->firstName($sender?->name), 'text' => mb_substr($message->body, 0, 120),
        ], ['type' => 'bus_message', 'booking_uuid' => $booking->uuid, 'trip_uuid' => $booking->trip->uuid]);

        return self::presentMessage($message, $side);
    }

    /** @return array{call: BusBookingCall, join: array{url: string, room: string, token: string}} */
    public function startCall(BusBooking $booking, string $side): array
    {
        $this->assertCallable($booking);
        $this->settleStale($booking);
        if (($live = BusBookingCall::query()->where('bus_booking_id', $booking->id)->whereIn('status', BusBookingCall::LIVE)->first()) !== null) {
            // Both called at once: whoever comes second joins the call already ringing.
            return $live->caller === $side ? ['call' => $live, 'join' => $this->join($booking, $live, $side)] : $this->accept($booking, $live, $side);
        }

        $call = BusBookingCall::query()->create(['bus_booking_id' => $booking->id, 'caller' => $side, 'status' => 'ringing', 'room' => 'bus-'.$booking->uuid.'-'.Str::lower(Str::random(8))]);
        $ring = $this->ringSeconds($booking);
        $from = $side === 'user' ? $booking->user : $booking->trip->driver;
        $to = $side === 'user' ? $booking->trip->driver : $booking->user;
        $this->center->send($to, 'bus.call', 'ride_call_title', 'bus_call_body', ['name' => $this->firstName($from?->name)], [
            'type' => 'bus_call', 'booking_uuid' => $booking->uuid, 'trip_uuid' => $booking->trip->uuid, 'call_uuid' => $call->uuid,
            'name' => $this->firstName($from?->name), 'expires_at' => now()->addSeconds($ring)->toIso8601String(),
        ], true, ['priority' => 10, 'ttl' => $ring, 'android_visibility' => 1]);
        $this->changed($booking, $call);

        return ['call' => $call, 'join' => $this->join($booking, $call, $side)];
    }

    /** @return array{call: BusBookingCall, join: array{url: string, room: string, token: string}} */
    public function accept(BusBooking $booking, BusBookingCall $call, string $side): array
    {
        $this->settleStale($booking);
        $call->refresh();
        if ($call->status !== 'ringing' || $call->caller === $side) {
            throw new RidesException('call_over', 409);
        }
        $call->forceFill(['status' => 'active', 'answered_at' => now()])->save();
        $this->changed($booking, $call);

        return ['call' => $call, 'join' => $this->join($booking, $call, $side)];
    }

    /** Declining (the one rung) or hanging up (either side, ringing or talking). */
    public function end(BusBooking $booking, BusBookingCall $call, string $side): BusBookingCall
    {
        if (! in_array($call->status, BusBookingCall::LIVE, true)) {
            return $call;
        }
        $call->forceFill([
            'status' => match (true) { $call->status === 'active' => 'ended', $call->caller === $side => 'canceled', default => 'declined' },
            'ended_at' => now(), 'ended_by' => $side,
        ])->save();
        $this->changed($booking, $call);

        return $call;
    }

    public function call(BusBooking $booking, string $uuid): BusBookingCall
    {
        return BusBookingCall::query()->where('bus_booking_id', $booking->id)->where('uuid', $uuid)->first() ?? throw new RidesException('call_over', 404);
    }

    /** @return array<string, mixed>|null the call going on now (the apps catch up on open) */
    public function currentCall(BusBooking $booking): ?array
    {
        $call = BusBookingCall::query()->where('bus_booking_id', $booking->id)->whereIn('status', BusBookingCall::LIVE)->latest('id')->first();
        if ($call === null || ($call->status === 'ringing' && $call->created_at->lt(now()->subSeconds($this->ringSeconds($booking))))) {
            return null;
        }

        return self::presentCall($call);
    }

    public static function unread(BusBooking $booking, string $viewerSide): int
    {
        return BusBookingMessage::query()->where('bus_booking_id', $booking->id)->where('sender_type', '!=', $viewerSide)->whereNull('read_at')->count();
    }

    /** @return list<array{key: string, text: string}> */
    public static function quickReplies(string $side): array
    {
        return array_map(fn (string $key) => ['key' => $key, 'text' => __('rides.quick.'.$key)], self::QUICK[$side]);
    }

    /** @return array<string, mixed> */
    public static function presentMessage(BusBookingMessage $m, ?string $viewer): array
    {
        return [
            'id' => $m->id, 'from' => $m->sender_type, 'mine' => $viewer !== null ? $m->sender_type === $viewer : null,
            'kind' => $m->kind, 'quick_key' => $m->quick_key, 'body' => $m->body, 'client_id' => $m->client_id,
            'read_at' => $m->read_at?->toIso8601String(), 'created_at' => $m->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function presentCall(BusBookingCall $call): array
    {
        return [
            'uuid' => $call->uuid, 'status' => $call->status, 'caller' => $call->caller,
            'started_at' => $call->created_at->toIso8601String(), 'answered_at' => $call->answered_at?->toIso8601String(),
        ];
    }

    private function assertCallable(BusBooking $booking): void
    {
        if (! $this->isOpen($booking) || ! (bool) $this->bus->policy($booking->trip->country_id)->get('calls.in_app') ?? true) {
            throw new RidesException('call_not_now', 409);
        }
        if ((string) config('chat.livekit.url') === '' || (string) config('chat.livekit.api_key') === '' || (string) config('chat.livekit.api_secret') === '') {
            throw new RidesException('call_not_configured', 503);
        }
    }

    private function ringSeconds(BusBooking $booking): int
    {
        return $this->bus->policy($booking->trip->country_id)->int('calls.ring_s') ?: 45;
    }

    /** Rings nobody answered in time become missed. */
    private function settleStale(BusBooking $booking): void
    {
        BusBookingCall::query()->where('bus_booking_id', $booking->id)->where('status', 'ringing')
            ->where('created_at', '<', now()->subSeconds($this->ringSeconds($booking)))
            ->update(['status' => 'missed', 'ended_at' => now()]);
    }

    private function changed(BusBooking $booking, BusBookingCall $call): void
    {
        $payload = ['booking_uuid' => $booking->uuid, 'trip_uuid' => $booking->trip->uuid, 'call_uuid' => $call->uuid, 'status' => $call->status];
        $userId = (int) $booking->user_id;
        $driverId = (int) $booking->trip->driver_id;
        DB::afterCommit(function () use ($userId, $driverId, $payload) {
            try {
                broadcast(new BusTalkChanged($userId, $driverId, 'bus.call_changed', $payload));
            } catch (Throwable $e) {
                Log::warning('[Rides] bus call broadcast failed: '.$e->getMessage());
            }
        });
    }

    /** @return array{url: string, room: string, token: string} */
    private function join(BusBooking $booking, BusBookingCall $call, string $side): array
    {
        $me = $side === 'user' ? $booking->user : $booking->trip->driver;

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
