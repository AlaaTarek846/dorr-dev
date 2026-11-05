<?php

namespace Modules\Moving\Services\Payment;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Modules\Moving\Enums\MovingStatus;
use Modules\Moving\Enums\OfferStatus;
use Modules\Moving\Exceptions\MovingException;
use Modules\Moving\Models\MovingOffer;
use Modules\Moving\Models\MovingRequest;
use Modules\Moving\Services\Requests\MovingJournal;
use Modules\User\Models\User;
use Modules\Wallet\Contracts\CheckoutPurpose;
use Modules\Wallet\Contracts\ReservesFunds;
use Modules\Wallet\Models\Checkout;
use Modules\Wallet\Support\Payments\CheckoutLine;

/**
 * Accepting an offer on the app's one payment screen (B-06). Paying — from the balance or a card top-up — only makes
 * sure the money is in the customer's wallet; fulfil() then holds it, accepts the offer, closes the others and keeps
 * the agreed numbers with the request (REQ-025, REQ-026, REQ-114). Nothing is debited until delivery.
 */
class MovingCheckoutPurpose implements CheckoutPurpose, ReservesFunds
{
    public const KEY = 'moving';

    public function __construct(
        private readonly MovingPayments $payments,
        private readonly MovingPricing $pricing,
        private readonly MovingJournal $journal,
    ) {}

    public function line(Model $owner, Country $country, array $reference): CheckoutLine
    {
        [$request, $offer] = $this->find($owner, $reference);
        $this->assertAcceptable($request, $offer);
        $money = $this->pricing->forOffer($request, $offer);

        return new CheckoutLine(
            $money['total_minor'],
            __('moving.checkout.title'),
            $offer->establishment?->name,
            ['request_uuid' => $request->uuid, 'offer_uuid' => $offer->uuid],
        );
    }

    public function fulfil(Checkout $checkout): void
    {
        $request = MovingRequest::query()->where('uuid', $checkout->reference['request_uuid'] ?? null)->lockForUpdate()->first();
        $offer = $request ? MovingOffer::query()->where('uuid', $checkout->reference['offer_uuid'] ?? null)->where('request_id', $request->id)->lockForUpdate()->first() : null;
        if ($request === null || $offer === null) {
            throw new MovingException('request_not_found', 404);
        }
        // Withdrawn, expired or another offer taken meanwhile: the checkout fails and the money stays in the wallet.
        $this->assertAcceptable($request, $offer);
        $money = $this->pricing->forOffer($request, $offer);

        $this->payments->hold($request, $request->user, (int) $checkout->amount_minor);
        $offer->update(['status' => OfferStatus::Accepted]);
        $request->offers()->whereKeyNot($offer->id)->whereIn('status', [OfferStatus::Sent->value, OfferStatus::AwaitingApproval->value])
            ->update(['status' => OfferStatus::Declined->value]);

        $this->journal->move($request, MovingStatus::Confirmed, 'accepted', 'user', $request->user_id, [
            ...$money,
            'accepted_offer_id' => $offer->id,
            'establishment_id' => $offer->establishment_id,
            'branch_id' => $offer->branch_id,
            'vehicle_id' => $offer->vehicle_id,
            'payment_status' => 'held',
            // Given at the door to confirm delivery (the QR holds it too); the driver never sees it before.
            'delivery_code' => (string) random_int(1000, 9999),
            'closed_at' => now(),
        ], ['offer' => $offer->uuid, 'checkout' => $checkout->uuid]);
    }

    /** @return array{0: MovingRequest, 1: MovingOffer} */
    private function find(Model $owner, array $reference): array
    {
        $request = $owner instanceof User
            ? MovingRequest::query()->where('uuid', (string) ($reference['request_uuid'] ?? ''))->where('user_id', $owner->getKey())->first()
            : null;
        $offer = $request ? MovingOffer::query()->with('establishment')->where('uuid', (string) ($reference['offer_uuid'] ?? ''))->where('request_id', $request->id)->first() : null;
        if ($request === null || $offer === null) {
            throw new MovingException('request_not_found', 404);
        }

        return [$request, $offer];
    }

    private function assertAcceptable(MovingRequest $request, MovingOffer $offer): void
    {
        if ($request->status !== MovingStatus::Open) {
            throw new MovingException('request_closed', 409);
        }
        if (! $offer->isLive()) {
            throw new MovingException('offer_not_available', 409);
        }
    }
}
