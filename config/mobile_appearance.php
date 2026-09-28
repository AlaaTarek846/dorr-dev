<?php

/**
 * Mobile app default colors — core tokens only (admin + platform default).
 * Matches DorrTheme / AppColors essentials in androidApp/ui/theme/Color.kt.
 *
 * User overrides (mobile) should use the same keys subset; see user_customizable_token_keys.
 */
return [
    'color_token_keys' => [
        'primary',
        'primaryLight',
        'primaryDark',
        'secondary',
        'accent',
        'danger',
        'success',
        'warning',
        'info',
        'background',
        'surface',
        'textPrimary',
        'textSecondary',
        'textMuted',
        'border',
        'headerBackground',
    ],

    /** Keys end-users may override after login (merge over platform default). */
    'user_customizable_token_keys' => [
        'primary',
        'secondary',
        'background',
        'surface',
        'textPrimary',
    ],

    'default_light_tokens' => [
        'primary' => '#E50914',
        'primaryLight' => '#F2202C',
        'primaryDark' => '#B30710',
        'secondary' => '#111928',
        'accent' => '#FF8A4C',
        'danger' => '#F05252',
        'success' => '#059669',
        'warning' => '#F59E0B',
        'info' => '#06B6D4',
        'background' => '#FFFFFF',
        'surface' => '#FFFFFF',
        'textPrimary' => '#111928',
        'textSecondary' => '#6B7280',
        'textMuted' => '#9CA3AF',
        'border' => '#E5E7EB',
        'headerBackground' => '#000000',
    ],

    'default_dark_tokens' => [
        'primary' => '#2DA8B0',
        'primaryLight' => '#2DA8B0',
        'primaryDark' => '#166064',
        'secondary' => '#0E9F6E',
        'accent' => '#FF8A4C',
        'danger' => '#F05252',
        'success' => '#059669',
        'warning' => '#F59E0B',
        'info' => '#06B6D4',
        'background' => '#111928',
        'surface' => '#1F2937',
        'textPrimary' => '#F9FAFB',
        'textSecondary' => '#9CA3AF',
        'textMuted' => '#9CA3AF',
        'border' => '#4B5563',
        'headerBackground' => '#000000',
    ],

    'dark_mode_values' => ['system', 'light', 'dark'],

    /**
     * Login / OTP / Splash auth chrome (Android LoginScreen, AccountDark, PinkBackdrop).
     * Stored in light_tokens / dark_tokens JSON but not editable in admin UI (16 core keys only).
     */
    'auth_color_token_keys' => [
        'authAccent',
        'authBackdropBase',
        'authGlowDeep',
        'authGlowMid',
        'authGlowSoft',
        'authGlowAccent',
        'authSurface',
        'authWell',
        'authBorder',
        'authBorderPink',
        'authTextPrimary',
        'authTextMuted',
        'authTextEmphasis',
    ],

    /** Light Login / OTP — 1:1 LoginScreen.kt (wa-red + pink backdrop + headings). */
    'default_light_auth_tokens' => [
        'authAccent' => '#E50914',
        'authBackdropBase' => '#FFFFFF',
        'authGlowDeep' => '#EFA8B4',
        'authGlowMid' => '#F3C4CC',
        'authGlowSoft' => '#F0B8C2',
        'authSurface' => '#FFFFFF',
        'authWell' => '#FDE8EC',
        'authBorder' => '#E5E7EB',
        'authBorderPink' => '#F8B4C0',
        'authTextPrimary' => '#111928',
        'authTextMuted' => '#6B7280',
        'authTextEmphasis' => '#991B1B',
    ],

    'default_dark_auth_tokens' => [
        'authAccent' => '#FF4D57',
        'authBackdropBase' => '#101216',
        'authGlowAccent' => '#E50914',
        'authSurface' => '#1A1D24',
        'authBorder' => '#2C313A',
        'authTextPrimary' => '#F4F5F7',
        'authTextMuted' => '#9AA1AC',
    ],
];
