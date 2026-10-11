package com.dorr.app.ui.screens.chat

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.widget.Toast
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import com.dorr.app.R
import com.dorr.app.chat.CallController
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.LiveDto
import com.dorr.app.network.MessageDto
import com.dorr.app.network.apiFailure
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import io.livekit.android.LiveKit
import io.livekit.android.events.RoomEvent
import io.livekit.android.events.collect
import io.livekit.android.renderer.TextureViewRenderer
import io.livekit.android.room.Room
import io.livekit.android.room.track.VideoTrack
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

/*
 * A call broadcast live (spec 93): start it from the call (title, who may watch), the LIVE badge
 * with its viewers, share its card or link, and the viewer's page — the call's cameras and voices,
 * nothing to publish (LiveKit's own rule for a viewer's token).
 */

private val LiveRed = Color(0xFFE11D48)

@Composable
internal fun LivePulseDot() {
    val t = rememberInfiniteTransition(label = "live")
    val a by t.animateFloat(0.35f, 1f, infiniteRepeatable(tween(700), RepeatMode.Reverse), label = "dot")
    Box(Modifier.size(8.dp).graphicsLayer { alpha = a }.clip(CircleShape).background(Color.White))
}

/** In the call: go live — a title and who may watch. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun StartLiveSheet(onDismiss: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var title by remember { mutableStateOf("") }
    var audience by remember { mutableStateOf("members") }
    var busy by remember { mutableStateOf(false) }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text("🔴 " + stringResource(R.string.lv_go_live), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.lv_go_live_sub), color = Ch.Mut, fontSize = 12.5.sp)
            Spacer(Modifier.height(12.dp))
            ChField(title, { title = it.take(120) }, stringResource(R.string.lv_title_hint))
            Spacer(Modifier.height(10.dp))
            listOf("members" to R.string.lv_audience_members, "link" to R.string.lv_audience_link).forEach { (k, l) ->
                Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { audience = k }.padding(vertical = 10.dp, horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(if (audience == k) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (audience == k) Ch.Red else Ch.Soft, modifier = Modifier.size(22.dp))
                    Spacer(Modifier.width(10.dp))
                    Text(stringResource(l), color = Ch.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold)
                }
            }
            ChPrimaryButton(stringResource(R.string.lv_start), Modifier.fillMaxWidth().padding(top = 12.dp), enabled = !busy) {
                val callId = CallController.call?.id ?: return@ChPrimaryButton
                busy = true
                scope.launch {
                    runCatching { ApiClient.voice.startLive(chatAuth(), callId, JsonObject().apply { if (title.isNotBlank()) addProperty("title", title.trim()); addProperty("audience", audience) }).data }
                        .onSuccess { CallController.live = it; onDismiss() }
                        .onFailure { Toast.makeText(context, it.apiFailure().message, Toast.LENGTH_LONG).show() }
                    busy = false
                }
            }
        }
    }
}

/** On top of the call while it's live: LIVE · viewers. Tap to share or end it. */
@Composable
internal fun LiveBadge(live: LiveDto, onClick: () -> Unit) {
    Row(Modifier.statusBarsPadding().padding(top = 8.dp).clip(CircleShape).background(LiveRed).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        LivePulseDot()
        Spacer(Modifier.width(6.dp))
        Text("LIVE", color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Black)
        Spacer(Modifier.width(8.dp))
        Icon(Icons.Rounded.Visibility, null, tint = Color.White, modifier = Modifier.size(14.dp))
        Spacer(Modifier.width(3.dp))
        Text("${live.viewers}", color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
    }
}

/** Share the live's card into chats, copy its link, or end it. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun LiveShareSheet(live: LiveDto, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var chats by remember { mutableStateOf<List<ConversationDto>?>(null) }
    val picked = remember { mutableStateListOf<String>() }
    LaunchedEffect(Unit) { chats = runCatching { ApiClient.chat.conversations(chatAuth(), perPage = 40).data.orEmpty() }.getOrNull().orEmpty() }
    val shared = stringResource(R.string.lv_shared)
    val copied = stringResource(R.string.ch_copied)
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 22.dp)) {
            Text(stringResource(R.string.lv_share), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(if (live.audience == "members") R.string.lv_share_members else R.string.lv_share_link), color = Ch.Mut, fontSize = 12.5.sp)
            Spacer(Modifier.height(10.dp))
            LazyColumn(Modifier.height(300.dp)) {
                items(chats.orEmpty(), key = { it.id }) { c ->
                    val on = c.id in picked
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { if (on) picked.remove(c.id) else picked.add(c.id) }.padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        ChAvatar(c.avatar, c.title, c.peer?.key ?: c.id, size = 38.dp, isGroup = c.isGroup)
                        Spacer(Modifier.width(10.dp))
                        Text(c.title.orEmpty(), color = Ch.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                        Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) Ch.Red else Ch.Soft, modifier = Modifier.size(22.dp))
                    }
                }
            }
            Row(Modifier.fillMaxWidth().padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                ChPrimaryButton(stringResource(R.string.lv_send), Modifier.weight(1f), enabled = picked.isNotEmpty()) {
                    scope.launch {
                        runCatching { ApiClient.voice.shareLive(chatAuth(), live.token, JsonObject().apply { add("conversation_ids", JsonArray().apply { picked.forEach { add(it) } }) }) }
                            .onSuccess { Toast.makeText(context, shared, Toast.LENGTH_SHORT).show(); onDismiss() }
                            .onFailure { Toast.makeText(context, it.apiFailure().message, Toast.LENGTH_LONG).show() }
                    }
                }
                if (live.audience == "link") Text(
                    stringResource(R.string.lv_copy_link), color = Ch.Red, fontSize = 13.5.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.clip(CircleShape).background(Ch.Red.copy(alpha = 0.1f)).clickable {
                        (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("live", live.link ?: "dorr://live/${live.token}"))
                        Toast.makeText(context, copied, Toast.LENGTH_SHORT).show()
                    }.padding(horizontal = 16.dp, vertical = 14.dp),
                )
            }
            Text(
                stringResource(R.string.lv_end), color = Ch.Danger, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth().padding(top = 10.dp).clip(RoundedCornerShape(14.dp)).clickable {
                    scope.launch { runCatching { ApiClient.voice.endLive(chatAuth(), live.id).data }.getOrNull()?.let { CallController.live = null }; onDismiss() }
                }.padding(12.dp),
            )
        }
    }
}

/** The card in a chat: "🔴 Live · title · host — Watch". */
@Composable
internal fun LiveCardBubble(dto: MessageDto, footer: @Composable () -> Unit) {
    val host = LocalChat.current
    val live = dto.meta?.getAsJsonObject("live") ?: return
    val token = live.get("token")?.asString ?: return
    Column(Modifier.width(250.dp).clip(RoundedCornerShape(18.dp)).background(Brush.linearGradient(listOf(Color(0xFF881337), LiveRed))).clickable { host.push(ChRoute.LiveViewer(token)) }.padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Row(Modifier.clip(CircleShape).background(Color.White.copy(alpha = 0.2f)).padding(horizontal = 8.dp, vertical = 3.dp), verticalAlignment = Alignment.CenterVertically) {
                LivePulseDot()
                Spacer(Modifier.width(5.dp))
                Text("LIVE", color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Black)
            }
        }
        Text(live.get("title")?.takeIf { !it.isJsonNull }?.asString ?: stringResource(R.string.lv_live), color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 8.dp))
        live.get("host")?.takeIf { !it.isJsonNull }?.asString?.let { Text(it, color = Color.White.copy(alpha = 0.8f), fontSize = 12.5.sp) }
        Text(
            "▶  " + stringResource(R.string.lv_watch), color = LiveRed, fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center,
            modifier = Modifier.fillMaxWidth().padding(top = 10.dp).clip(CircleShape).background(Color.White).padding(vertical = 9.dp),
        )
        footer()
    }
}

/** Watching: the call's cameras and voices, nothing to publish. */
@Composable
fun LiveViewerPage(token: String) {
    val host = LocalChat.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var live by remember { mutableStateOf<LiveDto?>(null) }
    var room by remember { mutableStateOf<Room?>(null) }
    var videos by remember { mutableStateOf<List<VideoTrack>>(emptyList()) }
    var failed by remember { mutableStateOf<String?>(null) }
    DisposableEffect(token) {
        val job = scope.launch {
            val watch = runCatching { ApiClient.voice.watch(chatAuth(), token).data }.getOrElse { failed = it.apiFailure().message; null } ?: return@launch
            live = watch.live
            val join = watch.join ?: return@launch
            val r = LiveKit.create(context.applicationContext)
            room = r
            launch {
                r.events.collect { e ->
                    when (e) {
                        is RoomEvent.TrackSubscribed, is RoomEvent.TrackUnsubscribed, is RoomEvent.ParticipantDisconnected ->
                            videos = r.remoteParticipants.values.flatMap { p -> p.videoTrackPublications.mapNotNull { it.second as? VideoTrack } }
                        is RoomEvent.Disconnected -> live = live?.copy(status = "ended")
                        else -> Unit
                    }
                }
            }
            runCatching { r.connect(join.url, join.token) }.onFailure { failed = it.message }
            while (isActive) {
                delay(30_000)
                runCatching { ApiClient.voice.pingLive(chatAuth(), token).data }.getOrNull()?.let { live = it }
            }
        }
        onDispose {
            job.cancel()
            runCatching { room?.disconnect() }
            runCatching { room?.release() }
        }
    }
    Box(Modifier.fillMaxSize().background(Color.Black)) {
        val r = room
        if (r != null && videos.isNotEmpty()) {
            Column(Modifier.fillMaxSize()) {
                videos.take(4).forEach { track ->
                    AndroidView(
                        factory = { ctx -> TextureViewRenderer(ctx).apply { r.initVideoRenderer(this); track.addRenderer(this) } },
                        onRelease = { v -> track.removeRenderer(v); v.release() },
                        modifier = Modifier.fillMaxWidth().weight(1f),
                    )
                }
            }
        } else {
            Column(Modifier.align(Alignment.Center).padding(30.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Text(if (live?.status == "ended") "⏹" else "🎙️", fontSize = 46.sp)
                Text(
                    failed ?: stringResource(if (live?.status == "ended") R.string.lv_ended else R.string.lv_listening), color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.Bold,
                    textAlign = TextAlign.Center, modifier = Modifier.padding(top = 10.dp),
                )
            }
        }
        // The title and who watches, on top.
        Row(Modifier.statusBarsPadding().padding(12.dp).fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text("‹", color = Color.White, fontSize = 30.sp, modifier = Modifier.clip(CircleShape).clickable { host.pop() }.padding(horizontal = 10.dp))
            Column(Modifier.weight(1f)) {
                Text(live?.title ?: stringResource(R.string.lv_live), color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                live?.host?.name?.let { Text(it, color = Color.White.copy(alpha = 0.7f), fontSize = 12.sp) }
            }
            if (live?.status == "live") LiveBadge(live!!) {}
        }
    }
}
