package com.dorr.provider.ui.screens

import android.content.Context
import android.media.AudioAttributes
import android.media.MediaPlayer
import android.net.Uri
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.material.icons.rounded.GraphicEq
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.MovingVoiceDto
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import kotlinx.coroutines.delay

private fun voiceTime(ms: Long): String {
    val s = (ms / 1000).coerceAtLeast(0)
    return "%d:%02d".format(java.util.Locale.US, s / 60, s % 60)
}

/**
 * The customer's voice note about the load (owner, 2026-11-12), heard on the request's page: play / pause, its bars filling as it
 * plays and the time. The player lives with the page and is released when it closes; the bar follows the player's own clock.
 */
@Composable
internal fun MvCustomerVoice(voice: MovingVoiceDto) {
    val context = LocalContext.current
    val accent = settingsAccent()
    var player by remember { mutableStateOf<MediaPlayer?>(null) }
    var preparing by remember { mutableStateOf(false) }
    var playing by remember { mutableStateOf(false) }
    var position by remember { mutableLongStateOf(0L) }
    var progress by remember { mutableFloatStateOf(0f) }
    val duration = voice.durationMs ?: 0L
    DisposableEffect(voice.url) { onDispose { runCatching { player?.release() }; player = null } }
    LaunchedEffect(playing) {
        while (playing) {
            player?.let { p ->
                runCatching {
                    position = p.currentPosition.toLong()
                    progress = if (p.duration > 0) p.currentPosition / p.duration.toFloat() else 0f
                }
            }
            delay(100)
        }
    }

    fun toggle(context: Context) {
        val current = player
        if (current != null) {
            if (current.isPlaying) { current.pause(); playing = false } else { current.start(); playing = true }
            return
        }
        val url = ApiClient.mediaUrl(voice.url) ?: return
        preparing = true
        val mp = MediaPlayer()
        player = mp
        mp.setAudioAttributes(AudioAttributes.Builder().setContentType(AudioAttributes.CONTENT_TYPE_SPEECH).setUsage(AudioAttributes.USAGE_MEDIA).build())
        runCatching {
            // The tunnel's header, or it answers with its warning page instead of the audio.
            mp.setDataSource(context.applicationContext, Uri.parse(url), mapOf("ngrok-skip-browser-warning" to "1"))
            mp.setOnPreparedListener { preparing = false; it.start(); playing = true }
            mp.setOnCompletionListener { playing = false; progress = 0f; position = 0L; runCatching { it.release() }; player = null }
            mp.setOnErrorListener { p, _, _ -> preparing = false; playing = false; runCatching { p.release() }; player = null; true }
            mp.prepareAsync()
        }.onFailure { preparing = false; runCatching { mp.release() }; player = null }
    }

    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(accent.copy(alpha = 0.08f)).padding(10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(44.dp).clip(CircleShape).background(accent).clickable { toggle(context) }, contentAlignment = Alignment.Center) {
            if (preparing) CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
            else Icon(if (playing) Icons.Rounded.Pause else Icons.Rounded.PlayArrow, null, tint = Color.White, modifier = Modifier.size(26.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.GraphicEq, null, tint = accent, modifier = Modifier.size(14.dp))
                Spacer(Modifier.width(4.dp))
                Text(stringResource(R.string.mv_voice_customer), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
            VoiceBars(voice.waveform.ifEmpty { List(40) { i -> 20 + (i * 37 % 60) } }, progress, accent)
            Text(if (player != null) voiceTime(position) + " / " + voiceTime(duration) else voiceTime(duration), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
private fun VoiceBars(bars: List<Int>, progress: Float, color: Color) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val played by animateFloatAsState(progress, tween(120), label = "mv-voice-played")
    Canvas(Modifier.fillMaxWidth().height(28.dp).padding(vertical = 2.dp)) {
        val n = bars.size.coerceAtLeast(1)
        val gap = 2.dp.toPx()
        val w = ((size.width - gap * (n - 1)) / n).coerceAtLeast(1f)
        bars.forEachIndexed { i, v ->
            val h = (size.height * (v.coerceIn(6, 100) / 100f)).coerceAtLeast(3f)
            val x = if (rtl) size.width - (i + 1) * w - i * gap else i * (w + gap)
            drawRoundRect(
                color = if ((i + 0.5f) / n <= played) color else color.copy(alpha = 0.28f),
                topLeft = Offset(x, (size.height - h) / 2), size = Size(w, h), cornerRadius = CornerRadius(w / 2, w / 2),
            )
        }
    }
}
