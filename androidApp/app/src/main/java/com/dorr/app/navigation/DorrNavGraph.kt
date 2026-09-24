package com.dorr.app.navigation

import android.os.Handler
import android.os.Looper
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
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
import com.dorr.app.ui.screens.wallet.WalletScreen
import kotlinx.coroutines.launch

object Routes {
    const val SPLASH = "splash"
    const val LOGIN = "login"
    const val OTP = "otp"
    const val MAIN = "main"
    const val NOTIFICATIONS = "notifications"
    const val WALLET = "wallet"
    const val SERVICES = "services"
}

@Composable
fun DorrNavGraph(navController: NavHostController = rememberNavController()) {
    // Held here (not as a nav argument) so the phone number never has to
    // round-trip through URL encoding on its way to the OTP screen.
    var pendingDialCode by remember { mutableStateOf("") }
    var pendingPhone by remember { mutableStateOf("") }
    val scope = rememberCoroutineScope()

    // A 401 on any authenticated request (expired/revoked token) is detected
    // by the ApiClient interceptor, which clears AuthSession and fires this
    // callback. Route back to Login on the main thread — never from the
    // interceptor's background thread.
    DisposableEffect(Unit) {
        AuthSession.onUnauthorized = {
            Handler(Looper.getMainLooper()).post {
                if (navController.currentDestination?.route != Routes.LOGIN) {
                    navController.navigate(Routes.LOGIN) {
                        popUpTo(Routes.MAIN) { inclusive = true }
                    }
                }
            }
        }
        onDispose { AuthSession.onUnauthorized = null }
    }

    fun logout() {
        val token = AuthSession.token
        AuthSession.clear()
        if (!token.isNullOrBlank()) {
            scope.launch {
                runCatching {
                    ApiClient.mobileAuth.logout("Bearer $token")
                }
            }
        }
        navController.navigate(Routes.LOGIN) {
            popUpTo(Routes.MAIN) { inclusive = true }
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
                    navController.navigate(Routes.LOGIN) {
                        popUpTo(Routes.SPLASH) { inclusive = true }
                    }
                },
            )
        }
        composable(Routes.LOGIN) {
            LoginScreen(
                onOtpRequested = { dialCode, phone ->
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
                    navController.navigate(Routes.MAIN) {
                        popUpTo(Routes.LOGIN) { inclusive = true }
                    }
                },
            )
        }
        composable(Routes.MAIN) {
            MainScreen(
                onLogout = { logout() },
                onOpenNotifications = { navController.navigate(Routes.NOTIFICATIONS) },
                onOpenWallet = { navController.navigate(Routes.WALLET) },
                onOpenServices = { navController.navigate(Routes.SERVICES) },
            )
        }
        composable(Routes.NOTIFICATIONS) {
            NotificationsScreen(onBack = { navController.popBackStack() })
        }
        composable(Routes.SERVICES) {
            ServicesScreen(onBack = { navController.popBackStack() })
        }
        composable(Routes.WALLET) {
            WalletScreen(onBack = { navController.popBackStack() })
        }
    }
}
