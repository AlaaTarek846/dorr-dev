package com.dorr.app.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
@Composable
fun DorrTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    val scheme = if (darkTheme) {
        darkColorScheme(
            primary = appearanceColor("primary", AppColors.primaryLight, night = true),
            secondary = appearanceColor("secondary", AppColors.secondary, night = true),
            error = appearanceColor("danger", AppColors.danger, night = true),
            background = appearanceColor("background", AppColors.darkBackground, night = true),
            surface = appearanceColor("surface", AppColors.darkSurface, night = true),
            onPrimary = appearanceColor("surface", AppColors.surface, night = true),
            onBackground = appearanceColor("textPrimary", AppColors.darkTextPrimary, night = true),
            onSurface = appearanceColor("textPrimary", AppColors.darkTextPrimary, night = true),
            outline = appearanceColor("border", AppColors.darkBorder, night = true),
        )
    } else {
        lightColorScheme(
            primary = appearanceColor("primary", AppColors.primary, night = false),
            secondary = appearanceColor("secondary", AppColors.secondary, night = false),
            error = appearanceColor("danger", AppColors.danger, night = false),
            background = appearanceColor("background", AppColors.background, night = false),
            surface = appearanceColor("surface", AppColors.surface, night = false),
            onPrimary = appearanceColor("surface", AppColors.surface, night = false),
            onBackground = appearanceColor("textPrimary", AppColors.textPrimary, night = false),
            onSurface = appearanceColor("textPrimary", AppColors.textPrimary, night = false),
            outline = appearanceColor("border", AppColors.border, night = false),
        )
    }
    MaterialTheme(
        colorScheme = scheme,
        typography = DorrTypography,
        content = content,
    )
}
