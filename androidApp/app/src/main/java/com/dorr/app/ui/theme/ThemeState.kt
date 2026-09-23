package com.dorr.app.ui.theme

import androidx.compose.runtime.compositionLocalOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue

/** App-wide dark-mode override. `null` means "follow the system setting". */
class ThemeState {
    var isDark by mutableStateOf<Boolean?>(null)
}

val LocalThemeState = compositionLocalOf { ThemeState() }
