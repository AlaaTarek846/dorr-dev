<?php

namespace App\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Http\Requests\General\StoreRatingRequest;
use App\Models\Rating;
use App\Services\General\MobileRatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Models\User;

class MobileRatingController extends Controller
{
    public function __construct(protected MobileRatingService $service) {}

    /**
     * The rating the signed-in user already gave to the app (or to `rateable_type` + `rateable_id`), or null.
     */
    public function mine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'rateable_type' => ['nullable', 'string', 'in:'.implode(',', array_keys(Rating::RATEABLES))],
            'rateable_id' => ['nullable', 'integer', 'required_with:rateable_type'],
        ]);

        /** @var User $user */
        $user = $request->user('user_api');

        return $this->service->mine(
            $user,
            $validated['rateable_type'] ?? null,
            isset($validated['rateable_id']) ? (int) $validated['rateable_id'] : null,
        );
    }

    public function store(StoreRatingRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');

        return $this->service->submit($user, $request->validated());
    }
}
