package com.dorr.app.navigation

import android.os.Handler
import android.os.Looper
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
import com.dorr.app.ui.screens.LoginScreen
import com.dorr.app.ui.screens.MainScreen
import com.dorr.app.ui.screens.NotificationsScreen
import com.dorr.app.ui.screens.OtpScreen
import com.dorr.app.ui.screens.ServicesScreen
import com.dorr.app.ui.screens.SplashScreen
import kotlinx.coroutines.launch

object Routes {
    const val SPLASH = "splash"
    const val LOGIN = "login"
    const val OTP = "otp"
    const val MAIN = "main"
    const val NOTIFICATIONS = "notifications"
    const val SERVICES = "services"
}

@Composable
fun DorrNavGraph(navController: NavHostController = rememberNavController()) {
    // Held here (not as a nav argument) so the phone number never has to
    // round-trip through URL encoding on its way to the OTP screen.
    var pendingDialCode by remember { mutableStateOf("") }
    var pendingPhone by remember { mutableStateOf("") }
    var sessionExpiredNotice by remember { mutableStateOf(false) }

    // Preserve the page the user was on before being kicked out by 401
    var targetRouteAfterLogin by remember { mutableStateOf<String?>(null) }
    var lastMainTab by remember { mutableIntStateOf(0) }
    var lastWalletOpen by remember { mutableStateOf(false) }

    val scope = rememberCoroutineScope()

    // A 401 on any authenticated request (expired/revoked token) is detected
    // by the ApiClient interceptor, which clears AuthSession and fires this
    // callback. Route back to Login on the main thread — never from the
    // interceptor's background thread.
    DisposableEffect(Unit) {
        AuthSession.onUnauthorized = {
            Handler(Looper.getMainLooper()).post {
                sessionExpiredNotice = true
                val currentRoute = navController.currentDestination?.route
                if (currentRoute != null && currentRoute != Routes.LOGIN && currentRoute != Routes.SPLASH && currentRoute != Routes.OTP) {
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
        AuthSession.clear()
        targetRouteAfterLogin = null
        lastMainTab = 0
        lastWalletOpen = false
        sessionExpiredNotice = false
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

    NavHost(
        navController = navController,
        startDestination = Routes.SPLASH,
        enterTransition = { fadeIn() },
        exitTransition = { fadeOut() },
    ) {
        composable(Routes.SPLASH) {
            SplashScreen(
                onFinished = {
                    // A persisted session (restored in DorrApp.onCreate) skips
                    // the auth flow and lands directly on Main.
                    val destination = if (AuthSession.isAuthenticated) Routes.MAIN else Routes.LOGIN
                    navController.navigate(destination) {
                        popUpTo(Routes.SPLASH) { inclusive = true }
                    }
                },
            )
        }
        composable(Routes.LOGIN) {
            LoginScreen(
                sessionExpiredNotice = sessionExpiredNotice,
                onDismissSessionExpired = { sessionExpiredNotice = false },
                onOtpRequested = { dialCode, phone ->
                    sessionExpiredNotice = false
                    pendingDialCode = dialCode
                    pendingPhone = phone
                    navController.navigate(Routes.OTP)
                },
            )
        }
        composable(Routes.OTP) {
            OtpScreen(
                dialCode = pendingDialCode,
                phoneNumber = pendingPhone,
                onBack = { navController.popBackStack() },
                onVerified = {
                    val returnDestination = targetRouteAfterLogin
                    targetRouteAfterLogin = null
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
