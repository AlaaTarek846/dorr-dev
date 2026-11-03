package com.dorr.freelance.session

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.util.Log
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.core.content.ContextCompat
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.ConsultationJoinDto
import com.dorr.freelance.net.ServerClock
import com.twilio.audioswitch.AudioDevice
import io.livekit.android.LiveKit
import io.livekit.android.audio.AudioSwitchHandler
import io.livekit.android.events.RoomEvent
import io.livekit.android.events.collect
import io.livekit.android.room.Room
import io.livekit.android.room.participant.Participant
import io.livekit.android.room.track.LocalVideoTrack
import io.livekit.android.room.track.Track
import io.livekit.android.room.track.VideoTrack
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** Where the session is on this phone. */
enum class SessionPhase { Connecting, Active, Ended }

/** Someone's camera in the room. */
data class SessionVideo(val key: String, val track: VideoTrack, val isLocal: Boolean)

/**
 * The consultation video session this phone is in (FL-L16) — app-wide, so it keeps going if I step to another screen. A copy, cut down
 * to two people and no ringing, of the DORR app's chat call controller (androidApp `chat/CallController`): LiveKit carries the sound and
 * the video, the server is told when I am in (`connected`) and when I leave (`leave`). The end comes from `ends_at` on the phone's clock
 * (corrected by the ticket's `server_time`) — nothing asks the server on a timer; the server also closes the room itself at the end.
 */
object SessionCallController {
    private const val TAG = "SessionCall"
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)

    var phase by mutableStateOf<SessionPhase?>(null)
        private set

    /** The consultation whose room I am in (or getting into). */
    var consultationUuid by mutableStateOf<String?>(null)
        private set
    var title by mutableStateOf("")
        private set
    var ticket by mutableStateOf<ConsultationJoinDto?>(null)
        private set

    /** When the room closes, on the server's clock (epoch ms). */
    var endsAtMs by mutableStateOf<Long?>(null)
        private set

    /** The session is being recorded (the ticket says so). */
    var recording by mutableStateOf(false)
        private set
    var otherName by mutableStateOf<String?>(null)
        private set

    /** The other side is in the room right now (from LiveKit's own participant list). */
    var otherInRoom by mutableStateOf(false)
        private set
    var micOn by mutableStateOf(true)
        private set
    var cameraOn by mutableStateOf(true)
        private set
    var flippedCamera by mutableStateOf(false)
        private set
    var speakerOn by mutableStateOf(true)
        private set
    var connectedAt by mutableStateOf<Long?>(null)
        private set

    /** Why it ended or could not start (shown once by the screen). */
    var error by mutableStateOf<String?>(null)

    /** The room's time ran out (auto-leave) — the screen says so. */
    var endedByTime by mutableStateOf(false)
        private set

    val videos = mutableStateListOf<SessionVideo>()

    var room: Room? = null
        private set
    private var appContext: Context? = null
    private var roomJob: Job? = null
    private var endJob: Job? = null

    fun attach(context: Context) {
        appContext = context.applicationContext
    }

    fun ongoing(): Boolean = phase == SessionPhase.Connecting || phase == SessionPhase.Active

    fun isIn(uuid: String): Boolean = ongoing() && consultationUuid == uuid

    // ------------------------------------------------------------------ actions

    /** Ask for a ticket and go into the room. [offline] / [generic] are the texts for a failure without the server's own message. */
    fun start(context: Context, uuid: String, title: String, offline: String, generic: String) {
        if (ongoing()) return
        appContext = context.applicationContext
        consultationUuid = uuid
        this.title = title
        error = null
        endedByTime = false
        micOn = true
        cameraOn = cameraAllowed()
        flippedCamera = false
        speakerOn = true
        updatePhase(SessionPhase.Connecting)
        scope.launch {
            when (val r = apiCall { ApiClient.consultationSessions.join(uuid) }) {
                is ApiResult.Ok -> connect(r.data)
                is ApiResult.Failed -> { error = r.error.displayMessage.ifBlank { generic }; end(tellServer = false) }
                is ApiResult.Offline -> { error = offline; end(tellServer = false) }
            }
        }
    }

    /** I leave the room (Leave, or the time is over): the server is told; coming back while time is left is fine. */
    fun leave() {
        if (phase == null || phase == SessionPhase.Ended) return
        end(tellServer = true)
    }

    fun toggleMic() {
        micOn = !micOn
        appContext?.let { SessionCallService.update(it) }
        scope.launch { applyMic(); refreshVideos() }
    }

    fun cameraAllowed(): Boolean = appContext?.let {
        ContextCompat.checkSelfPermission(it, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED
    } ?: false

    fun toggleCamera() {
        if (!cameraOn && !cameraAllowed()) return
        cameraOn = !cameraOn
        if (!cameraOn) flippedCamera = false
        scope.launch { applyCamera(); refreshVideos() }
    }

    fun flipCamera() {
        val track = room?.localParticipant?.getTrackPublication(Track.Source.CAMERA)?.track as? LocalVideoTrack ?: return
        if (runCatching { track.switchCamera() }.isSuccess) flippedCamera = !flippedCamera
    }

    fun toggleSpeaker() {
        speakerOn = !speakerOn
        applySpeaker()
    }

    /** The account signed out on this phone: the session ends with it. */
    fun signedOut() {
        if (ongoing()) leave()
    }

    /** Seconds left until the room closes, on the server's clock. */
    fun secondsLeft(): Long = endsAtMs?.let { ((it - ServerClock.nowMs()) / 1000).coerceAtLeast(0) } ?: 0

    // ------------------------------------------------------------------ LiveKit

    private suspend fun connect(join: ConsultationJoinDto) {
        val context = appContext ?: return
        ticket = join
        ServerClock.observe(join.serverTime)
        endsAtMs = ServerClock.parseInstant(join.endsAt)?.toEpochMilli()
        recording = join.recording
        otherName = join.other?.name
        otherInRoom = join.other?.inRoom == true
        val r = room ?: LiveKit.create(context).also { room = it }
        roomJob?.cancel()
        roomJob = scope.launch {
            r.events.collect { event ->
                when (event) {
                    is RoomEvent.TrackSubscribed, is RoomEvent.TrackUnsubscribed, is RoomEvent.TrackMuted, is RoomEvent.TrackUnmuted,
                    is RoomEvent.ParticipantConnected, is RoomEvent.ParticipantDisconnected -> refreshVideos()
                    // The room is gone (the server closed it at the end, or the staff did, or the network is gone for good).
                    is RoomEvent.Disconnected -> if (phase == SessionPhase.Active) end(tellServer = true)
                    else -> Unit
                }
            }
        }
        try {
            r.connect(join.url, join.token)
        } catch (e: Exception) {
            Log.w(TAG, "LiveKit connect failed", e)
            error = e.message
            end(tellServer = false)
            return
        }
        // The microphone and the camera each fail on their own (no permission, camera busy…) without taking the session down.
        applyMic()
        if (cameraOn) applyCamera()
        applySpeaker()
        connectedAt = System.currentTimeMillis()
        updatePhase(SessionPhase.Active)
        refreshVideos()
        val uuid = consultationUuid
        if (uuid != null) scope.launch { apiCall { ApiClient.consultationSessions.connected(uuid) } }
        scheduleEnd()
    }

    /** Out of the room at `ends_at` (the phone's clock, corrected) — one timer for a time already known, no request on the way. */
    private fun scheduleEnd() {
        endJob?.cancel()
        val at = endsAtMs ?: return
        endJob = scope.launch {
            delay((at - ServerClock.nowMs()).coerceAtLeast(0))
            if (ongoing()) {
                endedByTime = true
                end(tellServer = true)
            }
        }
    }

    private suspend fun applyMic() {
        val me = room?.localParticipant ?: return
        val want = micOn
        try {
            me.setMicrophoneEnabled(want)
            if (me.isMicrophoneEnabled() != want) me.getTrackPublication(Track.Source.MICROPHONE)?.muted = !want
        } catch (e: Exception) {
            Log.w(TAG, "Microphone ${if (want) "on" else "off"} failed", e)
            runCatching { me.getTrackPublication(Track.Source.MICROPHONE)?.muted = !want }
        }
        micOn = me.isMicrophoneEnabled()
    }

    private suspend fun applyCamera() {
        val me = room?.localParticipant ?: return
        if (cameraOn && !cameraAllowed()) {
            cameraOn = false
            return
        }
        try {
            me.setCameraEnabled(cameraOn)
        } catch (e: Exception) {
            Log.w(TAG, "Camera ${if (cameraOn) "on" else "off"} failed", e)
            cameraOn = false
            runCatching { me.setCameraEnabled(false) }
        }
    }

    private fun refreshVideos() {
        val r = room ?: return
        val list = mutableListOf<SessionVideo>()
        r.remoteParticipants.values.forEach { p ->
            val pub = p.getTrackPublication(Track.Source.CAMERA)
            (pub?.track as? VideoTrack)?.let { t -> if (pub.muted != true) list += SessionVideo(keyOf(p), t, false) }
        }
        (r.localParticipant.getTrackPublication(Track.Source.CAMERA)?.track as? VideoTrack)?.let { t ->
            if (cameraOn) list += SessionVideo(keyOf(r.localParticipant), t, true)
        }
        videos.clear()
        videos.addAll(list)
        otherInRoom = r.remoteParticipants.isNotEmpty()
        r.remoteParticipants.values.firstOrNull()?.name?.takeIf { it.isNotBlank() }?.let { otherName = it }
    }

    private fun keyOf(p: Participant): String = p.identity?.value ?: p.sid.value

    /**
     * LiveKit routes the audio itself (AudioSwitch): the device is picked through it — the loudspeaker, or else a headset, or else the
     * earpiece. Its device list fills in a moment after the room connects, so the choice is retried briefly.
     */
    private fun applySpeaker() {
        val want = speakerOn
        scope.launch {
            repeat(10) {
                if (selectAudioDevice(want)) return@launch
                delay(300)
            }
        }
    }

    private fun selectAudioDevice(speaker: Boolean): Boolean {
        val handler = room?.audioHandler as? AudioSwitchHandler ?: return false
        val devices = handler.availableAudioDevices
        val target = if (speaker) {
            devices.firstOrNull { it is AudioDevice.Speakerphone }
        } else {
            devices.firstOrNull { it is AudioDevice.BluetoothHeadset }
                ?: devices.firstOrNull { it is AudioDevice.WiredHeadset }
                ?: devices.firstOrNull { it is AudioDevice.Earpiece }
        } ?: return false
        return runCatching {
            if (handler.selectedAudioDevice != target) handler.selectDevice(target)
            true
        }.onFailure { Log.w(TAG, "Audio route failed", it) }.getOrDefault(false)
    }

    // ------------------------------------------------------------------ the end

    private fun updatePhase(next: SessionPhase?) {
        phase = next
        val ctx = appContext ?: return
        if (next == SessionPhase.Connecting || next == SessionPhase.Active) SessionCallService.update(ctx) else SessionCallService.stop(ctx)
    }

    private fun end(tellServer: Boolean) {
        val uuid = consultationUuid
        if (tellServer && uuid != null) scope.launch { apiCall { ApiClient.consultationSessions.leave(uuid) } }
        updatePhase(SessionPhase.Ended)
        endJob?.cancel()
        roomJob?.cancel()
        runCatching { room?.disconnect() }
        runCatching { room?.release() }
        room = null
        videos.clear()
        connectedAt = null
        otherInRoom = false
        flippedCamera = false
    }

    /** The screen closed after the end: nothing is left behind. */
    fun clear() {
        if (ongoing()) return
        phase = null
        ticket = null
        endsAtMs = null
        recording = false
        error = null
        endedByTime = false
    }
}
