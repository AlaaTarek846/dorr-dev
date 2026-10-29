package com.dorr.app.ui.screens.chat

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.runtime.LaunchedEffect
import com.dorr.app.ui.theme.LocalThemeState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.CloudOff
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Forum
import androidx.compose.runtime.collectAsState
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.res.stringResource
import com.dorr.app.R
import kotlinx.coroutines.launch

/**
 * The chat, as one visit: a stack of pages that slide over each other (a conversation comes in
 * from the reading edge, with a slight parallax on the page underneath), and a toast on top.
 * Drawn inside the main screen like the wallet.
 *
 * `openWalletQr` hands a scanned/shared wallet QR to the wallet, which opens its own confirmation.
 */
@Composable
fun ChatScreen(onExit: () -> Unit, openWalletQr: (String) -> Unit, initialConversation: String? = null) {
    val currentOnExit by rememberUpdatedState(onExit)
    val currentOpenQr by rememberUpdatedState(openWalletQr)
    val scope = rememberCoroutineScope()
    val host = remember {
        ChatHost(scope, onExit = { currentOnExit() }, openWalletQr = { currentOpenQr(it) }).also { h ->
            initialConversation?.let { h.push(ChRoute.Conversation(it)) }
        }
    }
    SideEffect { host.onExit = { currentOnExit() } }

    // Dark mode: the chat's own choice (system / light / dark), "system" following the app theme.
    val appDark = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    val mode = com.dorr.app.chat.ChatStore.themeMode
    val chatDark = when (mode) { "dark" -> true; "light" -> false; else -> appDark }
    // Colours from the appearance settings — the app colour and the neutrals (text, lines,
    // background, surfaces) for the chat's mode — exactly like the wallet and the profile pages.
    val palette = chatPaletteFromSettings(chatDark)
    val accent = if (chatDark) com.dorr.app.ui.screens.AccountDark.accent else com.dorr.app.ui.theme.appearanceColor("primary", com.dorr.app.ui.theme.AppColors.waRed, night = false)
    SideEffect {
        Ch.dark = chatDark
        Ch.accent = accent
        Ch.palette = palette
    }

    // Which AI tools the server offers (the ✨ buttons only show for those).
    LaunchedEffect(Unit) {
        ChatAi.load()
        host.refreshStories()
    }
    // What's on where I am (country / city rules) — the city from the phone's approximate
    // location, asked for once after our own explanation.
    val featureContext = androidx.compose.ui.platform.LocalContext.current
    val featureScope = rememberCoroutineScope()
    var explainLocation by remember { mutableStateOf(false) }
    val locationAsk = androidx.activity.compose.rememberLauncherForActivityResult(androidx.activity.result.contract.ActivityResultContracts.RequestPermission()) {
        com.dorr.app.chat.ChatFeatures.forceLocate(featureContext)
        featureScope.launch { com.dorr.app.chat.ChatFeatures.refresh(featureContext) }
    }
    LaunchedEffect(Unit) {
        com.dorr.app.chat.ChatFeatures.refresh(featureContext)
        if (com.dorr.app.chat.ChatFeatures.shouldAsk(featureContext)) explainLocation = true
    }
    if (explainLocation) {
        ChoiceSheet(
            title = stringResource(R.string.cf_location_title),
            subtitle = stringResource(R.string.cf_location_sub),
            options = listOf(
                stringResource(R.string.cf_location_ok) to {
                    explainLocation = false
                    com.dorr.app.chat.ChatFeatures.markAsked(featureContext)
                    locationAsk.launch(android.Manifest.permission.ACCESS_COARSE_LOCATION)
                },
                stringResource(R.string.cf_location_later) to {
                    explainLocation = false
                    com.dorr.app.chat.ChatFeatures.markAsked(featureContext)
                },
            ),
            onDismiss = {
                explainLocation = false
                com.dorr.app.chat.ChatFeatures.markAsked(featureContext)
            },
        )
    }
    // My reading and upload choices (spec 6, 13).
    ChatPrefs.load(androidx.compose.ui.platform.LocalContext.current)

    // This phone's privacy: settings, the private-notification count, the recent-apps snapshot,
    // and "while you were private" once a timed privacy mode has ended.
    val shieldContext = androidx.compose.ui.platform.LocalContext.current
    var privateSummary by remember { mutableStateOf<com.dorr.app.network.PrivacySummaryDto?>(null) }
    LaunchedEffect(Unit) {
        com.dorr.app.chat.ChatShield.load(shieldContext)
        com.dorr.app.chat.ChatShield.clearPrivate(shieldContext)
        val started = com.dorr.app.chat.ChatShield.privacyStartedAt(shieldContext)
        if (started > 0) {
            val mode = runCatching { com.dorr.app.network.ApiClient.chat.privacy(chatAuth()).data?.privacyMode }.getOrNull()
            if (mode != null && !mode.on) {
                val from = java.time.Instant.ofEpochMilli(started).toString()
                privateSummary = runCatching { com.dorr.app.network.ApiClient.chat.privacySummary(chatAuth(), from).data }.getOrNull()
                com.dorr.app.chat.ChatShield.setPrivacyStartedAt(shieldContext, 0)
                com.dorr.app.chat.ChatShield.setSafeView(shieldContext, false)
            }
        }
    }
    HideFromRecents()

    // A notification tapped for a conversation: open it on top of whatever chat page is showing.
    LaunchedEffect(Unit) {
        com.dorr.app.chat.ChatPush.deepLink.collect { link ->
            if (link is com.dorr.app.chat.ChatDeepLink.Conversation) {
                if ((host.current as? ChRoute.Conversation)?.id != link.id) host.push(ChRoute.Conversation(link.id))
                com.dorr.app.chat.ChatPush.consumeDeepLink()
            }
            // A "click to chat" link from outside the app (backlog 130).
            if (link is com.dorr.app.chat.ChatDeepLink.Join) {
                host.joinToken = link.token
                com.dorr.app.chat.ChatPush.consumeDeepLink()
            }
            if (link is com.dorr.app.chat.ChatDeepLink.CallLink) {
                host.callLinkToken = link.token
                com.dorr.app.chat.ChatPush.consumeDeepLink()
            }
            if (link is com.dorr.app.chat.ChatDeepLink.ChatLink) {
                host.openChatLink(link.payload)
                com.dorr.app.chat.ChatPush.consumeDeepLink()
            }
            // A task whose time came (spec 38): my tasks.
            if (link == com.dorr.app.chat.ChatDeepLink.Tasks) {
                if (host.current != ChRoute.Tasks) host.push(ChRoute.Tasks)
                com.dorr.app.chat.ChatPush.consumeDeepLink()
            }
            // As a parent (GR-018, 027): my children.
            if (link == com.dorr.app.chat.ChatDeepLink.ParentChildren) {
                if (host.current != ChRoute.ParentChildren) host.push(ChRoute.ParentChildren)
                com.dorr.app.chat.ChatPush.consumeDeepLink()
            }
        }
    }

    // Inside a chat — anything past the chat list — the app's tab bar steps away, so the
    // conversation (its wallpaper and the composer) has the whole screen.
    val immersive = host.current !is ChRoute.List
    SideEffect { com.dorr.app.chat.ChatStore.immersive = immersive }
    androidx.compose.runtime.DisposableEffect(Unit) { onDispose { com.dorr.app.chat.ChatStore.immersive = false } }

    CompositionLocalProvider(LocalChat provides host) {
        Box(Modifier.fillMaxSize().background(Ch.Bg)) {
            BackHandler { host.pop() }
            androidx.compose.runtime.DisposableEffect(Unit) {
                com.dorr.app.chat.ChatStore.screenOpen = true
                onDispose { com.dorr.app.chat.ChatStore.screenOpen = false }
            }
            // A little room above the home bar: its raised centre button pokes ~8dp up and would
            // otherwise sit on the composer / the last row.
            BoxWithConstraints(Modifier.fillMaxSize().padding(bottom = 12.dp)) {
                // Room for two (a tablet, an unfolded phone, landscape — spec 89): the list stays on
                // the side and pages open beside it. Measured from the real space, so split screen and
                // folding follow too.
                val twoPane = maxWidth >= TWO_PANE_MIN_WIDTH
                SideEffect { host.twoPane = twoPane }
                if (twoPane) {
                    val listWidth = (maxWidth * 0.38f).coerceIn(320.dp, 420.dp)
                    Row(Modifier.fillMaxSize()) {
                        Box(Modifier.width(listWidth).fillMaxHeight()) { ChatListPage() }
                        Box(Modifier.width(1.dp).fillMaxHeight().background(Ch.Line))
                        Box(Modifier.weight(1f).fillMaxHeight()) { ChatPages(host, host.detail) }
                    }
                } else {
                    ChatPages(host, host.current)
                }
            }
            StoryViewerOverlay()
            // A video played / a PDF read inside the app.
            ChatViewersHost()
            privateSummary?.let { PrivacySummaryDialog(it) { privateSummary = null } }
            OfflineBanner()
            // In a voice room while elsewhere in the chat: it stays with me (spec 91).
            Box(Modifier.fillMaxSize().statusBarsPadding(), contentAlignment = Alignment.TopCenter) { VoiceMiniBar() }
            ChatToast(host)
            // A group invite link was tapped: preview + "Join" / "Ask to join".
            host.joinToken?.let { token -> JoinGroupSheet(token) { host.joinToken = null } }
            // A call link was opened: who made it, then "Join".
            host.callLinkToken?.let { token -> CallLinkSheet(token) { host.callLinkToken = null } }
            // Once: let calls show over other apps and the lock screen.
            CallPermissionPrompt()
            // The admins answered my request to join.
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.TopCenter) {
                AnimatedVisibility(host.joinDecided != null, enter = slideInVertically { -it } + fadeIn(), exit = slideOutVertically { -it } + fadeOut()) {
                    host.joinDecided?.let { d ->
                        JoinDecisionBanner(d, onOpen = {
                            host.joinDecided = null
                            host.push(ChRoute.Conversation(d.conversationId))
                        }, onDismiss = { host.joinDecided = null })
                    }
                }
            }
        }
    }
}

/** From this width the chat list and the open page sit side by side (spec 89). */
private val TWO_PANE_MIN_WIDTH = 700.dp

/** The page on top — or, beside the list on a wide screen, `null` until something is opened there. */
@Composable
private fun ChatPages(host: ChatHost, target: ChRoute?) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val sign = if (rtl) -1 else 1
    AnimatedContent(
        targetState = target,
        transitionSpec = {
            val forward = host.forward
            val enter = slideInHorizontally(spring(dampingRatio = 0.9f, stiffness = 380f)) { full -> (if (forward) sign else -sign) * full / 3 } + fadeIn(tween(260))
            val exit = slideOutHorizontally(tween(300)) { full -> (if (forward) -sign else sign) * full / 8 } + fadeOut(tween(220))
            ContentTransform(enter, exit, targetContentZIndex = if (forward) 1f else 0f)
        },
        label = "chatPages",
    ) { route ->
        if (route == null) ChatPanePlaceholder() else ChatPage(route)
    }
}

/** Beside the list, before a chat is opened. */
@Composable
private fun ChatPanePlaceholder() {
    Box(Modifier.fillMaxSize().background(Ch.Bg), contentAlignment = Alignment.Center) {
        Column(Modifier.padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(84.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.08f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Forum, null, tint = Ch.Red, modifier = Modifier.size(40.dp))
            }
            Spacer(Modifier.height(18.dp))
            Text(androidx.compose.ui.res.stringResource(com.dorr.app.R.string.ch_pane_title), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(6.dp))
            Text(androidx.compose.ui.res.stringResource(com.dorr.app.R.string.ch_pane_sub), color = Ch.Mut, fontSize = 14.sp, textAlign = TextAlign.Center)
        }
    }
}

@Composable
private fun ChatPage(route: ChRoute) {
    when (route) {
        ChRoute.List -> ChatListPage()
        is ChRoute.Conversation -> ConversationPage(route)
        is ChRoute.Info -> ChatInfoPage(route.id)
        is ChRoute.Media -> ChatMediaPage(route)
        is ChRoute.ChatTheme -> ChatThemePage(route.id)
        is ChRoute.ChatMoney -> ChatMoneyPage(route.id)
        is ChRoute.GroupMembers -> GroupMembersPage(route.id)
        ChRoute.NewChat -> NewChatPage()
        ChRoute.Channels -> ChannelsPage()
        is ChRoute.NewGroup -> NewGroupPage(route.addTo)
        ChRoute.MyQr -> MyQrPage()
        ChRoute.MyStatus -> MyStatusPage()
        ChRoute.ChatSettings -> ChatSettingsPage()
        ChRoute.Privacy -> PrivacyPage()
        ChRoute.BlockedContacts -> BlockedContactsPage()
        ChRoute.Business -> BusinessPage()
        ChRoute.Starred -> StarredPage()
        ChRoute.ReadLater -> FollowUpsPage()
        ChRoute.Calls -> CallsPage()
        ChRoute.Communities -> CommunitiesPage()
        is ChRoute.Community -> CommunityPage(route.id)
        is ChRoute.StoryComposer -> StoryComposerPage(route.media)
        ChRoute.StoryPrivacy -> StoryPrivacyPage()
        is ChRoute.Thread -> ThreadPage(route.conversationId, route.rootId)
        is ChRoute.Decisions -> DecisionsPage(route.conversationId, route.isAdmin)
        is ChRoute.GroupTasks -> GroupTasksPage(route.conversationId, route.spaceId, route.spaceName)
        is ChRoute.Classroom -> ClassroomPage(route.conversationId)
        is ChRoute.ParentChildren -> ParentChildrenPage()
        is ChRoute.ParentChild -> ParentChildPage(route.linkId, route.title)
        ChRoute.Broadcasts -> BroadcastsPage()
        is ChRoute.Broadcast -> BroadcastPage(route.id)
        ChRoute.Tasks -> TasksPage()
        ChRoute.CatchUp -> CatchUpPage()
        ChRoute.Important -> ImportantPage()
        ChRoute.Today -> TodayPage()
        ChRoute.PrivacyCenter -> PrivacyCenterPage()
        is ChRoute.DecisionRoom -> DecisionRoomPage(route.id)
        ChRoute.Commitments -> CommitmentsPage()
        ChRoute.CommRules -> CommRulesPage()
        is ChRoute.ContextRules -> ContextRulesPage(route.key)
        ChRoute.LifeInbox -> LifeInboxPage()
        ChRoute.DataPerformance -> DataPerformancePage()
        ChRoute.Usage -> UsagePage()
        is ChRoute.Storage -> StoragePage(route.conversationId, route.title)
        is ChRoute.ChannelStats -> ChannelStatsPage(route.conversationId)
        is ChRoute.VoiceRoom -> VoiceRoomPage(route.conversationId, route.title)
        is ChRoute.LiveViewer -> LiveViewerPage(route.token)
    }
}

/** No connection: a slim bar slides down — everything saved on the phone stays readable. */
@Composable
private fun OfflineBanner() {
    val context = androidx.compose.ui.platform.LocalContext.current
    val online by com.dorr.app.network.NetworkMonitor.getInstance(context).isOnline.collectAsState()
    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.TopCenter) {
        AnimatedVisibility(!online, enter = slideInVertically { -it } + fadeIn(), exit = slideOutVertically { -it } + fadeOut()) {
            Row(
                Modifier.padding(top = 8.dp).shadow(10.dp, RoundedCornerShape(16.dp)).clip(RoundedCornerShape(16.dp)).background(Ch.Ink).padding(horizontal = 16.dp, vertical = 9.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.CloudOff, null, tint = Ch.Bg, modifier = Modifier.size(16.dp))
                Text(androidx.compose.ui.res.stringResource(com.dorr.app.R.string.ch_offline), color = Ch.Bg, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

@Composable
internal fun ChatToast(host: ChatHost) {
    val message = host.toast
    var last by remember { mutableStateOf("") }
    var lastIsError by remember { mutableStateOf(false) }
    if (message != null) {
        last = message
        lastIsError = host.toastError
    }
    // Same chip as the wallet: navy (or danger) and white type, above the composer so it stays readable at night.
    Box(Modifier.fillMaxSize().padding(start = 16.dp, end = 16.dp, bottom = 148.dp), contentAlignment = Alignment.BottomCenter) {
        AnimatedVisibility(
            visible = message != null,
            enter = slideInVertically(spring(dampingRatio = 0.6f)) { it } + fadeIn() + scaleIn(initialScale = 0.9f),
            exit = slideOutVertically { it / 2 } + fadeOut() + scaleOut(targetScale = 0.9f),
        ) {
            Row(
                Modifier
                    .shadow(12.dp, RoundedCornerShape(16.dp), ambientColor = Color(0x33111928), spotColor = Color(0x44111928))
                    .clip(RoundedCornerShape(16.dp))
                    .background(if (lastIsError) Ch.Danger else Color(0xFF111928))
                    .padding(horizontal = 18.dp, vertical = 12.dp),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(
                    if (lastIsError) Icons.Rounded.ErrorOutline else Icons.Rounded.CheckCircle,
                    null,
                    tint = Color.White,
                    modifier = Modifier.size(18.dp),
                )
                Text(last, color = Color.White, fontSize = 13.5.sp, fontWeight = FontWeight.SemiBold, lineHeight = 20.sp)
            }
        }
    }
}
