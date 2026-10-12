<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Services\ChatFeatures;

/**
 * What the app may offer where the person is (the app hides the rest), and the phone telling
 * the server where it is — kept as the nearest city only.
 */
class ChatFeatureController extends Controller
{
    public function __construct(private readonly ChatFeatures $features) {}

    public function show(Request $request)
    {
        return ApiResponse::success($this->features->forAccount($request->user()), __('api.retrieved'));
    }

    public function locate(Request $request)
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $this->features->locate($request->user(), (float) $data['latitude'], (float) $data['longitude']);

        return ApiResponse::success($this->features->forAccount($request->user()), __('api.updated'));
    }
}
