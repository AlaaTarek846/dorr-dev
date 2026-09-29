<?php

namespace App\Services\General;

use App\Http\Resources\General\MobileAppColorDefaultResource;
use App\Repositories\General\MobileAppColorDefaultRepository;
use App\Support\Api\ApiResponse;
use App\Support\Mobile\MobileColorTokens;
use Illuminate\Http\JsonResponse;

class MobileAppColorDefaultService
{
    public function __construct(
        protected MobileAppColorDefaultRepository $repository,
    ) {}

    public function getSettings(): JsonResponse
    {
        return ApiResponse::success(
            new MobileAppColorDefaultResource($this->repository->active()),
            __('api.retrieved'),
        );
    }

    /** Public defaults for pre-login mobile (Splash, Login, OTP). */
    public function publicDefaults(): JsonResponse
    {
        $record = $this->repository->active();
        $storedLight = is_array($record->light_tokens) ? $record->light_tokens : [];
        $storedDark = is_array($record->dark_tokens) ? $record->dark_tokens : [];

        return ApiResponse::success([
            'light_tokens' => MobileColorTokens::defaultsFromStoredLight($storedLight),
            'dark_tokens' => MobileColorTokens::defaultsFromStoredDark($storedDark),
        ], __('api.retrieved'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(array $data): JsonResponse
    {
        $record = $this->repository->active();

        $light = array_key_exists('light_tokens', $data)
            ? MobileColorTokens::normalize($data['light_tokens'])
            : MobileColorTokens::normalize($record->light_tokens ?? []);

        $dark = array_key_exists('dark_tokens', $data)
            ? MobileColorTokens::normalize($data['dark_tokens'])
            : MobileColorTokens::normalize($record->dark_tokens ?? []);

        $record->update([
            'light_tokens' => array_merge(
                MobileColorTokens::defaultAuthLight(),
                MobileColorTokens::merge(MobileColorTokens::platformDefaultsLight(), $light),
            ),
            'dark_tokens' => array_merge(
                MobileColorTokens::defaultAuthDark(),
                MobileColorTokens::merge(MobileColorTokens::platformDefaultsDark(), $dark),
            ),
            'light_gradients' => null,
            'dark_gradients' => null,
        ]);

        return ApiResponse::success(
            new MobileAppColorDefaultResource($record->fresh()),
            __('api.updated'),
        );
    }
}
