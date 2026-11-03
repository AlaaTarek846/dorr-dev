package com.dorr.freelance.ui.screens

import android.app.DownloadManager
import android.content.Context
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.widget.MediaController
import android.widget.Toast
import android.widget.VideoView
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Download
import androidx.compose.material.icons.rounded.FiberManualRecord
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.People
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.VideoChat
import androidx.compose.material.icons.rounded.VideoLibrary
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AppLocale
import com.dorr.freelance.net.ConsultationDto
import com.dorr.freelance.net.ConsultationSessionDto
import com.dorr.freelance.net.ServerClock
import com.dorr.freelance.net.SessionRecordingDto
import com.dorr.freelance.session.SessionCallController
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.util.Locale

/*
 * FL-L16, the consultation's video session on the booking's page: before the room opens a countdown (the phone's clock, corrected once
 * by the server's), then a big "join" while it is open, who is in the room, that it is recorded, the attendance, and after it the
 * recorded parts — each one downloaded (the phone's downloads) or watched in the app — with the day they are deleted. Read when the page
 * opens and when a notice arrives; nothing asks the server on a timer (the countdown only reads the clock).
 */

private fun sesHm(at: Instant?): String = at?.let { DateTimeFormatter.ofPattern("HH:mm", Locale.US).format(it.atZone(ZoneId.systemDefault())) }.orEmpty()

private fun sesDay(at: Instant?): String = at?.let {
    val z = it.atZone(ZoneId.systemDefault())
    "${DateTimeFormatter.ofPattern("EEEE", AppLocale.java()).format(z)} ${z.dayOfMonth} ${DateTimeFormatter.ofPattern("MMMM", AppLocale.java()).format(z)}"
}.orEmpty()

/** "1:02:05" / "12:05" — digits Latin. */
internal fun sesClock(totalSeconds: Long): String {
    val s = totalSeconds.coerceAtLeast(0)
    val h = s / 3600
    val m = (s % 3600) / 60
    val sec = s % 60
    return if (h > 0) String.format(Locale.US, "%d:%02d:%02d", h, m, sec) else String.format(Locale.US, "%02d:%02d", m, sec)
}

/** The session card. [onJoin] opens the session screen. Shown once the booking is taken (or when it left recordings behind). */
@Composable
internal fun ConsultationSessionCard(c: ConsultationDto, onJoin: () -> Unit) {
    val s = c.session ?: return
    val taken = c.status in setOf("booked", "completed", "disputed", "refunded")
    if (!taken && s.recordings.isEmpty() && s.attendance.isEmpty()) return

    val opensAt = ServerClock.parseInstant(s.opensAt)
    val startsAt = ServerClock.parseInstant(s.startsAt ?: c.startsAt)
    val endsAt = ServerClock.parseInstant(s.endsAt ?: c.endsAt)
    // Only the clock ticks (once a second, while a countdown or an open room is on screen) — never a request.
    var nowMs by remember { mutableLongStateOf(ServerClock.nowMs()) }
    val live = c.status == "booked" && s.closed == null && endsAt != null && nowMs < endsAt.toEpochMilli()
    LaunchedEffect(live) {
        while (live) {
            nowMs = ServerClock.nowMs()
            delay(1000)
        }
    }
    val beforeOpen = live && opensAt != null && nowMs < opensAt.toEpochMilli()
    // The server said "join" when the page was read; the room also opens on its own at `opens_at` by the clock.
    val canJoin = s.available && live && !beforeOpen && (s.can.join || (opensAt != null && nowMs >= opensAt.toEpochMilli()))
    val inThisSession = SessionCallController.isIn(c.uuid)

    InfoCard(title = stringResource(R.string.ses_card_title), icon = Icons.Rounded.VideoChat) {
        if (s.recorded) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                // Recording only counts when the room can really record (same rule as the client app).
                if (s.recordingAvailable) SvcPill(stringResource(R.string.ses_recorded), SvcTone(Color(0xFFDC2626), Color(0xFFDC2626).copy(alpha = if (settingsNight()) 0.22f else 0.1f)))
                else if (live) SvcPill(stringResource(R.string.ses_not_recorded), serviceTone("paused"))
                if (s.recordingsHeld) SvcPill(stringResource(R.string.ses_held), serviceTone("pending_review"))
            }
        }

        when {
            beforeOpen && opensAt != null -> Countdown(opensAt, startsAt, nowMs)
            inThisSession || canJoin -> {
                JoinButton(stringResource(if (inThisSession) R.string.ses_back_to else R.string.ses_join), onJoin)
                SesNote(Icons.Rounded.Info, stringResource(R.string.ses_room_open_note, sesHm(endsAt)), SvcTone(settingsAccent(), settingsAccent().copy(alpha = 0.08f)))
            }
            live && !s.available -> SesNote(Icons.Rounded.Info, stringResource(R.string.ses_unavailable), serviceTone("pending_review"))
            s.closed?.by == "staff" -> SesNote(Icons.Rounded.Lock, stringResource(R.string.ses_closed_staff), serviceTone("paused"))
            endsAt != null && nowMs >= endsAt.toEpochMilli() -> SesNote(Icons.Rounded.HourglassTop, stringResource(R.string.ses_over), serviceTone("paused"))
        }

        if (live) {
            if (s.recordingAvailable) Text(stringResource(R.string.ses_recorded_note), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
            else Text(stringResource(R.string.ses_recording_off), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
            InRoom(s)
        }

        if (s.attendance.isNotEmpty()) Attendance(s)
        Recordings(c.uuid, s)
    }
}

@Composable
private fun Countdown(opensAt: Instant, startsAt: Instant?, nowMs: Long) {
    val accent = settingsAccent()
    val left = (opensAt.toEpochMilli() - nowMs) / 1000
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Brush.linearGradient(listOf(accent, accent.copy(alpha = 0.78f)))).padding(16.dp),
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Text(stringResource(R.string.ses_opens_in), color = Color.White.copy(alpha = 0.9f), fontSize = 13.sp, fontWeight = FontWeight.Bold)
        val days = left / 86_400
        Text(
            "‎" + (if (days > 0) "${days}d " else "") + sesClock(left % 86_400),
            color = Color.White, fontSize = 28.sp, fontWeight = FontWeight.ExtraBold,
        )
        val before = startsAt?.let { ((it.toEpochMilli() - opensAt.toEpochMilli()) / 60_000).toInt() } ?: 5
        Text(
            stringResource(R.string.ses_opens_at, "${sesDay(opensAt)} ${sesHm(opensAt)}", before),
            color = Color.White.copy(alpha = 0.9f), fontSize = 12.sp, lineHeight = 17.sp,
        )
    }
}

@Composable
private fun JoinButton(text: String, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().height(58.dp).clip(RoundedCornerShape(18.dp)).background(Color(0xFF16A34A)).clickable(onClick = onClick).padding(horizontal = 16.dp),
        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.VideoChat, contentDescription = null, tint = Color.White, modifier = Modifier.size(24.dp))
        Box(Modifier.size(10.dp))
        Text(text, color = Color.White, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
    }
}

/** Who is in the room right now: the client and me. */
@Composable
private fun InRoom(s: ConsultationSessionDto) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Icon(Icons.Rounded.People, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        Text(stringResource(R.string.ses_in_room_title), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
    }
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        PresencePill(stringResource(R.string.ses_side_client), s.inRoom.client)
        PresencePill(stringResource(R.string.ses_side_me), s.inRoom.freelancer)
    }
}

@Composable
private fun PresencePill(who: String, inside: Boolean) {
    val tone = if (inside) serviceTone("published") else serviceTone("paused")
    SvcPill("$who · " + stringResource(if (inside) R.string.ses_in_room_yes else R.string.ses_in_room_no), tone)
}

@Composable
private fun Attendance(s: ConsultationSessionDto) {
    Line()
    Text(stringResource(R.string.ses_attendance), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
    s.attendance.forEach { a ->
        val joined = ServerClock.parseInstant(a.joinedAt)
        val left = ServerClock.parseInstant(a.leftAt)
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Box(Modifier.size(8.dp).clip(CircleShape).background(if (a.side == "freelancer") settingsAccent() else Color(0xFF0EA5E9)))
            Text(
                stringResource(if (a.side == "freelancer") R.string.ses_side_me else R.string.ses_side_client),
                color = settingsInk(), fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f),
            )
            Text(
                "‎${sesHm(joined)} → " + (if (left != null) sesHm(left) else stringResource(R.string.ses_still_in)),
                color = settingsMut(), fontSize = 12.5.sp,
            )
        }
    }
}

@Composable
private fun Recordings(uuid: String, s: ConsultationSessionDto) {
    if (s.recordings.isEmpty() && s.recordingsDeletedAt == null) return
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val failed = stringResource(R.string.ses_link_failed)
    val started = stringResource(R.string.ses_download_started)
    var watching by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf<String?>(null) }

    /** The links are good for a few minutes only: a fresh one is read when it is used (one request, on a tap). */
    fun fresh(part: SessionRecordingDto, use: (SessionRecordingDto) -> Unit) {
        busy = part.uuid
        scope.launch {
            val r = apiCall { ApiClient.consultationSessions.session(uuid) }
            busy = null
            val now = (r as? ApiResult.Ok)?.data?.recordings?.firstOrNull { it.uuid == part.uuid }
            if (now == null || now.status != "stored") Toast.makeText(context, failed, Toast.LENGTH_SHORT).show() else use(now)
        }
    }

    Line()
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Icon(Icons.Rounded.VideoLibrary, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        Text(stringResource(R.string.ses_recordings), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
    }
    s.recordings.forEach { part ->
        RecordingRow(
            part, busy = busy == part.uuid,
            onDownload = {
                fresh(part) { p ->
                    val ok = p.downloadUrl?.let { downloadRecording(context, it, "consultation-${uuid.take(8)}-part-${p.part}.mp4") } == true
                    Toast.makeText(context, if (ok) started else failed, Toast.LENGTH_SHORT).show()
                }
            },
            onWatch = { fresh(part) { p -> p.watchUrl?.let { watching = it } ?: Toast.makeText(context, failed, Toast.LENGTH_SHORT).show() } },
        )
    }
    when {
        s.recordingsDeletedAt != null ->
            SesNote(Icons.Rounded.Delete, stringResource(R.string.ses_deleted_all, sesDay(ServerClock.parseInstant(s.recordingsDeletedAt))), serviceTone("paused"))
        s.recordingsHeld -> SesNote(Icons.Rounded.Lock, stringResource(R.string.ses_held_note), serviceTone("pending_review"))
        s.recordingsDeleteAt != null ->
            SesNote(Icons.Rounded.Delete, stringResource(R.string.ses_delete_at, sesDay(ServerClock.parseInstant(s.recordingsDeleteAt))), serviceTone("pending_review"))
    }

    watching?.let { url -> RecordingPlayer(url) { watching = null } }
}

@Composable
private fun RecordingRow(r: SessionRecordingDto, busy: Boolean, onDownload: () -> Unit, onWatch: () -> Unit) {
    val stored = r.status == "stored"
    val (label, tone) = when (r.status) {
        "stored" -> stringResource(R.string.ses_rec_ready) to serviceTone("published")
        "recording" -> stringResource(R.string.ses_rec_recording) to SvcTone(Color(0xFFDC2626), Color(0xFFDC2626).copy(alpha = 0.1f))
        "deleted" -> stringResource(R.string.ses_rec_deleted) to serviceTone("paused")
        else -> stringResource(R.string.ses_rec_processing) to serviceTone("pending_review")
    }
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsMut().copy(alpha = 0.08f)).padding(12.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(if (r.status == "recording") Icons.Rounded.FiberManualRecord else Icons.Rounded.VideoChat, contentDescription = null, tint = tone.ink, modifier = Modifier.size(18.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.ses_part, r.part), color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold)
                val facts = listOfNotNull(
                    r.durationSeconds?.let { "‎" + sesClock(it.toLong()) },
                    r.sizeBytes?.takeIf { it > 0 }?.let { stringResource(R.string.ses_size_mb, String.format(Locale.US, "%.1f", it / 1_048_576.0)) },
                )
                if (facts.isNotEmpty()) Text(facts.joinToString(" · "), color = settingsMut(), fontSize = 12.sp)
            }
            SvcPill(label, tone)
        }
        if (stored) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                SmallAction(stringResource(R.string.ses_watch), Icons.Rounded.PlayArrow, Modifier.weight(1f), enabled = !busy, onClick = onWatch)
                SmallAction(stringResource(R.string.ses_download), Icons.Rounded.Download, Modifier.weight(1f), enabled = !busy, onClick = onDownload)
                if (busy) CircularProgressIndicator(Modifier.size(18.dp), color = settingsAccent(), strokeWidth = 2.dp)
            }
        }
    }
}

@Composable
private fun SmallAction(text: String, icon: ImageVector, modifier: Modifier, enabled: Boolean, onClick: () -> Unit) {
    Row(
        modifier.height(40.dp).clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = if (enabled) 0.12f else 0.05f))
            .clickable(enabled = enabled, onClick = onClick).padding(horizontal = 10.dp),
        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        Box(Modifier.size(6.dp))
        Text(text, color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun SesNote(icon: ImageVector, text: String, tone: SvcTone) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(tone.fill).padding(12.dp),
        verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Icon(icon, contentDescription = null, tint = tone.ink, modifier = Modifier.size(18.dp))
        Text(text, color = tone.ink, fontSize = 12.5.sp, lineHeight = 18.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
    }
}

@Composable
private fun Line() {
    Box(Modifier.fillMaxWidth().height(1.dp).background(if (settingsNight()) AccountDark.line else Color(0xFFEEF0F3)))
}

/** Under "accept" on a request: accepting is agreeing to the recording. */
@Composable
internal fun SessionConsentLine() {
    Text(
        stringResource(R.string.ses_accept_consent), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp,
        modifier = Modifier.widthIn(max = 520.dp).fillMaxWidth(),
    )
}

/**
 * A part into the phone's downloads through the system's download manager (its own notification, resumes on a bad network). The link is
 * signed for this freelancer and a few minutes, so no sign-in header is needed.
 */
private fun downloadRecording(context: Context, url: String, name: String): Boolean = runCatching {
    val request = DownloadManager.Request(Uri.parse(ApiClient.mediaUrl(url) ?: url))
        .setTitle(name)
        .setMimeType("video/mp4")
        .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
        .addRequestHeader("ngrok-skip-browser-warning", "1")
    // Android 10+: the public Downloads folder needs no permission; before it, the app's own folder (also no permission).
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, name)
    else request.setDestinationInExternalFilesDir(context, Environment.DIRECTORY_DOWNLOADS, name)
    context.getSystemService(DownloadManager::class.java).enqueue(request)
    true
}.getOrDefault(false)

/** Watching a part in the app: the phone's own player streaming the signed link (the server answers range requests). */
@Composable
private fun RecordingPlayer(url: String, onClose: () -> Unit) {
    Dialog(onDismissRequest = onClose, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Box(Modifier.fillMaxSize().background(Color.Black)) {
            var loading by remember { mutableStateOf(true) }
            AndroidView(
                factory = { ctx ->
                    VideoView(ctx).apply {
                        val controls = MediaController(ctx)
                        controls.setAnchorView(this)
                        setMediaController(controls)
                        setVideoURI(Uri.parse(ApiClient.mediaUrl(url) ?: url), mapOf("ngrok-skip-browser-warning" to "1"))
                        setOnPreparedListener { loading = false; start() }
                        setOnErrorListener { _, _, _ -> loading = false; false }
                    }
                },
                onRelease = { it.stopPlayback() },
                modifier = Modifier.fillMaxSize().padding(top = 56.dp).align(Alignment.Center),
            )
            if (loading) CircularProgressIndicator(Modifier.align(Alignment.Center), color = Color.White)
            Box(
                Modifier.statusBarsPadding().padding(12.dp).size(40.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.18f)).clickable(onClick = onClose),
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.Close, contentDescription = stringResource(R.string.ses_close), tint = Color.White, modifier = Modifier.size(22.dp)) }
        }
    }
}
