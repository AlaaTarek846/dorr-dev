package com.dorr.driver.ui

import android.net.Uri
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import com.dorr.driver.duty.Duty
import com.dorr.driver.net.DriverBackend

/**
 * Sign-in (phone → code), then the shift: home, signing up, the ride, the earnings — with a
 * request card over all of them while the driver is online. A 401 anywhere means sign-in again.
 */
@Composable
fun DriverNavHost() {
    val nav = rememberNavController()
    val start = if (DriverBackend.session.isSignedIn) "home" else "login"

    val signedOut by DriverBackend.signedOut.collectAsState()
    LaunchedEffect(signedOut) {
        if (signedOut > 0) {
            nav.navigate("login") { popUpTo(0) { inclusive = true } }
        }
    }
    // Accepted from the notification while the app was elsewhere: open the ride.
    val rideTick by Duty.rideChanged.collectAsState()
    LaunchedEffect(rideTick) {
        if (rideTick > 0 && DriverBackend.session.isSignedIn && nav.currentDestination?.route != "trip") nav.navigate("trip")
    }

    Box(Modifier.fillMaxSize()) {
        NavHost(navController = nav, startDestination = start) {
            composable("login") {
                LoginScreen(onCodeSent = { dial, phone, masked, cooldown ->
                    // "+" and "*" must survive the route (Navigation decodes the arguments back).
                    nav.navigate("otp/${Uri.encode(dial)}/${Uri.encode(phone)}/${Uri.encode(masked)}/$cooldown")
                })
            }
            composable(
                "otp/{dial}/{phone}/{masked}/{cooldown}",
                arguments = listOf(
                    navArgument("dial") { type = NavType.StringType },
                    navArgument("phone") { type = NavType.StringType },
                    navArgument("masked") { type = NavType.StringType },
                    navArgument("cooldown") { type = NavType.IntType },
                ),
            ) { entry ->
                val args = requireNotNull(entry.arguments)
                OtpScreen(
                    dialCode = args.getString("dial").orEmpty(),
                    phone = args.getString("phone").orEmpty(),
                    maskedPhone = args.getString("masked").orEmpty(),
                    initialCooldown = args.getInt("cooldown"),
                    onBack = { nav.popBackStack() },
                    onSignedIn = { nav.navigate("home") { popUpTo(0) { inclusive = true } } },
                )
            }
            composable("home") {
                HomeScreen(
                    onSignedOut = { nav.navigate("login") { popUpTo(0) { inclusive = true } } },
                    onOpenSignup = { nav.navigate("signup") },
                    onOpenRide = { if (nav.currentDestination?.route != "trip") nav.navigate("trip") },
                    onOpenEarnings = { nav.navigate("earnings") },
                    onOpenScheduled = { nav.navigate("scheduled") },
                    onOpenSafety = { nav.navigate("safety") },
                    onOpenNotifications = { nav.navigate("notifications") },
                    onOpenWallet = { nav.navigate("wallet") },
                )
            }
            composable("signup") { SignupScreen(onBack = { nav.popBackStack() }) }
            composable("trip") { TripScreen(onDone = { nav.popBackStack("home", inclusive = false) }) }
            composable("earnings") { EarningsScreen(onBack = { nav.popBackStack() }) }
            composable("wallet") { WalletScreen(onBack = { nav.popBackStack() }) }
            composable("notifications") {
                NotificationsScreen(onBack = { nav.popBackStack() }, onOpen = { n ->
                    // A tap goes where the notification is about.
                    val event = n.event.orEmpty()
                    when {
                        event.startsWith("wallet") || event.contains("earnings") -> nav.navigate("wallet")
                        event.contains("safety") || event.contains("contact") -> nav.navigate("safety")
                        event.startsWith("ride.scheduled") -> nav.navigate("scheduled")
                        event.startsWith("ride.") && Duty.onTrip.value -> nav.navigate("trip")
                    }
                })
            }
            composable("safety") { SafetyCenterScreen(onBack = { nav.popBackStack() }) }
            composable("scheduled") {
                ScheduledScreen(onBack = { nav.popBackStack() }, onStarted = { nav.navigate("trip") { popUpTo("home") } })
            }
        }

        if (DriverBackend.session.isSignedIn) OfferOverlay(onAccepted = { nav.navigate("trip") })
    }
}
