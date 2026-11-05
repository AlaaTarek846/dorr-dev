<?php

namespace Modules\Provider\Services\Notifications;

use App\Services\Notifications\NotificationCenter;
use Modules\Provider\Enums\DocumentStatus;
use Modules\Provider\Events\EstablishmentReviewed;
use Modules\Provider\Events\ProviderDocumentExpiring;
use Modules\Provider\Events\ProviderDocumentReviewed;
use Modules\Provider\Models\Membership;
use Modules\Provider\Models\Provider;

/**
 * What the providers app is told about its establishment (docs/moving_tasks.md C-07): the admin's decision on the
 * establishment, on a paper or vehicle photo, a paper about to end, and an invitation to join. Stored in-app, sent on
 * the person's private channel and pushed (the providers app's own OneSignal app when set).
 *
 * Texts: lang/{ar,en}/notifications.php → `provider_{event}_title|body`.
 */
class ProviderNotifier
{
    public function __construct(private readonly NotificationCenter $notifications) {}

    public function establishmentReviewed(EstablishmentReviewed $event): void
    {
        $establishment = $event->establishment;
        $this->send($establishment->owner, 'establishment_'.$establishment->status->value, [
            'name' => $establishment->name, 'reason' => (string) $establishment->status_reason,
        ], ['establishment' => $establishment->uuid]);
    }

    public function documentReviewed(ProviderDocumentReviewed $event): void
    {
        $document = $event->document->loadMissing(['type.translations', 'establishment.owner']);
        $key = $document->status === DocumentStatus::Approved ? 'document_approved' : 'document_rejected';
        $variables = ['document' => (string) $document->type?->translatedName(), 'reason' => (string) $document->rejection_reason];
        $receivers = [$document->establishment->owner];
        // A driver's licence also reaches the driver.
        if ($document->subject_type === 'membership') {
            $receivers[] = Membership::query()->find($document->subject_id)?->provider;
        }
        foreach (collect($receivers)->filter()->unique('id') as $person) {
            $this->send($person, $key, $variables, ['establishment' => $document->establishment->uuid, 'document' => $document->uuid]);
        }
    }

    public function documentExpiring(ProviderDocumentExpiring $event): void
    {
        $document = $event->document->loadMissing(['type.translations', 'establishment.owner']);
        $this->send($document->establishment->owner, 'document_expiring', [
            'document' => (string) $document->type?->translatedName(), 'date' => (string) $document->expires_on?->toDateString(),
        ], ['establishment' => $document->establishment->uuid, 'document' => $document->uuid]);
    }

    public function invited(Membership $membership): void
    {
        $membership->loadMissing(['provider', 'establishment']);
        if ($membership->provider !== null) {
            $this->send($membership->provider, 'invitation', ['name' => $membership->establishment->name], ['membership' => $membership->uuid]);
        }
    }

    /**
     * @param  array<string, string>  $variables
     * @param  array<string, string>  $data
     */
    private function send(?Provider $receiver, string $event, array $variables, array $data): void
    {
        if ($receiver === null) {
            return;
        }
        $this->notifications->send($receiver, 'provider.'.$event, "provider_{$event}_title", "provider_{$event}_body", $variables, ['type' => 'provider_'.$event] + $data);
    }
}
