package com.dorr.app.ui.theme

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.text.font.FontFamily

/**
 * The app font currently in use, mirrored from what [AppFontLoader] loaded for the appearance
 * settings (MainActivity sets it). Snapshot state, so every text that sets [CairoFontFamily]
 * explicitly (the chat, text fields, custom styles) switches with the theme typography.
 */
object AppFont {
    var family by mutableStateOf<FontFamily>(CairoFontFamily)
}
