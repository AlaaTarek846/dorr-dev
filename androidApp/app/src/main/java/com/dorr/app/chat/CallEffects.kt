package com.dorr.app.chat

import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.LinearGradient
import android.graphics.Paint
import android.graphics.Shader
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.google.mlkit.vision.common.InputImage
import com.google.mlkit.vision.segmentation.Segmentation
import com.google.mlkit.vision.segmentation.selfie.SelfieSegmenterOptions
import livekit.org.webrtc.JavaI420Buffer
import livekit.org.webrtc.VideoFrame
import livekit.org.webrtc.VideoProcessor
import livekit.org.webrtc.VideoSink
import java.nio.ByteBuffer
import java.util.concurrent.atomic.AtomicBoolean

/**
 * Camera backgrounds in video calls (spec 94): blur, or a picture behind me. Done on the phone —
 * Google's on-device selfie segmentation finds me in each frame, the rest is blurred or replaced
 * — and nothing of it goes anywhere but the call itself. Without an effect the camera works as
 * before (LiveKit's own capture); with one, its frames pass through [BackgroundProcessor].
 */
object CallEffects {
    enum class Mode { None, Blur, Image }

    /** For the call screen. */
    var mode by mutableStateOf(Mode.None)
        private set
    var picked by mutableStateOf<Int?>(null)
        private set

    @Volatile internal var background: Bitmap? = null
        private set

    @Volatile internal var version = 0
        private set

    fun set(mode: Mode, bitmap: Bitmap? = null, preset: Int? = null) {
        background = if (mode == Mode.Image) bitmap ?: background else null
        picked = if (mode == Mode.Image) preset else null
        this.mode = if (mode == Mode.Image && background == null) Mode.None else mode
        version++
    }

    /** Ready-made backgrounds: soft gradients in the app's colours. */
    val presets: List<IntArray> = listOf(
        intArrayOf(0xFF0F172A.toInt(), 0xFF1E3A8A.toInt(), 0xFF0D9488.toInt()),
        intArrayOf(0xFF7C2D12.toInt(), 0xFFEA580C.toInt(), 0xFFFBBF24.toInt()),
        intArrayOf(0xFF4C1D95.toInt(), 0xFFDB2777.toInt(), 0xFFF472B6.toInt()),
        intArrayOf(0xFF064E3B.toInt(), 0xFF16A34A.toInt(), 0xFFA3E635.toInt()),
    )

    fun presetBitmap(index: Int): Bitmap {
        val bmp = Bitmap.createBitmap(540, 960, Bitmap.Config.ARGB_8888)
        val colors = presets[index.coerceIn(0, presets.lastIndex)]
        Canvas(bmp).drawPaint(Paint().apply { shader = LinearGradient(0f, 0f, 540f, 960f, colors, null, Shader.TileMode.CLAMP) })
        return bmp
    }
}

/** Finds me in each frame (every other one, kept in between) and blurs or replaces the rest. */
internal class BackgroundProcessor : VideoProcessor {
    private var sink: VideoSink? = null
    private val segmenter = Segmentation.getClient(
        SelfieSegmenterOptions.Builder().setDetectorMode(SelfieSegmenterOptions.STREAM_MODE).enableRawSizeMask().build(),
    )
    private val busy = AtomicBoolean(false)

    @Volatile private var mask: FloatArray? = null
    @Volatile private var maskW = 0
    @Volatile private var maskH = 0

    // The picture behind, in the frame's own layout (rebuilt when the size, rotation or picture change).
    private var bgKey = ""
    private var bgY = ByteArray(0)
    private var bgU = ByteArray(0)
    private var bgV = ByteArray(0)

    override fun setSink(sink: VideoSink?) {
        this.sink = sink
    }

    override fun onCapturerStarted(success: Boolean) = Unit

    override fun onCapturerStopped() = Unit

    override fun onFrameCaptured(frame: VideoFrame) {
        val out = sink ?: return
        val mode = CallEffects.mode
        if (mode == CallEffects.Mode.None) {
            out.onFrame(frame)
            return
        }
        val i420 = frame.buffer.toI420() ?: run { out.onFrame(frame); return }
        try {
            val rotation = frame.rotation
            segment(i420, rotation)
            val m = mask
            if (m == null) {
                out.onFrame(frame)
                return
            }
            val result = composite(i420, rotation, m, maskW, maskH, mode)
            val processed = VideoFrame(result, rotation, frame.timestampNs)
            out.onFrame(processed)
            processed.release()
        } catch (_: Throwable) {
            out.onFrame(frame)
        } finally {
            i420.release()
        }
    }

    /** A small upright copy to the segmenter — one at a time; frames in between use the last mask. */
    private fun segment(i420: VideoFrame.I420Buffer, rotation: Int) {
        if (!busy.compareAndSet(false, true)) return
        val w = i420.width
        val h = i420.height
        val step = maxOf(1, w / 256)
        val sw = (w / step) and 1.inv()
        val sh = (h / step) and 1.inv()
        val nv21 = ByteArray(sw * sh * 3 / 2)
        val y = i420.dataY
        val u = i420.dataU
        val v = i420.dataV
        for (r in 0 until sh) for (c in 0 until sw) nv21[r * sw + c] = y.get(r * step * i420.strideY + c * step)
        var o = sw * sh
        for (r in 0 until sh / 2) for (c in 0 until sw / 2) {
            val idx = (r * step) * i420.strideU + c * step
            nv21[o++] = v.get(minOf(idx, v.capacity() - 1))
            nv21[o++] = u.get(minOf(idx, u.capacity() - 1))
        }
        segmenter.process(InputImage.fromByteArray(nv21, sw, sh, rotation, InputImage.IMAGE_FORMAT_NV21))
            .addOnSuccessListener { result ->
                val buf = result.buffer
                buf.rewind()
                val arr = FloatArray(result.width * result.height)
                buf.asFloatBuffer().get(arr)
                mask = arr
                maskW = result.width
                maskH = result.height
                busy.set(false)
            }
            .addOnFailureListener { busy.set(false) }
    }

    /** The upright position of a pixel of the frame (as rotated for display). */
    private inline fun upright(x: Int, y: Int, w: Int, h: Int, rotation: Int, out: (Int, Int) -> Unit) = when (rotation) {
        90 -> out(h - 1 - y, x)
        180 -> out(w - 1 - x, h - 1 - y)
        270 -> out(y, w - 1 - x)
        else -> out(x, y)
    }

    private fun composite(src: VideoFrame.I420Buffer, rotation: Int, m: FloatArray, mw: Int, mh: Int, mode: CallEffects.Mode): JavaI420Buffer {
        val w = src.width
        val h = src.height
        val uw = if (rotation == 90 || rotation == 270) h else w
        val uh = if (rotation == 90 || rotation == 270) w else h
        val dst = JavaI420Buffer.allocate(w, h)
        val image = mode == CallEffects.Mode.Image && CallEffects.background != null
        if (image) prepareImage(w, h, rotation, uw, uh)
        // A blurred copy: 1/8 of the size, smoothed — read back stretched.
        val blurY = if (!image) shrinkBlur(src.dataY, src.strideY, w, h, 8) else null
        val cw = (w + 1) / 2
        val ch = (h + 1) / 2
        val blurU = if (!image) shrinkBlur(src.dataU, src.strideU, cw, ch, 4) else null
        val blurV = if (!image) shrinkBlur(src.dataV, src.strideV, cw, ch, 4) else null

        val alpha = { x: Int, y: Int ->
            var a = 0f
            upright(x, y, w, h, rotation) { ux, uy -> a = m[(uy * mh / uh).coerceIn(0, mh - 1) * mw + (ux * mw / uw).coerceIn(0, mw - 1)] }
            ((a - 0.35f) / 0.3f).coerceIn(0f, 1f)
        }
        val sy = src.dataY
        val dy = dst.dataY
        for (y in 0 until h) for (x in 0 until w) {
            val a = alpha(x, y)
            val fg = sy.get(y * src.strideY + x).toInt() and 0xFF
            val bg = if (image) bgY[y * w + x].toInt() and 0xFF else blurY!!.at(x / 8, y / 8)
            dy.put(y * dst.strideY + x, (fg * a + bg * (1 - a)).toInt().toByte())
        }
        for (plane in 0..1) {
            val s = if (plane == 0) src.dataU else src.dataV
            val ss = if (plane == 0) src.strideU else src.strideV
            val d = if (plane == 0) dst.dataU else dst.dataV
            val ds = if (plane == 0) dst.strideU else dst.strideV
            val blur = if (plane == 0) blurU else blurV
            val img = if (plane == 0) bgU else bgV
            for (y in 0 until ch) for (x in 0 until cw) {
                val a = alpha(minOf(x * 2, w - 1), minOf(y * 2, h - 1))
                val fg = s.get(y * ss + x).toInt() and 0xFF
                val bg = if (image) img[y * cw + x].toInt() and 0xFF else blur!!.at(x / 4, y / 4)
                d.put(y * ds + x, (fg * a + bg * (1 - a)).toInt().toByte())
            }
        }
        return dst
    }

    private class Small(val data: IntArray, val w: Int, val h: Int) {
        fun at(x: Int, y: Int): Int = data[y.coerceIn(0, h - 1) * w + x.coerceIn(0, w - 1)]
    }

    /** Shrink by `f` (averaging) and box-blur twice. */
    private fun shrinkBlur(plane: ByteBuffer, stride: Int, w: Int, h: Int, f: Int): Small {
        val sw = maxOf(1, w / f)
        val sh = maxOf(1, h / f)
        var a = IntArray(sw * sh)
        for (y in 0 until sh) for (x in 0 until sw) {
            var sum = 0
            for (dy in 0 until f step 2) for (dx in 0 until f step 2) sum += plane.get(minOf(y * f + dy, h - 1) * stride + minOf(x * f + dx, w - 1)).toInt() and 0xFF
            a[y * sw + x] = sum / ((f / 2) * (f / 2)).coerceAtLeast(1)
        }
        repeat(2) {
            val b = IntArray(sw * sh)
            for (y in 0 until sh) for (x in 0 until sw) {
                var sum = 0
                var n = 0
                for (dy in -2..2) for (dx in -2..2) {
                    val yy = y + dy
                    val xx = x + dx
                    if (yy in 0 until sh && xx in 0 until sw) { sum += a[yy * sw + xx]; n++ }
                }
                b[y * sw + x] = sum / n
            }
            a = b
        }
        return Small(a, sw, sh)
    }

    /** The picture, cropped to fill the upright frame, in the frame's own layout (Y, U, V). */
    private fun prepareImage(w: Int, h: Int, rotation: Int, uw: Int, uh: Int) {
        val bmp = CallEffects.background ?: return
        val key = "$w:$h:$rotation:${CallEffects.version}"
        if (key == bgKey) return
        val scale = maxOf(uw.toFloat() / bmp.width, uh.toFloat() / bmp.height)
        val scaled = Bitmap.createScaledBitmap(bmp, maxOf(uw, (bmp.width * scale).toInt()), maxOf(uh, (bmp.height * scale).toInt()), true)
        val ox = (scaled.width - uw) / 2
        val oy = (scaled.height - uh) / 2
        val px = IntArray(uw * uh)
        scaled.getPixels(px, 0, uw, ox, oy, uw, uh)
        val cw = (w + 1) / 2
        val ch = (h + 1) / 2
        bgY = ByteArray(w * h)
        bgU = ByteArray(cw * ch)
        bgV = ByteArray(cw * ch)
        for (y in 0 until h) for (x in 0 until w) {
            upright(x, y, w, h, rotation) { ux, uy ->
                val c = px[uy.coerceIn(0, uh - 1) * uw + ux.coerceIn(0, uw - 1)]
                val r = (c shr 16) and 0xFF
                val g = (c shr 8) and 0xFF
                val b = c and 0xFF
                bgY[y * w + x] = (((66 * r + 129 * g + 25 * b + 128) shr 8) + 16).coerceIn(0, 255).toByte()
                if (x % 2 == 0 && y % 2 == 0) {
                    bgU[(y / 2) * cw + x / 2] = (((-38 * r - 74 * g + 112 * b + 128) shr 8) + 128).coerceIn(0, 255).toByte()
                    bgV[(y / 2) * cw + x / 2] = (((112 * r - 94 * g - 18 * b + 128) shr 8) + 128).coerceIn(0, 255).toByte()
                }
            }
        }
        bgKey = key
    }
}
