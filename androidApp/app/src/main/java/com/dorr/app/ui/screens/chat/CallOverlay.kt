package com.dorr.app.ui.screens.chat

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectDragGestures
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.CallEnd
import androidx.compose.material.icons.rounded.Cameraswitch
import androidx.compose.material.icons.rounded.KeyboardArrowUp
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.MicOff
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material.icons.rounded.VideocamOff
import androidx.compose.material.icons.rounded.VolumeUp
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import com.dorr.app.R
import com.dorr.app.chat.CallController
import com.dorr.app.chat.CallPhase
import com.dorr.app.chat.CallVideo
import io.livekit.android.renderer.TextureViewRenderer
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlin.math.roundToInt

/**
 * Starts a call after making sure the microphone (and the camera, for video) may be used.
 * Returns `(conversationId, video, title, avatar, peerKey) -> Unit`.
 */
@Composable
fun rememberCallStarter(): (String, Boolean, String, String?, String?) -> Unit {
    val context = LocalContext.current
    var pending by remember { mutableStateOf<(() -> Unit)?>(null) }
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted ->
        if (granted[Manifest.permission.RECORD_AUDIO] == true) pending?.invoke()
        pending = null
    }
    return { id, video, title, avatar, key ->
        val needed = buildList {
            add(Manifest.permission.RECORD_AUDIO)
            if (video) add(Manifest.permission.CAMERA)
        }.filter { ContextCompat.checkSelfPermission(context, it) != PackageManager.PERMISSION_GRANTED }
        val start = { CallController.start(id, video, title, avatar, key) }
        if (needed.isEmpty()) start() else {
            pending = start
            launcher.launch(needed.toTypedArray())
        }
    }
}

/**
 * The call screen, over everything (a call can ring on any screen of the app). Drawn only while
 * there is a call.
 */
@Composable
fun CallOverlay() {
    val phase = CallController.phase
    AnimatedVisibility(
        visible = phase != null,
        enter = slideInVertically(spring(dampingRatio = 0.85f, stiffness = 320f)) { it } + fadeIn(),
        exit = slideOutVertically(tween(320)) { it } + fadeOut(tween(260)),
    ) {
        CallScreen()
    }
}

@Composable
private fun CallScreen() {
    val context = LocalContext.current
    val phase = CallController.phase ?: CallPhase.Ended
    val video = CallController.isVideo
    var controlsVisible by remember { mutableStateOf(true) }
    var tick by remember { mutableLongStateOf(0L) }
    val remote = CallController.videos.firstOrNull { !it.isLocal }
    val local = CallController.videos.firstOrNull { it.isLocal }
    // A group call (or anyone who joined a 1:1 later) shows everyone as a grid instead of one face.
    val grouped = phase == CallPhase.Active &&
        (CallController.call?.conversationType == "group" || CallController.members.count { !it.isLocal } > 1)

    BackHandler(enabled = true) { /* a call is ended with the red button, not by accident */ }

    LaunchedEffect(Unit) {
        val job = CallController.keepTicking { tick = CallController.elapsedSeconds() }
        try { kotlinx.coroutines.awaitCancellation() } finally { job.cancel() }
    }
    // In a video call, controls hide themselves; a tap brings them back.
    LaunchedEffect(controlsVisible, phase, remote != null, grouped) {
        if (controlsVisible && phase == CallPhase.Active && remote != null && !grouped) {
            delay(4000)
            controlsVisible = false
        }
    }

    val answer = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted ->
        if (granted[Manifest.permission.RECORD_AUDIO] == true) CallController.accept() else CallController.decline()
    }
    fun accept() {
        val needed = buildList { add(Manifest.permission.RECORD_AUDIO); if (video) add(Manifest.permission.CAMERA) }
            .filter { ContextCompat.checkSelfPermission(context, it) != PackageManager.PERMISSION_GRANTED }
        if (needed.isEmpty()) CallController.accept() else answer.launch(needed.toTypedArray())
    }

    Box(
        Modifier.fillMaxSize().background(Color.Black)
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { controlsVisible = !controlsVisible },
    ) {
        // ------------------------------------------------------------ group: everyone in a grid
        if (grouped) {
            AnimatedBackdrop()
            GroupGrid(Modifier.fillMaxSize().padding(top = 96.dp, bottom = 124.dp).navigationBarsPadding())
            Column(Modifier.fillMaxWidth().padding(top = 34.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Text(CallController.title, color = Color.White, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
                Text(
                    stringResource(R.string.ch_call_people, CallController.members.size) + "  ·  " + durationText(tick * 1000),
                    color = Color.White.copy(alpha = 0.75f), fontSize = 13.sp, fontWeight = FontWeight.SemiBold,
                )
            }
        } else if (remote != null && phase == CallPhase.Active) {
            key(remote.track) { VideoSurface(remote, Modifier.fillMaxSize()) }
        } else if (local != null && phase != CallPhase.Ended) {
            // Calling, connecting, or their camera is off: my own camera fills the screen (like
            // WhatsApp), so it's plain the camera did open.
            key(local.track) { VideoSurface(local, Modifier.fillMaxSize()) }
        } else {
            AnimatedBackdrop()
        }

        // ------------------------------------------------------------ who + state
        if (!grouped) AnimatedVisibility(remote == null || phase != CallPhase.Active || controlsVisible, enter = fadeIn(), exit = fadeOut()) {
            Column(Modifier.fillMaxWidth().padding(top = 70.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                if (remote == null || phase != CallPhase.Active) {
                    Sonar(ringing = phase == CallPhase.Incoming || phase == CallPhase.Outgoing) {
                        ChAvatar(CallController.avatar, CallController.title, CallController.peerKey, size = 124.dp)
                    }
                    Spacer(Modifier.height(26.dp))
                }
                Text(CallController.title, color = Color.White, fontSize = 27.sp, fontWeight = FontWeight.ExtraBold)
                Spacer(Modifier.height(6.dp))
                AnimatedContent(targetState = phase, label = "callState", transitionSpec = { fadeIn() togetherWith fadeOut() }) { p ->
                    Text(
                        when (p) {
                            CallPhase.Incoming -> stringResource(if (video) R.string.ch_call_incoming_video else R.string.ch_call_incoming_voice)
                            CallPhase.Outgoing -> stringResource(R.string.ch_call_calling)
                            CallPhase.Connecting -> stringResource(R.string.ch_call_connecting)
                            CallPhase.Active -> durationText(tick * 1000)
                            CallPhase.Ended -> CallController.error ?: stringResource(R.string.ch_call_ended)
                        },
                        color = Color.White.copy(alpha = 0.8f), fontSize = 16.sp, fontWeight = FontWeight.SemiBold,
                    )
                }
            }
        }

        // ------------------------------------------------------------ my camera (drag it anywhere, it snaps to a corner)
        if (local != null && remote != null && phase == CallPhase.Active && !grouped) LocalPreview(local)

        // ------------------------------------------------------------ controls
        Box(Modifier.align(Alignment.BottomCenter).navigationBarsPadding().padding(bottom = 36.dp)) {
            if (phase == CallPhase.Incoming) {
                IncomingControls(onDecline = { CallController.decline() }, onAccept = { accept() })
            } else {
                AnimatedVisibility(controlsVisible || remote == null || grouped, enter = slideInVertically { it } + fadeIn(), exit = slideOutVertically { it } + fadeOut()) {
                    ActiveControls(video)
                }
            }
        }
    }
}

/**
 * Everyone in the call: 2 people stacked, up to 6 in two columns filling the screen, more than
 * that scroll. A tile shows the camera when it's on, else the person's photo on a soft gradient;
 * whoever is talking gets a glowing ring, and a muted mic shows a small badge.
 */
@Composable
private fun GroupGrid(modifier: Modifier) {
    val people = CallController.members
    BoxWithConstraints(modifier.padding(horizontal = 10.dp)) {
        val columns = if (people.size <= 2) 1 else 2
        val rows = ((people.size + columns - 1) / columns).coerceAtLeast(1)
        val gap = 10.dp
        val tileHeight = if (people.size <= 6) (maxHeight - gap * (rows - 1)) / rows else 220.dp
        androidx.compose.foundation.lazy.grid.LazyVerticalGrid(
            columns = androidx.compose.foundation.lazy.grid.GridCells.Fixed(columns),
            verticalArrangement = Arrangement.spacedBy(gap),
            horizontalArrangement = Arrangement.spacedBy(gap),
            modifier = Modifier.fillMaxSize(),
        ) {
            items(people.size, key = { people[it].key }) { i ->
                MemberTile(people[i], Modifier.animateItem(fadeInSpec = tween(300), placementSpec = spring(dampingRatio = 0.8f, stiffness = 300f)).fillMaxWidth().height(tileHeight))
            }
        }
    }
}

@Composable
private fun MemberTile(member: com.dorr.app.chat.CallMember, modifier: Modifier) {
    val glow by androidx.compose.animation.core.animateDpAsState(if (member.speaking) 3.dp else 0.dp, spring(dampingRatio = 0.5f), label = "speaking")
    val shape = RoundedCornerShape(22.dp)
    Box(
        modifier
            .shadow(if (member.speaking) 18.dp else 6.dp, shape, spotColor = Color(0xFF22C55E))
            .clip(shape)
            .background(Brush.linearGradient(listOf(Color(0xFF2A0A0D), Color(0xFF14080A))))
            .then(if (glow > 0.dp) Modifier.border(glow, Color(0xFF22C55E), shape) else Modifier),
    ) {
        val v = member.video
        if (v != null) {
            key(v.track) { VideoSurface(v, Modifier.fillMaxSize()) }
        } else {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                Sonar(ringing = member.speaking) { ChAvatar(member.avatar, member.name, member.key, size = 84.dp) }
            }
        }
        Row(
            Modifier.align(Alignment.BottomStart).padding(10.dp).clip(RoundedCornerShape(12.dp)).background(Color.Black.copy(alpha = 0.45f)).padding(horizontal = 10.dp, vertical = 5.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            if (!member.micOn) {
                Icon(Icons.Rounded.MicOff, null, tint = Color(0xFFF87171), modifier = Modifier.size(14.dp))
                Spacer(Modifier.width(4.dp))
            }
            Text(
                if (member.isLocal) stringResource(R.string.ch_you) else member.name.orEmpty(),
                color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1,
            )
        }
    }
}

/** Slowly drifting brand glows behind the avatar. */
@Composable
private fun AnimatedBackdrop() {
    val t = rememberInfiniteTransition(label = "backdrop")
    val a by t.animateFloat(0f, 1f, infiniteRepeatable(tween(7000, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "a")
    val b by t.animateFloat(1f, 0f, infiniteRepeatable(tween(9000, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "b")
    Canvas(Modifier.fillMaxSize().background(Brush.verticalGradient(listOf(Color(0xFF3B0206), Color(0xFF120203), Color.Black)))) {
        drawCircle(Brush.radialGradient(listOf(Color(0x66001B53), Color.Transparent), center = Offset(size.width * (0.2f + 0.5f * a), size.height * 0.25f), radius = size.width * 0.8f), radius = size.width * 0.8f, center = Offset(size.width * (0.2f + 0.5f * a), size.height * 0.25f))
        drawCircle(Brush.radialGradient(listOf(Color(0x44FF8A4C), Color.Transparent), center = Offset(size.width * (0.8f - 0.4f * b), size.height * 0.75f), radius = size.width * 0.7f), radius = size.width * 0.7f, center = Offset(size.width * (0.8f - 0.4f * b), size.height * 0.75f))
    }
}

/** Three rings expanding out of the avatar while it rings. */
@Composable
private fun Sonar(ringing: Boolean, content: @Composable () -> Unit) {
    val t = rememberInfiniteTransition(label = "sonar")
    Box(Modifier.size(240.dp), contentAlignment = Alignment.Center) {
        if (ringing) repeat(3) { i ->
            val p by t.animateFloat(0f, 1f, infiniteRepeatable(tween(2400, delayMillis = i * 800, easing = LinearEasing)), label = "ring$i")
            Box(Modifier.size(124.dp).scale(1f + p * 0.95f).graphicsLayer { alpha = (1f - p) * 0.55f }.background(Ch.Red.copy(alpha = 0.55f), CircleShape))
        }
        content()
    }
}

@Composable
private fun IncomingControls(onDecline: () -> Unit, onAccept: () -> Unit) {
    val t = rememberInfiniteTransition(label = "incoming")
    val bob by t.animateFloat(0f, 1f, infiniteRepeatable(tween(900, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "bob")
    Row(Modifier.fillMaxWidth().padding(horizontal = 50.dp), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.Bottom) {
        RoundAction(Icons.Rounded.CallEnd, stringResource(R.string.ch_call_decline), Ch.Danger, size = 72.dp, onClick = onDecline)
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Icon(Icons.Rounded.KeyboardArrowUp, null, tint = Color.White.copy(alpha = 0.6f), modifier = Modifier.offset(y = (-bob * 8).dp).size(28.dp))
            Box(Modifier.graphicsLayer { translationY = -bob * 6.dp.toPx() }) {
                RoundAction(Icons.Rounded.Call, stringResource(R.string.ch_call_accept), Color(0xFF22C55E), size = 72.dp, onClick = onAccept)
            }
        }
    }
}

@Composable
private fun ActiveControls(video: Boolean) {
    val context = LocalContext.current
    val cameraAsk = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) CallController.toggleCamera()
        else android.widget.Toast.makeText(context, context.getString(R.string.ch_call_camera_denied), android.widget.Toast.LENGTH_SHORT).show()
    }
    Row(
        Modifier.clip(RoundedCornerShape(36.dp)).background(Color.White.copy(alpha = 0.12f)).padding(horizontal = 18.dp, vertical = 14.dp),
        horizontalArrangement = Arrangement.spacedBy(16.dp), verticalAlignment = Alignment.CenterVertically,
    ) {
        ToggleAction(if (CallController.micOn) Icons.Rounded.Mic else Icons.Rounded.MicOff, !CallController.micOn) { CallController.toggleMic() }
        ToggleAction(Icons.Rounded.VolumeUp, CallController.speakerOn) { CallController.toggleSpeaker() }
        ToggleAction(if (CallController.cameraOn) Icons.Rounded.Videocam else Icons.Rounded.VideocamOff, CallController.cameraOn) {
            if (!CallController.cameraOn && !CallController.cameraAllowed()) cameraAsk.launch(Manifest.permission.CAMERA)
            else CallController.toggleCamera()
        }
        if (CallController.cameraOn) ToggleAction(Icons.Rounded.Cameraswitch, false) { CallController.flipCamera() }
        RoundAction(Icons.Rounded.CallEnd, null, Ch.Danger, size = 60.dp) { CallController.hangUp() }
    }
}

@Composable
private fun RoundAction(icon: ImageVector, label: String?, color: Color, size: androidx.compose.ui.unit.Dp, onClick: () -> Unit) {
    val press = remember { MutableInteractionSource() }
    val scale by com.dorr.app.ui.screens.wallet.rememberPressScale(press, 0.88f)
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        Box(
            Modifier.size(size).scale(scale).shadow(18.dp, CircleShape, spotColor = color).clip(CircleShape).background(color)
                .clickable(interactionSource = press, indication = null, onClick = onClick),
            contentAlignment = Alignment.Center,
        ) { Icon(icon, null, tint = Color.White, modifier = Modifier.size(size * 0.44f)) }
        label?.let {
            Spacer(Modifier.height(8.dp))
            Text(it, color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
private fun ToggleAction(icon: ImageVector, active: Boolean, onClick: () -> Unit) {
    val bg by animateColorAsState(if (active) Color.White else Color.White.copy(alpha = 0.16f), label = "toggle")
    val fg by animateColorAsState(if (active) Color.Black else Color.White, label = "toggleFg")
    Box(Modifier.size(52.dp).clip(CircleShape).background(bg).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = fg, modifier = Modifier.size(24.dp))
    }
}

/** A LiveKit video track drawn into a TextureView. */
@Composable
private fun VideoSurface(video: CallVideo, modifier: Modifier) {
    val room = CallController.room ?: return
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

@Composable
private fun LocalPreview(local: CallVideo) {
    val density = LocalDensity.current
    val scope = rememberCoroutineScope()
    BoxWithConstraints(Modifier.fillMaxSize().padding(16.dp)) {
        val w = with(density) { 110.dp.toPx() }
        val h = with(density) { 160.dp.toPx() }
        val maxX = with(density) { maxWidth.toPx() } - w
        val maxY = with(density) { maxHeight.toPx() } - h - with(density) { 120.dp.toPx() }
        val x = remember { Animatable(maxX) }
        val y = remember { Animatable(with(density) { 40.dp.toPx() }) }
        Box(
            Modifier
                .offset { IntOffset(x.value.roundToInt(), y.value.roundToInt()) }
                .size(110.dp, 160.dp)
                .shadow(16.dp, RoundedCornerShape(20.dp))
                .clip(RoundedCornerShape(20.dp))
                .pointerInput(Unit) {
                    detectDragGestures(
                        onDragEnd = {
                            // Snap to the nearest side with a spring.
                            scope.launch { x.animateTo(if (x.value + w / 2 < maxX / 2 + w / 2) 0f else maxX, spring(dampingRatio = 0.7f)) }
                            scope.launch { y.animateTo(y.value.coerceIn(0f, maxY.coerceAtLeast(0f)), spring(dampingRatio = 0.7f)) }
                        },
                    ) { change, drag ->
                        change.consume()
                        scope.launch { x.snapTo(x.value + drag.x); y.snapTo(y.value + drag.y) }
                    }
                },
        ) {
            key(local.track) { VideoSurface(local, Modifier.fillMaxSize()) }
        }
    }
}
