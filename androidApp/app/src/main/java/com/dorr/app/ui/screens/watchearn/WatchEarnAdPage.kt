package com.dorr.app.ui.screens.watchearn

import android.content.Intent
import android.media.MediaPlayer
import android.net.Uri
import android.widget.VideoView
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.OpenInNew
import androidx.compose.material.icons.automirrored.rounded.VolumeUp
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Timer
import androidx.compose.material.icons.rounded.VolumeOff
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.WeAdPageDto
import com.dorr.app.network.WeFeedAdDto
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSkeleton
import kotlinx.coroutines.delay

/** Where the comments' heading sits in the page's list (after the media, the text and the reward note). */
private const val COMMENTS_HEADING = 3

/**
 * One ad for a viewer who already saw it (opened from a push, e.g. the answer to a report on their comment):
 * `GET ads/{id}` — the image or video (no timer, no view session), the title, the text and the link, then the
 * reactions and the comments, live through the same channel as the comments sheet ([WeComments]). Reacting and
 * commenting work; they earn the interaction reward only while `can_interact` (the server decides).
 */
@Composable
fun WatchEarnAdPage(adId: String) {
    val host = LocalWe.current
    val generic = stringResource(R.string.we_error_generic)
    val rewardCaption = stringResource(R.string.we_interaction_reward)
    var page by remember { mutableStateOf<WeAdPageDto?>(null) }
    var failed by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    var interacted by remember { mutableStateOf(false) }
    var nowMs by remember { mutableLongStateOf(System.currentTimeMillis()) }
    var reportOpen by remember { mutableStateOf(false) }

    LaunchedEffect(adId, reload) {
        runCatching { ApiClient.watchEarn.feedAd(weAuth(), adId).data }
            .onSuccess { if (it != null) { page = it; failed = false } else if (page == null) failed = true }
            .onFailure {
                if (page == null) failed = true
                host.showToast(it.weMessage(generic), error = true)
            }
    }

    val deadlineMs = remember(page) { page?.takeIf { it.canInteract }?.let { weInstant(it.interactionDeadlineAt)?.toEpochMilli() } }
    val windowOpen = deadlineMs != null && nowMs < deadlineMs && !interacted
    // The interaction window's countdown runs on the device clock — no server call.
    LaunchedEffect(deadlineMs, interacted) {
        val until = deadlineMs ?: return@LaunchedEffect
        while (!interacted && System.currentTimeMillis() < until) {
            nowMs = System.currentTimeMillis()
            delay(1_000L)
        }
        nowMs = System.currentTimeMillis()
    }

    /** The first comment or reaction inside the window: the server pays it; the page says so once. */
    fun claimInteraction() {
        val p = page ?: return
        val until = weInstant(p.interactionDeadlineAt)?.toEpochMilli() ?: return
        if (!p.canInteract || interacted || System.currentTimeMillis() >= until) return
        interacted = true
        if (p.ad.earn.interactionMinor > 0) host.showToast("+ " + weMoney(p.ad.earn.interactionMinor, p.ad.currency) + " · " + rewardCaption)
    }

    WaPage(title = stringResource(R.string.we_ad_page_title), onBack = { host.pop() }, scroll = false) {
        val p = page
        if (p == null) {
            if (failed) {
                WaEmpty(
                    Icons.Rounded.Warning, Tone.Red, stringResource(R.string.we_load_failed), stringResource(R.string.we_load_failed_hint),
                    action = { WaButton(stringResource(R.string.we_retry), { reload++ }, modifier = Modifier.padding(horizontal = 40.dp)) },
                )
            } else {
                Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    WaSkeleton(Modifier.fillMaxWidth().height(320.dp), RoundedCornerShape(22.dp))
                    WaSkeleton(Modifier.fillMaxWidth().height(22.dp))
                    WaSkeleton(Modifier.fillMaxWidth().height(60.dp))
                }
            }
            return@WaPage
        }
        AdPageBody(
            page = p,
            windowOpen = windowOpen,
            leftMs = (deadlineMs ?: 0L) - nowMs,
            onInteracted = { claimInteraction() },
            onReportAd = { reportOpen = true },
        )
    }

    if (reportOpen) {
        WeReportAdSheet(
            adId = adId,
            onDismiss = { reportOpen = false },
            onReported = { message ->
                reportOpen = false
                host.showToast(message)
            },
        )
    }
}

/** The loaded page: one list (the ad, then its comments) and the composer under it. */
@Composable
private fun AdPageBody(page: WeAdPageDto, windowOpen: Boolean, leftMs: Long, onInteracted: () -> Unit, onReportAd: () -> Unit) {
    val ad = page.ad
    val listState = rememberLazyListState()
    val comments = rememberWeComments(ad.id, listState, onInteracted)
    Column(Modifier.fillMaxSize()) {
        LazyColumn(Modifier.weight(1f).fillMaxWidth(), state = listState, contentPadding = PaddingValues(bottom = 12.dp)) {
            item(key = "we_ad_media") { AdMedia(ad) }
            item(key = "we_ad_text") { AdText(ad) }
            item(key = "we_ad_reward") {
                when {
                    windowOpen && ad.earn.interactionMinor > 0 -> RewardWindow(ad, leftMs)
                    !page.canInteract -> WaNote(stringResource(R.string.we_ad_page_no_reward), Modifier.padding(top = 12.dp), icon = Icons.Rounded.Info)
                    else -> Unit
                }
            }
            item(key = "we_ad_reactions") {
                Column(Modifier.fillMaxWidth().padding(top = 18.dp, bottom = 6.dp)) {
                    Text(stringResource(R.string.we_comments), color = Wa.Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                    Spacer(Modifier.height(10.dp))
                    WeReactionCounts(comments)
                }
            }
            weCommentItems(comments, onReportAd)
        }
        WeCommentComposer(comments, Modifier.fillMaxWidth().padding(top = 8.dp, bottom = 12.dp)) {
            listState.animateScrollToItem(COMMENTS_HEADING)
        }
    }
    WeDeleteCommentDialog(comments)
}

/** The image (whole, on black) or the video, at a phone-friendly height. */
@Composable
private fun AdMedia(ad: WeFeedAdDto) {
    val url = ApiClient.mediaUrl(ad.media.url)
    Box(
        Modifier.fillMaxWidth().height(380.dp).clip(RoundedCornerShape(22.dp)).background(Color.Black),
        contentAlignment = Alignment.Center,
    ) {
        when {
            url == null -> Unit
            ad.media.type == "video" -> AdVideo(url)
            else -> AsyncImage(url, ad.title, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize())
        }
    }
}

/**
 * The ad's video on its page: no timer and no session. It waits for a tap, plays once, and starts muted (🔈 turns the
 * sound on). A tap again pauses it.
 */
@Composable
private fun AdVideo(url: String) {
    var player by remember { mutableStateOf<MediaPlayer?>(null) }
    var playing by remember { mutableStateOf(false) }
    var muted by remember { mutableStateOf(true) }
    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        AndroidView(
            factory = { ctx ->
                VideoView(ctx).apply {
                    setVideoURI(Uri.parse(url))
                    setOnPreparedListener { mp ->
                        mp.isLooping = false
                        runCatching { mp.setVolume(0f, 0f) }
                        player = mp
                        // The first frame instead of a black box.
                        seekTo(1)
                    }
                    setOnCompletionListener { playing = false }
                    setOnErrorListener { _, _, _ ->
                        playing = false
                        true
                    }
                }
            },
            update = { view ->
                if (playing && !view.isPlaying && player != null) view.start() else if (!playing && view.isPlaying) view.pause()
            },
            onRelease = { it.stopPlayback() },
            modifier = Modifier.fillMaxWidth(),
        )
        // Taps land here (the video view itself would swallow them).
        Box(Modifier.matchParentSize().clickable(enabled = player != null) { playing = !playing })
        AnimatedVisibility(!playing, enter = scaleIn() + fadeIn(), exit = scaleOut() + fadeOut()) {
            Box(Modifier.size(64.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.45f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.PlayArrow, stringResource(R.string.we_play), tint = Color.White, modifier = Modifier.size(38.dp))
            }
        }
        Icon(
            if (muted) Icons.Rounded.VolumeOff else Icons.AutoMirrored.Rounded.VolumeUp,
            stringResource(if (muted) R.string.we_unmute else R.string.we_mute),
            tint = Color.White,
            modifier = Modifier.align(Alignment.TopEnd).padding(10.dp).size(38.dp).clip(CircleShape)
                .background(Color.Black.copy(alpha = 0.4f)).clickable { muted = !muted }.padding(8.dp),
        )
    }
    LaunchedEffect(muted, player) {
        val mp = player ?: return@LaunchedEffect
        val volume = if (muted) 0f else 1f
        runCatching { mp.setVolume(volume, volume) }
    }
}

/** The advertiser, the title, the text and the link. */
@Composable
private fun AdText(ad: WeFeedAdDto) {
    val context = LocalContext.current
    Column(Modifier.fillMaxWidth().padding(top = 14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(ad.advertiser?.name.orEmpty(), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.we_sponsored), color = Wa.Soft, fontSize = 11.5.sp)
        }
        Text(ad.title, color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 4.dp))
        if (!ad.body.isNullOrBlank()) {
            Text(ad.body, color = Wa.Ink, fontSize = 14.sp, lineHeight = 22.sp, modifier = Modifier.padding(top = 6.dp))
        }
        val link = ad.linkUrl
        if (!link.isNullOrBlank()) {
            Spacer(Modifier.height(12.dp))
            WaButton(
                stringResource(R.string.we_open_link),
                { runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(link))) } },
                style = WaButtonStyle.Ghost, icon = Icons.AutoMirrored.Rounded.OpenInNew, iconAfter = true, compact = true,
                modifier = Modifier.width(170.dp),
            )
        }
    }
}

/** While the interaction reward is still open: what it pays and how long is left (device clock). */
@Composable
private fun RewardWindow(ad: WeFeedAdDto, leftMs: Long) {
    Row(
        Modifier.padding(top = 12.dp).clip(RoundedCornerShape(99.dp)).background(We.Interaction.copy(alpha = 0.14f))
            .padding(horizontal = 12.dp, vertical = 7.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.Timer, null, tint = We.Interaction, modifier = Modifier.size(15.dp))
        Spacer(Modifier.width(6.dp))
        Text(
            stringResource(R.string.we_interaction_window, weMoney(ad.earn.interactionMinor, ad.currency)),
            color = We.Interaction, fontSize = 12.sp, fontWeight = FontWeight.Bold,
        )
        Spacer(Modifier.width(6.dp))
        WeLtr { Text(weCountdown(leftMs), color = We.Interaction, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold) }
    }
}
