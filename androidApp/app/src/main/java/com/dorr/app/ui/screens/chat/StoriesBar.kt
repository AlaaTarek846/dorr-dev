package com.dorr.app.ui.screens.chat

import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
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
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.snapshotFlow
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.PhotoCamera
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.rotate
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AuthSession
import com.dorr.app.network.StoryGroupDto
import com.dorr.app.ui.theme.CairoFontFamily

// ------------------------------------------------------------------------------- text story look

/** Backgrounds for text stories — sent as a name, so both apps paint the same gradient. */
object StoryLook {
    val backgrounds: Map<String, List<Color>> = linkedMapOf(
        "dorr" to listOf(Color(0xFF1E3A7B), Color(0xFF7A0410)),
        "sunset" to listOf(Color(0xFFFF8A4C), Color(0xFFDB2777)),
        "ocean" to listOf(Color(0xFF22D3EE), Color(0xFF1D4ED8)),
        "forest" to listOf(Color(0xFF34D399), Color(0xFF065F46)),
        "grape" to listOf(Color(0xFFA78BFA), Color(0xFF5B21B6)),
        "gold" to listOf(Color(0xFFFBBF24), Color(0xFFB45309)),
        "night" to listOf(Color(0xFF334155), Color(0xFF020617)),
        "rose" to listOf(Color(0xFFFDA4AF), Color(0xFFBE123C)),
    )

    val fonts: List<String> = listOf("bold", "serif", "mono", "script", "light")

    fun brush(name: String?): Brush = Brush.linearGradient(backgrounds[name] ?: backgrounds.getValue("dorr"), start = Offset(0f, 0f), end = Offset(900f, 1800f))

    fun font(name: String?): FontFamily = when (name) {
        "serif" -> FontFamily.Serif
        "mono" -> FontFamily.Monospace
        "script" -> FontFamily.Cursive
        else -> CairoFontFamily
    }

    fun weight(name: String?): FontWeight = when (name) {
        "light" -> FontWeight.Normal
        "serif", "script" -> FontWeight.SemiBold
        else -> FontWeight.ExtraBold
    }

    /** Big text for a short status, smaller as it grows. */
    fun textSize(length: Int): Float = when {
        length < 20 -> 38f
        length < 60 -> 30f
        length < 140 -> 24f
        else -> 19f
    }
}

// ------------------------------------------------------------------------------- ring

/**
 * The story ring: one arc per story. Unseen arcs wear the brand gradient (which slowly turns),
 * seen ones are grey. `uploading` spins a single arc while my story is being posted.
 */
@Composable
fun StoryRing(count: Int, seenCount: Int, size: Dp, uploading: Boolean = false, modifier: Modifier = Modifier, content: @Composable () -> Unit) {
    val spin = rememberInfiniteTransition(label = "ring")
    val angle by spin.animateFloat(0f, 360f, infiniteRepeatable(tween(if (uploading) 900 else 9000, easing = LinearEasing)), label = "angle")
    Box(modifier.size(size), contentAlignment = Alignment.Center) {
        Canvas(Modifier.fillMaxSize()) {
            val stroke = 2.6.dp.toPx()
            val inset = stroke / 2
            val arcSize = Size(this.size.width - stroke, this.size.height - stroke)
            val brand = Brush.sweepGradient(listOf(Ch.Red, Ch.RedDeep, Ch.Red.shade(0.45f), Ch.Red))
            if (uploading) {
                rotate(angle) { drawArc(brand, 0f, 100f, false, Offset(inset, inset), arcSize, style = Stroke(stroke, cap = StrokeCap.Round)) }
                return@Canvas
            }
            if (count <= 0) return@Canvas
            val gap = if (count > 1) 7f else 0f
            val sweep = 360f / count
            for (i in 0 until count) {
                val seen = i < seenCount
                val start = -90f + i * sweep + gap / 2
                if (seen) {
                    drawArc(Ch.Soft, start, sweep - gap, false, Offset(inset, inset), arcSize, style = Stroke(stroke * 0.8f, cap = StrokeCap.Round))
                } else {
                    rotate(angle) {
                        drawArc(brand, start - angle, sweep - gap, false, Offset(inset, inset), arcSize, style = Stroke(stroke, cap = StrokeCap.Round))
                    }
                }
            }
        }
        Box(Modifier.padding(5.dp)) { content() }
    }
}

// ------------------------------------------------------------------------------- bar

/**
 * The stories row at the top of the chat list: "My status" first (tap to add or to watch mine),
 * then everyone else — unseen first. Each avatar slides in after the one before.
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun StoriesBar(onAdd: () -> Unit) {
    val host = LocalChat.current
    val feed = host.stories
    val mine = feed?.mine
    val people = feed?.recent.orEmpty()
    val muted = feed?.muted.orEmpty()

    val listState = rememberLazyListState()
    LaunchedEffect(listState, people.size, muted.size, feed?.hasMore) {
        snapshotFlow { listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index to listState.layoutInfo.totalItemsCount }
            .collect { (last, total) ->
                if (last != null && total > 0 && last >= total - 2) host.loadMoreStories()
            }
    }
    LazyRow(
        state = listState,
        contentPadding = PaddingValues(start = 8.dp, end = 8.dp, top = 12.dp, bottom = 4.dp),
        horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        item(key = "mine") { MyStatus(mine, host.storyUploading, onAdd) }
        itemsIndexed(people, key = { _, g -> g.owner?.key ?: g.lastAt.orEmpty() }) { i, group ->
            PersonStory(group, i + 1) { host.openStories(people, i) }
        }
        if (muted.isNotEmpty()) {
            itemsIndexed(muted, key = { _, g -> "muted-" + (g.owner?.key ?: "") }) { i, group ->
                Box(Modifier.graphicsLayer { alpha = 0.55f }) {
                    PersonStory(group, people.size + i + 1) { host.openStories(muted, i) }
                }
            }
        }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun MyStatus(mine: StoryGroupDto?, uploading: Boolean, onAdd: () -> Unit) {
    val host = LocalChat.current
    val me = AuthSession.user
    val count = mine?.stories?.size ?: 0
    Column(
        Modifier.width(64.dp)
            .combinedClickable(onClick = { if (count > 0) host.openStories(listOf(mine!!), 0) else onAdd() }, onLongClick = onAdd),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box {
            // My stories show like anyone's: a coloured arc per story, and my latest one inside
            // the circle (not my initials), so posting two statuses is visible at a glance.
            StoryRing(count, seenCount = 0, size = 58.dp, uploading = uploading) {
                val latest = mine?.stories?.lastOrNull()
                if (latest != null) StoryThumb(latest, size = 50.dp)
                else ChAvatar(null, me?.name, myKey(), size = 50.dp)
            }
            if (count > 1) Text(
                "$count", color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.align(Alignment.TopEnd).clip(CircleShape).background(Ch.Red).padding(horizontal = 6.dp, vertical = 1.dp),
            )
            Box(
                Modifier.align(Alignment.BottomEnd).size(20.dp).clip(CircleShape).background(Ch.HeaderBrush).border(2.dp, Color.White, CircleShape)
                    .clickable(onClick = onAdd),
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.Add, null, tint = Color.White, modifier = Modifier.size(14.dp)) }
        }
        Spacer(Modifier.height(4.dp))
        Text(
            when {
                uploading -> stringResource(R.string.st_uploading)
                count > 0 -> timeAgo(mine?.stories?.lastOrNull()?.createdAt).ifEmpty { stringResource(R.string.st_my_status) }
                else -> stringResource(R.string.st_my_status)
            },
            color = Ch.Ink, fontSize = 10.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center,
        )
    }
}

@Composable
private fun PersonStory(group: StoryGroupDto, index: Int, onClick: () -> Unit) {
    val owner = group.owner
    val seen = group.stories.count { it.seen }
    Column(Modifier.width(64.dp).chStagger(index).clickable(onClick = onClick), horizontalAlignment = Alignment.CenterHorizontally) {
        StoryRing(group.stories.size, seen, size = 58.dp) {
            ChAvatar(owner?.avatar, owner?.name, owner?.key, size = 50.dp)
        }
        Spacer(Modifier.height(4.dp))
        Text(
            owner?.name.orEmpty(), color = if (group.allSeen) Ch.Mut else Ch.Ink, fontSize = 10.5.sp,
            fontWeight = if (group.allSeen) FontWeight.Normal else FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center,
        )
    }
}

/**
 * A story in miniature for a ring: the photo (or the video's poster), or a text status as its
 * gradient with the first words.
 */
@Composable
internal fun StoryThumb(story: com.dorr.app.network.StoryDto, size: Dp) {
    val context = androidx.compose.ui.platform.LocalContext.current
    Box(Modifier.size(size).clip(CircleShape).background(StoryLook.brush(story.style?.background)), contentAlignment = Alignment.Center) {
        val media = story.media
        if (media != null && media.mimeType?.startsWith("video/") == true) {
            Box(Modifier.fillMaxSize().background(Ch.HeaderBrush), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.PlayArrow, null, tint = Color.White, modifier = Modifier.size(size * 0.45f))
            }
        } else if (media != null) {
            coil.compose.AsyncImage(
                model = coil.request.ImageRequest.Builder(context).data(com.dorr.app.network.ApiClient.mediaUrl(media.url)).crossfade(true).build(),
                contentDescription = null, imageLoader = chatImages(context),
                contentScale = androidx.compose.ui.layout.ContentScale.Crop, modifier = Modifier.fillMaxSize(),
            )
        } else {
            Text(
                story.body.orEmpty().take(18), color = Color.White, fontSize = 8.sp, fontWeight = FontWeight.Bold, maxLines = 3,
                textAlign = TextAlign.Center, lineHeight = 9.sp, modifier = Modifier.padding(6.dp),
            )
        }
    }
}

/** "Text", "Photo or video" or "Voice" — the ways to post. Same sheet language as ChoiceSheet. */
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
fun StoryAddSheet(onDismiss: () -> Unit, onText: () -> Unit, onMedia: () -> Unit, onVoice: () -> Unit) {
    val options = listOf(
        Triple(Icons.Rounded.Edit, stringResource(R.string.st_text), onText),
        Triple(Icons.Rounded.PhotoCamera, stringResource(R.string.st_photo_video), onMedia),
        Triple(Icons.Rounded.Mic, stringResource(R.string.bk_voice_status), onVoice),
    )
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 30.dp)) {
            Text(stringResource(R.string.st_add_status), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(14.dp))
            options.forEach { (icon, label, action) ->
                Row(
                    Modifier.fillMaxWidth().padding(vertical = 4.dp).clip(RoundedCornerShape(16.dp))
                        .background(Ch.SurfaceMuted)
                        .clickable { action(); onDismiss() }
                        .padding(horizontal = 16.dp, vertical = 15.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(12.dp))
                    Text(label, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
                }
            }
            Box(Modifier.fillMaxWidth().padding(top = 6.dp).clip(RoundedCornerShape(16.dp)).clickable(onClick = onDismiss).padding(15.dp), contentAlignment = Alignment.Center) {
                Text(stringResource(R.string.ch_cancel), color = Ch.Mut, fontWeight = FontWeight.Bold)
            }
        }
    }
}

/** "5 min ago", "3 h ago". */
@Composable
internal fun timeAgo(iso: String?): String {
    val instant = parseInstant(iso) ?: return ""
    val minutes = java.time.Duration.between(instant, java.time.Instant.now()).toMinutes()
    return when {
        minutes < 1 -> stringResource(R.string.st_just_now)
        minutes < 60 -> stringResource(R.string.st_minutes_ago, minutes.toInt())
        else -> stringResource(R.string.st_hours_ago, (minutes / 60).toInt())
    }
}
