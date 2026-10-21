<?php

namespace Modules\Rides\Services\Payment;

use App\Models\Country;
use Illuminate\Database\Eloquent\Model;
use Modules\Rides\Exceptions\RidesException;
use Modules\User\Models\User;
use Modules\Wallet\Contracts\ChargesItself;
use Modules\Wallet\Contracts\CheckoutPurpose;
use Modules\Wallet\Models\Checkout;
use Modules\Wallet\Support\Payments\CheckoutLine;

/**
 * O-67: bus seats, a boat, a driver, a lesson pack — booked first «unpaid» (holding the seats / the time),
 * then paid on the app's one payment screen: a coupon, the wallet, or a gateway top-up and back to the
 * same page. fulfil() takes what's left after the coupon and confirms the booking (the service's own
 * confirmPaid()). No cash for these.
 *
 * The app sends `reference: {booking_uuid}`.
 */
abstract class ServiceBookingPurpose implements ChargesItself, CheckoutPurpose
{
    /** The booking's model class. */
    abstract protected function model(): string;

    abstract protected function country(Model $booking): ?Country;

    abstract protected function title(Model $booking): string;

    abstract protected function subtitle(Model $booking): ?string;

    abstract protected function confirm(Model $booking, int $payable, int $discount): void;

    public function line(Model $owner, Country $country, array $reference): CheckoutLine
    {
        $booking = $this->booking($owner, (string) ($reference['booking_uuid'] ?? ''));
        if ((int) $this->country($booking)?->getKey() !== (int) $country->getKey()) {
            throw new RidesException('booking_lapsed', 409);
        }

        return new CheckoutLine((int) $booking->amount_minor, $this->title($booking), $this->subtitle($booking), ['booking_uuid' => $booking->uuid]);
    }

    public function fulfil(Checkout $checkout): void
    {
        $class = $this->model();
        $booking = $class::query()->where('uuid', $checkout->reference['booking_uuid'] ?? null)->lockForUpdate()->first();
        if ($booking === null) {
            throw new RidesException('booking_lapsed', 409);
        }
        $this->confirm($booking, $checkout->payableMinor(), (int) $checkout->discount_minor);
    }

    private function booking(Model $owner, string $uuid): Model
    {
        $class = $this->model();
        $booking = $owner instanceof User ? $class::query()->where('uuid', $uuid)->where('user_id', $owner->getKey())->first() : null;
        if ($booking === null) {
            throw new RidesException('booking_not_found', 404);
        }
        if ($booking->status !== 'unpaid' || ($booking->payment_expires_at !== null && $booking->payment_expires_at->isPast())) {
            throw new RidesException('booking_lapsed', 409);
        }

        return $booking;
    }
}
