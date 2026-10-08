package com.dorr.app.ui.components

import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.material3.pulltorefresh.PullToRefreshDefaults
import androidx.compose.material3.pulltorefresh.rememberPullToRefreshState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import com.dorr.app.network.RefreshCoordinator
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsNight
import kotlinx.coroutines.launch

/**
 * Pull-to-refresh for any screen with scrollable content (a LazyColumn, a verticalScroll…): wrap it, nothing else.
 *
 * Pulling from the top shows the native Material indicator and asks the screen on show to reload its data through
 * [RefreshCoordinator] — the same hook the screens already use to reload when the connection returns — and the
 * indicator goes away when the API requests that started have finished. The layout and the navigation are not touched,
 * and only what is on screen reloads (nothing is restarted).
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PullToRefreshHost(modifier: Modifier = Modifier, content: @Composable () -> Unit) {
    val scope = rememberCoroutineScope()
    val state = rememberPullToRefreshState()
    var refreshing by remember { mutableStateOf(false) }
    val accent = if (settingsNight()) AccountDark.accent else settingsAccent()

    PullToRefreshBox(
        isRefreshing = refreshing,
        onRefresh = {
            if (!refreshing) {
                refreshing = true
                scope.launch {
                    try {
                        RefreshCoordinator.refresh()
                    } finally {
                        refreshing = false
                    }
                }
            }
        },
        modifier = modifier,
        state = state,
        indicator = {
            PullToRefreshDefaults.Indicator(
                state = state,
                isRefreshing = refreshing,
                modifier = Modifier.align(Alignment.TopCenter),
                containerColor = settingsCard(),
                color = accent,
            )
        },
    ) {
        content()
    }
}
