package com.dorr.freelance.navigation

import android.net.Uri
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import com.dorr.freelance.R
import com.dorr.freelance.net.Gate
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.Session
import com.dorr.freelance.net.Welcome
import com.dorr.freelance.ui.components.AppShell
import com.dorr.freelance.ui.components.ShellState
import com.dorr.freelance.ui.screens.AvailabilityScreen
import com.dorr.freelance.ui.screens.CertificatesScreen
import com.dorr.freelance.ui.screens.ExperiencesScreen
import com.dorr.freelance.ui.screens.MainActions
import com.dorr.freelance.ui.screens.NotificationsScreen
import com.dorr.freelance.ui.screens.MainScreen
import com.dorr.freelance.ui.screens.PortfolioFormScreen
import com.dorr.freelance.ui.screens.PortfolioTab
import com.dorr.freelance.ui.screens.ProjectDetailScreen
import com.dorr.freelance.ui.screens.OfferFormScreen
import com.dorr.freelance.ui.screens.EditOfferScreen
import com.dorr.freelance.ui.screens.MyOffersScreen
import com.dorr.freelance.ui.screens.LoginScreen
import com.dorr.freelance.ui.screens.OtpScreen
import com.dorr.freelance.ui.screens.ProfileScreen
import com.dorr.freelance.ui.screens.SkillsScreen
import com.dorr.freelance.ui.screens.SpecialtiesScreen
import com.dorr.freelance.ui.screens.SplashScreen
import com.dorr.freelance.ui.screens.StartScreen
import com.dorr.freelance.ui.screens.TermsScreen
import com.dorr.freelance.ui.screens.VerificationScreen
import com.dorr.freelance.ui.screens.WalletRoute
import com.dorr.freelance.ui.screens.stepText

/**
 * The app's routes: splash → welcome (once, on the first launch after install) → sign-in (phone → code) → home. A signed-in
 * freelancer who has not agreed to the terms is sent to [Routes.TERMS] before the home; the profile (name included) is filled in later.
 */
object Routes {
    const val SPLASH = "splash"
    const val START = "start"
    const val LOGIN = "login"
    const val OTP = "otp/{dial}/{phone}/{cooldown}"
    const val HOME = "home"
    const val TERMS = "terms"
    const val PROFILE = "profile"
    const val SPECIALTIES = "specialties"
    const val SKILLS = "skills"
    const val EXPERIENCES = "experiences"
    const val AVAILABILITY = "availability"
    const val PORTFOLIO = "portfolio"
    const val PROJECT = "project/{uuid}"
    const val OFFER_FORM = "offer/{uuid}"
    const val OFFER_EDIT = "offer-edit/{uuid}"
    const val MY_OFFERS = "offers"
    const val CERTIFICATES = "certificates"
    const val VERIFICATION = "verification"
    const val NOTIFICATIONS = "notifications"
    const val WALLET = "wallet"
    const val PORTFOLIO_ITEM = "portfolio/{id}"

    fun project(uuid: String) = "project/$uuid"

    fun offerForm(uuid: String) = "offer/$uuid"

    fun offerEdit(uuid: String) = "offer-edit/$uuid"

    fun portfolioItem(id: Int) = "portfolio/$id"

    fun otp(dial: String, phone: String, cooldown: Int) = "otp/${Uri.encode(dial)}/${Uri.encode(phone)}/$cooldown"
}

@Composable
fun FreelanceNavGraph() {
    val nav = rememberNavController()
    val context = LocalContext.current
    val signedIn by Session.signedIn.collectAsState()
    val me by Session.me.collectAsState()

    // A session that ended (the server answered 401, or the freelancer signed out) sends the app back to sign-in by itself.
    LaunchedEffect(signedIn) {
        val current = nav.currentDestination?.route
        val beforeSignIn = current == null || current == Routes.LOGIN || current == Routes.OTP || current == Routes.SPLASH || current == Routes.START
        if (!signedIn) ShellState.tab = 0
        if (!signedIn && !beforeSignIn) nav.navigate(Routes.LOGIN) { popUpTo(0) { inclusive = true } }
    }

    // The terms come first: as soon as the account is known and they are not accepted, from the home.
    LaunchedEffect(signedIn, me?.terms?.accepted) {
        if (signedIn && me?.terms?.accepted == false && nav.currentDestination?.route == Routes.HOME) nav.navigate(Routes.TERMS)
    }

    // FL-110: a request that needs a verified account was refused — say which step they are at, once, from any screen.
    val gateStep by Gate.step.collectAsState()
    gateStep?.let { step ->
        AlertDialog(
            onDismissRequest = { Gate.dismiss() },
            title = { Text(stringResource(R.string.gate_title)) },
            text = { Text(stepText(step, null)) },
            confirmButton = {
                TextButton(onClick = {
                    Gate.dismiss()
                    nav.navigate(Routes.VERIFICATION) { launchSingleTop = true }
                }) { Text(stringResource(R.string.gate_open)) }
            },
            dismissButton = { TextButton(onClick = { Gate.dismiss() }) { Text(stringResource(R.string.common_cancel)) } },
        )
    }

    // A notification tapped in the phone's tray (push): the notifications page, once the freelancer is signed in.
    val tapped by Realtime.opened.collectAsState()
    LaunchedEffect(tapped, signedIn) {
        if (tapped != null && signedIn && nav.currentDestination?.route == Routes.HOME) {
            Realtime.consumeOpened()
            nav.navigate(Routes.NOTIFICATIONS)
        }
    }

    fun afterWelcome() = if (Session.signedIn.value) Routes.HOME else Routes.LOGIN

    // Every page over the tabs sits in the same frame as the tabs: the header (wallet, bell) and the bottom bar. A tab tapped
    // there goes back to the tabs on that tab; the wallet and the bell open once (a second tap on the open one does nothing).
    val shell: @Composable (Boolean, @Composable () -> Unit) -> Unit = { header, page ->
        AppShell(
            showHeader = header,
            onSelectTab = { index ->
                ShellState.tab = index
                nav.popBackStack(Routes.HOME, inclusive = false)
            },
            onOpenWallet = { nav.navigate(Routes.WALLET) { launchSingleTop = true } },
            onOpenNotifications = { nav.navigate(Routes.NOTIFICATIONS) { launchSingleTop = true } },
            content = page,
        )
    }

    NavHost(navController = nav, startDestination = Routes.SPLASH) {
        composable(Routes.SPLASH) {
            SplashScreen(onFinished = {
                val next = if (Welcome.seen(context)) afterWelcome() else Routes.START
                nav.navigate(next) { popUpTo(Routes.SPLASH) { inclusive = true } }
            })
        }
        composable(Routes.START) {
            StartScreen(onStart = {
                Welcome.markSeen(context)
                nav.navigate(afterWelcome()) { popUpTo(Routes.START) { inclusive = true } }
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
                onVerified = { nav.navigate(Routes.HOME) { popUpTo(0) { inclusive = true } } },
            )
        }
        composable(Routes.HOME) {
            MainScreen(
                MainActions(
                    openProfile = { nav.navigate(Routes.PROFILE) },
                    openSpecialties = { nav.navigate(Routes.SPECIALTIES) },
                    openSkills = { nav.navigate(Routes.SKILLS) },
                    openExperiences = { nav.navigate(Routes.EXPERIENCES) },
                    openAvailability = { nav.navigate(Routes.AVAILABILITY) },
                    openPortfolio = { nav.navigate(Routes.PORTFOLIO) },
                    openProject = { uuid -> nav.navigate(Routes.project(uuid)) },
                    openMyOffers = { nav.navigate(Routes.MY_OFFERS) },
                    openCertificates = { nav.navigate(Routes.CERTIFICATES) },
                    openVerification = { nav.navigate(Routes.VERIFICATION) },
                    openPortfolioItem = { id -> nav.navigate(Routes.portfolioItem(id)) },
                    openNotifications = { nav.navigate(Routes.NOTIFICATIONS) },
                    openWallet = { nav.navigate(Routes.WALLET) },
                ),
            )
        }
        composable(Routes.SPECIALTIES) { shell(true) { SpecialtiesScreen(onBack = { nav.popBackStack() }) } }
        composable(Routes.SKILLS) { shell(true) { SkillsScreen(onBack = { nav.popBackStack() }) } }
        composable(Routes.PORTFOLIO) { shell(true) { PortfolioTab(onOpenItem = { id -> nav.navigate(Routes.portfolioItem(id)) }) } }
        composable(Routes.PROJECT, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            val uuid = entry.arguments?.getString("uuid").orEmpty()
            shell(true) { ProjectDetailScreen(uuid = uuid, onBack = { nav.popBackStack() }, onOffer = { nav.navigate(Routes.offerForm(uuid)) }, onEditOffer = { nav.navigate(Routes.offerEdit(uuid)) }) }
        }
        composable(Routes.OFFER_FORM, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell(true) { OfferFormScreen(projectUuid = entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() }, onSent = { nav.popBackStack() }) }
        }
        composable(Routes.OFFER_EDIT, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell(true) { EditOfferScreen(projectUuid = entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() }, onDone = { nav.popBackStack() }) }
        }
        composable(Routes.MY_OFFERS) { shell(true) { MyOffersScreen(onBack = { nav.popBackStack() }, onOpenProject = { uuid -> nav.navigate(Routes.project(uuid)) }) } }
        composable(Routes.AVAILABILITY) { shell(true) { AvailabilityScreen(onBack = { nav.popBackStack() }) } }
        composable(Routes.EXPERIENCES) { shell(true) { ExperiencesScreen(onBack = { nav.popBackStack() }) } }
        composable(Routes.PORTFOLIO_ITEM, arguments = listOf(navArgument("id") { type = NavType.IntType })) { entry ->
            shell(true) { PortfolioFormScreen(itemId = entry.arguments?.getInt("id") ?: 0, onBack = { nav.popBackStack() }) }
        }
        // The wallet draws its own page header (title, back), so the app header is left out there.
        composable(Routes.WALLET) { shell(false) { WalletRoute(onBack = { nav.popBackStack() }) } }
        composable(Routes.NOTIFICATIONS) { shell(false) { NotificationsScreen(onBack = { nav.popBackStack() }, onOpenVerification = { nav.navigate(Routes.VERIFICATION) }, onOpenProject = { uuid -> nav.navigate(Routes.project(uuid)) }) } }
        composable(Routes.CERTIFICATES) { shell(true) { CertificatesScreen(onBack = { nav.popBackStack() }) } }
        composable(Routes.VERIFICATION) { shell(true) { VerificationScreen(onBack = { nav.popBackStack() }, onOpenProfile = { nav.navigate(Routes.PROFILE) }) } }
        composable(Routes.TERMS) {
            TermsScreen(
                version = me?.terms?.currentVersion ?: "1",
                // The name is not asked for here: it can be added later from the profile.
                onAccepted = { nav.popBackStack() },
            )
        }
        composable(Routes.PROFILE) {
            shell(true) {
                ProfileScreen(
                    onBack = { nav.popBackStack() },
                    onSaved = { nav.popBackStack(Routes.HOME, inclusive = false) },
                )
            }
        }
    }
}
