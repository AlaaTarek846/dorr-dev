<?php

namespace Modules\Freelance\Services\Market;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceOfferStatus as OfferStatus;
use Modules\Freelance\Enums\FreelanceProjectStatus as Status;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement as Agreement;
use Modules\Freelance\Models\FreelanceOffer as Offer;
use Modules\Freelance\Models\FreelanceOfferEvent;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelanceService as Service;
use Modules\Freelance\Models\FreelanceServiceOrder as Order;
use Modules\Freelance\Models\FreelanceServiceSubscription as ServiceSubscription;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Chat\OfferChat;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Moderation\Sanctions;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Offers\FreelancerOffers;
use Modules\Freelance\Services\Offers\Loyalty;
use Modules\Freelance\Services\Offers\OfferChecks;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Projects\Agreements;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Projects\ProjectStateMachine;
use Modules\User\Models\User;

/**
 * Ordering a ready-made service (FL-L04) without offers. One transaction makes, from the level and extras chosen, a private project with
 * the freelancer's offer already accepted and the agreement sent — the client's order is their approval — with its price, days and
 * revisions as bought (a copy kept on the order) and the commission and client fee of any project here. The freelancer then has
 * `services.answer_hours` to accept (the agreement starts: the client funds the part from the wallet and the work runs like any project)
 * or decline; left unanswered it ends by itself. Declined, ended or cancelled by the client before that, the project ends with it.
 * No money moves before the freelancer accepts. A `request_id` makes a double tap one order.
 */
class ServiceOrders
{
    public function __construct(
        private readonly ServiceCatalog $catalog,
        private readonly Agreements $agreements,
        private readonly ProjectStateMachine $machine,
        private readonly FreelancePolicies $policies,
        private readonly FreelanceFeatures $features,
        private readonly FreelanceNotifier $notifier,
    ) {}

    /** @param  array{level: string, addons?: list<int>, note?: ?string, request_id?: ?string}  $data */
    public function order(User $client, Service $service, array $data): Order
    {
        app(Sanctions::class)->assertAllowed($client, 'work');
        $requestId = isset($data['request_id']) ? (string) $data['request_id'] : null;
        if ($requestId !== null && ($same = $this->sameTap($client, $requestId)) !== null) {
            return $same;
        }
        $note = isset($data['note']) ? (trim(strip_tags((string) $data['note'])) ?: null) : null;
        if ($service->requirements !== null && $note === null) {
            throw new FreelanceException('service_note_required', 422, ['question' => $service->requirements]);
        }
        // No way to reach each other outside Dorr before the agreement starts (FL-308).
        app(OfferChecks::class)->assertNoContact($note);
        app(ClientProjects::class)->roomFor($client);
        // FL-L10: at the freelancer's cap only the client holding the place offered from the waiting list may order.
        app(FreelanceCapacity::class)->assertCanOrder($client, $service);

        try {
            $order = DB::transaction(fn () => $this->place($client, $service, $data, $note, $requestId));
        } catch (QueryException $e) {
            // The same tap twice at the same moment: the first one won.
            if ($requestId !== null && ($same = $this->sameTap($client, $requestId)) !== null) {
                return $same;
            }
            throw $e;
        }

        app(FreelanceCapacity::class)->ordered($client, $service);
        $project = $order->project;
        if ($this->features->allows((int) $project->country_id, 'chat')) {
            rescue(fn () => app(OfferChat::class)->open($project->offers()->first(), $client), report: false);
        }
        $this->notifier->notify($order->freelancer, 'order.received', ['service' => (string) $order->snapshot['title'], 'name' => (string) $client->name], ['order' => $order->uuid, 'project' => $project->uuid]);

        return $order->load(['service', 'project', 'agreement', 'freelancer', 'client']);
    }

    public function accept(Order $order, Freelancer $freelancer): Order
    {
        $this->agreements->approve($this->agreementOf($order), $freelancer);

        return $order->refresh()->load(['service', 'project', 'agreement', 'freelancer', 'client']);
    }

    public function decline(Order $order, Freelancer $freelancer, ?string $reason): Order
    {
        $this->agreements->decline($this->agreementOf($order), $freelancer, $reason);

        return $order->refresh()->load(['service', 'project', 'agreement', 'freelancer', 'client']);
    }

    /** The client takes it back before the freelancer accepted it. */
    public function cancel(Order $order, User $client): Order
    {
        $a = $this->agreementOf($order);
        if ($a->status !== Agreement::PENDING) {
            throw new FreelanceException('agreement_invalid_state', 409, ['status' => __('freelance.agreement_statuses.'.$a->status)], ['status' => $a->status]);
        }
        $this->agreements->cancel($a, $client);

        return $order->refresh()->load(['service', 'project', 'agreement', 'freelancer', 'client']);
    }

    // -------------------------------------------------------------- lists

    public function forClient(User $client, string $uuid): Order
    {
        return Order::query()->where('client_id', $client->id)->where('uuid', $uuid)->with(['service', 'project', 'agreement', 'freelancer'])->firstOrFail();
    }

    public function forFreelancer(Freelancer $freelancer, string $uuid): Order
    {
        return Order::query()->where('freelancer_id', $freelancer->id)->where('uuid', $uuid)->with(['service', 'project', 'agreement', 'client'])->firstOrFail();
    }

    /** @param  'client'|'freelancer'  $side */
    public function page(string $side, int $personId, ?string $status, int $perPage): LengthAwarePaginator
    {
        return Order::query()->where($side === 'client' ? 'client_id' : 'freelancer_id', $personId)
            ->when($status !== null, fn ($q) => $q->inStatus($status))
            ->with(['service', 'project', 'agreement', 'freelancer', 'client'])
            ->latest('id')->paginate($perPage);
    }

    /**
     * @param  'client'|'freelancer'|'staff'  $side  each sees the money from their side
     * @return array<string, mixed>
     */
    public function present(Order $o, string $side): array
    {
        $a = $o->agreement;
        $money = (array) ($a?->money ?? []);
        $status = $o->status();
        $snap = (array) $o->snapshot;

        return [
            'uuid' => $o->uuid,
            'status' => $status,
            'service' => ['uuid' => $o->service?->uuid, 'title' => $snap['title'] ?? $o->service?->title],
            'level' => $o->level,
            'level_label' => __('freelance.service_levels.'.$o->level),
            'tier_name' => $snap['tier']['name'] ?? null,
            'features' => array_values((array) ($snap['tier']['features'] ?? [])),
            'addons' => array_values((array) ($snap['addons'] ?? [])),
            'price_minor' => $o->price_minor,
            // FL-L09: the usual price and the loyalty discount taken off it (null: none).
            'list_price_minor' => $snap['loyalty']['list_price_minor'] ?? null,
            'loyalty_bp' => $snap['loyalty']['bp'] ?? null,
            'saving_minor' => isset($snap['loyalty']) ? max(0, (int) $snap['loyalty']['list_price_minor'] - (int) $o->price_minor) : null,
            'currency' => $o->currency_code,
            'decimals' => (int) ($snap['decimals'] ?? 2),
            'delivery_days' => $o->delivery_days,
            'revisions' => $o->revisions,
            'note' => $o->note,
            'project' => $o->project?->uuid,
            // FL-L05: the monthly subscription this order is one month of.
            'subscription' => $o->subscription_id === null ? null : ['uuid' => $o->subscription?->uuid, 'cycle' => $o->cycle],
            'agreement' => ['uuid' => $a?->uuid, 'status' => $a?->status, 'end_reason' => $a?->end_reason],
            // Until when the freelancer can accept it.
            'answer_by' => $status === 'awaiting' ? $a?->expires_at?->toIso8601String() : null,
            'money' => match ($side) {
                'client' => ['price_minor' => $money['price_minor'] ?? $o->price_minor, 'client_fee_minor' => $money['client_fee_minor'] ?? 0, 'total_minor' => $money['client_total_minor'] ?? $o->price_minor],
                'freelancer' => ['price_minor' => $money['price_minor'] ?? $o->price_minor, 'commission_minor' => $money['commission_minor'] ?? 0, 'net_minor' => $money['net_minor'] ?? $o->price_minor],
                default => $money,
            },
            'client' => $side === 'client' ? null : ['name' => $o->client?->name] + ($side === 'staff' ? ['id' => $o->client_id, 'phone' => $o->client?->phone] : []),
            'freelancer' => $side === 'freelancer' ? null : ['uuid' => $o->freelancer?->uuid, 'name' => $o->freelancer?->name] + ($side === 'staff' ? ['id' => $o->freelancer_id] : []),
            'can' => match ($side) {
                'freelancer' => ['accept' => $status === 'awaiting', 'decline' => $status === 'awaiting'],
                'client' => ['cancel' => $status === 'awaiting'],
                default => new \stdClass,
            },
            'created_at' => $o->created_at?->toIso8601String(),
        ];
    }

    // -------------------------------------------------------------- inside

    /** Inside the order's transaction, with the service locked: it must still be on offer, at the price read under the lock. */
    private function place(User $client, Service $service, array $data, ?string $note, ?string $requestId): Order
    {
        /** @var Service $s */
        $s = Service::query()->whereKey($service->id)->lockForUpdate()->with(['freelancer', 'country.currency'])->firstOrFail();
        $freelancer = $s->freelancer;
        if (! $s->live() || $freelancer === null || $freelancer->trashed() || $freelancer->status !== FreelancerStatus::Verified
            || app(Sanctions::class)->restrictions($freelancer)['work'] !== null) {
            throw new FreelanceException('service_unavailable', 409);
        }
        $pick = $this->catalog->pick($s, (string) $data['level'], $data['addons'] ?? []);
        $tier = $pick['tier'];
        // FL-L09: a client back for more gets the loyalty step they earned off the price (a monthly service keeps one price for every month).
        $deal = $s->monthly() ? null : app(Loyalty::class)->apply($freelancer, (int) $client->id, $pick['price_minor']);
        $bought = [
            'title' => $s->title,
            'level' => $tier->level,
            'tier' => ['name' => $tier->name, 'description' => $tier->description, 'features' => array_values((array) $tier->features)],
            'addons' => $pick['addons']->map(fn ($a) => ['title' => $a->title, 'price_minor' => $a->price_minor, 'extra_days' => $a->extra_days])->values()->all(),
            'decimals' => (int) ($s->country?->currency?->decimal_places ?? 2),
            'price_minor' => $deal['price_minor'] ?? $pick['price_minor'],
            'delivery_days' => $pick['delivery_days'],
            'revisions' => $pick['revisions'],
        ] + (($deal['loyalty_bp'] ?? null) === null ? [] : ['loyalty' => ['bp' => $deal['loyalty_bp'], 'list_price_minor' => $deal['list_price_minor']]]);
        $hours = max(1, (int) ($this->policies->for((int) $s->country_id, $s->freelance_specialty_id)->get('services.answer_hours') ?? 48));
        $order = $this->book($s, $client, $bought, $note, $requestId, now()->addHours($hours));

        // FL-L05: a monthly service - this order is the first month of a subscription that keeps these terms.
        if ($s->monthly()) {
            $sub = ServiceSubscription::query()->create([
                'service_id' => $s->id, 'client_id' => $client->id, 'freelancer_id' => $freelancer->id, 'level' => $tier->level,
                'snapshot' => array_diff_key($bought, array_flip(['price_minor', 'delivery_days', 'revisions'])),
                'price_minor' => $bought['price_minor'], 'currency_code' => $s->currency_code, 'delivery_days' => $bought['delivery_days'],
                'revisions' => $bought['revisions'], 'note' => $note, 'status' => ServiceSubscription::PENDING, 'cycle' => 1,
            ]);
            $order->forceFill(['subscription_id' => $sub->id, 'cycle' => 1])->save();
        }

        return $order;
    }

    /**
     * Inside a transaction: the private project, the accepted offer and the agreement sent for what was bought (a level with its extras,
     * at a price) - an order, or (FL-L05) one month of a subscription at its kept terms.
     *
     * @param  array{title: string, level: string, tier: array{name: string, description: ?string, features: list<string>}, addons: list<array{title: string, price_minor: int, extra_days: int}>, decimals: int, price_minor: int, delivery_days: int, revisions: int, loyalty?: array{bp: int, list_price_minor: int}}  $bought
     */
    public function book(Service $s, User $client, array $bought, ?string $note, ?string $requestId, \DateTimeInterface $answerBy): Order
    {
        $freelancer = $s->freelancer;
        $addons = $bought['addons'];
        $features = (array) $bought['tier']['features'];

        $project = Project::query()->create([
            'client_id' => $client->id,
            'country_id' => $s->country_id,
            'freelance_specialty_id' => $s->freelance_specialty_id,
            'service_id' => $s->id,
            'title' => $bought['title'],
            'description' => $s->description,
            'budget_type' => 'fixed',
            'budget_min_minor' => $bought['price_minor'],
            'currency_code' => $s->currency_code,
            'duration_days' => $bought['delivery_days'],
            'visibility' => 'private',
            'status' => Status::Awarded,
            'awarded_at' => now(),
            'offers_closed_at' => now(),
            'offers_close_reason' => 'chosen',
        ]);
        $this->machine->record($project, 'project.ordered', 'client', $client->id, ['service' => $s->uuid, 'level' => $bought['level']], null, Status::Awarded);

        $preview = app(FreelancerOffers::class)->preview($project, $bought['price_minor'], $freelancer);
        $offer = Offer::query()->create([
            'project_id' => $project->id,
            'freelancer_id' => $freelancer->id,
            'price_minor' => $preview['price_minor'],
            'list_price_minor' => $bought['loyalty']['list_price_minor'] ?? null,
            'loyalty_bp' => $bought['loyalty']['bp'] ?? null,
            'currency_code' => $s->currency_code,
            'duration_days' => $bought['delivery_days'],
            'details' => $this->details($bought['tier']['name'], $features, $addons, $note),
            // FL-L13: the service's own terms are the order's.
            'rights' => $s->rights,
            'status' => OfferStatus::Accepted,
            'accepted_at' => now(),
            'commission_minor' => $preview['commission_minor'],
            'net_minor' => $preview['net_minor'],
            'fees' => $preview['commission'],
            // A service's order costs the freelancer none of their offers.
            'free' => false,
        ]);
        FreelanceOfferEvent::query()->create([
            'offer_id' => $offer->id, 'type' => 'offer.accepted', 'from_status' => null, 'to_status' => OfferStatus::Accepted->value,
            'actor_type' => 'client', 'actor_id' => $client->id, 'payload' => ['auto' => 'service_order'],
        ]);

        $agreement = $this->agreements->draft($project, $offer);
        $agreement->forceFill([
            // The level's revisions are the free rounds of this agreement.
            'policy' => ['review.free_revisions' => $bought['revisions']] + (array) $agreement->policy,
            'includes' => implode("\n", array_merge($features, array_column($addons, 'title'))) ?: null,
        ])->save();
        $this->agreements->send($agreement, $client, $answerBy, false);

        return Order::query()->create([
            'service_id' => $s->id,
            'client_id' => $client->id,
            'freelancer_id' => $freelancer->id,
            'project_id' => $project->id,
            'request_id' => $requestId,
            'level' => $bought['level'],
            'snapshot' => ['title' => $bought['title'], 'tier' => $bought['tier'], 'addons' => $addons, 'decimals' => $bought['decimals']]
                + (isset($bought['loyalty']) ? ['loyalty' => $bought['loyalty']] : []),
            'price_minor' => $bought['price_minor'],
            'currency_code' => $s->currency_code,
            'delivery_days' => $bought['delivery_days'],
            'revisions' => $bought['revisions'],
            'note' => $note,
        ]);
    }

    private function sameTap(User $client, string $requestId): ?Order
    {
        return Order::query()->where('client_id', $client->id)->where('request_id', $requestId)->with(['service', 'project', 'agreement', 'freelancer', 'client'])->first();
    }

    private function agreementOf(Order $order): Agreement
    {
        return $order->agreement ?? throw new FreelanceException('agreement_invalid_state', 409, ['status' => '-'], ['status' => null]);
    }

    /**
     * What the offer (and so the agreement's scope) says was bought.
     *
     * @param  list<string>  $features
     * @param  list<array{title: string, price_minor: int, extra_days: int}>  $addons
     */
    private function details(string $tier, array $features, array $addons, ?string $note): string
    {
        $lines = [__('freelance.service_order_level', ['name' => $tier])];
        foreach ($features as $f) {
            $lines[] = '• '.$f;
        }
        foreach ($addons as $a) {
            $lines[] = '+ '.$a['title'];
        }
        if ($note !== null) {
            $lines[] = '';
            $lines[] = __('freelance.service_order_note').' '.$note;
        }

        return implode("\n", $lines);
    }
}
