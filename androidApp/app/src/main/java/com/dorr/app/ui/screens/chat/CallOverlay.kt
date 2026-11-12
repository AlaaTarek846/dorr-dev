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
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.material.icons.rounded.ScreenShare
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
import androidx.compose.foundation.layout.absoluteOffset
import androidx.compose.foundation.layout.offset
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.CallEnd
import androidx.compose.material.icons.rounded.Cameraswitch
import androidx.compose.material.icons.rounded.BlurOn
import androidx.compose.material.icons.rounded.Podcasts
import androidx.compose.material.icons.rounded.KeyboardArrowUp
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.LockOpen
import androidx.compose.material.icons.rounded.MoreVert
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.MicOff
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material.icons.rounded.VideocamOff
import androidx.compose.material.icons.rounded.VolumeUp
import androidx.compose.material3.DropdownMenu
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
import androidx.compose.ui.AbsoluteAlignment
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.input.pointer.util.VelocityTracker
import kotlin.math.abs
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
import com.dorr.app.ui.locale.rememberAppLocaleFrame
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
        visible = phase != null && !CallController.minimized && !CallController.inPip,
        enter = slideInVertically(spring(dampingRatio = 0.85f, stiffness = 320f)) { it } + fadeIn(),
        exit = slideOutVertically(tween(320)) { it } + fadeOut(tween(260)),
    ) {
        CallScreen()
    }
    // Minimised: a small window over the app that I can drag; a tap brings the call back.
    if (phase != null && phase != CallPhase.Ended && CallController.minimized && !CallController.inPip) CallMiniBubble()
}

/** The call screen's own dark red, for the minimised window and picture-in-picture too. */
private val CallBackdropBrush = Brush.verticalGradient(listOf(Color(0xFF3B0206), Color(0xFF120203)))

/** The call, minimised: its video (or photo, name and timer), anywhere on screen. */
@Composable
private fun CallMiniBubble() {
    val density = LocalDensity.current
    val scope = rememberCoroutineScope()
    var tick by remember { mutableLongStateOf(0L) }
    LaunchedEffect(Unit) {
        val job = CallController.keepTicking { tick = CallController.elapsedSeconds() }
        try { kotlinx.coroutines.awaitCancellation() } finally { job.cancel() }
    }
    val remote = CallController.videos.firstOrNull { !it.isLocal }
    val w = if (remote != null) 112.dp else 190.dp
    val h = if (remote != null) 168.dp else 62.dp
    // Top-left in every language: absoluteOffset counts from there (Arabic's TopStart is the right edge).
    BoxWithConstraints(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding().padding(12.dp), contentAlignment = AbsoluteAlignment.TopLeft) {
        val wPx = with(density) { w.toPx() }
        val hPx = with(density) { h.toPx() }
        val maxX = (constraints.maxWidth - wPx).coerceAtLeast(0f)
        val maxY = (constraints.maxHeight - hPx).coerceAtLeast(0f)
        // Screen coordinates (absoluteOffset): `offset` is mirrored in Arabic, so a drag went the other way.
        val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
        val x = remember { Animatable(if (rtl) 0f else maxX) }
        val y = remember { Animatable(with(density) { 64.dp.toPx() }.coerceAtMost(maxY)) }
        Box(
            Modifier
                .absoluteOffset { IntOffset(x.value.roundToInt(), y.value.roundToInt()) }
                .size(w, h)
                .shadow(18.dp, RoundedCornerShape(20.dp))
                .clip(RoundedCornerShape(20.dp))
                .background(CallBackdropBrush)
                .pointerInput(maxX, maxY) {
                    // The finger's speed, from the total drag (the window moves with the finger, so its own
                    // positions would read as standing still).
                    val tracker = VelocityTracker()
                    var travelled = Offset.Zero
                    val fling = 400.dp.toPx()
                    detectDragGestures(
                        onDragStart = { tracker.resetTracking(); travelled = Offset.Zero },
                        // Let go: a flick sends it to that side; otherwise the nearest side.
                        onDragEnd = {
                            val vx = tracker.calculateVelocity().x
                            val right = if (abs(vx) > fling) vx > 0 else x.value >= maxX / 2
                            scope.launch { x.animateTo(if (right) maxX else 0f, spring(dampingRatio = 0.75f, stiffness = 380f), initialVelocity = vx) }
                        },
                    ) { change, drag ->
                        change.consume()
                        travelled += drag
                        tracker.addPosition(change.uptimeMillis, travelled)
                        scope.launch {
                            x.snapTo((x.value + drag.x).coerceIn(0f, maxX))
                            y.snapTo((y.value + drag.y).coerceIn(0f, maxY))
                        }
                    }
                }
                .clickable { CallController.minimized = false },
        ) {
            if (remote != null) {
                key(remote.track) { VideoSurface(remote, Modifier.fillMaxSize()) }
                Text(
                    durationText(tick * 1000), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.align(Alignment.BottomCenter).padding(6.dp).clip(RoundedCornerShape(8.dp)).background(Color.Black.copy(alpha = 0.45f)).padding(horizontal = 6.dp, vertical = 2.dp),
                )
            } else {
                Row(Modifier.fillMaxSize().padding(horizontal = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                    ChAvatar(CallController.avatar, CallController.title, CallController.peerKey, size = 40.dp)
                    Spacer(Modifier.width(8.dp))
                    Column(Modifier.weight(1f)) {
                        Text(ltrTitle(CallController.title), color = Color.White, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                        Text(
                            if (CallController.phase == CallPhase.Active) durationText(tick * 1000) else stringResource(R.string.ch_call_calling),
                            color = Color.White.copy(alpha = 0.75f), fontSize = 12.sp,
                        )
                    }
                    Box(
                        Modifier.size(34.dp).clip(CircleShape).background(Ch.Danger).clickable { CallController.hangUp() },
                        contentAlignment = Alignment.Center,
                    ) { Icon(Icons.Rounded.CallEnd, null, tint = Color.White, modifier = Modifier.size(18.dp)) }
                }
            }
        }
    }
}

/** The call in the picture-in-picture window (over other apps): their video, or their photo and the timer. */
@Composable
fun CallPipView() {
    var tick by remember { mutableLongStateOf(0L) }
    LaunchedEffect(Unit) {
        val job = CallController.keepTicking { tick = CallController.elapsedSeconds() }
        try { kotlinx.coroutines.awaitCancellation() } finally { job.cancel() }
    }
    val remote = CallController.videos.firstOrNull { !it.isLocal }
    Box(Modifier.fillMaxSize().background(CallBackdropBrush), contentAlignment = Alignment.Center) {
        if (remote != null) {
            key(remote.track) { VideoSurface(remote, Modifier.fillMaxSize()) }
        } else {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                ChAvatar(CallController.avatar, CallController.title, CallController.peerKey, size = 56.dp)
                Spacer(Modifier.height(6.dp))
                Text(ltrTitle(CallController.title), color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                Text(
                    if (CallController.phase == CallPhase.Active) durationText(tick * 1000) else stringResource(R.string.ch_call_calling),
                    color = Color.White.copy(alpha = 0.8f), fontSize = 12.sp,
                )
            }
        }
    }
}

@Composable
private fun CallScreen() {
    val context = LocalContext.current
    val phase = CallController.phase ?: CallPhase.Ended
    val video = CallController.isVideo
    var controlsVisible by remember { mutableStateOf(true) }
    var locked by remember { mutableStateOf(false) }
    var tick by remember { mutableLongStateOf(0L) }
    val remote = CallController.videos.firstOrNull { !it.isLocal }
    val local = CallController.videos.firstOrNull { it.isLocal }
    // A group call (or anyone who joined a 1:1 later) shows everyone as a grid instead of one face.
    val grouped = phase == CallPhase.Active &&
        (CallController.call?.conversationType == "group" || CallController.members.count { !it.isLocal } > 1)

    // Back minimises the call (it goes on, small, while I use the app) — it's ended with the red
    // button, never by accident. A ringing call stays on screen until answered or declined.
    BackHandler(enabled = true) {
        if (phase == CallPhase.Outgoing || phase == CallPhase.Connecting || phase == CallPhase.Active) CallController.minimized = true
    }

    LaunchedEffect(Unit) {
        val job = CallController.keepTicking { tick = CallController.elapsedSeconds() }
        try { kotlinx.coroutines.awaitCancellation() } finally { job.cancel() }
    }
    // In a video call, controls hide themselves; a tap brings them back.
    LaunchedEffect(controlsVisible, phase, remote != null, grouped, locked) {
        if (locked) {
            controlsVisible = true
            return@LaunchedEffect
        }
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
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) {
                if (!locked) controlsVisible = !controlsVisible
            },
    ) {
        // ------------------------------------------------------------ group: everyone in a grid
        if (grouped) {
            AnimatedBackdrop()
            GroupGrid(Modifier.fillMaxSize().padding(top = 96.dp, bottom = 124.dp).navigationBarsPadding())
            Column(Modifier.fillMaxWidth().padding(top = 34.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Text(ltrTitle(CallController.title), color = Color.White, fontSize = 19.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
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
                Text(ltrTitle(CallController.title), color = Color.White, fontSize = 27.sp, fontWeight = FontWeight.ExtraBold)
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

        // ------------------------------------------------------------ minimise (like the back button)
        if (phase == CallPhase.Outgoing || phase == CallPhase.Connecting || phase == CallPhase.Active) {
            Box(
                Modifier.align(Alignment.TopStart).statusBarsPadding().padding(12.dp).size(42.dp).clip(CircleShape)
                    .background(Color.White.copy(alpha = 0.16f)).clickable { CallController.minimized = true },
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.KeyboardArrowDown, stringResource(R.string.ch_call_minimize), tint = Color.White, modifier = Modifier.size(26.dp)) }
        }

        // ------------------------------------------------------------ my screen is going out: say so, with "Stop"
        if (CallController.sharingScreen && phase == CallPhase.Active) Row(
            Modifier.align(Alignment.TopCenter).statusBarsPadding().padding(top = 8.dp).clip(RoundedCornerShape(20.dp)).background(Ch.Danger)
                .padding(start = 14.dp, end = 6.dp, top = 6.dp, bottom = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.ScreenShare, null, tint = Color.White, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.ch_call_sharing), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp)
            Spacer(Modifier.width(8.dp))
            Text(
                stringResource(R.string.ch_call_stop_sharing), color = Ch.Danger, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp,
                modifier = Modifier.clip(RoundedCornerShape(14.dp)).background(Color.White).clickable { CallController.stopScreenShare() }.padding(horizontal = 10.dp, vertical = 4.dp),
            )
        }

        // ------------------------------------------------------------ my camera (drag it anywhere, it snaps to a corner)
        if (local != null && remote != null && phase == CallPhase.Active && !grouped) LocalPreview(local)

        // ------------------------------------------------------------ live (spec 93): LIVE · viewers
        CallController.live?.takeIf { it.status == "live" && phase == CallPhase.Active }?.let { l ->
            var sharing by remember { mutableStateOf(false) }
            Box(Modifier.align(Alignment.TopCenter)) { LiveBadge(l) { sharing = true } }
            if (sharing) LiveShareSheet(l) { sharing = false }
        }

        // ------------------------------------------------------------ controls
        Box(Modifier.align(Alignment.BottomCenter).navigationBarsPadding().padding(bottom = 36.dp)) {
            if (phase == CallPhase.Incoming) {
                IncomingControls(onDecline = { CallController.decline() }, onAccept = { accept() })
            } else {
                AnimatedVisibility(controlsVisible || remote == null || grouped, enter = slideInVertically { it } + fadeIn(), exit = slideOutVertically { it } + fadeOut()) {
                    ActiveControls(video, locked) { locked = it }
                }
            }
        }

        // ------------------------------------------------------------ recording (spec 92): REC for as long as it records,
        // and the questions that come before anything is recorded — on top of everything.
        CallRecordingLayer(phase)
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
private fun ActiveControls(video: Boolean, locked: Boolean, onLocked: (Boolean) -> Unit) {
    val context = LocalContext.current
    val locale = rememberAppLocaleFrame()
    // Share my screen (WhatsApp list 71): Android asks first, then LiveKit captures it.
    val screenAsk = rememberLauncherForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        val data = result.data
        if (result.resultCode == android.app.Activity.RESULT_OK && data != null) CallController.startScreenShare(data)
    }
    var backgrounds by remember { mutableStateOf(false) }
    var goLive by remember { mutableStateOf(false) }
    var liveShare by remember { mutableStateOf(false) }
    var moreOpen by remember { mutableStateOf(false) }
    val cameraAsk = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) CallController.toggleCamera()
        else android.widget.Toast.makeText(context, context.getString(R.string.ch_call_camera_denied), android.widget.Toast.LENGTH_SHORT).show()
    }
    fun unlessLocked(block: () -> Unit) {
        if (!locked) block()
    }
    fun shareScreen() {
        unlessLocked {
            if (CallController.sharingScreen) CallController.stopScreenShare()
            else context.getSystemService(android.media.projection.MediaProjectionManager::class.java)?.let { screenAsk.launch(it.createScreenCaptureIntent()) }
        }
    }
    val liveOn = CallController.live?.status == "live"
    val showLive = com.dorr.app.chat.ChatFeatures.allows("live") || liveOn
    val showFlip = CallController.cameraOn
    val showBlur = CallController.cameraOn && !com.dorr.app.chat.ChatLite.active
    val extras = 4 /* mic, speaker, camera, share */ + 1 /* lock */ + (if (showLive) 1 else 0) + (if (showFlip) 1 else 0) + (if (showBlur) 1 else 0)
    val overflow = extras > 6
    Column(
        Modifier.padding(horizontal = 12.dp).clip(RoundedCornerShape(36.dp)).background(Color.White.copy(alpha = 0.12f)).padding(horizontal = 18.dp, vertical = 14.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly, verticalAlignment = Alignment.CenterVertically) {
            ToggleAction(if (CallController.cameraOn) Icons.Rounded.Videocam else Icons.Rounded.VideocamOff, CallController.cameraOn, enabled = !locked) {
                unlessLocked {
                    if (!CallController.cameraOn && !CallController.cameraAllowed()) cameraAsk.launch(Manifest.permission.CAMERA)
                    else CallController.toggleCamera()
                }
            }
            ToggleAction(Icons.Rounded.VolumeUp, CallController.speakerOn, enabled = !locked) { unlessLocked { CallController.toggleSpeaker() } }
            ToggleAction(if (CallController.micOn) Icons.Rounded.Mic else Icons.Rounded.MicOff, !CallController.micOn, enabled = !locked) { unlessLocked { CallController.toggleMic() } }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly, verticalAlignment = Alignment.CenterVertically) {
            if (overflow) {
                if (showLive) {
                    ToggleAction(Icons.Rounded.Podcasts, liveOn, enabled = !locked) {
                        unlessLocked { if (liveOn) liveShare = true else goLive = true }
                    }
                } else {
                    Spacer(Modifier.size(52.dp))
                }
                if (showFlip) {
                    ToggleAction(Icons.Rounded.Cameraswitch, CallController.flippedCamera, enabled = !locked) { unlessLocked { CallController.flipCamera() } }
                } else {
                    Spacer(Modifier.size(52.dp))
                }
                Box {
                    ToggleAction(Icons.Rounded.MoreVert, moreOpen, enabled = true) { moreOpen = true }
                    DropdownMenu(
                        expanded = moreOpen,
                        onDismissRequest = { moreOpen = false },
                        modifier = Modifier.widthIn(min = 220.dp),
                        shape = RoundedCornerShape(18.dp),
                        containerColor = Ch.SurfaceMuted,
                        tonalElevation = 0.dp,
                        shadowElevation = 12.dp,
                    ) {
                        locale.Provide {
                        CallMoreItem(if (locked) Icons.Rounded.Lock else Icons.Rounded.LockOpen, stringResource(if (locked) R.string.ch_call_unlock else R.string.ch_call_lock)) {
                            moreOpen = false
                            onLocked(!locked)
                        }
                        CallMoreItem(Icons.Rounded.ScreenShare, stringResource(R.string.ch_call_share_screen)) {
                            moreOpen = false
                            shareScreen()
                        }
                        if (showBlur) {
                            CallMoreItem(Icons.Rounded.BlurOn, stringResource(R.string.ce_title)) {
                                moreOpen = false
                                unlessLocked { backgrounds = true }
                            }
                        }
                        }
                    }
                }
            } else {
                ToggleAction(Icons.Rounded.ScreenShare, CallController.sharingScreen, enabled = !locked) { shareScreen() }
                if (showLive) {
                    ToggleAction(Icons.Rounded.Podcasts, liveOn, enabled = !locked) {
                        unlessLocked { if (liveOn) liveShare = true else goLive = true }
                    }
                } else if (showFlip) {
                    ToggleAction(Icons.Rounded.Cameraswitch, CallController.flippedCamera, enabled = !locked) { unlessLocked { CallController.flipCamera() } }
                } else {
                    Spacer(Modifier.size(52.dp))
                }
                ToggleAction(if (locked) Icons.Rounded.Lock else Icons.Rounded.LockOpen, locked) { onLocked(!locked) }
            }
        }
        RoundAction(Icons.Rounded.CallEnd, null, if (locked) Ch.Danger.copy(alpha = 0.4f) else Ch.Danger, size = 60.dp) { unlessLocked { CallController.hangUp() } }
    }
    if (backgrounds) CallBackgroundSheet { backgrounds = false }
    if (goLive) StartLiveSheet { goLive = false }
    CallController.live?.takeIf { liveShare }?.let { l -> LiveShareSheet(l) { liveShare = false } }
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
private fun CallMoreItem(icon: ImageVector, text: String, onClick: () -> Unit) {
    Row(
        Modifier.clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(12.dp))
        Text(text, color = Ch.Ink, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
    }
}

@Composable
private fun ToggleAction(icon: ImageVector, active: Boolean, enabled: Boolean = true, onClick: () -> Unit) {
    val bg by animateColorAsState(if (active) Color.White else Color.White.copy(alpha = 0.16f), label = "toggle")
    val fg by animateColorAsState(if (active) Color.Black else Color.White, label = "toggleFg")
    Box(
        Modifier.size(52.dp).graphicsLayer { alpha = if (enabled) 1f else 0.4f }.clip(CircleShape).background(bg).clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
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
                // A shared screen is shown whole, never cropped.
                if (video.screen) setScalingType(livekit.org.webrtc.RendererCommon.ScalingType.SCALE_ASPECT_FIT)
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
    BoxWithConstraints(Modifier.fillMaxSize().padding(16.dp), contentAlignment = AbsoluteAlignment.TopLeft) {
        val w = with(density) { 110.dp.toPx() }
        val h = with(density) { 160.dp.toPx() }
        val maxX = with(density) { maxWidth.toPx() } - w
        val maxY = with(density) { maxHeight.toPx() } - h - with(density) { 120.dp.toPx() }
        val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
        val x = remember { Animatable(if (rtl) 0f else maxX) }
        val y = remember { Animatable(with(density) { 40.dp.toPx() }) }
        Box(
            Modifier
                .absoluteOffset { IntOffset(x.value.roundToInt(), y.value.roundToInt()) }
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
