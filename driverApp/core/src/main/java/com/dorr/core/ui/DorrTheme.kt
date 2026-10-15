package com.dorr.core.ui

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

/**
 * DORR's identity — the logo's navy and orange (same values as :app's AppColors fallbacks).
 * Status colours are shared so a state reads the same in both apps.
 */
object DorrBrand {
    val navy = Color(0xFF001B53)
    val navyLight = Color(0xFF1E3A7B)
    val orange = Color(0xFFFA7552)
    val success = Color(0xFF059669)
    val warning = Color(0xFFF59E0B)
    val danger = Color(0xFFF05252)
    val info = Color(0xFF06B6D4)
}

private val Light = lightColorScheme(
    primary = DorrBrand.navy,
    onPrimary = Color.White,
    secondary = DorrBrand.orange,
    onSecondary = Color.White,
    tertiary = DorrBrand.success,
    background = Color(0xFFF7F8FB),
    surface = Color.White,
    surfaceVariant = Color(0xFFEEF1F7),
    error = DorrBrand.danger,
)

// Night driving: a dark scheme that stays easy on the eyes (docs/rides-plan.md §17).
private val Dark = darkColorScheme(
    primary = Color(0xFF8FA8FF),
    onPrimary = Color(0xFF00113A),
    secondary = DorrBrand.orange,
    onSecondary = Color.White,
    tertiary = Color(0xFF34D399),
    background = Color(0xFF0B1020),
    surface = Color(0xFF131A2E),
    surfaceVariant = Color(0xFF1C2540),
    error = Color(0xFFFF7A7A),
)

@Composable
fun DorrTheme(dark: Boolean = isSystemInDarkTheme(), content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = if (dark) Dark else Light, content = content)
}
