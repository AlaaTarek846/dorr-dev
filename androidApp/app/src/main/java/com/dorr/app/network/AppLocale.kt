package com.dorr.app.network

/**
 * Current app language, as sent to the backend via the `X-Locale` header
 * (see LocaleResolver::supported() in the Laravel backend — currently "ar"
 * and "en"). Plain object rather than Compose state because the OkHttp
 * interceptor that reads it runs off the UI thread, outside any composition.
 * Kept in sync with the persisted choice by LocalizedApp (ui/locale), which
 * is what the Profile/Login language pickers actually update.
 */
object AppLocale {
    @Volatile
    var current: String = "ar"
}
