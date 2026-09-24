<?php

namespace App\Http\Controllers\General\Public;

use App\Http\Controllers\Controller;
use App\Services\General\CountryService;
use App\Services\General\LanguageService;
use App\Services\General\PlatformSettingService;
use Illuminate\Http\JsonResponse;

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
}
