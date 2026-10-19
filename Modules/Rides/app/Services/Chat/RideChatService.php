<?php

namespace Modules\Rides\Services\Chat;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Driver\Models\Driver;
use Modules\Rides\Events\RideMessageSent;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\RideMessage;
use Throwable;

/**
 * The rider ↔ driver chat of a ride (owner, 2026-10-13): text and ready-made messages, open only
 * while a driver is on the ride (accepted → arrived → on the way). When the ride ends nobody can
 * write any more, but the conversation stays — the admin reads it if there's a complaint.
 */
class RideChatService
{
    /** Ready-made messages, by who sends them (texts in lang/{locale}/rides.php → quick). */
    public const QUICK = [
        'user' => ['coming', 'where_are_you', 'wait_please', 'at_pickup', 'call_me'],
        'driver' => ['on_my_way', 'arrived', 'traffic', 'where_exactly', 'waiting'],
    ];

    public function __construct(private readonly NotificationCenter $notifications) {}

    public function isOpen(Ride $ride): bool
    {
        return $ride->driver_id !== null && $ride->status->hasDriver();
    }

    /** @return Collection<int, array<string, mixed>> the conversation, oldest first; the other side's messages are marked read */
    public function messages(Ride $ride, Model $reader): Collection
    {
        $side = self::side($reader);
        RideMessage::query()->where('ride_id', $ride->id)->where('sender_type', '!=', $side)->whereNull('read_at')->update(['read_at' => now()]);

        return RideMessage::query()->where('ride_id', $ride->id)->orderBy('id')->limit(500)->get()
            ->map(fn (RideMessage $m) => self::present($m, $side));
    }

    /** @return array<string, mixed> */
    public function send(Ride $ride, Model $sender, ?string $body, ?string $quick, ?string $clientId): array
    {
        if (! $this->isOpen($ride)) {
            throw new RidesException('chat_closed', 409);
        }
        $side = self::side($sender);
        if ($quick !== null && ! in_array($quick, self::QUICK[$side], true)) {
            throw new RidesException('chat_unknown_quick', 422);
        }
        $text = $quick !== null ? __('rides.quick.'.$quick, [], $sender->locale ?? app()->getLocale()) : trim((string) $body);
        if ($text === '') {
            throw new RidesException('chat_empty', 422);
        }

        try {
            $message = RideMessage::query()->create([
                'ride_id' => $ride->id,
                'sender_type' => $side,
                'sender_id' => $sender->getKey(),
                'kind' => $quick !== null ? 'quick' : 'text',
                'quick_key' => $quick,
                'body' => mb_substr($text, 0, 1000),
                'client_id' => $clientId,
                'created_at' => now(),
            ]);
        } catch (QueryException) {
            // The same message sent again (a weak network): the one already stored.
            $message = RideMessage::query()->where('ride_id', $ride->id)->where('sender_type', $side)->where('client_id', $clientId)->firstOrFail();

            return self::present($message, $side);
        }

        $this->deliver($ride, $message);

        return self::present($message, $side);
    }

    private function deliver(Ride $ride, RideMessage $message): void
    {
        DB::afterCommit(function () use ($ride, $message) {
            try {
                broadcast(new RideMessageSent($ride->uuid, self::present($message, null)));
            } catch (Throwable $e) {
                Log::warning('[Rides] chat broadcast failed: '.$e->getMessage());
            }
        });

        $receiver = $message->sender_type === 'user' ? $ride->driver : $ride->rider;
        if ($receiver !== null) {
            $this->notifications->send($receiver, 'ride.message', 'ride_message_title', 'ride_message_body', [
                'name' => $message->sender_type === 'user' ? (strtok((string) $ride->rider?->name, ' ') ?: '') : ($ride->driver?->name ?? ''),
                'text' => mb_substr($message->body, 0, 120),
            ], ['type' => 'ride_message', 'ride_uuid' => $ride->uuid]);
        }
    }

    /** @return array<string, mixed> */
    public static function present(RideMessage $message, ?string $viewer): array
    {
        return [
            'id' => $message->id,
            'from' => $message->sender_type,
            'mine' => $viewer !== null ? $message->sender_type === $viewer : null,
            'kind' => $message->kind,
            'quick_key' => $message->quick_key,
            'body' => $message->body,
            'client_id' => $message->client_id,
            'read_at' => $message->read_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /** @return list<array{key: string, text: string}> the ready-made messages this side may send */
    public static function quickReplies(Model $sender): array
    {
        return array_map(fn (string $key) => ['key' => $key, 'text' => __('rides.quick.'.$key)], self::QUICK[self::side($sender)]);
    }

    /** O-10: messages from the other side not read yet — the badge on the chat icon. */
    public static function unread(Ride $ride, string $viewerSide): int
    {
        return RideMessage::query()->where('ride_id', $ride->id)->where('sender_type', '!=', $viewerSide)->whereNull('read_at')->count();
    }

    /**
     * O-09: the other side's number for a call from outside the app — only while the ride is on
     * (accepted → in progress), and only where the policy allows it (`calls.reveal_phone`).
     */
    public static function callPhone(Ride $ride, string $viewerSide): ?string
    {
        if (! in_array($ride->status, [RideStatus::Accepted, RideStatus::Arrived, RideStatus::InProgress], true) || ! (bool) $ride->policy('calls.reveal_phone', true)) {
            return null;
        }
        if ($viewerSide === 'driver') {
            return ($ride->beneficiary['phone'] ?? null) ?: $ride->rider()->value('phone');
        }

        return $ride->driver()->value('phone');
    }

    private static function side(Model $person): string
    {
        return $person instanceof Driver ? 'driver' : 'user';
    }
}
