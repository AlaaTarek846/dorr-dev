package com.dorr.app.ui.screens.moving

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Stop
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import com.dorr.app.R
import com.dorr.app.chat.VoicePlayer
import com.dorr.app.chat.VoiceRecorder
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MovingMediaDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.rides.pressable
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.delay
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody

/*
 * The customer's voice note about the load (owner, 2026-11-12), on the page where the description is written: tell the pieces
 * and what matters instead of typing. One note per request (a new one replaces it); the companies hear it with the request.
 * Recorded with the chat's recorder (AAC .m4a, the live level for the bars), played with the chat's one-at-a-time player.
 */

private const val MAX_VOICE_MS = 120_000L

internal fun voiceTime(ms: Long): String {
    val s = (ms / 1000).coerceAtLeast(0)
    return "%d:%02d".format(java.util.Locale.US, s / 60, s % 60)
}

/** The voice card of the description page: record (pulsing rings and live bars), then listen, re-record or delete. */
@Composable
internal fun MvVoiceNote(draft: MovingDraft, scope: (suspend () -> Unit) -> Unit, context: Context) {
    val recorder = remember { VoiceRecorder(context) }
    var uploading by remember { mutableStateOf(false) }
    var warning by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)
    val typeFirst = stringResource(R.string.mv_media_first)
    val micDenied = stringResource(R.string.mv_voice_mic_denied)
    DisposableEffect(Unit) { onDispose { if (recorder.recording) recorder.cancel() } }

    fun upload() {
        val uuid = draft.uuid ?: return
        val clip = recorder.finish() ?: return
        uploading = true
        scope {
            val parts = mapOf(
                "kind" to "voice".toRequestBody("text/plain".toMediaTypeOrNull()),
                "duration_ms" to clip.durationMs.toString().toRequestBody("text/plain".toMediaTypeOrNull()),
                "waveform" to clip.waveform.joinToString(",", "[", "]").toRequestBody("text/plain".toMediaTypeOrNull()),
            )
            val file = MultipartBody.Part.createFormData("file", clip.file.name, clip.file.asRequestBody("audio/mp4".toMediaTypeOrNull()))
            runCatching { ApiClient.moving.addVoice(mvAuth(), uuid, parts, file).data }
                .onSuccess { media ->
                    if (media != null) {
                        draft.voice = media
                        draft.missing = draft.missing.filterNot { it == "description_or_photo" }
                    }
                }
                .onFailure { warning = it.apiFailure().message ?: failed }
            clip.file.delete()
            uploading = false
        }
    }

    fun start() {
        warning = null
        if (draft.uuid == null) { warning = typeFirst; return }
        VoicePlayer.stop()
        if (!recorder.start()) warning = failed
    }

    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted -> if (granted) start() else warning = micDenied }

    // A note is at most two minutes: it stops and is kept at the end.
    LaunchedEffect(recorder.recording) {
        while (recorder.recording) {
            if (recorder.elapsedMs >= MAX_VOICE_MS) { upload(); break }
            delay(250)
        }
    }

    fun remove(media: MovingMediaDto) {
        val uuid = draft.uuid ?: return
        if (VoicePlayer.playingId == "mv-voice-${media.id}") VoicePlayer.stop()
        draft.voice = null
        scope { runCatching { ApiClient.moving.removeMedia(mvAuth(), uuid, media.id) } }
    }

    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        val state = when {
            recorder.recording -> "recording"
            uploading -> "uploading"
            draft.voice != null -> "done"
            else -> "idle"
        }
        AnimatedContent(targetState = state, transitionSpec = { (fadeIn(tween(220)) + scaleIn(initialScale = 0.96f)) togetherWith fadeOut(tween(150)) }, label = "mv-voice") { now ->
            when (now) {
                "recording" -> VoiceRecording(recorder, onStop = ::upload, onCancel = { recorder.cancel() })
                "uploading" -> VoiceUploading()
                "done" -> draft.voice?.let { media ->
                    MvVoicePlayer(media, onRedo = {
                        if (ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) start()
                        else permission.launch(Manifest.permission.RECORD_AUDIO)
                    }, onDelete = { remove(media) })
                } ?: VoiceIdle {}
                else -> VoiceIdle {
                    if (ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) start()
                    else permission.launch(Manifest.permission.RECORD_AUDIO)
                }
            }
        }
        warning?.let { MvNote(it, AppColors.warning) }
    }
}

/** Before recording: the mic on a disc that breathes softly, and what to say. */
@Composable
private fun VoiceIdle(onRecord: () -> Unit) {
    val accent = settingsAccent()
    val breath by rememberInfiniteTransition(label = "mv-voice-idle").animateFloat(
        1f, 1.12f, infiniteRepeatable(tween(1300, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "mv-voice-breath",
    )
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp))
            .background(Brush.horizontalGradient(listOf(accent.copy(alpha = 0.10f), accent.copy(alpha = 0.03f))))
            .pressable(onClick = onRecord).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(contentAlignment = Alignment.Center) {
            Box(Modifier.size(52.dp).scale(breath).clip(CircleShape).background(accent.copy(alpha = 0.18f)))
            Box(Modifier.size(44.dp).clip(CircleShape).background(accent), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Mic, null, tint = Color.White, modifier = Modifier.size(24.dp))
            }
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.mv_voice_title), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
            Text(stringResource(R.string.mv_voice_hint), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
        }
    }
}

/** While recording: rings pulsing out of the stop button, the bars following the voice, the time and the bin. */
@Composable
private fun VoiceRecording(recorder: VoiceRecorder, onStop: () -> Unit, onCancel: () -> Unit) {
    val red = AppColors.danger
    val pulse = rememberInfiniteTransition(label = "mv-voice-rec")
    val ring by pulse.animateFloat(0f, 1f, infiniteRepeatable(tween(1400, easing = LinearEasing)), label = "mv-voice-ring")
    val dot by pulse.animateFloat(1f, 0.25f, infiniteRepeatable(tween(600), RepeatMode.Reverse), label = "mv-voice-dot")
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(red.copy(alpha = 0.07f)).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(9.dp).clip(CircleShape).background(red.copy(alpha = dot)))
            Spacer(Modifier.width(6.dp))
            Text(stringResource(R.string.mv_voice_recording), color = red, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.weight(1f))
            Text(voiceTime(recorder.elapsedMs) + " / " + voiceTime(MAX_VOICE_MS), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 13.sp)
        }
        LiveBars(recorder.levels, red)
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(42.dp).clip(CircleShape).background(settingsMut().copy(alpha = 0.12f)).pressable(onClick = onCancel), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Delete, stringResource(R.string.mv_voice_cancel), tint = settingsMut(), modifier = Modifier.size(20.dp))
            }
            Spacer(Modifier.weight(1f))
            Box(Modifier.size(78.dp), contentAlignment = Alignment.Center) {
                // Two rings leaving the button one after the other.
                listOf(ring, (ring + 0.5f) % 1f).forEach { t ->
                    Box(Modifier.size(56.dp).scale(1f + t * 0.45f).clip(CircleShape).background(red.copy(alpha = 0.28f * (1f - t))))
                }
                Box(Modifier.size(56.dp).clip(CircleShape).background(red).pressable(onClick = onStop), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Stop, stringResource(R.string.mv_voice_stop), tint = Color.White, modifier = Modifier.size(28.dp))
                }
            }
            Spacer(Modifier.weight(1f))
            Spacer(Modifier.size(42.dp))
        }
        Text(stringResource(R.string.mv_voice_stop_hint), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.fillMaxWidth(), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
    }
}

/** The live level, newest at the end, each bar springing to its height. */
@Composable
private fun LiveBars(levels: List<Int>, color: Color) {
    val shown = (List((40 - levels.size).coerceAtLeast(0)) { 4 } + levels).takeLast(40)
    Row(Modifier.fillMaxWidth().height(38.dp), horizontalArrangement = Arrangement.spacedBy(2.dp), verticalAlignment = Alignment.CenterVertically) {
        shown.forEach { level ->
            val h by animateFloatAsState(level / 100f, spring(dampingRatio = 0.6f, stiffness = 400f), label = "mv-voice-bar")
            Box(Modifier.weight(1f).height((4 + 34 * h).dp).clip(RoundedCornerShape(50)).background(color.copy(alpha = 0.35f + 0.65f * h)))
        }
    }
}

@Composable
private fun VoiceUploading() {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsAccent().copy(alpha = 0.07f)).padding(16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
        Spacer(Modifier.width(10.dp))
        Text(stringResource(R.string.mv_voice_saving), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
    }
}

/**
 * A saved voice note: play / pause, its bars filling as it plays, the time; [onRedo] and [onDelete] when it can still be
 * changed (the draft), nothing more where it is only heard.
 */
@Composable
internal fun MvVoicePlayer(media: MovingMediaDto, onRedo: (() -> Unit)? = null, onDelete: (() -> Unit)? = null) {
    val accent = settingsAccent()
    val id = "mv-voice-${media.id}"
    val playing = VoicePlayer.playingId == id && !VoicePlayer.paused
    val progress = if (VoicePlayer.playingId == id) VoicePlayer.progress else 0f
    val duration = media.durationMs ?: 0L
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(accent.copy(alpha = 0.08f)).padding(horizontal = 10.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            Modifier.size(44.dp).clip(CircleShape).background(accent).pressable {
                ApiClient.mediaUrl(media.url)?.let { VoicePlayer.toggle(id, it) }
            },
            contentAlignment = Alignment.Center,
        ) { Icon(if (playing) Icons.Rounded.Pause else Icons.Rounded.PlayArrow, null, tint = Color.White, modifier = Modifier.size(26.dp)) }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Waveform(media.waveform.ifEmpty { List(40) { i -> 20 + (i * 37 % 60) } }, progress, accent)
            Spacer(Modifier.height(2.dp))
            Text(
                if (VoicePlayer.playingId == id) voiceTime(VoicePlayer.positionMs) + " / " + voiceTime(duration) else voiceTime(duration),
                color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold,
            )
        }
        if (onRedo != null) {
            Spacer(Modifier.width(6.dp))
            Box(Modifier.size(36.dp).clip(CircleShape).pressable(onClick = onRedo), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Refresh, stringResource(R.string.mv_voice_redo), tint = settingsMut(), modifier = Modifier.size(20.dp))
            }
        }
        if (onDelete != null) {
            Box(Modifier.size(36.dp).clip(CircleShape).pressable(onClick = onDelete), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Delete, stringResource(R.string.mv_voice_delete), tint = AppColors.danger, modifier = Modifier.size(20.dp))
            }
        }
    }
}

/** The note's bars, the played part in the app colour (from the reading side in Arabic too). */
@Composable
private fun Waveform(bars: List<Int>, progress: Float, color: Color) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val played by animateFloatAsState(progress, tween(120), label = "mv-voice-played")
    Canvas(Modifier.fillMaxWidth().height(30.dp)) {
        val n = bars.size.coerceAtLeast(1)
        val gap = 2.dp.toPx()
        val w = ((size.width - gap * (n - 1)) / n).coerceAtLeast(1f)
        bars.forEachIndexed { i, v ->
            val h = (size.height * (v.coerceIn(6, 100) / 100f)).coerceAtLeast(3f)
            val x = if (rtl) size.width - (i + 1) * w - i * gap else i * (w + gap)
            val on = (i + 0.5f) / n <= played
            drawRoundRect(
                color = if (on) color else color.copy(alpha = 0.28f),
                topLeft = Offset(x, (size.height - h) / 2), size = Size(w, h), cornerRadius = CornerRadius(w / 2, w / 2),
            )
        }
    }
}
