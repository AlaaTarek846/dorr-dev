<?php

namespace Modules\Freelance\Services\Notifications;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Freelance\Models\Freelancer;

/**
 * The catalog of freelance notifications (FL-007): every event the work can raise, who it is for, and
 * where its texts live. Later tasks only call `notify($receiver, 'offer.accepted', [...], [...])` — nobody
 * invents texts or event names. Each notice is stored in-app, sent live on the receiver's private channel
 * and pushed (OneSignal) in every language; the receiver's app shows it in theirs.
 *
 * Texts: lang/{ar,en}/notifications.php → `freelance_{audience}_{event}_title|body`, with dots as underscores.
 */
class FreelanceNotifier
{
    /** event => who gets it ('freelancer' or 'client'), as variables its text expects */
    public const EVENTS = [
        // For the freelancer
        'freelancer.invitation.received' => ['project'],
        'freelancer.offer.viewed' => ['project'],
        'freelancer.offer.shortlisted' => ['project'],
        'freelancer.offer.accepted' => ['project'],
        'freelancer.offer.rejected' => ['project'],
        'freelancer.agreement.received' => ['project'],
        'freelancer.agreement.cancelled' => ['project'],
        'freelancer.agreement.expired' => ['project'],
        'freelancer.project.cancelled' => ['project'],
        'freelancer.project.completed' => ['project'],
        'freelancer.milestone.revisions_exhausted' => ['project'],
        'freelancer.milestone.cancel_requested' => ['project'],
        'freelancer.milestone.cancel_rejected' => ['project'],
        'freelancer.milestone.cancelled' => ['project', 'amount'],
        'freelancer.milestone.called_off' => ['project'],
        'freelancer.milestone.approved' => ['project'],
        'freelancer.milestone.revision' => ['project'],
        'freelancer.payment.held' => ['project', 'amount'],
        'freelancer.payment.released' => ['project', 'amount'],
        'freelancer.payment.pending' => ['project', 'amount', 'date'],
        'freelancer.payment.available' => ['project', 'amount'],
        'freelancer.verification.approved' => [],
        'freelancer.verification.rejected' => ['reason'],
        'freelancer.verification.changes_requested' => ['reason'],
        'freelancer.dispute.opened' => ['project'],
        'freelancer.dispute.resolved' => ['project'],
        'freelancer.dispute.decided' => ['project'],
        'freelancer.review.received' => ['project', 'name'],
        'freelancer.review.published' => ['project', 'name'],
        'freelancer.change.requested' => ['project'],
        'freelancer.change.accepted' => ['project'],
        'freelancer.change.rejected' => ['project'],
        'freelancer.change.cancelled' => ['project'],
        'freelancer.dispute.appealed' => ['project'],
        'freelancer.quota.low' => ['count'],
        'freelancer.portfolio.hidden' => ['title', 'reason'],
        'freelancer.skill.approved' => ['name'],
        'freelancer.skill.rejected' => ['name', 'reason'],
        // For the client (a Dorr user)
        'client.project.published' => ['project'],
        'client.project.offers_closed' => ['project'],
        'client.project.rejected' => ['project', 'reason'],
        'client.project.changes_requested' => ['project', 'reason'],
        'client.invitation.accepted' => ['project', 'name'],
        'client.invitation.declined' => ['project', 'name'],
        'client.invitation.none_left' => ['project'],
        'client.interview.proposed' => ['project', 'name', 'time'],
        'client.interview.confirmed' => ['project', 'name', 'time'],
        'client.interview.declined' => ['project', 'name', 'time'],
        'client.interview.cancelled' => ['project', 'name', 'time'],
        'client.interview.reminder' => ['project', 'name', 'time'],
        'freelancer.interview.proposed' => ['project', 'name', 'time'],
        'freelancer.interview.confirmed' => ['project', 'name', 'time'],
        'freelancer.interview.declined' => ['project', 'name', 'time'],
        'freelancer.interview.cancelled' => ['project', 'name', 'time'],
        'freelancer.interview.reminder' => ['project', 'name', 'time'],
        'client.offer.received' => ['project', 'name'],
        'client.offer.updated' => ['project', 'name'],
        'client.agreement.active' => ['project', 'name'],
        'client.agreement.declined' => ['project', 'name'],
        'client.agreement.expired' => ['project'],
        'client.milestone.delivered' => ['project', 'name'],
        'client.milestone.review_due' => ['project', 'date'],
        'client.milestone.auto_approved' => ['project'],
        'client.project.completed' => ['project'],
        'client.milestone.cancel_requested' => ['project'],
        'client.milestone.cancel_rejected' => ['project'],
        'client.milestone.cancelled' => ['project', 'amount'],
        'client.milestone.called_off' => ['project'],
        'client.dispute.opened' => ['project'],
        'client.dispute.resolved' => ['project'],
        'client.dispute.decided' => ['project'],
        'client.change.requested' => ['project'],
        'client.change.accepted' => ['project'],
        'client.change.rejected' => ['project'],
        'client.change.cancelled' => ['project'],
        'client.dispute.appealed' => ['project'],
        'client.review.received' => ['project', 'name'],
        'client.review.published' => ['project', 'name'],
    ];

    public function __construct(private readonly NotificationCenter $notifications) {}

    /**
     * @param  string  $event  e.g. 'offer.accepted' (the audience comes from the receiver)
     * @param  array<string, scalar|null>  $variables  the placeholders the text uses (see EVENTS)
     * @param  array<string, mixed>  $data  ids for the app to open the right screen — no secrets
     */
    public function notify(Model $receiver, string $event, array $variables = [], array $data = []): void
    {
        $audience = $receiver instanceof Freelancer ? 'freelancer' : 'client';
        $key = $audience.'.'.$event;

        if (! array_key_exists($key, self::EVENTS)) {
            throw new InvalidArgumentException("Unknown freelance notification [{$key}].");
        }
        $missing = array_diff(self::EVENTS[$key], array_keys($variables));
        if ($missing !== []) {
            throw new InvalidArgumentException("Freelance notification [{$key}] needs: ".implode(', ', $missing).'.');
        }

        $lang = 'freelance_'.str_replace('.', '_', $key);

        $this->notifications->send(
            $receiver,
            'freelance.'.$event,
            $lang.'_title',
            $lang.'_body',
            $variables,
            ['type' => 'freelance_'.str_replace('.', '_', $event)] + $data,
        );
    }

    /** @return list<string> every event key, for the texts check */
    public static function keys(): array
    {
        return array_keys(self::EVENTS);
    }
}
