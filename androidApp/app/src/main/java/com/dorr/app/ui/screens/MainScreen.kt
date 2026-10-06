package com.dorr.app.ui.screens

import androidx.activity.compose.BackHandler
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
import androidx.compose.foundation.interaction.collectIsPressedAsState
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
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.ServiceDto
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.theme.LocalAppearance
import com.dorr.app.ui.theme.appearanceColor
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
    onAccountDeleted: () -> Unit = {},
    initialTab: Int = 0,
    initialWalletOpen: Boolean = false,
    onStateChanged: (tab: Int, walletOpen: Boolean) -> Unit = { _, _ -> },
) {
    var currentTab by rememberSaveable { mutableIntStateOf(initialTab) }
    var walletOpen by rememberSaveable { mutableStateOf(initialWalletOpen) }
    var chatOpen by rememberSaveable { mutableStateOf(false) }
    var portalsOpen by rememberSaveable { mutableStateOf(false) }
    var momentsOpen by rememberSaveable { mutableStateOf(false) }
    var calendarOpen by rememberSaveable { mutableStateOf(false) }
    // Public stories on the home page — their own small chat host (viewer + composer).
    val homeStories = com.dorr.app.ui.screens.chat.rememberHomeStories()
    var serviceDetail by remember { mutableStateOf<Pair<ServiceDto, Color>?>(null) }
    val openServiceDetail: (ServiceDto, Color) -> Unit = { service, color ->
        serviceDetail = service to color
    }

    // A tapped notification: open the chat (the chat itself opens the conversation), or ring the call.
    LaunchedEffect(Unit) {
        com.dorr.app.chat.ChatPush.requestPermission()
        com.dorr.app.chat.ChatPush.deepLink.collect { link ->
            when (link) {
                is com.dorr.app.chat.ChatDeepLink.Conversation -> { walletOpen = false; calendarOpen = false; chatOpen = true }
                com.dorr.app.chat.ChatDeepLink.Tasks -> { walletOpen = false; chatOpen = true }
                com.dorr.app.chat.ChatDeepLink.Calendar -> {
                    walletOpen = false
                    calendarOpen = true
                    com.dorr.app.chat.ChatPush.consumeDeepLink()
                }
                com.dorr.app.chat.ChatDeepLink.Moments -> {
                    walletOpen = false
                    momentsOpen = true
                    com.dorr.app.chat.ChatPush.consumeDeepLink()
                }
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
    // Live locations I was sharing before the app was closed carry on (the app is in front now,
    // so Android lets the location service start).
    val liveContext = androidx.compose.ui.platform.LocalContext.current
    androidx.compose.runtime.LaunchedEffect(Unit) { com.dorr.app.chat.LiveLocationSharing.resume(liveContext) }
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

    // The phone's back button mirrors the in-app back: any tab other than Home returns to Home
    // (what the Services header arrow does); on Home it falls through and the app closes as before.
    // The wallet, chat and profile sub-screens register their own handlers later, so they win.
    BackHandler(enabled = currentTab != 0 && !walletOpen && !chatOpen) { currentTab = 0 }
    // Declared after the tab handler so an open service page closes first.
    BackHandler(enabled = serviceDetail != null) { serviceDetail = null }

    Box(Modifier.fillMaxSize()) {
    Scaffold(
        containerColor = if (night) AccountDark.bg else MaterialTheme.colorScheme.background,
        bottomBar = {
            // Hidden inside a chat (the conversation gets the whole screen), back on the chat list.
            AnimatedVisibility(
                visible = !(chatOpen && com.dorr.app.chat.ChatStore.immersive),
                enter = slideInVertically(tween(300, easing = FastOutSlowInEasing)) { it } + fadeIn(tween(220)),
                exit = slideOutVertically(tween(260, easing = FastOutSlowInEasing)) { it } + fadeOut(tween(180)),
            ) {
            DorrBottomNavigationBar(
                currentTab = currentTab,
                // No tab is "current" while the wallet or the chat is open over the tabs.
                walletOpen = walletOpen || chatOpen,
                onSelectTab = { index ->
                    currentTab = index
                    walletOpen = false
                    chatOpen = false
                },
                onFabClick = {
                    // New request flow placeholder / trigger
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
                        onOpenService = openServiceDetail,
                        onOpenChat = { chatOpen = true },
                        onOpenPortals = { portalsOpen = true },
                        homeStories = homeStories,
                        onOpenMoments = { momentsOpen = true },
                        onOpenCalendar = { calendarOpen = true },
                    )
                    1 -> ServicesScreen(
                        onBack = { currentTab = 0 },
                        onOpenService = openServiceDetail,
                    )
                    3 -> ProfileScreen(
                        onLogout = onLogout,
                        onOpenWallet = { walletOpen = true },
                        onAccountDeleted = onAccountDeleted,
                    )
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
        }
    }

    serviceDetail?.let { (service, color) ->
        ServiceDetailScreen(
            service = service,
            accentColor = color,
            onBack = { serviceDetail = null },
            onOpenChild = { child -> serviceDetail = child.toServiceDto() to color },
        )
    }

    // Merchant portals, opened from the home page's circles.
    AnimatedVisibility(
        visible = portalsOpen,
        enter = slideInVertically(animationSpec = tween(420, easing = FastOutSlowInEasing), initialOffsetY = { it }) + fadeIn(animationSpec = tween(320)),
        exit = slideOutVertically(animationSpec = tween(340, easing = FastOutSlowInEasing), targetOffsetY = { it }) + fadeOut(animationSpec = tween(260)),
    ) {
        com.dorr.app.ui.screens.portals.PortalsScreen(onExit = { portalsOpen = false })
    }

    // DORR Moments: occasions, my own dates, preferences.
    AnimatedVisibility(
        visible = momentsOpen,
        enter = slideInVertically(animationSpec = tween(420, easing = FastOutSlowInEasing), initialOffsetY = { it }) + fadeIn(animationSpec = tween(320)),
        exit = slideOutVertically(animationSpec = tween(340, easing = FastOutSlowInEasing), targetOffsetY = { it }) + fadeOut(animationSpec = tween(260)),
    ) {
        com.dorr.app.ui.screens.moments.MomentsScreen(onExit = { momentsOpen = false })
    }

    // DORR Calendar & DORR Today: my appointments, occasions, tasks and reminders.
    AnimatedVisibility(
        visible = calendarOpen,
        enter = slideInVertically(animationSpec = tween(420, easing = FastOutSlowInEasing), initialOffsetY = { it }) + fadeIn(animationSpec = tween(320)),
        exit = slideOutVertically(animationSpec = tween(340, easing = FastOutSlowInEasing), targetOffsetY = { it }) + fadeOut(animationSpec = tween(260)),
    ) {
        com.dorr.app.ui.screens.calendar.CalendarScreen(onExit = { calendarOpen = false }, onOpenMoments = { calendarOpen = false; momentsOpen = true })
    }

    // A public story being watched or written (from the home page's circles).
    com.dorr.app.ui.screens.chat.HomeStoriesLayer(homeStories)

    // The one payment screen, over whatever opened it.
    com.dorr.app.ui.screens.wallet.CheckoutOverlay()

    // A call can ring over anything (it's the only chat screen that takes the whole display).
    com.dorr.app.ui.screens.chat.CallOverlay()
    }
}

/**
 * The bar's top edge: flat under the four tabs, and under the centre button a smooth well — like
 * a planet bending space-time, a bell curve with gentle shoulders and a round bottom. Only the
 * middle dips; the tabs sit on the flat part.
 */
private fun gravityWellEdge(width: Float, top: Float, wellWidth: Float, depth: Float): androidx.compose.ui.graphics.Path {
    val cx = width / 2f
    val half = wellWidth / 2f
    return androidx.compose.ui.graphics.Path().apply {
        moveTo(0f, top)
        lineTo(cx - half, top)
        // Shoulder → slope → the round bottom of the well (two cubics per side keep it smooth).
        cubicTo(cx - half * 0.62f, top, cx - half * 0.52f, top + depth, cx, top + depth)
        cubicTo(cx + half * 0.52f, top + depth, cx + half * 0.62f, top, cx + half, top)
        lineTo(width, top)
    }
}

private class GravityWellShape(private val wellWidth: Float, private val depth: Float) : androidx.compose.ui.graphics.Shape {
    override fun createOutline(
        size: androidx.compose.ui.geometry.Size,
        layoutDirection: androidx.compose.ui.unit.LayoutDirection,
        density: androidx.compose.ui.unit.Density,
    ): androidx.compose.ui.graphics.Outline {
        val path = gravityWellEdge(size.width, 0f, wellWidth, depth).apply {
            lineTo(size.width, size.height)
            lineTo(0f, size.height)
            close()
        }
        return androidx.compose.ui.graphics.Outline.Generic(path)
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
    // Room above the bar for the button's top (it rises out of the well).
    val headroom = 26.dp
    val wellWidth = 156.dp
    // The gap between the button and the bottom of the well.
    val wellGap = 7.dp
    val night = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    val accountDark = night
    val barColor = if (accountDark) AccountDark.bg else appearanceColor("background", Color.White, night = false)
    val ringColor = barColor
    val edgeColor = if (barColor.luminance() > 0.6f) Color.Black.copy(alpha = 0.07f) else Color.White.copy(alpha = 0.10f)

    // Pressing the button makes the well a little deeper — the "planet" sinks in, then springs back.
    val fabInteraction = remember { MutableInteractionSource() }
    val pressed by fabInteraction.collectIsPressedAsState()
    val depth by androidx.compose.animation.core.animateDpAsState(
        if (pressed) 32.dp else 26.dp,
        androidx.compose.animation.core.spring(dampingRatio = 0.42f, stiffness = 420f),
        label = "wellDepth",
    )
    val density = androidx.compose.ui.platform.LocalDensity.current
    val wellWidthPx = with(density) { wellWidth.toPx() }
    val depthPx = with(density) { depth.toPx() }
    val shape = remember(wellWidthPx, depthPx) { GravityWellShape(wellWidthPx, depthPx) }

    Box(modifier = modifier.fillMaxWidth(), contentAlignment = Alignment.BottomCenter) {
        Column(Modifier.fillMaxWidth()) {
            Spacer(Modifier.height(headroom))
            // The bar: its top edge dips under the button; a soft shadow and a hairline follow the curve.
            Box(
                Modifier
                    .fillMaxWidth()
                    .shadow(16.dp, shape, ambientColor = Color.Black.copy(alpha = 0.10f), spotColor = Color.Black.copy(alpha = 0.10f))
                    .background(barColor, shape)
                    .drawBehind {
                        drawPath(
                            gravityWellEdge(size.width, 0f, wellWidthPx, depthPx),
                            color = edgeColor,
                            style = androidx.compose.ui.graphics.drawscope.Stroke(width = 1.dp.toPx()),
                        )
                    },
            ) {
                // 4 Tab items evenly distributed around the center FAB space
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .navigationBarsPadding()
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

                    // The well's room: no tab here.
                    Spacer(modifier = Modifier.width(wellWidth * 0.55f))

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

        // The button resting in the well (its bottom a small gap above the curve), with its glow.
        Box(
            modifier = Modifier
                .align(Alignment.TopCenter)
                .offset(y = headroom + depth - wellGap - fabSize - 13.dp),
            contentAlignment = Alignment.Center,
        ) {
            // Soft radial glow that diffuses onto the bar surface
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

            // Circular button with a crisp ring in the bar's colour
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
                        interactionSource = fabInteraction,
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
