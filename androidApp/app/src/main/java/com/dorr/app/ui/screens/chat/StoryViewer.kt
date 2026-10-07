package com.dorr.app.ui.screens.chat

import android.app.Activity
import android.net.Uri
import android.view.WindowManager
import android.widget.VideoView
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.gestures.detectVerticalDragGestures
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
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.PagerState
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.KeyboardArrowUp
import androidx.compose.material.icons.rounded.MoreVert
import androidx.compose.material.icons.rounded.NotificationsOff
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.runtime.key
import androidx.compose.ui.draw.blur
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.TransformOrigin
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.StoryDto
import com.dorr.app.network.StoryGroupDto
import com.dorr.app.network.StoryViewerDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.theme.CairoFontFamily
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlin.math.absoluteValue
import kotlin.random.Random

private const val PHOTO_MS = 5500L
private val QuickStoryReactions = listOf("❤️", "😂", "😮", "😢", "👏", "🔥")

/** The full-screen stories viewer, over the chat. */
@Composable
fun StoryViewerOverlay() {
    val host = LocalChat.current
    val session = host.storyViewer
    AnimatedVisibility(
        visible = session != null,
        enter = fadeIn(tween(220)) + androidx.compose.animation.scaleIn(spring(dampingRatio = 0.85f, stiffness = 380f), initialScale = 0.86f),
        exit = fadeOut(tween(200)) + androidx.compose.animation.scaleOut(tween(220), targetScale = 0.9f),
    ) {
        // Keep the last session while the exit animation runs.
        var last by remember { mutableStateOf(session) }
        if (session != null) last = session
        last?.let { StoryViewer(it.groups, it.start) { host.storyViewer = null; host.scope.launch { host.refreshStories() } } }
    }
}

@Composable
private fun StoryViewer(groups: List<StoryGroupDto>, start: Int, onClose: () -> Unit) {
    val pager = rememberPagerState(initialPage = start) { groups.size }
    val scope = rememberCoroutineScope()
    val drag = remember { Animatable(0f) }
    val context = LocalContext.current

    BackHandler { onClose() }

    // Screenshots off when this person asked for it.
    val secure = groups.getOrNull(pager.currentPage)?.blockScreenshots == true
    DisposableEffect(secure) {
        val window = (context as? Activity)?.window
        if (secure) window?.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
        onDispose { window?.clearFlags(WindowManager.LayoutParams.FLAG_SECURE) }
    }

    val dismiss = (drag.value / 1400f).coerceIn(0f, 1f)
    Box(
        Modifier
            .fillMaxSize()
            .background(Color.Black.copy(alpha = 1f - dismiss))
            .pointerInput(Unit) {
                // Pull down to close: the story shrinks and follows the finger.
                detectVerticalDragGestures(
                    onDragEnd = { if (drag.value > 260f) onClose() else scope.launch { drag.animateTo(0f, spring(dampingRatio = 0.65f)) } },
                    onDragCancel = { scope.launch { drag.animateTo(0f) } },
                ) { change, dy ->
                    if (drag.value + dy >= 0f) {
                        change.consume()
                        scope.launch { drag.snapTo(drag.value + dy) }
                    }
                }
            },
    ) {
        HorizontalPager(
            state = pager,
            beyondViewportPageCount = 1,
            modifier = Modifier.fillMaxSize().graphicsLayer {
                translationY = drag.value
                val s = 1f - dismiss * 0.35f
                scaleX = s
                scaleY = s
                clip = true
                shape = RoundedCornerShape((dismiss * 60).dp)
            },
        ) { page ->
            Box(Modifier.fillMaxSize().cube(pager, page, LocalLayoutDirection.current == LayoutDirection.Rtl)) {
                StoryGroupPage(
                    group = groups[page],
                    active = pager.currentPage == page && !pager.isScrollInProgress && drag.value < 1f,
                    onPrevGroup = { if (page > 0) scope.launch { pager.animateScrollToPage(page - 1) } },
                    onNextGroup = { if (page < groups.lastIndex) scope.launch { pager.animateScrollToPage(page + 1) } else onClose() },
                    onClose = onClose,
                )
            }
        }
    }
}

/** The 3D cube turn between people. */
private fun Modifier.cube(pager: PagerState, page: Int, rtl: Boolean): Modifier = graphicsLayer {
    val offset = (pager.currentPage - page) + pager.currentPageOffsetFraction
    val o = if (rtl) -offset else offset
    cameraDistance = 14f * density
    transformOrigin = TransformOrigin(if (o > 0) 1f else 0f, 0.5f)
    rotationY = (o * -90f).coerceIn(-90f, 90f)
    alpha = if (o.absoluteValue >= 1f) 0f else 1f
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun StoryGroupPage(group: StoryGroupDto, active: Boolean, onPrevGroup: () -> Unit, onNextGroup: () -> Unit, onClose: () -> Unit) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    val stories = remember(group) { mutableStateListOf<StoryDto>().apply { addAll(group.stories) } }
    // Open on the first unseen story (like WhatsApp), or the first one.
    var index by remember(group) { mutableIntStateOf(stories.indexOfFirst { !it.seen }.takeIf { it >= 0 } ?: 0) }
    val progress = remember(group) { Animatable(0f) }
    var paused by remember { mutableStateOf(false) }
    var uiHidden by remember { mutableStateOf(false) }
    var typing by remember { mutableStateOf(false) }
    var videoMs by remember { mutableStateOf<Long?>(null) }
    var viewersFor by remember { mutableStateOf<StoryDto?>(null) }
    var menu by remember { mutableStateOf(false) }
    val bursts = remember { mutableStateListOf<Burst>() }
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val story = stories.getOrNull(index) ?: return
    val owner = group.owner
    val sent = stringResource(R.string.st_replied)
    val deleted = stringResource(R.string.st_deleted)
    val holding = paused || typing || viewersFor != null || menu

    fun next() {
        if (index < stories.lastIndex) index++ else onNextGroup()
    }
    fun prev() {
        if (index > 0) index-- else onPrevGroup()
    }

    // Mark seen as soon as it shows.
    LaunchedEffect(story.id, active) {
        if (active && !story.isMine && !story.seen) {
            runCatching { if (story.isDorr) ApiClient.chat.viewDorrStory(chatAuth(), story.id) else ApiClient.chat.viewStory(chatAuth(), story.id) }
            stories[index] = story.copy(seen = true)
        }
    }

    // The progress bar drives the timing: photo / text for a few seconds, video for its length.
    // A new story starts its bar from zero *here*, before animating: a separate snapTo(0) in its
    // own effect ran right after this one and cancelled the animation, so the bar stood still
    // (on opening, and after every automatic move to the next story) until the screen was touched.
    var progressFor by remember(group) { mutableStateOf<String?>(null) }
    LaunchedEffect(story.id, active, holding, videoMs) {
        if (progressFor != story.id) {
            progressFor = story.id
            progress.snapTo(0f)
        }
        if (!active || holding) return@LaunchedEffect
        val total = if (story.type == "video") (videoMs ?: story.durationMs ?: 15_000L) else PHOTO_MS
        if (story.type == "video" && videoMs == null) return@LaunchedEffect // wait for the video to start
        val remaining = ((1f - progress.value) * total).toLong().coerceAtLeast(1)
        progress.animateTo(1f, tween(remaining.toInt(), easing = LinearEasing))
        next()
    }
    // A new video reports its own length again.
    LaunchedEffect(story.id) { videoMs = null }

    Box(Modifier.fillMaxSize()) {
        // ------------------------------------------------------------ the story itself
        StoryContent(story, playing = active && !holding, onVideoReady = { videoMs = it })

        // ------------------------------------------------------------ taps (next / previous) and hold (pause)
        Box(
            Modifier.fillMaxSize().pointerInput(story.id) {
                awaitEachGesture {
                    val down = awaitFirstDown()
                    val startedAt = System.currentTimeMillis()
                    paused = true
                    // Only a real hold hides the bars — a quick tap mustn't make them blink.
                    val hide = scope.launch { delay(250); uiHidden = true }
                    val up = waitForUpOrCancellation()
                    hide.cancel()
                    uiHidden = false
                    paused = false
                    if (up != null && System.currentTimeMillis() - startedAt < 220) {
                        val leftSide = down.position.x < size.width * 0.33f
                        // The "back" side is the reading start: left in English, right in Arabic.
                        if (leftSide != rtl) prev() else next()
                    }
                }
            },
        )

        // ------------------------------------------------------------ top: bars + owner
        AnimatedVisibility(!uiHidden, enter = fadeIn(), exit = fadeOut(tween(150))) {
            Column(Modifier.fillMaxWidth().background(Brush.verticalGradient(listOf(Color.Black.copy(alpha = 0.55f), Color.Transparent))).padding(top = 10.dp, start = 10.dp, end = 10.dp, bottom = 26.dp)) {
                Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                    stories.forEachIndexed { i, _ ->
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
                    if (story.isDorr) DorrAvatar(38.dp) else ChAvatar(owner?.avatar, owner?.name, owner?.key, size = 38.dp)
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text(if (story.isMine) stringResource(R.string.st_my_status) else owner?.name.orEmpty(), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
                        Text(timeAgo(story.createdAt), color = Color.White.copy(alpha = 0.75f), fontSize = 12.sp)
                    }
                    if (!story.isDorr) Box {
                        Icon(Icons.Rounded.MoreVert, null, tint = Color.White, modifier = Modifier.size(40.dp).clip(CircleShape).clickable { menu = true }.padding(8.dp))
                        DropdownMenu(menu, onDismissRequest = { menu = false }, containerColor = Color.White, shape = RoundedCornerShape(16.dp)) {
                            if (story.isMine) {
                                MenuItem(Icons.Rounded.Delete, stringResource(R.string.st_delete), tint = Ch.Danger) {
                                    menu = false
                                    scope.launch {
                                        runCatching { ApiClient.chat.deleteStory(chatAuth(), story.id) }
                                        host.showToast(deleted)
                                        stories.removeAt(index)
                                        if (stories.isEmpty()) onClose() else if (index > stories.lastIndex) index = stories.lastIndex
                                    }
                                }
                            } else if (owner != null) {
                                MenuItem(Icons.Rounded.NotificationsOff, stringResource(R.string.st_mute, owner.name.orEmpty())) {
                                    menu = false
                                    scope.launch {
                                        runCatching { ApiClient.chat.muteStories(chatAuth(), mapOf("participant_id" to owner.id, "muted" to true)).data }.getOrNull()?.let { host.stories = it }
                                        onNextGroup()
                                    }
                                }
                            }
                        }
                    }
                    Icon(Icons.Rounded.Close, null, tint = Color.White, modifier = Modifier.size(40.dp).clip(CircleShape).clickable(onClick = onClose).padding(8.dp))
                }
            }
        }

        // ------------------------------------------------------------ floating reactions
        bursts.forEach { b -> key(b.id) { BurstEmoji(b) { bursts.remove(b) } } }

        // ------------------------------------------------------------ bottom: caption, reply / viewers
        Column(Modifier.align(Alignment.BottomCenter).fillMaxWidth().imePadding().navigationBarsPadding()) {
            if (story.type != "text" && !story.body.isNullOrBlank()) {
                AnimatedVisibility(!uiHidden) {
                    Text(
                        story.body, color = Color.White, fontSize = 15.sp, textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth().background(Color.Black.copy(alpha = 0.35f)).padding(horizontal = 20.dp, vertical = 12.dp),
                    )
                }
            }
            AnimatedVisibility(!uiHidden || typing, enter = fadeIn(), exit = fadeOut()) {
                if (story.isMine) {
                    Column(
                        Modifier.fillMaxWidth().clickable { viewersFor = story }.padding(bottom = 18.dp, top = 8.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                    ) {
                        Icon(Icons.Rounded.KeyboardArrowUp, null, tint = Color.White)
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Icon(Icons.Rounded.Visibility, null, tint = Color.White, modifier = Modifier.size(18.dp))
                            Spacer(Modifier.width(6.dp))
                            Text((story.views ?: 0).toString(), color = Color.White, fontWeight = FontWeight.ExtraBold)
                        }
                    }
                } else if (story.isDorr) {
                    // Dorr's own story: its "Open" button, if it has a link.
                    story.linkUrl?.let { url -> DorrLinkButton(story.linkLabel, url) }
                } else if (story.allowReplies) {
                    ReplyBar(
                        onFocus = { typing = it },
                        onReact = { emoji ->
                            repeat(7) { bursts += Burst(emoji) }
                            scope.launch { runCatching { ApiClient.chat.reactStory(chatAuth(), story.id, mapOf("emoji" to emoji)) } }
                        },
                        onSend = { text ->
                            scope.launch {
                                runCatching { ApiClient.chat.replyStory(chatAuth(), story.id, mapOf("body" to text)) }
                                    .onSuccess { host.showToast(sent) }
                                    .onFailure { host.showToast(it.apiFailure().message ?: sent) }
                            }
                        },
                    )
                } else {
                    Text(stringResource(R.string.st_replies_off), color = Color.White.copy(alpha = 0.6f), fontSize = 12.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(18.dp))
                }
            }
        }
    }

    viewersFor?.let { s -> ViewersSheet(s) { viewersFor = null } }
}

// ------------------------------------------------------------------------------- content

@Composable
private fun StoryContent(story: StoryDto, playing: Boolean, onVideoReady: (Long) -> Unit) {
    val context = LocalContext.current
    when (story.type) {
        "text" -> {
            val style = story.style
            Box(Modifier.fillMaxSize().background(StoryLook.brush(style?.background)).padding(28.dp), contentAlignment = Alignment.Center) {
                val body = story.body.orEmpty()
                Text(
                    body, color = Color.White, fontFamily = StoryLook.font(style?.font), fontWeight = StoryLook.weight(style?.font),
                    fontSize = StoryLook.textSize(body.length).sp, lineHeight = (StoryLook.textSize(body.length) * 1.25f).sp, textAlign = TextAlign.Center,
                )
            }
        }
        "image" -> Box(Modifier.fillMaxSize()) {
            val url = ApiClient.mediaUrl(story.media?.url)
            // A blurred, zoomed copy fills the edges of photos that aren't phone-shaped.
            AsyncImage(url, null, imageLoader = chatImages(context), contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxSize().graphicsLayer { alpha = 0.55f }.then(if (android.os.Build.VERSION.SDK_INT >= 31) Modifier.blur(40.dp) else Modifier))
            // Ken Burns: a very slow zoom while it's on screen.
            val zoom by animateFloatAsState(if (playing) 1.08f else 1f, tween(if (playing) PHOTO_MS.toInt() else 300, easing = LinearEasing), label = "kenburns")
            AsyncImage(url, null, imageLoader = chatImages(context), contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize().graphicsLayer { scaleX = zoom; scaleY = zoom })
        }
        "video" -> {
            val url = ApiClient.mediaUrl(story.media?.url)
            AndroidView(
                factory = { ctx ->
                    VideoView(ctx).apply {
                        setVideoURI(Uri.parse(url))
                        setOnPreparedListener { mp ->
                            mp.isLooping = false
                            onVideoReady(mp.duration.toLong().coerceAtLeast(1000))
                            start()
                        }
                    }
                },
                update = { view -> if (playing && !view.isPlaying) view.start() else if (!playing && view.isPlaying) view.pause() },
                onRelease = { it.stopPlayback() },
                modifier = Modifier.fillMaxSize(),
            )
        }
    }
}

@Composable
private fun ReplyBar(onFocus: (Boolean) -> Unit, onReact: (String) -> Unit, onSend: (String) -> Unit) {
    var text by remember { mutableStateOf("") }
    var focused by remember { mutableStateOf(false) }
    val keyboard = LocalSoftwareKeyboardController.current
    Column(Modifier.fillMaxWidth().background(Brush.verticalGradient(listOf(Color.Transparent, Color.Black.copy(alpha = 0.6f)))).padding(horizontal = 12.dp, vertical = 12.dp)) {
        // Quick reactions: each one pops in, a tap sends a burst up the screen.
        Row(Modifier.fillMaxWidth().padding(bottom = 10.dp), horizontalArrangement = Arrangement.SpaceEvenly) {
            QuickStoryReactions.forEachIndexed { i, emoji ->
                val pop = remember { Animatable(0f) }
                LaunchedEffect(Unit) {
                    delay(120L + i * 50L)
                    pop.animateTo(1f, spring(dampingRatio = 0.4f, stiffness = 450f))
                }
                var bounce by remember { mutableStateOf(false) }
                val scale by animateFloatAsState(if (bounce) 1.45f else 1f, spring(dampingRatio = 0.3f, stiffness = 600f), finishedListener = { bounce = false }, label = "react")
                Text(
                    emoji, fontSize = 28.sp,
                    modifier = Modifier.graphicsLayer { val p = pop.value * scale; scaleX = p; scaleY = p }
                        .clip(CircleShape).clickable { bounce = true; onReact(emoji) }.padding(6.dp),
                )
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier.weight(1f).heightIn(min = 48.dp).clip(RoundedCornerShape(24.dp)).background(Color.White.copy(alpha = 0.16f)).padding(horizontal = 18.dp, vertical = 13.dp),
            ) {
                if (text.isEmpty()) Text(stringResource(R.string.st_reply_hint), color = Color.White.copy(alpha = 0.7f), fontSize = 15.sp)
                BasicTextField(
                    value = text, onValueChange = { text = it }, maxLines = 4,
                    textStyle = TextStyle(color = Color.White, fontSize = 15.sp, fontFamily = CairoFontFamily),
                    cursorBrush = SolidColor(Color.White),
                    modifier = Modifier.fillMaxWidth().onFocusChanged { focused = it.isFocused; onFocus(it.isFocused) },
                )
            }
            AnimatedVisibility(text.isNotBlank(), enter = androidx.compose.animation.scaleIn(spring(dampingRatio = 0.45f)) + fadeIn(), exit = androidx.compose.animation.scaleOut() + fadeOut()) {
                Box(
                    Modifier.padding(start = 8.dp).size(48.dp).clip(CircleShape).background(Ch.HeaderBrush).clickable {
                        onSend(text.trim())
                        text = ""
                        keyboard?.hide()
                    },
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.AutoMirrored.Rounded.Send, null, tint = Color.White, modifier = Modifier.size(22.dp)) }
            }
        }
    }
}

// ------------------------------------------------------------------------------- reaction bursts

private class Burst(val emoji: String) {
    val id = Random.nextLong()
    val drift = Random.nextFloat() * 2f - 1f
    val delay = Random.nextLong(0, 260)
    val size = 26 + Random.nextInt(18)
}

/** One emoji floating up from the reply bar, drifting sideways, spinning a little, fading out. */
@Composable
private fun BurstEmoji(b: Burst, onDone: () -> Unit) {
    val t = remember { Animatable(0f) }
    val density = LocalDensity.current
    LaunchedEffect(Unit) {
        delay(b.delay)
        t.animateTo(1f, tween(1500, easing = FastOutSlowInEasing))
        onDone()
    }
    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.BottomCenter) {
        Text(
            b.emoji, fontSize = b.size.sp,
            modifier = Modifier.padding(bottom = 120.dp).graphicsLayer {
                val p = t.value
                translationY = -p * with(density) { 520.dp.toPx() }
                translationX = b.drift * p * with(density) { 120.dp.toPx() } + kotlin.math.sin(p * 8f) * 18f
                rotationZ = b.drift * 30f * p
                alpha = if (p < 0.7f) 1f else (1f - p) / 0.3f
                val s = if (p < 0.15f) p / 0.15f else 1f
                scaleX = s
                scaleY = s
            },
        )
    }
}

// ------------------------------------------------------------------------------- viewers

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ViewersSheet(story: StoryDto, onDismiss: () -> Unit) {
    var viewers by remember { mutableStateOf<List<StoryViewerDto>?>(null) }
    LaunchedEffect(story.id) { viewers = runCatching { ApiClient.chat.storyViewers(chatAuth(), story.id).data }.getOrNull().orEmpty() }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Color.White, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            val list = viewers
            Text(stringResource(R.string.st_viewers, list?.size ?: story.views ?: 0), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(12.dp))
            when {
                list == null -> com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(120.dp))
                list.isEmpty() -> Text(stringResource(R.string.st_no_views), color = Ch.Soft, modifier = Modifier.padding(vertical = 24.dp))
                else -> LazyColumn(Modifier.heightIn(max = 420.dp)) {
                    itemsIndexed(list) { i, v ->
                        Row(Modifier.fillMaxWidth().chStagger(i).padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                            ChAvatar(v.viewer?.avatar, v.viewer?.name, v.viewer?.key, size = 44.dp)
                            Spacer(Modifier.width(12.dp))
                            Column(Modifier.weight(1f)) {
                                Text(v.viewer?.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.Bold)
                                Text(timeAgo(v.viewedAt), color = Ch.Mut, fontSize = 12.sp)
                            }
                            v.reaction?.let { Text(it, fontSize = 24.sp) }
                        }
                    }
                }
            }
        }
    }
}
