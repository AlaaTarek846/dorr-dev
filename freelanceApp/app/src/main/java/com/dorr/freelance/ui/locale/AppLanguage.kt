package com.dorr.freelance.ui.locale

import android.content.Context
import android.content.ContextWrapper
import android.content.res.Configuration
import android.os.LocaleList
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.dorr.freelance.net.AppLocale
import com.dorr.freelance.ui.theme.ProvideAppFont
import java.util.Locale

/**
 * The app language, as in the DORR app (androidApp ui/locale) but with the two bundled languages only (ar, en): the
 * saved choice drives the app's strings, the RTL / LTR direction and the `X-Locale` header, without restarting the
 * activity.
 */
object AppLanguagePreferences {
    const val DEFAULT = "ar"

    private const val PREFS_NAME = "dorr_freelance_prefs"
    private const val KEY_LOCALE = "locale"
    private val SUPPORTED = setOf("ar", "en")

    fun get(context: Context): String =
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).getString(KEY_LOCALE, DEFAULT)
            ?.takeIf { it in SUPPORTED } ?: DEFAULT

    fun save(context: Context, code: String) {
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).edit().putString(KEY_LOCALE, code).apply()
    }
}

/** Handle the screens use to read or switch the language. */
class AppLanguageState(val code: String, private val onSelect: (String) -> Unit) {
    val isArabic: Boolean get() = code == "ar"
    val isRtl: Boolean get() = isArabic

    fun set(next: String) = onSelect(next.lowercase())

    /** ar ⇄ en. */
    fun toggle() = set(if (isArabic) "en" else "ar")
}

val LocalAppLanguage = staticCompositionLocalOf<AppLanguageState> {
    error("LocalAppLanguage not provided — wrap the app in LocalizedApp()")
}

/**
 * Wraps the app in a locale-aware [Context] and recomposes every [LocalContext] / [LocalConfiguration] /
 * [LocalLayoutDirection] reader when the language changes, so stringResource and the direction follow the saved choice.
 */
@Composable
fun LocalizedApp(content: @Composable () -> Unit) {
    val context = LocalContext.current
    var code by remember { mutableStateOf(AppLanguagePreferences.get(context)) }

    // The X-Locale header (read by the OkHttp interceptor) follows the saved choice, from the cold start on.
    SideEffect { AppLocale.current = code }

    // Rebuilt when the screen changes too (rotation, split screen), so LocalConfiguration carries the new size.
    val screen = LocalConfiguration.current
    val localizedContext = remember(code, screen) { context.forLocale(code) }

    val state = AppLanguageState(code) { next ->
        code = next
        AppLanguagePreferences.save(context, next)
    }

    CompositionLocalProvider(
        // Compose UI 1.7 has no LocalResources — stringResource reads resources off LocalContext.
        LocalContext provides localizedContext,
        LocalConfiguration provides localizedContext.resources.configuration,
        LocalLayoutDirection provides (if (state.isRtl) LayoutDirection.Rtl else LayoutDirection.Ltr),
        LocalAppLanguage provides state,
    ) {
        content()
    }
}

/** Capture the locale-aware locals *outside* a Dialog / sheet, then [Provide] them inside the new window. */
class AppLocaleFrame(
    val context: Context,
    val configuration: Configuration,
    val layoutDirection: LayoutDirection,
) {
    @Composable
    fun Provide(content: @Composable () -> Unit) {
        CompositionLocalProvider(
            LocalContext provides context,
            LocalConfiguration provides configuration,
            LocalLayoutDirection provides layoutDirection,
        ) {
            ProvideAppFont(content)
        }
    }
}

@Composable
fun rememberAppLocaleFrame(): AppLocaleFrame = AppLocaleFrame(
    LocalContext.current,
    LocalConfiguration.current,
    LocalLayoutDirection.current,
)

/** A [Dialog] in the app's language (its window would otherwise keep the activity's own context — English strings). */
@Composable
fun LocaleAwareDialog(
    onDismissRequest: () -> Unit,
    properties: DialogProperties = DialogProperties(),
    content: @Composable () -> Unit,
) {
    val frame = rememberAppLocaleFrame()
    Dialog(onDismissRequest = onDismissRequest, properties = properties) {
        frame.Provide(content)
    }
}

/** A bottom sheet in the app's language and font (its own window, like a dialog). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LocaleAwareBottomSheet(
    onDismissRequest: () -> Unit,
    containerColor: Color,
    shape: Shape,
    /** Open to full height at once (a tall form) instead of half-way first. */
    fullHeight: Boolean = false,
    content: @Composable ColumnScope.() -> Unit,
) {
    val frame = rememberAppLocaleFrame()
    ModalBottomSheet(
        onDismissRequest = onDismissRequest,
        containerColor = containerColor,
        shape = shape,
        sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = fullHeight),
    ) {
        frame.Provide { content() }
    }
}

/**
 * Wraps [this] in a [Context] whose resources resolve against [code]. It stays a [ContextWrapper] *around the Activity*
 * so Compose still finds the Activity's owners (activity-result registry, back dispatcher) by unwrapping LocalContext —
 * a photo picker or camera launcher would crash otherwise.
 */
private fun Context.forLocale(code: String): Context {
    val config = Configuration(resources.configuration).apply { setLocales(LocaleList(Locale(code))) }
    val localized = createConfigurationContext(config)
    return object : ContextWrapper(this) {
        override fun getResources() = localized.resources
        override fun getAssets() = localized.assets
    }
}
