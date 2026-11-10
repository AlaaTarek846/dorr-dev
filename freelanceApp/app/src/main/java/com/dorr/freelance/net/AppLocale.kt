package com.dorr.freelance.net

/**
 * The app language sent as `X-Locale` (the server speaks ar / en). A plain object, not Compose state, because the OkHttp
 * interceptor reads it off the UI thread; LocalizedApp (ui/locale) keeps it in step with the saved choice.
 */
object AppLocale {
    @Volatile
    var current: String = "ar"

    /**
     * Java locale for dates and numbers — the app language, not the phone's, always with Latin digits (0-9): an Arabic screen
     * mixed ٣٠ with 1500.00 before, and the owner asked for one kind of digit everywhere.
     */
    fun java(): java.util.Locale = latin(current.ifBlank { "ar" })

    /** [code] (ar, en...) as a locale that writes its numbers with Latin digits. */
    fun latin(code: String): java.util.Locale = java.util.Locale.forLanguageTag("$code-u-nu-latn")
}
