package com.dorr.freelance.net

/**
 * The app language sent as `X-Locale` (the server speaks ar / en). A plain object, not Compose state, because the OkHttp
 * interceptor reads it off the UI thread; LocalizedApp (ui/locale) keeps it in step with the saved choice.
 */
object AppLocale {
    @Volatile
    var current: String = "ar"

    /** Java locale for dates and numbers — the app language, not the phone's. */
    fun java(): java.util.Locale = java.util.Locale.forLanguageTag(current.ifBlank { "ar" })
}
