package com.dorr.provider.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.duty.MoveTracking
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.can
import com.dorr.provider.net.EstablishmentDto
import com.dorr.provider.net.Realtime
import com.dorr.provider.net.Session
import com.dorr.provider.net.Workspace
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.ui.components.AppShell
import com.dorr.provider.ui.components.ShellState
import com.dorr.provider.ui.components.TAB_ACCOUNT
import com.dorr.provider.ui.components.TAB_HOME
import com.dorr.provider.ui.components.TAB_WALLET
import com.dorr.provider.ui.components.visibleTabs

/** Where the tabs send the provider: a page over the tabs, or back through the workspace gate. */
class MainActions(
    val openDocuments: () -> Unit,
    val openInvitations: () -> Unit,
    val openNotifications: () -> Unit,
    /** Creating my own establishment (when I only work in others'). */
    val openOnboarding: () -> Unit,
    /** The chosen establishment is gone (I left or was removed): the gate picks again. */
    val openWorkspace: () -> Unit,
    // C-03: running the establishment.
    val openBranches: () -> Unit,
    val openTeam: () -> Unit,
    val openVehicles: () -> Unit,
    /** A driver's licence page (the membership uuid; my own from the Account tab). */
    val openDriverDocs: (String) -> Unit,
    // C-04: the moving work.
    val openRequests: () -> Unit,
    /** true: straight on the offers waiting for approval. */
    val openOffers: (Boolean) -> Unit,
    /** true: straight on the jobs still without a driver. */
    val openJobs: (Boolean) -> Unit,
    val openMyJobs: () -> Unit,
    val openTickets: () -> Unit,
)

/**
 * The app once an establishment is chosen, like the DORR app: inside the shared [AppShell] (header + bottom bar) the pages are
 * tabs — Home, Wallet (with `wallet.view`), Account. The person, the workspace and the establishment with my seat in it are read
 * once when this opens, again when the connection comes back, when a live notice arrives and when another establishment is
 * chosen — never on a timer. Back from another tab returns to Home first.
 */
@Composable
fun MainScreen(actions: MainActions) {
    val reconnect = collectReconnectTick()
    val establishmentUuid by Session.establishment.collectAsState()
    var establishment by remember { mutableStateOf<EstablishmentDto?>(null) }
    var failed by remember { mutableStateOf<String?>(null) }
    val offlineText = stringResource(R.string.login_error_offline)
    val loadFailed = stringResource(R.string.home_loading_failed)

    suspend fun loadEstablishment() {
        when (val result = apiCall { ApiClient.workspace.establishment() }) {
            is ApiResult.Ok -> {
                establishment = result.data.establishment
                Session.setMembership(result.data.membership)
                failed = null
            }
            is ApiResult.Failed -> {
                // My seat there ended (I left, was removed, or the establishment is gone): no choice any more, so the
                // effect below sends me through the gate to pick again.
                if (result.error.errorCode == "provider_no_establishment" || result.error.errorCode == "provider_pick_establishment") {
                    Session.chooseEstablishment(null)
                } else if (establishment == null) {
                    failed = result.errorText(offlineText) ?: loadFailed
                }
            }
            // A 401 already cleared the session (the navigation goes back to sign-in by itself).
            else -> if (establishment == null) failed = result.errorText(offlineText) ?: loadFailed
        }
    }

    LaunchedEffect(reconnect) {
        (apiCall { ApiClient.auth.me() } as? ApiResult.Ok)?.data?.let { Session.setMe(it) }
        Workspace.reload()
    }
    LaunchedEffect(reconnect, establishmentUuid) {
        if (establishmentUuid == null) actions.openWorkspace() else loadEstablishment()
    }

    // A notice arrived (live): the establishment's status, my seat or my invitations may have changed — one reload, no timer.
    LaunchedEffect(Unit) {
        Realtime.changed.collect {
            Workspace.reload()
            loadEstablishment()
        }
    }
    // A driver with a running job: the location service back on if the phone killed it, off if the job ended meanwhile.
    val appContext = LocalContext.current.applicationContext
    val seatUuid = Session.membership.collectAsState().value?.uuid
    LaunchedEffect(reconnect, seatUuid) {
        if (Session.membership.value.can("driving.work")) MoveTracking.sync(appContext)
    }

    // Phone notifications are asked for once, after the first screen is up.
    LaunchedEffect(Unit) { Realtime.requestPushPermission() }

    // The open tab lives in [ShellState] so the pages over the tabs keep it lit; a tab my seat may not see falls back to Home.
    val tabs = visibleTabs()
    val tab = ShellState.tab.takeIf { key -> tabs.any { it.first == key } } ?: TAB_HOME
    BackHandler(enabled = tab != TAB_HOME) { ShellState.tab = TAB_HOME }

    AppShell(
        showHeader = tab == TAB_HOME,
        onSelectTab = { ShellState.tab = it },
        onOpenWallet = { ShellState.tab = TAB_WALLET },
        onOpenNotifications = actions.openNotifications,
        onOpenInvitations = actions.openInvitations,
    ) {
        // Another establishment chosen: every tab starts over for it (its wallet, its papers).
        key(establishmentUuid) {
            // A tab slides in from the side it sits on, with a fade, like the pages do.
            AnimatedContent(
                targetState = tab,
                label = "mainTab",
                transitionSpec = {
                    val sign = if (targetState > initialState) 1 else -1
                    ContentTransform(
                        slideInHorizontally(tween(320, easing = FastOutSlowInEasing)) { sign * it / 6 } + fadeIn(tween(260)),
                        slideOutHorizontally(tween(280, easing = FastOutSlowInEasing)) { -sign * it / 6 } + fadeOut(tween(200)),
                        sizeTransform = null,
                    )
                },
            ) { shownTab ->
                when (shownTab) {
                    TAB_WALLET -> WalletRoute(onBack = { ShellState.tab = TAB_HOME })
                    TAB_ACCOUNT -> AccountScreen(actions)
                    else -> HomeScreen(establishment, failed, actions, onReload = { loadEstablishment() })
                }
            }
        }
    }
}
