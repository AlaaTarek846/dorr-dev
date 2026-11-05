package com.dorr.provider.navigation

import android.net.Uri
import androidx.activity.compose.LocalOnBackPressedDispatcherOwner
import androidx.compose.animation.AnimatedContentTransitionScope
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.navigation.NavHostController
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import com.dorr.provider.net.Realtime
import com.dorr.provider.net.Session
import com.dorr.provider.ui.components.AppShell
import com.dorr.provider.ui.components.ShellState
import com.dorr.provider.ui.components.TAB_HOME
import com.dorr.provider.ui.components.TAB_WALLET
import com.dorr.provider.net.can
import com.dorr.provider.ui.screens.BranchesScreen
import com.dorr.provider.ui.screens.DocumentsScreen
import com.dorr.provider.ui.screens.JobScreen
import com.dorr.provider.ui.screens.JobsScreen
import com.dorr.provider.ui.screens.MyJobsScreen
import com.dorr.provider.ui.screens.OffersScreen
import com.dorr.provider.ui.screens.RequestScreen
import com.dorr.provider.ui.screens.RequestsScreen
import com.dorr.provider.ui.screens.TeamScreen
import com.dorr.provider.ui.screens.TicketScreen
import com.dorr.provider.ui.screens.TicketsScreen
import com.dorr.provider.ui.screens.VehicleScreen
import com.dorr.provider.ui.screens.VehiclesScreen
import com.dorr.provider.ui.screens.InvitationsScreen
import com.dorr.provider.ui.screens.LoginScreen
import com.dorr.provider.ui.screens.MainActions
import com.dorr.provider.ui.screens.MainScreen
import com.dorr.provider.ui.screens.NotificationsScreen
import com.dorr.provider.ui.screens.OnboardingScreen
import com.dorr.provider.ui.screens.OtpScreen
import com.dorr.provider.ui.screens.SplashScreen
import com.dorr.provider.ui.screens.WorkspaceGateScreen

/**
 * The app's routes: splash → sign-in (phone → code) → the workspace gate, which opens the establishment I work in (home), the
 * invitations waiting for me, or the onboarding that creates my own establishment (C-02) → home.
 */
object Routes {
    const val SPLASH = "splash"
    const val LOGIN = "login"
    const val OTP = "otp/{dial}/{phone}/{cooldown}"
    /** Reads `workspace` once and sends the person on (no screen of its own beyond a spinner / a retry). */
    const val WORKSPACE = "workspace"
    const val ONBOARDING = "onboarding"
    const val INVITATIONS = "invitations"
    const val HOME = "home"
    const val DOCUMENTS = "documents"
    const val NOTIFICATIONS = "notifications"
    /** Not a page: the wallet is a tab — [openRoute] lights it (a notice about money leads here). */
    const val WALLET = "wallet"

    // C-03: running the establishment.
    const val BRANCHES = "branches"
    const val TEAM = "team"
    const val VEHICLES = "vehicles"
    const val VEHICLE = "vehicle/{uuid}"
    const val DRIVER_DOCS = "driver-docs/{uuid}"

    // C-04: the moving work.
    const val REQUESTS = "requests"
    const val REQUEST = "request/{uuid}"
    const val OFFERS = "offers?approval={approval}"
    const val JOBS = "jobs?unassigned={unassigned}"
    const val JOB = "job/{uuid}"
    const val MY_JOBS = "my-jobs"
    const val TICKETS = "tickets"
    const val TICKET = "ticket/{uuid}"

    fun otp(dial: String, phone: String, cooldown: Int) = "otp/${Uri.encode(dial)}/${Uri.encode(phone)}/$cooldown"
    fun vehicle(uuid: String) = "vehicle/$uuid"
    fun driverDocs(uuid: String) = "driver-docs/$uuid"
    fun request(uuid: String) = "request/$uuid"
    fun offers(approval: Boolean) = "offers?approval=$approval"
    fun jobs(unassigned: Boolean) = "jobs?unassigned=$unassigned"
    fun job(uuid: String) = "job/$uuid"
    fun ticket(uuid: String) = "ticket/$uuid"
}

@Composable
fun ProviderNavGraph() {
    val nav = rememberNavController()
    val signedIn by Session.signedIn.collectAsState()
    val backDispatcher = LocalOnBackPressedDispatcherOwner.current?.onBackPressedDispatcher

    /** Back from a page that may be the only one on the stack (the onboarding opened from the gate): leave the app then. */
    fun backOrLeave() {
        if (nav.previousBackStackEntry != null) nav.popBackStack() else backDispatcher?.onBackPressed()
    }

    // A session that ended (the server answered 401, or the person signed out) sends the app back to sign-in by itself.
    LaunchedEffect(signedIn) {
        val current = nav.currentDestination?.route
        val beforeSignIn = current == null || current == Routes.LOGIN || current == Routes.OTP || current == Routes.SPLASH
        if (!signedIn) ShellState.tab = TAB_HOME
        if (!signedIn && !beforeSignIn) nav.navigate(Routes.LOGIN) { popUpTo(0) { inclusive = true } }
    }

    // A notification tapped in the phone's tray (push): the screen it is about, or the notifications page — once signed in and
    // past the workspace gate.
    val tapped by Realtime.opened.collectAsState()
    val tappedRoute by Realtime.openedRoute.collectAsState()
    LaunchedEffect(tapped, tappedRoute, signedIn) {
        val here = nav.currentDestination?.route
        val ready = here != null && here != Routes.SPLASH && here != Routes.LOGIN && here != Routes.OTP && here != Routes.WORKSPACE && here != Routes.ONBOARDING
        if (tapped != null && signedIn && ready) {
            val target = tappedRoute
            Realtime.consumeOpened()
            openRoute(nav, target ?: Routes.NOTIFICATIONS)
        }
    }

    // Every page over the tabs sits in the same frame as the tabs: the bottom bar (the header with the wallet and the bell is on the
    // Home tab only). A tab tapped there goes back to the tabs on that tab.
    val shell: @Composable (@Composable () -> Unit) -> Unit = { page ->
        AppShell(
            showHeader = false,
            onSelectTab = { key ->
                ShellState.tab = key
                nav.popBackStack(Routes.HOME, inclusive = false)
            },
            onOpenWallet = { openRoute(nav, Routes.WALLET) },
            onOpenNotifications = { nav.navigate(Routes.NOTIFICATIONS) { launchSingleTop = true } },
            onOpenInvitations = { nav.navigate(Routes.INVITATIONS) { launchSingleTop = true } },
            content = page,
        )
    }

    // Pages slide and fade between each other like the DORR app: forward from the trailing edge, back to it.
    NavHost(
        navController = nav,
        startDestination = Routes.SPLASH,
        enterTransition = {
            slideIntoContainer(AnimatedContentTransitionScope.SlideDirection.Start, tween(340, easing = FastOutSlowInEasing)) + fadeIn(tween(280))
        },
        exitTransition = {
            slideOutOfContainer(AnimatedContentTransitionScope.SlideDirection.Start, tween(320, easing = FastOutSlowInEasing)) + fadeOut(tween(240))
        },
        popEnterTransition = {
            slideIntoContainer(AnimatedContentTransitionScope.SlideDirection.End, tween(340, easing = FastOutSlowInEasing)) + fadeIn(tween(280))
        },
        popExitTransition = {
            slideOutOfContainer(AnimatedContentTransitionScope.SlideDirection.End, tween(320, easing = FastOutSlowInEasing)) + fadeOut(tween(240))
        },
    ) {
        composable(Routes.SPLASH, exitTransition = { fadeOut(tween(400)) }) {
            SplashScreen(onFinished = {
                val next = if (Session.signedIn.value) Routes.WORKSPACE else Routes.LOGIN
                nav.navigate(next) { popUpTo(Routes.SPLASH) { inclusive = true } }
            })
        }
        composable(Routes.LOGIN) {
            LoginScreen(onOtpSent = { dial, phone, cooldown -> nav.navigate(Routes.otp(dial, phone, cooldown)) })
        }
        composable(
            Routes.OTP,
            arguments = listOf(
                navArgument("dial") { type = NavType.StringType },
                navArgument("phone") { type = NavType.StringType },
                navArgument("cooldown") { type = NavType.IntType },
            ),
        ) { entry ->
            OtpScreen(
                dialCode = entry.arguments?.getString("dial").orEmpty(),
                phoneNumber = entry.arguments?.getString("phone").orEmpty(),
                cooldownSeconds = entry.arguments?.getInt("cooldown") ?: 60,
                onBack = { nav.popBackStack() },
                onVerified = { nav.navigate(Routes.WORKSPACE) { popUpTo(0) { inclusive = true } } },
            )
        }
        composable(Routes.WORKSPACE) {
            WorkspaceGateScreen(
                onHome = { nav.navigate(Routes.HOME) { popUpTo(0) { inclusive = true } } },
                onInvitations = { nav.navigate(Routes.INVITATIONS) { popUpTo(0) { inclusive = true } } },
                onOnboarding = { nav.navigate(Routes.ONBOARDING) { popUpTo(0) { inclusive = true } } },
            )
        }
        composable(Routes.ONBOARDING) {
            OnboardingScreen(
                onExit = ::backOrLeave,
                // The new establishment is chosen already: the home, with its papers page on top.
                onCreated = {
                    nav.navigate(Routes.HOME) { popUpTo(0) { inclusive = true } }
                    nav.navigate(Routes.DOCUMENTS)
                },
            )
        }
        composable(Routes.INVITATIONS) {
            InvitationsScreen(
                onBack = ::backOrLeave,
                onJoined = { nav.navigate(Routes.HOME) { popUpTo(0) { inclusive = true } } },
                onCreateOwn = { nav.navigate(Routes.ONBOARDING) },
                // Nothing left to answer and no seat anywhere: the gate decides again (the onboarding).
                onNothingLeft = { nav.navigate(Routes.WORKSPACE) { popUpTo(0) { inclusive = true } } },
            )
        }
        composable(Routes.HOME) {
            MainScreen(
                MainActions(
                    openDocuments = { nav.navigate(Routes.DOCUMENTS) { launchSingleTop = true } },
                    openInvitations = { nav.navigate(Routes.INVITATIONS) { launchSingleTop = true } },
                    openNotifications = { nav.navigate(Routes.NOTIFICATIONS) { launchSingleTop = true } },
                    openOnboarding = { nav.navigate(Routes.ONBOARDING) { launchSingleTop = true } },
                    openWorkspace = { nav.navigate(Routes.WORKSPACE) { popUpTo(0) { inclusive = true } } },
                    openBranches = { nav.navigate(Routes.BRANCHES) { launchSingleTop = true } },
                    openTeam = { nav.navigate(Routes.TEAM) { launchSingleTop = true } },
                    openVehicles = { nav.navigate(Routes.VEHICLES) { launchSingleTop = true } },
                    openDriverDocs = { uuid -> nav.navigate(Routes.driverDocs(uuid)) },
                    openRequests = { nav.navigate(Routes.REQUESTS) { launchSingleTop = true } },
                    openOffers = { approval -> nav.navigate(Routes.offers(approval)) },
                    openJobs = { unassigned -> nav.navigate(Routes.jobs(unassigned)) },
                    openMyJobs = { nav.navigate(Routes.MY_JOBS) { launchSingleTop = true } },
                    openTickets = { nav.navigate(Routes.TICKETS) { launchSingleTop = true } },
                ),
            )
        }
        composable(Routes.DOCUMENTS) { shell { DocumentsScreen(onBack = { nav.popBackStack() }) } }
        // C-03
        composable(Routes.BRANCHES) { shell { BranchesScreen(onBack = { nav.popBackStack() }) } }
        composable(Routes.TEAM) { shell { TeamScreen(onBack = { nav.popBackStack() }, onOpenDriverDocs = { uuid -> nav.navigate(Routes.driverDocs(uuid)) }) } }
        composable(Routes.VEHICLES) { shell { VehiclesScreen(onBack = { nav.popBackStack() }, onOpenVehicle = { uuid -> nav.navigate(Routes.vehicle(uuid)) }) } }
        composable(Routes.VEHICLE, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell { VehicleScreen(uuid = entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() }) }
        }
        composable(Routes.DRIVER_DOCS, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell { DocumentsScreen(onBack = { nav.popBackStack() }, driverUuid = entry.arguments?.getString("uuid").orEmpty()) }
        }
        // C-04
        composable(Routes.REQUESTS) { shell { RequestsScreen(onBack = { nav.popBackStack() }, onOpenRequest = { uuid -> nav.navigate(Routes.request(uuid)) }) } }
        composable(Routes.REQUEST, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            val seat by Session.membership.collectAsState()
            shell { RequestScreen(uuid = entry.arguments?.getString("uuid").orEmpty(), canOffer = seat.can("offers.send"), onBack = { nav.popBackStack() }) }
        }
        composable(Routes.OFFERS, arguments = listOf(navArgument("approval") { type = NavType.BoolType; defaultValue = false })) { entry ->
            shell { OffersScreen(startOnApproval = entry.arguments?.getBoolean("approval") ?: false, onBack = { nav.popBackStack() }, onOpenRequest = { uuid -> nav.navigate(Routes.request(uuid)) }) }
        }
        composable(Routes.JOBS, arguments = listOf(navArgument("unassigned") { type = NavType.BoolType; defaultValue = false })) { entry ->
            shell { JobsScreen(startUnassigned = entry.arguments?.getBoolean("unassigned") ?: false, onBack = { nav.popBackStack() }, onOpenJob = { uuid -> nav.navigate(Routes.job(uuid)) }) }
        }
        composable(Routes.JOB, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell { JobScreen(uuid = entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() }) }
        }
        composable(Routes.MY_JOBS) { shell { MyJobsScreen(onBack = { nav.popBackStack() }, onOpenJob = { uuid -> nav.navigate(Routes.job(uuid)) }) } }
        composable(Routes.TICKETS) { shell { TicketsScreen(onBack = { nav.popBackStack() }, onOpenTicket = { uuid -> nav.navigate(Routes.ticket(uuid)) }) } }
        composable(Routes.TICKET, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell { TicketScreen(uuid = entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() }, onOpenJob = { uuid -> nav.navigate(Routes.job(uuid)) }) }
        }
        composable(Routes.NOTIFICATIONS) { shell { NotificationsScreen(onBack = { nav.popBackStack() }, onOpenRoute = { route -> openRoute(nav, route) }) } }
    }
}

/** Opens [route] from anywhere: the wallet and the home are tabs (the home frame on the right tab), the rest pages over the tabs. */
private fun openRoute(nav: NavHostController, route: String) {
    when (route) {
        Routes.HOME -> {
            ShellState.tab = TAB_HOME
            nav.popBackStack(Routes.HOME, inclusive = false)
        }
        Routes.WALLET -> {
            ShellState.tab = TAB_WALLET
            nav.popBackStack(Routes.HOME, inclusive = false)
        }
        else -> nav.navigate(route) { launchSingleTop = true }
    }
}
