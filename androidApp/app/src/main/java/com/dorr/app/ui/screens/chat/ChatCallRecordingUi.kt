package com.dorr.app.ui.screens.chat

import android.widget.Toast
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.FiberManualRecord
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.chat.CallController
import com.dorr.app.chat.CallPhase
import com.dorr.app.chat.VoicePlayer
import com.dorr.app.network.ApiClient
import com.dorr.app.network.CallRecordingDto
import com.dorr.app.network.CallRecordingStateDto
import com.dorr.app.ui.locale.LocaleAwareBottomSheet
import kotlinx.coroutines.delay

/*
 * Call recording (spec 92, safety rule 1) on the phone: the REC badge everyone in the call sees for
 * as long as it records, the question everyone in the call must answer before anything is recorded,
 * "join only if you agree" for whoever comes in later, and the recordings I agreed to, played from
 * the chat or the calls log.
 */

private val RecRed = Color(0xFFEF4444)
private val Amber = Color(0xFFF59E0B)

/**
 * Over the call screen: REC · the request and how many agreed · or a "Record" chip in the top
 * corner (never hidden with the controls), and the questions in front of everything when needed.
 */
@Composable
internal fun BoxScope.CallRecordingLayer(phase: CallPhase) {
    val recording = CallController.recording
    var confirm by remember { mutableStateOf<String?>(null) }
    RecordingNotes()

    // Shown while it rings too (it tells the one answering), but only someone in the call acts on it.
    val inCall = phase == CallPhase.Active
    Box(Modifier.align(Alignment.TopStart).padding(start = 12.dp, top = 8.dp)) {
        when {
            recording?.status == "recording" -> RecBadge(inCall) { confirm = "stop" }
            recording?.status == "pending" -> AskingPill(recording, inCall) { CallController.stopRecording() }
            inCall && CallController.recordingAvailable -> RecordChip { confirm = "ask" }
        }
    }

    when (confirm) {
        "ask" -> PromptCard(
            title = stringResource(R.string.cr_ask_title),
            body = stringResource(R.string.cr_ask_body),
            no = stringResource(R.string.cr_cancel),
            yes = stringResource(R.string.cr_ask_yes),
            onNo = { confirm = null },
            onYes = { confirm = null; CallController.requestRecording() },
        )
        "stop" -> PromptCard(
            title = stringResource(R.string.cr_stop_title),
            body = stringResource(R.string.cr_stop_body),
            no = stringResource(R.string.cr_keep),
            yes = stringResource(R.string.cr_stop_yes),
            onNo = { confirm = null },
            onYes = { confirm = null; CallController.stopRecording() },
        )
    }
    // "Stop recording?" is moot once it stopped.
    LaunchedEffect(recording?.status) { if (confirm == "stop" && recording?.status != "recording") confirm = null }

    // Someone asked: nothing is recorded until I (and everyone else) answer.
    if (recording != null && CallController.mustAnswerRecording()) {
        val name = CallController.nameOf(recording.requestedBy)
        PromptCard(
            title = stringResource(R.string.cr_consent_title),
            body = if (name != null) stringResource(R.string.cr_consent_body, name) else stringResource(R.string.cr_consent_body_someone),
            extra = consentCountdown(recording.consentUntil),
            no = stringResource(R.string.cr_consent_no),
            yes = stringResource(R.string.cr_consent_yes),
            onNo = { CallController.answerRecording(false) },
            onYes = { CallController.answerRecording(true) },
        )
    }

    // Joining a call that records takes my yes first.
    CallController.joinConsent?.let { asking ->
        PromptCard(
            title = stringResource(if (asking.status == "pending") R.string.cr_join_title_asking else R.string.cr_join_title),
            body = stringResource(R.string.cr_join_body),
            no = stringResource(R.string.cr_join_no),
            yes = stringResource(R.string.cr_join_yes),
            onNo = { CallController.answerJoinConsent(false) },
            onYes = { CallController.answerJoinConsent(true) },
        )
    }
}

/** ⏺ REC 01:23 — tapping it (in the call) asks whether to stop. */
@Composable
private fun RecBadge(enabled: Boolean, onClick: () -> Unit) {
    val since = CallController.recordingSince
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(since) {
        while (true) {
            now = System.currentTimeMillis()
            delay(1000)
        }
    }
    Row(
        Modifier.clip(CircleShape).background(Color.Black.copy(alpha = 0.55f)).border(1.dp, RecRed.copy(alpha = 0.85f), CircleShape)
            .clickable(enabled = enabled, onClick = onClick).padding(horizontal = 12.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        PulseDot(RecRed)
        Spacer(Modifier.width(6.dp))
        Text(stringResource(R.string.cr_rec), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Black)
        if (since != null) {
            Spacer(Modifier.width(6.dp))
            Text(durationText((now - since).coerceAtLeast(0)), color = Color.White.copy(alpha = 0.85f), fontSize = 12.sp, fontWeight = FontWeight.Bold)
        }
    }
}

/** Waiting for everyone's yes: "1 of 2 agreed", and × (in the call) takes the request back. */
@Composable
private fun AskingPill(recording: CallRecordingStateDto, enabled: Boolean, onCancel: () -> Unit) {
    val total = recording.agreed.size + recording.waitingFor.size
    Row(
        Modifier.clip(CircleShape).background(Color.Black.copy(alpha = 0.55f)).border(1.dp, Amber.copy(alpha = 0.8f), CircleShape)
            .clickable(enabled = enabled, onClick = onCancel).padding(start = 12.dp, end = if (enabled) 8.dp else 12.dp, top = 6.dp, bottom = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        PulseDot(Amber)
        Spacer(Modifier.width(6.dp))
        Text(stringResource(R.string.cr_asking, recording.agreed.size, total), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        if (enabled) {
            Spacer(Modifier.width(6.dp))
            Icon(Icons.Rounded.Close, null, tint = Color.White.copy(alpha = 0.8f), modifier = Modifier.size(15.dp))
        }
    }
}

@Composable
private fun RecordChip(onClick: () -> Unit) {
    Row(
        Modifier.clip(CircleShape).background(Color.White.copy(alpha = 0.14f)).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 7.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.FiberManualRecord, null, tint = RecRed, modifier = Modifier.size(14.dp))
        Spacer(Modifier.width(6.dp))
        Text(stringResource(R.string.cr_record), color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun PulseDot(color: Color) {
    val t = rememberInfiniteTransition(label = "recDot")
    val alpha by t.animateFloat(1f, 0.25f, infiniteRepeatable(tween(700), RepeatMode.Reverse), label = "recDotAlpha")
    Box(Modifier.size(9.dp).graphicsLayer { this.alpha = alpha }.clip(CircleShape).background(color))
}

/** "12 s left" until the request is dropped. */
@Composable
private fun consentCountdown(until: String?): String? {
    val end = remember(until) { until?.let { runCatching { java.time.OffsetDateTime.parse(it).toInstant().toEpochMilli() }.getOrNull() } }
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(end) {
        while (end != null && now < end) {
            delay(1000)
            now = System.currentTimeMillis()
        }
    }
    val left = end?.let { ((it - now) / 1000).coerceAtLeast(0).toInt() }
    return left?.let { stringResource(R.string.cr_seconds_left, it) }
}

/** A question in front of the call: a scrim, a card, two answers. */
@Composable
private fun PromptCard(title: String, body: String, no: String, yes: String, onNo: () -> Unit, onYes: () -> Unit, extra: String? = null) {
    Box(
        Modifier.fillMaxSize().background(Color.Black.copy(alpha = 0.62f))
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { },
        contentAlignment = Alignment.Center,
    ) {
        Column(
            Modifier.padding(24.dp).widthIn(max = 420.dp).fillMaxWidth().clip(RoundedCornerShape(26.dp)).background(Color(0xFF1C1C22)).padding(22.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Box(Modifier.size(56.dp).clip(CircleShape).background(RecRed.copy(alpha = 0.16f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.FiberManualRecord, null, tint = RecRed, modifier = Modifier.size(26.dp))
            }
            Spacer(Modifier.height(14.dp))
            Text(title, color = Color.White, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
            Spacer(Modifier.height(8.dp))
            Text(body, color = Color.White.copy(alpha = 0.78f), fontSize = 14.sp, textAlign = TextAlign.Center, lineHeight = 20.sp)
            extra?.let {
                Spacer(Modifier.height(8.dp))
                Text(it, color = Amber, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold)
            }
            Spacer(Modifier.height(20.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Box(Modifier.weight(1f).height(48.dp).clip(RoundedCornerShape(16.dp)).background(Color.White.copy(alpha = 0.1f)).clickable(onClick = onNo), contentAlignment = Alignment.Center) {
                    Text(no, color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
                }
                Box(Modifier.weight(1f).height(48.dp).clip(RoundedCornerShape(16.dp)).background(RecRed).clickable(onClick = onYes), contentAlignment = Alignment.Center) {
                    Text(yes, color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
                }
            }
        }
    }
}

/** What became of the recording, said once. */
@Composable
private fun RecordingNotes() {
    val context = LocalContext.current
    val note = CallController.recordingNote
    LaunchedEffect(note) {
        val text = when (note) {
            "recording" -> R.string.cr_note_started
            "declined" -> R.string.cr_note_declined
            "expired" -> R.string.cr_note_expired
            "cancelled" -> R.string.cr_note_cancelled
            "failed" -> R.string.cr_note_failed
            "processing", "ready" -> R.string.cr_note_saved
            else -> null
        }
        if (text != null) Toast.makeText(context, context.getString(text), Toast.LENGTH_LONG).show()
        if (note != null) CallController.recordingNote = null
    }
    val error = CallController.recordingError
    LaunchedEffect(error) {
        if (error != null) {
            Toast.makeText(context, error, Toast.LENGTH_SHORT).show()
            CallController.recordingError = null
        }
    }
}

// =============================================================================== playing them

/** Under a call line in the chat: it was recorded — tap for the recordings I agreed to. */
@Composable
internal fun RecordedLink(callId: String?, mine: Boolean) {
    callId ?: return
    Row(
        Modifier.padding(top = 4.dp).clip(RoundedCornerShape(10.dp)).clickable { ChatViewers.recordings = callId }.padding(vertical = 2.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.FiberManualRecord, null, tint = if (mine) Ch.OutText else RecRed, modifier = Modifier.size(10.dp))
        Spacer(Modifier.width(5.dp))
        Text(stringResource(R.string.cr_recorded), color = if (mine) Ch.OutText else Ch.Red, fontSize = 12.sp, fontWeight = FontWeight.Bold)
    }
}

/**
 * The recordings of one call I agreed to: a video opens in the in-app player, a voice call plays
 * right here. Links from the server work for a few minutes, so they're fetched when this opens.
 */
@Composable
internal fun CallRecordingsSheet(callId: String, onDismiss: () -> Unit) {
    var list by remember(callId) { mutableStateOf<List<CallRecordingDto>?>(null) }
    var failed by remember(callId) { mutableStateOf(false) }
    LaunchedEffect(callId) {
        runCatching { ApiClient.chat.callRecordings(chatAuth(), callId).data.orEmpty() }
            .onSuccess { list = it }
            .onFailure { failed = true; list = emptyList() }
    }
    DisposableEffect(Unit) { onDispose { if (VoicePlayer.playingId?.startsWith("rec:") == true) VoicePlayer.stop() } }

    LocaleAwareBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.cr_list_title), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.cr_list_sub), color = Ch.Mut, fontSize = 12.5.sp)
            Spacer(Modifier.height(14.dp))
            val items = list
            when {
                items == null -> Box(Modifier.fillMaxWidth().height(90.dp), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp)
                }
                items.isEmpty() -> Text(stringResource(if (failed) R.string.cr_list_failed else R.string.cr_list_empty), color = Ch.Mut, fontSize = 14.sp, modifier = Modifier.padding(vertical = 18.dp))
                else -> Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items.forEachIndexed { i, r -> RecordingRow(i + 1, r, onVideo = { url -> ChatViewers.video = url; onDismiss() }) }
                }
            }
        }
    }
}

@Composable
private fun RecordingRow(number: Int, recording: CallRecordingDto, onVideo: (String) -> Unit) {
    val playerId = "rec:${recording.id}"
    val playing = VoicePlayer.playingId == playerId
    val ready = recording.status == "ready" && recording.url != null
    val locale = LocalConfiguration.current.locales[0]
    val until = remember(recording.expiresAt, locale) {
        recording.expiresAt?.let { iso ->
            runCatching { java.time.OffsetDateTime.parse(iso).format(java.time.format.DateTimeFormatter.ofPattern("d MMM", locale)) }.getOrNull()
        }
    }
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Ch.Bg).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(42.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
            Icon(if (recording.type == "video") Icons.Rounded.Videocam else Icons.Rounded.Mic, null, tint = Ch.Red, modifier = Modifier.size(21.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.cr_item, number), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
            val line = when {
                !ready -> stringResource(R.string.cr_saving)
                until != null -> (recording.durationSeconds?.let { durationText(it * 1000L) + "  ·  " } ?: "") + stringResource(R.string.cr_until, until)
                else -> recording.durationSeconds?.let { durationText(it * 1000L) }.orEmpty()
            }
            Text(line, color = Ch.Mut, fontSize = 12.5.sp)
            if (playing) {
                Spacer(Modifier.height(6.dp))
                LinearProgressIndicator(progress = { VoicePlayer.progress }, modifier = Modifier.fillMaxWidth().height(3.dp).clip(CircleShape), color = Ch.Red, trackColor = Ch.Red.copy(alpha = 0.15f))
            }
        }
        if (ready) {
            Spacer(Modifier.width(10.dp))
            Box(
                Modifier.size(42.dp).clip(CircleShape).background(Ch.Red).clickable {
                    val url = recording.url ?: return@clickable
                    if (recording.type == "video") onVideo(url) else VoicePlayer.toggle(playerId, url)
                },
                contentAlignment = Alignment.Center,
            ) {
                Icon(if (VoicePlayer.isPlaying(playerId)) Icons.Rounded.Pause else Icons.Rounded.PlayArrow, null, tint = Color.White, modifier = Modifier.size(22.dp))
            }
        }
    }
}
