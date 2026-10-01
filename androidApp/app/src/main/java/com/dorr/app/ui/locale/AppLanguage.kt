package com.dorr.app.ui.locale

import android.content.Context
import android.content.ContextWrapper
import android.content.res.Configuration
import android.os.LocaleList
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AppLocale
import com.dorr.app.network.LanguageDto
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

/** ar/en ship in the APK (values-ar/, values/); every other language is downloaded. */
private val BUNDLED_LANGUAGES = setOf("ar", "en")

internal fun isBundledLanguage(code: String) = code.lowercase() in BUNDLED_LANGUAGES

/** Handle exposed to screens so the pickers can switch the app language. */
class AppLanguageState(
    val code: String,
    /** Layout direction of the current language — saved with a downloaded one, so it holds offline. */
    val isRtl: Boolean,
    private val onSelect: (String) -> Unit,
    private val onStringsUpdated: () -> Unit,
) {
    fun set(next: String) = onSelect(next.lowercase())
    val isArabic: Boolean get() = code == "ar"

    /**
     * Switches to [next]. A bundled language applies at once and drops any downloaded file; any
     * other language is downloaded first and applied only once the download succeeded.
     * Returns false when the download failed — the current language stays.
     */
    suspend fun choose(context: Context, next: String): Boolean {
        val target = next.lowercase()
        if (isBundledLanguage(target)) {
            set(target)
            DownloadedTranslations.clear(context)
            return true
        }
        if (target == code && DownloadedTranslations.load(context, target) != null) return true
        return when (DownloadedTranslations.download(context, target)) {
            DownloadedTranslations.Result.Success -> {
                if (target == code) onStringsUpdated() else set(target)
                true
            }
            else -> false
        }
    }

    /**
     * Keeps a downloaded current language in step with the server list ([languages] =
     * GET translations/languages?platform=android): a newer `android_version` is downloaded and
     * applied, and a language that is no longer offered (unpublished, disabled) falls back to en.
     * An empty list means the request failed — the stored file keeps working.
     */
    suspend fun syncWith(context: Context, languages: List<LanguageDto>) {
        if (isBundledLanguage(code) || languages.isEmpty()) return

        val entry = languages.find { it.code.equals(code, ignoreCase = true) }
        if (entry == null) {
            fallBackToEnglish(context)
            return
        }

        val stored = DownloadedTranslations.meta(context)
        if (stored?.code == code && entry.androidVersion != null && stored.version == entry.androidVersion) return

        when (DownloadedTranslations.download(context, code)) {
            DownloadedTranslations.Result.Success -> onStringsUpdated()
            DownloadedTranslations.Result.NotAvailable -> fallBackToEnglish(context)
            DownloadedTranslations.Result.Failed -> Unit
        }
    }

    private fun fallBackToEnglish(context: Context) {
        set("en")
        DownloadedTranslations.clear(context)
    }
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
    var code by remember { mutableStateOf(initialLanguage(context)) }
    // Bumped when the current downloaded language was replaced by a newer version.
    var stringsRevision by remember { mutableIntStateOf(0) }

    // Keep the X-Locale header (read by the OkHttp interceptor) in sync with
    // the persisted choice — including on cold start from stored prefs.
    SideEffect { AppLocale.current = code }

    val localizedContext = remember(code, stringsRevision) {
        val downloaded = if (isBundledLanguage(code)) null else DownloadedTranslations.load(context, code)
        context.forLocale(code, downloaded)
    }

    val isRtl = when (code) {
        "ar" -> true
        "en" -> false
        else -> DownloadedTranslations.meta(context)?.takeIf { it.code == code }?.direction == "rtl"
    }

    val state = AppLanguageState(
        code = code,
        isRtl = isRtl,
        onSelect = { next ->
            code = next
            AppLanguagePreferences.save(context, next)
        },
        onStringsUpdated = { stringsRevision++ },
    )

    // A downloaded language is checked against the server once per launch; offline, the stored
    // file is used as it is.
    LaunchedEffect(Unit) {
        if (isBundledLanguage(code)) return@LaunchedEffect
        val languages = runCatching { ApiClient.languages.list().data }.getOrNull() ?: return@LaunchedEffect
        state.syncWith(context, languages)
    }

    CompositionLocalProvider(
        // Compose UI 1.7 (BOM 2024.09) has no LocalResources — stringResource reads
        // resources off LocalContext, so swap in the locale-wrapped context instead.
        LocalContext provides localizedContext,
        LocalConfiguration provides localizedContext.resources.configuration,
        LocalLayoutDirection provides (if (isRtl) LayoutDirection.Rtl else LayoutDirection.Ltr),
        LocalAppLanguage provides state,
    ) {
        content()
    }
}

/** The saved language, or en when it is a downloaded one whose file is gone. */
private fun initialLanguage(context: Context): String {
    val saved = AppLanguagePreferences.get(context)
    if (isBundledLanguage(saved) || DownloadedTranslations.load(context, saved) != null) return saved
    AppLanguagePreferences.save(context, "en")
    return "en"
}

/**
 * [Dialog] content is composed in a separate window that keeps the Activity's
 * default [Context], so [stringResource] would ignore [LocalizedApp] and fall
 * back to `values/` (English). Capture the locale-aware locals from the caller
 * and re-provide them inside the dialog.
 */
@Composable
fun LocaleAwareDialog(
    onDismissRequest: () -> Unit,
    properties: DialogProperties = DialogProperties(),
    content: @Composable () -> Unit,
) {
    val context = LocalContext.current
    val configuration = LocalConfiguration.current
    val layoutDirection = LocalLayoutDirection.current
    Dialog(onDismissRequest = onDismissRequest, properties = properties) {
        CompositionLocalProvider(
            LocalContext provides context,
            LocalConfiguration provides configuration,
            LocalLayoutDirection provides layoutDirection,
        ) {
            content()
        }
    }
}

/**
 * Wraps [context] in a [Context] whose resources resolve against [code]; with [downloaded] strings
 * (a language not bundled in the APK) they are served through [DynamicResources].
 *
 * It must stay a [ContextWrapper] *around the Activity*: `createConfigurationContext` alone returns an unrelated
 * context, and Compose finds the Activity's owners (activity-result registry, back-press dispatcher…) by
 * unwrapping `LocalContext`. Without that, `rememberLauncherForActivityResult` throws on the first frame of any
 * screen that picks a photo or opens the QR camera — the app just closes.
 */
private fun Context.forLocale(code: String, downloaded: Map<String, Any>?): Context {
    val config = Configuration(resources.configuration).apply {
        setLocales(LocaleList(Locale(code)))
    }
    val localized = createConfigurationContext(config)
    val resources = downloaded?.let { DynamicResources(localized.resources, it, code) } ?: localized.resources
    return object : ContextWrapper(this) {
        override fun getResources() = resources
        override fun getAssets() = localized.assets
    }
}
