package com.dorr.app.ui.screens

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.consumeWindowInsets
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
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.ripple
import com.dorr.app.ui.theme.LocalThemeState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.saveable.rememberSaveable
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
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.theme.LocalAppearance
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
    var currentTab by rememberSaveable { mutableIntStateOf(initialTab) }
    var walletOpen by rememberSaveable { mutableStateOf(initialWalletOpen) }
    var chatOpen by rememberSaveable { mutableStateOf(false) }
    var aiChatOpen by rememberSaveable { mutableStateOf(false) }

    // A tapped notification: open the chat (the chat itself opens the conversation), or ring the call.
    LaunchedEffect(Unit) {
        com.dorr.app.chat.ChatPush.requestPermission()
        com.dorr.app.chat.ChatPush.deepLink.collect { link ->
            when (link) {
                is com.dorr.app.chat.ChatDeepLink.Conversation -> { walletOpen = false; chatOpen = true }
                is com.dorr.app.chat.ChatDeepLink.Call -> {
                    com.dorr.app.chat.CallController.loadIncoming(link.id)
                    com.dorr.app.chat.ChatPush.consumeDeepLink()
                }
                null -> Unit
            }
        }
    }

    // Chat real-time for the whole signed-in session, plus "online" while the app is in front.
    val lifecycle = androidx.compose.ui.platform.LocalLifecycleOwner.current.lifecycle
    androidx.compose.runtime.DisposableEffect(lifecycle) {
        com.dorr.app.chat.ChatRealtime.start()
        val observer = androidx.lifecycle.LifecycleEventObserver { _, event ->
            when (event) {
                androidx.lifecycle.Lifecycle.Event.ON_START -> com.dorr.app.chat.ChatRealtime.onForeground()
                androidx.lifecycle.Lifecycle.Event.ON_STOP -> {
                    com.dorr.app.chat.ChatRealtime.onBackground()
                    // Leaving the app locks the locked chats again.
                    com.dorr.app.ui.screens.chat.ChatLock.lock()
                }
                else -> Unit
            }
        }
        lifecycle.addObserver(observer)
        onDispose { lifecycle.removeObserver(observer) }
    }

    val currentOnStateChanged by rememberUpdatedState(onStateChanged)
    LaunchedEffect(currentTab, walletOpen) {
        currentOnStateChanged(currentTab, walletOpen)
    }

    val appearance = LocalAppearance.current
    LaunchedEffect(Unit) {
        val token = AuthSession.token
        // The profile is cached in SharedPreferences and restored at startup
        // (AuthSession.attach). A cached user can be missing fields that were
        // filled in later on the server (e.g. the avatar added from another
        // device, or country set after the first login), and every profile
        // screen reads the cache without its own network call. So refresh
        // auth/me once here — a single cheap call in the shell at launch, not
        // in any screen — and let AuthSession push the fresh data out.
        if (!token.isNullOrBlank()) {
            runCatching {
                ApiClient.mobileAuth.me("Bearer $token").data
            }.onSuccess { me ->
                if (me != null && AuthSession.token == token) AuthSession.user = me
            }
            runCatching {
                ApiClient.appearance.show("Bearer $token").data
            }.onSuccess { dto ->
                if (dto != null && AuthSession.token == token) appearance.apply(dto)
            }
        }
    }

    val night = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    Box(Modifier.fillMaxSize()) {
    Scaffold(
        containerColor = if (night) AccountDark.bg else MaterialTheme.colorScheme.background,
        bottomBar = {
            // Real, observed request (2026-09-29): unlike the wallet and the
            // person-to-person chat (which deliberately keep the tab bar
            // visible underneath them - see the comment on the AI overlay
            // below), the user wants the AI Assistant specifically to feel
            // like its own full-screen space with no tab bar showing
            // through underneath it. Rendering nothing here (rather than
            // hiding the bar with alpha/visibility) also lets Scaffold's
            // own bottom content padding shrink to zero while it's open,
            // so AiChatHost's content extends cleanly to the bottom edge
            // instead of leaving an empty gap where the bar used to be.
            if (!aiChatOpen) {
                DorrBottomNavigationBar(
                    currentTab = currentTab,
                    // No tab is "current" while the wallet or a chat is open over the tabs.
                    walletOpen = walletOpen || chatOpen,
                    onSelectTab = { index ->
                        currentTab = index
                        walletOpen = false
                        chatOpen = false
                        aiChatOpen = false
                    },
                    onFabClick = {
                        // Central "+" button — opens the AI Assistant, the same way the
                        // chat button opens person-to-person chat below.
                        walletOpen = false
                        chatOpen = false
                        aiChatOpen = true
                    },
                )
            }
        },
    ) { padding ->
        // consumeWindowInsets: the tab bar already covers the navigation-bar area, so pages below
        // it (chat composer, stories reply, buttons) must not add the system bars / keyboard a
        // second time — that is what pushed the chat button under the phone's buttons before.
        Box(Modifier.padding(padding).consumeWindowInsets(padding)) {
            AnimatedContent(
                targetState = currentTab,
                label = "mainTab",
                transitionSpec = {
                    val enter = fadeIn(animationSpec = tween(280, easing = FastOutSlowInEasing))
                    val exit = fadeOut(animationSpec = tween(220, easing = FastOutSlowInEasing))
                    ContentTransform(enter, exit, sizeTransform = null)
                },
            ) { tab ->
                when (tab) {
                    0 -> HomeScreen(
                        onOpenAccount = { currentTab = 3 },
                        onOpenNotifications = onOpenNotifications,
                        onOpenWallet = { walletOpen = true },
                        onOpenServices = onOpenServices,
                        onOpenChat = { chatOpen = true },
                        onOpenAi = {
                            walletOpen = false
                            chatOpen = false
                            aiChatOpen = true
                        },
                    )
                    1 -> ServicesScreen(
                        onBack = { currentTab = 0 },
                        onOpenAi = {
                            walletOpen = false
                            chatOpen = false
                            aiChatOpen = true
                        },
                    )
                    3 -> ProfileScreen(onLogout = onLogout, onOpenWallet = { walletOpen = true })
                    else -> PlaceholderScreen()
                }
            }
            AnimatedVisibility(
                visible = walletOpen,
                enter = slideInVertically(
                    animationSpec = tween(420, easing = FastOutSlowInEasing),
                    initialOffsetY = { it },
                ) + fadeIn(animationSpec = tween(320)),
                exit = slideOutVertically(
                    animationSpec = tween(340, easing = FastOutSlowInEasing),
                    targetOffsetY = { it },
                ) + fadeOut(animationSpec = tween(260)),
            ) {
                WalletScreen(onExit = { walletOpen = false })
            }
            // The chat sits above the tab bar, like the wallet — the home bar stays on every chat page.
            AnimatedVisibility(
                visible = chatOpen,
                enter = slideInVertically(animationSpec = tween(420, easing = FastOutSlowInEasing), initialOffsetY = { it }) + fadeIn(animationSpec = tween(320)),
                exit = slideOutVertically(animationSpec = tween(340, easing = FastOutSlowInEasing), targetOffsetY = { it }) + fadeOut(animationSpec = tween(260)),
            ) {
                com.dorr.app.ui.screens.chat.ChatScreen(
                    onExit = { chatOpen = false },
                    openWalletQr = { payload ->
                        com.dorr.app.ui.screens.wallet.WalletDeepLink.openQr(payload)
                        chatOpen = false
                        walletOpen = true
                    },
                )
            }
            // The AI Assistant, opened from the docked "+" FAB — sits above the tab bar
            // exactly like the wallet and the person-to-person chat above.
            AnimatedVisibility(
                visible = aiChatOpen,
                enter = slideInVertically(animationSpec = tween(420, easing = FastOutSlowInEasing), initialOffsetY = { it }) + fadeIn(animationSpec = tween(320)),
                exit = slideOutVertically(animationSpec = tween(340, easing = FastOutSlowInEasing), targetOffsetY = { it }) + fadeOut(animationSpec = tween(260)),
            ) {
                com.dorr.app.ui.screens.aichat.AiChatHost(onExit = { aiChatOpen = false })
            }
        }
    }

    // A call can ring over anything (it's the only chat screen that takes the whole display).
    com.dorr.app.ui.screens.chat.CallOverlay()
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
    val night = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    val accountDark = night
    val barColor = if (accountDark) AccountDark.bg else Color.White
    val ringColor = if (accountDark) AccountDark.bg else Color.White

    Box(
        modifier = modifier.fillMaxWidth().background(barColor),
        contentAlignment = Alignment.BottomCenter,
    ) {
        Surface(
            modifier = Modifier
                .fillMaxWidth()
                .then(
                    if (accountDark) Modifier
                    else Modifier.shadow(
                        elevation = 10.dp,
                        spotColor = settingsAccent().copy(alpha = 0.08f),
                        ambientColor = Color.Black.copy(alpha = 0.04f),
                    ),
                ),
            color = barColor,
            tonalElevation = 0.dp,
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
                        .then(
                            if (accountDark) Modifier.background(Color(0xFF2C313A))
                            else Modifier.background(
                                Brush.horizontalGradient(
                                    colors = listOf(
                                        Color(0xFFEEEEEE),
                                        Color(0xFFFDE8EB),
                                        Color(0xFFF9C0C8),
                                        Color(0xFFFDE8EB),
                                        Color(0xFFEEEEEE),
                                    ),
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
                                settingsAccent().copy(alpha = 0.36f),
                                settingsAccent().copy(alpha = 0.15f),
                                settingsAccent().copy(alpha = 0.03f),
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
                        spotColor = settingsAccent().copy(alpha = 0.40f),
                        ambientColor = settingsAccent().copy(alpha = 0.18f),
                    )
                    .background(settingsAccent(), CircleShape)
                    .border(3.5.dp, ringColor, CircleShape)
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
                    contentDescription = stringResource(R.string.ai_fab_content_description),
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
                tint = if (selected) settingsAccent() else BottomBarInactiveGray,
                modifier = Modifier.size(23.dp),
            )
            Spacer(modifier = Modifier.height(4.dp))
            Text(
                text = stringResource(tab.label),
                fontSize = 11.sp,
                lineHeight = 13.sp,
                fontWeight = if (selected) FontWeight.Bold else FontWeight.Medium,
                color = if (selected) settingsAccent() else BottomBarInactiveGray,
                maxLines = 1,
            )
        }
    }
}
