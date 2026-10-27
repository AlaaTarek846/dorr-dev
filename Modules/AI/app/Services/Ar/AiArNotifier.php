<?php

namespace Modules\AI\Services\Ar;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\AI\Models\Ar\AiArApproval;
use Modules\AI\Models\Ar\AiArExperience;
use Modules\AI\Models\Ar\AiArOrder;
use Modules\Provider\Models\Provider;

/** Tells each side of a design review what happened, through the platform's NotificationCenter (in-app + real-time + push, in the reader's language). */
class AiArNotifier
{
    public function __construct(private readonly NotificationCenter $center) {}

    public function submitted(AiArApproval $approval): void
    {
        $this->toProvider($approval, 'ai_ar.submitted', 'ai_ar_submitted');
    }

    public function withdrawn(AiArApproval $approval): void
    {
        $this->toProvider($approval, 'ai_ar.withdrawn', 'ai_ar_withdrawn');
    }

    public function accepted(AiArApproval $approval): void
    {
        $this->toProvider($approval, 'ai_ar.accepted', 'ai_ar_accepted');
    }

    /** approved | changes_requested | rejected, to the customer who owns the design. */
    public function decided(AiArApproval $approval): void
    {
        $owner = $this->ownerOf($approval->experience);

        if ($owner === null) {
            return;
        }

        $this->center->send(
            $owner,
            'ai_ar.'.$approval->status,
            'ai_ar_'.$approval->status.'_title',
            'ai_ar_'.$approval->status.'_body',
            ['title' => (string) $approval->experience?->title, 'note' => (string) $approval->provider_note],
            ['type' => 'ai_ar_review', 'experience_id' => $approval->experience_id, 'approval_id' => $approval->id],
        );
    }


    // ---- Orders (money) ----

    public function orderPaid(AiArOrder $order): void
    {
        $this->orderToProvider($order, 'ai_ar.order_paid', 'ai_ar_order_paid');
    }

    public function orderReleased(AiArOrder $order): void
    {
        $this->orderToProvider($order, 'ai_ar.order_released', 'ai_ar_order_released', $order->provider_net_minor);
    }

    public function orderDisputed(AiArOrder $order): void
    {
        $this->orderToProvider($order, 'ai_ar.order_disputed', 'ai_ar_order_disputed');
    }

    public function orderDelivered(AiArOrder $order): void
    {
        $this->orderToCustomer($order, 'ai_ar.order_delivered', 'ai_ar_order_delivered');
    }

    /** The customer gets the money back; $by (customer | provider | admin) decides who else hears about it. */
    public function orderRefunded(AiArOrder $order, string $by): void
    {
        $this->orderToCustomer($order, 'ai_ar.order_refunded', 'ai_ar_order_refunded');

        if ($by !== 'provider') {
            $this->orderToProvider($order, 'ai_ar.order_cancelled', 'ai_ar_order_cancelled');
        }
    }

    private function orderToProvider(AiArOrder $order, string $event, string $key, ?int $minor = null): void
    {
        $provider = Provider::query()->find($order->provider_id);

        if ($provider !== null) {
            $this->sendOrder($provider, $order, $event, $key, $minor ?? $order->amount_minor);
        }
    }

    private function orderToCustomer(AiArOrder $order, string $event, string $key): void
    {
        $class = Relation::getMorphedModel($order->customer_type) ?? $order->customer_type;
        $customer = class_exists($class) ? $class::query()->find($order->customer_id) : null;

        if ($customer !== null) {
            $this->sendOrder($customer, $order, $event, $key, $order->amount_minor);
        }
    }

    private function sendOrder(object $to, AiArOrder $order, string $event, string $key, int $minor): void
    {
        $decimals = \App\Models\Currency::query()->where('code', $order->currency)->value('decimal_places') ?? 2;

        $this->center->send(
            $to,
            $event,
            $key.'_title',
            $key.'_body',
            ['title' => (string) $order->experience?->title, 'amount' => number_format($minor / (10 ** $decimals), (int) $decimals).' '.$order->currency, 'note' => (string) ($order->dispute_reason ?? $order->delivery_note)],
            ['type' => 'ai_ar_order', 'experience_id' => $order->experience_id, 'order_id' => $order->id],
        );
    }

    private function toProvider(AiArApproval $approval, string $event, string $key): void
    {
        $provider = Provider::query()->find($approval->provider_id);

        if ($provider === null) {
            return;
        }

        $this->center->send(
            $provider,
            $event,
            $key.'_title',
            $key.'_body',
            ['title' => (string) $approval->experience?->title],
            ['type' => 'ai_ar_review', 'experience_id' => $approval->experience_id, 'approval_id' => $approval->id],
        );
    }

    private function ownerOf(?AiArExperience $experience): ?object
    {
        if ($experience === null) {
            return null;
        }

        $class = Relation::getMorphedModel($experience->owner_type) ?? $experience->owner_type;

        return class_exists($class) ? $class::query()->find($experience->owner_id) : null;
    }
}
