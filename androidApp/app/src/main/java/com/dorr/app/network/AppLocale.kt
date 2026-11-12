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

    /** Java locale for dates and weekday names — the app language, not the phone's. */
    fun java(): java.util.Locale = latin(current.ifBlank { "ar" })

    /** [code] (ar, en...) as a locale that writes its numbers with Latin digits (0-9) - one kind of digit on every screen. */
    fun latin(code: String): java.util.Locale = java.util.Locale.forLanguageTag("$code-u-nu-latn")
}
