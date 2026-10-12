package com.dorr.app.chat

import android.content.Context
import android.graphics.Bitmap
import android.media.MediaMetadataRetriever
import android.net.Uri
import android.util.Log
import androidx.media3.common.MediaItem
import androidx.media3.common.MimeTypes
import androidx.media3.effect.Presentation
import androidx.media3.transformer.Composition
import androidx.media3.transformer.DefaultEncoderFactory
import androidx.media3.transformer.EditedMediaItem
import androidx.media3.transformer.Effects
import androidx.media3.transformer.ExportException
import androidx.media3.transformer.ExportResult
import androidx.media3.transformer.Transformer
import androidx.media3.transformer.VideoEncoderSettings
import com.dorr.app.ui.screens.chat.ChatPrefs
import com.dorr.app.ui.screens.chat.LocalFile
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import java.io.File
import java.util.UUID
import kotlin.coroutines.resume

/** On a slow connection a video may go at most [VideoTools.SLOW_MAX_BYTES] (backlog 48). */
class VideoTooBigException : Exception("video too big for a slow connection")

/** What we know about a picked video before sending it. */
data class VideoInfo(val durationMs: Long?, val width: Int, val height: Int, val bitrate: Long?)

/**
 * Chat videos, WhatsApp-style: re-encoded on the phone to 720p H.264 / AAC at ~2.5 Mbps before
 * upload (a minute of 4K camera video, ~300 MB, becomes ~20 MB), plus a poster frame so the
 * bubble has a picture before — and after — it's played. Anything that fails keeps the original:
 * compression must never stop a video from being sent.
 */
object VideoTools {
    private const val TAG = "VideoTools"
    private const val SHORT_SIDE = 720
    private const val BITRATE = 2_500_000
    private const val SAVER_SHORT_SIDE = 480
    private const val SAVER_BITRATE = 1_200_000
    /** HD when I chose high quality (backlog 47): 1080p at ~6 Mbps. */
    private const val HD_SHORT_SIDE = 1080
    private const val HD_BITRATE = 6_000_000
    /** The cap on a slow connection (backlog 48). */
    const val SLOW_MAX_BYTES = 64L * 1024 * 1024
    private const val AUDIO_BITRATE = 128_000
    /** Below this the picture is mush: too long a video for the cap. */
    private const val MIN_BITRATE = 250_000

    fun info(context: Context, file: File): VideoInfo? = runCatching {
        MediaMetadataRetriever().run {
            setDataSource(context, Uri.fromFile(file))
            var w = extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_WIDTH)?.toIntOrNull() ?: 0
            var h = extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_HEIGHT)?.toIntOrNull() ?: 0
            val rotation = extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_ROTATION)?.toIntOrNull() ?: 0
            if (rotation == 90 || rotation == 270) w = h.also { h = w }
            VideoInfo(
                durationMs = extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)?.toLongOrNull(),
                width = w, height = h,
                bitrate = extractMetadata(MediaMetadataRetriever.METADATA_KEY_BITRATE)?.toLongOrNull(),
            ).also { release() }
        }
    }.getOrNull()

    /** A frame near the start, max 480px, as a small JPEG — the bubble's picture. */
    fun poster(context: Context, file: File): File? = runCatching {
        val retriever = MediaMetadataRetriever()
        retriever.setDataSource(context, Uri.fromFile(file))
        val frame = retriever.getFrameAtTime(700_000, MediaMetadataRetriever.OPTION_CLOSEST_SYNC) ?: retriever.frameAtTime
        retriever.release()
        frame ?: return null
        val scale = (480f / maxOf(frame.width, frame.height)).coerceAtMost(1f)
        val small = if (scale < 1f) Bitmap.createScaledBitmap(frame, (frame.width * scale).toInt(), (frame.height * scale).toInt(), true) else frame
        val out = File(File(context.cacheDir, "chat-out").apply { mkdirs() }, "${UUID.randomUUID()}-poster.jpg")
        out.outputStream().use { small.compress(Bitmap.CompressFormat.JPEG, 78, it) }
        out
    }.getOrNull()

    /**
     * Shrink the video if it's worth it. Already small (≤ the size for now and a modest bitrate)
     * → sent as is. HD (1080p) when I chose high quality; on a slow line 480p and never over
     * 64 MB — a long video gets the bitrate its length allows, or [VideoTooBigException].
     */
    suspend fun compress(context: Context, input: LocalFile): LocalFile {
        val mode = ChatPrefs.videoMode(context)
        val slow = mode == "slow"
        val meta = info(context, input.file) ?: return input.also { checkCap(slow, it) }
        val side = when (mode) {
            "hd" -> HD_SHORT_SIDE
            "slow" -> SAVER_SHORT_SIDE
            else -> SHORT_SIDE
        }
        var rate = when (mode) {
            "hd" -> HD_BITRATE
            "slow" -> SAVER_BITRATE
            else -> BITRATE
        }
        val seconds = (meta.durationMs ?: 0L) / 1000.0
        if (slow && seconds > 0) {
            // What fits under the cap for this length (sound ~128 kbps, 8% spare for the container).
            val fit = (SLOW_MAX_BYTES * 8 * 0.92 / seconds - AUDIO_BITRATE).toLong()
            if (fit < MIN_BITRATE) checkCap(true, input) else rate = minOf(rate.toLong(), fit).toInt()
        }
        val shortSide = minOf(meta.width, meta.height)
        val alreadySmall = shortSide in 1..side && (meta.bitrate ?: Long.MAX_VALUE) <= rate * 1.3
        if (alreadySmall || input.file.length() < 4L * 1024 * 1024) return input.also { checkCap(slow, it) }

        val out = File(File(context.cacheDir, "chat-out").apply { mkdirs() }, "${UUID.randomUUID()}.mp4")
        // Scale so the *short* side is the size for now — portrait videos stay sharp too — never up.
        val target = if (shortSide > 0) minOf(side, shortSide) else side
        val presentation = if (meta.width >= meta.height) Presentation.createForHeight(target)
        else Presentation.createForWidthAndHeight(target, target * meta.height / meta.width.coerceAtLeast(1), Presentation.LAYOUT_SCALE_TO_FIT)

        val item = EditedMediaItem.Builder(MediaItem.fromUri(Uri.fromFile(input.file)))
            .setEffects(Effects(emptyList(), listOf(presentation)))
            .build()
        val ok = export(context, item, out, rate)

        val result = if (ok && out.length() in 1 until input.file.length()) LocalFile(out, "video/mp4", input.name.substringBeforeLast('.') + ".mp4")
        else input.also { out.delete() }
        return result.also { checkCap(slow, it) }
    }

    private fun checkCap(slow: Boolean, file: LocalFile) {
        if (slow && file.file.length() > SLOW_MAX_BYTES) throw VideoTooBigException()
    }

    /** The longest clip that goes as a GIF (spec 11). */
    const val GIF_MAX_MS = 6_000L

    /**
     * A GIF made from a video, WhatsApp-style: really a short MP4 — [startMs]..[endMs] (at most
     * [GIF_MAX_MS]), no sound, 480p at ~1 Mbps — that the chat plays muted on a loop. Null when
     * the phone couldn't make it.
     */
    suspend fun gifClip(context: Context, input: File, startMs: Long, endMs: Long): LocalFile? {
        val meta = info(context, input) ?: return null
        val end = endMs.coerceAtMost(startMs + GIF_MAX_MS)
        if (end <= startMs) return null
        val presentation = if (meta.width >= meta.height) Presentation.createForHeight(GIF_SHORT_SIDE)
        else Presentation.createForWidthAndHeight(GIF_SHORT_SIDE, GIF_SHORT_SIDE * meta.height / meta.width.coerceAtLeast(1), Presentation.LAYOUT_SCALE_TO_FIT)
        val clipped = MediaItem.Builder()
            .setUri(Uri.fromFile(input))
            .setClippingConfiguration(MediaItem.ClippingConfiguration.Builder().setStartPositionMs(startMs).setEndPositionMs(end).build())
            .build()
        val item = EditedMediaItem.Builder(clipped)
            .setRemoveAudio(true)
            .setEffects(Effects(emptyList(), listOf(presentation)))
            .build()
        val out = File(File(context.cacheDir, "chat-out").apply { mkdirs() }, "${UUID.randomUUID()}-gif.mp4")
        return if (export(context, item, out, GIF_BITRATE) && out.length() > 0) LocalFile(out, "video/mp4", "gif.mp4") else null.also { out.delete() }
    }

    private const val GIF_SHORT_SIDE = 480
    private const val GIF_BITRATE = 1_000_000

    /** One Transformer run to H.264 (+ AAC when there's sound); false when it failed. */
    private suspend fun export(context: Context, item: EditedMediaItem, out: File, bitrate: Int): Boolean =
        // Transformer must be driven from a thread with a Looper: the main one.
        withContext(Dispatchers.Main) {
            suspendCancellableCoroutine { cont ->
                val encoder = DefaultEncoderFactory.Builder(context)
                    .setRequestedVideoEncoderSettings(VideoEncoderSettings.Builder().setBitrate(bitrate).build())
                    .build()
                val transformer = Transformer.Builder(context)
                    .setVideoMimeType(MimeTypes.VIDEO_H264)
                    .setAudioMimeType(MimeTypes.AUDIO_AAC)
                    .setEncoderFactory(encoder)
                    .addListener(object : Transformer.Listener {
                        override fun onCompleted(composition: Composition, exportResult: ExportResult) {
                            if (cont.isActive) cont.resume(true)
                        }

                        override fun onError(composition: Composition, exportResult: ExportResult, exportException: ExportException) {
                            Log.w(TAG, "Video export failed", exportException)
                            if (cont.isActive) cont.resume(false)
                        }
                    })
                    .build()
                runCatching { transformer.start(item, out.absolutePath) }.onFailure { if (cont.isActive) cont.resume(false) }
                cont.invokeOnCancellation { runCatching { transformer.cancel() } }
            }
        }
}
