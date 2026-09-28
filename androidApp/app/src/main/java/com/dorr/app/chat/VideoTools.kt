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
import com.dorr.app.ui.screens.chat.LocalFile
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.coroutines.withContext
import java.io.File
import java.util.UUID
import kotlin.coroutines.resume

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
     * Shrink the video if it's worth it. Already small (≤ 720p and a modest bitrate) → sent as is.
     */
    suspend fun compress(context: Context, input: LocalFile): LocalFile {
        val meta = info(context, input.file) ?: return input
        val shortSide = minOf(meta.width, meta.height)
        val alreadySmall = shortSide in 1..SHORT_SIDE && (meta.bitrate ?: Long.MAX_VALUE) <= BITRATE * 1.3
        if (alreadySmall || input.file.length() < 4L * 1024 * 1024) return input

        val out = File(File(context.cacheDir, "chat-out").apply { mkdirs() }, "${UUID.randomUUID()}.mp4")
        // Scale so the *short* side is 720 — portrait videos stay sharp too.
        val presentation = if (meta.width >= meta.height) Presentation.createForHeight(SHORT_SIDE)
        else Presentation.createForWidthAndHeight(SHORT_SIDE, SHORT_SIDE * meta.height / meta.width.coerceAtLeast(1), Presentation.LAYOUT_SCALE_TO_FIT)

        // Transformer must be driven from a thread with a Looper: the main one.
        val ok = withContext(Dispatchers.Main) {
            suspendCancellableCoroutine { cont ->
                val encoder = DefaultEncoderFactory.Builder(context)
                    .setRequestedVideoEncoderSettings(VideoEncoderSettings.Builder().setBitrate(BITRATE).build())
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
                            Log.w(TAG, "Video compression failed — sending the original", exportException)
                            if (cont.isActive) cont.resume(false)
                        }
                    })
                    .build()
                val item = EditedMediaItem.Builder(MediaItem.fromUri(Uri.fromFile(input.file)))
                    .setEffects(Effects(emptyList(), listOf(presentation)))
                    .build()
                runCatching { transformer.start(item, out.absolutePath) }.onFailure { if (cont.isActive) cont.resume(false) }
                cont.invokeOnCancellation { runCatching { transformer.cancel() } }
            }
        }

        return if (ok && out.length() in 1 until input.file.length()) LocalFile(out, "video/mp4", input.name.substringBeforeLast('.') + ".mp4")
        else input.also { out.delete() }
    }
}
