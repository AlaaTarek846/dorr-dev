<?php

namespace Modules\Provider\Services\Notifications;

use App\Services\Notifications\NotificationCenter;
use Modules\Moving\Models\MovingCase;
use Modules\Moving\Models\MovingRequest;
use Modules\Moving\Models\MovingTicket;
use Modules\Provider\Enums\EstablishmentStatus;
use Modules\Provider\Enums\VehicleStatus;
use Modules\Provider\Models\Establishment;
use Modules\Provider\Models\ProviderDocument;
use Modules\Provider\Models\Vehicle;

/**
 * What the dashboard is told about the providers' side, so nothing waits unseen: an establishment sent for review,
 * a paper or a vehicle to look at, a customer's report after a move, an exception asked on a cancellation, and a
 * ticket that reached DORR. To the admins who hold the page's permission (super-admins always) — the bell's list
 * and its live toast, each opening the page it is about (`data.url`). No phone push.
 *
 * Papers and vehicles of an establishment still being filled in (draft) wait for its "send for review", which says
 * it once for all of them.
 *
 * Texts: lang/{ar,en}/notifications.php → `admin_{event}_title|body`.
 */
class ProviderAdminNotifier
{
    public function __construct(private readonly NotificationCenter $center) {}

    public function establishmentSubmitted(Establishment $establishment): void
    {
        $this->send('provider-establishments.view', 'provider_establishment_submitted', ['name' => $establishment->name], $this->establishmentUrl($establishment));
    }

    public function documentUploaded(ProviderDocument $document): void
    {
        $establishment = $document->establishment;
        if ($establishment === null || $establishment->status === EstablishmentStatus::Draft) {
            return;
        }
        $this->send('provider-establishments.view', 'provider_document_uploaded', [
            'name' => $establishment->name, 'document' => (string) $document->type?->translatedName(),
        ], $this->establishmentUrl($establishment));
    }

    public function vehiclePending(Vehicle $vehicle): void
    {
        $establishment = $vehicle->establishment;
        if ($establishment === null || $establishment->status === EstablishmentStatus::Draft || $vehicle->status !== VehicleStatus::Pending) {
            return;
        }
        $this->send('provider-establishments.view', 'provider_vehicle_pending', [
            'name' => $establishment->name, 'plate' => trim($vehicle->plate_letters.' '.$vehicle->plate_number),
        ], $this->establishmentUrl($establishment));
    }

    public function movingCaseOpened(MovingCase $case): void
    {
        $key = $case->kind === MovingCase::REPORT ? 'moving_report_opened' : 'moving_exception_requested';
        $this->send('moving-cases.view', $key, ['request' => $this->requestRef($case->request_id)], '/admin/moving/cases');
    }

    public function movingTicketEscalated(MovingTicket $ticket): void
    {
        $this->send('moving-tickets.view', 'moving_ticket_escalated', [
            'subject' => (string) $ticket->subject, 'request' => $this->requestRef($ticket->request_id),
        ], '/admin/moving/tickets');
    }

    /** @param array<string, string> $variables */
    private function send(string $permission, string $event, array $variables, string $url): void
    {
        $admins = $this->center->adminsWith($permission);
        if ($admins->isEmpty()) {
            return;
        }
        $this->center->send($admins, 'admin.'.$event, "admin_{$event}_title", "admin_{$event}_body", $variables, ['type' => 'provider_admin', 'url' => $url], push: false);
    }

    private function establishmentUrl(Establishment $establishment): string
    {
        return '/admin/moving/establishments/'.$establishment->uuid;
    }

    /** A request as people read it: the first part of its id. */
    private function requestRef(?int $requestId): string
    {
        $uuid = MovingRequest::query()->whereKey($requestId)->value('uuid');

        return $uuid ? '#'.strtoupper(substr((string) $uuid, 0, 8)) : '';
    }
}
