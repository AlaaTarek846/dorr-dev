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
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
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
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

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

    CompositionLocalProvider(LocalChat provides host) {
        Box(Modifier.fillMaxSize().background(Ch.Bg)) {
            BackHandler { host.pop() }
            ChatPages(host)
            ChatToast(host)
        }
    }
}

@Composable
private fun ChatPages(host: ChatHost) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val sign = if (rtl) -1 else 1
    AnimatedContent(
        targetState = host.current,
        transitionSpec = {
            val forward = host.forward
            val enter = slideInHorizontally(spring(dampingRatio = 0.9f, stiffness = 380f)) { full -> (if (forward) sign else -sign) * full / 3 } + fadeIn(tween(260))
            val exit = slideOutHorizontally(tween(300)) { full -> (if (forward) -sign else sign) * full / 8 } + fadeOut(tween(220))
            ContentTransform(enter, exit, targetContentZIndex = if (forward) 1f else 0f)
        },
        label = "chatPages",
    ) { route ->
        when (route) {
            ChRoute.List -> ChatListPage()
            is ChRoute.Conversation -> ConversationPage(route)
            is ChRoute.Info -> ChatInfoPage(route.id)
            ChRoute.NewChat -> NewChatPage()
            is ChRoute.NewGroup -> NewGroupPage(route.addTo)
            ChRoute.MyQr -> MyQrPage()
            ChRoute.Privacy -> PrivacyPage()
            ChRoute.Starred -> StarredPage()
            ChRoute.Calls -> CallsPage()
        }
    }
}

@Composable
private fun ChatToast(host: ChatHost) {
    val message = host.toast
    var last by remember { mutableStateOf("") }
    if (message != null) last = message
    Box(Modifier.fillMaxSize().padding(bottom = 96.dp), contentAlignment = Alignment.BottomCenter) {
        AnimatedVisibility(
            visible = message != null,
            enter = slideInVertically(spring(dampingRatio = 0.6f)) { it } + fadeIn() + scaleIn(initialScale = 0.9f),
            exit = slideOutVertically { it / 2 } + fadeOut() + scaleOut(targetScale = 0.9f),
        ) {
            Row(
                Modifier.shadow(12.dp, RoundedCornerShape(18.dp)).clip(RoundedCornerShape(18.dp)).background(Ch.Ink).padding(horizontal = 18.dp, vertical = 12.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.CheckCircle, null, tint = Color.White, modifier = Modifier.size(16.dp))
                Text(last, color = Color.White, fontSize = 13.5.sp, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}
