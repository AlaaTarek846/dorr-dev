package com.dorr.driver.ui.theme

import android.content.Context
import java.util.Locale

/**
 * The context the Activity is built on, in the language the captain chose (Arabic or English; "like the
 * phone" leaves it alone). Strings, the layout direction and `Locale.getDefault()` — which the network
 * layer sends as `X-Locale` — all follow it.
 */
fun Context.withDriverLanguage(): Context {
    val code = DriverPrefs.language
    if (code.isEmpty()) return this
    val locale = Locale(code)
    Locale.setDefault(locale)
    val config = android.content.res.Configuration(resources.configuration)
    config.setLocale(locale)
    config.setLayoutDirection(locale)
    return createConfigurationContext(config)
}
