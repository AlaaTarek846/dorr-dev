<?php

namespace Modules\Rides\Services\Safety;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideLostItem;
use Modules\Rides\Models\RideLostItemMessage;
use Modules\User\Models\User;

/**
 * Lost items (docs/rides_tasks.md D-294..D-297): the rider reports what they left (within `lost_items.report_days`
 * of the ride), the captain hears of it and answers — found or not — they agree how it comes back in messages here
 * (never their numbers), and the captain records the return with a photo. Each step tells the other side.
 */
class LostItems
{
    public function __construct(private readonly NotificationCenter $center) {}

    public function report(User $rider, Ride $ride, string $description): RideLostItem
    {
        if ($ride->user_id !== $rider->id || $ride->status !== RideStatus::Completed || $ride->driver_id === null) {
            throw new RidesException('lost_item_not_allowed', 409);
        }
        $days = (int) $ride->policy('lost_items.report_days', 7);
        if ($ride->completed_at !== null && $ride->completed_at->lt(now()->subDays($days))) {
            throw new RidesException('lost_item_too_late', 409, ['days' => $days]);
        }
        if (RideLostItem::query()->where('ride_id', $ride->id)->whereIn('status', ['reported', 'found'])->exists()) {
            throw new RidesException('lost_item_already_open', 409);
        }
        $item = RideLostItem::query()->create(['ride_id' => $ride->id, 'user_id' => $rider->id, 'driver_id' => $ride->driver_id, 'description' => mb_substr(trim($description), 0, 500)]);
        $this->center->send($ride->driver, 'lost.reported', 'lost_reported_title', 'lost_reported_body', [
            'number' => $ride->number, 'what' => mb_substr($item->description, 0, 80),
        ], ['type' => 'lost_item', 'lost_uuid' => $item->uuid], true, ['priority' => 10]);

        return $item;
    }

    public function answer(Driver $driver, RideLostItem $item, bool $found, ?string $plan): RideLostItem
    {
        $this->assertDriver($driver, $item);
        if (! in_array($item->status, ['reported', 'found', 'not_found'], true)) {
            throw new RidesException('lost_item_closed', 409);
        }
        $item->forceFill(['status' => $found ? 'found' : 'not_found', 'return_plan' => $plan ? mb_substr($plan, 0, 300) : $item->return_plan, 'answered_at' => now()])->save();
        $this->toRider($item, $found ? 'lost.found' : 'lost.not_found', $found ? 'lost_found' : 'lost_not_found', ['plan' => (string) $item->return_plan]);

        return $item->refresh();
    }

    /** D-297: the return, with a photo of the item handed back. */
    public function returned(Driver $driver, RideLostItem $item, ?UploadedFile $photo): RideLostItem
    {
        $this->assertDriver($driver, $item);
        if ($item->status !== 'found') {
            throw new RidesException('lost_item_not_found_yet', 409);
        }
        $path = $photo?->store('lost-items/'.$item->id, 'public');
        $item->forceFill(['status' => 'returned', 'returned_at' => now(), 'return_photo_path' => $path])->save();
        $this->toRider($item, 'lost.returned', 'lost_returned', []);

        return $item->refresh();
    }

    /** D-296: a message to the other side, inside the app. */
    public function message(User|Driver $from, RideLostItem $item, string $body): RideLostItemMessage
    {
        $side = $from instanceof Driver ? 'driver' : 'user';
        if (($side === 'driver' && $item->driver_id !== $from->id) || ($side === 'user' && $item->user_id !== $from->id)) {
            throw new RidesException('lost_item_not_found', 404);
        }
        if (in_array($item->status, ['returned', 'closed'], true)) {
            throw new RidesException('lost_item_closed', 409);
        }
        $message = $item->messages()->create(['sender' => $side, 'body' => mb_substr(trim($body), 0, 1000), 'created_at' => now()]);
        $to = $side === 'driver' ? User::query()->find($item->user_id) : Driver::query()->find($item->driver_id);
        $this->center->send($to, 'lost.message', 'lost_message_title', 'lost_message_body', ['text' => mb_substr($message->body, 0, 120)], [
            'type' => 'lost_item', 'lost_uuid' => $item->uuid,
        ]);

        return $message;
    }

    public function close(User $rider, RideLostItem $item): RideLostItem
    {
        if ($item->user_id !== $rider->id) {
            throw new RidesException('lost_item_not_found', 404);
        }
        $item->forceFill(['status' => 'closed'])->save();

        return $item->refresh();
    }

    /** @return array<string, mixed> */
    public static function present(RideLostItem $i, string $viewer): array
    {
        $i->loadMissing(['ride', 'messages']);

        return [
            'uuid' => $i->uuid, 'status' => $i->status, 'description' => $i->description, 'return_plan' => $i->return_plan,
            'ride' => ['uuid' => $i->ride?->uuid, 'number' => $i->ride?->number, 'completed_at' => $i->ride?->completed_at?->toIso8601String()],
            'return_photo_url' => $i->return_photo_path ? Storage::disk('public')->url($i->return_photo_path) : null,
            'created_at' => $i->created_at?->toIso8601String(), 'answered_at' => $i->answered_at?->toIso8601String(), 'returned_at' => $i->returned_at?->toIso8601String(),
            'messages' => $i->messages->sortBy('id')->map(fn (RideLostItemMessage $m) => [
                'id' => $m->id, 'mine' => $m->sender === $viewer, 'body' => $m->body, 'created_at' => $m->created_at?->toIso8601String(),
            ])->values(),
        ];
    }

    private function toRider(RideLostItem $item, string $event, string $key, array $vars): void
    {
        $this->center->send(User::query()->find($item->user_id), $event, $key.'_title', $key.'_body', $vars + ['what' => mb_substr($item->description, 0, 80)], [
            'type' => 'lost_item', 'lost_uuid' => $item->uuid,
        ]);
    }

    private function assertDriver(Driver $driver, RideLostItem $item): void
    {
        if ($item->driver_id !== $driver->id) {
            throw new RidesException('lost_item_not_found', 404);
        }
    }
}
