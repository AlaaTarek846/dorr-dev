package com.dorr.app.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable

private val LightColors = lightColorScheme(
    primary = AppColors.primary,
    secondary = AppColors.secondary,
    error = AppColors.danger,
    background = AppColors.background,
    surface = AppColors.surface,
    onPrimary = AppColors.surface,
    onBackground = AppColors.textPrimary,
    onSurface = AppColors.textPrimary,
    outline = AppColors.border,
)

private val DarkColors = darkColorScheme(
    primary = AppColors.primaryLight,
    secondary = AppColors.secondary,
    error = AppColors.danger,
    background = AppColors.darkBackground,
    surface = AppColors.darkSurface,
    onPrimary = AppColors.surface,
    onBackground = AppColors.darkTextPrimary,
    onSurface = AppColors.darkTextPrimary,
    outline = AppColors.darkBorder,
)

@Composable
fun DorrTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    MaterialTheme(
        colorScheme = if (darkTheme) DarkColors else LightColors,
        typography = DorrTypography,
        content = content,
    )
}
