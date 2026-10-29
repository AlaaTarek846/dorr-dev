package com.dorr.freelance

import android.graphics.Color as AndroidColor
import android.os.Build
import android.os.Bundle
import androidx.fragment.app.FragmentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.compose.LocalActivityResultRegistryOwner
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
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
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.platform.LocalContext
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.navigation.FreelanceNavGraph
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.NetworkMonitor
import com.dorr.freelance.net.Session
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.locale.LocalizedApp
import com.dorr.freelance.ui.screens.NoInternetScreen
import com.dorr.freelance.ui.theme.AppFont
import com.dorr.freelance.ui.theme.AppFontLoader
import com.dorr.freelance.ui.theme.AppearanceState
import com.dorr.freelance.ui.theme.DorrTheme
import com.dorr.freelance.ui.theme.LocalAppearance
import com.dorr.freelance.ui.theme.LocalDorrFontFamily
import com.dorr.freelance.ui.theme.LocalThemeState
import com.dorr.freelance.ui.theme.ThemeState
import com.dorr.freelance.ui.theme.toThemeOverride

/** The one activity: the language, the theme (the phone's light / dark), the routes and the offline overlay. */
class MainActivity : FragmentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.light(AndroidColor.WHITE, AndroidColor.WHITE),
            navigationBarStyle = SystemBarStyle.auto(AndroidColor.TRANSPARENT, AndroidColor.TRANSPARENT),
        )
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            window.isNavigationBarContrastEnforced = false
        }
        val networkMonitor = NetworkMonitor.getInstance(this)

        setContent {
            // LocalizedApp swaps LocalContext for a locale-only context, which hides the Activity from the photo
            // picker; keep the registry here.
            CompositionLocalProvider(LocalActivityResultRegistryOwner provides this) {
                LocalizedApp {
                    val appearance = remember { AppearanceState() }
                    val themeState = remember { ThemeState().apply { isDark = appearance.snapshot.toThemeOverride() } }
                    // The mode the freelancer chose (light / dark / the phone's) — set again whenever the saved choice changes.
                    val themeOverride = appearance.snapshot.toThemeOverride()
                    SideEffect { themeState.isDark = themeOverride }
                    val isOnline by networkMonitor.isOnline.collectAsState()
                    val context = LocalContext.current

                    // The font of the account (Cairo until another is chosen), downloaded once and kept on the phone.
                    LaunchedEffect(appearance.snapshot?.font?.id, appearance.snapshot?.font?.slug) {
                        appearance.fontFamily = AppFontLoader.load(context, appearance.snapshot?.font)
                        AppFont.family = appearance.fontFamily
                    }

                    // The account's own appearance is read once when the session starts (cold start or sign-in) and dropped at sign-out;
                    // the platform's stays for the sign-in pages.
                    val signedIn by Session.signedIn.collectAsState()
                    LaunchedEffect(signedIn) {
                        if (!signedIn) {
                            appearance.clear()
                            return@LaunchedEffect
                        }
                        val result = apiCall { ApiClient.appearance.show() }
                        if (result is ApiResult.Ok && Session.signedIn.value) appearance.apply(result.data)
                    }

                    CompositionLocalProvider(
                        LocalThemeState provides themeState,
                        LocalAppearance provides appearance,
                        LocalDorrFontFamily provides appearance.fontFamily,
                    ) {
                        val night = themeState.isDark ?: isSystemInDarkTheme()
                        // The status and navigation bars follow the app's colours: dark with light icons at night,
                        // white with dark icons by day.
                        DisposableEffect(night) {
                            enableEdgeToEdge(
                                statusBarStyle = if (night) SystemBarStyle.dark(AndroidColor.TRANSPARENT) else SystemBarStyle.light(AndroidColor.WHITE, AndroidColor.WHITE),
                                navigationBarStyle = if (night) SystemBarStyle.dark(AndroidColor.TRANSPARENT) else SystemBarStyle.light(AndroidColor.TRANSPARENT, AndroidColor.TRANSPARENT),
                            )
                            onDispose {}
                        }
                        DorrTheme(darkTheme = night) {
                            Surface(
                                modifier = Modifier.fillMaxSize(),
                                color = if (night) AccountDark.bg else MaterialTheme.colorScheme.background,
                            ) {
                                Box(Modifier.fillMaxSize().statusBarsPadding()) {
                                    FreelanceNavGraph()

                                    AnimatedVisibility(
                                        visible = !isOnline,
                                        enter = fadeIn(animationSpec = tween(300)),
                                        exit = fadeOut(animationSpec = tween(300)),
                                    ) {
                                        NoInternetScreen(onRetry = { networkMonitor.refresh() })
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
