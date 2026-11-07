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
        'freelancer.project.matched' => ['project'],
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
        'freelancer.change.reminder' => ['project'],
        'freelancer.health.at_risk' => ['project'],
        'freelancer.health.delayed' => ['project'],
        'freelancer.health.blocked' => ['project'],
        'freelancer.dispute.appealed' => ['project'],
        'freelancer.quota.low' => ['count'],
        // FL-L01: the freelancer's plan
        'freelancer.subscription.started' => ['plan', 'date'],
        'freelancer.subscription.renewing' => ['plan', 'date', 'amount'],
        'freelancer.subscription.renewed' => ['plan', 'date'],
        'freelancer.subscription.renewal_failed' => ['plan', 'date'],
        'freelancer.subscription.expired' => ['plan'],
        // FL-L04: ready-made services and their orders
        'freelancer.service.approved' => ['service'],
        'freelancer.service.rejected' => ['service', 'reason'],
        'freelancer.service.suspended' => ['service', 'reason'],
        'freelancer.order.received' => ['service', 'name'],
        'freelancer.waitlist.joined' => ['service', 'name'],
        // FL-L09: special offers to past clients
        'freelancer.special_offer.accepted' => ['name', 'title'],
        'freelancer.special_offer.declined' => ['name', 'title'],
        // FL-L05: monthly service subscriptions
        'freelancer.retainer.renewed' => ['service', 'name', 'cycle'],
        'freelancer.retainer.cancel_requested' => ['service', 'date'],
        'freelancer.retainer.kept' => ['service'],
        'freelancer.retainer.ended' => ['service'],
        // FL-L07: paid consultations.
        'freelancer.consultation.listing_approved' => ['title'],
        'freelancer.consultation.listing_rejected' => ['title', 'reason'],
        'freelancer.consultation.listing_suspended' => ['title', 'reason'],
        'freelancer.consultation.requested' => ['name', 'time', 'minutes'],
        'freelancer.consultation.expired' => ['name', 'time'],
        'freelancer.consultation.cancelled' => ['name', 'time'],
        'freelancer.consultation.reminder' => ['name', 'time'],
        'freelancer.consultation.complained' => ['name', 'time'],
        'freelancer.consultation.paid' => ['name', 'amount'],
        'freelancer.consultation.refunded' => ['name', 'time'],
        'freelancer.consultation.rated' => ['name', 'stars', 'title'],
        'freelancer.portfolio.hidden' => ['title', 'reason'],
        'freelancer.skill.approved' => ['name'],
        'freelancer.skill.rejected' => ['name', 'reason'],
        'freelancer.report.due' => ['project'],
        'freelancer.review.objection_decided' => ['project', 'outcome'],
        'freelancer.review.moderated' => ['project', 'outcome'],
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
        'client.retainer.renew_due' => ['service', 'date'],
        'client.retainer.cancel_requested' => ['service', 'date'],
        'client.retainer.kept' => ['service'],
        'client.retainer.ended' => ['service'],
        'client.consultation.accepted' => ['name', 'time'],
        'client.consultation.declined' => ['name', 'time'],
        'client.consultation.cancelled' => ['name', 'time'],
        'client.consultation.reminder' => ['name', 'time'],
        'client.consultation.review_due' => ['name', 'hours'],
        'client.consultation.refunded' => ['name', 'amount'],
        'client.consultation.complaint_closed' => ['name'],
        'client.waitlist.turn' => ['service', 'hours'],
        // FL-L09: a special offer from a freelancer I worked with
        'client.special_offer.received' => ['name', 'title'],
        'client.special_offer.ending' => ['name', 'title'],
        // FL-L09 (part B): a client reached a step of a freelancer's loyalty program
        'client.loyalty.unlocked' => ['name', 'percent'],
        // FL-L13: ownership and licences — the rights of a part pass, and the leave to show a finished work in the portfolio
        'client.rights.granted' => ['project'],
        'client.rights.portfolio_requested' => ['project', 'name'],
        'client.rights.claim_closed' => ['title', 'outcome'],
        'freelancer.rights.portfolio_granted' => ['project'],
        'freelancer.rights.portfolio_revoked' => ['project'],
        // FL-L16: the consultation's video session
        'freelancer.consultation.session_joined' => ['name', 'time'],
        'freelancer.consultation.session_ending' => ['name', 'minutes'],
        'freelancer.consultation.session_closed' => ['name'],
        'freelancer.consultation.session_reopened' => ['name'],
        'freelancer.consultation.recording_ready' => ['name', 'date'],
        'client.consultation.session_joined' => ['name', 'time'],
        'client.consultation.session_ending' => ['name', 'minutes'],
        'client.consultation.session_closed' => ['name'],
        'client.consultation.session_reopened' => ['name'],
        'client.consultation.recording_ready' => ['name', 'date'],
        // FL-L12: the professional community
        'freelancer.community.commented' => ['name'],
        'freelancer.community.replied' => ['name'],
        'freelancer.community.followed' => ['name'],
        'freelancer.community.answer_accepted' => ['name'],
        'freelancer.community.hidden' => ['reason'],
        'freelancer.community.muted' => ['date', 'reason'],
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
        'client.change.reminder' => ['project'],
        'client.health.at_risk' => ['project'],
        'client.health.delayed' => ['project'],
        'client.health.blocked' => ['project'],
        // FS-104: the project team - an invitation, the answer to it, a changed role, leaving, and every step a delegate took.
        'client.team.invited' => ['project', 'name'],
        'client.team.joined' => ['project', 'name'],
        'client.team.declined' => ['project', 'name'],
        'client.team.left' => ['project', 'name'],
        'client.team.removed' => ['project'],
        'client.team.role_changed' => ['project'],
        'client.team.acted_fund' => ['project', 'name'],
        'client.team.acted_release' => ['project', 'name'],
        'client.team.acted_change_accept' => ['project', 'name'],
        'client.team.acted_change_reject' => ['project', 'name'],
        // FS-150a: the other side gave me a task in the Workroom.
        'client.workroom.task_assigned' => ['project', 'title'],
        'freelancer.workroom.task_assigned' => ['project', 'title'],
        // FS-150b: the other side shared a file (or a new version of it) / wrote a note on a file or a delivery.
        'client.workroom.file_shared' => ['project', 'title'],
        'freelancer.workroom.file_shared' => ['project', 'title'],
        'client.workroom.comment_added' => ['project', 'title'],
        'freelancer.workroom.comment_added' => ['project', 'title'],
        // FS-150c: a decision waits for my answer / the other side answered mine.
        'client.workroom.decision_proposed' => ['project', 'title'],
        'freelancer.workroom.decision_proposed' => ['project', 'title'],
        'client.workroom.decision_agreed' => ['project', 'title'],
        'freelancer.workroom.decision_agreed' => ['project', 'title'],
        'client.workroom.decision_declined' => ['project', 'title'],
        'freelancer.workroom.decision_declined' => ['project', 'title'],
        'client.talent.reminder' => ['name'],
        'client.dispute.appealed' => ['project'],
        'client.review.received' => ['project', 'name'],
        'client.review.published' => ['project', 'name'],
        'client.report.received' => ['project', 'name'],
        'client.report.late' => ['project', 'name'],
        'client.review.objection_decided' => ['project', 'outcome'],
        'client.review.moderated' => ['project', 'outcome'],
        // FL-708: warnings, limits and suspensions, for both sides
        'freelancer.sanction.issued' => ['type', 'reason'],
        'freelancer.sanction.lifted' => ['type'],
        'freelancer.sanction.appeal_decided' => ['type', 'outcome'],
        'client.sanction.issued' => ['type', 'reason'],
        'client.sanction.lifted' => ['type'],
        'client.sanction.appeal_decided' => ['type', 'outcome'],
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

        // FS-104: the owner's project team hears of the same step, each as far as his role sees (amounts only with `money`).
        $receivers = [$receiver];
        if ($audience === 'client' && is_string($data['project'] ?? null) && isset(\Modules\Freelance\Services\Team\ClientTeam::FAN_OUT[$event])) {
            $team = app(\Modules\Freelance\Services\Team\ClientTeam::class)->recipients($data['project'], (int) $receiver->getKey(), $event)
                ->reject(fn ($u) => (int) $u->getKey() === (int) $receiver->getKey());
            $receivers = array_merge($receivers, $team->all());
        }

        $this->notifications->send(
            count($receivers) === 1 ? $receiver : $receivers,
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
