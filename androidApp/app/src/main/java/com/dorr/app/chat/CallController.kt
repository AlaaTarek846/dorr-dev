package com.dorr.app.chat

import android.content.Context
import android.media.AudioManager
import android.util.Log
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.CallDto
import com.dorr.app.network.JoinDto
import com.dorr.app.network.ProfileDto
import com.dorr.app.network.apiFailure
import com.google.gson.Gson
import io.livekit.android.LiveKit
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
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

/** Where a call is. */
enum class CallPhase { Incoming, Outgoing, Connecting, Active, Ended }

/** Someone's video in the call (their camera track), keyed by their participant key ("user:7"). */
data class CallVideo(val key: String, val track: VideoTrack, val isLocal: Boolean)

/**
 * The one call this phone can be in (app-wide — a call rings over any screen). It drives the
 * server's ringing state machine (start / accept / decline / leave) and the LiveKit room that
 * carries the audio and video. The UI (CallOverlay) only reads this object's state.
 */
object CallController {
    private const val TAG = "CallController"
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private val gson = Gson()

    var phase by mutableStateOf<CallPhase?>(null)
        private set
    var call by mutableStateOf<CallDto?>(null)
        private set
    /** Who the call is with (the other person, or the group). */
    var title by mutableStateOf("")
        private set
    var avatar by mutableStateOf<String?>(null)
        private set
    var peerKey by mutableStateOf<String?>(null)
        private set
    var isVideo by mutableStateOf(false)
        private set
    var micOn by mutableStateOf(true)
        private set
    var cameraOn by mutableStateOf(false)
        private set
    var speakerOn by mutableStateOf(false)
        private set
    var connectedAt by mutableStateOf<Long?>(null)
        private set
    var error by mutableStateOf<String?>(null)

    val videos = mutableStateListOf<CallVideo>()

    var room: Room? = null
        private set
    private var appContext: Context? = null
    private var roomJob: Job? = null

    fun attach(context: Context) {
        appContext = context.applicationContext
        scope.launch { ChatRealtime.events.collect { onEvent(it) } }
    }

    private fun auth() = "Bearer ${AuthSession.token.orEmpty()}"

    // ------------------------------------------------------------------ actions

    fun start(conversationId: String, video: Boolean, title: String, avatar: String?, peerKey: String?) {
        if (phase != null && phase != CallPhase.Ended) return
        this.title = title
        this.avatar = avatar
        this.peerKey = peerKey
        isVideo = video
        cameraOn = video
        speakerOn = video
        micOn = true
        error = null
        phase = CallPhase.Outgoing
        scope.launch {
            try {
                val session = ApiClient.chat.startCall(auth(), conversationId, mapOf("type" to if (video) "video" else "audio")).data ?: error("no session")
                call = session.call
                // A group call already running: we joined it straight away.
                if (session.call.status == "ongoing") phase = CallPhase.Connecting
                session.join?.let { connect(it) }
            } catch (e: Exception) {
                error = e.apiFailure().message
                end(localOnly = true)
            }
        }
    }

    fun accept() {
        val current = call ?: return
        phase = CallPhase.Connecting
        scope.launch {
            try {
                val session = ApiClient.chat.acceptCall(auth(), current.id).data ?: error("no session")
                call = session.call
                session.join?.let { connect(it) }
            } catch (e: Exception) {
                error = e.apiFailure().message
                end(localOnly = true)
            }
        }
    }

    fun decline() {
        val current = call ?: return
        scope.launch { runCatching { ApiClient.chat.declineCall(auth(), current.id) } }
        end(localOnly = true)
    }

    fun hangUp() {
        val current = call
        if (current != null) scope.launch { runCatching { ApiClient.chat.leaveCall(auth(), current.id) } }
        end(localOnly = true)
    }

    fun toggleMic() {
        micOn = !micOn
        scope.launch { runCatching { room?.localParticipant?.setMicrophoneEnabled(micOn) } }
    }

    fun toggleCamera() {
        cameraOn = !cameraOn
        scope.launch {
            runCatching { room?.localParticipant?.setCameraEnabled(cameraOn) }
            refreshVideos()
        }
    }

    fun flipCamera() {
        val track = room?.localParticipant?.getTrackPublication(Track.Source.CAMERA)?.track as? LocalVideoTrack ?: return
        runCatching { track.switchCamera() }
    }

    fun toggleSpeaker() {
        speakerOn = !speakerOn
        applySpeaker()
    }

    // ------------------------------------------------------------------ LiveKit

    private suspend fun connect(join: JoinDto) {
        val context = appContext ?: return
        val r = room ?: LiveKit.create(context).also { room = it }
        roomJob?.cancel()
        roomJob = scope.launch {
            r.events.collect { event ->
                when (event) {
                    is RoomEvent.TrackSubscribed, is RoomEvent.TrackUnsubscribed, is RoomEvent.TrackMuted, is RoomEvent.TrackUnmuted,
                    is RoomEvent.ParticipantConnected, is RoomEvent.ParticipantDisconnected -> refreshVideos()
                    is RoomEvent.Disconnected -> if (phase == CallPhase.Active) end(localOnly = true)
                    else -> Unit
                }
            }
        }
        try {
            r.connect(join.url, join.token)
            r.localParticipant.setMicrophoneEnabled(micOn)
            if (isVideo && cameraOn) r.localParticipant.setCameraEnabled(true)
            applySpeaker()
            // The caller is in the room already but still "Calling…" until someone answers.
            if (phase != CallPhase.Outgoing) {
                phase = CallPhase.Active
                connectedAt = System.currentTimeMillis()
            }
            refreshVideos()
        } catch (e: Exception) {
            Log.w(TAG, "LiveKit connect failed", e)
            error = e.message
            hangUp()
        }
    }

    private fun refreshVideos() {
        val r = room ?: return
        val list = mutableListOf<CallVideo>()
        r.remoteParticipants.values.forEach { p ->
            (p.getTrackPublication(Track.Source.CAMERA)?.track as? VideoTrack)?.let { t ->
                if (p.getTrackPublication(Track.Source.CAMERA)?.muted != true) list += CallVideo(keyOf(p), t, false)
            }
        }
        (r.localParticipant.getTrackPublication(Track.Source.CAMERA)?.track as? VideoTrack)?.let { t ->
            if (cameraOn) list += CallVideo(keyOf(r.localParticipant), t, true)
        }
        videos.clear()
        videos.addAll(list)
    }

    private fun keyOf(p: Participant): String = p.identity?.value ?: p.sid.value

    private fun applySpeaker() {
        val audio = appContext?.getSystemService(Context.AUDIO_SERVICE) as? AudioManager ?: return
        runCatching {
            audio.mode = AudioManager.MODE_IN_COMMUNICATION
            @Suppress("DEPRECATION")
            audio.isSpeakerphoneOn = speakerOn
        }
    }

    private fun end(localOnly: Boolean) {
        phase = CallPhase.Ended
        roomJob?.cancel()
        runCatching { room?.disconnect() }
        runCatching { room?.release() }
        room = null
        videos.clear()
        connectedAt = null
        scope.launch {
            delay(1400) // show "call ended" for a moment
            if (phase == CallPhase.Ended) {
                phase = null
                call = null
            }
        }
    }

    // ------------------------------------------------------------------ real-time

    private fun onEvent(event: ChatEvent) {
        val data = event.data
        when (event.name) {
            "chat.call.ringing" -> {
                if (phase != null && phase != CallPhase.Ended) return // busy: the caller hears it ring out
                val dto = runCatching { gson.fromJson(data, CallDto::class.java) }.getOrNull() ?: return
                val caller = runCatching { gson.fromJson(data.getAsJsonObject("caller"), ProfileDto::class.java) }.getOrNull()
                call = dto
                isVideo = dto.type == "video"
                cameraOn = isVideo
                speakerOn = isVideo
                micOn = true
                title = if (dto.conversationType == "group") dto.groupName.orEmpty() else caller?.name ?: dto.initiator?.name.orEmpty()
                avatar = caller?.avatar
                peerKey = caller?.key
                phase = CallPhase.Incoming
            }
            "chat.call.accepted" -> if (call?.id == data.get("call_id")?.asString && (phase == CallPhase.Outgoing || phase == CallPhase.Connecting)) {
                phase = CallPhase.Active
                if (connectedAt == null) connectedAt = System.currentTimeMillis()
            }
            "chat.call.ended", "chat.call.declined" -> {
                if (call?.id != data.get("call_id")?.asString) return
                val status = data.get("status")?.asString
                if (event.name == "chat.call.ended" || status == "declined") end(localOnly = true)
            }
        }
    }

    /** Ticks once a second for the call timer. */
    fun elapsedSeconds(): Long = connectedAt?.let { (System.currentTimeMillis() - it) / 1000 } ?: 0

    fun keepTicking(onTick: () -> Unit): Job = scope.launch {
        while (isActive) {
            onTick()
            delay(1000)
        }
    }
}
