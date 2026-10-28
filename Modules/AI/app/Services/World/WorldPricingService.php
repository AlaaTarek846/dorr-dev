<?php

namespace Modules\AI\Services\World;

use Modules\AI\Exceptions\AiWorldException;
use Modules\AI\Models\World\AiWorldPrice;

/** Price of a trip video = generated seconds x the country's price per second for the chosen quality. */
class WorldPricingService
{
    public function pricePerSecond(int $countryId, string $quality): int
    {
        $price = AiWorldPrice::query()->where('country_id', $countryId)->where('quality', $quality)->where('is_active', true)->value('price_per_second_minor');

        if ($price === null) {
            throw new AiWorldException('price_missing', 422);
        }

        return (int) $price;
    }

    /** @param list<int> $sceneSeconds */
    public function estimate(int $countryId, string $quality, array $sceneSeconds): int
    {
        return $this->pricePerSecond($countryId, $quality) * array_sum($sceneSeconds);
    }

    public function sceneAmount(int $countryId, string $quality, int $seconds): int
    {
        return $this->pricePerSecond($countryId, $quality) * $seconds;
    }
}
