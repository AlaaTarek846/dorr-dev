<?php

namespace Modules\AI\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A DORR service that DORR AI can offer right inside a chat answer (rides when the person wants to go
 * somewhere, later bookings, deliveries…). The AI module knows only this contract; each module registers
 * its own provider with {@see \Modules\AI\Services\AiServiceActions}.
 */
interface AiServiceActionProvider
{
    /**
     * Extra guidance for the model when this message is about the service (null = not about it). Cheap: it
     * runs before every answer.
     */
    public function guidance(Authenticatable $owner, string $message): ?string;

    /**
     * The cards to show under the answer — each `{type, service, title, …}` with whatever the app needs to
     * open the service already filled in. Empty when the message isn't about the service or it isn't open
     * for this person.
     *
     * @return list<array<string, mixed>>
     */
    public function actions(Authenticatable $owner, string $message): array;
}
