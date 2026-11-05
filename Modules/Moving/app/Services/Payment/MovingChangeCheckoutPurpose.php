<?php

namespace Modules\Moving\Services\Payment;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Modules\Moving\Exceptions\MovingException;
use Modules\Moving\Models\MovingChangeOrder;
use Modules\Moving\Services\Requests\MovingChangeService;
use Modules\User\Models\User;
use Modules\Wallet\Contracts\CheckoutPurpose;
use Modules\Wallet\Contracts\ReservesFunds;
use Modules\Wallet\Models\Checkout;
use Modules\Wallet\Support\Payments\CheckoutLine;

/**
 * Paying the difference of an approved change order (REQ-037): held with the rest of the move until delivery; the
 * change counts only once it is paid.
 */
class MovingChangeCheckoutPurpose implements CheckoutPurpose, ReservesFunds
{
    public const KEY = 'moving_change';

    public function __construct(
        private readonly MovingChangeService $changes,
        private readonly MovingPayments $payments,
    ) {}

    public function line(Model $owner, Country $country, array $reference): CheckoutLine
    {
        $change = $this->find($owner, $reference);

        return new CheckoutLine($this->changes->extraTotal($change), __('moving.checkout.change'), $change->request->establishment?->name, ['change_uuid' => $change->uuid]);
    }

    public function fulfil(Checkout $checkout): void
    {
        $change = MovingChangeOrder::query()->where('uuid', $checkout->reference['change_uuid'] ?? null)->lockForUpdate()->first();
        if ($change === null || $change->status !== 'awaiting_payment') {
            throw new MovingException('change_closed', 409);
        }
        $this->payments->hold($change->request, $change->request->user, (int) $checkout->amount_minor, 'change:'.$change->uuid);
        $this->changes->apply($change);
    }

    private function find(Model $owner, array $reference): MovingChangeOrder
    {
        $change = MovingChangeOrder::query()->with('request.establishment')->where('uuid', (string) ($reference['change_uuid'] ?? ''))->first();
        if ($change === null || ! $owner instanceof User || $change->request->user_id !== $owner->getKey() || $change->status !== 'awaiting_payment') {
            throw new MovingException('change_not_found', 404);
        }

        return $change;
    }
}
