package com.dorr.app.ui.screens.chat

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
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
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.PhotoCamera
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
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
import kotlinx.coroutines.delay

// ------------------------------------------------------------------------------- text story look

/** Backgrounds for text stories — sent as a name, so both apps paint the same gradient. */
object StoryLook {
    val backgrounds: Map<String, List<Color>> = linkedMapOf(
        "dorr" to listOf(Color(0xFFF2202C), Color(0xFF7A0410)),
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
            val brand = Brush.sweepGradient(listOf(Color(0xFFF2202C), Color(0xFFFF8A4C), Color(0xFFDB2777), Color(0xFFF2202C)))
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
                    drawArc(Color(0xFFD1D5DB), start, sweep - gap, false, Offset(inset, inset), arcSize, style = Stroke(stroke * 0.8f, cap = StrokeCap.Round))
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
    val feed = host.stories ?: return
    val mine = feed.mine
    val people = feed.recent
    if (people.isEmpty() && mine == null && !host.storyUploading) {
        // Nothing to watch yet: just the add button, so the row doesn't eat space.
        LazyRow(contentPadding = PaddingValues(horizontal = 14.dp)) { item { MyStatus(null, host.storyUploading, 0, onAdd) } }
        return
    }

    LazyRow(
        contentPadding = PaddingValues(horizontal = 14.dp, vertical = 6.dp),
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item(key = "mine") { MyStatus(mine, host.storyUploading, 0, onAdd) }
        itemsIndexed(people, key = { _, g -> g.owner?.key ?: g.lastAt.orEmpty() }) { i, group ->
            PersonStory(group, i + 1) { host.openStories(people, i) }
        }
        if (feed.muted.isNotEmpty()) {
            itemsIndexed(feed.muted, key = { _, g -> "muted-" + (g.owner?.key ?: "") }) { i, group ->
                Box(Modifier.graphicsLayer { alpha = 0.55f }) {
                    PersonStory(group, people.size + i + 1) { host.openStories(feed.muted, i) }
                }
            }
        }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun MyStatus(mine: StoryGroupDto?, uploading: Boolean, index: Int, onAdd: () -> Unit) {
    val host = LocalChat.current
    val me = AuthSession.user
    val count = mine?.stories?.size ?: 0
    val plus = remember { Animatable(0f) }
    LaunchedEffect(Unit) {
        delay(250)
        plus.animateTo(1f, spring(dampingRatio = 0.35f, stiffness = 400f))
    }
    Column(
        Modifier.width(74.dp).chStagger(index)
            .combinedClickable(onClick = { if (count > 0) host.openStories(listOf(mine!!), 0) else onAdd() }, onLongClick = onAdd),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box {
            StoryRing(count, seenCount = count, size = 68.dp, uploading = uploading) {
                ChAvatar(null, me?.name, myKey(), size = 58.dp)
            }
            Box(
                Modifier.align(Alignment.BottomEnd).scale(plus.value).size(24.dp).clip(CircleShape).background(Ch.HeaderBrush).border(2.dp, Color.White, CircleShape)
                    .clickable(onClick = onAdd),
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.Add, null, tint = Color.White, modifier = Modifier.size(16.dp)) }
        }
        Spacer(Modifier.height(4.dp))
        Text(
            stringResource(if (uploading) R.string.st_uploading else R.string.st_my_status),
            color = Ch.Ink, fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center,
        )
    }
}

@Composable
private fun PersonStory(group: StoryGroupDto, index: Int, onClick: () -> Unit) {
    val owner = group.owner
    val seen = group.stories.count { it.seen }
    Column(Modifier.width(74.dp).chStagger(index).clickable(onClick = onClick), horizontalAlignment = Alignment.CenterHorizontally) {
        StoryRing(group.stories.size, seen, size = 68.dp) {
            ChAvatar(owner?.avatar, owner?.name, owner?.key, size = 58.dp)
        }
        Spacer(Modifier.height(4.dp))
        Text(
            owner?.name.orEmpty(), color = if (group.allSeen) Ch.Mut else Ch.Ink, fontSize = 11.5.sp,
            fontWeight = if (group.allSeen) FontWeight.Normal else FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center,
        )
    }
}

/** "Text" or "Photo or video" — the two ways to post. */
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
fun StoryAddSheet(onDismiss: () -> Unit, onText: () -> Unit, onMedia: () -> Unit) {
    androidx.compose.material3.ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = androidx.compose.foundation.shape.RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(start = 20.dp, end = 20.dp, bottom = 34.dp)) {
            Text(stringResource(R.string.st_add_status), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(16.dp))
            androidx.compose.foundation.layout.Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                AddTile(Icons.Rounded.Edit, stringResource(R.string.st_text), StoryLook.brush("sunset"), 0, Modifier.weight(1f)) { onDismiss(); onText() }
                AddTile(Icons.Rounded.PhotoCamera, stringResource(R.string.st_photo_video), StoryLook.brush("dorr"), 1, Modifier.weight(1f)) { onDismiss(); onMedia() }
            }
        }
    }
}

@Composable
private fun AddTile(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, brush: Brush, index: Int, modifier: Modifier, onClick: () -> Unit) {
    var pressed by remember { mutableStateOf(false) }
    Column(
        modifier.chStagger(index).height(120.dp).clip(androidx.compose.foundation.shape.RoundedCornerShape(24.dp)).background(brush).clickable(onClick = onClick).padding(16.dp),
        verticalArrangement = Arrangement.SpaceBetween,
    ) {
        Box(Modifier.size(44.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.22f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = Color.White, modifier = Modifier.size(24.dp))
        }
        Text(label, color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
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
