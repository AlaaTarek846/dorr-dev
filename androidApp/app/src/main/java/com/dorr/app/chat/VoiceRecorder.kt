package com.dorr.app.chat

import android.content.Context
import android.media.MediaRecorder
import android.os.Build
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import java.io.File
import java.util.UUID

/** A finished voice note, ready to upload. */
data class VoiceClip(val file: File, val durationMs: Long, val waveform: List<Int>)

/**
 * Records a voice note (AAC in .m4a) and samples the microphone level ~12 times a second, so the
 * composer can draw a live waveform while you talk and the note carries its waveform to the
 * other side (the `waveform` bars, 0–100).
 */
class VoiceRecorder(private val context: Context) {
    var recording by mutableStateOf(false)
        private set
    var elapsedMs by mutableLongStateOf(0L)
        private set

    /** The latest levels (0–100), newest last — for the live bars. */
    val levels = mutableStateListOf<Int>()

    private var recorder: MediaRecorder? = null
    private var file: File? = null
    private var startedAt = 0L
    private val samples = mutableListOf<Int>()
    private var sampler: Job? = null
    private val scope = CoroutineScope(Dispatchers.Main)

    fun start(): Boolean {
        if (recording) return true
        val out = File(File(context.cacheDir, "chat-voice").apply { mkdirs() }, "${UUID.randomUUID()}.m4a")
        val r = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) MediaRecorder(context) else @Suppress("DEPRECATION") MediaRecorder()
        return runCatching {
            r.setAudioSource(MediaRecorder.AudioSource.MIC)
            r.setOutputFormat(MediaRecorder.OutputFormat.MPEG_4)
            r.setAudioEncoder(MediaRecorder.AudioEncoder.AAC)
            r.setAudioEncodingBitRate(64_000)
            r.setAudioSamplingRate(44_100)
            r.setOutputFile(out.absolutePath)
            r.prepare()
            r.start()
            recorder = r
            file = out
            startedAt = System.currentTimeMillis()
            samples.clear()
            levels.clear()
            recording = true
            sampler = scope.launch {
                while (isActive) {
                    val amp = runCatching { recorder?.maxAmplitude ?: 0 }.getOrDefault(0)
                    // maxAmplitude is 0..32767 — a square-root curve makes quiet speech visible.
                    val level = (kotlin.math.sqrt(amp / 32767.0) * 100).toInt().coerceIn(4, 100)
                    samples += level
                    levels += level
                    if (levels.size > 40) levels.removeAt(0)
                    elapsedMs = System.currentTimeMillis() - startedAt
                    delay(80)
                }
            }
            true
        }.getOrElse {
            runCatching { r.release() }
            false
        }
    }

    /** Stop and hand back the clip (null when it was too short to be a message). */
    fun finish(): VoiceClip? {
        val clip = stopInternal()
        val f = file ?: return null
        if (clip < 700) {
            f.delete()
            return null
        }
        return VoiceClip(f, clip, compress(samples, 64))
    }

    fun cancel() {
        stopInternal()
        file?.delete()
        file = null
    }

    private fun stopInternal(): Long {
        sampler?.cancel()
        val duration = System.currentTimeMillis() - startedAt
        runCatching { recorder?.stop() }
        runCatching { recorder?.release() }
        recorder = null
        recording = false
        elapsedMs = 0
        return duration
    }

    /** Squeeze the samples into `bars` peaks. */
    private fun compress(values: List<Int>, bars: Int): List<Int> {
        if (values.isEmpty()) return List(bars) { 10 }
        if (values.size <= bars) return values
        val step = values.size.toFloat() / bars
        return List(bars) { i ->
            val from = (i * step).toInt()
            val to = ((i + 1) * step).toInt().coerceAtMost(values.size).coerceAtLeast(from + 1)
            values.subList(from, to).max()
        }
    }
}
