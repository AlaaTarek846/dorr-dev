package com.dorr.app.navigation

import android.os.Handler
import android.os.Looper
import androidx.compose.animation.AnimatedContentTransitionScope
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.OnboardingStore
import com.dorr.app.ui.screens.LoginScreen
import com.dorr.app.ui.screens.MainScreen
import com.dorr.app.ui.screens.NotificationsScreen
import com.dorr.app.ui.screens.OnboardingScreen
import com.dorr.app.ui.screens.OtpScreen
import com.dorr.app.ui.screens.ServicesScreen
import com.dorr.app.ui.screens.SplashScreen
import com.dorr.app.ui.theme.LocalAppearance
import kotlinx.coroutines.launch

object Routes {
    const val SPLASH = "splash"
    const val ONBOARDING = "onboarding"
    const val LOGIN = "login"
    const val OTP = "otp"
    const val MAIN = "main"
    const val NOTIFICATIONS = "notifications"
    const val SERVICES = "services"
}

/** Where Splash goes once its animation finishes. */
internal fun routeAfterSplash(onboardingCompleted: Boolean, authenticated: Boolean): String =
    when {
        !onboardingCompleted -> Routes.ONBOARDING
        authenticated -> Routes.MAIN
        else -> Routes.LOGIN
    }

/** Where onboarding goes after Skip or the last step. */
internal fun routeAfterOnboarding(authenticated: Boolean): String =
    if (authenticated) Routes.MAIN else Routes.LOGIN

@Composable
fun DorrNavGraph(navController: NavHostController = rememberNavController()) {
    // Held here (not as a nav argument) so the phone number never has to
    // round-trip through URL encoding on its way to the OTP screen.
    val context = androidx.compose.ui.platform.LocalContext.current
    var pendingDialCode by remember { mutableStateOf("") }
    var pendingPhone by remember { mutableStateOf("") }
    var sessionExpiredNotice by remember { mutableStateOf(false) }
    // Set right after DELETE /profile/account, so Login can tell the user their
    // account was deleted *and* that signing in with the same phone restores it.
    var accountDeletedNotice by remember { mutableStateOf(false) }
    // Set when the OTP request found a soft-deleted account and restored it.
    var restoreMode by remember { mutableStateOf(false) }

    // Preserve the page the user was on before being kicked out by 401
    var targetRouteAfterLogin by remember { mutableStateOf<String?>(null) }
    var lastMainTab by remember { mutableIntStateOf(0) }
    var lastWalletOpen by remember { mutableStateOf(false) }

    val scope = rememberCoroutineScope()
    val appearance = LocalAppearance.current

    // A 401 on any authenticated request (expired/revoked token) is detected
    // by the ApiClient interceptor, which clears AuthSession and fires this
    // callback. Route back to Login on the main thread — never from the
    // interceptor's background thread.
    DisposableEffect(Unit) {
        AuthSession.onUnauthorized = {
            Handler(Looper.getMainLooper()).post {
                appearance.clear()
                sessionExpiredNotice = true
                accountDeletedNotice = false
                restoreMode = false
                val currentRoute = navController.currentDestination?.route
                if (currentRoute != null && currentRoute != Routes.LOGIN && currentRoute != Routes.SPLASH && currentRoute != Routes.OTP && currentRoute != Routes.ONBOARDING) {
                    targetRouteAfterLogin = currentRoute
                }
                if (currentRoute != Routes.LOGIN) {
                    navController.navigate(Routes.LOGIN) {
                        popUpTo(0) { inclusive = true }
                    }
                }
            }
        }
        onDispose { AuthSession.onUnauthorized = null }
    }

    fun logout() {
        val token = AuthSession.token
        // Another person may sign in on this phone: drop the chat's cache, live connection and push id.
        com.dorr.app.chat.ChatRealtime.stop()
        com.dorr.app.chat.ChatPush.signedOut()
        com.dorr.app.chat.ChatStore.clear()
        com.dorr.app.chat.ChatOutbox.clear(context)
        com.dorr.app.chat.LiveLocationSharing.stopAll(context)
        appearance.clear()
        AuthSession.clear()
        targetRouteAfterLogin = null
        lastMainTab = 0
        lastWalletOpen = false
        sessionExpiredNotice = false
        accountDeletedNotice = false
        restoreMode = false
        if (!token.isNullOrBlank()) {
            scope.launch {
                runCatching {
                    ApiClient.mobileAuth.logout("Bearer $token")
                }
            }
        }
        navController.navigate(Routes.LOGIN) {
            popUpTo(0) { inclusive = true }
        }
    }

    /**
     * DELETE /profile/account already soft-deleted the user and revoked every token,
     * so this mirrors logout() without the logout request — that one would 401 and the
     * ApiClient interceptor would turn a deliberate delete into a "session expired"
     * notice on the login screen.
     */
    fun accountDeleted() {
        com.dorr.app.chat.ChatRealtime.stop()
        com.dorr.app.chat.ChatPush.signedOut()
        com.dorr.app.chat.ChatStore.clear()
        com.dorr.app.chat.LiveLocationSharing.stopAll(context)
        appearance.clear()
        AuthSession.clear()
        targetRouteAfterLogin = null
        lastMainTab = 0
        lastWalletOpen = false
        sessionExpiredNotice = false
        restoreMode = false
        accountDeletedNotice = true
        navController.navigate(Routes.LOGIN) {
            popUpTo(0) { inclusive = true }
        }
    }

    NavHost(
        navController = navController,
        startDestination = Routes.SPLASH,
        enterTransition = {
            slideIntoContainer(
                towards = AnimatedContentTransitionScope.SlideDirection.Start,
                animationSpec = tween(340, easing = FastOutSlowInEasing),
            ) + fadeIn(animationSpec = tween(280))
        },
        exitTransition = {
            slideOutOfContainer(
                towards = AnimatedContentTransitionScope.SlideDirection.Start,
                animationSpec = tween(320, easing = FastOutSlowInEasing),
            ) + fadeOut(animationSpec = tween(240))
        },
        popEnterTransition = {
            slideIntoContainer(
                towards = AnimatedContentTransitionScope.SlideDirection.End,
                animationSpec = tween(340, easing = FastOutSlowInEasing),
            ) + fadeIn(animationSpec = tween(280))
        },
        popExitTransition = {
            slideOutOfContainer(
                towards = AnimatedContentTransitionScope.SlideDirection.End,
                animationSpec = tween(320, easing = FastOutSlowInEasing),
            ) + fadeOut(animationSpec = tween(240))
        },
    ) {
        composable(
            route = Routes.SPLASH,
            exitTransition = { fadeOut(animationSpec = tween(400)) },
        ) {
            SplashScreen(
                onFinished = {
                    val destination = routeAfterSplash(
                        onboardingCompleted = OnboardingStore.isCompleted,
                        authenticated = AuthSession.isAuthenticated,
                    )
                    navController.navigate(destination) {
                        popUpTo(Routes.SPLASH) { inclusive = true }
                    }
                },
            )
        }
        composable(Routes.ONBOARDING) {
            OnboardingScreen(
                onFinished = {
                    OnboardingStore.markCompleted()
                    navController.navigate(routeAfterOnboarding(AuthSession.isAuthenticated)) {
                        popUpTo(Routes.ONBOARDING) { inclusive = true }
                    }
                },
            )
        }
        composable(Routes.LOGIN) {
            LoginScreen(
                sessionExpiredNotice = sessionExpiredNotice,
                onDismissSessionExpired = { sessionExpiredNotice = false },
                accountDeletedNotice = accountDeletedNotice,
                onDismissAccountDeleted = { accountDeletedNotice = false },
                onOtpRequested = { dialCode, phone, restore ->
                    sessionExpiredNotice = false
                    accountDeletedNotice = false
                    pendingDialCode = dialCode
                    pendingPhone = phone
                    restoreMode = restore
                    navController.navigate(Routes.OTP)
                },
            )
        }
        composable(Routes.OTP) {
            OtpScreen(
                dialCode = pendingDialCode,
                phoneNumber = pendingPhone,
                restoreMode = restoreMode,
                onBack = {
                    restoreMode = false
                    navController.popBackStack()
                },
                onVerified = {
                    val returnDestination = targetRouteAfterLogin
                    targetRouteAfterLogin = null
                    restoreMode = false
                    navController.navigate(Routes.MAIN) {
                        popUpTo(Routes.LOGIN) { inclusive = true }
                    }
                    // If the user was on another screen (e.g. Notifications or Services), navigate to it
                    if (returnDestination != null && returnDestination != Routes.MAIN) {
                        navController.navigate(returnDestination)
                    }
                },
            )
        }
        composable(Routes.MAIN) {
            MainScreen(
                initialTab = lastMainTab,
                initialWalletOpen = lastWalletOpen,
                onStateChanged = { tab, wallet ->
                    lastMainTab = tab
                    lastWalletOpen = wallet
                },
                onLogout = { logout() },
                onAccountDeleted = { accountDeleted() },
                onOpenNotifications = { navController.navigate(Routes.NOTIFICATIONS) },
                onOpenServices = { navController.navigate(Routes.SERVICES) },
            )
        }
        composable(Routes.NOTIFICATIONS) {
            NotificationsScreen(onBack = { navController.popBackStack() })
        }
        composable(Routes.SERVICES) {
            ServicesScreen(onBack = { navController.popBackStack() })
        }
    }
}
