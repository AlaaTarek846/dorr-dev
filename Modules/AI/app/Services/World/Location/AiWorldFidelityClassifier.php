<?php

namespace Modules\AI\Services\World\Location;

use Modules\AI\Models\World\AiWorldLocation;

/**
 * Decides what the user is told to expect for a place. No place is ever promised a faithful reconstruction:
 * only places with a known landmark reference get "reference based", and "interactive 3D" needs real 3D data
 * for that exact place plus the feature being switched on.
 */
class AiWorldFidelityClassifier
{
    public const REASON_TILES = 'tiles_available';

    public const REASON_CURATED = 'curated_landmark';

    public const REASON_GENERIC = 'generic';

    /** @return array{tier: string, reason: string} */
    public function classify(AiWorldLocation $location): array
    {
        if ($location->tiles_available && (bool) config('ai.world.tiles_enabled', false)) {
            return ['tier' => AiWorldLocation::TIER_INTERACTIVE_3D, 'reason' => self::REASON_TILES];
        }

        if ($location->is_curated) {
            return ['tier' => AiWorldLocation::TIER_REFERENCE_BASED, 'reason' => self::REASON_CURATED];
        }

        return ['tier' => AiWorldLocation::TIER_CINEMATIC, 'reason' => self::REASON_GENERIC];
    }

    public function apply(AiWorldLocation $location): AiWorldLocation
    {
        $verdict = $this->classify($location);

        $location->forceFill(['fidelity_tier' => $verdict['tier'], 'tier_reason' => $verdict['reason']]);

        return $location;
    }
}
