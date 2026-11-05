package com.dorr.app.ui.screens.freelance

import android.Manifest
import android.app.DownloadManager
import android.content.Context
import android.content.pm.PackageManager
import android.net.Uri
import android.util.Log
import android.widget.MediaController
import android.widget.Toast
import android.widget.VideoView
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CallEnd
import androidx.compose.material.icons.rounded.Cameraswitch
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Download
import androidx.compose.material.icons.rounded.EventBusy
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.MicOff
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.ReportProblem
import androidx.compose.material.icons.rounded.Timer
import androidx.compose.material.icons.rounded.VideoChat
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material.icons.rounded.VideocamOff
import androidx.compose.material.icons.rounded.VolumeUp
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CheckboxDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.compose.ui.window.DialogProperties
import androidx.core.content.ContextCompat
import com.dorr.app.R
import com.dorr.app.chat.CallVideo
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ConsultDto
import com.dorr.app.network.ConsultJoinDto
import com.dorr.app.network.ConsultRecordingDto
import com.dorr.app.network.ConsultSessionDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.locale.LocaleAwareDialog
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.profile.settingsSurface
import com.twilio.audioswitch.AudioDevice
import io.livekit.android.LiveKit
import io.livekit.android.audio.AudioSwitchHandler
import io.livekit.android.events.RoomEvent
import io.livekit.android.events.collect
import io.livekit.android.renderer.TextureViewRenderer
import io.livekit.android.room.Room
import io.livekit.android.room.track.LocalVideoTrack
import io.livekit.android.room.track.Track
import io.livekit.android.room.track.VideoTrack
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * FL-L16, the consultation's video session on the client's side: the consent before booking, the session card on the consultation
 * (the room's opening by the phone's clock, «enter», who is in, attendance, the recorded parts with download and watch, the
 * freelancer-did-not-come complaint) and the room itself, full screen, on LiveKit like the chat's calls. Nothing asks the server on a
 * timer: the end comes from `ends_at` on the phone's clock (corrected once by `server_time`), the other side's arrival from LiveKit, and
 * the rest from the notifications (`freelance.consultation.session_*`, `recording_ready`) that reload the page once. If the app goes away
 * the server ends the stay itself; coming back while time is left is a new join.
 */

private val S_GREEN = Color(0xFF16A34A)
private val S_AMBER = Color(0xFFD97706)
private val S_RED = Color(0xFFDC2626)
private val S_SLATE = Color(0xFF64748B)

private fun ssInstant(iso: String?): Instant? = iso?.let { runCatching { Instant.parse(it) }.getOrNull() ?: runCatching { OffsetDateTime.parse(it).toInstant() }.getOrNull() }

private fun ssHm(iso: String?): String = ssInstant(iso)?.let { DateTimeFormatter.ofPattern("HH:mm", Locale.US).format(it.atZone(ZoneId.systemDefault())) }.orEmpty()

private fun ssDay(iso: String?): String = ssInstant(iso)?.let {
    val z = it.atZone(ZoneId.systemDefault())
    "${DateTimeFormatter.ofPattern("EEEE", Locale.getDefault()).format(z)} ${z.dayOfMonth} ${DateTimeFormatter.ofPattern("MMMM", Locale.getDefault()).format(z)}"
}.orEmpty()

/** "1:05:09" / "05:09" — a countdown or a length. */
internal fun ssClock(seconds: Long): String {
    val s = seconds.coerceAtLeast(0)
    return if (s >= 3600) String.format(Locale.US, "%d:%02d:%02d", s / 3600, (s % 3600) / 60, s % 60) else String.format(Locale.US, "%02d:%02d", s / 60, s % 60)
}

private fun ssSize(bytes: Long?): String? = bytes?.takeIf { it > 0 }?.let {
    if (it >= 1_048_576) String.format(Locale.US, "%.1f MB", it / 1_048_576.0) else String.format(Locale.US, "%d KB", (it / 1024).coerceAtLeast(1))
}

// ==================================================================== the consent (booking)

/** FL-L16: the required yes to the recording — booking (and a special offer's consultation) waits for it. */
@Composable
internal fun RecordingConsentRow(checked: Boolean, onChange: (Boolean) -> Unit) {
    val accent = settingsAccent()
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(if (checked) S_GREEN.copy(alpha = 0.10f) else accent.copy(alpha = 0.06f))
            .border(1.dp, if (checked) S_GREEN.copy(alpha = 0.4f) else accent.copy(alpha = 0.25f), RoundedCornerShape(14.dp))
            .clickable { onChange(!checked) }.padding(horizontal = 6.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Checkbox(checked = checked, onCheckedChange = onChange, colors = CheckboxDefaults.colors(checkedColor = S_GREEN))
        Text(stringResource(R.string.css_consent), color = settingsInk(), fontSize = 12.5.sp, lineHeight = 18.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f).padding(end = 8.dp))
    }
}

// ==================================================================== the room (one at a time, app-wide)

internal enum class ConsultSessionPhase { Joining, Live, Ended }

/**
 * The one consultation session this phone can be in. It asks the server for a ticket, connects LiveKit, says it is in, and leaves on
 * «Leave», when LiveKit drops it (the server closes the room at the end) or when the time is over by the phone's corrected clock.
 */
internal object ConsultSession {
    private const val TAG = "ConsultSession"
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)

    var uuid by mutableStateOf<String?>(null)
        private set
    var phase by mutableStateOf<ConsultSessionPhase?>(null)
        private set
    var join by mutableStateOf<ConsultJoinDto?>(null)
        private set
    /** The other side's name (from the consultation, then the ticket). */
    var otherName by mutableStateOf("")
        private set
    var otherInRoom by mutableStateOf(false)
        private set
    var micOn by mutableStateOf(true)
        private set
    var cameraOn by mutableStateOf(true)
        private set
    var speakerOn by mutableStateOf(true)
        private set
    var remote by mutableStateOf<CallVideo?>(null)
        private set
    var local by mutableStateOf<CallVideo?>(null)
        private set
    var error by mutableStateOf<String?>(null)
    /** Bumped each time the screen closes: the consultation page reads itself once more. */
    var closedCount by mutableIntStateOf(0)
        private set

    /** The server's clock minus the phone's, from the ticket's `server_time` (once per join). */
    private var offsetMs = 0L
    var room: Room? = null
        private set
    private var appContext: Context? = null
    private var roomJob: Job? = null
    private var endJob: Job? = null

    /** Now on the server's clock, by the phone's clock. */
    fun serverNow(): Long = System.currentTimeMillis() + offsetMs

    fun endsAtMs(): Long? = ssInstant(join?.endsAt)?.toEpochMilli()

    /** Into the room: a ticket, LiveKit, then «I am in». [onError] gets the server's words when it says no. */
    fun start(context: Context, consultation: String, other: String, onError: (String) -> Unit) {
        if (phase != null && phase != ConsultSessionPhase.Ended) return
        appContext = context.applicationContext
        uuid = consultation
        otherName = other
        otherInRoom = false
        micOn = true
        cameraOn = ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED
        speakerOn = true
        error = null
        join = null
        remote = null
        local = null
        phase = ConsultSessionPhase.Joining
        scope.launch {
            val ticket = try {
                ApiClient.freelanceConsultations.joinSession(auth(), consultation).data ?: error("no ticket")
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                // Not in yet (too early, over, closed…): nothing to leave — the card says why.
                val message = e.apiFailure().message ?: context.getString(R.string.fl_error_generic)
                phase = null
                uuid = null
                onError(message)
                return@launch
            }
            join = ticket
            ticket.other.name?.takeIf { it.isNotBlank() }?.let { otherName = it }
            otherInRoom = ticket.other.inRoom
            offsetMs = ssInstant(ticket.serverTime)?.toEpochMilli()?.minus(System.currentTimeMillis()) ?: 0L
            connect(ticket, onError)
        }
    }

    private suspend fun connect(ticket: ConsultJoinDto, onError: (String) -> Unit) {
        val ctx = appContext ?: return
        val r = LiveKit.create(ctx).also { room = it }
        roomJob?.cancel()
        roomJob = scope.launch {
            r.events.collect { event ->
                when (event) {
                    is RoomEvent.TrackSubscribed, is RoomEvent.TrackUnsubscribed, is RoomEvent.TrackMuted, is RoomEvent.TrackUnmuted,
                    is RoomEvent.ParticipantConnected, is RoomEvent.ParticipantDisconnected, is RoomEvent.TrackPublished -> refresh()
                    // The server closed the room (time over, or the staff), or the network is gone for good.
                    is RoomEvent.Disconnected -> if (phase == ConsultSessionPhase.Live || phase == ConsultSessionPhase.Joining) leave()
                    else -> Unit
                }
            }
        }
        try {
            r.connect(ticket.url, ticket.token)
        } catch (e: Exception) {
            Log.w(TAG, "LiveKit connect failed", e)
            error = e.message ?: ctx.getString(R.string.fl_error_generic)
            close()
            onError(error.orEmpty())
            return
        }
        runCatching { r.localParticipant.setMicrophoneEnabled(micOn) }.onFailure { micOn = false }
        if (cameraOn) runCatching { r.localParticipant.setCameraEnabled(true) }.onFailure { cameraOn = false }
        applySpeaker()
        phase = ConsultSessionPhase.Live
        refresh()
        // The server starts the attendance (and the recording) only once we are really in.
        val id = uuid ?: return
        try {
            ApiClient.freelanceConsultations.sessionConnected(auth(), id)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            val message = e.apiFailure().message ?: ctx.getString(R.string.fl_error_generic)
            error = message
            leave()
            onError(message)
            return
        }
        // Out at the end, by the phone's clock corrected by the server's.
        endJob?.cancel()
        endsAtMs()?.let { end ->
            endJob = scope.launch {
                delay((end - serverNow()).coerceAtLeast(0))
                if (phase == ConsultSessionPhase.Live) leave()
            }
        }
    }

    /** «Leave», the end of the time, or the room closed: the server hears it, LiveKit lets go, the page reads itself again. */
    fun leave() {
        val id = uuid
        if (id != null && phase != null && phase != ConsultSessionPhase.Ended) scope.launch { runCatching { ApiClient.freelanceConsultations.leaveSession(auth(), id) } }
        close()
    }

    private fun close() {
        endJob?.cancel()
        roomJob?.cancel()
        room?.let { r -> runCatching { r.disconnect() }; runCatching { r.release() } }
        room = null
        remote = null
        local = null
        phase = ConsultSessionPhase.Ended
        closedCount++
        scope.launch {
            delay(1200)
            if (phase == ConsultSessionPhase.Ended) {
                phase = null
                uuid = null
                join = null
            }
        }
    }

    fun toggleMic() {
        micOn = !micOn
        scope.launch { runCatching { room?.localParticipant?.setMicrophoneEnabled(micOn) }; refresh() }
    }

    fun toggleCamera() {
        val ctx = appContext ?: return
        if (!cameraOn && ContextCompat.checkSelfPermission(ctx, Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) return
        cameraOn = !cameraOn
        scope.launch {
            runCatching { room?.localParticipant?.setCameraEnabled(cameraOn) }.onFailure { cameraOn = false }
            refresh()
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

    /** The loudspeaker, else a headset, else the earpiece — through LiveKit's AudioSwitch (its device list fills in a moment later). */
    private fun applySpeaker() {
        val want = speakerOn
        scope.launch {
            repeat(10) {
                val handler = room?.audioHandler as? AudioSwitchHandler
                val devices = handler?.availableAudioDevices.orEmpty()
                val target = if (want) devices.firstOrNull { it is AudioDevice.Speakerphone }
                else devices.firstOrNull { it is AudioDevice.BluetoothHeadset } ?: devices.firstOrNull { it is AudioDevice.WiredHeadset } ?: devices.firstOrNull { it is AudioDevice.Earpiece }
                if (handler != null && target != null) {
                    runCatching { if (handler.selectedAudioDevice != target) handler.selectDevice(target) }
                    return@launch
                }
                delay(300)
            }
        }
    }

    private fun refresh() {
        val r = room ?: return
        otherInRoom = r.remoteParticipants.isNotEmpty()
        remote = r.remoteParticipants.values.firstNotNullOfOrNull { p ->
            val pub = p.getTrackPublication(Track.Source.CAMERA)
            (pub?.track as? VideoTrack)?.takeIf { pub.muted != true }?.let { CallVideo(p.identity?.value ?: p.sid.value, it, false) }
        }
        local = (r.localParticipant.getTrackPublication(Track.Source.CAMERA)?.track as? VideoTrack)?.takeIf { cameraOn }?.let { CallVideo("me", it, true) }
    }
}

// ==================================================================== the session card (on the consultation)

/** The session on the consultation page: when the room opens, «enter», who is in, who came when, the recordings, and «did not come». */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun ConsultSessionCard(uuid: String, c: ConsultDto, s: ConsultSessionDto, onChanged: (ConsultDto?) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.fl_error_generic)
    val micNeeded = stringResource(R.string.css_mic_needed)
    val other = c.freelancer?.name.orEmpty()
    var error by remember(uuid) { mutableStateOf<String?>(null) }
    var noShowAsk by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var fetching by remember { mutableStateOf<String?>(null) }
    var playing by remember { mutableStateOf<Pair<Int, String>?>(null) }
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }

    val opensAt = ssInstant(s.opensAt)?.toEpochMilli()
    val endsAt = ssInstant(s.endsAt)?.toEpochMilli()
    val booked = c.status == "booked"
    val beforeOpen = booked && s.closed == null && opensAt != null && now < opensAt
    // The next moment the card changes on its own (the room opens, or its time is over): one read then, nothing in between.
    val next = when {
        beforeOpen -> opensAt
        booked && s.roomOpen && endsAt != null && now < endsAt -> endsAt
        else -> null
    }
    LaunchedEffect(next) {
        if (next == null) return@LaunchedEffect
        delay((next - System.currentTimeMillis()).coerceAtLeast(0) + 1500)
        onChanged(null)
    }
    // The countdown on show ticks by the phone's clock (and each fresh read restarts from it).
    LaunchedEffect(s) { now = System.currentTimeMillis() }
    LaunchedEffect(beforeOpen) { while (beforeOpen) { now = System.currentTimeMillis(); delay(1000) } }
    // By the phone's clock the room is open: «enter» shows (the server has the last word, and its reason is shown as it comes).
    val openByClock = booked && s.available && s.closed == null && opensAt != null && endsAt != null && now in opensAt until endsAt

    val permissions = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted ->
        if (granted[Manifest.permission.RECORD_AUDIO] == true) ConsultSession.start(context, uuid, other) { error = it; Toast.makeText(context, it, Toast.LENGTH_LONG).show() }
        else error = micNeeded
    }
    fun enter() {
        error = null
        val needed = listOf(Manifest.permission.RECORD_AUDIO, Manifest.permission.CAMERA).filter { ContextCompat.checkSelfPermission(context, it) != PackageManager.PERMISSION_GRANTED }
        if (needed.isEmpty()) ConsultSession.start(context, uuid, other) { error = it; Toast.makeText(context, it, Toast.LENGTH_LONG).show() }
        else permissions.launch(needed.toTypedArray())
    }

    /** The links are good for 15 minutes: they are read fresh right before use. */
    fun withFreshLink(r: ConsultRecordingDto, watch: Boolean, use: (String) -> Unit) {
        fetching = r.uuid + if (watch) ":w" else ":d"
        scope.launch {
            try {
                val fresh = ApiClient.freelanceConsultations.session(auth(), uuid).data?.recordings?.firstOrNull { it.uuid == r.uuid }
                // Signed on the path only (`signed:relative`): the dev host rewrite of other media applies.
                val url = ApiClient.mediaUrl(if (watch) fresh?.watchUrl else fresh?.downloadUrl)
                if (url.isNullOrBlank()) error = generic else use(url)
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.apiFailure().message ?: generic
            }
            fetching = null
        }
    }

    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(Icons.Rounded.VideoChat, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
            Text(stringResource(R.string.css_title), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            if (s.recorded && s.recordingAvailable) SsPill(stringResource(R.string.css_recorded), S_RED)
            else if (booked) SsPill(stringResource(R.string.css_not_recorded), S_SLATE)
        }
        error?.let { FlNotice(it, Icons.Rounded.Close, S_RED) }

        when {
            booked && !s.available -> FlNotice(stringResource(R.string.css_unavailable), Icons.Rounded.Info, S_AMBER)
            booked && s.closed?.by == "staff" && (endsAt == null || now < endsAt) -> FlNotice(stringResource(R.string.css_closed_staff), Icons.Rounded.Lock, S_AMBER)
            beforeOpen -> {
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(settingsAccent().copy(alpha = if (settingsNight()) 0.2f else 0.08f)).padding(14.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    Icon(Icons.Rounded.HourglassTop, null, tint = settingsAccent(), modifier = Modifier.size(24.dp))
                    Text(stringResource(R.string.css_opens_in, "‎" + ssClock(((opensAt ?: now) - now) / 1000)), color = settingsAccent(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                }
                Text(stringResource(R.string.css_opens_rule), color = settingsMut(), fontSize = 11.5.sp, lineHeight = 17.sp)
            }
            s.can.join || openByClock -> {
                Text(stringResource(R.string.css_join_hint), color = S_GREEN, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                FlAction(stringResource(R.string.css_join), Icons.Rounded.Videocam, FlTone.Primary, loading = ConsultSession.uuid == uuid && ConsultSession.phase == ConsultSessionPhase.Joining, modifier = Modifier.fillMaxWidth()) { enter() }
            }
            booked && endsAt != null && now >= endsAt -> FlNotice(stringResource(R.string.css_over), Icons.Rounded.Timer, S_SLATE)
        }

        // Who is in the room now.
        if (s.roomOpen) {
            Text(stringResource(R.string.css_in_room), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                if (s.inRoom.client) SsPill(stringResource(R.string.css_you), S_GREEN)
                if (s.inRoom.freelancer) SsPill(other.ifBlank { stringResource(R.string.css_freelancer) }, S_GREEN)
                if (!s.inRoom.client && !s.inRoom.freelancer) SsPill(stringResource(R.string.css_nobody), S_SLATE)
            }
        }

        // Who came when.
        if (s.attendance.isNotEmpty()) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(Icons.Rounded.Groups, null, tint = settingsMut(), modifier = Modifier.size(16.dp))
                Text(stringResource(R.string.css_attendance), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
            s.attendance.forEach { a ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(if (a.side == "client") stringResource(R.string.css_you) else other.ifBlank { stringResource(R.string.css_freelancer) }, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                    Text(
                        if (a.leftAt == null) stringResource(R.string.css_still_in, "‎" + ssHm(a.joinedAt)) else stringResource(R.string.css_joined_left, "‎" + ssHm(a.joinedAt), "‎" + ssHm(a.leftAt)),
                        color = if (a.leftAt == null) S_GREEN else settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
                    )
                }
            }
        }

        // The recorded parts.
        if (s.recordings.isNotEmpty() || s.recordingsDeletedAt != null) {
            Text(stringResource(R.string.css_recordings), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            s.recordings.forEach { r -> RecordingRow(r, fetching, onDownload = { withFreshLink(r, false) { url -> download(context, url, c.uuid, r.part) } }, onWatch = { withFreshLink(r, true) { url -> playing = r.part to url } }) }
            when {
                s.recordingsDeletedAt != null -> FlNotice(stringResource(R.string.css_deleted_all), Icons.Rounded.EventBusy, S_SLATE)
                s.recordingsHeld -> FlNotice(stringResource(R.string.css_held), Icons.Rounded.Lock, S_AMBER)
                s.recordingsDeleteAt != null -> FlNotice(stringResource(R.string.css_delete_at, ssDay(s.recordingsDeleteAt)), Icons.Rounded.Info, settingsAccent())
            }
        }

        if (s.can.noShow) FlAction(stringResource(R.string.css_no_show), Icons.Rounded.ReportProblem, FlTone.Warn, modifier = Modifier.fillMaxWidth()) { noShowAsk = true }
    }

    if (noShowAsk) {
        ConfirmDialog(
            icon = Icons.Rounded.ReportProblem, title = stringResource(R.string.css_no_show_title), message = stringResource(R.string.css_no_show_body),
            confirmLabel = stringResource(R.string.css_no_show_confirm), loading = busy,
            onConfirm = {
                busy = true
                scope.launch {
                    try {
                        val fresh = ApiClient.freelanceConsultations.noShow(auth(), uuid).data
                        Toast.makeText(context, context.getString(R.string.css_no_show_done), Toast.LENGTH_SHORT).show()
                        error = null
                        onChanged(fresh)
                    } catch (e: CancellationException) {
                        throw e
                    } catch (e: Exception) {
                        error = e.apiFailure().message ?: generic
                    }
                    busy = false
                    noShowAsk = false
                }
            },
            onDismiss = { noShowAsk = false },
        )
    }
    playing?.let { (part, url) -> RecordingPlayer(part, url) { playing = null } }
}

@Composable
private fun SsPill(text: String, color: Color) {
    Row(
        Modifier.clip(RoundedCornerShape(999.dp)).background(color.copy(alpha = if (settingsNight()) 0.22f else 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(6.dp).clip(CircleShape).background(color))
        Spacer(Modifier.width(6.dp))
        Text(text, color = color, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

/** One recorded part: its length, size and state; once stored, «Download» and «Watch». */
@Composable
private fun RecordingRow(r: ConsultRecordingDto, fetching: String?, onDownload: () -> Unit, onWatch: () -> Unit) {
    val stored = r.status == "stored"
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(12.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(Icons.Rounded.Videocam, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
            Text(stringResource(R.string.css_part, r.part), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
            Text(
                listOfNotNull(r.durationSeconds?.takeIf { it > 0 }?.let { "‎" + ssClock(it) }, ssSize(r.sizeBytes)).joinToString(" · "),
                color = settingsMut(), fontSize = 12.sp, modifier = Modifier.weight(1f),
            )
            when (r.status) {
                "stored" -> Unit
                "recording" -> SsPill(stringResource(R.string.css_recording_now), S_RED)
                "deleted" -> SsPill(stringResource(R.string.css_deleted), S_SLATE)
                else -> SsPill(stringResource(R.string.css_preparing), S_AMBER)
            }
        }
        if (stored) Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FlAction(stringResource(R.string.css_watch), Icons.Rounded.PlayArrow, FlTone.Primary, loading = fetching == r.uuid + ":w", modifier = Modifier.weight(1f), onClick = onWatch)
            FlAction(stringResource(R.string.css_download), Icons.Rounded.Download, loading = fetching == r.uuid + ":d", modifier = Modifier.weight(1f), onClick = onDownload)
        }
    }
}

/** Saved through the system's downloads (a notification shows it), like the other files the app saves. */
private fun download(context: Context, url: String, consultation: String, part: Int) {
    runCatching {
        val name = "consultation-${consultation.take(8)}-part-$part.mp4"
        val request = DownloadManager.Request(Uri.parse(url))
            .addRequestHeader("ngrok-skip-browser-warning", "1")
            .setTitle(name)
            .setMimeType("video/mp4")
            .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
            .setDestinationInExternalPublicDir(android.os.Environment.DIRECTORY_DOWNLOADS, name)
        (context.getSystemService(Context.DOWNLOAD_SERVICE) as DownloadManager).enqueue(request)
        Toast.makeText(context, context.getString(R.string.css_download_started), Toast.LENGTH_SHORT).show()
    }.onFailure {
        Toast.makeText(context, context.getString(R.string.css_download_failed), Toast.LENGTH_SHORT).show()
    }
}

/** A recorded part played inside the app (the link supports Range, so it streams and seeks). */
@Composable
private fun RecordingPlayer(part: Int, url: String, onClose: () -> Unit) {
    LocaleAwareDialog(onDismissRequest = onClose, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Box(Modifier.fillMaxSize().background(Color.Black)) {
            AndroidView(
                factory = { ctx ->
                    VideoView(ctx).apply {
                        val controls = MediaController(ctx)
                        controls.setAnchorView(this)
                        setMediaController(controls)
                        setVideoURI(Uri.parse(url), mapOf("ngrok-skip-browser-warning" to "1"))
                        setOnPreparedListener { start() }
                    }
                },
                onRelease = { it.stopPlayback() },
                modifier = Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding(),
            )
            Row(Modifier.fillMaxWidth().statusBarsPadding().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(stringResource(R.string.css_part, part), color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                Box(Modifier.size(40.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.16f)).clickable(onClick = onClose), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Close, stringResource(R.string.css_close), tint = Color.White, modifier = Modifier.size(22.dp))
                }
            }
        }
    }
}

// ==================================================================== the room, full screen

/** In the session: their video (or who we wait for), mine in a corner, the time left, the warning, REC, and the controls. */
@Composable
internal fun ConsultSessionScreen() {
    val phase = ConsultSession.phase ?: return
    var leaving by remember { mutableStateOf(false) }
    var now by remember { mutableLongStateOf(ConsultSession.serverNow()) }
    LaunchedEffect(phase) { while (phase == ConsultSessionPhase.Live) { now = ConsultSession.serverNow(); delay(1000) } }

    LocaleAwareDialog(onDismissRequest = { leaving = true }, properties = DialogProperties(usePlatformDefaultWidth = false, dismissOnClickOutside = false)) {
        // The screen stays awake while I am in the session.
        val view = LocalView.current
        DisposableEffect(view) {
            view.keepScreenOn = true
            onDispose { view.keepScreenOn = false }
        }
        BackHandler { leaving = true }
        val join = ConsultSession.join
        val endsAt = ConsultSession.endsAtMs()
        val left = endsAt?.let { (it - now) / 1000 }
        val warnAt = (join?.warnBeforeEndMinutes ?: 5) * 60L
        Box(Modifier.fillMaxSize().background(Brush.verticalGradient(listOf(Color(0xFF0B1220), Color(0xFF10234F), Color(0xFF0B1220))))) {
            val remote = ConsultSession.remote
            if (remote != null) key(remote.track) { SessionVideo(remote, Modifier.fillMaxSize()) }
            else Column(Modifier.fillMaxSize().padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                Box(Modifier.size(104.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                    Text(ConsultSession.otherName.take(1).ifEmpty { "?" }, color = Color.White, fontSize = 42.sp, fontWeight = FontWeight.Black)
                }
                Spacer(Modifier.height(16.dp))
                Text(ConsultSession.otherName, color = Color.White, fontSize = 22.sp, fontWeight = FontWeight.Black, textAlign = TextAlign.Center)
                Spacer(Modifier.height(6.dp))
                Text(
                    when {
                        phase == ConsultSessionPhase.Joining -> stringResource(R.string.css_connecting)
                        phase == ConsultSessionPhase.Ended -> ConsultSession.error ?: stringResource(R.string.css_ended)
                        ConsultSession.otherInRoom -> stringResource(R.string.css_other_in, ConsultSession.otherName)
                        else -> stringResource(R.string.css_waiting_for, ConsultSession.otherName)
                    },
                    color = Color.White.copy(alpha = 0.75f), fontSize = 14.sp, textAlign = TextAlign.Center,
                )
            }
            // My camera in the corner.
            ConsultSession.local?.let { mine ->
                Box(Modifier.align(Alignment.TopEnd).statusBarsPadding().padding(top = 70.dp, end = 16.dp).size(104.dp, 150.dp).clip(RoundedCornerShape(18.dp)).background(Color.Black)) {
                    key(mine.track) { SessionVideo(mine, Modifier.fillMaxSize()) }
                }
            }
            // The top: REC, the time left, and the warning near the end.
            Column(Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 16.dp, vertical = 12.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    if (join?.recording == true) Row(
                        Modifier.clip(RoundedCornerShape(999.dp)).background(Color.Black.copy(alpha = 0.45f)).padding(horizontal = 10.dp, vertical = 6.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Box(Modifier.size(9.dp).clip(CircleShape).background(Color(0xFFEF4444)))
                        Spacer(Modifier.width(6.dp))
                        Text(stringResource(R.string.css_recording_now), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    }
                    Spacer(Modifier.weight(1f))
                    if (left != null && phase == ConsultSessionPhase.Live) Row(
                        Modifier.clip(RoundedCornerShape(999.dp)).background(Color.Black.copy(alpha = 0.45f)).padding(horizontal = 12.dp, vertical = 6.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Rounded.Timer, null, tint = if (left <= warnAt) Color(0xFFFBBF24) else Color.White, modifier = Modifier.size(15.dp))
                        Spacer(Modifier.width(6.dp))
                        Text(stringResource(R.string.css_left_time, "‎" + ssClock(left)), color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                    }
                }
                if (left != null && phase == ConsultSessionPhase.Live && left in 1..warnAt) Row(
                    Modifier.clip(RoundedCornerShape(14.dp)).background(Color(0xFFD97706).copy(alpha = 0.92f)).padding(horizontal = 12.dp, vertical = 9.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    Icon(Icons.Rounded.WarningAmber, null, tint = Color.White, modifier = Modifier.size(18.dp))
                    Text(stringResource(R.string.css_ending_warn, ((left + 59) / 60).toInt()), color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                }
            }
            // The controls.
            if (phase != ConsultSessionPhase.Ended) Row(
                Modifier.align(Alignment.BottomCenter).fillMaxWidth().navigationBarsPadding().padding(16.dp)
                    .clip(RoundedCornerShape(36.dp)).background(Color.White.copy(alpha = 0.12f)).padding(horizontal = 14.dp, vertical = 12.dp),
                horizontalArrangement = Arrangement.SpaceEvenly, verticalAlignment = Alignment.CenterVertically,
            ) {
                SessionToggle(if (ConsultSession.micOn) Icons.Rounded.Mic else Icons.Rounded.MicOff, !ConsultSession.micOn) { ConsultSession.toggleMic() }
                SessionToggle(if (ConsultSession.cameraOn) Icons.Rounded.Videocam else Icons.Rounded.VideocamOff, !ConsultSession.cameraOn) { ConsultSession.toggleCamera() }
                SessionToggle(Icons.Rounded.Cameraswitch, false, enabled = ConsultSession.cameraOn) { ConsultSession.flipCamera() }
                SessionToggle(Icons.Rounded.VolumeUp, ConsultSession.speakerOn) { ConsultSession.toggleSpeaker() }
                Box(Modifier.size(58.dp).clip(CircleShape).background(Color(0xFFE53935)).clickable { leaving = true }, contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.CallEnd, stringResource(R.string.css_leave), tint = Color.White, modifier = Modifier.size(26.dp))
                }
            }
        }
        if (leaving) {
            ConfirmDialog(
                icon = Icons.Rounded.CallEnd, title = stringResource(R.string.css_leave_title), message = stringResource(R.string.css_leave_body),
                confirmLabel = stringResource(R.string.css_leave),
                onConfirm = { leaving = false; ConsultSession.leave() }, onDismiss = { leaving = false },
            )
        }
    }
}

@Composable
private fun SessionToggle(icon: ImageVector, active: Boolean, enabled: Boolean = true, onClick: () -> Unit) {
    Box(
        Modifier.size(50.dp).clip(CircleShape).background(if (active) Color.White else Color.White.copy(alpha = if (enabled) 0.16f else 0.06f)).clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, null, tint = if (active) Color.Black else Color.White.copy(alpha = if (enabled) 1f else 0.4f), modifier = Modifier.size(23.dp))
    }
}

/** A LiveKit video track drawn into a TextureView (the chat call's renderer); my own camera mirrored. */
@Composable
private fun SessionVideo(video: CallVideo, modifier: Modifier) {
    val room = ConsultSession.room ?: return
    AndroidView(
        factory = { ctx ->
            TextureViewRenderer(ctx).apply {
                room.initVideoRenderer(this)
                setMirror(video.isLocal)
                video.track.addRenderer(this)
            }
        },
        onRelease = { view ->
            video.track.removeRenderer(view)
            view.release()
        },
        modifier = modifier,
    )
}
