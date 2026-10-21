<?php

namespace Modules\Rides\Services\Drivers;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Str;
use Modules\Driver\Enums\DriverStatus;
use Modules\Driver\Models\Driver;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\DriverPayoutSetting;
use Modules\Rides\Services\Policy\RidePolicies;
use Modules\Wallet\Models\WithdrawalMethod;

/**
 * The captain's payout rules (docs/rides_tasks.md D-216..D-224, D-299), plugged into the wallet's withdrawals:
 *
 *  - D-216: only a verified, active captain withdraws (KYC = the documents the team approved).
 *  - D-218: a payout account added or changed waits `wallet.driver_new_method_hold_hours` before money goes to it,
 *    and the captain is told at once (if it wasn't them, they freeze it).
 *  - D-219: an account in someone else's name needs the captain's acknowledgement — where the policy allows it.
 *  - D-223, D-224: an account outside the captain's country is refused until they ask and the team approves.
 *  - D-299: an emergency freeze stops every withdrawal until the team lifts it.
 */
class DriverPayouts
{
    public function __construct(
        private readonly RidePolicies $policies,
        private readonly NotificationCenter $center,
    ) {}

    /** @return array<string, mixed> what the payout screens show */
    public function status(Driver $driver): array
    {
        $s = $this->settings($driver);
        $policy = $this->policy($driver);

        return [
            'kyc_ok' => $this->kycProblem($driver) === null,
            'kyc_reason' => $this->kycProblem($driver),
            'method_hold_hours' => $policy->int('wallet.driver_new_method_hold_hours'),
            'third_party_allowed' => $policy->bool('wallet.driver_third_party_payout'),
            'international' => [
                'status' => $s->international_status, 'answer' => $s->international_answer,
                'requested_at' => $s->international_requested_at?->toIso8601String(),
            ],
            'frozen' => $s->frozen_at !== null,
            'frozen_at' => $s->frozen_at?->toIso8601String(),
        ];
    }

    /** Saving a payout account. @param array<string, mixed> $attributes */
    public function onMethod(Driver $driver, array $attributes, ?WithdrawalMethod $existing): array
    {
        if (($attributes['type'] ?? null) === 'bank') {
            $data = (array) ($attributes['data'] ?? []);
            $iban = strtoupper(preg_replace('/\s+/', '', (string) ($data['iban'] ?? '')));
            $home = strtoupper((string) $driver->country()->value('code'));
            if ($home !== '' && strlen($iban) >= 2 && substr($iban, 0, 2) !== $home && $this->settings($driver)->international_status !== 'approved') {
                throw new RidesException('payout_international_off', 422);
            }
            if (! $this->sameName((string) ($data['account_holder'] ?? ''), (string) $driver->name)) {
                if (! $this->policy($driver)->bool('wallet.driver_third_party_payout')) {
                    throw new RidesException('payout_third_party_not_allowed', 422);
                }
                if (! request()->boolean('acknowledge_third_party')) {
                    throw new RidesException('payout_third_party_ack', 422);
                }
                $data['third_party_ack_at'] = now()->toIso8601String();
                $attributes['data'] = $data;
            }
        }
        // D-218: told at once — if it wasn't them, they freeze.
        $this->center->send($driver, 'driver.payout_method', 'driver_payout_method_title', 'driver_payout_method_body', [
            'hours' => $this->policy($driver)->int('wallet.driver_new_method_hold_hours'),
        ], ['type' => 'driver_wallet'], true, ['priority' => 10]);

        return $attributes;
    }

    /** Before a withdrawal. */
    public function onWithdrawal(Driver $driver, WithdrawalMethod $method): void
    {
        if ($this->settings($driver)->frozen_at !== null) {
            throw new RidesException('payout_frozen', 423);
        }
        if (($reason = $this->kycProblem($driver)) !== null) {
            throw new RidesException('payout_kyc_required', 403, ['reason' => __('rides.payouts.kyc.'.$reason)]);
        }
        $hold = $this->policy($driver)->int('wallet.driver_new_method_hold_hours');
        $since = $method->updated_at ?? $method->created_at;
        if ($hold > 0 && $since !== null && $since->copy()->addHours($hold)->isFuture()) {
            throw new RidesException('payout_method_cooling', 423, ['hours' => max(1, (int) ceil(now()->diffInMinutes($since->copy()->addHours($hold)) / 60))]);
        }
    }

    /** D-224: ask for international payouts. */
    public function requestInternational(Driver $driver, string $reason): DriverPayoutSetting
    {
        $s = $this->settings($driver);
        if (in_array($s->international_status, ['pending', 'approved'], true)) {
            throw new RidesException('payout_international_already', 409);
        }
        $s->forceFill(['international_status' => 'pending', 'international_reason' => mb_substr($reason, 0, 500), 'international_requested_at' => now(), 'international_answer' => null])->save();

        return $s;
    }

    public function answerInternational(Driver $driver, bool $approve, ?string $answer): DriverPayoutSetting
    {
        $s = $this->settings($driver);
        $s->forceFill(['international_status' => $approve ? 'approved' : 'rejected', 'international_answer' => $answer, 'international_answered_at' => now()])->save();
        $this->center->send($driver, 'driver.payout', $approve ? 'driver_intl_approved_title' : 'driver_intl_rejected_title', 'driver_intl_body', ['answer' => (string) $answer], ['type' => 'driver_wallet']);

        return $s;
    }

    /** D-299: freeze (a lost phone) / lift. */
    public function freeze(Driver $driver, string $reason): void
    {
        $this->settings($driver)->forceFill(['frozen_at' => now(), 'frozen_reason' => mb_substr($reason, 0, 160)])->save();
        // Every session ends — whoever has the phone is signed out.
        rescue(fn () => $driver->tokens()->delete(), report: false);
    }

    public function unfreeze(Driver $driver): void
    {
        $this->settings($driver)->forceFill(['frozen_at' => null, 'frozen_reason' => null])->save();
        $this->center->send($driver, 'driver.payout', 'driver_unfrozen_title', 'driver_unfrozen_body', [], ['type' => 'driver_wallet']);
    }

    public function settings(Driver $driver): DriverPayoutSetting
    {
        return DriverPayoutSetting::query()->firstOrCreate(['driver_id' => $driver->id]);
    }

    private function kycProblem(Driver $driver): ?string
    {
        $status = $driver->status instanceof \BackedEnum ? $driver->status->value : $driver->status;
        return match (true) {
            $driver->activated_at === null => 'not_activated',
            $status !== DriverStatus::Active->value => 'not_active',
            default => null,
        };
    }

    /** Loose: the same words in any order, case and spacing aside (Arabic letter forms evened out). */
    private function sameName(string $holder, string $name): bool
    {
        $norm = fn (string $s) => collect(preg_split('/\s+/u', Str::lower(strtr(trim($s), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي'])), -1, PREG_SPLIT_NO_EMPTY));
        $a = $norm($holder);
        $b = $norm($name);
        if ($a->isEmpty() || $b->isEmpty()) {
            return false;
        }

        return $a->intersect($b)->count() >= min(2, $b->count());
    }

    private function policy(Driver $driver): \Modules\Rides\Services\Policy\RidePolicyData
    {
        return $this->policies->for((int) ($driver->country_id ?? 0) ?: (int) \App\Models\Country::query()->value('id'));
    }
}
