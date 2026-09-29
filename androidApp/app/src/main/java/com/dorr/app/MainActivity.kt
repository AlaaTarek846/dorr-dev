package com.dorr.app

import android.os.Build
import android.os.Bundle
import androidx.activity.SystemBarStyle
import androidx.activity.compose.LocalActivityResultRegistryOwner
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.fragment.app.FragmentActivity
import android.graphics.Color as AndroidColor
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import com.dorr.app.navigation.DorrNavGraph
import com.dorr.app.network.NetworkMonitor
import com.dorr.app.ui.locale.LocalizedApp
import com.dorr.app.ui.screens.NoInternetScreen
import com.dorr.app.ui.theme.AppearanceState
import com.dorr.app.ui.theme.AppFontLoader
import com.dorr.app.ui.theme.DorrTheme
import com.dorr.app.ui.theme.LocalAppearance
import com.dorr.app.ui.theme.AppFont
import com.dorr.app.ui.theme.LocalDorrFontFamily
import com.dorr.app.ui.theme.LocalThemeState
import com.dorr.app.ui.theme.ThemeState
import com.dorr.app.ui.theme.toThemeOverride

// FragmentActivity (not plain ComponentActivity) so BiometricPrompt — which needs a FragmentManager —
// has somewhere to host its invisible tracking fragment. Still a ComponentActivity underneath: every
// Compose API used below (setContent, enableEdgeToEdge…) works exactly as before.
class MainActivity : FragmentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.light(
                AndroidColor.WHITE,
                AndroidColor.WHITE
            ),
            navigationBarStyle = SystemBarStyle.auto(
                AndroidColor.TRANSPARENT,
                AndroidColor.TRANSPARENT,
            )
        )
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            window.isNavigationBarContrastEnforced = false
        }
        val networkMonitor = NetworkMonitor.getInstance(this)

        setContent {
            // LocalizedApp swaps LocalContext for a locale-only context, which
            // hides the Activity from the photo picker. Keep the registry here.
            CompositionLocalProvider(LocalActivityResultRegistryOwner provides this) {
            LocalizedApp {
                val appearance = remember { AppearanceState() }
                val themeState = remember {
                    ThemeState().apply { isDark = appearance.snapshot.toThemeOverride() }
                }
                val themeOverride = appearance.snapshot.toThemeOverride()
                SideEffect { themeState.isDark = themeOverride }
                val isOnline by networkMonitor.isOnline.collectAsState()
                val context = LocalContext.current
                LaunchedEffect(appearance.snapshot?.font?.id, appearance.snapshot?.font?.slug) {
                    appearance.fontFamily = AppFontLoader.load(context, appearance.snapshot?.font)
                    AppFont.family = appearance.fontFamily
                }

                CompositionLocalProvider(
                    LocalThemeState provides themeState,
                    LocalAppearance provides appearance,
                    LocalDorrFontFamily provides appearance.fontFamily,
                ) {
                    DorrTheme(darkTheme = themeState.isDark ?: isSystemInDarkTheme()) {
                        Surface(
                            modifier = Modifier.fillMaxSize(),
                            color = MaterialTheme.colorScheme.background,
                        ) {
                            Box(
                                modifier = Modifier
                                    .fillMaxSize()
                                    .statusBarsPadding()
                            ) {
                                DorrNavGraph()

                                AnimatedVisibility(
                                    // The chat reads its saved messages offline (and shows its own banner).
                                    visible = !isOnline && !com.dorr.app.chat.ChatStore.screenOpen,
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
}
