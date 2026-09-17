<?php

namespace Modules\Provider\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Provider\Http\Requests\ProviderProfilePasswordRequest;
use Modules\Provider\Http\Requests\ProviderProfileUpdateRequest;
use Modules\Provider\Http\Resources\ProviderResource;
use Modules\Provider\Models\Provider;

class ProviderProfileController extends Controller
{
    public function update(ProviderProfileUpdateRequest $request): JsonResponse
    {
        /** @var Provider $provider */
        $provider = $request->user('provider_api');
        $validated = $request->validated();

        if ($request->boolean('remove_avatar')) {
            $provider->clearMediaCollection('avatar');
        }

        if ($request->hasFile('avatar')) {
            $provider->setSingleMedia('avatar', $request->file('avatar'));
        }

        $provider->update(collect($validated)->except(['avatar', 'remove_avatar'])->all());
        $provider->load(['country.flag']);

        return ApiResponse::success(
            new ProviderResource($provider),
            __('api.updated'),
        );
    }

    public function updatePassword(ProviderProfilePasswordRequest $request): JsonResponse
    {
        /** @var Provider $provider */
        $provider = $request->user('provider_api');

        $provider->update([
            'password' => $request->validated('password'),
        ]);

        return ApiResponse::success([], __('api.password_updated'));
    }
}
