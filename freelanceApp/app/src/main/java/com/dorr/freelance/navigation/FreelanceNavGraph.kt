package com.dorr.freelance.navigation

import android.net.Uri
import androidx.compose.animation.AnimatedContentTransitionScope
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import com.dorr.freelance.ui.kit.AlertDialog
import com.dorr.freelance.ui.kit.DialogButton
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
import com.dorr.freelance.net.CommunityHub
import com.dorr.freelance.net.Gate
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.Session
import com.dorr.freelance.net.Welcome
import com.dorr.freelance.ui.components.AppShell
import com.dorr.freelance.ui.components.ShellState
import com.dorr.freelance.ui.screens.AgreementScreen
import com.dorr.freelance.ui.screens.DisputeScreen
import com.dorr.freelance.ui.screens.AvailabilityScreen
import com.dorr.freelance.ui.screens.ChatScreen
import com.dorr.freelance.ui.screens.ProfileCoachScreen
import com.dorr.freelance.ui.screens.SkillTestsScreen
import com.dorr.freelance.ui.screens.ReputationScreen
import com.dorr.freelance.ui.screens.CapacityScreen
import com.dorr.freelance.ui.screens.ConsultationScreen
import com.dorr.freelance.ui.screens.ConsultationSessionScreen
import com.dorr.freelance.ui.screens.ConsultationEditorScreen
import com.dorr.freelance.ui.screens.ConsultationsScreen
import com.dorr.freelance.ui.screens.CertificatesScreen
import com.dorr.freelance.ui.screens.CommunityComposeScreen
import com.dorr.freelance.ui.screens.CommunityMemberScreen
import com.dorr.freelance.ui.screens.CommunityNav
import com.dorr.freelance.ui.screens.CommunityPeopleScreen
import com.dorr.freelance.ui.screens.CommunityPostScreen
import com.dorr.freelance.ui.screens.CommunityScreen
import com.dorr.freelance.ui.screens.ExperiencesScreen
import com.dorr.freelance.ui.screens.MainActions
import com.dorr.freelance.ui.screens.NotificationsScreen
import com.dorr.freelance.ui.screens.MainScreen
import com.dorr.freelance.ui.screens.PlansScreen
import com.dorr.freelance.ui.screens.PortfolioFormScreen
import com.dorr.freelance.ui.screens.PortfolioTab
import com.dorr.freelance.ui.screens.ProjectDetailScreen
import com.dorr.freelance.ui.screens.InsightsScreen
import com.dorr.freelance.ui.screens.CrmClientScreen
import com.dorr.freelance.ui.screens.CrmScreen
import com.dorr.freelance.ui.screens.MyServicesScreen
import com.dorr.freelance.ui.screens.ServiceEditorScreen
import com.dorr.freelance.ui.screens.ServiceOrderScreen
import com.dorr.freelance.ui.screens.ServiceOrdersScreen
import com.dorr.freelance.ui.screens.LoyaltyScreen
import com.dorr.freelance.ui.screens.SpecialOfferComposeScreen
import com.dorr.freelance.ui.screens.SpecialOfferScreen
import com.dorr.freelance.ui.screens.SpecialOffersScreen
import com.dorr.freelance.ui.screens.OfferFormScreen
import com.dorr.freelance.ui.screens.EditOfferScreen
import com.dorr.freelance.ui.screens.InvitationsScreen
import com.dorr.freelance.ui.screens.TeamPlanScreen
import com.dorr.freelance.ui.screens.TeamScreen
import com.dorr.freelance.ui.screens.TeamThreadScreen
import com.dorr.freelance.ui.screens.TeamVerificationScreen
import com.dorr.freelance.ui.screens.workroom.WorkroomFileScreen
import com.dorr.freelance.ui.screens.workroom.WorkroomScreen
import com.dorr.freelance.ui.screens.TeamsScreen
import com.dorr.freelance.ui.screens.MyOffersScreen
import com.dorr.freelance.ui.screens.OfferDetailScreen
import com.dorr.freelance.ui.screens.LoginScreen
import com.dorr.freelance.ui.screens.OtpScreen
import com.dorr.freelance.ui.screens.ProfileScreen
import com.dorr.freelance.ui.screens.SkillsScreen
import com.dorr.freelance.ui.screens.SpecialtiesScreen
import com.dorr.freelance.ui.screens.SplashScreen
import com.dorr.freelance.ui.screens.StartScreen
import com.dorr.freelance.ui.screens.VerificationScreen
import com.dorr.freelance.ui.screens.WalletRoute
import com.dorr.freelance.ui.screens.stepText

/**
 * The app's routes: splash → welcome (once, on the first launch after install) → sign-in (phone → code, the terms are agreed on that page) → home; the profile (name included) is filled in later.
 */
object Routes {
    const val SPLASH = "splash"
    const val START = "start"
    const val LOGIN = "login"
    const val OTP = "otp/{dial}/{phone}/{cooldown}"
    const val HOME = "home"
    const val PROFILE = "profile"
    const val SPECIALTIES = "specialties"
    const val SKILLS = "skills"
    const val EXPERIENCES = "experiences"
    const val AVAILABILITY = "availability"
    const val PLANS = "plans"
    const val INSIGHTS = "insights"
    // FS-703: my clients (the private CRM) and one client's page.
    const val CRM = "crm"
    const val CRM_CLIENT = "crm/{uuid}"
    const val COACH = "profile-coach"
    const val SKILL_TESTS = "skill-tests"
    /** FS-604: my reputation figures. */
    const val REPUTATION = "reputation"
    const val CAPACITY = "capacity"
    // FS-209: my freelancers' teams and one team's page.
    const val TEAMS = "teams"
    const val TEAM = "team/{uuid}"
    // FS-209c: a team's inside chat thread, its works (the list and one work) and its verification.
    const val TEAM_THREAD = "team/{uuid}/chat/{thread}"
    const val TEAM_PORTFOLIO = "team/{uuid}/portfolio"
    const val TEAM_PORTFOLIO_ITEM = "team/{uuid}/portfolio/{id}"
    const val TEAM_VERIFICATION = "team/{uuid}/verification"
    // FS-211: a team's plan (its Owners read it and subscribe).
    const val TEAM_PLAN = "team/{uuid}/plan"
    const val PORTFOLIO = "portfolio"
    const val PROJECT = "project/{uuid}"
    const val OFFER_FORM = "offer/{uuid}"
    const val OFFER_EDIT = "offer-edit/{uuid}"
    const val OFFER_DETAIL = "offer-detail/{uuid}"
    const val MY_OFFERS = "offers"
    const val INVITATIONS = "invitations"
    const val CHAT = "chat/{uuid}"
    const val AGREEMENT = "agreement/{uuid}"
    // FS-150e: a project's Workroom (opens on `tab` = tasks · files · decisions · timeline) and one file of it.
    const val WORKROOM = "workroom/{uuid}?tab={tab}"
    const val WORKROOM_FILE = "workroom/{uuid}/file/{file}"
    const val DISPUTE = "dispute/{uuid}/{dispute}"
    const val DISPUTE_NEW = "dispute-new/{uuid}/{milestone}"
    const val CERTIFICATES = "certificates"
    const val VERIFICATION = "verification"
    const val NOTIFICATIONS = "notifications"
    const val WALLET = "wallet"
    const val PORTFOLIO_ITEM = "portfolio/{id}"
    // FL-L04: my ready-made services and the orders on them.
    const val SERVICES = "services"
    const val SERVICE = "service/{uuid}"
    const val SERVICE_ORDERS = "service-orders"
    const val SERVICE_ORDER = "service-order/{uuid}"
    // FL-L07: paid consultations.
    const val CONSULTATIONS = "consultations"
    const val CONSULTATION_EDIT = "consultation-edit?uuid={uuid}"
    const val CONSULTATION = "consultation/{uuid}"
    /** FL-L16: the consultation's video session (full screen, outside the shell). */
    const val CONSULTATION_SESSION = "consultation-session/{uuid}"
    // FL-L09: special offers to past clients.
    const val SPECIAL_OFFERS = "special-offers"
    const val SPECIAL_OFFER_NEW = "special-offer-new"
    const val SPECIAL_OFFER = "special-offer/{uuid}"
    // FL-L09 (part B): my loyalty program.
    const val LOYALTY = "loyalty"
    // FL-L12: the professional community.
    const val COMMUNITY = "community"
    const val COMMUNITY_POST = "community-post/{uuid}"
    const val COMMUNITY_MEMBER = "community-member/{uuid}"
    const val COMMUNITY_PEOPLE = "community-people/{uuid}/{which}"
    const val COMMUNITY_COMPOSE = "community-compose?kind={kind}&edit={edit}"

    fun communityPost(uuid: String) = "community-post/$uuid"
    fun communityMember(uuid: String) = "community-member/$uuid"
    fun communityPeople(uuid: String, which: String) = "community-people/$uuid/$which"

    /** A new post of [kind], or (with [edit]) editing that post of mine. */
    fun communityCompose(kind: String = "post", edit: String? = null) = "community-compose?kind=$kind" + (edit?.let { "&edit=$it" } ?: "")

    /** `null` = a new service. */
    fun service(uuid: String?) = "service/${uuid ?: "new"}"

    fun serviceOrder(uuid: String) = "service-order/$uuid"
    fun consultation(uuid: String) = "consultation/$uuid"
    fun consultationSession(uuid: String) = "consultation-session/$uuid"
    fun specialOffer(uuid: String) = "special-offer/$uuid"

    /** The editor of one consultation I list (no uuid: a new one). */
    fun consultationEdit(uuid: String? = null) = if (uuid == null) "consultation-edit" else "consultation-edit?uuid=$uuid"

    fun project(uuid: String) = "project/$uuid"
    fun crmClient(uuid: String) = "crm/$uuid"
    fun team(uuid: String) = "team/$uuid"
    fun teamThread(uuid: String, thread: String) = "team/$uuid/chat/$thread"
    fun teamPortfolio(uuid: String) = "team/$uuid/portfolio"
    fun teamPortfolioItem(uuid: String, id: Int) = "team/$uuid/portfolio/$id"
    fun teamVerification(uuid: String) = "team/$uuid/verification"
    fun teamPlan(uuid: String) = "team/$uuid/plan"

    fun chat(uuid: String) = "chat/$uuid"

    fun agreement(uuid: String) = "agreement/$uuid"

    fun workroom(project: String, tab: String = "tasks") = "workroom/$project?tab=$tab"

    fun workroomFile(project: String, file: String) = "workroom/$project/file/$file"

    fun offerForm(uuid: String) = "offer/$uuid"

    fun offerEdit(uuid: String) = "offer-edit/$uuid"
    fun offerDetail(uuid: String) = "offer-detail/$uuid"

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
        if (!signedIn) ShellState.tab = com.dorr.freelance.ui.components.Tabs.HOME
        if (!signedIn) CommunityHub.clear()
        if (!signedIn) com.dorr.freelance.net.HomeHub.clear()
        if (!signedIn && !beforeSignIn) nav.navigate(Routes.LOGIN) { popUpTo(0) { inclusive = true } }
    }

    // FL-110: a request that needs a verified account was refused — say which step they are at, once, from any screen.
    val gateStep by Gate.step.collectAsState()
    gateStep?.let { step ->
        AlertDialog(
            onDismissRequest = { Gate.dismiss() },
            title = { Text(stringResource(R.string.gate_title)) },
            text = { Text(stepText(step, null)) },
            confirmButton = {
                DialogButton(onClick = {
                    Gate.dismiss()
                    nav.navigate(Routes.VERIFICATION) { launchSingleTop = true }
                }) { Text(stringResource(R.string.gate_open)) }
            },
            dismissButton = { DialogButton(onClick = { Gate.dismiss() }) { Text(stringResource(R.string.common_cancel)) } },
        )
    }

    // A notification tapped in the phone's tray (push): the notifications page — or the project itself when the notice is "a project
    // that fits you" (FL-208) — once the freelancer is signed in.
    val tapped by Realtime.opened.collectAsState()
    val tappedRoute by Realtime.openedRoute.collectAsState()
    LaunchedEffect(tapped, tappedRoute, signedIn) {
        val here = nav.currentDestination?.route
        // A notice lands on the screen of what it is about from wherever the app was left; one without such a screen, on the list from the home.
        val ready = if (tappedRoute != null) here != null && here != Routes.SPLASH && here != Routes.START && here != Routes.LOGIN && here != Routes.OTP else here == Routes.HOME
        if (tapped != null && signedIn && ready) {
            val target = tappedRoute
            Realtime.consumeOpened()
            nav.navigate(target ?: Routes.NOTIFICATIONS)
        }
    }

    fun afterWelcome() = if (Session.signedIn.value) Routes.HOME else Routes.LOGIN

    // FL-L12: where the community screens lead.
    val communityNav = CommunityNav(
        openPost = { uuid -> nav.navigate(Routes.communityPost(uuid)) },
        openMember = { uuid -> nav.navigate(Routes.communityMember(uuid)) },
        editPost = { uuid -> nav.navigate(Routes.communityCompose(edit = uuid)) },
        compose = { kind -> nav.navigate(Routes.communityCompose(kind)) { launchSingleTop = true } },
        openPeople = { uuid, which -> nav.navigate(Routes.communityPeople(uuid, which)) },
        openVerification = { nav.navigate(Routes.VERIFICATION) { launchSingleTop = true } },
        openPortfolio = { nav.navigate(Routes.PORTFOLIO) { launchSingleTop = true } },
    )

    // The store's pages swap in place (the switch at their top): the open one leaves the back stack.
    val switchStore: (String) -> Unit = { key ->
        val route = when (key) {
            "services" -> Routes.SERVICES
            "consultations" -> Routes.CONSULTATIONS
            "special_offers" -> Routes.SPECIAL_OFFERS
            else -> Routes.LOYALTY
        }
        nav.popBackStack()
        nav.navigate(route) { launchSingleTop = true }
    }

    // Every page over the tabs sits in the same frame as the tabs: the bottom bar (the header with the wallet and the bell is on the Home tab only). A tab tapped
    // there goes back to the tabs on that tab; the wallet and the bell open once (a second tap on the open one does nothing).
    val shell: @Composable (Boolean, @Composable () -> Unit) -> Unit = { _, page ->
        AppShell(
            showHeader = false,
            onSelectTab = { key ->
                ShellState.open(key)
                nav.popBackStack(Routes.HOME, inclusive = false)
            },
            onOpenWallet = { nav.navigate(Routes.WALLET) { launchSingleTop = true } },
            onOpenNotifications = { nav.navigate(Routes.NOTIFICATIONS) { launchSingleTop = true } },
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
                    openPlans = { nav.navigate(Routes.PLANS) },
                    openPortfolio = { nav.navigate(Routes.PORTFOLIO) },
                    openProject = { uuid -> nav.navigate(Routes.project(uuid)) },
                    openAgreement = { uuid -> nav.navigate(Routes.agreement(uuid)) },
                    openMyOffers = { nav.navigate(Routes.MY_OFFERS) },
                    openOffer = { uuid -> nav.navigate(Routes.offerDetail(uuid)) },
                    openInvitations = { nav.navigate(Routes.INVITATIONS) },
                    openCertificates = { nav.navigate(Routes.CERTIFICATES) },
                    openVerification = { nav.navigate(Routes.VERIFICATION) },
                    openPortfolioItem = { id -> nav.navigate(Routes.portfolioItem(id)) },
                    openNotifications = { nav.navigate(Routes.NOTIFICATIONS) },
                    openWallet = { nav.navigate(Routes.WALLET) },
                    openServices = { nav.navigate(Routes.SERVICES) },
                    openServiceOrders = { nav.navigate(Routes.SERVICE_ORDERS) },
                    openServiceOrder = { uuid -> nav.navigate(Routes.serviceOrder(uuid)) },
                    openInsights = { nav.navigate(Routes.INSIGHTS) },
                    openCrm = { nav.navigate(Routes.CRM) },
                    openCoach = { nav.navigate(Routes.COACH) },
                    openSkillTests = { nav.navigate(Routes.SKILL_TESTS) },
                    openReputation = { nav.navigate(Routes.REPUTATION) },
                    openCapacity = { nav.navigate(Routes.CAPACITY) },
                    openTeams = { nav.navigate(Routes.TEAMS) },
                    openConsultations = { nav.navigate(Routes.CONSULTATIONS) },
                    openConsultation = { uuid -> nav.navigate(Routes.consultation(uuid)) },
                    openConsultationEditor = { uuid -> nav.navigate(Routes.consultationEdit(uuid)) { launchSingleTop = true } },
                    openSpecialOffers = { nav.navigate(Routes.SPECIAL_OFFERS) },
                    openLoyalty = { nav.navigate(Routes.LOYALTY) },
                    community = communityNav,
                ),
            )
        }
        composable(Routes.SPECIALTIES) { shell(true) { SpecialtiesScreen(onBack = { nav.popBackStack() }) } }
        composable(Routes.SKILLS) { shell(true) { SkillsScreen(onBack = { nav.popBackStack() }) } }
        composable(Routes.PORTFOLIO) { shell(true) { PortfolioTab(onBack = { nav.popBackStack() }, onOpenItem = { id -> nav.navigate(Routes.portfolioItem(id)) }) } }
        composable(Routes.PROJECT, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            val uuid = entry.arguments?.getString("uuid").orEmpty()
            shell(true) { ProjectDetailScreen(uuid = uuid, onBack = { nav.popBackStack() }, onOffer = { nav.navigate(Routes.offerForm(uuid)) }, onOpenOffer = { nav.navigate(Routes.offerDetail(uuid)) }, onAgreement = { nav.navigate(Routes.agreement(uuid)) }) }
        }
        composable(Routes.AGREEMENT, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            val p = entry.arguments?.getString("uuid").orEmpty()
            shell(true) { AgreementScreen(projectUuid = p, onBack = { nav.popBackStack() }, onDispute = { m, d -> nav.navigate(if (d != null) "dispute/$p/$d" else "dispute-new/$p/$m") }, onWorkroom = { nav.navigate(Routes.workroom(p)) }) }
        }
        composable(Routes.WORKROOM, arguments = listOf(navArgument("uuid") { type = NavType.StringType }, navArgument("tab") { type = NavType.StringType; defaultValue = "tasks" })) { entry ->
            val p = entry.arguments?.getString("uuid").orEmpty()
            shell(true) { WorkroomScreen(project = p, tab = entry.arguments?.getString("tab") ?: "tasks", onBack = { nav.popBackStack() }, onOpenFile = { f -> nav.navigate(Routes.workroomFile(p, f)) }) }
        }
        composable(Routes.WORKROOM_FILE, arguments = listOf(navArgument("uuid") { type = NavType.StringType }, navArgument("file") { type = NavType.StringType })) { entry ->
            shell(true) { WorkroomFileScreen(project = entry.arguments?.getString("uuid").orEmpty(), fileUuid = entry.arguments?.getString("file").orEmpty(), onBack = { nav.popBackStack() }) }
        }
        composable(Routes.DISPUTE, arguments = listOf(navArgument("uuid") { type = NavType.StringType }, navArgument("dispute") { type = NavType.StringType })) { entry ->
            shell(true) { DisputeScreen(projectUuid = entry.arguments?.getString("uuid").orEmpty(), milestoneUuid = null, disputeUuid = entry.arguments?.getString("dispute"), onBack = { nav.popBackStack() }, onOpened = {}) }
        }
        composable(Routes.DISPUTE_NEW, arguments = listOf(navArgument("uuid") { type = NavType.StringType }, navArgument("milestone") { type = NavType.StringType })) { entry ->
            val p = entry.arguments?.getString("uuid").orEmpty()
            shell(true) { DisputeScreen(projectUuid = p, milestoneUuid = entry.arguments?.getString("milestone"), disputeUuid = null, onBack = { nav.popBackStack() }, onOpened = { d -> nav.popBackStack(); nav.navigate("dispute/$p/$d") }) }
        }
        composable(Routes.CHAT, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell(false) { ChatScreen(conversationUuid = entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() }) }
        }
        composable(Routes.OFFER_FORM, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell(true) { OfferFormScreen(projectUuid = entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() }, onSent = { nav.popBackStack() }, onOpenProfile = { ShellState.openProfileHub = true; ShellState.open(com.dorr.freelance.ui.components.Tabs.ME); nav.popBackStack(Routes.HOME, inclusive = false) }, onOpenPlans = { nav.navigate(Routes.PLANS) { launchSingleTop = true } }) }
        }
        composable(Routes.OFFER_EDIT, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell(true) { EditOfferScreen(projectUuid = entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() }, onDone = { nav.popBackStack() }) }
        }
        composable(Routes.INVITATIONS) { shell(true) { InvitationsScreen(onBack = { nav.popBackStack() }, onOpenProject = { uuid -> nav.navigate(Routes.project(uuid)) }) } }
        composable(Routes.MY_OFFERS) { shell(true) { MyOffersScreen(onBack = { nav.popBackStack() }, onOpenProject = { uuid -> nav.navigate(Routes.offerDetail(uuid)) }) } }
        composable(Routes.OFFER_DETAIL, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            val p = entry.arguments?.getString("uuid").orEmpty()
            shell(true) {
                OfferDetailScreen(
                    projectUuid = p, onBack = { nav.popBackStack() }, onOpenProject = { nav.navigate(Routes.project(p)) },
                    onEdit = { nav.navigate(Routes.offerEdit(p)) }, onChat = { room -> nav.navigate(Routes.chat(room)) }, onAgreement = { nav.navigate(Routes.agreement(p)) },
                )
            }
        }
        composable(Routes.SERVICES) {
            shell(true) { MyServicesScreen(onBack = { nav.popBackStack() }, onOpenService = { uuid -> nav.navigate(Routes.service(uuid)) }, onOpenOrders = { nav.navigate(Routes.SERVICE_ORDERS) { launchSingleTop = true } }, onSwitchStore = switchStore) }
        }
        composable(Routes.SERVICE, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            val id = entry.arguments?.getString("uuid")?.takeIf { it != "new" }
            shell(true) { ServiceEditorScreen(uuid = id, onBack = { nav.popBackStack() }) }
        }
        composable(Routes.SERVICE_ORDERS) { shell(true) { ServiceOrdersScreen(onBack = { nav.popBackStack() }, onOpenOrder = { uuid -> nav.navigate(Routes.serviceOrder(uuid)) }) } }
        composable(Routes.SERVICE_ORDER, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell(true) { ServiceOrderScreen(uuid = entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() }, onOpenWork = { p -> nav.navigate(Routes.agreement(p)) }) }
        }
        composable(Routes.AVAILABILITY) { shell(true) { AvailabilityScreen(onBack = { nav.popBackStack() }) } }
        composable(Routes.PLANS) { shell(true) { PlansScreen(onBack = { nav.popBackStack() }, onOpenWallet = { nav.navigate(Routes.WALLET) { launchSingleTop = true } }, onOpenInsights = { nav.navigate(Routes.INSIGHTS) { launchSingleTop = true } }) } }
        composable(Routes.INSIGHTS) { shell(true) { InsightsScreen(onBack = { nav.popBackStack() }, onOpenPlans = { nav.navigate(Routes.PLANS) { launchSingleTop = true } }) } }
        // FS-703: my clients — the list, and one client (a project of ours opens its agreement, what is still waiting opens the project).
        composable(Routes.CRM) {
            shell(true) {
                CrmScreen(
                    onBack = { nav.popBackStack() }, onOpenClient = { uuid -> nav.navigate(Routes.crmClient(uuid)) },
                    onOpenPlans = { nav.navigate(Routes.PLANS) { launchSingleTop = true } },
                )
            }
        }
        composable(Routes.CRM_CLIENT, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell(true) {
                CrmClientScreen(
                    uuid = entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() },
                    onOpenAgreement = { project -> nav.navigate(Routes.agreement(project)) }, onOpenProject = { project -> nav.navigate(Routes.project(project)) },
                    onOpenPlans = { nav.navigate(Routes.PLANS) { launchSingleTop = true } },
                )
            }
        }
        composable(Routes.CAPACITY) { shell(true) { CapacityScreen(onBack = { nav.popBackStack() }) } }
        composable(Routes.TEAMS) { shell(true) { TeamsScreen(onBack = { nav.popBackStack() }, onOpenTeam = { uuid -> nav.navigate(Routes.team(uuid)) }) } }
        composable(Routes.TEAM, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            val team = entry.arguments?.getString("uuid").orEmpty()
            shell(true) {
                TeamScreen(
                    uuid = team, onBack = { nav.popBackStack() }, onOpenProject = { project -> nav.navigate(Routes.agreement(project)) },
                    onOpenThread = { thread -> nav.navigate(Routes.teamThread(team, thread)) },
                    onOpenPortfolio = { nav.navigate(Routes.teamPortfolio(team)) },
                    onOpenVerification = { nav.navigate(Routes.teamVerification(team)) },
                    onOpenPlan = { nav.navigate(Routes.teamPlan(team)) },
                )
            }
        }
        composable(Routes.TEAM_THREAD, arguments = listOf(navArgument("uuid") { type = NavType.StringType }, navArgument("thread") { type = NavType.StringType })) { entry ->
            shell(true) { TeamThreadScreen(teamUuid = entry.arguments?.getString("uuid").orEmpty(), threadUuid = entry.arguments?.getString("thread").orEmpty(), onBack = { nav.popBackStack() }) }
        }
        composable(Routes.TEAM_PORTFOLIO, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            val team = entry.arguments?.getString("uuid").orEmpty()
            shell(true) { PortfolioTab(team = team, onBack = { nav.popBackStack() }, onOpenItem = { id -> nav.navigate(Routes.teamPortfolioItem(team, id)) }) }
        }
        composable(Routes.TEAM_PORTFOLIO_ITEM, arguments = listOf(navArgument("uuid") { type = NavType.StringType }, navArgument("id") { type = NavType.IntType })) { entry ->
            shell(true) { PortfolioFormScreen(itemId = entry.arguments?.getInt("id") ?: 0, team = entry.arguments?.getString("uuid"), onBack = { nav.popBackStack() }) }
        }
        composable(Routes.TEAM_VERIFICATION, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell(true) { TeamVerificationScreen(teamUuid = entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() }) }
        }
        composable(Routes.TEAM_PLAN, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell(true) {
                TeamPlanScreen(
                    teamUuid = entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() },
                    onOpenWallet = { nav.navigate(Routes.WALLET) { launchSingleTop = true } },
                )
            }
        }
        composable(Routes.CONSULTATIONS) {
            shell(true) { ConsultationsScreen(onBack = { nav.popBackStack() }, onOpenEditor = { uuid -> nav.navigate(Routes.consultationEdit(uuid)) { launchSingleTop = true } }, onOpenConsultation = { uuid -> nav.navigate(Routes.consultation(uuid)) }, onSwitchStore = switchStore) }
        }
        composable(Routes.CONSULTATION_EDIT, arguments = listOf(navArgument("uuid") { type = NavType.StringType; nullable = true; defaultValue = null })) { entry ->
            shell(true) { ConsultationEditorScreen(uuid = entry.arguments?.getString("uuid"), onBack = { nav.popBackStack() }) }
        }
        composable(Routes.CONSULTATION, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell(true) {
                ConsultationScreen(
                    uuid = entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() },
                    onOpenSession = { uuid -> nav.navigate(Routes.consultationSession(uuid)) { launchSingleTop = true } },
                )
            }
        }
        composable(Routes.CONSULTATION_SESSION, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            ConsultationSessionScreen(uuid = entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() })
        }
        composable(Routes.SPECIAL_OFFERS) {
            shell(true) { SpecialOffersScreen(onBack = { nav.popBackStack() }, onCompose = { nav.navigate(Routes.SPECIAL_OFFER_NEW) { launchSingleTop = true } }, onOpen = { uuid -> nav.navigate(Routes.specialOffer(uuid)) }, onSwitchStore = switchStore) }
        }
        composable(Routes.LOYALTY) {
            shell(true) { LoyaltyScreen(onBack = { nav.popBackStack() }, onSwitchStore = switchStore) }
        }
        // FL-L12: the professional community.
        composable(Routes.COMMUNITY) { shell(true) { CommunityScreen(nav = communityNav, onBack = { nav.popBackStack() }) } }
        composable(Routes.COMMUNITY_POST, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell(true) { CommunityPostScreen(uuid = entry.arguments?.getString("uuid").orEmpty(), nav = communityNav, onBack = { nav.popBackStack() }) }
        }
        composable(Routes.COMMUNITY_MEMBER, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell(true) { CommunityMemberScreen(uuid = entry.arguments?.getString("uuid").orEmpty(), nav = communityNav, onBack = { nav.popBackStack() }) }
        }
        composable(Routes.COMMUNITY_PEOPLE, arguments = listOf(navArgument("uuid") { type = NavType.StringType }, navArgument("which") { type = NavType.StringType })) { entry ->
            shell(true) {
                CommunityPeopleScreen(
                    uuid = entry.arguments?.getString("uuid").orEmpty(), startWhich = entry.arguments?.getString("which").orEmpty(),
                    nav = communityNav, onBack = { nav.popBackStack() },
                )
            }
        }
        composable(
            Routes.COMMUNITY_COMPOSE,
            arguments = listOf(
                navArgument("kind") { type = NavType.StringType; defaultValue = "post" },
                navArgument("edit") { type = NavType.StringType; nullable = true; defaultValue = null },
            ),
        ) { entry ->
            shell(true) {
                CommunityComposeScreen(
                    startKind = entry.arguments?.getString("kind") ?: "post", editUuid = entry.arguments?.getString("edit"),
                    nav = communityNav, onBack = { nav.popBackStack() },
                )
            }
        }
        composable(Routes.SPECIAL_OFFER_NEW) {
            shell(true) { SpecialOfferComposeScreen(onBack = { nav.popBackStack() }, onSent = { uuid -> nav.navigate(Routes.specialOffer(uuid)) { popUpTo(Routes.SPECIAL_OFFER_NEW) { inclusive = true } } }) }
        }
        composable(Routes.SPECIAL_OFFER, arguments = listOf(navArgument("uuid") { type = NavType.StringType })) { entry ->
            shell(true) {
                SpecialOfferScreen(
                    uuid = entry.arguments?.getString("uuid").orEmpty(), onBack = { nav.popBackStack() },
                    onOpenWork = { p -> nav.navigate(Routes.agreement(p)) }, onOpenConsultation = { c -> nav.navigate(Routes.consultation(c)) }, onOpenOrder = { o -> nav.navigate(Routes.serviceOrder(o)) },
                )
            }
        }
        composable(Routes.REPUTATION) { shell(true) { ReputationScreen(onBack = { nav.popBackStack() }) } }
        composable(Routes.SKILL_TESTS) { shell(true) { SkillTestsScreen(onBack = { nav.popBackStack() }, onOpenSkills = { nav.navigate(Routes.SKILLS) }) } }
        composable(Routes.COACH) {
            shell(true) {
                ProfileCoachScreen(
                    onBack = { nav.popBackStack() },
                    onOpen = { action ->
                        nav.navigate(
                            when (action) {
                                "skills" -> Routes.SKILLS
                                "specialties" -> Routes.SPECIALTIES
                                "portfolio" -> Routes.PORTFOLIO
                                "verification" -> Routes.VERIFICATION
                                "services" -> Routes.SERVICES
                                else -> Routes.PROFILE
                            },
                        )
                    },
                )
            }
        }
        composable(Routes.EXPERIENCES) { shell(true) { ExperiencesScreen(onBack = { nav.popBackStack() }) } }
        composable(Routes.PORTFOLIO_ITEM, arguments = listOf(navArgument("id") { type = NavType.IntType })) { entry ->
            shell(true) { PortfolioFormScreen(itemId = entry.arguments?.getInt("id") ?: 0, onBack = { nav.popBackStack() }) }
        }
        // The wallet draws its own page header (title, back), so the app header is left out there.
        composable(Routes.WALLET) { shell(false) { WalletRoute(onBack = { nav.popBackStack() }) } }
        composable(Routes.NOTIFICATIONS) { shell(false) { NotificationsScreen(onBack = { nav.popBackStack() }, onOpenRoute = { route -> nav.navigate(route) }) } }
        composable(Routes.CERTIFICATES) { shell(true) { CertificatesScreen(onBack = { nav.popBackStack() }) } }
        composable(Routes.VERIFICATION) { shell(true) { VerificationScreen(onBack = { nav.popBackStack() }, onOpenProfile = { nav.navigate(Routes.PROFILE) }) } }
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
