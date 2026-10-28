<?php

namespace Modules\AI\Http\Controllers\World;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\AI\Http\Resources\World\AiWorldLocationResource;
use Modules\AI\Models\World\AiWorldLocation;
use Modules\AI\Models\World\AiWorldSetting;
use Modules\AI\Services\World\AiWorldAccess;
use Modules\AI\Services\World\Location\AiWorldLocationEngine;

/** Customer side of DORR AI WORLD: availability and place search. Shared by the user and provider apps. */
class AiWorldController extends Controller
{
    public function __construct(
        protected AiWorldAccess $access,
        protected AiWorldLocationEngine $locations,
    ) {}

    /** Whether the service and each mode are on for the caller's country, the limits, and the fidelity levels the app explains. */
    public function info()
    {
        $setting = AiWorldSetting::current();

        return ApiResponse::success([
            'enabled' => $this->access->enabled(),
            'modes' => $this->access->modes(),
            'limits' => [
                'max_scenes' => $setting->max_scenes_per_project,
                'scene_seconds' => array_values((array) config('ai.world.durations', [4, 6, 8])),
            ],
            'fidelity_levels' => collect([
                AiWorldLocation::TIER_CINEMATIC,
                AiWorldLocation::TIER_REFERENCE_BASED,
                AiWorldLocation::TIER_INTERACTIVE_3D,
            ])->map(fn (string $tier) => [
                'tier' => $tier,
                'label' => __('ai.world_tier_'.$tier),
                'description' => __('ai.world_tier_'.$tier.'_desc'),
            ])->all(),
        ]);
    }

    public function searchLocations(Request $request)
    {
        $this->access->assertEnabled();

        $language = app()->getLocale() === 'ar' ? 'ar' : 'en';
        $limit = (int) $request->query('limit', 8);

        $results = $this->locations->search((string) $request->query('q', ''), $language, $limit > 0 ? $limit : 8);

        return ApiResponse::success(AiWorldLocationResource::collection($results)->resolve());
    }

    public function showLocation(int $id)
    {
        $this->access->assertEnabled();

        return ApiResponse::success((new AiWorldLocationResource(AiWorldLocation::query()->findOrFail($id)))->resolve());
    }
}
