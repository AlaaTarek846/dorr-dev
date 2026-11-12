<?php

namespace Modules\Freelance\Services\Home;

use Modules\Freelance\Models\FreelancePromo as Promo;

/**
 * The promo banners an app's home shows now (the freelancer app's home, or the client's freelance hub in the Dorr user app): on, between
 * their dates, in the country or everywhere, for that app or both, and — when a banner opens a page — only while the page is open in the
 * country.
 */
class HomePromos
{
    /**
     * @param  array<string, bool>  $on  the country's features
     * @return list<array<string, mixed>>
     */
    public function for(string $app, ?int $countryId, array $on): array
    {
        $screens = $app === Promo::CLIENT ? Promo::CLIENT_SCREENS : Promo::SCREENS;

        return Promo::query()->for($app)->showing($countryId)->with('media')->orderBy('sort_order')->orderByDesc('id')
            ->limit((int) config('freelance.home.promos_max', 6))->get()
            ->filter(function (Promo $p) use ($on, $screens) {
                if ($p->target_type !== Promo::SCREEN) {
                    return true;
                }
                $needs = $screens[$p->target] ?? false;

                return $needs === null || ($needs !== false && ($on[$needs] ?? false));
            })
            ->map(fn (Promo $p) => [
                'uuid' => $p->uuid,
                'title' => $p->text('title'),
                'body' => $p->text('body'),
                'cta' => $p->text('cta'),
                'image_path' => $p->imagePath(),
                'target_type' => $p->target_type,
                'target' => $p->target_type === Promo::NONE ? null : $p->target,
            ])->values()->all();
    }
}
