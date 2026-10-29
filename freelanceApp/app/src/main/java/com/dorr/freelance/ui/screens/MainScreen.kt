package com.dorr.freelance.ui.screens

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.activity.compose.BackHandler
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.res.stringResource
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.Session
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.AppShell
import com.dorr.freelance.ui.components.ShellState

/** Where the tabs send the freelancer: a page over the tabs. */
class MainActions(
    val openProfile: () -> Unit,
    val openSpecialties: () -> Unit,
    val openSkills: () -> Unit,
    val openExperiences: () -> Unit,
    val openAvailability: () -> Unit,
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
)

/**
 * The app once signed in, like the DORR app: inside the shared [AppShell] (header + bottom bar) the pages are tabs — Home, My portfolio, My profile, My account.
 * The account is asked for once when this opens (and again when the connection comes back); a 401 already sends the
 * freelancer back to sign-in. Back from another tab returns to Home first.
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
    BackHandler(enabled = tab != 0) { ShellState.tab = 0 }

    AppShell(showHeader = tab == 0, onSelectTab = { ShellState.tab = it }, onOpenWallet = actions.openWallet, onOpenNotifications = actions.openNotifications, onOpenInvitations = actions.openInvitations) {
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
                0 -> HomeScreen(actions, failed, onOpenPortfolio = actions.openPortfolio, onOpenProfileTab = { ShellState.tab = 4 })
                1 -> ProjectsTab(onBack = { ShellState.tab = 0 }, onOpenProject = actions.openProject)
                2 -> MyOffersScreen(onBack = { ShellState.tab = 0 }, onOpenProject = actions.openOffer, asTab = true)
                3 -> MyWorkTab(onBack = { ShellState.tab = 0 }, onOpenProject = actions.openProject)
                else -> AccountScreen(actions)
            }
        }
    }
}
