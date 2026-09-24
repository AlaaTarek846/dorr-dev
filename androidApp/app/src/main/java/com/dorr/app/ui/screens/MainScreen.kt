package com.dorr.app.ui.screens

import androidx.compose.animation.Crossfade
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.outlined.History
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Inventory2
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material3.BottomAppBar
import androidx.compose.material3.FabPosition
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession

private data class Tab(
    val label: Int,
    val icon: ImageVector,
    val activeIcon: ImageVector,
)

private val tabs = listOf(
    Tab(R.string.tab_home, Icons.Outlined.Home, Icons.Rounded.Home),
    Tab(R.string.tab_items, Icons.Outlined.Inventory2, Icons.Rounded.Inventory2),
    Tab(R.string.tab_history, Icons.Outlined.History, Icons.Rounded.History),
    Tab(R.string.tab_account, Icons.Outlined.Person, Icons.Rounded.Person),
)

/**
 * Shell hosting the bottom-tab bar + centered FAB, mirroring the reference
 * app's MainShell (BottomAppBar with a notch, FAB docked in the center).
 * Tabs 1-2 are lightweight placeholders — only Home (tab 0) and Account
 * (tab 3, mapped to [ProfileScreen]) are fully designed per this request.
 */
@Composable
fun MainScreen(
    onLogout: () -> Unit,
    onOpenNotifications: () -> Unit,
    onOpenWallet: () -> Unit,
    onOpenServices: () -> Unit,
) {
    var currentTab by remember { mutableIntStateOf(0) }

    // Gentle session check on entry: refresh the profile from /auth/me and, if
    // the token is expired/revoked, the backend answers 401 — the ApiClient
    // interceptor clears AuthSession and fires onUnauthorized, which the nav
    // graph turns into a trip back to Login. No local state needed here.
    LaunchedEffect(Unit) {
        val token = AuthSession.token
        if (!token.isNullOrBlank()) {
            runCatching {
                ApiClient.mobileAuth.me("Bearer $token").data
            }.onSuccess { me ->
                if (me != null) AuthSession.user = me
            }
        }
    }

    Scaffold(
        floatingActionButton = {
            FloatingActionButton(onClick = { /* placeholder: open new-request flow */ }) {
                Icon(Icons.Filled.Add, contentDescription = stringResource(R.string.home_new_request))
            }
        },
        floatingActionButtonPosition = FabPosition.Center,
        bottomBar = {
            BottomAppBar(
                actions = {
                    TabItem(tabs[0], selected = currentTab == 0) { currentTab = 0 }
                    TabItem(tabs[1], selected = currentTab == 1) { currentTab = 1 }
                    Spacer(Modifier.width(48.dp))
                    TabItem(tabs[2], selected = currentTab == 2) { currentTab = 2 }
                    TabItem(tabs[3], selected = currentTab == 3) { currentTab = 3 }
                },
            )
        },
    ) { padding ->
        Crossfade(targetState = currentTab, label = "mainTab", modifier = Modifier.padding(padding)) { tab ->
            when (tab) {
                0 -> HomeScreen(
                    onOpenAccount = { currentTab = 3 },
                    onOpenNotifications = onOpenNotifications,
                    onOpenWallet = onOpenWallet,
                    onOpenServices = onOpenServices,
                )
                3 -> ProfileScreen(onLogout = onLogout)
                else -> PlaceholderScreen()
            }
        }
    }
}

@Composable
private fun TabItem(tab: Tab, selected: Boolean, onClick: () -> Unit) {
    IconButton(onClick = onClick) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Icon(
                imageVector = if (selected) tab.activeIcon else tab.icon,
                contentDescription = stringResource(tab.label),
                tint = if (selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Text(
                text = stringResource(tab.label),
                fontSize = 10.sp,
                fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
                color = if (selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}
