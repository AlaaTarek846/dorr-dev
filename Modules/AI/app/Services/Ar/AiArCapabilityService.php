<?php

namespace Modules\AI\Services\Ar;

use Modules\AI\Models\Ar\AiArDeviceProfile;

/**
 * Decides how much AR a device gets. The phone reports what ARCore says about it; a tested-device row can only
 * LOWER that (a device we found unreliable), never raise it. Anything below full AR still gets the 3D viewer.
 */
class AiArCapabilityService
{
    /**
     * @param  array{manufacturer?: ?string, model?: ?string, arcore_supported?: bool, depth_supported?: bool, platform?: ?string}  $device
     * @return array{tier: string, ar_available: bool, fallback: string|null, matched_profile: bool}
     */
    public function evaluate(array $device): array
    {
        $tier = ! empty($device['arcore_supported'])
            ? (! empty($device['depth_supported']) ? AiArDeviceProfile::TIER_FULL : AiArDeviceProfile::TIER_BASIC)
            : AiArDeviceProfile::TIER_FALLBACK;

        $profile = $this->profileFor($device);

        if ($profile !== null) {
            $order = [AiArDeviceProfile::TIER_FALLBACK => 0, AiArDeviceProfile::TIER_BASIC => 1, AiArDeviceProfile::TIER_FULL => 2];
            $limit = $profile->arcore_supported ? $profile->tier : AiArDeviceProfile::TIER_FALLBACK;

            if ($order[$limit] < $order[$tier]) {
                $tier = $limit;
            }
        }

        return [
            'tier' => $tier,
            'ar_available' => $tier !== AiArDeviceProfile::TIER_FALLBACK,
            'fallback' => $tier === AiArDeviceProfile::TIER_FALLBACK ? 'viewer_3d' : null,
            'matched_profile' => $profile !== null,
        ];
    }

    protected function profileFor(array $device): ?AiArDeviceProfile
    {
        $model = (string) ($device['model'] ?? '');

        if ($model === '') {
            return null;
        }

        return AiArDeviceProfile::query()
            ->where('platform', $device['platform'] ?? 'android')
            ->whereRaw('LOWER(manufacturer) = ?', [mb_strtolower((string) ($device['manufacturer'] ?? ''))])
            ->whereRaw('? LIKE model_pattern', [$model])
            ->first();
    }
}
