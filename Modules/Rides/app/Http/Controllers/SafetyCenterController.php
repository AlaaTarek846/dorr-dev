<?php

namespace Modules\Rides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Rides\Services\Safety\SafetyCenterService;
use Modules\User\Models\User;

/**
 * The Safety Center screen in both apps (R-219, D-140): trusted contacts, safety preferences, who
 * they won't be matched with, favourite captains. Rider: `mobile/v1/rides/safety…`; captain:
 * `driver/v1/safety…`.
 */
class SafetyCenterController extends Controller
{
    public function show(Request $request, SafetyCenterService $center): JsonResponse
    {
        return ApiResponse::success($center->overview($request->user()), __('api.retrieved'));
    }

    public function storeContact(Request $request, SafetyCenterService $center): JsonResponse
    {
        $center->saveContact($request->user(), $this->contactData($request));

        return ApiResponse::success($center->contacts($request->user()), __('api.created'), 201);
    }

    public function updateContact(Request $request, int $id, SafetyCenterService $center): JsonResponse
    {
        $center->saveContact($request->user(), $this->contactData($request), $id);

        return ApiResponse::success($center->contacts($request->user()), __('api.updated'));
    }

    public function destroyContact(Request $request, int $id, SafetyCenterService $center): JsonResponse
    {
        $center->deleteContact($request->user(), $id);

        return ApiResponse::success($center->contacts($request->user()), __('api.deleted'));
    }

    public function preferences(Request $request, SafetyCenterService $center): JsonResponse
    {
        $data = $request->validate([
            'pin_mode' => ['sometimes', Rule::in(['policy', 'always', 'night'])],
            'checkin_after_min' => ['sometimes', 'nullable', 'integer', 'between:5,240'],
            'seatbelt_reminder' => ['sometimes', 'boolean'],
        ]);
        $center->savePreferences($request->user(), $data);

        return ApiResponse::success($center->overview($request->user())['preferences'], __('api.updated'));
    }

    public function unblock(Request $request, int $id, SafetyCenterService $center): JsonResponse
    {
        $center->unblock($request->user(), $id);

        return ApiResponse::success($center->blocked($request->user()), __('api.deleted'));
    }

    public function unfavorite(Request $request, int $driver, SafetyCenterService $center): JsonResponse
    {
        /** @var User $rider */
        $rider = $request->user();
        $center->unfavorite($rider, $driver);

        return ApiResponse::success($center->favorites($rider), __('api.deleted'));
    }

    /** @return array{name: string, phone: string, share_mode?: string, notify_arrival?: bool} */
    private function contactData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9 ]{8,18}$/'],
            'share_mode' => ['sometimes', Rule::in(['all', 'night', 'manual'])],
            'notify_arrival' => ['sometimes', 'boolean'],
        ]);
    }
}
