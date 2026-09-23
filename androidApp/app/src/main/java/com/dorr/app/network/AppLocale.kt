package com.dorr.app.network

/**
 * Current app language, as sent to the backend via the `X-Locale` header
 * (see LocaleResolver::supported() in the Laravel backend — currently "ar"
 * and "en"). Plain object rather than Compose state because the OkHttp
 * interceptor that reads it runs off the UI thread, outside any composition.
 * The Profile screen's language picker writes to this on selection.
 */
object AppLocale {
    @Volatile
    var current: String = "ar"
}
