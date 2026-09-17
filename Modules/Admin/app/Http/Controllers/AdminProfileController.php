<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Admin\Http\Requests\AdminProfilePasswordRequest;
use Modules\Admin\Http\Requests\AdminProfileUpdateRequest;
use Modules\Admin\Http\Resources\AdminResource;
use Modules\Admin\Models\Admin;

class AdminProfileController extends Controller
{
    public function update(AdminProfileUpdateRequest $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin_api');
        $validated = $request->validated();

        if ($request->boolean('remove_avatar')) {
            $admin->clearMediaCollection('avatar');
        }

        if ($request->hasFile('avatar')) {
            $admin->setSingleMedia('avatar', $request->file('avatar'));
        }

        $admin->update(collect($validated)->except(['avatar', 'remove_avatar'])->all());
        $admin->load(['country.flag']);

        return ApiResponse::success(
            new AdminResource($admin),
            __('api.updated'),
        );
    }

    public function updatePassword(AdminProfilePasswordRequest $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin_api');

        $admin->update([
            'password' => $request->validated('password'),
        ]);

        return ApiResponse::success([], __('api.password_updated'));
    }
}
