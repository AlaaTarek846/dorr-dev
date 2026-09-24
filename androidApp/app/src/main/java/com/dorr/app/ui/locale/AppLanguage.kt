package com.dorr.app.ui.locale

import android.content.Context
import android.content.res.Configuration
import android.os.LocaleList
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.unit.LayoutDirection
import com.dorr.app.network.AppLocale
import java.util.Locale

/**
 * Persisted app language preference. The source of truth for both the
 * X-Locale header (via [AppLocale.current]) and the app's own UI strings.
 */
object AppLanguagePreferences {
    const val DEFAULT = "ar"

    private const val PREFS_NAME = "dorr_app_prefs"
    private const val KEY_LOCALE = "locale"

    fun get(context: Context): String =
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .getString(KEY_LOCALE, DEFAULT) ?: DEFAULT

    fun save(context: Context, code: String) {
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
            .edit().putString(KEY_LOCALE, code).apply()
    }
}

/** Handle exposed to screens so the pickers can switch the app language. */
class AppLanguageState(
    val code: String,
    private val onSelect: (String) -> Unit,
) {
    fun set(next: String) = onSelect(next.lowercase())
    val isArabic: Boolean get() = code == "ar"
}

val LocalAppLanguage = staticCompositionLocalOf<AppLanguageState> {
    error("LocalAppLanguage not provided — wrap the app in LocalizedApp()")
}

/**
 * Wraps the whole app content in a locale-aware [Context] and recomposes
 * every [LocalContext]/[LocalConfiguration]/[LocalLayoutDirection] reader
 * when the language changes — so stringResource, RTL/LTR flipping and
 * isSystemInDarkTheme all follow the persisted preference without recreating
 * the Activity (in-memory state like ThemeState and the nav graph survive).
 */
@Composable
fun LocalizedApp(content: @Composable () -> Unit) {
    val context = LocalContext.current
    var code by remember { mutableStateOf(AppLanguagePreferences.get(context)) }

    // Keep the X-Locale header (read by the OkHttp interceptor) in sync with
    // the persisted choice — including on cold start from stored prefs.
    SideEffect { AppLocale.current = code }

    val localizedContext = remember(code) { context.forLocale(code) }

    val state = AppLanguageState(code) { next ->
        code = next
        AppLanguagePreferences.save(context, next)
    }

    CompositionLocalProvider(
        LocalContext provides localizedContext,
        LocalConfiguration provides localizedContext.resources.configuration,
        LocalLayoutDirection provides (if (code == "ar") LayoutDirection.Rtl else LayoutDirection.Ltr),
        LocalAppLanguage provides state,
    ) {
        content()
    }
}

/** Wraps [context] in a [Context] whose resources resolve against [code]. */
private fun Context.forLocale(code: String): Context {
    val config = Configuration(resources.configuration).apply {
        setLocales(LocaleList(Locale(code)))
    }
    return createConfigurationContext(config)
}
