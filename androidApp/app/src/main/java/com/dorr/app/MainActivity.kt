package com.dorr.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Surface
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import com.dorr.app.navigation.DorrNavGraph
import com.dorr.app.ui.locale.LocalizedApp
import com.dorr.app.ui.theme.DorrTheme
import com.dorr.app.ui.theme.LocalThemeState
import com.dorr.app.ui.theme.ThemeState

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            LocalizedApp {
                val themeState = remember { ThemeState() }
                CompositionLocalProvider(LocalThemeState provides themeState) {
                    DorrTheme(darkTheme = themeState.isDark ?: isSystemInDarkTheme()) {
                        Surface(modifier = Modifier.fillMaxSize()) {
                            DorrNavGraph()
                        }
                    }
                }
            }
        }
    }
}
