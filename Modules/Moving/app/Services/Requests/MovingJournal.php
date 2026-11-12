<?php

namespace Modules\Moving\Services\Requests;

use Modules\Moving\Enums\MovingStatus;
use Modules\Moving\Events\MovingRequestChanged;
use Modules\Moving\Models\MovingRequest;
use Modules\Moving\Models\MovingRequestEvent;

/**
 * Every move of a request goes through here: the status changes, the step is written down (who, what, where), and
 * the apps hear about it at once (MovingRequestChanged) — no app asks on a timer.
 */
class MovingJournal
{
    /**
     * @param  array<string, mixed>  $attributes  saved with the move
     * @param  array<string, mixed>  $data  kept with the step
     */
    public function move(MovingRequest $request, ?MovingStatus $to, string $action, string $actorType, ?int $actorId, array $attributes = [], array $data = [], ?float $lat = null, ?float $lng = null): MovingRequest
    {
        $from = $request->status;
        $request->fill($attributes);
        if ($to !== null) {
            $request->status = $to;
        }
        $request->save();

        MovingRequestEvent::query()->create([
            'request_id' => $request->id,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'data' => $data ?: null,
            'lat' => $lat,
            'lng' => $lng,
        ]);
        event(new MovingRequestChanged($request, $action));

        return $request;
    }
}
