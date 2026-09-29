package com.dorr.app.ui.theme

import android.content.Context
import androidx.compose.ui.text.ExperimentalTextApi
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontVariation
import androidx.compose.ui.text.font.FontWeight
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MobileAppFontDto
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.Request
import java.io.File

object AppFontLoader {
    private const val BUNDLED_CAIRO_SLUG = "cairo"

    suspend fun load(context: Context, font: MobileAppFontDto?): FontFamily = withContext(Dispatchers.IO) {
        if (font == null || font.slug == BUNDLED_CAIRO_SLUG) {
            return@withContext CairoFontFamily
        }
        val files = font.fontFiles.orEmpty().filter { !it.url.isNullOrBlank() }
        if (files.isEmpty()) {
            return@withContext CairoFontFamily
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
        if (composeFonts.isEmpty()) CairoFontFamily else FontFamily(composeFonts)
    }

    @OptIn(ExperimentalTextApi::class)
    private fun cairoWeight(weight: Int): Font = Font(
        resId = R.font.cairo,
        weight = FontWeight(weight),
        variationSettings = FontVariation.Settings(FontVariation.weight(weight)),
    )
}
