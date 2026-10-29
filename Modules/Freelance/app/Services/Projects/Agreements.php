<?php

namespace Modules\Freelance\Services\Projects;

use Modules\Freelance\Services\Policy\FreelanceFees;
use Modules\Freelance\Models\FreelanceProjectApproval;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceOfferStatus as OfferStatus;
use Modules\Freelance\Enums\FreelanceProjectStatus as Status;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceConversation;
use Modules\Freelance\Models\FreelanceOffer as Offer;
use Modules\Freelance\Models\FreelanceOfferEvent;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Chat\OfferChat;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Offers\ClientOfferViews;
use Modules\Freelance\Services\Offers\FreelancerOffers;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;

/**
 * The agreement between the client and the chosen freelancer (FL-401). Made as a draft from the chosen offer the moment the client
 * chooses (the price and the days are the offer's; the policy — commission, fees, review days, free revisions — is copied in and stays
 * as it was). The client writes what the work covers and sends it, which is their approval; the freelancer approves or declines it.
 * Changing it after it was sent takes the approval back. When both approved it is active and frozen, the project is in progress and
 * the chat is no longer kept to Dorr-only details. Declined, cancelled or left unanswered (`expires_at`): the project takes offers again,
 * the offers turned down only because this one was chosen come back, and the client may choose again. Every step is in
 * `freelance_project_approvals` (FL-406), never deleted.
 */
class Agreements
{
    public const ANSWER_DAYS = 7;

    public function __construct(
        private readonly ProjectStateMachine $machine,
        private readonly ProjectOffersWindow $window,
        private readonly FreelancePolicies $policies,
        private readonly FreelanceNotifier $notifier,
        private readonly OfferChat $chat,
        private readonly Milestones $milestones,
    ) {}

    /** The draft, made from the chosen offer (inside the choosing transaction). */
    public function draft(Project $project, Offer $offer): Agreement
    {
        $policy = $this->policies->for($project->country_id, $project->freelance_specialty_id)->all();
        $cost = app(ClientOfferViews::class)->cost($project, (int) $offer->price_minor);
        $net = app(FreelancerOffers::class)->preview($project, (int) $offer->price_minor);

        $agreement = Agreement::query()->create([
            'project_id' => $project->id,
            'offer_id' => $offer->id,
            'client_id' => $project->client_id,
            'freelancer_id' => $offer->freelancer_id,
            'scope' => trim($project->description."\n\n".$offer->details),
            'price_minor' => $offer->price_minor,
            'currency_code' => $offer->currency_code,
            'duration_days' => $offer->duration_days,
            'policy' => $policy,
            'money' => [
                'price_minor' => $cost['price_minor'], 'client_fee_minor' => $cost['client_fee_minor'], 'client_total_minor' => $cost['total_minor'],
                'commission_minor' => $net['commission_minor'], 'net_minor' => $net['net_minor'],
                // FL-510: what the client was told before choosing — kept, so a split that changes it (a minimum fee per part) is shown.
                'quoted_total_minor' => $cost['total_minor'],
            ],
            'status' => Agreement::DRAFT,
        ]);
        // FL-402: one part for the whole price, until the client splits it.
        $this->milestones->single($agreement->setRelation('project', $project));

        return $agreement;
    }

    /**
     * The client splits the work into parts (FL-402) — the whole list each time. Like a change to the text, after sending it takes the
     * agreement back to a draft.
     *
     * @param  list<array{title: string, description?: ?string, amount_minor: int, duration_days?: ?int}>  $items
     */
    public function setMilestones(Agreement $agreement, User $client, array $items): Agreement
    {
        return DB::transaction(function () use ($agreement, $client, $items) {
            $a = $this->locked($agreement);
            $this->assertOpen($a);
            $this->milestones->replace($a, $items);
            $wasSent = $a->status === Agreement::PENDING;
            $a->fill(['status' => Agreement::DRAFT, 'client_approved_at' => null, 'sent_at' => null, 'expires_at' => null, 'version' => $a->version + 1])->save();
            $this->approval($a, $wasSent ? 'agreement.withdrawn' : 'agreement.milestones', 'client', $client->id, ['count' => count($items)]);

            return $a;
        });
    }

    /** The project's current agreement (the last one), or null. */
    public function current(Project $project): ?Agreement
    {
        return Agreement::query()->where('project_id', $project->id)->latest('id')->first();
    }

    /** @throws \Illuminate\Database\Eloquent\ModelNotFoundException */
    public function forFreelancer(Project $project, Freelancer $freelancer): Agreement
    {
        return Agreement::query()->where('project_id', $project->id)->where('freelancer_id', $freelancer->id)->latest('id')->firstOrFail();
    }

    /**
     * The client changes what the work covers. After it was sent the freelancer's look at it is undone: it goes back to a draft.
     *
     * @param  array{scope?: string, includes?: ?string, excludes?: ?string}  $data
     */
    public function update(Agreement $agreement, User $client, array $data): Agreement
    {
        return DB::transaction(function () use ($agreement, $client, $data) {
            $a = $this->locked($agreement);
            $this->assertOpen($a);
            foreach (['scope', 'includes', 'excludes'] as $key) {
                if (array_key_exists($key, $data)) {
                    $text = $data[$key] === null ? null : trim(strip_tags((string) $data[$key]));
                    $a->{$key} = $text === '' ? null : $text;
                }
            }
            if ($a->scope === null) {
                throw new FreelanceException('agreement_scope_required', 422);
            }
            // FL-605: how often the client asks for a progress report (none by default).
            if (array_key_exists('report_every_days', $data)) {
                // 0 (or null) = no reports: the apps leave a null out of the request, so they send 0 to turn it off.
                $days = (int) ($data['report_every_days'] ?? 0) === 0 ? null : (int) $data['report_every_days'];
                if ($days !== null && ! in_array($days, ProgressReports::EVERY_DAYS, true)) {
                    throw new FreelanceException('report_days_invalid', 422);
                }
                $a->report_every_days = $days;
            }
            $wasSent = $a->status === Agreement::PENDING;
            $a->fill(['status' => Agreement::DRAFT, 'client_approved_at' => null, 'sent_at' => null, 'expires_at' => null, 'version' => $a->version + 1])->save();
            if ($wasSent) {
                $this->approval($a, 'agreement.withdrawn', 'client', $client->id);
            }

            return $a;
        });
    }

    /** The client sends it — that is their approval — and the freelancer has a few days to answer. */
    public function send(Agreement $agreement, User $client): Agreement
    {
        $sent = DB::transaction(function () use ($agreement, $client) {
            $a = $this->locked($agreement);
            if ($a->status !== Agreement::DRAFT) {
                throw new FreelanceException('agreement_invalid_state', 409, ['status' => __('freelance.agreement_statuses.'.$a->status)], ['status' => $a->status]);
            }
            // FL-210: the price and the fees of its parts together must stay within the most the client said they'd pay.
            app(ClientProjects::class)->assertWithinCostCap($a->project, (int) $a->price_minor + (int) $this->milestones->of($a)->sum('client_fee_minor'));
            $a->fill(['status' => Agreement::PENDING, 'sent_at' => now(), 'client_approved_at' => now(), 'expires_at' => now()->addDays(self::ANSWER_DAYS)])->save();
            $this->approval($a, 'agreement.sent', 'client', $client->id);

            return $a;
        });
        $this->notifier->notify($sent->freelancer, 'agreement.received', ['project' => (string) $sent->project->title], ['project' => $sent->project->uuid]);

        return $sent;
    }

    /** The freelancer approves: both did, so it is active and frozen, and the work starts. */
    public function approve(Agreement $agreement, Freelancer $freelancer): Agreement
    {
        $active = DB::transaction(function () use ($agreement, $freelancer) {
            $a = $this->locked($agreement);
            $this->assertPending($a);
            $parts = $this->milestones->of($a);
            $fee = (int) $parts->sum('client_fee_minor');
            $a->fill([
                'status' => Agreement::ACTIVE, 'freelancer_approved_at' => now(), 'activated_at' => now(), 'expires_at' => null,
                // The parts are paid one by one: the totals are theirs (a fee rounded per part can differ by a cent from one on the whole).
                'money' => ['price_minor' => $a->price_minor, 'client_fee_minor' => $fee, 'client_total_minor' => $a->price_minor + $fee,
                    'commission_minor' => (int) $parts->sum('commission_minor'), 'net_minor' => (int) $parts->sum('net_minor'),
                    'quoted_total_minor' => ((array) $a->money)['quoted_total_minor'] ?? null],
            ])->save();
            $this->approval($a, 'agreement.approved', 'freelancer', $freelancer->id);
            app(ProgressReports::class)->start($a);
            $this->machine->move($a->project, Status::InProgress, 'project.started', 'freelancer', $freelancer->id, [], ['agreement' => $a->uuid]);

            return $a;
        });
        $this->notifier->notify($active->client, 'agreement.active', ['project' => (string) $active->project->title, 'name' => (string) $freelancer->name], ['project' => $active->project->uuid]);

        return $active;
    }

    /** The freelancer declines: the offer is taken back and the client chooses again. */
    public function decline(Agreement $agreement, Freelancer $freelancer, ?string $reason): Agreement
    {
        $reason = $reason === null ? null : (trim(strip_tags($reason)) ?: null);
        $ended = DB::transaction(function () use ($agreement, $freelancer, $reason) {
            $a = $this->locked($agreement);
            $this->assertPending($a);
            $this->end($a, Agreement::DECLINED, $reason, 'freelancer', $freelancer->id, OfferStatus::Withdrawn, 'agreement_declined');

            return $a;
        });
        $this->notifier->notify($ended->client, 'agreement.declined', ['project' => (string) $ended->project->title, 'name' => (string) $freelancer->name], ['project' => $ended->project->uuid]);

        return $ended;
    }

    /** The client gives up on this freelancer before the agreement started; the freelancer is told. */
    public function cancel(Agreement $agreement, User $client): Agreement
    {
        $ended = DB::transaction(function () use ($agreement, $client) {
            $a = $this->locked($agreement);
            $this->assertOpen($a);
            $this->end($a, Agreement::CANCELLED, null, 'client', $client->id, OfferStatus::Rejected, 'agreement_cancelled');

            return $a;
        });
        $this->notifier->notify($ended->freelancer, 'agreement.cancelled', ['project' => (string) $ended->project->title], ['project' => $ended->project->uuid]);

        return $ended;
    }

    /** The project itself was cancelled while it was awarded: the agreement ends with it and the chosen freelancer is told. */
    public function projectCancelled(Project $project): void
    {
        $a = $this->current($project);
        if ($a === null || ! in_array($a->status, [Agreement::DRAFT, Agreement::PENDING, Agreement::ACTIVE], true)) {
            return;
        }
        $a->fill(['status' => Agreement::CANCELLED, 'expires_at' => null, 'end_reason' => 'project_cancelled'])->save();
        $this->approval($a, 'agreement.cancelled', 'client', $project->client_id, ['reason' => 'project_cancelled']);
        $this->notifier->notify($a->freelancer, 'project.cancelled', ['project' => (string) $project->title], ['project' => $project->uuid]);
    }

    /** Hourly: an agreement the freelancer left unanswered ends, both are told and the client chooses again. */
    public function expireDue(): int
    {
        $count = 0;
        Agreement::query()->where('status', Agreement::PENDING)->where('expires_at', '<=', now())->each(function (Agreement $agreement) use (&$count): void {
            $ended = DB::transaction(function () use ($agreement) {
                $a = $this->locked($agreement);
                if ($a->status !== Agreement::PENDING) {
                    return null;
                }
                $this->end($a, Agreement::EXPIRED, null, 'system', null, OfferStatus::Rejected, 'agreement_expired');

                return $a;
            });
            if ($ended !== null) {
                $this->notifier->notify($ended->client, 'agreement.expired', ['project' => (string) $ended->project->title], ['project' => $ended->project->uuid]);
                $this->notifier->notify($ended->freelancer, 'agreement.expired', ['project' => (string) $ended->project->title], ['project' => $ended->project->uuid]);
                $count++;
            }
        });

        return $count;
    }

    /** @return array<string, mixed> `$side` = client or freelancer: each sees the money from their side */
    public function present(Agreement $a, string $side): array
    {
        $money = (array) $a->money;
        $policy = (array) $a->policy;
        // FL-510: until it is active the money is the sum of the parts as split now; after, it is what was agreed (and changed by accepted changes).
        if (in_array($a->status, [Agreement::DRAFT, Agreement::PENDING], true) && $this->milestones->of($a)->isNotEmpty()) {
            $money = $this->milestones->totals($a) + $money;
        }
        $mine = $side === 'client'
            ? ['price_minor' => $money['price_minor'] ?? $a->price_minor, 'client_fee_minor' => $money['client_fee_minor'] ?? 0, 'total_minor' => $money['client_total_minor'] ?? $a->price_minor,
                'quoted_total_minor' => $money['quoted_total_minor'] ?? null]
            : ['price_minor' => $money['price_minor'] ?? $a->price_minor, 'commission_minor' => $money['commission_minor'] ?? 0, 'net_minor' => $money['net_minor'] ?? $a->price_minor];

        return [
            'uuid' => $a->uuid,
            'status' => $a->status,
            'version' => $a->version,
            'scope' => $a->scope,
            'includes' => $a->includes,
            'excludes' => $a->excludes,
            'price_minor' => $a->price_minor,
            'currency' => $a->currency_code,
            'duration_days' => $a->duration_days,
            // FL-605: the optional progress reports (how often, when the next is due, whether it is late).
            'reports' => app(ProgressReports::class)->summary($a),
            'money' => $mine + ['lines' => FreelanceFees::lines($side, $mine)],
            // What the work runs on, as it was when the agreement was made.
            'terms' => ['review_days' => $policy['review.days'] ?? null, 'free_revisions' => $policy['review.free_revisions'] ?? null, 'auto_approve' => $policy['review.auto_approve'] ?? null],
            'sent_at' => $a->sent_at?->toIso8601String(),
            'client_approved_at' => $a->client_approved_at?->toIso8601String(),
            'freelancer_approved_at' => $a->freelancer_approved_at?->toIso8601String(),
            'activated_at' => $a->activated_at?->toIso8601String(),
            'completed_at' => $a->completed_at?->toIso8601String(),
            'expires_at' => $a->expires_at?->toIso8601String(),
            'end_reason' => $a->end_reason,
            'freelancer' => $side === 'client' ? ['uuid' => $a->freelancer?->uuid, 'name' => $a->freelancer?->name] : null,
            // FL-402: the parts it is paid in, with the limits the client splits it within.
            'milestones' => $this->milestones->of($a)->map(fn ($m) => Milestones::present($m, $side, max(0, (int) ($policy['review.free_revisions'] ?? 0))) + [
                // an object even when empty: the apps read it as a map, and an empty PHP array would go out as a JSON list.
                'can' => $a->status === Agreement::ACTIVE ? app(MilestoneWork::class)->can($m, $side) : new \stdClass,
                // FL-501: what is held for it in the client's wallet.
                'held_minor' => app(MilestonePayments::class)->held($m),
                // FL-504: when an unanswered delivery approves itself.
                'auto_approve_at' => app(MilestoneRelease::class)->dueAt($m->setRelation('agreement', $a))?->toIso8601String(),
            ])->values()->all(),
            // FL-701: rating each other once it is completed.
            'review' => $a->status === Agreement::COMPLETED ? app(FreelanceReviews::class)->forAgreement($a, $side) : null,
            // FL-404 / FL-405: the changes asked, newest first.
            'changes' => $a->status === Agreement::ACTIVE || $a->status === Agreement::COMPLETED ? app(ChangeRequests::class)->list($a, $side) : [],
            // FL-501: what the client can spend now, for the funding sheet.
            'wallet_available_minor' => $side === 'client' && $a->status === Agreement::ACTIVE ? app(MilestonePayments::class)->availableFor($a->client, (int) $a->project->country_id) : null,
            'milestone_rules' => ['max_count' => (int) ($policy['milestones.max_count'] ?? 5), 'min_amount_minor' => isset($policy['milestones.min_amount_minor']) ? (int) $policy['milestones.min_amount_minor'] : null],
            'can' => $side === 'client'
                ? ['edit' => $a->open(), 'send' => $a->status === Agreement::DRAFT, 'cancel' => $a->open(), 'split' => $a->open()]
                : ['approve' => $a->status === Agreement::PENDING, 'decline' => $a->status === Agreement::PENDING, 'report' => $a->status === Agreement::ACTIVE],
        ];
    }

    // ------------------------------------------------------------------ inside

    private function locked(Agreement $agreement): Agreement
    {
        return Agreement::query()->whereKey($agreement->id)->lockForUpdate()->with(['project', 'offer', 'client', 'freelancer'])->firstOrFail();
    }

    private function assertOpen(Agreement $a): void
    {
        if (! $a->open()) {
            throw new FreelanceException('agreement_invalid_state', 409, ['status' => __('freelance.agreement_statuses.'.$a->status)], ['status' => $a->status]);
        }
    }

    private function assertPending(Agreement $a): void
    {
        if ($a->status !== Agreement::PENDING) {
            throw new FreelanceException('agreement_invalid_state', 409, ['status' => __('freelance.agreement_statuses.'.$a->status)], ['status' => $a->status]);
        }
    }

    /** Ends an open agreement: its offer leaves, the project takes offers again, and the offers turned down only for this one come back. */
    private function end(Agreement $a, string $status, ?string $reason, string $actorType, ?int $actorId, OfferStatus $offerTo, string $why): void
    {
        $a->fill(['status' => $status, 'expires_at' => null, 'end_reason' => $reason])->save();
        $this->approval($a, 'agreement.'.$status, $actorType, $actorId, $reason === null ? [] : ['reason' => $reason]);

        $offer = $a->offer;
        $from = $offer->status;
        $offer->forceFill(['status' => $offerTo, 'status_reason' => null] + ($offerTo === OfferStatus::Withdrawn ? ['withdrawn_at' => now()] : []))->save();
        FreelanceOfferEvent::query()->create([
            'offer_id' => $offer->id, 'type' => $offerTo === OfferStatus::Withdrawn ? 'offer.withdrawn' : 'offer.rejected', 'from_status' => $from->value, 'to_status' => $offerTo->value,
            'actor_type' => $actorType, 'actor_id' => $actorId, 'payload' => ['auto' => $why],
        ]);
        $this->chat->freeze($a->project, $offer->freelancer_id);

        $project = $a->project;
        $this->machine->move($project, Status::Published, 'project.reopened', $actorType, $actorId, [
            'awarded_at' => null, 'offers_closed_at' => null, 'offers_close_reason' => null, 'offers_deadline_at' => $this->window->deadlineFor($project),
        ], ['agreement' => $a->uuid, 'reason' => $why]);

        // The offers turned down only because this one was chosen are back to «seen», and their rooms open again.
        Offer::query()->where('project_id', $project->id)->where('status', OfferStatus::Rejected->value)->whereKeyNot($offer->id)->get()
            ->filter(fn (Offer $o) => ($o->events()->where('type', 'offer.rejected')->latest('id')->first()?->payload['auto'] ?? null) === 'another_chosen')
            ->each(function (Offer $o) use ($actorType, $actorId, $project): void {
                $o->forceFill(['status' => OfferStatus::Viewed, 'status_reason' => null])->save();
                FreelanceOfferEvent::query()->create(['offer_id' => $o->id, 'type' => 'offer.restored', 'from_status' => 'rejected', 'to_status' => 'viewed', 'actor_type' => $actorType, 'actor_id' => $actorId]);
                FreelanceConversation::query()->where('context_type', OfferChat::CONTEXT)->where('context_id', $project->id)->where('freelancer_id', $o->freelancer_id)
                    ->where('status', FreelanceConversation::READ_ONLY)->update(['status' => FreelanceConversation::OPEN]);
            });
    }

    /** @param  array<string, mixed>  $payload */
    private function approval(Agreement $a, string $action, string $actorType, ?int $actorId, array $payload = []): void
    {
        FreelanceProjectApproval::forAgreement($a, $action, $actorType, $actorId, $payload);
    }
}
