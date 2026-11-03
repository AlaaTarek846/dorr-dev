package com.dorr.freelance.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Handyman
import androidx.compose.material.icons.rounded.Inbox
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.MarkEmailUnread
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.VideoChat
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.res.stringResource
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.HomeHub
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.Session
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.AppShell
import com.dorr.freelance.ui.components.ShellState
import com.dorr.freelance.ui.components.Tabs
import com.dorr.freelance.ui.components.shellTabs
import com.dorr.freelance.ui.kit.HubTabFrame
import com.dorr.freelance.ui.kit.Segment

/** Where the tabs send the freelancer: a page over the tabs. */
class MainActions(
    val openProfile: () -> Unit,
    val openSpecialties: () -> Unit,
    val openSkills: () -> Unit,
    val openExperiences: () -> Unit,
    val openAvailability: () -> Unit,
    val openPlans: () -> Unit,
    val openPortfolio: () -> Unit,
    val openProject: (String) -> Unit,
    val openMyOffers: () -> Unit,
    val openOffer: (String) -> Unit,
    val openInvitations: () -> Unit,
    val openCertificates: () -> Unit,
    val openVerification: () -> Unit,
    val openPortfolioItem: (Int) -> Unit,
    val openNotifications: () -> Unit,
    val openWallet: () -> Unit,
    // FL-L04
    val openServices: () -> Unit,
    val openServiceOrders: () -> Unit,
    val openServiceOrder: (String) -> Unit,
    // FL-L01 phase 4: my performance numbers
    val openInsights: () -> Unit,
    // FL-L11: improve my profile
    val openCoach: () -> Unit,
    // FL-L08: skill tests and badges
    val openSkillTests: () -> Unit,
    // FL-L10: my capacity and the line on my services
    val openCapacity: () -> Unit,
    // FL-L07: paid consultations
    val openConsultations: () -> Unit,
    val openConsultation: (String) -> Unit,
    val openConsultationEditor: (String?) -> Unit,
    // FL-L09: special offers to past clients
    val openSpecialOffers: () -> Unit,
    val openLoyalty: () -> Unit,
    // FL-L12: the professional community (a tab of the bar)
    val community: CommunityNav,
)

/**
 * The app once signed in: inside the shared [AppShell] (header + bottom bar) the tabs — Home (a dashboard), Opportunities (open projects,
 * invitations), My work (running projects, my offers, service orders, consultations), Community (while it is on in my country) and Me.
 * The account is asked for once when this opens (and again when the connection comes back); a 401 already sends the freelancer back to
 * sign-in. Back from another tab returns to Home first.
 */
@Composable
fun MainScreen(actions: MainActions) {
    val reconnect = collectReconnectTick()
    var failed by rememberSaveable { mutableStateOf<String?>(null) }
    val offlineText = stringResource(R.string.login_error_offline)
    val loadFailed = stringResource(R.string.home_loading_failed)

    LaunchedEffect(reconnect) {
        when (val result = apiCall { ApiClient.auth.me() }) {
            is ApiResult.Ok -> {
                Session.setMe(result.data)
                failed = null
            }
            // A 401 already cleared the session (the navigation goes back to sign-in by itself).
            else -> if (Session.me.value == null) failed = result.errorText(offlineText) ?: loadFailed
        }
    }

    // A notice arrived (live): the account may have changed (a verification decision) — one reload, no timer.
    LaunchedEffect(Unit) {
        Realtime.changed.collect {
            val result = apiCall { ApiClient.auth.me() }
            if (result is ApiResult.Ok) Session.setMe(result.data)
        }
    }
    // Phone notifications are asked for once, after the first screen is up.
    LaunchedEffect(Unit) { Realtime.requestPushPermission() }

    // The open tab lives in [ShellState] so the pages over the tabs keep it lit, and a tab tapped there lands on it.
    val tab = ShellState.tab
    BackHandler(enabled = tab != Tabs.HOME) { ShellState.tab = Tabs.HOME }
    val tabs = shellTabs()
    // The community's switch went off while its tab was open: back home.
    LaunchedEffect(tabs.size, tab) { if (tabs.none { it.key == tab }) ShellState.tab = Tabs.HOME }

    AppShell(onSelectTab = { ShellState.open(it) }, onOpenWallet = actions.openWallet, onOpenNotifications = actions.openNotifications) {
        // A tab slides in from the side it sits on, with a fade, like the pages do.
        AnimatedContent(
            targetState = tab,
            label = "mainTab",
            transitionSpec = {
                val from = tabs.indexOfFirst { it.key == initialState }
                val to = tabs.indexOfFirst { it.key == targetState }
                val sign = if (to > from) 1 else -1
                ContentTransform(
                    slideInHorizontally(tween(320, easing = FastOutSlowInEasing)) { sign * it / 6 } + fadeIn(tween(260)),
                    slideOutHorizontally(tween(280, easing = FastOutSlowInEasing)) { -sign * it / 6 } + fadeOut(tween(200)),
                    sizeTransform = null,
                )
            },
        ) { shown ->
            when (shown) {
                Tabs.OPPORTUNITIES -> OpportunitiesTab(actions)
                Tabs.WORK -> WorkTab(actions)
                Tabs.COMMUNITY -> CommunityScreen(nav = actions.community, onBack = null)
                Tabs.ME -> AccountScreen(actions)
                else -> HomeScreen(actions, failed)
            }
        }
    }
}

/** The part a shortcut asked for (taken once), else the one open before, else [default]. */
@Composable
private fun rememberSegment(allowed: List<String>, default: String): Pair<String, (String) -> Unit> {
    var segment by rememberSaveable { mutableStateOf(ShellState.segment?.takeIf { it in allowed } ?: default) }
    val asked = ShellState.segment
    LaunchedEffect(asked) {
        if (asked != null) {
            if (asked in allowed) segment = asked
            ShellState.segment = null
        }
    }
    // A part whose feature went off falls back to the first one.
    return (if (segment in allowed) segment else default) to { segment = it }
}

/** Opportunities: the open projects (search, filter, saved) and the clients' invitations to private projects. */
@Composable
private fun OpportunitiesTab(actions: MainActions) {
    val home by HomeHub.home.collectAsState()
    val withInvitations = home?.on("invitations") != false
    val segments = buildList {
        add(Segment("projects", stringResource(R.string.opp_projects), Icons.Rounded.Search))
        if (withInvitations) add(Segment("invitations", stringResource(R.string.opp_invitations), Icons.Rounded.MarkEmailUnread, home?.attention?.invitationsPending ?: 0))
    }
    val (segment, select) = rememberSegment(segments.map { it.key }, "projects")
    HubTabFrame(stringResource(R.string.tab_opportunities), stringResource(R.string.opp_subtitle), segments, segment, select) {
        when (segment) {
            "invitations" -> InvitationsScreen(onBack = {}, onOpenProject = actions.openProject, embedded = true)
            else -> ProjectsTab(onBack = {}, onOpenProject = actions.openProject, embedded = true)
        }
    }
}

/** My work: the projects I was chosen for, my offers, the orders on my ready-made services and my consultations. */
@Composable
private fun WorkTab(actions: MainActions) {
    val home by HomeHub.home.collectAsState()
    val a = home?.attention
    val segments = buildList {
        add(Segment("projects", stringResource(R.string.work_seg_projects), Icons.Rounded.Handyman, a?.revisions ?: 0))
        add(Segment("offers", stringResource(R.string.work_seg_offers), Icons.Rounded.LocalOffer))
        if (home?.on("services") != false) add(Segment("orders", stringResource(R.string.work_seg_orders), Icons.Rounded.Inbox, a?.ordersAwaiting ?: 0))
        if (home?.on("consultations") != false) add(Segment("consultations", stringResource(R.string.work_seg_consultations), Icons.Rounded.VideoChat, a?.consultationsPending ?: 0))
    }
    val (segment, select) = rememberSegment(segments.map { it.key }, "projects")
    HubTabFrame(stringResource(R.string.tab_work), stringResource(R.string.work_subtitle), segments, segment, select) {
        when (segment) {
            "offers" -> MyOffersScreen(onBack = null, onOpenProject = actions.openOffer, embedded = true)
            "orders" -> ServiceOrdersScreen(onBack = {}, onOpenOrder = actions.openServiceOrder, embedded = true)
            "consultations" -> ConsultationsScreen(onBack = {}, onOpenEditor = actions.openConsultationEditor, onOpenConsultation = actions.openConsultation, embedded = true)
            else -> MyWorkTab(onBack = {}, onOpenProject = actions.openProject, embedded = true)
        }
    }
}
