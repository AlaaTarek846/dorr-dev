package com.dorr.freelance.ui.screens

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CallEnd
import androidx.compose.material.icons.rounded.Cameraswitch
import androidx.compose.material.icons.rounded.FiberManualRecord
import androidx.compose.material.icons.rounded.Hearing
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.MicOff
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Timer
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material.icons.rounded.VideocamOff
import androidx.compose.material.icons.rounded.VolumeUp
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import com.dorr.freelance.R
import com.dorr.freelance.session.SessionCallController
import com.dorr.freelance.session.SessionPhase
import com.dorr.freelance.session.SessionVideo
import io.livekit.android.renderer.TextureViewRenderer
import kotlinx.coroutines.delay

/**
 * FL-L16, the consultation's video session (adapted from the DORR app's call screen, androidApp `ui/screens/chat/CallOverlay`): asks
 * for the microphone and camera, takes a ticket and goes into the LiveKit room; the client's camera fills the screen and mine floats in
 * a corner; mute, camera, switch camera, speaker and leave; the time left to `ends_at` (the phone's clock, corrected by the server's), a
 * warning `warn_before_end_minutes` before the end and out at the end. The screen stays on while it is open.
 */
@Composable
fun ConsultationSessionScreen(uuid: String, onBack: () -> Unit) {
    val context = LocalContext.current
    val offline = stringResource(R.string.login_error_offline)
    val generic = stringResource(R.string.login_error_generic)
    val micNeeded = stringResource(R.string.ses_mic_needed)
    var denied by remember { mutableStateOf<String?>(null) }
    var confirmLeave by remember { mutableStateOf(false) }

    fun close() {
        SessionCallController.clear()
        onBack()
    }

    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted ->
        val mic = granted[Manifest.permission.RECORD_AUDIO] == true ||
            ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED
        if (mic) SessionCallController.start(context, uuid, "", offline, generic) else denied = micNeeded
    }

    // Into the room once (coming back to a session already running only shows it).
    LaunchedEffect(uuid) {
        if (SessionCallController.isIn(uuid)) return@LaunchedEffect
        if (SessionCallController.ongoing()) SessionCallController.leave()
        SessionCallController.clear()
        val needed = listOf(Manifest.permission.RECORD_AUDIO, Manifest.permission.CAMERA)
            .filter { ContextCompat.checkSelfPermission(context, it) != PackageManager.PERMISSION_GRANTED }
        if (needed.isEmpty()) SessionCallController.start(context, uuid, "", offline, generic) else launcher.launch(needed.toTypedArray())
    }

    // The screen stays on for the whole session.
    val view = LocalView.current
    DisposableEffect(Unit) {
        view.keepScreenOn = true
        onDispose { view.keepScreenOn = false }
    }

    val phase = SessionCallController.phase
    BackHandler { if (SessionCallController.ongoing()) confirmLeave = true else close() }

    Box(Modifier.fillMaxSize().background(Color(0xFF0B1220))) {
        val remote = SessionCallController.videos.firstOrNull { !it.isLocal }
        val local = SessionCallController.videos.firstOrNull { it.isLocal }
        if (remote != null) {
            key(remote.track) { VideoSurface(remote, Modifier.fillMaxSize()) }
        } else {
            Waiting(phase)
        }
        if (local != null) {
            Box(Modifier.fillMaxSize().statusBarsPadding().padding(top = 92.dp, end = 16.dp, start = 16.dp), contentAlignment = Alignment.TopEnd) {
                Box(Modifier.size(110.dp, 160.dp).shadow(16.dp, RoundedCornerShape(20.dp)).clip(RoundedCornerShape(20.dp))) {
                    key(local.track) { VideoSurface(local, Modifier.fillMaxSize()) }
                }
            }
        }

        TopBar()

        if (phase == SessionPhase.Active || phase == SessionPhase.Connecting) {
            Controls(onLeave = { confirmLeave = true }, modifier = Modifier.align(Alignment.BottomCenter))
        }

        val ended = phase == SessionPhase.Ended || denied != null
        if (ended) {
            val text = denied ?: SessionCallController.error
                ?: stringResource(if (SessionCallController.endedByTime) R.string.ses_ended_time else R.string.ses_ended)
            Ended(text, onClose = { close() })
            // A plain leave closes by itself; a reason to read stays until it is closed.
            if (denied == null && SessionCallController.error == null && !SessionCallController.endedByTime) {
                LaunchedEffect(Unit) { delay(1200); close() }
            }
        }
    }

    if (confirmLeave) {
        AlertDialog(
            onDismissRequest = { confirmLeave = false },
            title = { Text(stringResource(R.string.ses_leave_title), fontWeight = FontWeight.ExtraBold) },
            text = { Text(stringResource(R.string.ses_leave_body), fontSize = 13.sp, lineHeight = 19.sp) },
            confirmButton = {
                TextButton(onClick = { confirmLeave = false; SessionCallController.leave() }) {
                    Text(stringResource(R.string.ses_leave), color = Color(0xFFDC2626), fontWeight = FontWeight.Bold)
                }
            },
            dismissButton = { TextButton(onClick = { confirmLeave = false }) { Text(stringResource(R.string.ses_keep_in)) } },
        )
    }
}

/** The title, the recording dot, the time left and — near the end — the warning. */
@Composable
private fun TopBar() {
    val ticket = SessionCallController.ticket
    var left by remember { mutableLongStateOf(SessionCallController.secondsLeft()) }
    val active = SessionCallController.phase == SessionPhase.Active
    // The clock only (once a second) — the end itself is SessionCallController's own timer.
    LaunchedEffect(active, SessionCallController.endsAtMs) {
        while (active) {
            left = SessionCallController.secondsLeft()
            delay(1000)
        }
    }
    val warnMinutes = ticket?.warnBeforeEndMinutes ?: 5
    Column(Modifier.fillMaxWidth().statusBarsPadding().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Column(Modifier.weight(1f)) {
                Text(
                    SessionCallController.otherName?.takeIf { it.isNotBlank() } ?: stringResource(R.string.ses_title),
                    color = Color.White, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1,
                )
                Text(
                    stringResource(if (active) R.string.ses_ongoing else R.string.ses_connecting),
                    color = Color.White.copy(alpha = 0.75f), fontSize = 12.sp,
                )
            }
            if (SessionCallController.recording && active) {
                Row(
                    Modifier.clip(RoundedCornerShape(999.dp)).background(Color(0xFFDC2626)).padding(horizontal = 10.dp, vertical = 5.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp),
                ) {
                    Icon(Icons.Rounded.FiberManualRecord, contentDescription = null, tint = Color.White, modifier = Modifier.size(10.dp))
                    Text(stringResource(R.string.ses_rec_badge), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold)
                }
            }
            if (active && SessionCallController.endsAtMs != null) {
                Row(
                    Modifier.clip(RoundedCornerShape(999.dp)).background(Color.White.copy(alpha = 0.16f)).padding(horizontal = 10.dp, vertical = 5.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp),
                ) {
                    Icon(Icons.Rounded.Timer, contentDescription = null, tint = Color.White, modifier = Modifier.size(14.dp))
                    Text("‎" + sesClock(left), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
        if (active && left > 0 && left <= warnMinutes * 60L) {
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Color(0xFFD97706)).padding(12.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Icon(Icons.Rounded.WarningAmber, contentDescription = null, tint = Color.White, modifier = Modifier.size(18.dp))
                Text(
                    stringResource(R.string.ses_warn, ((left + 59) / 60).toInt()),
                    color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f),
                )
            }
        }
    }
}

/** No video from the other side yet: who we are waiting for (or that we are connecting). */
@Composable
private fun Waiting(phase: SessionPhase?) {
    Column(Modifier.fillMaxSize().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
        Box(Modifier.size(96.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Person, contentDescription = null, tint = Color.White.copy(alpha = 0.8f), modifier = Modifier.size(52.dp))
        }
        Box(Modifier.height(16.dp))
        when {
            phase == SessionPhase.Connecting -> {
                CircularProgressIndicator(Modifier.size(28.dp), color = Color.White, strokeWidth = 2.5.dp)
                Box(Modifier.height(10.dp))
                Text(stringResource(R.string.ses_connecting), color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.Bold)
            }
            phase == SessionPhase.Active && !SessionCallController.otherInRoom -> Text(
                stringResource(R.string.ses_waiting_other, SessionCallController.otherName?.takeIf { it.isNotBlank() } ?: stringResource(R.string.ses_the_client)),
                color = Color.White, fontSize = 15.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
            )
            phase == SessionPhase.Active -> Text(
                SessionCallController.otherName.orEmpty(), color = Color.White, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center,
            )
        }
    }
}

@Composable
private fun Controls(onLeave: () -> Unit, modifier: Modifier) {
    val active = SessionCallController.phase == SessionPhase.Active
    Row(
        modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 16.dp, vertical = 20.dp)
            .clip(RoundedCornerShape(28.dp)).background(Color.Black.copy(alpha = 0.45f)).padding(vertical = 12.dp),
        horizontalArrangement = Arrangement.SpaceEvenly, verticalAlignment = Alignment.CenterVertically,
    ) {
        RoundButton(
            if (SessionCallController.micOn) Icons.Rounded.Mic else Icons.Rounded.MicOff,
            stringResource(if (SessionCallController.micOn) R.string.ses_mute else R.string.ses_unmute),
            on = SessionCallController.micOn, enabled = active,
        ) { SessionCallController.toggleMic() }
        RoundButton(
            if (SessionCallController.cameraOn) Icons.Rounded.Videocam else Icons.Rounded.VideocamOff, stringResource(R.string.ses_camera),
            on = SessionCallController.cameraOn, enabled = active && SessionCallController.cameraAllowed(),
        ) { SessionCallController.toggleCamera() }
        RoundButton(
            Icons.Rounded.Cameraswitch, stringResource(R.string.ses_switch_camera),
            on = true, enabled = active && SessionCallController.cameraOn,
        ) { SessionCallController.flipCamera() }
        RoundButton(
            if (SessionCallController.speakerOn) Icons.Rounded.VolumeUp else Icons.Rounded.Hearing, stringResource(R.string.ses_speaker),
            on = SessionCallController.speakerOn, enabled = active,
        ) { SessionCallController.toggleSpeaker() }
        Box(
            Modifier.size(56.dp).clip(CircleShape).background(Color(0xFFDC2626)).clickable(onClick = onLeave),
            contentAlignment = Alignment.Center,
        ) { Icon(Icons.Rounded.CallEnd, contentDescription = stringResource(R.string.ses_leave), tint = Color.White, modifier = Modifier.size(26.dp)) }
    }
}

@Composable
private fun RoundButton(icon: ImageVector, label: String, on: Boolean, enabled: Boolean, onClick: () -> Unit) {
    Box(
        Modifier.size(50.dp).graphicsLayer { alpha = if (enabled) 1f else 0.4f }.clip(CircleShape)
            .background(if (on) Color.White.copy(alpha = 0.18f) else Color.White).clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, contentDescription = label, tint = if (on) Color.White else Color(0xFF0B1220), modifier = Modifier.size(24.dp)) }
}

@Composable
private fun Ended(text: String, onClose: () -> Unit) {
    Box(Modifier.fillMaxSize().background(Color.Black.copy(alpha = 0.7f)).padding(32.dp), contentAlignment = Alignment.Center) {
        Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(16.dp)) {
            Text(text, color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, lineHeight = 23.sp)
            Box(
                Modifier.clip(RoundedCornerShape(16.dp)).background(Color.White).clickable(onClick = onClose).padding(horizontal = 28.dp, vertical = 12.dp),
            ) { Text(stringResource(R.string.ses_close), color = Color(0xFF0B1220), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold) }
        }
    }
}

/** A LiveKit video track drawn into a TextureView (as in the DORR app's call screen). */
@Composable
private fun VideoSurface(video: SessionVideo, modifier: Modifier) {
    val room = SessionCallController.room ?: return
    AndroidView(
        factory = { ctx ->
            TextureViewRenderer(ctx).apply {
                room.initVideoRenderer(this)
                setMirror(video.isLocal)
                video.track.addRenderer(this)
            }
        },
        onRelease = { v ->
            video.track.removeRenderer(v)
            v.release()
        },
        modifier = modifier,
    )
}
