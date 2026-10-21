package com.dorr.app.ui.screens.rides

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.media.Ringtone
import android.media.RingtoneManager
import android.util.Log
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.CallEnd
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.MicOff
import androidx.compose.material.icons.rounded.VolumeUp
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import com.dorr.app.R
import com.twilio.audioswitch.AudioDevice
import io.livekit.android.LiveKit
import io.livekit.android.audio.AudioSwitchHandler
import io.livekit.android.events.RoomEvent
import io.livekit.android.events.collect
import io.livekit.android.room.Room
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/*
 * In-app voice call between the rider and the captain of a ride (docs/rides_tasks.md O-09; X-13 decided:
 * LiveKit, like the chat's calls). The server rings the other side by push and broadcasts each change on the
 * ride; the call itself is a two-person LiveKit room. «Answered» is the other person arriving in the room and
 * «over» is them leaving — LiveKit tells us, so nothing asks the server on a timer. The ring stops by the
 * phone's own clock after [RING_MS].
 */

internal enum class RideCallPhase { Incoming, Calling, Active, Ended }

/** Where to join: the server's call id and its LiveKit room token. */
internal data class RideCallJoin(val callUuid: String, val url: String, val token: String)

internal object RideCallSession {
    private const val TAG = "RideCall"
    private const val RING_MS = 45_000L
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)

    var phase by mutableStateOf<RideCallPhase?>(null)
        private set
    var peerName by mutableStateOf("")
        private set
    var muted by mutableStateOf(false)
        private set
    var speaker by mutableStateOf(false)
        private set
    var activeSince by mutableStateOf<Long?>(null)
        private set
    var error by mutableStateOf<String?>(null)

    var rideUuid: String? = null
        private set
    var callUuid: String? = null
        private set

    private var room: Room? = null
    private var roomJob: Job? = null
    private var ringJob: Job? = null
    private var ringtone: Ringtone? = null
    private var endApi: (suspend (String, String) -> Unit)? = null

    /** O-68: how to answer a call that isn't a ride's (a bus ticket's) — null: the ride's own. */
    var acceptApi: (suspend (String, String) -> RideCallJoin)? = null
        private set

    /** I call: the server makes the room and rings them; I wait in the room until they arrive. */
    fun call(context: Context, ride: String, peer: String, start: suspend () -> RideCallJoin, end: suspend (String, String) -> Unit) {
        if (phase != null) return
        reset(ride, peer, end)
        phase = RideCallPhase.Calling
        scope.launch {
            val join = runCatching { start() }.getOrElse { error = it.message; finish(); return@launch }
            callUuid = join.callUuid
            ringFor(RideCallPhase.Calling)
            connect(context, join)
        }
    }

    /** They call me: ring until I answer, decline, or the ring runs out. */
    fun incoming(context: Context, ride: String, call: String, peer: String, end: suspend (String, String) -> Unit, accept: (suspend (String, String) -> RideCallJoin)? = null) {
        if (phase != null || callUuid == call) return
        reset(ride, peer, end)
        acceptApi = accept
        callUuid = call
        phase = RideCallPhase.Incoming
        ringtone = runCatching { RingtoneManager.getRingtone(context, RingtoneManager.getDefaultUri(RingtoneManager.TYPE_RINGTONE))?.also { it.play() } }.getOrNull()
        ringFor(RideCallPhase.Incoming)
    }

    fun accept(context: Context, accept: suspend () -> RideCallJoin) {
        if (phase != RideCallPhase.Incoming) return
        stopRinging()
        scope.launch {
            val join = runCatching { accept() }.getOrElse { error = it.message; finish(); return@launch }
            connect(context, join)
            markActive()
        }
    }

    /** Decline, cancel my ring, or hang up — the server hears it and tells the other side. */
    fun hangUp() {
        val ride = rideUuid
        val call = callUuid
        val end = endApi
        if (ride != null && call != null && end != null) scope.launch { runCatching { end(ride, call) } }
        finish()
    }

    /** The server says the call is over (they hung up / declined, or the ride moved on). */
    fun endedElsewhere(call: String?) {
        if (phase != null && phase != RideCallPhase.Ended && (call == null || call == callUuid)) finish()
    }

    fun toggleMute() {
        muted = !muted
        scope.launch { runCatching { room?.localParticipant?.setMicrophoneEnabled(!muted) } }
    }

    fun toggleSpeaker() {
        speaker = !speaker
        applySpeaker()
    }

    private fun reset(ride: String, peer: String, end: suspend (String, String) -> Unit) {
        rideUuid = ride
        callUuid = null
        peerName = peer
        muted = false
        speaker = false
        activeSince = null
        error = null
        endApi = end
        acceptApi = null
    }

    private fun ringFor(during: RideCallPhase) {
        ringJob?.cancel()
        ringJob = scope.launch {
            delay(RING_MS)
            if (phase == during) hangUp()
        }
    }

    private suspend fun connect(context: Context, join: RideCallJoin) {
        val r = room ?: LiveKit.create(context.applicationContext).also { room = it }
        roomJob?.cancel()
        roomJob = scope.launch {
            r.events.collect { event ->
                when (event) {
                    // They're in the room: the call is answered.
                    is RoomEvent.ParticipantConnected -> markActive()
                    // They left: the call is over.
                    is RoomEvent.ParticipantDisconnected -> if (phase == RideCallPhase.Active) hangUp()
                    is RoomEvent.Disconnected -> if (phase != null && phase != RideCallPhase.Ended) finish()
                    else -> Unit
                }
            }
        }
        try {
            r.connect(join.url, join.token)
            r.localParticipant.setMicrophoneEnabled(!muted)
            applySpeaker()
            if (r.remoteParticipants.isNotEmpty()) markActive()
        } catch (e: Exception) {
            Log.w(TAG, "LiveKit connect failed", e)
            error = e.message
            hangUp()
        }
    }

    private fun markActive() {
        if (phase == RideCallPhase.Active) return
        ringJob?.cancel()
        stopRinging()
        phase = RideCallPhase.Active
        activeSince = System.currentTimeMillis()
    }

    private fun applySpeaker() {
        val handler = room?.audioHandler as? AudioSwitchHandler ?: return
        val devices = handler.availableAudioDevices
        val target = if (speaker) devices.firstOrNull { it is AudioDevice.Speakerphone }
        else devices.firstOrNull { it is AudioDevice.BluetoothHeadset } ?: devices.firstOrNull { it is AudioDevice.WiredHeadset } ?: devices.firstOrNull { it is AudioDevice.Earpiece }
        if (target != null && handler.selectedAudioDevice != target) handler.selectDevice(target)
    }

    private fun stopRinging() {
        runCatching { ringtone?.stop() }
        ringtone = null
    }

    private fun finish() {
        ringJob?.cancel()
        stopRinging()
        roomJob?.cancel()
        room?.let { r -> runCatching { r.disconnect() }; runCatching { r.release() } }
        room = null
        phase = RideCallPhase.Ended
        scope.launch {
            delay(1500)
            if (phase == RideCallPhase.Ended) {
                phase = null
                callUuid = null
            }
        }
    }
}

/** The call screen over everything: who, the state, the time; mute, speaker, hang up — or answer / decline. */
@Composable
internal fun RideCallOverlay(onAccept: () -> Unit) {
    val phase = RideCallSession.phase ?: return
    val context = LocalContext.current
    var pendingAccept by remember { mutableStateOf(false) }
    val mic = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { ok -> if (ok && pendingAccept) onAccept(); pendingAccept = false }
    BackHandler {}
    val pulse = rememberInfiniteTransition(label = "call")
    val p by pulse.animateFloat(0f, 1f, infiniteRepeatable(tween(1800, easing = LinearEasing)), label = "ring")
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(phase) { while (phase == RideCallPhase.Active) { delay(1000); now = System.currentTimeMillis() } }

    Box(Modifier.fillMaxSize().background(Brush.verticalGradient(listOf(Color(0xFF001B53), Color(0xFF0B2E7A), Color(0xFF12224A)))).clickable(enabled = false) {}) {
        Column(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding().padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Spacer(Modifier.height(48.dp))
            Box(Modifier.size(180.dp), contentAlignment = Alignment.Center) {
                if (phase == RideCallPhase.Incoming || phase == RideCallPhase.Calling) {
                    Canvas(Modifier.fillMaxSize()) {
                        for (i in 0..2) {
                            val f = (p + i / 3f) % 1f
                            drawCircle(Color.White.copy(alpha = (1f - f) * 0.35f), radius = size.minDimension / 2 * (0.45f + f * 0.55f), style = Stroke(width = 4f))
                        }
                    }
                }
                Box(Modifier.size(96.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.16f)), contentAlignment = Alignment.Center) {
                    Text(RideCallSession.peerName.take(1).ifEmpty { "?" }, color = Color.White, fontWeight = FontWeight.Black, fontSize = 40.sp)
                }
            }
            Spacer(Modifier.height(18.dp))
            Text(RideCallSession.peerName, color = Color.White, fontWeight = FontWeight.Black, fontSize = 26.sp)
            Spacer(Modifier.height(6.dp))
            Text(
                when (phase) {
                    RideCallPhase.Incoming -> stringResource(R.string.rc_incoming)
                    RideCallPhase.Calling -> stringResource(R.string.rc_calling)
                    RideCallPhase.Ended -> RideCallSession.error ?: stringResource(R.string.rc_ended)
                    RideCallPhase.Active -> RideCallSession.activeSince?.let { ((now - it) / 1000).let { s -> String.format("%d:%02d", s / 60, s % 60) } } ?: ""
                },
                color = Color.White.copy(alpha = 0.8f), fontSize = 15.sp,
            )
            Text(stringResource(R.string.rc_private_note), color = Color.White.copy(alpha = 0.5f), fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
            Spacer(Modifier.weight(1f))
            when (phase) {
                RideCallPhase.Incoming -> Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
                    CallButton(Icons.Rounded.CallEnd, stringResource(R.string.rc_decline), Color(0xFFE53935)) { RideCallSession.hangUp() }
                    CallButton(Icons.Rounded.Call, stringResource(R.string.rc_answer), Color(0xFF2E7D32)) {
                        if (ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) onAccept()
                        else { pendingAccept = true; mic.launch(Manifest.permission.RECORD_AUDIO) }
                    }
                }
                RideCallPhase.Ended -> Unit
                else -> Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
                    CallButton(if (RideCallSession.muted) Icons.Rounded.MicOff else Icons.Rounded.Mic, stringResource(R.string.rc_mute), if (RideCallSession.muted) Color.White else Color.White.copy(alpha = 0.18f), dark = RideCallSession.muted) { RideCallSession.toggleMute() }
                    CallButton(Icons.Rounded.CallEnd, stringResource(R.string.rc_hang_up), Color(0xFFE53935)) { RideCallSession.hangUp() }
                    CallButton(Icons.Rounded.VolumeUp, stringResource(R.string.rc_speaker), if (RideCallSession.speaker) Color.White else Color.White.copy(alpha = 0.18f), dark = RideCallSession.speaker) { RideCallSession.toggleSpeaker() }
                }
            }
            Spacer(Modifier.height(24.dp))
        }
    }
}

@Composable
private fun CallButton(icon: ImageVector, label: String, bg: Color, dark: Boolean = false, onClick: () -> Unit) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Box(Modifier.size(68.dp).clip(CircleShape).background(bg).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
            Icon(icon, label, tint = if (dark) Color(0xFF001B53) else Color.White, modifier = Modifier.size(30.dp))
        }
        Text(label, color = Color.White.copy(alpha = 0.85f), fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
    }
}

/** O-09: call the ride's captain inside the app. */
internal fun startRideCall(context: Context, ride: com.dorr.app.network.RideDto) {
    RideCallSession.call(
        context, ride.uuid, ride.driver?.name.orEmpty(),
        start = { com.dorr.app.network.ApiClient.rides.startCall(ridesAuth(), ride.uuid).data!!.let { RideCallJoin(it.uuid, it.join.url, it.join.token) } },
        end = { r, c -> com.dorr.app.network.ApiClient.rides.endCall(ridesAuth(), r, c) },
    )
}
