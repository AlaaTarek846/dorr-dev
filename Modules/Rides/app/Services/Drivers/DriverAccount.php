<?php

namespace Modules\Rides\Services\Drivers;

use App\Enums\LegalPageType;
use App\Models\LegalPage;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Support\Facades\DB;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Wallet\Enums\WithdrawalRequestStatus;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Models\WithdrawalRequest;
use Modules\Wallet\Services\PinService;

/**
 * The captain's account (docs/rides_tasks.md D-314, D-316..D-318, D-226):
 *
 *  - D-314: a new number — the wallet PIN when there is one, a code sent to the new number, and the old number told.
 *  - D-316, D-226: closing the account — only once nothing is owed either way (money in the wallet withdrawn, no debt,
 *    no withdrawal waiting, no trip running, no case open); what blocks it is listed so the captain can settle it.
 *  - D-317, D-318: the terms and privacy in force, with their version; a newer version than the one accepted asks
 *    for acceptance again before going online.
 */
class DriverAccount
{
    private const CODE_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly NotificationCenter $center) {}

    // ---------------------------------------------------------------- D-314: the number

    public function startPhoneChange(Driver $driver, string $newPhone, ?string $pin): void
    {
        $pins = app(PinService::class);
        if ($pins->has($driver)) {
            if (! is_string($pin) || $pin === '') {
                throw new RidesException('account_pin_required', 403);
            }
            $pins->verify($driver, $pin);
        }
        if ($newPhone === $driver->phone) {
            throw new RidesException('account_same_phone', 422);
        }
        if (Driver::query()->where('phone', $newPhone)->where('id', '!=', $driver->id)->exists()) {
            throw new RidesException('account_phone_taken', 422);
        }
        DB::table('driver_phone_changes')->updateOrInsert(['driver_id' => $driver->id], [
            'new_phone' => $newPhone, 'code' => (string) config('auth_flow.phone_otp_fixed', '1234'), 'attempts' => 0,
            'expires_at' => now()->addMinutes(self::CODE_MINUTES), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function confirmPhoneChange(Driver $driver, string $code): Driver
    {
        $row = DB::table('driver_phone_changes')->where('driver_id', $driver->id)->first() ?? throw new RidesException('account_no_change', 422);
        if (now()->gt($row->expires_at)) {
            DB::table('driver_phone_changes')->where('driver_id', $driver->id)->delete();
            throw new RidesException('account_code_expired', 422);
        }
        if ($row->attempts >= self::MAX_ATTEMPTS) {
            throw new RidesException('account_code_attempts', 429);
        }
        if (! hash_equals((string) $row->code, $code)) {
            DB::table('driver_phone_changes')->where('driver_id', $driver->id)->increment('attempts');
            throw new RidesException('account_code_wrong', 422);
        }
        if (Driver::query()->where('phone', $row->new_phone)->where('id', '!=', $driver->id)->exists()) {
            throw new RidesException('account_phone_taken', 422);
        }
        $old = $driver->phone;
        DB::transaction(function () use ($driver, $row) {
            $driver->forceFill(['phone' => $row->new_phone, 'phone_verified_at' => now()])->save();
            DB::table('driver_phone_changes')->where('driver_id', $driver->id)->delete();
        });
        $this->center->send($driver, 'driver.account', 'driver_phone_changed_title', 'driver_phone_changed_body', ['old' => substr((string) $old, -4)], ['type' => 'driver_account'], true, ['priority' => 10]);

        return $driver->refresh();
    }

    // ---------------------------------------------------------------- D-316, D-226: closing

    /** @return list<array{code: string, label: string}> what stands in the way of closing */
    public function closeBlockers(Driver $driver): array
    {
        $out = [];
        $wallets = Wallet::query()->where('owner_type', 'driver')->where('owner_id', $driver->id)->get();
        if ($wallets->contains(fn (Wallet $w) => (int) $w->withdrawable_minor + (int) $w->spend_only_minor > 0)) {
            $out[] = 'balance';
        }
        if ($wallets->contains(fn (Wallet $w) => (int) $w->withdrawable_minor < 0)) {
            $out[] = 'debt';
        }
        if (WithdrawalRequest::query()->whereIn('wallet_id', $wallets->pluck('id'))->where('status', WithdrawalRequestStatus::Pending)->exists()) {
            $out[] = 'withdrawal_pending';
        }
        if (Ride::query()->where('driver_id', $driver->id)->whereIn('status', [RideStatus::Accepted->value, RideStatus::Arrived->value, RideStatus::InProgress->value])->exists()) {
            $out[] = 'trip_running';
        }
        if (DB::getSchemaBuilder()->hasTable('ride_settlement_items') && DB::table('ride_settlement_items')->where('driver_id', $driver->id)->where('status', 'pending')->exists()) {
            $out[] = 'earnings_pending';
        }
        if (DB::getSchemaBuilder()->hasTable('ride_cases') && DB::table('ride_cases')->where('reporter_type', 'driver')->where('reporter_id', $driver->id)->whereIn('status', ['submitted', 'in_review', 'needs_info'])->exists()) {
            $out[] = 'case_open';
        }

        return array_map(fn (string $c) => ['code' => $c, 'label' => __('rides.account.blockers.'.$c)], $out);
    }

    public function close(Driver $driver, string $reason): void
    {
        if ($this->closeBlockers($driver) !== []) {
            throw new RidesException('account_close_blocked', 409);
        }
        $driver->forceFill(['closed_at' => now(), 'closed_reason' => mb_substr($reason, 0, 300), 'status' => \Modules\Driver\Enums\DriverStatus::Blocked, 'status_reason' => 'closed_by_driver'])->save();
        $driver->tokens()->delete();
    }

    // ---------------------------------------------------------------- D-317, D-318: terms

    /** @return list<array<string, mixed>> the pages in force, their version, and whether this captain accepted it */
    public function legal(Driver $driver): array
    {
        $out = [];
        foreach ([LegalPageType::Term, LegalPageType::Privacy] as $type) {
            $page = LegalPage::query()->where('type', $type->value)->whereNull('service_id')->where('status', true)->with('translations')->latest('updated_at')->first();
            if ($page === null) {
                continue;
            }
            $version = $page->updated_at?->format('YmdHis') ?? (string) $page->id;
            $accepted = DB::table('driver_consents')->where('driver_id', $driver->id)->where('type', $type->value)->orderByDesc('accepted_at')->first();
            $out[] = [
                'type' => $type->value, 'version' => $version, 'updated_at' => $page->updated_at?->toIso8601String(),
                'title' => __('rides.account.legal.'.$type->value), 'content' => $page->translated('content'),
                'accepted_version' => $accepted?->version, 'accepted_at' => $accepted?->accepted_at,
                'needs_acceptance' => $accepted === null || $accepted->version !== $version,
            ];
        }

        return $out;
    }

    /** @param list<string> $types */
    public function accept(Driver $driver, array $types): void
    {
        foreach ($this->legal($driver) as $page) {
            if (in_array($page['type'], $types, true)) {
                DB::table('driver_consents')->updateOrInsert(
                    ['driver_id' => $driver->id, 'type' => $page['type'], 'version' => $page['version']],
                    ['locale' => app()->getLocale(), 'accepted_at' => now()],
                );
            }
        }
    }
}
