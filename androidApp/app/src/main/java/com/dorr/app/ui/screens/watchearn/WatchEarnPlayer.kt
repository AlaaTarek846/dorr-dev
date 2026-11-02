package com.dorr.app.ui.screens.watchearn

import android.content.Intent
import android.media.MediaPlayer
import android.net.Uri
import android.widget.VideoView
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
import androidx.compose.foundation.gestures.waitForUpOrCancellation
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.OpenInNew
import androidx.compose.material.icons.automirrored.rounded.VolumeUp
import androidx.compose.material.icons.rounded.ChatBubbleOutline
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.EmojiEvents
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.MoreVert
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.SkipNext
import androidx.compose.material.icons.rounded.Timer
import androidx.compose.material.icons.rounded.VolumeOff
import androidx.compose.material.icons.rounded.WifiOff
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.blur
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.WeCompleteDto
import com.dorr.app.network.WeCurrencyDto
import com.dorr.app.network.WeFeedAdDto
import com.dorr.app.network.WeFeedDto
import com.dorr.app.network.WeReactionRequest
import com.dorr.app.network.WeStartDto
import com.dorr.app.network.WeStartRequest
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlin.math.abs

/** The chat's quick reactions, in the same order. */
internal val WeQuickReactions = listOf("❤️", "😂", "😮", "😢", "👏", "🔥")

/** Auto mode: how long the end of an ad stays before the next one (device clock). */
private const val AUTO_NEXT_MS = 4_500L

/** Errors that mean "this ad is not for me now": go on to the next one. */
private val SKIP_CODES = setOf("watch_earn_not_eligible", "watch_earn_already_watched", "watch_earn_ad_unavailable")

private enum class Phase { Starting, Playing, Completing, Ended }

/**
 * The player, like statuses (D5/D6): one progress bar per ad, the advertiser, ✕ and ⋮ (report); the title, text and
 * link at the bottom. Each ad starts on the server when it shows; an image counts its seconds on the device clock, a
 * video plays to its end (muted until 🔈). Holding pauses, and so does opening the comments. At the end the view is
 * completed, the reward toast pops, and only then the interaction bar slides up with the window's countdown.
 * When the feed is done, a summary shows what this session earned.
 */
@Composable
fun WatchEarnPlayer(feed: WeFeedDto) {
    val host = LocalWe.current
    val ads = feed.ads
    var index by remember { mutableIntStateOf(0) }
    var earnedMinor by remember { mutableLongStateOf(0L) }
    var watched by remember { mutableIntStateOf(0) }
    val currency = ads.firstOrNull()?.currency

    if (index >= ads.size) {
        PlayerSummary(earnedMinor, watched, currency, onRewards = { host.replace(WeRoute.Rewards) }, onClose = { host.pop() })
        return
    }
    val ad = ads[index]
    key(ad.id) {
        AdPage(
            ad = ad,
            feed = feed,
            index = index,
            onEarned = { earnedMinor += it },
            onWatched = { watched++ },
            onNext = { index++ },
            onClose = { host.pop() },
        )
    }
}

@Composable
private fun AdPage(
    ad: WeFeedAdDto,
    feed: WeFeedDto,
    index: Int,
    onEarned: (Long) -> Unit,
    onWatched: () -> Unit,
    onNext: () -> Unit,
    onClose: () -> Unit,
) {
    val host = LocalWe.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.we_error_generic)
    val reportedText = stringResource(R.string.we_reported)

    var phase by remember { mutableStateOf(Phase.Starting) }
    var start by remember { mutableStateOf<WeStartDto?>(null) }
    var startedAtMs by remember { mutableLongStateOf(0L) }
    var offline by remember { mutableStateOf(false) }
    var retry by remember { mutableIntStateOf(0) }
    val progress = remember { Animatable(0f) }
    var holding by remember { mutableStateOf(false) }
    var commentsOpen by remember { mutableStateOf(false) }
    var reportOpen by remember { mutableStateOf(false) }
    var menu by remember { mutableStateOf(false) }
    var muted by remember { mutableStateOf(true) }
    var videoMs by remember { mutableStateOf<Long?>(null) }
    var videoEnded by remember { mutableStateOf(false) }
    var complete by remember { mutableStateOf<WeCompleteDto?>(null) }
    var interacted by remember { mutableStateOf(false) }
    var myReaction by remember { mutableStateOf<String?>(null) }
    var toastFx by remember { mutableStateOf<RewardFx?>(null) }
    var nowMs by remember { mutableLongStateOf(System.currentTimeMillis()) }
    val paused = holding || commentsOpen || reportOpen || menu
    val isVideo = ad.media.type == "video"
    val currentOnNext by rememberUpdatedState(onNext)

    val deadlineMs = remember(complete) { weInstant(complete?.interactionDeadlineAt)?.toEpochMilli() }
    val windowOpen = complete?.canInteract == true && deadlineMs != null && nowMs < deadlineMs && !interacted

    // 1. The ad starts on the server as it shows.
    LaunchedEffect(retry) {
        offline = false
        try {
            val s = ApiClient.watchEarn.start(weAuth(), ad.id, WeStartRequest()).data ?: throw IllegalStateException()
            start = s
            startedAtMs = System.currentTimeMillis()
            phase = Phase.Playing
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            val failure = e.apiFailure()
            when {
                failure.httpStatus == null -> offline = true
                failure.errorCode in SKIP_CODES -> currentOnNext()
                else -> {
                    host.showToast(failure.message ?: generic, error = true)
                    currentOnNext()
                }
            }
        }
    }

    // 2. The bar: an image's seconds, or the video's own length — stopped while held or while a sheet is open.
    val totalMs: Long? = if (isVideo) videoMs else ((start?.seconds ?: ad.media.seconds ?: feed.player.imageSeconds).coerceAtLeast(1)) * 1000L
    LaunchedEffect(phase, paused, totalMs) {
        if (phase != Phase.Playing || paused || totalMs == null) return@LaunchedEffect
        val remaining = ((1f - progress.value) * totalMs).toLong().coerceAtLeast(1L)
        progress.animateTo(1f, tween(remaining.toInt(), easing = LinearEasing))
        // A video ends when it really ends (it may have buffered); an image when its time is up.
        if (!isVideo) phase = Phase.Completing
    }
    LaunchedEffect(videoEnded) {
        if (videoEnded && phase == Phase.Playing) {
            progress.snapTo(1f)
            phase = Phase.Completing
        }
    }

    // 3. Completed: the server checks the time, charges and rewards. Too fast → wait out the rest, then ask again.
    LaunchedEffect(phase) {
        if (phase != Phase.Completing) return@LaunchedEffect
        val s = start ?: return@LaunchedEffect
        var attempts = 0
        while (true) {
            try {
                val done = ApiClient.watchEarn.complete(weAuth(), s.viewId).data
                complete = done
                nowMs = System.currentTimeMillis()
                onWatched()
                if (ad.earn.viewMinor > 0) {
                    onEarned(ad.earn.viewMinor)
                    toastFx = RewardFx("+ " + weMoney(ad.earn.viewMinor, ad.currency), null)
                }
                phase = Phase.Ended
                break
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                val failure = e.apiFailure()
                when {
                    failure.errorCode == "watch_earn_too_fast" && attempts < 2 -> {
                        attempts++
                        val left = startedAtMs + (s.seconds ?: 0) * 1000L - System.currentTimeMillis()
                        delay(left.coerceIn(400L, 15_000L) + 400L)
                    }
                    failure.errorCode in SKIP_CODES -> {
                        currentOnNext()
                        break
                    }
                    else -> {
                        host.showToast(failure.message ?: generic, error = true)
                        phase = Phase.Ended
                        break
                    }
                }
            }
        }
    }

    // The interaction window's countdown, on the device clock only.
    LaunchedEffect(deadlineMs, interacted) {
        val until = deadlineMs ?: return@LaunchedEffect
        while (!interacted && System.currentTimeMillis() < until) {
            nowMs = System.currentTimeMillis()
            delay(1_000L)
        }
        nowMs = System.currentTimeMillis()
    }

    // Auto mode: on to the next ad a few seconds after the end, unless held or the comments are open.
    LaunchedEffect(phase, paused) {
        if (phase != Phase.Ended || paused || feed.player.mode != "auto") return@LaunchedEffect
        delay(AUTO_NEXT_MS)
        currentOnNext()
    }

    /** The first comment or reaction inside the window earns the interaction reward. */
    fun claimInteraction() {
        // Read from state (not a value of some earlier frame): the sheet may call this long after it opened.
        val done = complete ?: return
        val until = weInstant(done.interactionDeadlineAt)?.toEpochMilli() ?: return
        if (!done.canInteract || interacted || System.currentTimeMillis() >= until) return
        interacted = true
        if (ad.earn.interactionMinor > 0) {
            onEarned(ad.earn.interactionMinor)
            toastFx = RewardFx("+ " + weMoney(ad.earn.interactionMinor, ad.currency), context.getString(R.string.we_interaction_reward))
        }
    }

    fun react(emoji: String) {
        scope.launch {
            try {
                val result = if (myReaction == emoji) ApiClient.watchEarn.unreact(weAuth(), ad.id).data else ApiClient.watchEarn.react(weAuth(), ad.id, WeReactionRequest(emoji)).data
                val before = myReaction
                myReaction = result?.myReaction
                if (before == null && myReaction != null) claimInteraction()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                host.showToast(e.weMessage(generic), error = true)
            }
        }
    }

    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    Box(
        Modifier
            .fillMaxSize()
            .background(Color.Black)
            .pointerInput(Unit) {
                // A swipe sideways goes on to the next ad once this one is over.
                var dragged = 0f
                detectHorizontalDragGestures(
                    onDragEnd = {
                        if (abs(dragged) > 120f && phase == Phase.Ended) currentOnNext()
                        dragged = 0f
                    },
                    onDragCancel = { dragged = 0f },
                ) { change, dx ->
                    change.consume()
                    dragged += dx
                }
            },
    ) {
        // ------------------------------------------------------------ the ad itself
        val ended = phase == Phase.Ended
        val manualClosed = ended && feed.player.mode == "manual"
        if (isVideo) {
            WeVideo(
                url = ApiClient.mediaUrl(ad.media.url),
                playing = phase == Phase.Playing && !paused,
                muted = muted,
                onReady = { videoMs = it },
                onEnded = { videoEnded = true },
                onError = { if (phase == Phase.Playing || phase == Phase.Starting) currentOnNext() },
            )
        } else {
            WeImage(ApiClient.mediaUrl(ad.media.url), zooming = phase == Phase.Playing && !paused, seconds = (totalMs ?: 8000L))
        }
        // Manual mode: the ad view closes at the end — a dim card says a tap goes on.
        AnimatedVisibility(manualClosed, enter = fadeIn(tween(350)), exit = fadeOut()) {
            Box(Modifier.fillMaxSize().background(Color.Black.copy(alpha = 0.78f)), contentAlignment = Alignment.Center) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Icon(Icons.Rounded.SkipNext, null, tint = Color.White, modifier = Modifier.size(44.dp))
                    Spacer(Modifier.height(8.dp))
                    Text(stringResource(R.string.we_ad_over), color = Color.White, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.we_tap_for_next), color = Color.White.copy(alpha = 0.75f), fontSize = 13.sp)
                }
            }
        }

        // ------------------------------------------------------------ hold to pause; a tap after the end goes on
        Box(
            Modifier.fillMaxSize().pointerInput(Unit) {
                awaitEachGesture {
                    awaitFirstDown()
                    val startedAt = System.currentTimeMillis()
                    holding = true
                    val up = waitForUpOrCancellation()
                    holding = false
                    if (up != null && System.currentTimeMillis() - startedAt < 220 && phase == Phase.Ended) currentOnNext()
                }
            },
        )

        if (phase == Phase.Starting && !offline) {
            CircularProgressIndicator(color = Color.White, strokeWidth = 2.5.dp, modifier = Modifier.align(Alignment.Center).size(34.dp))
        }
        if (offline) {
            Column(Modifier.align(Alignment.Center).padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Icon(Icons.Rounded.WifiOff, null, tint = Color.White, modifier = Modifier.size(40.dp))
                Spacer(Modifier.height(10.dp))
                Text(stringResource(R.string.we_error_network), color = Color.White, fontSize = 14.sp, textAlign = TextAlign.Center)
                Spacer(Modifier.height(14.dp))
                WaButton(stringResource(R.string.we_retry), { retry++ }, compact = true, modifier = Modifier.width(160.dp))
            }
        }

        // ------------------------------------------------------------ top: one bar per ad, the advertiser, ⋮ and ✕
        AnimatedVisibility(!holding, enter = fadeIn(), exit = fadeOut(tween(150))) {
            Column(
                Modifier.fillMaxWidth()
                    .background(Brush.verticalGradient(listOf(Color.Black.copy(alpha = 0.6f), Color.Transparent)))
                    .padding(start = 10.dp, end = 10.dp, top = 10.dp, bottom = 26.dp),
            ) {
                Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    feed.ads.forEachIndexed { i, _ ->
                        val fill = when {
                            i < index -> 1f
                            i == index -> progress.value
                            else -> 0f
                        }
                        Box(Modifier.weight(1f).height(3.dp).clip(RoundedCornerShape(2.dp)).background(Color.White.copy(alpha = 0.3f))) {
                            Box(Modifier.fillMaxHeight().fillMaxWidth(fill).background(Color.White))
                        }
                    }
                }
                Spacer(Modifier.height(10.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    AdvertiserAvatar(ad.advertiser?.avatar, ad.advertiser?.name)
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text(ad.advertiser?.name.orEmpty(), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(stringResource(R.string.we_sponsored), color = Color.White.copy(alpha = 0.75f), fontSize = 11.5.sp)
                            if (ad.earn.viewMinor > 0) {
                                Spacer(Modifier.width(8.dp))
                                Row(
                                    Modifier.clip(RoundedCornerShape(99.dp)).background(We.RewardBrush).padding(horizontal = 8.dp, vertical = 2.dp),
                                    verticalAlignment = Alignment.CenterVertically,
                                ) {
                                    Icon(Icons.Rounded.EmojiEvents, null, tint = Color.White, modifier = Modifier.size(11.dp))
                                    Spacer(Modifier.width(3.dp))
                                    WeLtr { Text("+ " + weMoney(ad.earn.viewMinor, ad.currency), color = Color.White, fontSize = 10.5.sp, fontWeight = FontWeight.ExtraBold) }
                                }
                            }
                        }
                    }
                    if (isVideo) {
                        Icon(
                            if (muted) Icons.Rounded.VolumeOff else Icons.AutoMirrored.Rounded.VolumeUp,
                            stringResource(if (muted) R.string.we_unmute else R.string.we_mute),
                            tint = Color.White,
                            modifier = Modifier.size(40.dp).clip(CircleShape).clickable { muted = !muted }.padding(9.dp),
                        )
                    }
                    Box {
                        Icon(
                            Icons.Rounded.MoreVert, stringResource(R.string.we_more), tint = Color.White,
                            modifier = Modifier.size(40.dp).clip(CircleShape).clickable { menu = true }.padding(8.dp),
                        )
                        DropdownMenu(menu, onDismissRequest = { menu = false }, containerColor = Wa.Surface, shape = RoundedCornerShape(16.dp)) {
                            DropdownMenuItem(
                                text = { Text(stringResource(R.string.we_report_ad), color = Wa.Ink) },
                                leadingIcon = { Icon(Icons.Rounded.Flag, null, tint = Wa.Danger) },
                                onClick = { menu = false; reportOpen = true },
                            )
                        }
                    }
                    Icon(
                        Icons.Rounded.Close, stringResource(R.string.we_close), tint = Color.White,
                        modifier = Modifier.size(40.dp).clip(CircleShape).clickable(onClick = onClose).padding(8.dp),
                    )
                }
            }
        }

        // ------------------------------------------------------------ the reward toast
        toastFx?.let { fx -> key(fx.id) { RewardToast(fx, Modifier.align(Alignment.Center)) { if (toastFx?.id == fx.id) toastFx = null } } }

        // ------------------------------------------------------------ bottom: title, text, link — then the interaction bar
        Column(Modifier.align(Alignment.BottomCenter).fillMaxWidth()) {
            AnimatedVisibility(!holding, enter = fadeIn(), exit = fadeOut(tween(150))) {
                Column(
                    Modifier.fillMaxWidth()
                        .background(Brush.verticalGradient(listOf(Color.Transparent, Color.Black.copy(alpha = 0.7f))))
                        .padding(start = 16.dp, end = 16.dp, top = 30.dp, bottom = if (ended) 6.dp else 18.dp),
                ) {
                    Text(ad.title, color = Color.White, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    if (!ad.body.isNullOrBlank()) {
                        Text(ad.body, color = Color.White.copy(alpha = 0.9f), fontSize = 13.5.sp, lineHeight = 20.sp, maxLines = 3, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 4.dp))
                    }
                    val link = ad.linkUrl
                    if (!link.isNullOrBlank()) {
                        Row(
                            Modifier.padding(top = 10.dp).clip(RoundedCornerShape(99.dp)).background(Color.White)
                                .clickable {
                                    menu = false
                                    runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(link))) }
                                }
                                .padding(horizontal = 14.dp, vertical = 7.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Text(stringResource(R.string.we_open_link), color = Color.Black, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                            Spacer(Modifier.width(6.dp))
                            Icon(Icons.AutoMirrored.Rounded.OpenInNew, null, tint = Color.Black, modifier = Modifier.size(15.dp))
                        }
                    }
                }
            }
            // Only once the ad is over: the chat's quick reactions and «write a comment», sliding up.
            AnimatedVisibility(
                visible = ended,
                enter = slideInVertically(spring(dampingRatio = 0.75f, stiffness = 300f)) { it } + fadeIn(tween(250)),
                exit = slideOutVertically { it } + fadeOut(),
            ) {
                InteractionBar(
                    windowOpen = windowOpen,
                    leftMs = (deadlineMs ?: 0L) - nowMs,
                    interactionMinor = ad.earn.interactionMinor,
                    currency = ad.currency,
                    myReaction = myReaction,
                    comments = ad.counts.comments,
                    onReact = { react(it) },
                    onComment = { commentsOpen = true },
                )
            }
        }
    }

    if (commentsOpen) {
        WatchEarnCommentsSheet(
            adId = ad.id,
            onDismiss = { commentsOpen = false },
            onInteracted = { claimInteraction() },
            onReportAd = { commentsOpen = false; reportOpen = true },
        )
    }
    if (reportOpen) {
        WeReportAdSheet(
            adId = ad.id,
            onDismiss = { reportOpen = false },
            onReported = { message ->
                reportOpen = false
                host.showToast(message.ifBlank { reportedText })
                currentOnNext()
            },
        )
    }
}

// ------------------------------------------------------------------------------- media

@Composable
private fun WeImage(url: String?, zooming: Boolean, seconds: Long) {
    Box(Modifier.fillMaxSize()) {
        // A blurred copy fills the edges of a photo that isn't phone-shaped.
        AsyncImage(
            url, null, contentScale = ContentScale.Crop,
            modifier = Modifier.fillMaxSize().graphicsLayer { alpha = 0.5f }
                .then(if (android.os.Build.VERSION.SDK_INT >= 31) Modifier.blur(40.dp) else Modifier),
        )
        // A very slow zoom while it counts (Ken Burns), like statuses.
        val zoom by animateFloatAsState(if (zooming) 1.07f else 1f, tween(if (zooming) seconds.toInt() else 300, easing = LinearEasing), label = "zoom")
        AsyncImage(url, null, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize().graphicsLayer { scaleX = zoom; scaleY = zoom })
    }
}

/**
 * The ad's video: plays to the end, never loops. Sound is on the track but starts muted (the 🔈 button turns it on).
 * Holding, a sheet or the end stop it.
 */
@Composable
private fun WeVideo(url: String?, playing: Boolean, muted: Boolean, onReady: (Long) -> Unit, onEnded: () -> Unit, onError: () -> Unit) {
    var player by remember { mutableStateOf<MediaPlayer?>(null) }
    var finished by remember { mutableStateOf(false) }
    val isPlaying by rememberUpdatedState(playing)
    val isMuted by rememberUpdatedState(muted)
    val ready by rememberUpdatedState(onReady)
    val ended by rememberUpdatedState(onEnded)
    val failed by rememberUpdatedState(onError)
    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        AndroidView(
            factory = { ctx ->
                VideoView(ctx).apply {
                    setVideoURI(Uri.parse(url))
                    setOnPreparedListener { mp ->
                        mp.isLooping = false
                        player = mp
                        val volume = if (isMuted) 0f else 1f
                        runCatching { mp.setVolume(volume, volume) }
                        ready(mp.duration.toLong().coerceAtLeast(1_000L))
                        if (isPlaying) start()
                    }
                    setOnCompletionListener {
                        finished = true
                        ended()
                    }
                    setOnErrorListener { _, _, _ ->
                        failed()
                        true
                    }
                }
            },
            update = { view ->
                if (finished) return@AndroidView
                if (playing && !view.isPlaying && player != null) view.start() else if (!playing && view.isPlaying) view.pause()
            },
            onRelease = { it.stopPlayback() },
            modifier = Modifier.fillMaxWidth(),
        )
    }
    LaunchedEffect(muted, player) {
        val mp = player ?: return@LaunchedEffect
        val volume = if (muted) 0f else 1f
        runCatching { mp.setVolume(volume, volume) }
    }
}

@Composable
private fun AdvertiserAvatar(avatar: String?, name: String?) {
    Box(
        Modifier.size(38.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.2f)).border(1.5.dp, Color.White.copy(alpha = 0.6f), CircleShape),
        contentAlignment = Alignment.Center,
    ) {
        val url = ApiClient.mediaUrl(avatar)
        if (url != null) {
            AsyncImage(url, null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
        } else {
            val initial = name?.trim()?.firstOrNull()?.toString()
            if (initial != null) Text(initial, color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
            else Icon(Icons.Rounded.Person, null, tint = Color.White, modifier = Modifier.size(20.dp))
        }
    }
}

// ------------------------------------------------------------------------------- the interaction bar

@Composable
private fun InteractionBar(
    windowOpen: Boolean,
    leftMs: Long,
    interactionMinor: Long,
    currency: WeCurrencyDto,
    myReaction: String?,
    comments: Int,
    onReact: (String) -> Unit,
    onComment: () -> Unit,
) {
    Column(
        Modifier.fillMaxWidth()
            .background(Color.Black.copy(alpha = 0.72f))
            .padding(horizontal = 12.dp, vertical = 12.dp),
    ) {
        if (windowOpen && interactionMinor > 0) {
            Row(
                Modifier.align(Alignment.CenterHorizontally).padding(bottom = 10.dp)
                    .clip(RoundedCornerShape(99.dp)).background(We.Interaction.copy(alpha = 0.85f)).padding(horizontal = 12.dp, vertical = 5.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Timer, null, tint = Color.White, modifier = Modifier.size(14.dp))
                Spacer(Modifier.width(6.dp))
                Text(
                    stringResource(R.string.we_interaction_window, weMoney(interactionMinor, currency)),
                    color = Color.White, fontSize = 11.5.sp, fontWeight = FontWeight.Bold,
                )
                Spacer(Modifier.width(6.dp))
                WeLtr { Text(weCountdown(leftMs), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold) }
            }
        }
        Row(Modifier.fillMaxWidth().padding(bottom = 10.dp), horizontalArrangement = Arrangement.SpaceEvenly) {
            WeQuickReactions.forEachIndexed { i, emoji ->
                val pop = remember { Animatable(0f) }
                LaunchedEffect(Unit) {
                    delay(140L + i * 55L)
                    pop.animateTo(1f, spring(dampingRatio = 0.4f, stiffness = 450f))
                }
                var bounce by remember { mutableStateOf(false) }
                val scale by animateFloatAsState(if (bounce) 1.45f else 1f, spring(dampingRatio = 0.3f, stiffness = 600f), finishedListener = { bounce = false }, label = "react")
                val mine = myReaction == emoji
                Text(
                    emoji, fontSize = 27.sp,
                    modifier = Modifier
                        .graphicsLayer { val p = pop.value * scale; scaleX = p; scaleY = p }
                        .clip(CircleShape)
                        .background(if (mine) Color.White.copy(alpha = 0.25f) else Color.Transparent)
                        .clickable { bounce = true; onReact(emoji) }
                        .padding(6.dp),
                )
            }
        }
        Row(
            Modifier.fillMaxWidth().height(46.dp).clip(RoundedCornerShape(23.dp)).background(Color.White.copy(alpha = 0.16f))
                .clickable(onClick = onComment).padding(horizontal = 16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.ChatBubbleOutline, null, tint = Color.White, modifier = Modifier.size(19.dp))
            Spacer(Modifier.width(10.dp))
            Text(stringResource(R.string.we_write_comment), color = Color.White.copy(alpha = 0.85f), fontSize = 14.sp, modifier = Modifier.weight(1f))
            if (comments > 0) Text(comments.toString(), color = Color.White.copy(alpha = 0.7f), fontSize = 12.sp, fontWeight = FontWeight.Bold)
        }
    }
}

// ------------------------------------------------------------------------------- the reward toast

private class RewardFx(val amount: String, val caption: String?) {
    val id: Long = System.nanoTime()
}

/** «+ 1.50 SAR» pops up big, floats a little higher and fades — the interaction one carries its caption. */
@Composable
private fun RewardToast(fx: RewardFx, modifier: Modifier, onDone: () -> Unit) {
    val t = remember { Animatable(0f) }
    val out = remember { Animatable(0f) }
    LaunchedEffect(Unit) {
        t.animateTo(1f, spring(dampingRatio = 0.45f, stiffness = 380f))
        delay(1_300L)
        out.animateTo(1f, tween(450, easing = FastOutSlowInEasing))
        onDone()
    }
    val shine = rememberInfiniteTransition(label = "shine")
    val glow by shine.animateFloat(0.55f, 1f, infiniteRepeatable(tween(600), RepeatMode.Reverse), label = "glow")
    Column(
        modifier.graphicsLayer {
            val s = 0.4f + 0.6f * t.value
            scaleX = s
            scaleY = s
            alpha = (t.value.coerceIn(0f, 1f)) * (1f - out.value)
            translationY = -out.value * 90.dp.toPx()
        },
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Row(
            Modifier.clip(RoundedCornerShape(99.dp)).background(We.RewardBrush)
                .border(2.dp, Color.White.copy(alpha = glow), RoundedCornerShape(99.dp))
                .padding(horizontal = 20.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.EmojiEvents, null, tint = Color.White, modifier = Modifier.size(24.dp))
            Spacer(Modifier.width(8.dp))
            WeLtr { Text(fx.amount, color = Color.White, fontSize = 24.sp, fontWeight = FontWeight.Black) }
        }
        if (fx.caption != null) {
            Text(
                fx.caption, color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.padding(top = 8.dp).clip(RoundedCornerShape(99.dp)).background(We.Interaction).padding(horizontal = 12.dp, vertical = 4.dp),
            )
        }
    }
}

// ------------------------------------------------------------------------------- the end of the feed

@Composable
private fun PlayerSummary(earnedMinor: Long, watched: Int, currency: WeCurrencyDto?, onRewards: () -> Unit, onClose: () -> Unit) {
    val float = rememberInfiniteTransition(label = "trophy")
    val dy by float.animateFloat(0f, -10f, infiniteRepeatable(tween(1500, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "dy")
    Box(
        Modifier.fillMaxSize().background(Brush.verticalGradient(listOf(Color(0xFF111827), Color.Black))),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            Modifier.padding(24.dp).fillMaxWidth().clip(RoundedCornerShape(28.dp)).background(Wa.Surface).padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Box(
                Modifier.size(86.dp).graphicsLayer { translationY = dy.dp.toPx() }.clip(CircleShape).background(We.RewardBrush),
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.EmojiEvents, null, tint = Color.White, modifier = Modifier.size(46.dp)) }
            Spacer(Modifier.height(16.dp))
            Text(stringResource(R.string.we_summary_title), color = Wa.Ink, fontSize = 20.sp, fontWeight = FontWeight.Black)
            Text(stringResource(R.string.we_summary_watched, watched), color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp))
            Spacer(Modifier.height(14.dp))
            Text(stringResource(R.string.we_summary_earned), color = Wa.Mut, fontSize = 12.sp)
            WeCountUp(earnedMinor, { weMoney(it, currency) }, color = We.Reward, fontSize = 30.sp, durationMs = 1200)
            Text(
                stringResource(R.string.we_summary_note), color = Wa.Mut, fontSize = 12.sp, textAlign = TextAlign.Center, lineHeight = 19.sp,
                modifier = Modifier.padding(top = 10.dp),
            )
            Spacer(Modifier.height(18.dp))
            WaButton(stringResource(R.string.we_my_rewards), onRewards, icon = Icons.Rounded.EmojiEvents)
            Spacer(Modifier.height(8.dp))
            WaButton(stringResource(R.string.we_close), onClose, style = WaButtonStyle.Quiet)
        }
    }
}
