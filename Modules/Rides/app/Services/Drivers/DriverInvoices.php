<?php

namespace Modules\Rides\Services\Drivers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Models\Ride;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Models\WalletTransaction;

/**
 * The captain's monthly statement of DORR's service (docs/rides_tasks.md D-227, D-228): what the riders paid for
 * the captain's trips, DORR's commission and its tax kept apart, the captain's net — and the credit / debit notes
 * that adjusted it (a coupon DORR compensated, a refund, a manual correction, a penalty), each linked to its month.
 * Read from the rides and the wallet; nothing new is stored.
 */
class DriverInvoices
{
    /** @return Collection<int, array<string, mixed>> newest month first */
    public function months(Driver $driver, int $limit = 12): Collection
    {
        return collect(range(0, $limit - 1))
            ->map(fn (int $i) => now()->startOfMonth()->subMonths($i))
            ->map(fn (Carbon $m) => $this->summary($driver, $m))
            ->filter(fn (array $s) => $s['trips'] > 0 || $s['notes_count'] > 0)
            ->values();
    }

    /** @return array<string, mixed> */
    public function month(Driver $driver, string $ym): array
    {
        $m = Carbon::createFromFormat('Y-m', $ym)->startOfMonth();
        $rides = $this->rides($driver, $m)->get(['id', 'number', 'completed_at', 'final_total_minor', 'commission_minor', 'tax_minor', 'driver_net_minor', 'currency_id']);

        return $this->summary($driver, $m) + [
            'lines' => $rides->map(fn (Ride $r) => [
                'ride_number' => $r->number, 'date' => $r->completed_at?->toIso8601String(),
                'fare_minor' => (int) $r->final_total_minor, 'commission_minor' => (int) $r->commission_minor,
                'tax_minor' => (int) $r->tax_minor, 'net_minor' => (int) $r->driver_net_minor,
            ])->values(),
            'notes' => $this->notes($driver, $m)->map(fn (WalletTransaction $t) => [
                'kind' => $t->direction?->value === 'credit' ? 'credit' : 'debit',
                'type' => $t->type?->value, 'amount_minor' => (int) $t->amount_minor,
                'title' => is_array($t->notes) && isset($t->notes['key']) ? __($t->notes['key'], (array) ($t->notes['variables'] ?? [])) : null,
                'date' => $t->created_at?->toIso8601String(), 'reference' => $t->uuid,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(Driver $driver, Carbon $m): array
    {
        $rides = $this->rides($driver, $m);
        $notes = $this->notes($driver, $m);

        return [
            'month' => $m->format('Y-m'),
            'number' => 'DRV-'.$driver->id.'-'.$m->format('Ym'),
            'trips' => (clone $rides)->count(),
            'fares_minor' => (int) (clone $rides)->sum('final_total_minor'),
            'commission_minor' => (int) (clone $rides)->sum('commission_minor'),
            'tax_minor' => (int) (clone $rides)->sum('tax_minor'),
            'net_minor' => (int) (clone $rides)->sum('driver_net_minor'),
            'credit_notes_minor' => (int) $notes->filter(fn ($t) => $t->direction?->value === 'credit')->sum('amount_minor'),
            'debit_notes_minor' => (int) $notes->filter(fn ($t) => $t->direction?->value !== 'credit')->sum('amount_minor'),
            'notes_count' => $notes->count(),
        ];
    }

    private function rides(Driver $driver, Carbon $m)
    {
        return Ride::query()->where('driver_id', $driver->id)->where('status', RideStatus::Completed->value)
            ->whereBetween('completed_at', [$m->copy(), $m->copy()->endOfMonth()]);
    }

    /** @return Collection<int, WalletTransaction> */
    private function notes(Driver $driver, Carbon $m): Collection
    {
        return WalletTransaction::query()
            ->whereHas('wallet', fn ($q) => $q->where('owner_type', 'driver')->where('owner_id', $driver->id))
            ->whereIn('type', [WalletTransactionType::DiscountCompensation->value, WalletTransactionType::Refund->value, WalletTransactionType::ManualAdjustment->value, WalletTransactionType::Penalty->value, WalletTransactionType::Reversal->value])
            ->whereBetween('created_at', [$m->copy(), $m->copy()->endOfMonth()])
            ->orderBy('id')->get();
    }
}
