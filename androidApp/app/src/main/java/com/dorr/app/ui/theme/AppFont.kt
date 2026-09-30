package com.dorr.app.ui.theme

import android.content.Context
import android.util.Log
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AppearanceStore
import com.dorr.app.network.MobileAppFontDto
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.Request
import java.io.File

/**
 * The app's font, as chosen in the appearance settings (the admin uploads fonts; the server sends
 * the selected one with the appearance). Its files are downloaded once and kept on the phone, so
 * the font is there straight away on the next launch — offline too.
 *
 * [family] is snapshot state: every text drawn with [CairoFontFamily] or the theme typography
 * switches the moment a new font is ready. Until then — or if anything fails — it is Cairo.
 */
object AppFont {
    var family by mutableStateOf(CairoBuiltIn)
        private set

    private var appliedKey: String? = null
    private var appContext: Context? = null
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)

    fun attach(context: Context) {
        appContext = context.applicationContext
        apply(AppearanceStore.loadUser()?.font)
    }

    fun apply(font: MobileAppFontDto?) {
        val context = appContext ?: return
        val files = font?.fontFiles.orEmpty()
        val key = font?.let { "${it.id}:${files.joinToString { f -> f.url.orEmpty() }}" }
        if (key == appliedKey) return
        appliedKey = key

        if (font == null || files.isEmpty()) {
            family = CairoBuiltIn
            return
        }

        scope.launch {
            val fonts = withContext(Dispatchers.IO) {
                files.mapNotNull { file ->
                    val url = file.url ?: return@mapNotNull null
                    val local = download(context, font, url) ?: return@mapNotNull null
                    runCatching { Font(local, FontWeight(file.weight?.toIntOrNull()?.coerceIn(100, 900) ?: 400)) }.getOrNull()
                }
            }
            // Still the font that was asked for (a newer choice may have come in meanwhile).
            if (appliedKey == key) family = if (fonts.isEmpty()) CairoBuiltIn else FontFamily(fonts)
        }
    }

    fun reset() {
        appliedKey = null
        family = CairoBuiltIn
    }

    private fun download(context: Context, font: MobileAppFontDto, url: String): File? {
        val dir = File(context.filesDir, "fonts").apply { mkdirs() }
        val name = "${font.id}_${url.hashCode().toUInt()}" + (url.substringAfterLast('.', "ttf").take(5).let { ".$it" })
        val target = File(dir, name)
        if (target.exists() && target.length() > 0) return target

        return runCatching {
            val request = Request.Builder().url(ApiClient.mediaUrl(url) ?: url).build()
            ApiClient.okHttpClient.newCall(request).execute().use { response ->
                if (!response.isSuccessful) return@use null
                val body = response.body ?: return@use null
                val tmp = File(dir, "$name.part")
                tmp.outputStream().use { out -> body.byteStream().copyTo(out) }
                if (tmp.renameTo(target)) target else null
            }
        }.onFailure { Log.w("AppFont", "font download failed: $url", it) }.getOrNull()
    }
}
