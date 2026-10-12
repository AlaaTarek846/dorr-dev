package com.dorr.app.ui.screens.chat

import android.Manifest
import android.content.pm.PackageManager
import android.media.MediaPlayer
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.Crossfade
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
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
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AlternateEmail
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.Palette
import androidx.compose.material.icons.rounded.Stop
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import com.dorr.app.R
import com.dorr.app.chat.VoiceRecorder
import com.dorr.app.network.ApiClient
import com.dorr.app.network.StoryDto
import com.dorr.app.network.apiFailure
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/*
 * Statuses from the WhatsApp list in the spec's backlog: a voice status (78), mentioning people
 * (84), who may add it to their own status (85) without it saying whose it was (86).
 */

/** Record a voice status on a colour of my choice (up to the admin's story length). */
@Composable
internal fun VoiceStoryEditor() {
    val host = LocalChat.current
    val context = LocalContext.current
    val recorder = remember { VoiceRecorder(context) }
    var clip by remember { mutableStateOf<com.dorr.app.chat.VoiceClip?>(null) }
    var bg by remember { mutableIntStateOf(0) }
    var allowReplies by remember { mutableStateOf(true) }
    var mentions by remember { mutableStateOf<List<Int>>(emptyList()) }
    val backgrounds = StoryLook.backgrounds.keys.toList()
    val networkError = stringResource(R.string.ch_error_network)
    val mic = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted -> if (granted) recorder.start() }

    fun stop() {
        clip = recorder.finish()
    }
    // The longest a status may be.
    LaunchedEffect(recorder.recording) {
        while (recorder.recording) {
            if (recorder.elapsedMs >= VIDEO_LIMIT_SECONDS * 1000L) stop()
            delay(100)
        }
    }
    DisposableEffect(Unit) { onDispose { if (recorder.recording) recorder.cancel() } }
    BackHandler { host.pop() }

    Box(Modifier.fillMaxSize()) {
        Crossfade(targetState = backgrounds[bg], animationSpec = tween(450), label = "voiceBg") { name ->
            Box(Modifier.fillMaxSize().background(StoryLook.brush(name)))
        }
        Column(Modifier.fillMaxSize().padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
            VoiceBars(if (recorder.recording) recorder.levels.toList() else clip?.waveform.orEmpty(), Modifier.fillMaxWidth().height(90.dp))
            Spacer(Modifier.height(22.dp))
            val pulse = rememberInfiniteTransition(label = "mic")
            val scale by pulse.animateFloat(1f, 1.12f, infiniteRepeatable(tween(600), RepeatMode.Reverse), label = "micScale")
            Box(
                Modifier.size(92.dp).scale(if (recorder.recording) scale else 1f).clip(CircleShape).background(Color.White).clickable {
                    when {
                        recorder.recording -> stop()
                        ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED -> { clip = null; recorder.start() }
                        else -> mic.launch(Manifest.permission.RECORD_AUDIO)
                    }
                },
                contentAlignment = Alignment.Center,
            ) { Icon(if (recorder.recording) Icons.Rounded.Stop else Icons.Rounded.Mic, null, tint = Ch.Red, modifier = Modifier.size(44.dp)) }
            Spacer(Modifier.height(14.dp))
            Text(
                when {
                    recorder.recording -> stringResource(R.string.bk_voice_recording) + "  " + durationText(recorder.elapsedMs)
                    clip != null -> durationText(clip!!.durationMs)
                    else -> stringResource(R.string.bk_voice_hold, VIDEO_LIMIT_SECONDS)
                },
                color = Color.White, fontWeight = FontWeight.Bold, fontSize = 15.sp, textAlign = TextAlign.Center,
            )
        }

        Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            GlassIcon(Icons.Rounded.Close) { host.pop() }
            Spacer(Modifier.weight(1f))
            MentionPill(mentions) { mentions = it }
            Spacer(Modifier.width(10.dp))
            Box(
                Modifier.size(46.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.25f)).border(1.dp, Color.White.copy(alpha = 0.35f), CircleShape).clickable { bg = (bg + 1) % backgrounds.size },
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.Palette, null, tint = Color.White, modifier = Modifier.size(22.dp)) }
        }

        Row(Modifier.align(Alignment.BottomCenter).fillMaxWidth().navigationBarsPadding().padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
            PostOptions(allowReplies, onToggleReplies = { allowReplies = !allowReplies })
            Spacer(Modifier.weight(1f))
            PostButton(enabled = clip != null && !recorder.recording) {
                val c = clip ?: return@PostButton
                host.postStory(
                    buildMap {
                        put("type", "voice")
                        put("duration_ms", c.durationMs.toString())
                        put("style[background]", backgrounds[bg])
                        c.waveform.forEachIndexed { i, v -> put("style[waveform][$i]", v.toString()) }
                        put("allow_replies", if (allowReplies) "1" else "0")
                        mentions.forEachIndexed { i, id -> put("mentions[$i]", id.toString()) }
                    },
                    LocalFile(c.file, "audio/mp4", c.file.name),
                    networkError,
                )
            }
        }
    }
}

/** "@ Mention": pick saved contacts; they're told in our chat. */
@Composable
internal fun MentionPill(mentions: List<Int>, onMentions: (List<Int>) -> Unit) {
    var picking by remember { mutableStateOf(false) }
    Row(
        Modifier.clip(RoundedCornerShape(20.dp)).background(Color.Black.copy(alpha = 0.35f)).clickable { picking = true }.padding(horizontal = 12.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.AlternateEmail, null, tint = Color.White, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(if (mentions.isEmpty()) stringResource(R.string.bk_mention) else stringResource(R.string.bk_mentioned_n, mentions.size), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
    }
    if (picking) PeoplePickerSheet(mentions.toSet(), onDismiss = { picking = false }) { ids ->
        onMentions(ids.take(10))
        picking = false
    }
}

/** A voice status: its colour, its bars filling as it plays, the sound itself. */
@Composable
internal fun VoiceStoryContent(story: StoryDto, playing: Boolean) {
    val url = ApiClient.mediaUrl(story.media?.url)
    var player by remember(story.id) { mutableStateOf<MediaPlayer?>(null) }
    var progress by remember(story.id) { mutableStateOf(0f) }
    DisposableEffect(story.id, url) {
        val mp = MediaPlayer()
        runCatching {
            mp.setDataSource(url)
            mp.setOnPreparedListener { player = mp }
            mp.prepareAsync()
        }
        onDispose {
            runCatching { mp.release() }
            player = null
        }
    }
    LaunchedEffect(player, playing) {
        val mp = player ?: return@LaunchedEffect
        if (playing) runCatching { mp.start() } else runCatching { mp.pause() }
        while (playing) {
            progress = runCatching { mp.currentPosition / mp.duration.coerceAtLeast(1).toFloat() }.getOrDefault(0f)
            delay(80)
        }
    }
    Box(Modifier.fillMaxSize().background(StoryLook.brush(story.style?.background)), contentAlignment = Alignment.Center) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(84.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.2f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Mic, null, tint = Color.White, modifier = Modifier.size(42.dp))
            }
            Spacer(Modifier.height(24.dp))
            VoiceBars(story.style?.waveform.orEmpty().ifEmpty { List(36) { i -> 20 + (i * 37) % 60 } }, Modifier.fillMaxWidth().height(80.dp).padding(horizontal = 30.dp), progress)
        }
    }
}

@Composable
private fun VoiceBars(levels: List<Int>, modifier: Modifier, progress: Float = 1f) {
    Canvas(modifier) {
        val bars = levels.takeLast(48).ifEmpty { List(24) { 6 } }
        val gap = 3.dp.toPx()
        val w = ((size.width - gap * (bars.size - 1)) / bars.size).coerceAtLeast(2f)
        bars.forEachIndexed { i, v ->
            val h = (size.height * (0.12f + 0.88f * v / 100f)).coerceAtLeast(4.dp.toPx())
            val filled = (i + 0.5f) / bars.size <= progress
            drawRoundRect(Color.White.copy(alpha = if (filled) 1f else 0.45f), Offset(i * (w + gap), (size.height - h) / 2), Size(w, h), CornerRadius(w / 2))
        }
    }
}

/** "Mentioned you" on a status — and adding it to mine (it won't say whose it was). */
@Composable
internal fun MentionedMeBar(story: StoryDto) {
    val host = LocalChat.current
    val added = stringResource(R.string.bk_reshared)
    var done by remember(story.id) { mutableStateOf(false) }
    Row(
        Modifier.padding(horizontal = 14.dp, vertical = 6.dp).clip(RoundedCornerShape(20.dp)).background(Color.Black.copy(alpha = 0.45f)).padding(horizontal = 12.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.AlternateEmail, null, tint = Color.White, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(stringResource(R.string.bk_mentioned_you), color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.Bold)
        if (!done) {
            Spacer(Modifier.width(10.dp))
            Text(
                stringResource(R.string.bk_reshare), color = Ch.Red, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.clip(RoundedCornerShape(14.dp)).background(Color.White).clickable {
                    host.scope.launch {
                        runCatching { ApiClient.backlog.reshareStory(chatAuth(), story.id) }
                            .onSuccess { done = true; host.showToast(added); host.refreshStories() }
                            .onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                    }
                }.padding(horizontal = 10.dp, vertical = 5.dp),
            )
        }
    }
}

/** A `story_mention` message: the status, and (for the one mentioned) adding it to theirs. */
@Composable
internal fun StoryMentionLine(meta: JsonObject?, mine: Boolean, footer: @Composable () -> Unit) {
    val host = LocalChat.current
    val added = stringResource(R.string.bk_reshared)
    val storyId = meta?.get("story_id")?.takeIf { it.isJsonPrimitive }?.asString
    val expires = meta?.get("expires_at")?.takeIf { it.isJsonPrimitive }?.asString?.let { runCatching { java.time.OffsetDateTime.parse(it) }.getOrNull() }
    val live = expires?.isAfter(java.time.OffsetDateTime.now()) != false
    var done by remember(storyId) { mutableStateOf(false) }
    Column(Modifier.padding(horizontal = 12.dp, vertical = 8.dp)) {
        Text("📣 " + stringResource(if (mine) R.string.bk_story_mention_mine else R.string.bk_story_mention_line), color = if (mine) Ch.OutText else Ch.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold)
        if (!mine && storyId != null && live && !done) {
            Text(
                stringResource(R.string.bk_reshare), color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.padding(top = 8.dp).clip(RoundedCornerShape(14.dp)).background(Ch.Red).clickable {
                    host.scope.launch {
                        runCatching { ApiClient.backlog.reshareStory(chatAuth(), storyId) }
                            .onSuccess { done = true; host.showToast(added); host.refreshStories() }
                            .onFailure { e -> e.apiFailure().message?.let { host.showToast(it) } }
                    }
                }.padding(horizontal = 14.dp, vertical = 8.dp),
            )
        }
        Box(Modifier.fillMaxWidth().padding(top = 4.dp), contentAlignment = Alignment.CenterEnd) { footer() }
    }
}
