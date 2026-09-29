<?php

namespace Modules\User\Services;

use App\Http\Resources\General\MobileAppFontResource;
use App\Models\MobileAppFont;
use App\Repositories\General\MobileAppColorDefaultRepository;
use App\Repositories\General\MobileAppFontRepository;
use App\Support\Api\ApiResponse;
use App\Support\Mobile\MobileColorTokens;
use Illuminate\Http\JsonResponse;
use Modules\User\Models\User;
use Modules\User\Repositories\UserMobileAppearanceRepository;

class MobileAppearanceService
{
    public function __construct(
        protected UserMobileAppearanceRepository $appearances,
        protected MobileAppColorDefaultRepository $colorDefaults,
        protected MobileAppFontRepository $fonts,
    ) {}

    public function show(User $user): JsonResponse
    {
        return ApiResponse::success($this->resolvePayload($user), __('api.retrieved'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): JsonResponse
    {
        $attributes = [];

        if (array_key_exists('uses_default_colors', $data)) {
            $attributes['uses_default_colors'] = filter_var($data['uses_default_colors'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('custom_light_tokens', $data)) {
            $attributes['custom_light_tokens'] = MobileColorTokens::normalizeUserOverrides($data['custom_light_tokens'] ?? []);
        }

        if (array_key_exists('custom_dark_tokens', $data)) {
            $attributes['custom_dark_tokens'] = MobileColorTokens::normalizeUserOverrides($data['custom_dark_tokens'] ?? []);
        }

        if (array_key_exists('mobile_app_font_id', $data)) {
            $attributes['mobile_app_font_id'] = $data['mobile_app_font_id'];
        }

        if (array_key_exists('dark_mode', $data)) {
            $attributes['dark_mode'] = $data['dark_mode'];
        }

        if ($attributes !== []) {
            $this->appearances->upsertForUser($user, $attributes);
        }

        return ApiResponse::success($this->resolvePayload($user->fresh()), __('api.updated'));
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvePayload(User $user): array
    {
        $defaults = $this->colorDefaults->active();
        $appearance = $this->appearances->forUser($user);

        $storedLight = is_array($defaults->light_tokens) ? $defaults->light_tokens : [];
        $storedDark = is_array($defaults->dark_tokens) ? $defaults->dark_tokens : [];

        $baseLight = MobileColorTokens::defaultsFromStoredLight($storedLight);
        $baseDark = MobileColorTokens::defaultsFromStoredDark($storedDark);

        $usesDefault = $appearance === null || $appearance->uses_default_colors;

        $resolvedLight = $usesDefault
            ? $baseLight
            : MobileColorTokens::merge($baseLight, $appearance->custom_light_tokens ?? []);

        $resolvedDark = $usesDefault
            ? $baseDark
            : MobileColorTokens::merge($baseDark, $appearance->custom_dark_tokens ?? []);

        $font = $this->resolveFont($appearance?->mobile_app_font_id);

        return [
            'uses_default_colors' => $usesDefault,
            'custom_light_tokens' => $appearance?->custom_light_tokens ?? null,
            'custom_dark_tokens' => $appearance?->custom_dark_tokens ?? null,
            'dark_mode' => $appearance?->dark_mode ?? 'system',
            'mobile_app_font_id' => $appearance?->mobile_app_font_id,
            'available_fonts' => $this->availableFonts($font),
            'customizable_token_keys' => MobileColorTokens::userCustomizableKeys(),
            'default' => [
                'light_tokens' => $baseLight,
                'dark_tokens' => $baseDark,
            ],
            'resolved' => [
                'light_tokens' => $resolvedLight,
                'dark_tokens' => $resolvedDark,
            ],
            'font' => $font !== null ? (new MobileAppFontResource($font))->resolve() : null,
        ];
    }

    protected function resolveFont(?int $fontId): ?MobileAppFont
    {
        if ($fontId !== null) {
            $chosen = MobileAppFont::query()
                ->where('id', $fontId)
                ->where('status', true)
                ->with(['translations', 'translation', 'media'])
                ->first();

            if ($chosen !== null) {
                return $chosen;
            }
        }

        return $this->fonts->defaultFont()?->load(['translations', 'translation', 'media']);
    }

    /**
     * Every font the app may offer, in the order the picker shows them. The font in use comes
     * first so it is highlighted even when the viewer has scrolled away from the default.
     *
     * @return list<array<string, mixed>>
     */
    protected function availableFonts(?MobileAppFont $inUse): array
    {
        $fonts = MobileAppFont::query()
            ->where('status', true)
            ->with(['translations', 'translation', 'media'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $fonts->map(fn (MobileAppFont $font) => (new MobileAppFontResource($font))->resolve())->all();
    }
}
