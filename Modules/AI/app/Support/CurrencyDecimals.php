<?php

namespace Modules\AI\Support;

use App\Models\Currency;

/**
 * Minor units are stored per currency (currencies.decimal_places: 2 for EGP / SAR, 3 for KWD / BHD / OMR...). Admin
 * screens that show *_minor amounts get this map next to the rows so they divide by the right power of ten instead of
 * a fixed 100.
 */
final class CurrencyDecimals
{
    /**
     * @param  iterable<mixed>  $codes
     * @return array<string, int> currency code => decimal places (only the codes that exist)
     */
    public static function for(iterable $codes): array
    {
        $wanted = collect($codes)->filter(fn ($c) => is_string($c) && $c !== '')->map(fn (string $c) => strtoupper($c))->unique()->values();

        if ($wanted->isEmpty()) {
            return [];
        }

        return Currency::query()->withTrashed()->whereIn('code', $wanted->all())->get(['code', 'decimal_places'])
            ->mapWithKeys(fn (Currency $c) => [strtoupper((string) $c->code) => (int) ($c->decimal_places ?? 2)])
            ->all();
    }
}
