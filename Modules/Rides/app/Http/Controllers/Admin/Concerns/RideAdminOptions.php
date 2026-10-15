<?php

namespace Modules\Rides\Http\Controllers\Admin\Concerns;

use App\Models\Country;
use Illuminate\Support\Collection;

/** The countries and (Discover's) cities every rides admin form picks from. */
trait RideAdminOptions
{
    /** @return Collection<int, array{id: int, code: string, name: string}> */
    protected function countries(): Collection
    {
        return Country::query()->with('translations')->where('status', true)->get()
            ->map(fn (Country $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code])->values();
    }

    /** @return Collection<int, array{id: int, country_id: int, name: ?string}> */
    protected function cities(): Collection
    {
        $class = 'Modules\\Discover\\Models\\DiscoverCity';
        if (! class_exists($class)) {
            return collect();
        }

        return $class::query()->with('translations')->orderBy('sort_order')->get()
            ->map(fn ($c) => ['id' => (int) $c->id, 'country_id' => (int) $c->country_id, 'name' => $c->translatedName()])->values();
    }
}
