package com.dorr.app.ui.screens.chat

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CallEnd
import androidx.compose.material.icons.rounded.GraphicEq
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.MicOff
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
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
import androidx.core.content.ContextCompat
import com.dorr.app.R
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.chat.VoiceRoomController
import androidx.compose.material.icons.automirrored.rounded.VolumeUp
import androidx.compose.material.icons.rounded.PhoneInTalk
import com.dorr.app.network.ApiClient
import com.dorr.app.network.VoiceMemberDto
import kotlinx.coroutines.launch

/*
 * Standing voice rooms (spec 91): the bar in a group ("3 in the voice room — join"), the room
 * itself (who's in, who's talking — glowing —, hands raised, hosts' controls) and the mini bar
 * that keeps the room with me while I move around the chat.
 */

private val VoiceTeal = Color(0xFF0D9488)
private val VoiceDeep = Color(0xFF0F172A)

/** Join after the microphone is allowed (a listener needs it too, to speak once allowed). */
@Composable
private fun rememberVoiceJoiner(onReady: () -> Unit): () -> Unit {
    val context = LocalContext.current
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { ok -> if (ok) onReady() }
    return {
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) onReady()
        else launcher.launch(Manifest.permission.RECORD_AUDIO)
    }
}

/** In a group: who's in its voice room, and a way in. */
@Composable
internal fun VoiceRoomBar(conversationId: String, title: String?) {
    val host = LocalChat.current
    var count by remember { mutableStateOf(0) }
    LaunchedEffect(conversationId) {
        count = runCatching { ApiClient.voice.room(chatAuth(), conversationId).data?.count }.getOrNull() ?: 0
        ChatRealtime.events.collect { e ->
            if (e.name == "chat.voice.updated" && e.data.get("conversation_id")?.asString == conversationId) count = e.data.get("count")?.asInt ?: count
        }
    }
    val inIt = VoiceRoomController.isIn(conversationId)
    AnimatedVisibility(count > 0 && !inIt, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 6.dp).clip(RoundedCornerShape(16.dp))
                .background(Brush.horizontalGradient(listOf(VoiceTeal, Color(0xFF0E7490)))).clickable { host.push(ChRoute.VoiceRoom(conversationId, title)) }.padding(horizontal = 14.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            LiveBars(Color.White)
            Spacer(Modifier.width(10.dp))
            Text(stringResource(R.string.vr_bar, count), color = Color.White, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            Text(stringResource(R.string.vr_join), color = VoiceTeal, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.clip(CircleShape).background(Color.White).padding(horizontal = 12.dp, vertical = 5.dp))
        }
    }
}

/** On top of the chat while I'm in a room and not on its page. */
@Composable
internal fun VoiceMiniBar() {
    val host = LocalChat.current
    val room = VoiceRoomController.room ?: return
    if ((host.current as? ChRoute.VoiceRoom)?.conversationId == room.conversationId) return
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 4.dp).clip(RoundedCornerShape(18.dp)).background(VoiceDeep)
            .clickable { host.push(ChRoute.VoiceRoom(room.conversationId, VoiceRoomController.chatTitle)) }.padding(horizontal = 12.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        LiveBars(Color(0xFF5EEAD4))
        Spacer(Modifier.width(8.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.vr_title), color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold)
            Text(listOfNotNull(VoiceRoomController.chatTitle, stringResource(R.string.vr_people, room.count)).joinToString(" · "), color = Color.White.copy(alpha = 0.7f), fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        if (room.me.role != "listener") RoundButton(if (VoiceRoomController.micOn) Icons.Rounded.Mic else Icons.Rounded.MicOff, if (VoiceRoomController.micOn) VoiceTeal else Color.White.copy(alpha = 0.16f), 34) { VoiceRoomController.toggleMic() }
        Spacer(Modifier.width(6.dp))
        RoundButton(Icons.Rounded.CallEnd, Color(0xFFDC2626), 34) { VoiceRoomController.leave() }
    }
}

@Composable
private fun LiveBars(color: Color) {
    val t = rememberInfiniteTransition(label = "bars")
    Row(horizontalArrangement = Arrangement.spacedBy(2.dp), verticalAlignment = Alignment.CenterVertically, modifier = Modifier.height(16.dp)) {
        listOf(0, 180, 360).forEach { delayMs ->
            val h by t.animateFloat(0.3f, 1f, infiniteRepeatable(tween(520, delayMillis = delayMs), RepeatMode.Reverse), label = "bar")
            Box(Modifier.width(3.dp).height((16 * h).dp).clip(CircleShape).background(color))
        }
    }
}

@Composable
private fun RoundButton(icon: androidx.compose.ui.graphics.vector.ImageVector, bg: Color, size: Int, onClick: () -> Unit) {
    Box(Modifier.size(size.dp).clip(CircleShape).background(bg).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = Color.White, modifier = Modifier.size((size * 0.5f).dp))
    }
}

/** The room: who's in (talking ones glow), hands, my microphone, the hosts' controls. */
@Composable
fun VoiceRoomPage(conversationId: String, title: String?) {
    val host = LocalChat.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var preview by remember { mutableStateOf<com.dorr.app.network.VoiceRoomDto?>(null) }
    var menuFor by remember { mutableStateOf<VoiceMemberDto?>(null) }
    val join = rememberVoiceJoiner { scope.launch { if (!VoiceRoomController.join(context, conversationId, title)) VoiceRoomController.error?.let { host.showToast(it) } } }
    LaunchedEffect(conversationId) { preview = runCatching { ApiClient.voice.room(chatAuth(), conversationId).data }.getOrNull() }
    val inRoom = VoiceRoomController.isIn(conversationId)
    val room = if (inRoom) VoiceRoomController.room else preview

    Box(Modifier.fillMaxSize().background(Brush.verticalGradient(listOf(VoiceDeep, Color(0xFF134E4A))))) {
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp, vertical = 14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("‹", color = Color.White, fontSize = 30.sp, modifier = Modifier.clip(CircleShape).clickable { host.pop() }.padding(horizontal = 10.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.vr_title), color = Color.White, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold)
                    Text(listOfNotNull(title, room?.let { stringResource(R.string.vr_people, it.count) }).joinToString(" · "), color = Color.White.copy(alpha = 0.7f), fontSize = 12.5.sp)
                }
                if (room?.me?.canManage == true) {
                    val approved = room.speakPolicy == "approved"
                    Text(
                        stringResource(if (approved) R.string.vr_policy_approved else R.string.vr_policy_everyone), color = Color.White, fontSize = 11.5.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(CircleShape).background(Color.White.copy(alpha = 0.14f)).clickable(enabled = inRoom) { VoiceRoomController.setPolicy(if (approved) "everyone" else "approved") }.padding(horizontal = 10.dp, vertical = 6.dp),
                    )
                }
            }
            Spacer(Modifier.height(18.dp))
            val members = room?.members.orEmpty()
            if (members.isEmpty()) {
                Column(Modifier.fillMaxWidth().padding(vertical = 40.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    Icon(Icons.Rounded.GraphicEq, null, tint = Color(0xFF5EEAD4), modifier = Modifier.size(56.dp))
                    Text(stringResource(R.string.vr_empty), color = Color.White.copy(alpha = 0.85f), fontSize = 14.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 10.dp))
                }
            }
            listOf(
                R.string.vr_speakers to members.filter { it.role != "listener" },
                R.string.vr_listeners to members.filter { it.role == "listener" },
            ).forEach { (label, list) ->
                if (list.isEmpty()) return@forEach
                Text(stringResource(label), color = Color.White.copy(alpha = 0.6f), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 10.dp, bottom = 10.dp))
                list.chunked(4).forEach { row ->
                    Row(Modifier.fillMaxWidth().padding(bottom = 14.dp)) {
                        row.forEach { m -> MemberTile(m, m.key in VoiceRoomController.speaking, Modifier.weight(1f)) { if (room?.me?.canManage == true && inRoom && m.key != myKey()) menuFor = m } }
                        repeat(4 - row.size) { Spacer(Modifier.weight(1f)) }
                    }
                }
            }
            Spacer(Modifier.height(120.dp))
        }
        // The controls.
        Row(
            Modifier.align(Alignment.BottomCenter).fillMaxWidth().padding(16.dp).clip(RoundedCornerShape(28.dp)).background(Color.White.copy(alpha = 0.08f)).padding(14.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp, Alignment.CenterHorizontally), verticalAlignment = Alignment.CenterVertically,
        ) {
            if (!inRoom) {
                Text(
                    if (VoiceRoomController.connecting) stringResource(R.string.vr_connecting) else stringResource(R.string.vr_join_room), color = VoiceDeep, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center,
                    modifier = Modifier.weight(1f).clip(CircleShape).background(Color(0xFF5EEAD4)).clickable(enabled = !VoiceRoomController.connecting) { join() }.padding(vertical = 14.dp),
                )
            } else {
                val me = room?.me
                if (me?.role == "listener") {
                    val raised = room.members.firstOrNull { it.key == myKey() }?.handRaised == true
                    Text(
                        (if (raised) "✋ " + stringResource(R.string.vr_hand_down) else "✋ " + stringResource(R.string.vr_raise_hand)), color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(CircleShape).background(if (raised) Color(0xFFF59E0B) else Color.White.copy(alpha = 0.16f)).clickable { VoiceRoomController.raiseHand(!raised) }.padding(horizontal = 18.dp, vertical = 12.dp),
                    )
                } else {
                    RoundButton(if (VoiceRoomController.micOn) Icons.Rounded.Mic else Icons.Rounded.MicOff, if (VoiceRoomController.micOn) VoiceTeal else Color.White.copy(alpha = 0.16f), 58) { VoiceRoomController.toggleMic() }
                }
                // The loudspeaker, or the earpiece / headset.
                RoundButton(
                    if (VoiceRoomController.speakerOn) Icons.AutoMirrored.Rounded.VolumeUp else Icons.Rounded.PhoneInTalk,
                    if (VoiceRoomController.speakerOn) VoiceTeal else Color.White.copy(alpha = 0.16f), 58,
                ) { VoiceRoomController.toggleSpeaker() }
                RoundButton(Icons.Rounded.CallEnd, Color(0xFFDC2626), 58) { VoiceRoomController.leave(); host.pop() }
            }
        }
    }

    menuFor?.let { m ->
        ChoiceSheet(
            m.name.orEmpty(),
            listOfNotNull(
                if (m.role == "listener") stringResource(R.string.vr_let_speak) to { VoiceRoomController.setRole(m.participantId, "speaker") } else null,
                if (m.role == "speaker") stringResource(R.string.vr_make_listener) to { VoiceRoomController.setRole(m.participantId, "listener") } else null,
                stringResource(R.string.vr_remove) to { VoiceRoomController.remove(m.participantId) },
            ),
            onDismiss = { menuFor = null },
        )
    }
}

@Composable
private fun MemberTile(m: VoiceMemberDto, talking: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val pulse = rememberInfiniteTransition(label = "talk")
    val ring by pulse.animateFloat(1f, 1.12f, infiniteRepeatable(tween(600), RepeatMode.Reverse), label = "ring")
    Column(modifier.clickable(onClick = onClick), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(contentAlignment = Alignment.Center) {
            if (talking) Box(Modifier.size(70.dp).graphicsLayer { scaleX = ring; scaleY = ring }.clip(CircleShape).border(3.dp, Color(0xFF5EEAD4), CircleShape))
            ChAvatar(m.avatar, m.name, m.key, size = 60.dp)
            if (m.handRaised) Text("✋", fontSize = 18.sp, modifier = Modifier.align(Alignment.TopEnd))
            if (m.role == "host") Text("👑", fontSize = 14.sp, modifier = Modifier.align(Alignment.TopStart))
        }
        Text(
            if (m.key == myKey()) stringResource(R.string.lf_you) else m.name.orEmpty(), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold,
            maxLines = 1, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 6.dp),
        )
    }
}
