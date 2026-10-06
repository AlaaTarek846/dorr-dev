package com.dorr.app.chat

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.util.Log
import androidx.core.content.ContextCompat
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
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

/** Where a call is. */
enum class CallPhase { Incoming, Outgoing, Connecting, Active, Ended }

/** Someone's video in the call (their camera track), keyed by their participant key ("user:7"). */
data class CallVideo(val key: String, val track: VideoTrack, val isLocal: Boolean)

/** Someone in the call as the group grid shows them: their camera when it's on, else their photo. */
data class CallMember(
    val key: String,
    val name: String?,
    val avatar: String?,
    val video: CallVideo?,
    val micOn: Boolean,
    val speaking: Boolean,
    val isLocal: Boolean,
)

/**
 * The one call this phone can be in (app-wide — a call rings over any screen). It drives the
 * server's ringing state machine (start / accept / decline / leave) and the LiveKit room that
 * carries the audio and video. The UI (CallOverlay) only reads this object's state.
 */
object CallController {
    private const val TAG = "CallController"

    /** A little past the server's ring time (chat.call_ring_timeout_seconds = 45). */
    private const val RING_TIMEOUT_MS = 50_000L
    private const val CONNECT_TIMEOUT_MS = 25_000L
    private const val ALONE_TIMEOUT_MS = 20_000L
    private const val POLL_MS = 5_000L
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private val gson = Gson()

    var phase by mutableStateOf<CallPhase?>(null)
        private set

    /**
     * Every phase change goes through here, so the sounds follow the call: the ringtone while it
     * rings in the open app, the ring-back tone while I'm calling, silence otherwise — and the
     * ringing notification disappears as soon as the call is answered, declined or over.
     */
    private fun updatePhase(next: CallPhase?) {
        val changed = phase != next
        phase = next
        if (changed) watch(next)
        val ctx = appContext ?: return
        when (next) {
            CallPhase.Incoming -> if (ChatRealtime.inForeground) Ringer.startIncoming(ctx)
            CallPhase.Outgoing -> Ringer.startRingback()
            else -> {
                Ringer.stop()
                CallNotifications.cancel(ctx)
            }
        }
    }
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

    /** Everyone in the room, me last — what a group call shows as a grid. */
    val members = mutableStateListOf<CallMember>()

    var room: Room? = null
        private set
    private var appContext: Context? = null
    private var roomJob: Job? = null
    private var watchJob: Job? = null
    private var aloneJob: Job? = null

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
        updatePhase(CallPhase.Outgoing)
        scope.launch {
            try {
                val session = ApiClient.chat.startCall(auth(), conversationId, mapOf("type" to if (video) "video" else "audio")).data ?: error("no session")
                call = session.call
                // A group call already running: we joined it straight away.
                if (session.call.status == "ongoing") updatePhase(CallPhase.Connecting)
                session.join?.let { connect(it) }
            } catch (e: Exception) {
                error = e.apiFailure().message
                end(localOnly = true)
            }
        }
    }

    /**
     * Opened from a call notification (the app was closed, so the real-time "ringing" was missed):
     * if the call still rings, show the incoming screen for it.
     */
    /** The call is over on the other side (caller hung up / ring timed out) — close the ringing page. */
    fun onRemoteEnded(callId: String) {
        if (call?.id == callId && phase != null && phase != CallPhase.Ended) scope.launch { end(localOnly = true) }
    }

    /** From the ringing notification: load it and, for "Answer", pick up straight away (when the mic is allowed). */
    fun loadIncoming(callId: String, autoAnswer: Boolean) {
        loadIncoming(callId)
        if (!autoAnswer) return
        scope.launch {
            // Wait for the call to load, then answer.
            repeat(50) {
                if (phase == CallPhase.Incoming && call?.id == callId) {
                    val ctx = appContext ?: return@launch
                    if (androidx.core.content.ContextCompat.checkSelfPermission(ctx, android.Manifest.permission.RECORD_AUDIO) == android.content.pm.PackageManager.PERMISSION_GRANTED) accept()
                    return@launch
                }
                delay(100)
            }
        }
    }

    fun loadIncoming(callId: String) {
        if (phase != null && phase != CallPhase.Ended) return
        scope.launch {
            val dto = runCatching { ApiClient.chat.call(auth(), callId).data }.getOrNull() ?: return@launch
            if (dto.status != "ringing" || dto.isOutgoing) return@launch
            call = dto
            isVideo = dto.type == "video"
            cameraOn = isVideo
            speakerOn = isVideo
            micOn = true
            title = if (dto.conversationType == "group") dto.groupName.orEmpty() else dto.initiator?.name.orEmpty()
            avatar = dto.initiator?.avatar
            peerKey = dto.initiator?.key
            updatePhase(CallPhase.Incoming)
        }
    }

    fun accept() {
        val current = call ?: return
        updatePhase(CallPhase.Connecting)
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
        scope.launch {
            applyMic()
            refreshVideos()
        }
    }

    /** Whether this phone may use the camera — the overlay asks for it before turning it on. */
    fun cameraAllowed(): Boolean = appContext?.let {
        ContextCompat.checkSelfPermission(it, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED
    } ?: false

    fun toggleCamera() {
        if (!cameraOn && !cameraAllowed()) return
        cameraOn = !cameraOn
        scope.launch {
            applyCamera()
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

    /** The account signed out on this phone: whatever call there is ends with it. */
    fun signedOut() {
        when (phase) {
            CallPhase.Incoming -> decline()
            CallPhase.Outgoing, CallPhase.Connecting, CallPhase.Active -> hangUp()
            else -> Unit
        }
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
                    is RoomEvent.ParticipantConnected, is RoomEvent.ParticipantDisconnected,
                    is RoomEvent.ActiveSpeakersChanged -> refreshVideos()
                    // The room dropped us (network gone for good, kicked, room closed): the call is
                    // over in every phase, not only once it was answered.
                    is RoomEvent.Disconnected -> if (phase != null && phase != CallPhase.Ended) hangUp()
                    else -> Unit
                }
                if (event is RoomEvent.ParticipantConnected || event is RoomEvent.ParticipantDisconnected) watchAlone()
            }
        }
        try {
            r.connect(join.url, join.token)
        } catch (e: Exception) {
            Log.w(TAG, "LiveKit connect failed", e)
            error = e.message
            hangUp()
            return
        }
        // The microphone and the camera each fail on their own (no permission, camera busy…)
        // without taking the whole call down — the buttons then show what really happened.
        applyMic()
        if (isVideo && cameraOn) applyCamera()
        applySpeaker()
        // The caller is in the room already but still "Calling…" until someone answers.
        if (phase != CallPhase.Outgoing) {
            updatePhase(CallPhase.Active)
            connectedAt = System.currentTimeMillis()
        }
        refreshVideos()
    }

    /** Mute / unmute for real, and check it took: the publication itself is muted as a fallback. */
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

        val people = r.remoteParticipants.values.map { p ->
            val key = keyOf(p)
            CallMember(key, p.name, avatarOf(p), list.firstOrNull { !it.isLocal && it.key == key }, p.isMicrophoneEnabled(), p.isSpeaking, isLocal = false)
        }.sortedBy { it.name.orEmpty() }
        val me = r.localParticipant
        members.clear()
        members.addAll(people)
        members.add(CallMember(keyOf(me), me.name, avatarOf(me), list.firstOrNull { it.isLocal }, micOn, me.isSpeaking, isLocal = true))
    }

    /** The token carries `{"avatar": …}` as participant metadata (CallService::join). */
    private fun avatarOf(p: Participant): String? = runCatching {
        gson.fromJson(p.metadata, com.google.gson.JsonObject::class.java)?.get("avatar")?.takeIf { !it.isJsonNull }?.asString
    }.getOrNull()

    private fun keyOf(p: Participant): String = p.identity?.value ?: p.sid.value

    /**
     * LiveKit routes the call's audio itself (AudioSwitch): setting the AudioManager by hand is
     * undone by it, so the device is picked through it — the loudspeaker, or else a headset when
     * one is plugged in / paired, or else the earpiece. Its device list fills in a moment after
     * the room connects, so the choice is retried briefly.
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

    // ------------------------------------------------------------------ never stuck

    /**
     * A call can't hang in a ringing or connecting state: the server is asked every few seconds
     * (a missed real-time event — screen off, network blip — would leave the page up forever),
     * and each phase has its own limit.
     */
    private fun watch(next: CallPhase?) {
        watchJob?.cancel()
        if (next != CallPhase.Incoming && next != CallPhase.Outgoing && next != CallPhase.Connecting) return
        val limit = when (next) {
            CallPhase.Connecting -> CONNECT_TIMEOUT_MS
            else -> RING_TIMEOUT_MS
        }
        watchJob = scope.launch {
            val started = System.currentTimeMillis()
            while (isActive && phase == next) {
                delay(POLL_MS)
                if (phase != next) return@launch
                val id = call?.id
                val status = id?.let { runCatching { ApiClient.chat.call(auth(), it).data?.status }.getOrNull() }
                if (phase != next) return@launch
                when {
                    status != null && status != "ringing" && status != "ongoing" -> {
                        end(localOnly = true)
                        return@launch
                    }
                    // Answered while we missed the event: the caller is in the room already.
                    status == "ongoing" && next == CallPhase.Outgoing && room != null -> {
                        updatePhase(CallPhase.Active)
                        if (connectedAt == null) connectedAt = System.currentTimeMillis()
                        return@launch
                    }
                }
                if (System.currentTimeMillis() - started >= limit) {
                    when (next) {
                        CallPhase.Incoming -> end(localOnly = true) // it rang out; the caller's side closes it
                        CallPhase.Connecting -> {
                            error = appContext?.getString(com.dorr.app.R.string.ch_call_failed)
                            hangUp()
                        }
                        else -> hangUp() // nobody answered
                    }
                    return@launch
                }
            }
        }
    }

    /**
     * A one-to-one call whose other side vanished from the room (app killed, phone off) without
     * hanging up: after a grace period for them to come back, the call is over.
     */
    private fun watchAlone() {
        aloneJob?.cancel()
        val r = room ?: return
        if (phase != CallPhase.Active || call?.conversationType == "group" || r.remoteParticipants.isNotEmpty()) return
        aloneJob = scope.launch {
            delay(ALONE_TIMEOUT_MS)
            if (phase == CallPhase.Active && room === r && r.remoteParticipants.isEmpty()) hangUp()
        }
    }

    private fun end(localOnly: Boolean) {
        updatePhase(CallPhase.Ended)
        watchJob?.cancel()
        aloneJob?.cancel()
        roomJob?.cancel()
        runCatching { room?.disconnect() }
        runCatching { room?.release() }
        room = null
        videos.clear()
        members.clear()
        connectedAt = null
        scope.launch {
            delay(1400) // show "call ended" for a moment
            if (phase == CallPhase.Ended) {
                updatePhase(null)
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
                updatePhase(CallPhase.Incoming)
            }
            "chat.call.accepted" -> if (call?.id == data.get("call_id")?.asString && (phase == CallPhase.Outgoing || phase == CallPhase.Connecting)) {
                updatePhase(CallPhase.Active)
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
