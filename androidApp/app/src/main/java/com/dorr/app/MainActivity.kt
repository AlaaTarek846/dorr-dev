package com.dorr.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Surface
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import com.dorr.app.navigation.DorrNavGraph
import com.dorr.app.network.NetworkMonitor
import com.dorr.app.ui.locale.LocalizedApp
import com.dorr.app.ui.screens.NoInternetScreen
import com.dorr.app.ui.theme.DorrTheme
import com.dorr.app.ui.theme.LocalThemeState
import com.dorr.app.ui.theme.ThemeState

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        val networkMonitor = NetworkMonitor.getInstance(this)

        setContent {
            LocalizedApp {
                val themeState = remember { ThemeState() }
                val isOnline by networkMonitor.isOnline.collectAsState()

                CompositionLocalProvider(LocalThemeState provides themeState) {
                    DorrTheme(darkTheme = themeState.isDark ?: isSystemInDarkTheme()) {
                        Surface(modifier = Modifier.fillMaxSize()) {
                            Box(modifier = Modifier.fillMaxSize()) {
                                DorrNavGraph()

                                AnimatedVisibility(
                                    visible = !isOnline,
                                    enter = fadeIn(animationSpec = tween(300)),
                                    exit = fadeOut(animationSpec = tween(300)),
                                ) {
                                    NoInternetScreen(
                                        onRetry = {
                                            networkMonitor.refresh()
                                        },
                                    )
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
