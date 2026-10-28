package com.dorr.freelance.ui.theme

import androidx.compose.material3.ProvideTextStyle
import androidx.compose.runtime.Composable
import androidx.compose.ui.text.TextStyle

/**
 * The app font (Cairo, or what the appearance settings chose) for every Text inside — a screen drawn outside a Material
 * surface, or a dialog / sheet (its own window), otherwise falls back to the system font and one page shows two fonts.
 */
@Composable
fun ProvideAppFont(content: @Composable () -> Unit) {
    ProvideTextStyle(TextStyle(fontFamily = CairoFontFamily), content)
}
