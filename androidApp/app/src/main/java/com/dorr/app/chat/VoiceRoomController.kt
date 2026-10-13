package com.dorr.app.chat

import android.content.Context
import android.util.Log
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.VoiceRoomDto
import com.google.gson.JsonObject
import io.livekit.android.LiveKit
import io.livekit.android.audio.AudioSwitchHandler
import com.twilio.audioswitch.AudioDevice
import io.livekit.android.events.RoomEvent
import io.livekit.android.events.collect
import io.livekit.android.room.Room
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

/**
 * The standing voice room I'm in (spec 91) — one at a time, on LiveKit like calls. It stays on
 * while I move around the chat (the mini bar), sends a heartbeat every 30 s (the server sweeps
 * the silent), follows `chat.voice.updated` (a host let me speak, put me out…) and the room's own
 * speaking events (who's talking, glowing).
 */
object VoiceRoomController {
    private const val TAG = "VoiceRoom"
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private var appContext: Context? = null
    private var livekit: Room? = null
    private var roomJob: Job? = null
    private var pingJob: Job? = null

    /** The room I'm in (null: none). */
    var room by mutableStateOf<VoiceRoomDto?>(null)
        private set
    var chatTitle by mutableStateOf<String?>(null)
        private set
    var connecting by mutableStateOf(false)
        private set
    var micOn by mutableStateOf(false)
        private set
    /** The loudspeaker (off: the earpiece, or a headset when one is on). */
    var speakerOn by mutableStateOf(false)
        private set
    /** Who is talking right now (participant keys). */
    var speaking by mutableStateOf<Set<String>>(emptySet())
        private set
    var error by mutableStateOf<String?>(null)

    private fun auth() = "Bearer ${AuthSession.token.orEmpty()}"

    private fun myKey() = "user:${AuthSession.user?.id ?: 0}"

    fun attach(context: Context) {
        if (appContext != null) return
        appContext = context.applicationContext
        scope.launch { ChatRealtime.events.collect { e -> if (e.name == "chat.voice.updated") onUpdated(e.data) } }
    }

    fun isIn(conversationId: String): Boolean = room?.conversationId == conversationId

    suspend fun join(context: Context, conversationId: String, title: String?): Boolean {
        attach(context)
        if (CallController.phase != null) {
            error = context.getString(com.dorr.app.R.string.vr_in_call)
            return false
        }
        if (isIn(conversationId)) return true
        leave()
        connecting = true
        val joined = runCatching { ApiClient.voice.join(auth(), conversationId).data }.getOrElse {
            error = it.message
            connecting = false
            return false
        } ?: run { connecting = false; return false }
        room = joined.room
        chatTitle = title
        val r = LiveKit.create(context.applicationContext).also { livekit = it }
        roomJob = scope.launch {
            r.events.collect { event ->
                when (event) {
                    is RoomEvent.ActiveSpeakersChanged -> speaking = event.speakers.mapNotNull { it.identity?.value }.toSet()
                    is RoomEvent.Disconnected -> if (room != null) clear()
                    else -> Unit
                }
            }
        }
        return try {
            r.connect(joined.join.url, joined.join.token)
            // A speaker comes in muted — they unmute themselves.
            micOn = false
            applySpeaker()
            // Keeps sound and microphone alive when I leave the app; its notification is the room minimised.
            appContext?.let { VoiceRoomService.update(it) }
            pingJob = scope.launch {
                while (isActive) {
                    delay(30_000)
                    room?.id?.let { id -> runCatching { ApiClient.voice.ping(auth(), id) } }
                }
            }
            connecting = false
            true
        } catch (e: Exception) {
            Log.w(TAG, "LiveKit connect failed", e)
            error = e.message
            leave()
            false
        }
    }

    fun toggleMic() {
        val r = livekit ?: return
        if (room?.me?.role == "listener") return
        scope.launch {
            val want = !micOn
            runCatching { r.localParticipant.setMicrophoneEnabled(want) }.onFailure { Log.w(TAG, "mic", it) }
            micOn = r.localParticipant.isMicrophoneEnabled()
            appContext?.let { VoiceRoomService.update(it) }
        }
    }

    fun toggleSpeaker() {
        speakerOn = !speakerOn
        applySpeaker()
    }

    /**
     * LiveKit routes the room's sound itself (AudioSwitch), like calls: the device is picked
     * through it. Its device list fills in a moment after connecting, so the choice is retried.
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
        val handler = livekit?.audioHandler as? AudioSwitchHandler ?: return false
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

    fun raiseHand(raised: Boolean) {
        val id = room?.id ?: return
        scope.launch { runCatching { ApiClient.voice.hand(auth(), id, JsonObject().apply { addProperty("raised", raised) }).data }.getOrNull()?.let { room = it } }
    }

    fun setRole(participantId: Int, role: String) {
        val id = room?.id ?: return
        scope.launch { runCatching { ApiClient.voice.role(auth(), id, participantId, JsonObject().apply { addProperty("role", role) }).data }.getOrNull()?.let { room = it } }
    }

    fun remove(participantId: Int) {
        val id = room?.id ?: return
        scope.launch { runCatching { ApiClient.voice.remove(auth(), id, participantId).data }.getOrNull()?.let { room = it } }
    }

    fun setPolicy(policy: String) {
        val id = room?.id ?: return
        scope.launch { runCatching { ApiClient.voice.update(auth(), id, JsonObject().apply { addProperty("speak_policy", policy) }).data }.getOrNull()?.let { room = it } }
    }

    fun leave() {
        val id = room?.id
        clear()
        if (id != null) scope.launch { runCatching { ApiClient.voice.leave(auth(), id) } }
    }

    private fun clear() {
        pingJob?.cancel()
        roomJob?.cancel()
        runCatching { livekit?.disconnect() }
        runCatching { livekit?.release() }
        livekit = null
        room = null
        micOn = false
        appContext?.let { VoiceRoomService.stop(it) }
        speaking = emptySet()
        connecting = false
    }

    /** Someone came, left, raised a hand, or a host changed my role: read the room again. */
    private fun onUpdated(data: JsonObject) {
        val current = room ?: return
        if (data.get("room_id")?.takeIf { it.isJsonPrimitive }?.asString != current.id) return
        scope.launch {
            val fresh = runCatching { ApiClient.voice.room(auth(), current.conversationId).data }.getOrNull() ?: return@launch
            // Put out by a host.
            if (!fresh.me.inRoom) {
                clear()
                return@launch
            }
            // Made a listener: the microphone goes off (LiveKit took the right away already).
            if (fresh.me.role == "listener" && micOn) {
                runCatching { livekit?.localParticipant?.setMicrophoneEnabled(false) }
                micOn = false
            }
            val roleChanged = fresh.me.role != current.me.role
            room = fresh
            if (roleChanged) appContext?.let { VoiceRoomService.update(it) }
        }
    }
}
