<?php

namespace App\Http\Controllers\General\Public;

use App\Http\Controllers\Controller;
use App\Services\General\CountryService;
use App\Services\General\LanguageService;
use App\Services\General\PlatformSettingService;
use App\Services\General\MobileAppColorDefaultService;
use App\Services\General\ServiceCategoryService;
use App\Services\General\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public General API — no authentication (mobile onboarding, pre-login SPA).
 *
 * Delegates to existing General services; admin/user catalog controllers stay unchanged.
 */
class GeneralController extends Controller
{
    public function __construct(
        protected CountryService $countryService,
        protected LanguageService $languageService,
        protected PlatformSettingService $platformSettingService,
        protected ServiceCategoryService $serviceCategoryService,
        protected MobileAppColorDefaultService $mobileAppColorDefaultService,
        protected TranslationService $translationService,
    ) {}

    public function countriesDropdown(): JsonResponse
    {
        return $this->countryService->dropdown();
    }

    public function countriesDetect(): JsonResponse
    {
        return $this->countryService->detect();
    }

    public function languagesDropdown(): JsonResponse
    {
        return $this->languageService->dropdown();
    }

    public function platformBranding(): JsonResponse
    {
        return $this->platformSettingService->getBranding();
    }

    public function services(): JsonResponse
    {
        return $this->serviceCategoryService->publicList();
    }

    public function mobileAppearanceDefaults(): JsonResponse
    {
        return $this->mobileAppColorDefaultService->publicDefaults();
    }

    public function translationLanguages(Request $request): JsonResponse
    {
        $platform = $request->query('platform');

        return $this->translationService->interfaceLanguages(is_string($platform) ? $platform : null);
    }

    public function vueTranslations(string $code): JsonResponse
    {
        return $this->translationService->vueMessages($code);
    }

    public function androidTranslations(string $code): JsonResponse
    {
        return $this->translationService->androidStrings($code);
    }
}
