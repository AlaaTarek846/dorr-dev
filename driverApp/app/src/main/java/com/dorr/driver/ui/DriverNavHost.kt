package com.dorr.driver.ui

import android.net.Uri
import androidx.compose.animation.AnimatedContentTransitionScope
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.AccountBalanceWallet
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Payments
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Person
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.Alignment
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.ui.unit.dp
import androidx.navigation.NavBackStackEntry
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import com.dorr.driver.R
import com.dorr.driver.duty.Duty
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.ui.kit.DriverBottomBar
import com.dorr.driver.ui.kit.NavTab

/** The four tabs of the bottom bar; every other screen opens over them. */
private val Tabs = listOf(
    NavTab("home", R.string.nav_home, Icons.Outlined.Home, Icons.Rounded.Home),
    NavTab("earnings", R.string.nav_earnings, Icons.Outlined.Payments, Icons.Rounded.Payments),
    NavTab("wallet", R.string.nav_wallet, Icons.Outlined.AccountBalanceWallet, Icons.Rounded.AccountBalanceWallet),
    NavTab("account", R.string.nav_account, Icons.Outlined.Person, Icons.Rounded.Person),
)
private val TabRoutes = Tabs.map { it.route }.toSet()

/** Between tabs, and in and out of the splash and sign-in, the screen fades; everything else slides in. */
private fun AnimatedContentTransitionScope<NavBackStackEntry>.fades(): Boolean {
    val from = initialState.destination.route
    val to = targetState.destination.route
    return (from in TabRoutes && to in TabRoutes) || from == "splash" || to == "splash" || to == "login" || from == "login" && to == "home"
}

/**
 * Splash, then sign-in (phone → code) or the shift: four tabs (home, earnings, wallet, account) on a bottom bar, and the
 * rest — the ride, signing up, preferences, cases… — opening over them, with a request card over all of it while the
 * driver is online. A 401 anywhere means sign-in again.
 */
@Composable
fun DriverNavHost() {
    val nav = rememberNavController()

    val signedOut by DriverBackend.signedOut.collectAsState()
    LaunchedEffect(signedOut) {
        if (signedOut > 0) {
            nav.navigate("login") { popUpTo(0) { inclusive = true } }
        }
    }
    // Accepted from the notification while the app was elsewhere: open the ride. (On the splash, Home finds the running ride itself.)
    val rideTick by Duty.rideChanged.collectAsState()
    LaunchedEffect(rideTick) {
        val here = nav.currentDestination?.route
        if (rideTick > 0 && DriverBackend.session.isSignedIn && here != "trip" && here != "splash") nav.navigate("trip")
    }

    val route = nav.currentBackStackEntryAsState().value?.destination?.route

    /** A tapped notification (the list or a push) opens what it's about — the trip / listing itself when it names one. */
    fun openFor(event: String, data: (String) -> String?) {
        fun id(key: String) = data(key)?.takeIf { it.isNotBlank() && it != "null" }
        when {
            event.startsWith("wallet") || event.contains("earning") -> nav.navigate("wallet") { popUpTo("home") { saveState = true }; launchSingleTop = true; restoreState = true }
            event.contains("safety") || event.contains("contact") -> nav.navigate("safety")
            event.startsWith("ride.scheduled") -> nav.navigate("scheduled")
            event.startsWith("bus.") -> id("trip_uuid")?.let { nav.navigate("bus/$it") } ?: nav.navigate("bus")
            event.startsWith("marine.") -> id("listing_uuid")?.let { nav.navigate("marine/$it") } ?: nav.navigate("marine")
            event.startsWith("hire.") -> nav.navigate("hire")
            event.startsWith("lesson.") -> nav.navigate("lessons")
            event == "driver.rank" || event == "driver.note" || event == "driver.appeal" -> nav.navigate("rank")
            event.startsWith("support.") -> nav.navigate("support")
            event.startsWith("lost.") -> nav.navigate("lost")
            event.startsWith("driver.") -> nav.navigate("signup")
            event.startsWith("ride.zone_") -> nav.navigate("airport")
            event.startsWith("ride.case_") -> nav.navigate("cases")
            event.startsWith("ride.") && Duty.onTrip.value -> nav.navigate("trip")
        }
    }
    val tapped by com.dorr.driver.live.DriverPush.tapped.collectAsState()
    // D-214, D-298: «not me» on a wallet transaction opens a support ticket about it.
    val supportPrefill by SupportPrefill.open.collectAsState()
    LaunchedEffect(supportPrefill) { if (supportPrefill != null && route != "support") nav.navigate("support") }
    LaunchedEffect(tapped, route) {
        val (event, data) = tapped ?: return@LaunchedEffect
        // After the splash has decided where to start.
        if (route == null || route == "splash" || route == "login" || !DriverBackend.session.isSignedIn) return@LaunchedEffect
        com.dorr.driver.live.DriverPush.tapped.value = null
        runCatching { openFor(event) { data[it] } }
    }

    fun goTab(to: String) {
        nav.navigate(to) {
            popUpTo("home") { saveState = true }
            launchSingleTop = true
            restoreState = true
        }
    }

    Box(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            Box(Modifier.weight(1f)) {
                NavHost(
                    navController = nav,
                    startDestination = "splash",
                    enterTransition = {
                        if (fades()) fadeIn(tween(260))
                        else slideIntoContainer(AnimatedContentTransitionScope.SlideDirection.Start, tween(300)) + fadeIn(tween(300))
                    },
                    exitTransition = {
                        if (fades()) fadeOut(tween(160))
                        else slideOutOfContainer(AnimatedContentTransitionScope.SlideDirection.Start, tween(300), targetOffset = { it / 4 }) + fadeOut(tween(220))
                    },
                    popEnterTransition = {
                        if (fades()) fadeIn(tween(260))
                        else slideIntoContainer(AnimatedContentTransitionScope.SlideDirection.End, tween(300), initialOffset = { it / 4 }) + fadeIn(tween(300))
                    },
                    popExitTransition = {
                        if (fades()) fadeOut(tween(160))
                        else slideOutOfContainer(AnimatedContentTransitionScope.SlideDirection.End, tween(300)) + fadeOut(tween(220))
                    },
                ) {
                    composable("splash") {
                        SplashScreen(onFinished = {
                            val next = if (DriverBackend.session.isSignedIn) "home" else "login"
                            nav.navigate(next) { popUpTo("splash") { inclusive = true } }
                        })
                    }
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
                        TermsGate()
                        HomeScreen(
                            onOpenSignup = { nav.navigate("signup") },
                            onOpenRide = { if (nav.currentDestination?.route != "trip") nav.navigate("trip") },
                            onOpenEarnings = { goTab("earnings") },
                            onOpenAirport = { nav.navigate("airport") },
                            onOpenNotifications = { nav.navigate("notifications") },
                        )
                    }
                    composable("earnings") { EarningsScreen(onBack = null) }
                    // O-35: the DORR app's wallet, the same in every detail (PIN, recovery, settings, top-up) — plus withdrawing.
                    composable("wallet") {
                        // O-46: the wallet (copied from the DORR app, where its host keeps it under the status bar) starts below it here too.
                        Box(Modifier.fillMaxSize().background(com.dorr.driver.wallet.Wa.Bg).statusBarsPadding()) { com.dorr.driver.wallet.WalletScreen(onExit = { goTab("home") }) }
                    }
                    composable("account") {
                        AccountScreen(
                            onOpenSignup = { nav.navigate("signup") },
                            onOpenPreferences = { nav.navigate("preferences") },
                            onOpenDriverAi = { nav.navigate("driver_ai") },
                            onOpenScheduled = { nav.navigate("scheduled") },
                            onOpenAirport = { nav.navigate("airport") },
                            onOpenCases = { nav.navigate("cases") },
                            onOpenSafety = { nav.navigate("safety") },
                            onOpenNotifications = { nav.navigate("notifications") },
                            onSignedOut = { nav.navigate("login") { popUpTo(0) { inclusive = true } } },
                            onOpenBus = { nav.navigate("bus") },
                            onOpenWithMe = { nav.navigate("withme") },
                            onOpenMarine = { nav.navigate("marine") },
                            onOpenHire = { nav.navigate("hire") },
                            onOpenLessons = { nav.navigate("lessons") },
                            onOpenRank = { nav.navigate("rank") },
                            onOpenSupport = { nav.navigate("support") },
                            onOpenSettings = { nav.navigate("settings") },
                        )
                    }
                    composable("rank") { RankScreen(onBack = { nav.popBackStack() }) }
                    composable("settings") { SettingsScreen(onBack = { nav.popBackStack() }, onClosed = { DriverBackend.session.clear(); nav.navigate("login") { popUpTo(0) { inclusive = true } } }) }
                    composable("support") { SupportScreen(onBack = { nav.popBackStack() }, onLostItems = { nav.navigate("lost") }) }
                    composable("lost") { LostItemsScreen(onBack = { nav.popBackStack() }) }
                    composable("withme") { WithMeScreen(onBack = { nav.popBackStack() }, onOpenRide = { nav.navigate("trip/$it") }) }
                    composable("trip/{uuid}", arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
                        TripScreen(onDone = { nav.popBackStack() }, rideUuid = entry.arguments?.getString("uuid"))
                    }
                    composable("hire") { HireJobsScreen(onBack = { nav.popBackStack() }) }
                    composable("lessons") { TrainerScreen(onBack = { nav.popBackStack() }) }
                    composable("marine") { MarineListingsScreen(onBack = { nav.popBackStack() }, onOpen = { nav.navigate("marine/$it") }) }
                    composable("marine/{uuid}", arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
                        MarineListingScreen(entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() })
                    }
                    composable("bus") { BusTripsScreen(onBack = { nav.popBackStack() }, onOpen = { nav.navigate("bus/$it") }) }
                    composable("bus/{uuid}", arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
                        BusTripDetailScreen(entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() })
                    }
                    composable("signup") { SignupScreen(onBack = { nav.popBackStack() }) }
                    composable("trip") { TripScreen(onDone = { nav.popBackStack("home", inclusive = false) }) }
                    composable("notifications") {
                        NotificationsScreen(onBack = { nav.popBackStack() }, onOpen = { n ->
                            // A tap goes where the notification is about.
                            openFor(n.event.orEmpty()) { k -> n.data?.get(k)?.takeIf { it.isJsonPrimitive }?.asString }
                        })
                    }
                    composable("safety") { SafetyCenterScreen(onBack = { nav.popBackStack() }) }
                    composable("airport") { AirportScreen(onBack = { nav.popBackStack() }) }
                    composable("preferences") { PreferencesScreen(onBack = { nav.popBackStack() }) }
                    // Block 06: DORR Driver AI and where the work is.
                    composable("driver_ai") { DriverAiScreen(onBack = { nav.popBackStack() }) }
                    composable("cases") { CasesScreen(onBack = { nav.popBackStack() }) }
                    composable("scheduled") {
                        ScheduledScreen(onBack = { nav.popBackStack() }, onStarted = { nav.navigate("trip") { popUpTo("home") } })
                    }
                }
            }

            // The bottom bar belongs to the four tabs only; it slides away on every other screen.
            AnimatedVisibility(
                visible = route in TabRoutes,
                enter = slideInVertically(tween(260)) { it } + fadeIn(tween(200)),
                exit = slideOutVertically(tween(220)) { it } + fadeOut(tween(160)),
            ) {
                DriverBottomBar(Tabs, selected = route.orEmpty(), onSelect = { goTab(it) })
            }
        }

        if (DriverBackend.session.isSignedIn) {
            // O-36: the requests put aside wait behind this icon (with how many), above every screen.
            OffersInboxButton(Modifier.align(Alignment.BottomEnd).navigationBarsPadding().padding(end = 18.dp, bottom = 96.dp))
            OfferOverlay(onAccepted = { nav.navigate("trip") })
        }
    }
}
