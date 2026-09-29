package com.dorr.app.chat

import android.media.AudioAttributes
import android.media.MediaPlayer
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

/**
 * One voice note plays at a time, app-wide (starting another one stops the first, like WhatsApp).
 * Exposes Compose state so every bubble's waveform can follow its own progress.
 */
object VoicePlayer {
    var playingId by mutableStateOf<String?>(null)
        private set
    var progress by mutableFloatStateOf(0f)
        private set
    var positionMs by mutableStateOf(0L)
        private set
    var speed by mutableFloatStateOf(1f)
        private set
    var paused by mutableStateOf(false)
        private set

    private var player: MediaPlayer? = null
    private var ticker: Job? = null
    private val scope = CoroutineScope(Dispatchers.Main)

    fun toggle(id: String, url: String) {
        if (playingId == id) {
            player?.let { if (it.isPlaying) pause() else resume() }
            return
        }
        stop()
        playingId = id
        paused = false
        progress = 0f
        val mp = MediaPlayer()
        player = mp
        mp.setAudioAttributes(AudioAttributes.Builder().setContentType(AudioAttributes.CONTENT_TYPE_SPEECH).setUsage(AudioAttributes.USAGE_MEDIA).build())
        runCatching {
            mp.setDataSource(url)
            mp.setOnPreparedListener {
                applySpeed()
                it.start()
                tick()
            }
            mp.setOnCompletionListener { stop() }
            mp.setOnErrorListener { _, _, _ -> stop(); true }
            mp.prepareAsync()
        }.onFailure { stop() }
    }

    fun isPlaying(id: String): Boolean = playingId == id && !paused

    fun seek(id: String, fraction: Float) {
        if (playingId != id) return
        player?.let { it.seekTo((it.duration * fraction).toInt()) }
    }

    /** 1× → 1.5× → 2× */
    fun cycleSpeed() {
        speed = when (speed) {
            1f -> 1.5f
            1.5f -> 2f
            else -> 1f
        }
        applySpeed()
    }

    private fun applySpeed() {
        player?.let { mp ->
            runCatching { if (mp.isPlaying || playingId != null) mp.playbackParams = mp.playbackParams.setSpeed(speed) }
        }
    }

    private fun pause() {
        player?.pause()
        ticker?.cancel()
        paused = true
    }

    private fun resume() {
        player?.start()
        paused = false
        tick()
    }

    fun stop() {
        ticker?.cancel()
        runCatching { player?.release() }
        player = null
        playingId = null
        paused = false
        progress = 0f
        positionMs = 0
    }

    private fun tick() {
        ticker?.cancel()
        ticker = scope.launch {
            while (isActive) {
                player?.let { mp ->
                    runCatching {
                        if (mp.duration > 0) {
                            positionMs = mp.currentPosition.toLong()
                            progress = mp.currentPosition.toFloat() / mp.duration
                        }
                    }
                }
                delay(50)
            }
        }
    }
}
