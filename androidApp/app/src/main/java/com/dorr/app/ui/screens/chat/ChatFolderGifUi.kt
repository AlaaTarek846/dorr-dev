package com.dorr.app.ui.screens.chat

import android.graphics.Matrix
import android.graphics.SurfaceTexture
import android.media.MediaPlayer
import android.net.Uri
import android.view.Surface
import android.view.TextureView
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Gif
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.RangeSlider
import androidx.compose.material3.SliderDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.compose.ui.window.DialogProperties
import com.dorr.app.ui.locale.LocaleAwareDialog
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.dorr.app.R
import com.dorr.app.chat.VideoTools
import com.dorr.app.network.MessageDto
import com.google.gson.JsonObject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File
import java.util.Locale

// ------------------------------------------------------------------ a video sent as a GIF (spec 11)

/** A video that plays as a GIF: muted, on a loop, by itself. `meta.gif` (true from the server, 1 while sending). */
internal fun MessageDto.isGifVideo(): Boolean {
    if (type != "video") return false
    val flag = meta?.get("gif")?.takeIf { it.isJsonPrimitive }?.asJsonPrimitive ?: return false
    return (flag.isBoolean && flag.asBoolean) || (flag.isNumber && flag.asInt == 1)
}

/**
 * A short video playing muted on a loop, cropped to fill its box (a TextureView, so it scrolls in
 * a list like a picture). [endMs]: play only from [startMs] to there, again and again. Pauses
 * while the app is in the background.
 */
@Composable
internal fun LoopingVideo(source: String, modifier: Modifier = Modifier, startMs: Long = 0, endMs: Long? = null) {
    val start by rememberUpdatedState(startMs)
    val end by rememberUpdatedState(endMs)
    var player by remember(source) { mutableStateOf<MediaPlayer?>(null) }
    var ready by remember(source) { mutableStateOf(false) }
    val lifecycle = LocalLifecycleOwner.current.lifecycle

    // A clip window: back to its start whenever it passes the end.
    LaunchedEffect(player, ready) {
        val mp = player ?: return@LaunchedEffect
        if (!ready) return@LaunchedEffect
        while (isActive) {
            end?.let { e -> runCatching { if (mp.currentPosition >= e) mp.seekTo(start.toInt()) } }
            delay(40)
        }
    }
    // The window moved: show its start now.
    LaunchedEffect(startMs, ready) {
        if (ready) runCatching { player?.seekTo(startMs.toInt()) }
    }
    DisposableEffect(lifecycle, player) {
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_STOP -> runCatching { player?.pause() }
                Lifecycle.Event.ON_START -> if (ready) runCatching { player?.start() }
                else -> Unit
            }
        }
        lifecycle.addObserver(observer)
        onDispose { lifecycle.removeObserver(observer) }
    }
    DisposableEffect(source) {
        onDispose {
            player?.let { runCatching { it.release() } }
            player = null
            ready = false
        }
    }

    key(source) {
        AndroidView(
            modifier = modifier,
            factory = { ctx ->
                TextureView(ctx).apply {
                    fun fill(videoW: Int, videoH: Int) {
                        if (videoW <= 0 || videoH <= 0 || width <= 0 || height <= 0) return
                        // The texture is stretched to the view; scale back so it covers it, centred.
                        val scale = maxOf(width / videoW.toFloat(), height / videoH.toFloat())
                        setTransform(Matrix().apply { setScale(videoW * scale / width, videoH * scale / height, width / 2f, height / 2f) })
                    }
                    surfaceTextureListener = object : TextureView.SurfaceTextureListener {
                        override fun onSurfaceTextureAvailable(texture: SurfaceTexture, w: Int, h: Int) {
                            val mp = MediaPlayer()
                            player = mp
                            runCatching {
                                mp.setSurface(Surface(texture))
                                mp.setDataSource(ctx, Uri.parse(source))
                                mp.isLooping = true
                                mp.setVolume(0f, 0f)
                                mp.setOnVideoSizeChangedListener { _, vw, vh -> fill(vw, vh) }
                                mp.setOnPreparedListener {
                                    runCatching { it.seekTo(start.toInt()) }
                                    fill(it.videoWidth, it.videoHeight)
                                    if (lifecycle.currentState.isAtLeast(Lifecycle.State.STARTED)) it.start()
                                    ready = true
                                }
                                mp.setOnErrorListener { _, _, _ -> true }
                                mp.prepareAsync()
                            }
                        }

                        override fun onSurfaceTextureSizeChanged(texture: SurfaceTexture, w: Int, h: Int) {
                            player?.let { runCatching { fill(it.videoWidth, it.videoHeight) } }
                        }

                        override fun onSurfaceTextureDestroyed(texture: SurfaceTexture): Boolean {
                            player?.let { runCatching { it.release() } }
                            player = null
                            ready = false
                            return true
                        }

                        override fun onSurfaceTextureUpdated(texture: SurfaceTexture) = Unit
                    }
                }
            },
        )
    }
}

/** The small "GIF" tag on a looping clip. */
@Composable
internal fun GifTag(modifier: Modifier = Modifier) {
    Text(
        "GIF", color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold,
        modifier = modifier.clip(RoundedCornerShape(8.dp)).background(Color.Black.copy(alpha = 0.5f)).padding(horizontal = 6.dp, vertical = 2.dp),
    )
}

/**
 * Turn a picked video into a GIF: drag the two ends to the part you want (at most 6 s), watch it
 * loop, send. The clip goes muted and small (VideoTools.gifClip).
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun GifMakerDialog(source: LocalFile, onDismiss: () -> Unit, onSend: (clip: LocalFile, lengthMs: Long, poster: File?) -> Unit) {
    val context = LocalContext.current
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    val info = remember(source) { VideoTools.info(context, source.file) }
    val duration = info?.durationMs ?: 0L
    val failed = stringResource(R.string.fg_gif_failed)
    if (duration <= 0L) {
        LaunchedEffect(source) {
            host.showToast(failed)
            onDismiss()
        }
        return
    }
    val maxWindow = VideoTools.GIF_MAX_MS.toFloat()
    val minWindow = minOf(500f, duration.toFloat())
    var range by remember(source) { mutableStateOf(0f..minOf(duration.toFloat(), maxWindow)) }
    var making by remember { mutableStateOf(false) }
    val ratio = info?.let { if (it.width > 0 && it.height > 0) (it.width / it.height.toFloat()).coerceIn(0.5f, 2f) else null } ?: 1f
    val length = (range.endInclusive - range.start).toLong()

    LocaleAwareDialog(onDismissRequest = { if (!making) onDismiss() }, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Column(Modifier.fillMaxSize().background(Color(0xFF0E1116)).statusBarsPadding().navigationBarsPadding()) {
            Row(Modifier.fillMaxWidth().padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(
                    Modifier.size(42.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.12f)).clickable(enabled = !making, onClick = onDismiss),
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.Rounded.Close, null, tint = Color.White) }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.fg_gif_title), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp)
                    Text(stringResource(R.string.fg_gif_hint), color = Color.White.copy(alpha = 0.6f), fontSize = 12.sp)
                }
            }

            Box(Modifier.weight(1f).fillMaxWidth().padding(20.dp), contentAlignment = Alignment.Center) {
                Box(Modifier.fillMaxWidth().aspectRatio(ratio).clip(RoundedCornerShape(20.dp)).background(Color.Black)) {
                    LoopingVideo(Uri.fromFile(source.file).toString(), Modifier.fillMaxSize(), startMs = range.start.toLong(), endMs = range.endInclusive.toLong())
                    GifTag(Modifier.align(Alignment.TopStart).padding(10.dp))
                    if (making) {
                        Column(Modifier.align(Alignment.Center), horizontalAlignment = Alignment.CenterHorizontally) {
                            CircularProgressIndicator(color = Color.White, strokeWidth = 3.dp)
                            Text(stringResource(R.string.fg_gif_making), color = Color.White, fontSize = 13.sp, modifier = Modifier.padding(top = 10.dp))
                        }
                    }
                }
            }

            Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Gif, null, tint = Color.White, modifier = Modifier.size(22.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(
                        stringResource(R.string.fg_gif_length, String.format(Locale.US, "%.1f", length / 1000f)),
                        color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.Bold,
                    )
                }
                RangeSlider(
                    value = range,
                    enabled = !making,
                    valueRange = 0f..duration.toFloat(),
                    onValueChange = { r ->
                        // At most 6 s: dragging one end past it pulls the other along.
                        var a = r.start
                        var b = r.endInclusive
                        if (b - a > maxWindow) {
                            if (a != range.start) b = a + maxWindow else a = b - maxWindow
                        }
                        if (b - a >= minWindow) range = a.coerceAtLeast(0f)..b.coerceAtMost(duration.toFloat())
                    },
                    colors = SliderDefaults.colors(thumbColor = Color.White, activeTrackColor = Ch.Red, inactiveTrackColor = Color.White.copy(alpha = 0.25f)),
                )
            }

            ChPrimaryButton(
                stringResource(R.string.fg_gif_send), Modifier.fillMaxWidth().padding(20.dp), icon = Icons.Rounded.Gif, enabled = !making,
            ) {
                making = true
                scope.launch {
                    val clip = VideoTools.gifClip(context, source.file, range.start.toLong(), range.endInclusive.toLong())
                    val poster = clip?.let { withContext(Dispatchers.IO) { VideoTools.poster(context, it.file) } }
                    making = false
                    if (clip == null) host.showToast(failed)
                    else onSend(clip, length, poster)
                }
            }
        }
    }
}

// ------------------------------------------------------------------ a folder sent as one zip (spec 10)

/** A folder's name and file count: `meta.folder` from the server, `folder_name` / `folder_count` while sending. */
internal fun MessageDto.folderInfo(): Pair<String, Int?>? {
    if (type != "document") return null
    val meta = meta ?: return null
    (meta.get("folder") as? JsonObject)?.let { f ->
        val name = f.get("name")?.takeIf { it.isJsonPrimitive }?.asString ?: return@let
        return name to f.get("count")?.takeIf { it.isJsonPrimitive }?.asInt
    }
    val name = meta.get("folder_name")?.takeIf { it.isJsonPrimitive }?.asString ?: return null
    return name to meta.get("folder_count")?.takeIf { it.isJsonPrimitive }?.asInt
}
