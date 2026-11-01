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
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Policy\FreelanceFees;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Projects\ProjectOffersWindow;
use Modules\Freelance\Services\Verification\FreelancerGate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Sending an offer (FL-301). One rule decides whether a freelancer may offer on a project, checked in this order so the app can say
 * the most useful thing first: a verified account that is taking work (FL-110 / FL-106) → the project still taking offers (FL-204)
 * → not offered there already → the project not full (`offers.max_per_project`) → the monthly free quota not used up
 * (`offers.free_monthly_quota`, when the `free_offer_quota` switch is on). The price is free, but never under the project budget's
 * minimum of the country / specialty. The commission and the net are worked out from the policy now and kept with the offer.
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

    /** @throws FreelanceException the first reason this freelancer can't offer on this project */
    public function assertCanSend(Freelancer $freelancer, Project $project): void
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
        $max = (int) $this->policies->for($project->country_id, $project->freelance_specialty_id)->get('offers.max_per_project');
        if ($max > 0 && $this->liveCount($project) >= $max) {
            throw new FreelanceException('project_offers_full', 422, ['max' => $max], ['max' => $max]);
        }
        $quota = $this->quota($freelancer);
        if ($quota['limit'] !== null && $quota['left'] <= 0) {
            throw new FreelanceException('offer_quota_exhausted', 422, ['limit' => $quota['limit']], ['limit' => $quota['limit']]);
        }
    }

    /** @return array{code: string, message: string}|null why the freelancer can't offer (null: they can) — for the app to say before the form */
    public function blocked(Freelancer $freelancer, Project $project): ?array
    {
        try {
            $this->assertCanSend($freelancer, $project);

            return null;
        } catch (FreelanceException $e) {
            return ['code' => $e->apiErrorCode(), 'message' => $e->apiMessage()];
        }
    }

    /** @return array{enabled: bool, limit: ?int, used: int, left: ?int} the free offers of this calendar month */
    public function quota(Freelancer $freelancer): array
    {
        $enabled = $this->features->allows((int) $freelancer->country_id, 'free_offer_quota');
        // An offer taken back before the client saw it gives its place back (FL-302).
        $used = Offer::query()->where('freelancer_id', $freelancer->id)->where('free', true)->where('created_at', '>=', now()->startOfMonth())
            ->where(fn ($q) => $q->where('status', '!=', OfferStatus::Withdrawn->value)->orWhereNotNull('viewed_at'))->count();
        $limit = $enabled ? (int) $this->policies->for((int) $freelancer->country_id)->get('offers.free_monthly_quota') : null;

        return ['enabled' => $enabled, 'limit' => $limit, 'used' => $used, 'left' => $limit === null ? null : max(0, $limit - $used)];
    }

    /** @return array{price_minor: int, commission_minor: int, net_minor: int, currency: string, commission: array<string, mixed>} what an offer at this price leaves the freelancer */
    public function preview(Project $project, int $priceMinor): array
    {
        $values = $this->policies->for($project->country_id, $project->freelance_specialty_id)->all();
        // Where the commission switch is off, the platform keeps nothing.
        if (! $this->features->allows($project->country_id, 'commission')) {
            $values['commission.type'] = 'fixed';
            $values['commission.value'] = 0;
            $values['commission.min_minor'] = null;
        }
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
     * @param  array{price_minor: int, duration_days: int, details: string}  $data
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

        $offer = DB::transaction(function () use ($freelancer, $project, $data, $files, $answers) {
            // One at a time per project, so its limit and the one-offer rule hold when two arrive together.
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->assertCanSend($freelancer, $locked);

            $preview = $this->preview($locked, (int) $data['price_minor']);
            $offer = Offer::query()->create([
                'project_id' => $locked->id,
                'freelancer_id' => $freelancer->id,
                'price_minor' => $preview['price_minor'],
                'currency_code' => $locked->currency_code,
                'duration_days' => (int) $data['duration_days'],
                'details' => trim(strip_tags((string) $data['details'])),
                'answers' => $answers,
                'status' => OfferStatus::Submitted,
                'commission_minor' => $preview['commission_minor'],
                'net_minor' => $preview['net_minor'],
                'fees' => $preview['commission'],
                'free' => $this->quota($freelancer)['enabled'],
            ]);
            foreach ($files as $file) {
                $offer->addAttachment($file);
            }
            $this->record($offer, 'offer.submitted', 'freelancer', $freelancer->id, null, OfferStatus::Submitted);
            app(\Modules\Freelance\Services\Projects\ProjectInvitations::class)->acceptedByOffer($freelancer, $locked);

            // The offer that fills the project closes it to more.
            $max = (int) $this->policies->for($locked->country_id, $locked->freelance_specialty_id)->get('offers.max_per_project');
            if ($max > 0 && $this->liveCount($locked) >= $max) {
                $this->window->closeBecauseFull($locked);
            }

            return $offer;
        });

        if ($project->client !== null) {
            $this->notifier->notify($project->client, 'offer.received', ['project' => (string) $project->title, 'name' => (string) $freelancer->name], ['project' => $project->uuid, 'offer' => $offer->uuid]);
        }

        return $offer->load('media');
    }

    /** One of the freelancer's own offers by its uuid (any project they can see), else not found. */
    public function find(Freelancer $freelancer, string $uuid): Offer
    {
        return Offer::query()->where('freelancer_id', $freelancer->id)->where('uuid', $uuid)->with(['project', 'media'])->firstOrFail();
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
        $updated = DB::transaction(function () use ($freelancer, $offer, $data, $newAnswers) {
            /** @var Offer $locked */
            $locked = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $this->assertChangeable($locked);
            $project = $locked->project;

            $price = (int) ($data['price_minor'] ?? $locked->price_minor);
            $min = $this->policies->for($project->country_id, $project->freelance_specialty_id)->get('project.min_budget_minor');
            if ($min !== null && $price < (int) $min) {
                throw new FreelanceException('offer_below_min', 422, ['min' => number_format($min / 100, 2).' '.$project->currency_code], ['min_minor' => (int) $min]);
            }
            $details = array_key_exists('details', $data) ? trim(strip_tags((string) $data['details'])) : $locked->details;
            $days = (int) ($data['duration_days'] ?? $locked->duration_days);
            $answersChanged = $newAnswers !== null && $newAnswers !== $locked->answers;
            if ($price === $locked->price_minor && $days === $locked->duration_days && $details === $locked->details && ! $answersChanged) {
                throw new FreelanceException('offer_unchanged', 422);
            }

            // What it said until now, once (the first revision is the offer as it was sent).
            $number = (int) $locked->revisions()->max('number') + 1;
            $locked->revisions()->create([
                'number' => $number, 'price_minor' => $locked->price_minor, 'duration_days' => $locked->duration_days, 'details' => $locked->details,
                'commission_minor' => $locked->commission_minor, 'net_minor' => $locked->net_minor,
            ]);

            $preview = $this->preview($project, $price);
            $locked->update([
                'price_minor' => $preview['price_minor'], 'duration_days' => $days, 'details' => $details,
            ] + ($newAnswers !== null ? ['answers' => $newAnswers] : []) + [
                'commission_minor' => $preview['commission_minor'], 'net_minor' => $preview['net_minor'], 'fees' => $preview['commission'],
            ]);
            $this->record($locked, 'offer.edited', 'freelancer', $freelancer->id, $locked->status, $locked->status);

            return $locked->refresh();
        });

        $project = $updated->project;
        if ($project->client !== null) {
            $this->notifier->notify($project->client, 'offer.updated', ['project' => (string) $project->title, 'name' => (string) $freelancer->name], ['project' => $project->uuid, 'offer' => $updated->uuid]);
        }

        return $updated->load(['project', 'media']);
    }

    /** Takes the offer back. Before the client saw it, the free offer it used is given back (see `quota()`); a new one on the same project is not possible. */
    public function withdraw(Freelancer $freelancer, Offer $offer): Offer
    {
        return DB::transaction(function () use ($freelancer, $offer) {
            /** @var Offer $locked */
            $locked = Offer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $this->assertChangeable($locked);
            $from = $locked->status;
            $locked->update(['status' => OfferStatus::Withdrawn, 'withdrawn_at' => now()]);
            $this->record($locked, 'offer.withdrawn', 'freelancer', $freelancer->id, $from, OfferStatus::Withdrawn, ['quota_returned' => $locked->viewed_at === null && $locked->free]);

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
        return Offer::query()->where('project_id', $project->id)->where('freelancer_id', $freelancer->id)->with('media')->first();
    }

    /** @return LengthAwarePaginator<Offer> the freelancer's offers, newest first */
    public function myOffers(Freelancer $freelancer, int $perPage): LengthAwarePaginator
    {
        return Offer::query()->where('freelancer_id', $freelancer->id)->with(['project', 'media'])->latest('id')->paginate($perPage);
    }

    /** @return \Illuminate\Support\Collection<int, Offer> the offers on a project, for its client — newest first, without the ones taken back */
    public function forProject(Project $project)
    {
        return Offer::query()->where('project_id', $project->id)->where('status', '!=', OfferStatus::Withdrawn->value)
            ->with(['freelancer', 'media'])->latest('id')->get();
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
            'currency' => $offer->currency_code,
            'duration_days' => $offer->duration_days,
            'details' => $offer->details,
            // FL-307: each of the project's questions with this offer's answer.
            'answers' => collect((array) $offer->project?->questions)->values()->map(fn ($q, $i) => ['question' => $q, 'answer' => ((array) $offer->answers)[$i] ?? null])->all(),
            'attachments' => $offer->getMedia('attachments')->map(fn (Media $m) => ['id' => $m->id, 'name' => \Modules\Freelance\Services\Projects\MilestoneDeliveries::displayName($m), 'mime' => $m->mime_type, 'size' => $m->size, 'path' => "{$filesPath}/{$m->id}"])->values()->all(),
            'created_at' => $offer->created_at?->toIso8601String(),
            'edited' => $offer->revisions()->exists(),
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

    /** @param  array<string, mixed>  $payload */
    private function record(Offer $offer, string $type, string $actorType, ?int $actorId, ?OfferStatus $from, ?OfferStatus $to, array $payload = []): void
    {
        FreelanceOfferEvent::query()->create(['offer_id' => $offer->id, 'type' => $type, 'from_status' => $from?->value, 'to_status' => $to?->value, 'actor_type' => $actorType, 'actor_id' => $actorId, 'payload' => $payload === [] ? null : $payload]);
    }
}
