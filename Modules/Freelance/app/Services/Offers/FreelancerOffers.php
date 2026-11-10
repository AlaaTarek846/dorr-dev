<?php

namespace Modules\Freelance\Services\Offers;

use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceOfferStatus as OfferStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceOffer as Offer;
use Modules\Freelance\Models\FreelanceOfferEvent;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelanceTeam;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\FreelancerTeam\TeamOffers;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Policy\FreelanceFees;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Plans\FreelanceOfferCredits;
use Modules\Freelance\Services\Plans\FreelancePlans;
use Modules\Freelance\Services\Projects\ProjectOffersWindow;
use Modules\Freelance\Services\Rights\Rights;
use Modules\Freelance\Services\Verification\FreelancerGate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Sending an offer (FL-301). One rule decides whether a freelancer may offer on a project, checked in this order so the app can say
 * the most useful thing first: a verified account that is taking work (FL-110 / FL-106) → the project still taking offers (FL-204)
 * → not offered there already → the project not full (`offers.max_per_project`) → the monthly free quota not used up
 * (`offers.free_monthly_quota`, when the `free_offer_quota` switch is on) and no bought extra offers left (FL-L01 phase 2). The price is free, but never under the project budget's
 * minimum of the country / specialty. The commission and the net are worked out from the policy now and kept with the offer.
 *
 * FS-205: the same offer can be made in a freelancers' team's name (`team` + `staff`, by its Owner - see `TeamOffers`): it counts against the
 * team's monthly quota instead of the Owner's own free / bought offers, and it still holds the Owner's one offer per project.
 */
class FreelancerOffers
{
    public function __construct(
        private readonly FreelancerGate $gate,
        private readonly ProjectOffersWindow $window,
        private readonly FreelancePolicies $policies,
        private readonly FreelanceFeatures $features,
        private readonly FreelanceNotifier $notifier,
    ) {}

    /**
     * @param  FreelanceTeam|null  $team  FS-205: an offer in a team's name is counted against the team's quota, not the freelancer's own
     *
     * @throws FreelanceException the first reason this freelancer can't offer on this project
     */
    public function assertCanSend(Freelancer $freelancer, Project $project, ?FreelanceTeam $team = null): void
    {
        $this->gate->assertCanOffer($freelancer);
        $this->window->assertAccepting($project);
        // FL-205: a private project takes offers from the freelancers invited to it only.
        if ($project->visibility === 'private' && ! app(\Modules\Freelance\Services\Projects\ProjectInvitations::class)->hasLive($freelancer, $project)) {
            throw new FreelanceException('project_invite_only', 403);
        }
        if ($this->mine($freelancer, $project) !== null) {
            throw new FreelanceException('offer_already_sent', 409);
        }
        // FS-104: someone on the client's team can not bid on the same project (the same phone behind both accounts).
        if (app(\Modules\Freelance\Services\Team\ClientTeam::class)->sitsOnTeam($project, (string) $freelancer->phone)) {
            throw new FreelanceException('offer_team_member', 403);
        }
        $max = (int) $this->policies->for($project->country_id, $project->freelance_specialty_id)->get('offers.max_per_project');
        if ($max > 0 && $this->liveCount($project) >= $max) {
            throw new FreelanceException('project_offers_full', 422, ['max' => $max], ['max' => $max]);
        }
        if ($team !== null) {
            app(TeamOffers::class)->assertQuota($team);

            return;
        }
        $quota = $this->quota($freelancer);
        if ($quota['limit'] !== null && $quota['left'] <= 0 && $quota['credits'] <= 0) {
            throw new FreelanceException('offer_quota_exhausted', 422, ['limit' => $quota['limit']], ['limit' => $quota['limit'], 'packs' => $quota['packs']]);
        }
    }

    /** @return array{code: string, message: string}|null why the freelancer can't offer (null: they can) — for the app to say before the form */
    public function blocked(Freelancer $freelancer, Project $project, ?FreelanceTeam $team = null): ?array
    {
        try {
            $this->assertCanSend($freelancer, $project, $team);

            return null;
        } catch (FreelanceException $e) {
            return ['code' => $e->apiErrorCode(), 'message' => $e->apiMessage()];
        }
    }

    /** @return array{enabled: bool, limit: ?int, used: int, left: ?int, plan: string, credits: int, packs: bool} the free offers of this calendar month, the bought ones left, and whether more can be bought */
    public function quota(Freelancer $freelancer): array
    {
        $enabled = $this->features->allows((int) $freelancer->country_id, 'free_offer_quota');
        // An offer taken back before the client saw it gives its place back (FL-302).
        $used = Offer::query()->where('freelancer_id', $freelancer->id)->where('free', true)->where('created_at', '>=', now()->startOfMonth())
            ->where(fn ($q) => $q->where('status', '!=', OfferStatus::Withdrawn->value)->orWhereNotNull('viewed_at'))->count();
        // FL-L01: a plan raises the number, or lifts the limit (null).
        $plans = app(FreelancePlans::class);
        $limit = $enabled ? $plans->offersLimit($freelancer, (int) $this->policies->for((int) $freelancer->country_id)->get('offers.free_monthly_quota')) : null;

        // FL-L01 phase 2: offers bought in a pack are spent once the free ones are used up; a plan with no limit never needs them.
        $credits = $limit === null ? 0 : app(FreelanceOfferCredits::class)->available($freelancer);
        $packs = $limit !== null && $this->features->allows((int) $freelancer->country_id, 'offer_packs');

        return [
            'enabled' => $enabled, 'limit' => $limit, 'used' => $used, 'left' => $limit === null ? null : max(0, $limit - $used), 'plan' => $plans->planFor($freelancer)->slug,
            'credits' => $credits, 'packs' => $packs,
        ];
    }

    /** @return array{price_minor: int, commission_minor: int, net_minor: int, currency: string, commission: array<string, mixed>} what an offer at this price leaves the freelancer */
    public function preview(Project $project, int $priceMinor, ?Freelancer $freelancer = null): array
    {
        $values = $this->policies->for($project->country_id, $project->freelance_specialty_id)->all();
        // Where the commission switch is off, the platform keeps nothing.
        if (! $this->features->allows($project->country_id, 'commission')) {
            $values['commission.type'] = 'fixed';
            $values['commission.value'] = 0;
            $values['commission.min_minor'] = null;
        }
        // FL-L01: the freelancer's plan can lower the commission (never raise it).
        $values = app(FreelancePlans::class)->withCommission($values, $freelancer);
        $breakdown = FreelanceFees::breakdown($priceMinor, $values);
        $commission = array_intersect_key($values, array_flip(['commission.type', 'commission.value', 'commission.min_minor', 'commission.max_minor']));

        return [
            'price_minor' => $breakdown['amount_minor'],
            'commission_minor' => $breakdown['commission_minor'],
            'net_minor' => $breakdown['freelancer_net_minor'],
            'currency' => $project->currency_code,
            'commission' => $commission,
            // FL-510: the same as price lines.
            'lines' => FreelanceFees::lines('freelancer', ['price_minor' => $breakdown['amount_minor'], 'commission_minor' => $breakdown['commission_minor'], 'net_minor' => $breakdown['freelancer_net_minor']]),
        ];
    }

    /**
     * @param  array{price_minor: int, duration_days: int, details: string, team?: ?string, staff?: list<array<string, mixed>>}  $data  `team` (a team's uuid) + `staff`: FS-205, an offer in the team's name (the freelancer is its Owner)
     * @param  list<UploadedFile>  $files
     */
    public function send(Freelancer $freelancer, Project $project, array $data, array $files = []): Offer
    {
        // No way to reach each other outside Dorr before the agreement (FL-304 / FL-308).
        app(OfferChecks::class)->assertNoContact($data['details'] ?? null);
        $answers = $this->answers($project, $data['answers'] ?? null);
        $min = $this->policies->for($project->country_id, $project->freelance_specialty_id)->get('project.min_budget_minor');
        if ($min !== null && $data['price_minor'] < (int) $min) {
            throw new FreelanceException('offer_below_min', 422, ['min' => number_format($min / 100, 2).' '.$project->currency_code], ['min_minor' => (int) $min]);
        }
        // FS-205: an offer in a team's name - the team and its people are checked before anything is written.
        // FS-206c: a PM sends it for the Owner who signs - from here on the offer is the signer's (his limits, his one offer per project).
        $by = $freelancer;
        [$team, $staff, $signer] = empty($data['team']) ? [null, [], null] : app(TeamOffers::class)->prepare($freelancer, (string) $data['team'], (array) ($data['staff'] ?? []), $data['signer'] ?? null);
        $freelancer = $signer ?? $freelancer;
        $submittedBy = $by->id !== $freelancer->id ? $by : null;

        $offer = DB::transaction(function () use ($freelancer, $by, $submittedBy, $project, $data, $files, $answers, $team, $staff) {
            // One at a time per project, so its limit and the one-offer rule hold when two arrive together.
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            // And one at a time per freelancer, so the last free or bought offer can't be spent twice by two projects together.
            Freelancer::query()->whereKey($freelancer->id)->lockForUpdate()->first();
            // A team's offers are counted one at a time too.
            if ($team !== null) {
                FreelanceTeam::query()->whereKey($team->id)->lockForUpdate()->first();
            }
            $this->assertCanSend($freelancer, $locked, $team);

            // FL-L09: a client who earned a loyalty step with this freelancer gets it off the price asked.
            $deal = app(Loyalty::class)->apply($freelancer, (int) $locked->client_id, (int) $data['price_minor']);
            $preview = $this->preview($locked, $deal['price_minor'], $freelancer);
            // Once the month's free offers are used up the offer is paid for by the bought batch that ends first. A team's offer uses
            // the team's quota only - never the Owner's own free offers or bought ones.
            $quota = $this->quota($freelancer);
            $credit = $team === null && $quota['enabled'] && $quota['limit'] !== null && $quota['left'] <= 0 ? app(FreelanceOfferCredits::class)->pick($freelancer) : null;
            $offer = Offer::query()->create([
                'project_id' => $locked->id,
                'freelancer_id' => $freelancer->id,
                'team_id' => $team?->id,
                'submitted_by_freelancer_id' => $submittedBy?->id,
                'price_minor' => $preview['price_minor'],
                'list_price_minor' => $deal['list_price_minor'],
                'loyalty_bp' => $deal['loyalty_bp'],
                'currency_code' => $locked->currency_code,
                'duration_days' => (int) $data['duration_days'],
                'details' => trim(strip_tags((string) $data['details'])),
                'answers' => $answers,
                // FL-L13: who owns the work once it is paid and what the client may do with it (null where the switch is off).
                'rights' => app(Rights::class)->forListing((int) $locked->country_id, $data['rights'] ?? null),
                'status' => OfferStatus::Submitted,
                'commission_minor' => $preview['commission_minor'],
                'net_minor' => $preview['net_minor'],
                'fees' => $preview['commission'],
                'free' => $team === null && $quota['enabled'] && $credit === null,
                'credit_id' => $credit?->id,
            ]);
            foreach ($files as $file) {
                $offer->addAttachment($file);
            }
            $this->record($offer, 'offer.submitted', 'freelancer', $freelancer->id, null, OfferStatus::Submitted, $team === null ? [] : ['team' => $team->uuid], $submittedBy?->id);
            if ($team !== null) {
                app(TeamOffers::class)->save($offer, $team, $staff, $by);
            }
            app(\Modules\Freelance\Services\Projects\ProjectInvitations::class)->acceptedByOffer($freelancer, $locked);

            // The offer that fills the project closes it to more.
            $max = (int) $this->policies->for($locked->country_id, $locked->freelance_specialty_id)->get('offers.max_per_project');
            if ($max > 0 && $this->liveCount($locked) >= $max) {
                $this->window->closeBecauseFull($locked);
            }

            return $offer;
        });

        if ($submittedBy !== null && $team !== null) {
            app(TeamOffers::class)->toldSigner($offer->setRelation('project', $project), $team, $submittedBy, $freelancer);
        }
        if ($project->client !== null) {
            $this->notifier->notify($project->client, 'offer.received', ['project' => (string) $project->title, 'name' => (string) $freelancer->name], ['project' => $project->uuid, 'offer' => $offer->uuid]);
        }

        return $offer->load('media');
    }

    /** One of the freelancer's own offers by its uuid (any project they can see), else not found. */
    public function find(Freelancer $freelancer, string $uuid): Offer
    {
        // FS-206c: the PM who sent a team offer follows it too.
        return Offer::query()->where(fn ($q) => $q->where('freelancer_id', $freelancer->id)->orWhere('submitted_by_freelancer_id', $freelancer->id))->where('uuid', $uuid)->with(['project', 'media'])->firstOrFail();
    }

    /**
     * Changes an offer that is still open (FL-302): the price, the days, the details. What it said before is kept as a revision,
     * the commission and net are worked out again for the new price, and the client is told. Not after the client accepted or turned
     * it down, and not on a project that is no longer published.
     *
     * @param  array{price_minor?: int, duration_days?: int, details?: string}  $data  only what is sent changes
     */
    public function edit(Freelancer $freelancer, Offer $offer, array $data): Offer
    {
        app(OfferChecks::class)->assertNoContact($data['details'] ?? null);
        $newAnswers = array_key_exists('answers', $data) ? $this->answers($offer->project, $data['answers']) : null;
        [$freelancer, $by] = [$this->signerOf($offer, $freelancer), $freelancer]; // FS-206c: a PM changes the Owner's offer
        $updated = DB::transaction(function () use ($freelancer, $by, $offer, $data, $newAnswers) {
            /** @var Offer $locked */
            $locked = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $this->assertChangeable($locked);
            $project = $locked->project;

            // FL-L09: the price asked is the one before the loyalty discount (the offer keeps both).
            $listed = (int) ($locked->list_price_minor ?? $locked->price_minor);
            $price = (int) ($data['price_minor'] ?? $listed);
            $min = $this->policies->for($project->country_id, $project->freelance_specialty_id)->get('project.min_budget_minor');
            if ($min !== null && $price < (int) $min) {
                throw new FreelanceException('offer_below_min', 422, ['min' => number_format($min / 100, 2).' '.$project->currency_code], ['min_minor' => (int) $min]);
            }
            $details = array_key_exists('details', $data) ? trim(strip_tags((string) $data['details'])) : $locked->details;
            $days = (int) ($data['duration_days'] ?? $locked->duration_days);
            $answersChanged = $newAnswers !== null && $newAnswers !== $locked->answers;
            // FL-L13: the terms are part of the offer, so they can be changed while it is open (no change when the switch is off).
            $rights = array_key_exists('rights', $data) ? app(Rights::class)->forListing((int) $project->country_id, is_array($data['rights']) ? $data['rights'] : null) : $locked->rights;
            // FS-205: the people of a team's offer can be changed while it is open (the Owner who sent it, the team still active).
            $team = null;
            $rows = [];
            $staffChanged = false;
            if (array_key_exists('staff', $data)) {
                if ($locked->team_id === null) {
                    throw new FreelanceException('team_offer_staff_invalid', 422);
                }
                [$team, $rows] = app(TeamOffers::class)->prepare($by, (string) $locked->team->uuid, (array) $data['staff'], null, $freelancer);
                $staffChanged = ! app(TeamOffers::class)->same($locked, $rows);
            }
            if ($price === $listed && $days === $locked->duration_days && $details === $locked->details && ! $answersChanged && $rights === $locked->rights && ! $staffChanged) {
                throw new FreelanceException('offer_unchanged', 422);
            }

            // What it said until now, once (the first revision is the offer as it was sent).
            $number = (int) $locked->revisions()->max('number') + 1;
            $locked->revisions()->create([
                'number' => $number, 'price_minor' => $locked->price_minor, 'duration_days' => $locked->duration_days, 'details' => $locked->details,
                'commission_minor' => $locked->commission_minor, 'net_minor' => $locked->net_minor,
            ]);

            $deal = app(Loyalty::class)->apply($freelancer, (int) $project->client_id, $price);
            $preview = $this->preview($project, $deal['price_minor'], $freelancer);
            $locked->update([
                'price_minor' => $preview['price_minor'], 'list_price_minor' => $deal['list_price_minor'], 'loyalty_bp' => $deal['loyalty_bp'],
                'duration_days' => $days, 'details' => $details, 'rights' => $rights,
            ] + ($newAnswers !== null ? ['answers' => $newAnswers] : []) + [
                'commission_minor' => $preview['commission_minor'], 'net_minor' => $preview['net_minor'], 'fees' => $preview['commission'],
            ]);
            if ($staffChanged) {
                app(TeamOffers::class)->save($locked, $team, $rows, $by);
            }
            $this->record($locked, 'offer.edited', 'freelancer', $freelancer->id, $locked->status, $locked->status, $staffChanged ? ['staff' => true] : [], $by->id !== $freelancer->id ? $by->id : null);

            return $locked->refresh();
        });

        $project = $updated->project;
        if ($project->client !== null) {
            $this->notifier->notify($project->client, 'offer.updated', ['project' => (string) $project->title, 'name' => (string) $freelancer->name], ['project' => $project->uuid, 'offer' => $updated->uuid]);
        }

        return $updated->load(['project', 'media']);
    }

    /** Takes the offer back. Before the client saw it, the free (or bought) offer it used is given back (see `quota()`); a new one on the same project is not possible. */
    public function withdraw(Freelancer $freelancer, Offer $offer): Offer
    {
        [$freelancer, $by] = [$this->signerOf($offer, $freelancer), $freelancer];

        return DB::transaction(function () use ($freelancer, $by, $offer) {
            /** @var Offer $locked */
            $locked = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $this->assertChangeable($locked);
            $from = $locked->status;
            $locked->update(['status' => OfferStatus::Withdrawn, 'withdrawn_at' => now()]);
            $this->record($locked, 'offer.withdrawn', 'freelancer', $freelancer->id, $from, OfferStatus::Withdrawn, ['quota_returned' => $locked->viewed_at === null && ($locked->free || $locked->credit_id !== null || $locked->team_id !== null)], $by->id !== $freelancer->id ? $by->id : null);

            app(\Modules\Freelance\Services\Chat\OfferChat::class)->freeze($locked->project, $locked->freelancer_id);

            return $locked->refresh()->load(['project', 'media']);
        });
    }

    /** @return list<array<string, mixed>> what the offer said before each change, oldest first */
    public function history(Offer $offer): array
    {
        return $offer->revisions()->orderBy('number')->get()->map(fn ($r) => [
            'number' => $r->number, 'price_minor' => $r->price_minor, 'duration_days' => $r->duration_days, 'details' => $r->details,
            'net_minor' => $r->net_minor, 'at' => $r->created_at?->toIso8601String(),
        ])->all();
    }

    private function assertChangeable(Offer $offer): void
    {
        if (! $offer->status->editable() || $offer->project->status !== \Modules\Freelance\Enums\FreelanceProjectStatus::Published) {
            throw new FreelanceException('offer_not_editable', 409, ['status' => __('freelance.offer_statuses.'.$offer->status->value)], ['status' => $offer->status->value]);
        }
    }

    public function mine(Freelancer $freelancer, Project $project): ?Offer
    {
        return Offer::query()->where('project_id', $project->id)->where(fn ($q) => $q->where('freelancer_id', $freelancer->id)->orWhere('submitted_by_freelancer_id', $freelancer->id))->with('media')->first();
    }

    /** @return LengthAwarePaginator<Offer> the freelancer's offers, newest first */
    public function myOffers(Freelancer $freelancer, int $perPage): LengthAwarePaginator
    {
        // FL-L04: an order's offer is made by the order, not sent — it is listed with the orders.
        return Offer::query()->where(fn ($q) => $q->where('freelancer_id', $freelancer->id)->orWhere('submitted_by_freelancer_id', $freelancer->id))->whereHas('project', fn ($q) => $q->whereNull('service_id'))->with(['project', 'media'])->latest('id')->paginate($perPage);
    }

    /** @return \Illuminate\Support\Collection<int, Offer> the offers on a project, for its client — newest first, without the ones taken back */
    public function forProject(Project $project)
    {
        return Offer::query()->where('project_id', $project->id)->where('status', '!=', OfferStatus::Withdrawn->value)
            ->with(['freelancer', 'media', 'team'])->latest('id')->get();
    }

    public function liveCount(Project $project): int
    {
        return Offer::query()->where('project_id', $project->id)->whereIn('status', array_map(fn (OfferStatus $s) => $s->value, array_filter(OfferStatus::cases(), fn (OfferStatus $s) => $s->live())))->count();
    }

    /** @return array<string, mixed> `$for` = freelancer (their own offer: the net and the commission too) or client (the freelancer who sent it) */
    public function present(Offer $offer, string $for, string $filesPath): array
    {
        $out = [
            'uuid' => $offer->uuid,
            'status' => $offer->status->value,
            'price_minor' => $offer->price_minor,
            // FL-L09: the price asked and the loyalty discount taken off it (null: none).
            'list_price_minor' => $offer->list_price_minor,
            'loyalty_bp' => $offer->loyalty_bp,
            'saving_minor' => $offer->list_price_minor === null ? null : max(0, $offer->list_price_minor - $offer->price_minor),
            'currency' => $offer->currency_code,
            'duration_days' => $offer->duration_days,
            'details' => $offer->details,
            // FL-L13: who owns the work once it is paid and what the client may do with it (null: no terms).
            'rights' => Rights::summary(app(Rights::class)->effective($offer->rights, (int) $offer->project?->country_id)),
            // FL-307: each of the project's questions with this offer's answer.
            'answers' => collect((array) $offer->project?->questions)->values()->map(fn ($q, $i) => ['question' => $q, 'answer' => ((array) $offer->answers)[$i] ?? null])->all(),
            'attachments' => $offer->getMedia('attachments')->map(fn (Media $m) => ['id' => $m->id, 'name' => \Modules\Freelance\Services\Projects\MilestoneDeliveries::displayName($m), 'mime' => $m->mime_type, 'size' => $m->size, 'path' => "{$filesPath}/{$m->id}"])->values()->all(),
            'created_at' => $offer->created_at?->toIso8601String(),
            'edited' => $offer->revisions()->exists(),
            // FS-205: the team the offer is made in the name of and who works on it (null: the freelancer's own offer). The client sees first names only (D10).
            'team' => $offer->team_id === null ? null : app(TeamOffers::class)->present($offer->team, $offer->staff, $for),
            // FS-206c: the PM who sent it for the signing Owner (the client reads nothing of it).
            'submitted_by' => $for === 'client' || $offer->submitted_by_freelancer_id === null ? null : ['uuid' => $offer->submittedBy?->uuid, 'name' => $offer->submittedBy?->name],
        ];

        if ($for === 'freelancer') {
            return $out + [
                'can' => ['edit' => $offer->status->editable() && $offer->project?->status === \Modules\Freelance\Enums\FreelanceProjectStatus::Published, 'withdraw' => $offer->status->editable() && $offer->project?->status === \Modules\Freelance\Enums\FreelanceProjectStatus::Published],
                'revisions' => $offer->revisions()->count(),
                'status_reason' => in_array($offer->status, [OfferStatus::Rejected, OfferStatus::Withdrawn], true) ? ($offer->status_reason ?? $this->autoReason($offer)) : null,
                // FL-401: the agreement on my accepted offer, once the client sent it.
                'agreement' => ($ag = \Modules\Freelance\Models\FreelanceAgreement::query()->where('offer_id', $offer->id)->where('status', '!=', 'draft')->latest('id')->first()) === null ? null : ['uuid' => $ag->uuid, 'status' => $ag->status],
                'timeline' => $offer->events()->orderBy('id')->get()->map(fn ($e) => ['type' => $e->type, 'to' => $e->to_status, 'at' => $e->created_at?->toIso8601String()])->values()->all(),
                'chat' => app(\Modules\Freelance\Services\Chat\OfferChat::class)->summary($offer, 'freelancer'),
                'commission_minor' => $offer->commission_minor,
                'net_minor' => $offer->net_minor,
                'project' => $offer->relationLoaded('project') && $offer->project !== null ? ['uuid' => $offer->project->uuid, 'title' => $offer->project->title] : null,
            ];
        }

        $f = $offer->freelancer;

        return $out + [
            'freelancer' => $f === null ? null : [
                'uuid' => $f->uuid,
                'name' => $f->name,
                'headline' => $f->headline,
                'avatar_url' => $f->getFirstMediaUrl('avatar') ?: null,
                'verified' => $f->status?->value === 'verified',
            ],
        ];
    }

    /**
     * The answers to the project's questions, in their order: one for each, none left blank, no contact details (FL-307).
     *
     * @param  mixed  $given
     * @return list<string>|null
     */
    private function answers(Project $project, $given): ?array
    {
        $questions = array_values((array) $project->questions);
        if ($questions === []) {
            return null;
        }
        $given = array_values((array) $given);
        $out = [];
        foreach ($questions as $i => $q) {
            $a = trim(strip_tags((string) ($given[$i] ?? '')));
            if (mb_strlen($a) < 2) {
                throw new FreelanceException('offer_answers_required', 422, ['count' => count($questions)], ['count' => count($questions)]);
            }
            $out[] = $a;
        }
        app(OfferChecks::class)->assertNoContact(implode("\n", $out));

        return $out;
    }

    /** «Another offer was chosen» when the offer was turned down by the choice of another one (FL-309). */
    private function autoReason(Offer $offer): ?string
    {
        $last = $offer->events()->whereIn('type', ['offer.rejected', 'offer.withdrawn'])->latest('id')->first();
        $why = $last?->payload['auto'] ?? null;

        return in_array($why, ['another_chosen', 'agreement_cancelled', 'agreement_expired', 'agreement_declined'], true) ? __('freelance.offer_reasons.'.$why) : null;
    }

    /** The Owner the offer belongs to: the person himself, or - for a PM who sent it - the Owner who signs (FS-206c). */
    private function signerOf(Offer $offer, Freelancer $who): Freelancer
    {
        return (int) $offer->freelancer_id === (int) $who->id ? $who : Freelancer::query()->findOrFail($offer->freelancer_id);
    }

    /** @param  array<string, mixed>  $payload */
    private function record(Offer $offer, string $type, string $actorType, ?int $actorId, ?OfferStatus $from, ?OfferStatus $to, array $payload = [], ?int $actedBy = null): void
    {
        FreelanceOfferEvent::query()->create(($actedBy === null ? [] : ['acted_by_freelancer_id' => $actedBy]) + ['offer_id' => $offer->id, 'type' => $type, 'from_status' => $from?->value, 'to_status' => $to?->value, 'actor_type' => $actorType, 'actor_id' => $actorId, 'payload' => $payload === [] ? null : $payload]);
    }
}
