package com.dorr.freelance.ui.theme

import android.content.Context
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.MobileAppFontDto
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.Request
import java.io.File

/** The font chosen in the appearance settings: the bundled Cairo, or the font's files downloaded once into `filesDir/fonts` (as in the DORR app). */
object AppFontLoader {
    private const val BUNDLED_CAIRO_SLUG = "cairo"

    suspend fun load(context: Context, font: MobileAppFontDto?): FontFamily = withContext(Dispatchers.IO) {
        if (font == null || font.slug == BUNDLED_CAIRO_SLUG) {
            return@withContext CairoBuiltIn
        }
        val files = font.fontFiles.orEmpty().filter { !it.url.isNullOrBlank() }
        if (files.isEmpty()) {
            return@withContext CairoBuiltIn
        }
        val dir = File(context.filesDir, "fonts").apply { mkdirs() }
        val client = ApiClient.okHttpClient
        val composeFonts = files.mapNotNull { entry ->
            val weight = entry.weight?.toIntOrNull()?.coerceIn(1, 1000) ?: 400
            val safeName = entry.fileName?.replace(Regex("[^a-zA-Z0-9._-]"), "_") ?: "font.ttf"
            val dest = File(dir, "${font.id}_${weight}_$safeName")
            if (!dest.exists() || dest.length() == 0L) {
                val url = ApiClient.mediaUrl(entry.url) ?: return@mapNotNull null
                runCatching {
                    client.newCall(Request.Builder().url(url).build()).execute().use { response ->
                        if (!response.isSuccessful) return@mapNotNull null
                        response.body?.byteStream()?.use { input ->
                            dest.outputStream().use { output -> input.copyTo(output) }
                        }
                    }
                }.onFailure { return@mapNotNull null }
            }
            if (!dest.exists()) return@mapNotNull null
            Font(dest, FontWeight(weight))
        }
        if (composeFonts.isEmpty()) CairoBuiltIn else FontFamily(composeFonts)
    }
}
