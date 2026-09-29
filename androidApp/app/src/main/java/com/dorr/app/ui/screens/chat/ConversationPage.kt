package com.dorr.app.ui.screens.chat

import android.app.Activity
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.view.WindowManager
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTransformGestures
import androidx.compose.foundation.gestures.detectVerticalDragGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.PushPin
import androidx.compose.material.icons.rounded.Search
import androidx.compose.ui.focus.focusRequester
import androidx.compose.material.icons.rounded.Timer
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.blur
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.chat.CallController
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.MessageDto
import com.dorr.app.network.OpenDirectRequest
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.launch
import java.time.LocalDate

/** A row in the conversation list: a date chip or a message with its place in a run. */
private sealed interface Line {
    val key: String

    data class Day(val day: LocalDate) : Line {
        override val key get() = "day-$day"
    }

    data class Msg(val m: UiMessage, val first: Boolean, val last: Boolean) : Line {
        override val key get() = m.id
    }
}

@Composable
fun ConversationPage(route: ChRoute.Conversation) {
    val host = LocalChat.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val state = remember(route.id) { ConversationState(route.id, scope, host, context) }
    val listState = rememberLazyListState()
    var viewer by remember { mutableStateOf<Pair<MessageDto, Int>?>(null) }
    var highlight by remember { mutableStateOf<String?>(null) }
    var infoFor by remember { mutableStateOf<MessageDto?>(null) }
    var votesFor by remember { mutableStateOf<MessageDto?>(null) }
    // The money request / split being paid (PIN sheet open).
    var payFor by remember { mutableStateOf<MessageDto?>(null) }
    // A view-once file, opened this one time (closing the viewer is final).
    var viewOnceFiles by remember { mutableStateOf<List<com.dorr.app.network.AttachmentDto>?>(null) }
    val c = state.conversation ?: route.preview

    BackHandler(state.focused != null || viewer != null) {
        if (viewer != null) viewer = null else state.focused = null
    }

    // Block screenshots when the other person asked for it.
    val secure = c?.blockScreenshots == true
    DisposableEffect(secure) {
        val window = (context as? Activity)?.window
        if (secure) window?.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
        onDispose { window?.clearFlags(WindowManager.LayoutParams.FLAG_SECURE) }
    }

    // The chat's theme (admin-made, picked in chat info) colours the bubbles — Ch reads it — while open.
    val chatTheme = c?.theme?.applied
    androidx.compose.runtime.SideEffect { Ch.bubbleTheme = chatTheme }
    DisposableEffect(Unit) { onDispose { Ch.bubbleTheme = null } }

    // Newest at index 0 (reverseLayout): the list is anchored to the bottom like every messenger.
    val lines by remember {
        derivedStateOf {
            val out = mutableListOf<Line>()
            val msgs = state.messages
            var lastDay: LocalDate? = null
            msgs.forEachIndexed { i, m ->
                val day = dayOf(m.dto.createdAt) ?: LocalDate.now()
                if (day != lastDay) {
                    out += Line.Day(day)
                    lastDay = day
                }
                val prev = msgs.getOrNull(i - 1)
                val next = msgs.getOrNull(i + 1)
                fun sameRun(a: UiMessage?, b: UiMessage): Boolean {
                    if (a == null || a.dto.sender?.key != b.dto.sender?.key || a.dto.type == "system" || b.dto.type == "system") return false
                    val ta = parseInstant(a.dto.createdAt) ?: return false
                    val tb = parseInstant(b.dto.createdAt) ?: return false
                    return kotlin.math.abs(tb.epochSecond - ta.epochSecond) < 300 && dayOf(a.dto.createdAt) == dayOf(b.dto.createdAt)
                }
                out += Line.Msg(m, first = !sameRun(prev, m), last = next == null || !sameRun(m, next))
            }
            out.asReversed().toList()
        }
    }

    val atBottom by remember { derivedStateOf { listState.firstVisibleItemIndex <= 1 } }
    LaunchedEffect(atBottom) {
        state.atBottom = atBottom
        // After a jump to an old message, reaching the bottom loads what came after it.
        if (atBottom && state.hasMoreAfter) state.loadNewer()
        if (atBottom && state.unseenBelow > 0 && !state.hasMoreAfter) {
            state.unseenBelow = 0
            state.markRead()
        }
    }

    // The notification for this chat stays silent while it's on screen.
    DisposableEffect(route.id) {
        com.dorr.app.chat.ChatPush.openConversationId = route.id
        onDispose { if (com.dorr.app.chat.ChatPush.openConversationId == route.id) com.dorr.app.chat.ChatPush.openConversationId = null }
    }

    // Search inside this conversation.
    var searching by remember { mutableStateOf(false) }
    var searchQuery by remember { mutableStateOf("") }
    var searchResults by remember { mutableStateOf<List<MessageDto>?>(null) }
    LaunchedEffect(searchQuery) {
        if (searchQuery.trim().length < 2) {
            searchResults = null
            return@LaunchedEffect
        }
        kotlinx.coroutines.delay(300)
        searchResults = runCatching { ApiClient.chat.searchIn(chatAuth(), route.id, searchQuery.trim()).data }.getOrNull().orEmpty()
    }
    // Keep the newest message in view when I send / when I'm already at the bottom.
    LaunchedEffect(state.messages.size) {
        val last = state.messages.lastOrNull() ?: return@LaunchedEffect
        if (last.isMine || atBottom) listState.animateScrollToItem(0)
    }
    // Page back in history when the top comes into view.
    LaunchedEffect(listState) {
        snapshotFlow { listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0 }
            .distinctUntilChanged()
            .collect { lastVisible -> if (lastVisible >= lines.size - 6) state.loadOlder() }
    }

    fun jumpTo(messageId: String) {
        scope.launch {
            var index = lines.indexOfFirst { it.key == messageId }
            // Not loaded yet (an old search result / pin): load the page around it first.
            if (index < 0 && state.loadAround(messageId)) {
                kotlinx.coroutines.delay(60)
                index = lines.indexOfFirst { it.key == messageId }
            }
            if (index < 0) return@launch
            listState.animateScrollToItem(index)
            highlight = messageId
            kotlinx.coroutines.delay(1400)
            highlight = null
        }
    }

    val actions = remember(state) {
        BubbleActions(
            onReply = { state.replyTo = it; state.editing = null },
            onLongPress = { state.focused = it },
            onJumpTo = { jumpTo(it) },
            onRetry = { state.retry(it) },
            onOpenMedia = { m, i ->
                val a = m.attachments.getOrNull(i)
                if (a != null && a.mimeType?.startsWith("video/") == true) {
                    runCatching { context.startActivity(Intent(Intent.ACTION_VIEW).setDataAndType(Uri.parse(ApiClient.mediaUrl(a.url)), a.mimeType)) }
                } else viewer = m to i
            },
            onPayQr = { host.openWalletQr(it) },
            onMessageContact = { phone ->
                scope.launch {
                    runCatching {
                        val who = ApiClient.chat.lookup(chatAuth(), mapOf("phone" to phone)).data ?: return@runCatching
                        val conv = ApiClient.chat.openDirect(chatAuth(), OpenDirectRequest(who.id)).data ?: return@runCatching
                        host.push(ChRoute.Conversation(conv.id, conv))
                    }.onFailure { host.showToast(context.getString(R.string.ch_not_on_dorr)) }
                }
            },
            onVote = { m, option -> state.vote(m, option) },
            onShowVotes = { votesFor = it },
            onOpenViewOnce = { m -> scope.launch { state.openViewOnce(m)?.takeIf { it.isNotEmpty() }?.let { viewOnceFiles = it } } },
            onStopLive = { state.stopLive(it) },
            onJoinGroup = { host.joinToken = it },
            onPay = { payFor = it.dto },
            onDeclineRequest = { state.declineRequest(it) },
            onCancelRequest = { state.cancelRequest(it) },
        )
    }

    val blurred by animateFloatAsState(if (state.focused != null) 14f else 0f, tween(260), label = "blur")

    // A locked chat stays behind the phone's lock (fingerprint / face / PIN) until it's passed.
    if (c?.isLocked == true && !ChatLock.unlocked) {
        LockedGate(onUnlocked = {})
        return
    }

    Box(Modifier.fillMaxSize().background(Ch.Bg)) {
        Column(Modifier.fillMaxSize().imePadding().then(if (blurred > 0.5f && Build.VERSION.SDK_INT >= 31) Modifier.blur(blurred.dp) else Modifier)) {
            AnimatedContent(targetState = searching, label = "convHeader", transitionSpec = {
                (fadeIn(tween(220)) + slideInVertically { -it / 3 }) togetherWith (fadeOut(tween(150)) + slideOutVertically { -it / 3 })
            }) { isSearching ->
                if (isSearching) ConversationSearchBar(searchQuery, onChange = { searchQuery = it }) { searching = false; searchQuery = "" }
                else ConversationHeader(c, state, onSearch = { searching = true })
            }
            BackHandler(searching) { searching = false; searchQuery = "" }
            PinnedBanner(state) { jumpTo(it) }

            Box(Modifier.weight(1f).fillMaxWidth()) {
<<<<<<< HEAD
                ChWallpaper()
=======
                ThemedWallpaper(chatTheme)
>>>>>>> main
                when {
                    state.loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ch.Red, strokeWidth = 3.dp) }
                    state.failed -> ChEmptyState(Icons.Rounded.Warning, stringResource(R.string.ch_load_failed), stringResource(R.string.ch_error_network), stringResource(R.string.ch_retry), onAction = { scope.launch { state.load() } })
                    else -> LazyColumn(
                        state = listState,
                        reverseLayout = true,
                        contentPadding = PaddingValues(top = 12.dp, bottom = 8.dp),
                        modifier = Modifier.fillMaxSize(),
                    ) {
                        val typing = host.activity(state.id).firstOrNull()
                        if (typing != null) item(key = "typing") { TypingBubble(null) }
                        items(lines, key = { it.key }) { line ->
                            when (line) {
                                is Line.Day -> Box(Modifier.fillMaxWidth().padding(vertical = 10.dp).animateItem(), contentAlignment = Alignment.Center) { ChChip(dayLabel(line.day)) }
                                is Line.Msg -> Box(Modifier.animateItem(fadeInSpec = null, placementSpec = spring(dampingRatio = 0.85f, stiffness = 500f))) {
                                    // A channel's posts come from the channel, not from a person: no names / avatars.
                                    MessageRow(line.m, line.first, line.last, c?.isGroup == true && c.isChannel != true, actions, highlighted = highlight == line.m.id)
                                }
                            }
                        }
                        if (state.loadingOlder) item(key = "older") {
                            Box(Modifier.fillMaxWidth().padding(12.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(22.dp), color = Ch.Red, strokeWidth = 2.dp) }
                        }
                        if (!state.hasMoreBefore && state.messages.isEmpty()) item(key = "hello") {
                            Box(Modifier.fillMaxWidth().padding(40.dp), contentAlignment = Alignment.Center) { ChChip(stringResource(R.string.ch_empty_conversation)) }
                        }
                    }
                }

                androidx.compose.animation.AnimatedVisibility(searching && searchResults != null, enter = fadeIn(), exit = fadeOut()) {
                    SearchResults(searchQuery, searchResults.orEmpty()) { id ->
                        searching = false
                        searchQuery = ""
                        jumpTo(id)
                    }
                }

                // ↓ with the number of messages that arrived while scrolled up.
                androidx.compose.animation.AnimatedVisibility(
                    visible = !atBottom,
                    enter = scaleIn(spring(dampingRatio = 0.5f)) + fadeIn(),
                    exit = scaleOut() + fadeOut(),
                    modifier = Modifier.align(Alignment.BottomEnd).padding(14.dp),
                ) {
                    Box {
                        Box(
                            Modifier.size(46.dp).shadow(10.dp, CircleShape).clip(CircleShape).background(Ch.Surface).clickable { scope.launch { listState.animateScrollToItem(0) } },
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.Rounded.KeyboardArrowDown, null, tint = Ch.Red, modifier = Modifier.size(28.dp)) }
                        if (state.unseenBelow > 0) ChBadge(state.unseenBelow, modifier = Modifier.align(Alignment.TopEnd))
                    }
                }
            }

            BottomArea(c, state)
        }

        MessageFocusOverlay(state, onOpenInfo = { infoFor = it })

        AnimatedVisibility(viewer != null, enter = fadeIn(tween(200)) + scaleIn(initialScale = 0.92f), exit = fadeOut(tween(180)) + scaleOut(targetScale = 0.92f)) {
            viewer?.let { (m, i) -> MediaViewer(m, i) { viewer = null } }
        }
        infoFor?.let { m -> MessageInfoSheet(m) { infoFor = null } }
        votesFor?.let { m -> PollVotesSheet(m) { votesFor = null } }
        payFor?.let { m -> ChatPaySheet(m, onDismiss = { payFor = null }) { paid -> state.paymentUpdated(paid) } }
        androidx.compose.animation.AnimatedVisibility(
            viewOnceFiles != null,
            enter = androidx.compose.animation.fadeIn() + androidx.compose.animation.scaleIn(initialScale = 0.92f),
            exit = androidx.compose.animation.fadeOut(),
        ) {
            viewOnceFiles?.let { files -> ViewOnceViewer(files) { viewOnceFiles = null } }
        }
    }
}

// ------------------------------------------------------------------------------- header

@Composable
private fun ConversationSearchBar(query: String, onChange: (String) -> Unit, onClose: () -> Unit) {
    val focus = remember { androidx.compose.ui.focus.FocusRequester() }
    LaunchedEffect(Unit) { focus.requestFocus() }
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(bottomStart = 26.dp, bottomEnd = 26.dp)).background(Ch.HeaderBrush).padding(horizontal = 10.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        GlassIcon(Icons.Rounded.Close, size = 38.dp, onClick = onClose)
        Spacer(Modifier.width(8.dp))
        ChField(
            query, onChange, stringResource(R.string.ch_search_in_chat),
            modifier = Modifier.weight(1f),
            icon = Icons.Rounded.Search, clearable = true,
            fieldModifier = Modifier.focusRequester(focus),
        )
    }
}

/** Search results over the conversation: sender, the text with the match in bold, the date. */
@Composable
private fun SearchResults(query: String, results: List<MessageDto>, onPick: (String) -> Unit) {
    androidx.compose.foundation.lazy.LazyColumn(Modifier.fillMaxSize().background(Ch.Bg.copy(alpha = 0.97f)), contentPadding = PaddingValues(12.dp)) {
        if (results.isEmpty()) item {
            Text(stringResource(R.string.ch_no_results), color = Ch.Soft, modifier = Modifier.fillMaxWidth().padding(30.dp), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
        }
        items(results.size) { i ->
            val m = results[i]
            val body = m.body.orEmpty()
            val at = body.indexOf(query.trim(), ignoreCase = true)
            val text = androidx.compose.ui.text.buildAnnotatedString {
                append(body)
                if (at >= 0) addStyle(androidx.compose.ui.text.SpanStyle(color = Ch.Red, fontWeight = FontWeight.ExtraBold), at, at + query.trim().length)
            }
            Column(
                Modifier.fillMaxWidth().chStagger(i).padding(vertical = 3.dp).clip(RoundedCornerShape(16.dp)).background(Ch.Surface).clickable { onPick(m.id) }.padding(12.dp),
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(if (m.sender?.isMe == true) stringResource(R.string.ch_you) else m.sender?.name.orEmpty(), color = Ch.colorFor(m.sender?.key), fontWeight = FontWeight.ExtraBold, fontSize = 13.sp, modifier = Modifier.weight(1f))
                    Text(listTime(m.createdAt), color = Ch.Soft, fontSize = 11.5.sp)
                }
                Text(text, color = Ch.Ink, fontSize = 14.sp, maxLines = 3, overflow = TextOverflow.Ellipsis)
            }
        }
    }
}

@Composable
private fun ConversationHeader(c: ConversationDto?, state: ConversationState, onSearch: () -> Unit) {
    val host = LocalChat.current
    val peerKey = c?.peer?.key
    val presence = peerKey?.let { host.presence[it] } ?: c?.presence
    val activity = host.activity(state.id).firstOrNull()
    val startCall = rememberCallStarter()
    val video = remember { Animatable(0f) }
    LaunchedEffect(Unit) { video.animateTo(1f, spring(dampingRatio = 0.6f)) }

    Box(
        Modifier.fillMaxWidth().shadow(10.dp, RoundedCornerShape(bottomStart = 26.dp, bottomEnd = 26.dp), spotColor = Ch.Red.copy(alpha = 0.4f))
            .clip(RoundedCornerShape(bottomStart = 26.dp, bottomEnd = 26.dp)).background(Ch.HeaderBrush),
    ) {
        Row(Modifier.fillMaxWidth().padding(start = 8.dp, end = 10.dp, top = 12.dp, bottom = 12.dp), verticalAlignment = Alignment.CenterVertically) {
            GlassIcon(Icons.AutoMirrored.Rounded.ArrowBack, size = 38.dp) { host.pop() }
            Spacer(Modifier.width(8.dp))
            Row(
                Modifier.weight(1f).clip(RoundedCornerShape(16.dp)).clickable { host.push(ChRoute.Info(state.id)) }.padding(vertical = 2.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                ChAvatar(c?.avatar, c?.title, peerKey ?: state.id, size = 42.dp, isGroup = c?.isGroup == true, online = presence?.online == true)
                Spacer(Modifier.width(10.dp))
                Column {
                    Text(c?.title.orEmpty(), color = Color.White, fontSize = 16.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    val subtitle = when {
                        activity != null -> stringResource(if (activity.state == "recording") R.string.ch_recording else R.string.ch_typing)
                        c?.isChannel == true -> stringResource(R.string.ch_followers, c.group?.membersCount ?: 0)
                        c?.isGroup == true -> stringResource(R.string.ch_members, c.group?.membersCount ?: 0)
                        presence?.online == true -> stringResource(R.string.ch_online)
                        presence?.lastSeenAt != null -> lastSeenText(presence.lastSeenAt)
                        else -> stringResource(R.string.ch_tap_info)
                    }
                    AnimatedContent(targetState = subtitle, label = "subtitle", transitionSpec = {
                        (slideInVertically { it } + fadeIn()) togetherWith (slideOutVertically { -it } + fadeOut())
                    }) { text ->
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(text, color = Color.White.copy(alpha = 0.85f), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            if (activity != null) {
                                Spacer(Modifier.width(5.dp))
                                TypingDots(Color.White, dot = 4.dp)
                            }
                        }
                    }
                }
            }
            GlassIcon(Icons.Rounded.Search, size = 38.dp, onClick = onSearch)
            Spacer(Modifier.width(6.dp))
            if (c != null && !c.isRequest && c.canSend) {
                Box(Modifier.graphicsLayer { scaleX = video.value; scaleY = video.value }) {
                    GlassIcon(Icons.Rounded.Videocam, size = 38.dp) { startCall(state.id, true, c.title.orEmpty(), c.avatar, peerKey) }
                }
                Spacer(Modifier.width(6.dp))
                Box(Modifier.graphicsLayer { scaleX = video.value; scaleY = video.value }) {
                    GlassIcon(Icons.Rounded.Call, size = 38.dp) { startCall(state.id, false, c.title.orEmpty(), c.avatar, peerKey) }
                }
            }
        }
        if (c?.disappearingSeconds != null) {
            Icon(Icons.Rounded.Timer, null, tint = Color.White.copy(alpha = 0.7f), modifier = Modifier.align(Alignment.BottomCenter).padding(bottom = 2.dp).size(12.dp))
        }
    }
}

@Composable
private fun lastSeenText(iso: String): String {
    val day = dayOf(iso)
    return if (day == LocalDate.now()) stringResource(R.string.ch_last_seen_today, clockTime(iso))
    else stringResource(R.string.ch_last_seen, listTime(iso) + " " + clockTime(iso))
}

@Composable
private fun PinnedBanner(state: ConversationState, onJump: (String) -> Unit) {
    val pinned = state.pinned
    var index by remember(pinned) { mutableStateOf(0) }
    AnimatedVisibility(pinned.isNotEmpty(), enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
        val current = pinned.getOrNull(index % pinned.size.coerceAtLeast(1)) ?: return@AnimatedVisibility
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 8.dp).shadow(6.dp, RoundedCornerShape(16.dp)).clip(RoundedCornerShape(16.dp)).background(Ch.Surface)
                .clickable {
                    onJump(current.id)
                    index++ // tapping again cycles through the pins
                }
                .padding(horizontal = 12.dp, vertical = 9.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.PushPin, null, tint = Ch.Red, modifier = Modifier.size(18.dp).rotate(35f))
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.ch_pinned_message) + if (pinned.size > 1) " ${index % pinned.size + 1}/${pinned.size}" else "", color = Ch.Red, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold)
                val (_, label) = previewOf(current.type, current.isDeleted)
                AnimatedContent(current.body?.takeIf { it.isNotBlank() } ?: label, label = "pin") { text ->
                    Text(text, color = Ch.Ink, fontSize = 13.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            }
        }
    }
}

// ------------------------------------------------------------------------------- bottom

@Composable
private fun BottomArea(c: ConversationDto?, state: ConversationState) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    when {
        c == null -> Spacer(Modifier.height(1.dp))
        c.isRequest -> RequestBar(c, state)
        c.iBlocked == true -> NoticeBar(stringResource(R.string.ch_blocked_bar), Icons.Rounded.Block) {
            val peer = c.peer ?: return@NoticeBar
            scope.launch {
                runCatching { ApiClient.chat.unblock(chatAuth(), mapOf("participant_id" to peer.id)) }
                state.conversation = runCatching { ApiClient.chat.conversation(chatAuth(), c.id).data }.getOrNull() ?: c
            }
        }
        // A channel I don't (or no longer) follow: one big "Follow".
        c.isChannel && !c.isMember -> ChannelFollowBar(c) { state.conversation = it }
        !c.isMember -> NoticeBar(stringResource(R.string.ch_not_member), null) {}
        // A follower: mute and share instead of a composer.
        c.isChannel && !c.canSend -> ChannelFollowerBar(c) { state.conversation = it }
        c.blockedMe == true || c.status == "rejected" -> NoticeBar(stringResource(R.string.ch_cant_send), null) {}
        !c.canSend -> NoticeBar(stringResource(R.string.ch_only_admins), null) {}
        else -> Composer(state)
    }
}

@Composable
private fun NoticeBar(text: String, icon: androidx.compose.ui.graphics.vector.ImageVector?, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(12.dp).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).clickable(onClick = onClick).padding(16.dp),
        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
    ) {
        icon?.let { Icon(it, null, tint = Ch.Red, modifier = Modifier.size(18.dp)); Spacer(Modifier.width(8.dp)) }
        Text(text, color = Ch.Mut, fontSize = 13.5.sp, fontWeight = FontWeight.SemiBold)
    }
}

/** A stranger's first message: accept, or delete (optionally block). */
@Composable
private fun RequestBar(c: ConversationDto, state: ConversationState) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    Column(
        Modifier.fillMaxWidth().padding(12.dp).shadow(10.dp, RoundedCornerShape(24.dp)).clip(RoundedCornerShape(24.dp)).background(Ch.Surface).padding(18.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(stringResource(R.string.ch_request_title, c.title.orEmpty()), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.5.sp)
        Spacer(Modifier.height(4.dp))
        Text(stringResource(R.string.ch_request_text), color = Ch.Mut, fontSize = 12.5.sp, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
        Spacer(Modifier.height(14.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            listOf(
                Triple(R.string.ch_block, Ch.Danger.copy(alpha = 0.1f), Ch.Danger),
                Triple(R.string.ch_reject, Ch.SurfaceMuted, Ch.Ink),
            ).forEach { (label, bg, fg) ->
                Box(
                    Modifier.clip(RoundedCornerShape(14.dp)).background(bg).clickable {
                        scope.launch {
                            runCatching { ApiClient.chat.reject(chatAuth(), c.id, mapOf("block" to (label == R.string.ch_block))) }
                            host.remove(c.id)
                            host.pop()
                        }
                    }.padding(horizontal = 18.dp, vertical = 11.dp),
                ) { Text(stringResource(label), color = fg, fontWeight = FontWeight.ExtraBold) }
            }
            ChPrimaryButton(stringResource(R.string.ch_accept), modifier = Modifier) {
                scope.launch {
                    runCatching { ApiClient.chat.accept(chatAuth(), c.id).data }.getOrNull()?.let {
                        state.conversation = it
                        host.upsert(it)
                        state.markRead()
                    }
                }
            }
        }
    }
}

// ------------------------------------------------------------------------------- media viewer

/** Full-screen photos: swipe between them, pinch to zoom, drag down to close. */
@Composable
private fun MediaViewer(message: MessageDto, start: Int, onClose: () -> Unit) {
    val context = LocalContext.current
    val images = message.attachments.filter { it.isImage() }
    val pager = androidx.compose.foundation.pager.rememberPagerState(initialPage = start.coerceIn(0, (images.size - 1).coerceAtLeast(0))) { images.size }
    val drag = remember { Animatable(0f) }
    val scope = rememberCoroutineScope()
    val fade = (1f - kotlin.math.abs(drag.value) / 900f).coerceIn(0.2f, 1f)

    Box(
        Modifier.fillMaxSize().background(Color.Black.copy(alpha = fade))
            .pointerInput(Unit) {
                detectVerticalDragGestures(
                    onDragEnd = { if (kotlin.math.abs(drag.value) > 260f) onClose() else scope.launch { drag.animateTo(0f, spring(dampingRatio = 0.6f)) } },
                ) { _, dy -> scope.launch { drag.snapTo(drag.value + dy) } }
            },
    ) {
        androidx.compose.foundation.pager.HorizontalPager(pager, Modifier.fillMaxSize().graphicsLayer { translationY = drag.value; val s = 1f - kotlin.math.abs(drag.value) / 3000f; scaleX = s; scaleY = s }) { page ->
            var scale by remember { mutableStateOf(1f) }
            var offset by remember { mutableStateOf(androidx.compose.ui.geometry.Offset.Zero) }
            AsyncImage(
                ApiClient.mediaUrl(images[page].url), null, imageLoader = chatImages(context), contentScale = ContentScale.Fit,
                modifier = Modifier.fillMaxSize()
                    .pointerInput(page) {
                        detectTransformGestures { _, pan, zoom, _ ->
                            scale = (scale * zoom).coerceIn(1f, 5f)
                            offset = if (scale > 1f) offset + pan else androidx.compose.ui.geometry.Offset.Zero
                        }
                    }
                    .graphicsLayer { scaleX = scale; scaleY = scale; translationX = offset.x; translationY = offset.y },
            )
        }
        Row(Modifier.fillMaxWidth().padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(42.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.15f)).clickable(onClick = onClose), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Close, null, tint = Color.White)
            }
            Spacer(Modifier.width(12.dp))
            Column {
                Text(message.sender?.name.orEmpty(), color = Color.White, fontWeight = FontWeight.Bold)
                Text(listTime(message.createdAt) + " " + clockTime(message.createdAt), color = Color.White.copy(alpha = 0.7f), fontSize = 12.sp)
            }
            Spacer(Modifier.weight(1f))
            if (images.size > 1) Text("${pager.currentPage + 1}/${images.size}", color = Color.White, fontWeight = FontWeight.Bold)
        }
        if (!message.body.isNullOrBlank()) {
            Text(
                message.body, color = Color.White, fontSize = 15.sp,
                modifier = Modifier.align(Alignment.BottomCenter).fillMaxWidth().background(Color.Black.copy(alpha = 0.45f)).padding(20.dp),
            )
        }
    }
}

// ------------------------------------------------------------------------------- message info

@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
private fun MessageInfoSheet(message: MessageDto, onDismiss: () -> Unit) {
    var info by remember { mutableStateOf<com.dorr.app.network.MessageInfoDto?>(null) }
    LaunchedEffect(message.id) { info = runCatching { ApiClient.chat.info(chatAuth(), message.id).data }.getOrNull() }
    androidx.compose.material3.ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 30.dp)) {
            Text(stringResource(R.string.ch_message_info), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(12.dp))
            val data = info
            if (data == null) {
                com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(120.dp))
            } else {
                InfoGroup(stringResource(R.string.ch_read_by), "read", data.readBy.map { it.participant?.name.orEmpty() to it.readAt })
                InfoGroup(stringResource(R.string.ch_delivered_to), "delivered", data.deliveredTo.map { it.participant?.name.orEmpty() to null })
                InfoGroup(stringResource(R.string.ch_waiting), "sent", data.pending.map { it.participant?.name.orEmpty() to null })
            }
        }
    }
}

@Composable
private fun InfoGroup(title: String, status: String, rows: List<Pair<String, String?>>) {
    if (rows.isEmpty()) return
    Row(Modifier.padding(top = 10.dp, bottom = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        ChTicks(status, onBubble = false)
        Spacer(Modifier.width(6.dp))
        Text(title, color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.sp)
    }
    rows.forEachIndexed { i, (name, at) ->
        Row(Modifier.fillMaxWidth().chStagger(i).padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
            ChAvatar(null, name, name, size = 36.dp)
            Spacer(Modifier.width(10.dp))
            Text(name, color = Ch.Ink, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
            at?.let { Text(clockTime(it), color = Ch.Soft, fontSize = 12.sp) }
        }
    }
}
