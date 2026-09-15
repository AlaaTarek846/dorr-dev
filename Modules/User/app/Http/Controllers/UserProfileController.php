<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\User\Http\Requests\UserProfilePasswordRequest;
use Modules\User\Http\Requests\UserProfileUpdateRequest;
use Modules\User\Http\Resources\UserResource;
use Modules\User\Models\User;

class UserProfileController extends Controller
{
    public function update(UserProfileUpdateRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');
        $validated = $request->validated();

        if ($request->boolean('remove_avatar')) {
            $user->clearMediaCollection('avatar');
        }

        if ($request->hasFile('avatar')) {
            $user->setSingleMedia('avatar', $request->file('avatar'));
        }

        $user->update(collect($validated)->except(['avatar', 'remove_avatar'])->all());
        $user->load(['country.flag']);

        return ApiResponse::success(
            new UserResource($user),
            __('api.updated'),
        );
    }

    public function updatePassword(UserProfilePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('user_api');

        $user->update([
            'password' => $request->validated('password'),
        ]);

        return ApiResponse::success([], __('api.password_updated'));
    }
}
