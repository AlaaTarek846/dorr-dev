<?php

namespace Modules\Freelance\Services\Offers;

use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceOfferStatus as OfferStatus;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceOffer as Offer;
use Modules\Freelance\Models\FreelanceOfferEvent;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Services\Chat\OfferChat;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Projects\ProjectOffersWindow;
use Modules\Freelance\Services\Projects\ProjectStateMachine;
use Modules\User\Models\User;

/**
 * What a client does with the offers on their project (FL-303): opening them marks them seen (once, and the freelancer is told),
 * and putting one on the shortlist, turning it down (with an optional reason the freelancer can read) or choosing it. Every move is
 * checked against the offer's status, written as an event and told to the freelancer live and by push. Choosing an offer here only
 * is FL-309: it turns the others down and awards the project.
 */
class ClientOffers
{
    public function __construct(
        private readonly FreelanceNotifier $notifier,
        private readonly ProjectOffersWindow $window,
        private readonly ProjectStateMachine $machine,
        private readonly OfferChat $chat,
    ) {}

    /** Marks every offer still `submitted` as seen by the client — the first time only. */
    public function markSeen(Project $project, User $client): int
    {
        $seen = 0;
        Offer::query()->where('project_id', $project->id)->where('status', OfferStatus::Submitted->value)->with('freelancer')->each(function (Offer $offer) use ($client, &$seen): void {
            $this->move($offer, OfferStatus::Viewed, 'offer.viewed', $client, ['viewed_at' => now()]);
            $seen++;
        });

        return $seen;
    }

    public function find(Project $project, string $uuid): Offer
    {
        return Offer::query()->where('project_id', $project->id)->where('uuid', $uuid)->with(['freelancer', 'media', 'project'])->firstOrFail();
    }

    public function shortlist(Offer $offer, User $client): Offer
    {
        return $this->move($offer, OfferStatus::Shortlisted, 'offer.shortlisted', $client);
    }

    /** Takes it off the shortlist (back to «seen»). Quiet: the freelancer is not told. */
    public function unshortlist(Offer $offer, User $client): Offer
    {
        if ($offer->status !== OfferStatus::Shortlisted) {
            throw new FreelanceException('offer_invalid_state', 409, ['status' => __('freelance.offer_statuses.'.$offer->status->value)], ['status' => $offer->status->value]);
        }

        return DB::transaction(fn () => $this->moveNow($offer, OfferStatus::Viewed, 'offer.unshortlisted', $client))->refresh();
    }

    public function reject(Offer $offer, User $client, ?string $reason): Offer
    {
        $reason = $reason === null ? null : (trim(strip_tags($reason)) ?: null);

        $moved = $this->move($offer, OfferStatus::Rejected, 'offer.rejected', $client, ['status_reason' => $reason], $reason === null ? [] : ['reason' => $reason]);
        $this->chat->freeze($offer->project, $offer->freelancer_id);

        return $moved;
    }

    /**
     * Chooses the offer (FL-309), all in one transaction with the project locked: the offer is accepted, every other live offer is
     * turned down («another offer was chosen»), the project stops taking offers and becomes `awarded`. One freelancer per project; a
     * second choice, or a project that isn't published, is refused. The agreement and the payment come next (FL-401 / FL-501).
     */
    public function choose(Offer $offer, User $client): Offer
    {
        app(\Modules\Freelance\Services\Moderation\Sanctions::class)->assertAllowed($client, 'work');
        $others = collect();
        $chosen = DB::transaction(function () use ($offer, $client, &$others) {
            /** @var Project $project */
            $project = Project::query()->whereKey($offer->project_id)->lockForUpdate()->firstOrFail();
            if ($project->status !== FreelanceProjectStatus::Published) {
                throw $project->status === FreelanceProjectStatus::Awarded
                    ? new FreelanceException('offer_already_chosen', 409)
                    : new FreelanceException('project_invalid_state', 409, ['status' => __('freelance.project_statuses.'.$project->status->value)], ['status' => $project->status->value]);
            }
            if (Offer::query()->where('project_id', $project->id)->where('status', OfferStatus::Accepted->value)->exists()) {
                throw new FreelanceException('offer_already_chosen', 409);
            }
            // FL-210: dearer than the most the client said they'd pay — refused (they raise or lift the limit first).
            app(ClientProjects::class)->assertWithinCostCap($project, app(ClientOfferViews::class)->cost($project, (int) $offer->price_minor)['total_minor']);

            $chosen = $this->moveNow($offer, OfferStatus::Accepted, 'offer.accepted', $client, ['accepted_at' => now()]);
            $others = Offer::query()->where('project_id', $project->id)->whereKeyNot($chosen->id)
                ->whereIn('status', [OfferStatus::Submitted->value, OfferStatus::Viewed->value, OfferStatus::Shortlisted->value])->with('freelancer')->lockForUpdate()->get()
                ->map(fn (Offer $o) => $this->moveNow($o, OfferStatus::Rejected, 'offer.rejected', $client, ['status_reason' => null], ['auto' => 'another_chosen'], true));

            if ($project->offers_closed_at === null) {
                $this->window->closeBecauseChosen($project, $client->id);
            }
            $this->machine->move($project->refresh(), FreelanceProjectStatus::Awarded, 'project.awarded', 'client', $client->id, ['awarded_at' => now()], ['offer' => $chosen->uuid]);
            // FL-401: the agreement starts as a draft from the chosen offer.
            app(\Modules\Freelance\Services\Projects\Agreements::class)->draft($project->refresh(), $chosen);

            return $chosen;
        });

        // Told after the commit: the chosen one, then everyone else.
        $this->tell($chosen, 'offer.accepted');
        $others->each(function (Offer $o): void {
            $this->chat->freeze($o->project, $o->freelancer_id);
            $this->tell($o, 'offer.rejected');
        });

        return $chosen->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes  other columns set with the move
     * @param  array<string, mixed>  $payload  what goes in the event
     */
    private function move(Offer $offer, OfferStatus $to, string $event, User $client, array $attributes = [], array $payload = []): Offer
    {
        $moved = DB::transaction(fn () => $this->moveNow($offer, $to, $event, $client, $attributes, $payload));
        $this->tell($moved, $event);

        return $moved->refresh();
    }

    /**
     * The move itself, in the caller's transaction. `$quiet`: an offer turned down because another was chosen doesn't count as
     * the client having looked at it.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $payload
     */
    private function moveNow(Offer $offer, OfferStatus $to, string $event, User $client, array $attributes = [], array $payload = [], bool $quiet = false): Offer
    {
        /** @var Offer $locked */
        $locked = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
        $from = $locked->status;
        if (! $from->canMoveTo($to) && ! ($quiet && $from === OfferStatus::Submitted && $to === OfferStatus::Rejected)) {
            throw new FreelanceException('offer_invalid_state', 409, ['status' => __('freelance.offer_statuses.'.$from->value)], ['status' => $from->value]);
        }
        $locked->fill($attributes);
        // The first time the client looks at it, whatever they decide first.
        if ($locked->viewed_at === null && ! $quiet) {
            $locked->viewed_at = now();
        }
        $locked->status = $to;
        $locked->save();
        FreelanceOfferEvent::query()->create([
            'offer_id' => $locked->id, 'type' => $event, 'from_status' => $from->value, 'to_status' => $to->value,
            'actor_type' => 'client', 'actor_id' => $client->id, 'payload' => $payload === [] ? null : $payload,
        ]);

        return $locked;
    }

    /** Tells the offer's freelancer, live and by push. */
    private function tell(Offer $offer, string $event): void
    {
        $offer->loadMissing(['freelancer', 'project']);
        if ($offer->freelancer !== null) {
            $this->notifier->notify($offer->freelancer, $event, ['project' => (string) $offer->project->title], ['project' => $offer->project->uuid, 'offer' => $offer->uuid]);
        }
    }
}
