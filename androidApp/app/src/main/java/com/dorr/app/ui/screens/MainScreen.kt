package com.dorr.app.ui.screens

import androidx.compose.animation.Crossfade
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.outlined.Description
import androidx.compose.material.icons.outlined.GridView
import androidx.compose.material.icons.outlined.Home
import androidx.compose.material.icons.outlined.Person
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.GridView
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material3.Icon
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.ripple
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.ui.screens.wallet.WalletScreen

private data class Tab(
    val label: Int,
    val icon: ImageVector,
    val activeIcon: ImageVector,
)

private val tabs = listOf(
    Tab(R.string.tab_home, Icons.Outlined.Home, Icons.Rounded.Home),
    Tab(R.string.tab_services, Icons.Outlined.GridView, Icons.Rounded.GridView),
    Tab(R.string.tab_history, Icons.Outlined.Description, Icons.Rounded.Description),
    Tab(R.string.tab_account, Icons.Outlined.Person, Icons.Filled.Person),
)

private val BottomBarActiveRed = Color(0xFFE60012)
private val BottomBarInactiveGray = Color(0xFF8E9BAE)

/**
 * Shell hosting the bottom navigation bar + centered docked FAB,
 * matching the reference design:
 * - Clean white bottom bar with subtle top divider and glow
 * - 4 tabs: Home, Services, History, Account
 * - Elevated circular red FAB with white border and diffuse red glow
 */
@Composable
fun MainScreen(
    onLogout: () -> Unit,
    onOpenNotifications: () -> Unit,
    onOpenServices: () -> Unit,
    initialTab: Int = 0,
    initialWalletOpen: Boolean = false,
    onStateChanged: (tab: Int, walletOpen: Boolean) -> Unit = { _, _ -> },
) {
    var currentTab by remember(initialTab) { mutableIntStateOf(initialTab) }
    var walletOpen by remember(initialWalletOpen) { mutableStateOf(initialWalletOpen) }

    LaunchedEffect(currentTab, walletOpen) {
        onStateChanged(currentTab, walletOpen)
    }

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
        bottomBar = {
            DorrBottomNavigationBar(
                currentTab = currentTab,
                walletOpen = walletOpen,
                onSelectTab = { index ->
                    currentTab = index
                    walletOpen = false
                },
                onFabClick = {
                    // New request flow placeholder / trigger
                },
            )
        },
    ) { padding ->
        Box(Modifier.padding(padding)) {
            Crossfade(targetState = currentTab, label = "mainTab") { tab ->
                when (tab) {
                    0 -> HomeScreen(
                        onOpenAccount = { currentTab = 3 },
                        onOpenNotifications = onOpenNotifications,
                        onOpenWallet = { walletOpen = true },
                        onOpenServices = onOpenServices,
                    )
                    1 -> ServicesScreen(onBack = { currentTab = 0 })
                    3 -> ProfileScreen(onLogout = onLogout, onOpenWallet = { walletOpen = true })
                    else -> PlaceholderScreen()
                }
            }
            if (walletOpen) {
                WalletScreen(onExit = { walletOpen = false })
            }
        }
    }
}

@Composable
private fun DorrBottomNavigationBar(
    currentTab: Int,
    walletOpen: Boolean,
    onSelectTab: (Int) -> Unit,
    onFabClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val barHeight = 64.dp
    val fabSize = 54.dp

    Box(
        modifier = modifier.fillMaxWidth(),
        contentAlignment = Alignment.BottomCenter,
    ) {
        // 1. White bottom navigation bar surface
        Surface(
            modifier = Modifier
                .fillMaxWidth()
                .shadow(
                    elevation = 10.dp,
                    spotColor = BottomBarActiveRed.copy(alpha = 0.08f),
                    ambientColor = Color.Black.copy(alpha = 0.04f),
                ),
            color = Color.White,
        ) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .navigationBarsPadding(),
            ) {
                // Subtle hairline top line with gentle pink gradient towards center
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(1.dp)
                        .background(
                            brush = Brush.horizontalGradient(
                                colors = listOf(
                                    Color(0xFFEEEEEE),
                                    Color(0xFFFDE8EB),
                                    Color(0xFFF9C0C8),
                                    Color(0xFFFDE8EB),
                                    Color(0xFFEEEEEE),
                                ),
                            ),
                        ),
                )

                // 4 Tab items evenly distributed around the center FAB space
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(barHeight)
                        .padding(horizontal = 4.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    TabItem(
                        tab = tabs[0],
                        selected = currentTab == 0 && !walletOpen,
                        modifier = Modifier.weight(1f),
                        onClick = { onSelectTab(0) },
                    )
                    TabItem(
                        tab = tabs[1],
                        selected = currentTab == 1 && !walletOpen,
                        modifier = Modifier.weight(1f),
                        onClick = { onSelectTab(1) },
                    )

                    // Spacer reserved for center protruding FAB
                    Spacer(modifier = Modifier.width(68.dp))

                    TabItem(
                        tab = tabs[2],
                        selected = currentTab == 2 && !walletOpen,
                        modifier = Modifier.weight(1f),
                        onClick = { onSelectTab(2) },
                    )
                    TabItem(
                        tab = tabs[3],
                        selected = currentTab == 3 && !walletOpen,
                        modifier = Modifier.weight(1f),
                        onClick = { onSelectTab(3) },
                    )
                }
            }
        }

        // 2. Docked center floating red button with diffuse glow
        Box(
            modifier = Modifier
                .align(Alignment.BottomCenter)
                .navigationBarsPadding()
                .offset(y = (-21).dp),
            contentAlignment = Alignment.Center,
        ) {
            // Soft radial red glow that diffuses onto the white bar surface
            Box(
                modifier = Modifier
                    .size(80.dp)
                    .background(
                        brush = Brush.radialGradient(
                            colors = listOf(
                                BottomBarActiveRed.copy(alpha = 0.36f),
                                BottomBarActiveRed.copy(alpha = 0.15f),
                                BottomBarActiveRed.copy(alpha = 0.03f),
                                Color.Transparent,
                            ),
                        ),
                        shape = CircleShape,
                    ),
            )

            // Red circular button with crisp white ring
            Box(
                modifier = Modifier
                    .size(fabSize)
                    .shadow(
                        elevation = 8.dp,
                        shape = CircleShape,
                        spotColor = BottomBarActiveRed.copy(alpha = 0.40f),
                        ambientColor = BottomBarActiveRed.copy(alpha = 0.18f),
                    )
                    .background(BottomBarActiveRed, CircleShape)
                    .border(3.5.dp, Color.White, CircleShape)
                    .clip(CircleShape)
                    .clickable(
                        interactionSource = remember { MutableInteractionSource() },
                        indication = ripple(bounded = false, radius = 28.dp, color = Color.White),
                        onClick = onFabClick,
                    ),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    imageVector = Icons.Filled.Add,
                    contentDescription = stringResource(R.string.home_new_request),
                    tint = Color.White,
                    modifier = Modifier.size(24.dp),
                )
            }
        }
    }
}

@Composable
private fun TabItem(
    tab: Tab,
    selected: Boolean,
    modifier: Modifier = Modifier,
    onClick: () -> Unit,
) {
    Box(
        modifier = modifier
            .height(58.dp)
            .clickable(
                interactionSource = remember { MutableInteractionSource() },
                indication = null,
                onClick = onClick,
            ),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center,
        ) {
            Icon(
                imageVector = if (selected) tab.activeIcon else tab.icon,
                contentDescription = stringResource(tab.label),
                tint = if (selected) BottomBarActiveRed else BottomBarInactiveGray,
                modifier = Modifier.size(23.dp),
            )
            Spacer(modifier = Modifier.height(4.dp))
            Text(
                text = stringResource(tab.label),
                fontSize = 11.sp,
                lineHeight = 13.sp,
                fontWeight = if (selected) FontWeight.Bold else FontWeight.Medium,
                color = if (selected) BottomBarActiveRed else BottomBarInactiveGray,
                maxLines = 1,
            )
        }
    }
}
