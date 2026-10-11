package com.dorr.app.ui.screens.chat

import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.spring
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.OpenInNew
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.PublicStoryFeedDto
import com.dorr.app.network.StoryGroupDto
import com.dorr.app.ui.theme.LocalThemeState
import kotlinx.coroutines.delay

/**
 * Public stories on the home page (docs/remaining_chat.md ج.1), drawn with the chat's own stories
 * pieces: a small ChatHost of its own (in public mode) runs the same story viewer and composer, so
 * a story looks and behaves the same everywhere — posting from here makes it public.
 */
@Stable
class HomeStoriesState(val host: ChatHost) {
    var feed by mutableStateOf<PublicStoryFeedDto?>(null)
    var adding by mutableStateOf(false)
    var loadingMore by mutableStateOf(false)
    private var page = 1

    /** The first ten people (and mine / Dorr's). Pages already scrolled in stay, after the fresh first ten. */
    suspend fun refresh() {
        val fresh = runCatching { ApiClient.chat.publicStories(chatAuth(), page = 1, perPage = PAGE).data }.getOrNull() ?: return
        val keys = fresh.people.map { it.owner?.key }.toSet()
        val kept = feed?.people.orEmpty().drop(PAGE).filter { it.owner?.key !in keys }
        feed = fresh.copy(people = fresh.people + kept, hasMore = if (kept.isEmpty()) fresh.hasMore else feed?.hasMore ?: fresh.hasMore)
        page = (feed?.people.orEmpty().size + PAGE - 1) / PAGE
    }

    /** The next ten, when the row is scrolled to its end. */
    suspend fun loadMore() {
        val current = feed ?: return
        if (loadingMore || !current.hasMore) return
        loadingMore = true
        try {
            val next = runCatching { ApiClient.chat.publicStories(chatAuth(), page = page + 1, perPage = PAGE).data }.getOrNull() ?: return
            val known = current.people.map { it.owner?.key }.toSet()
            feed = current.copy(people = current.people + next.people.filter { it.owner?.key !in known }, hasMore = next.hasMore)
            page += 1
        } finally {
            loadingMore = false
        }
    }

    /** "View all": plays everything from the first circle on. */
    fun openAll() {
        val f = feed ?: return
        val all = listOfNotNull(f.mine, f.dorr) + f.people
        if (all.isNotEmpty()) host.openStories(all, 0)
    }

    private companion object {
        const val PAGE = 10
    }

    /** Everything the circles open, in their order — so the viewer pages from one to the next. */
    fun open(group: StoryGroupDto) {
        val f = feed ?: return
        val all = listOfNotNull(f.mine, f.dorr) + f.people
        host.openStories(all, all.indexOf(group).coerceAtLeast(0))
    }
}

@Composable
fun rememberHomeStories(): HomeStoriesState {
    val scope = rememberCoroutineScope()
    return remember {
        val host = ChatHost(scope, onExit = {}, openWalletQr = {})
        HomeStoriesState(host).also { state ->
            host.publicMode = true
            host.onRefreshStories = { state.refresh() }
        }
    }
}

/**
 * The full-screen part, over the main screen: the story viewer, the composer while writing one,
 * the "add" sheet and the chat's toast. Uses the chat's colours, set from the app's theme.
 */
@Composable
fun HomeStoriesLayer(state: HomeStoriesState) {
    val host = state.host
    val dark = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    val palette = chatPaletteFromSettings(dark)
    val accent = if (dark) com.dorr.app.ui.screens.AccountDark.accent else com.dorr.app.ui.theme.appearanceColor("primary", com.dorr.app.ui.theme.AppColors.waRed, night = false)
    // Only while it's in use (the chat sets its own colours when it opens).
    val busy = host.storyViewer != null || host.current !is ChRoute.List || state.adding
    if (busy) SideEffect {
        Ch.dark = dark
        Ch.accent = accent
        Ch.palette = palette
    }
    val media = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        uri?.let { host.push(ChRoute.StoryComposer(it.toString())) }
    }

    CompositionLocalProvider(LocalChat provides host) {
        val route = host.current
        // Over the whole screen (outside the tab scaffold): black behind the system bars, content between them.
        if (route is ChRoute.StoryComposer || host.storyViewer != null) Box(Modifier.fillMaxSize().background(Color.Black))
        Box(Modifier.fillMaxSize().systemBarsPadding()) {
            if (route is ChRoute.StoryComposer) Box(Modifier.fillMaxSize().background(Color.Black)) {
                BackHandler { host.pop() }
                StoryComposerPage(route.media)
            }
            StoryViewerOverlay()
        }
        ChatToast(host)
        if (state.adding) StoryAddSheet(
            onDismiss = { state.adding = false },
            onText = { host.push(ChRoute.StoryComposer()) },
            onMedia = { media.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo)) },
        )
    }
}

// =============================================================================== the circles

/** "Me": my public story (tap to watch, long-press to add another), or a "+" to post one. */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun HomeMyCircle(state: HomeStoriesState) {
    val feed = state.feed
    val mine = feed?.mine
    val count = mine?.stories?.size ?: 0
    val quota = feed?.quota
    val full = quota != null && quota.used >= quota.free
    val context = LocalContext.current
    val add = {
        if (full) state.host.showToast(context.getString(R.string.st_public_full, quota?.free ?: 1))
        else state.adding = true
    }
    val plus = remember { Animatable(0f) }
    LaunchedEffect(Unit) { delay(250); plus.animateTo(1f, spring(dampingRatio = 0.35f, stiffness = 400f)) }
    Column(
        Modifier.width(58.dp).combinedClickable(onClick = { if (mine != null) state.open(mine) else add() }, onLongClick = add),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box {
            StoryRing(count, seenCount = 0, size = 56.dp, uploading = state.host.storyUploading) {
                val latest = mine?.stories?.lastOrNull()
                if (latest != null) StoryThumb(latest, size = 48.dp)
                else ChAvatar(AuthSession.user?.avatar, AuthSession.user?.name, myKey(), size = 48.dp)
            }
            if (!full) Box(
                Modifier.align(Alignment.BottomEnd).scale(plus.value).size(20.dp).clip(CircleShape).background(Ch.HeaderBrush).border(2.dp, Color.White, CircleShape).clickable(onClick = add),
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.Add, null, tint = Color.White, modifier = Modifier.size(13.dp)) }
        }
        Spacer(Modifier.height(4.dp))
        CircleLabel(stringResource(R.string.st_my_public), bold = true)
    }
}

/** Dorr's own stories — a fixed circle with the logo. */
@Composable
fun HomeDorrCircle(state: HomeStoriesState, group: StoryGroupDto) {
    Column(Modifier.width(58.dp).clickable { state.open(group) }, horizontalAlignment = Alignment.CenterHorizontally) {
        StoryRing(group.stories.size, group.stories.count { it.seen }, size = 56.dp) { DorrAvatar(48.dp) }
        Spacer(Modifier.height(4.dp))
        CircleLabel(stringResource(R.string.st_dorr), bold = !group.allSeen)
    }
}

/** Someone's public stories — their name and photo, never their number. */
@Composable
fun HomePersonCircle(state: HomeStoriesState, group: StoryGroupDto) {
    val owner = group.owner
    Column(Modifier.width(58.dp).clickable { state.open(group) }, horizontalAlignment = Alignment.CenterHorizontally) {
        StoryRing(group.stories.size, group.stories.count { it.seen }, size = 56.dp) {
            ChAvatar(owner?.avatar, owner?.name, owner?.key, size = 48.dp)
        }
        Spacer(Modifier.height(4.dp))
        CircleLabel(owner?.name.orEmpty(), bold = !group.allSeen)
    }
}

@Composable
private fun CircleLabel(text: String, bold: Boolean) {
    Text(
        text, color = com.dorr.app.ui.screens.wallet.Wa.Ink.copy(alpha = if (bold) 1f else 0.65f), fontSize = 11.sp,
        fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal, maxLines = 1, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center,
    )
}

/** Dorr's logo in a circle — the avatar of Dorr's own stories. */
@Composable
internal fun DorrAvatar(size: Dp) {
    Box(Modifier.size(size).clip(CircleShape).background(Color.White), contentAlignment = Alignment.Center) {
        Image(painterResource(R.drawable.dorr_logo_light), null, contentScale = ContentScale.Fit, modifier = Modifier.size(size * 0.72f))
    }
}

/** The "Open" button on one of Dorr's stories. */
@Composable
internal fun DorrLinkButton(label: String?, url: String) {
    val context = LocalContext.current
    Box(Modifier.fillMaxWidth().padding(bottom = 22.dp, top = 8.dp), contentAlignment = Alignment.Center) {
        Row(
            Modifier.clip(RoundedCornerShape(24.dp)).background(Color.White)
                .clickable { runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) } }
                .padding(horizontal = 22.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(label?.takeIf { it.isNotBlank() } ?: stringResource(R.string.st_open_link), color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
            Spacer(Modifier.width(8.dp))
            Icon(Icons.Rounded.OpenInNew, null, tint = Ch.Red, modifier = Modifier.size(18.dp))
        }
    }
}
