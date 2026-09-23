package com.dorr.app.navigation

import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import com.dorr.app.ui.screens.LoginScreen
import com.dorr.app.ui.screens.MainScreen
import com.dorr.app.ui.screens.NotificationsScreen
import com.dorr.app.ui.screens.OtpScreen
import com.dorr.app.ui.screens.SplashScreen

object Routes {
    const val SPLASH = "splash"
    const val LOGIN = "login"
    const val OTP = "otp"
    const val MAIN = "main"
    const val NOTIFICATIONS = "notifications"
}

@Composable
fun DorrNavGraph(navController: NavHostController = rememberNavController()) {
    // Held here (not as a nav argument) so the phone number never has to
    // round-trip through URL encoding on its way to the OTP screen.
    var pendingPhone by remember { mutableStateOf("") }

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
                    pendingPhone = "$dialCode $phone"
                    navController.navigate(Routes.OTP)
                },
            )
        }
        composable(Routes.OTP) {
            OtpScreen(
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
                onLogout = {
                    navController.navigate(Routes.LOGIN) {
                        popUpTo(Routes.MAIN) { inclusive = true }
                    }
                },
                onOpenNotifications = { navController.navigate(Routes.NOTIFICATIONS) },
            )
        }
        composable(Routes.NOTIFICATIONS) {
            NotificationsScreen(onBack = { navController.popBackStack() })
        }
    }
}
