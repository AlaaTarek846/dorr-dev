@file:OptIn(ExperimentalFoundationApi::class, ExperimentalMaterial3Api::class)

package com.dorr.freelance.ui.screens

import android.content.Context
import android.widget.Toast
import androidx.compose.animation.animateContentSize
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.spring
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.gestures.calculatePan
import androidx.compose.foundation.gestures.calculateZoom
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.BookmarkBorder
import androidx.compose.material.icons.outlined.ChatBubbleOutline
import androidx.compose.material.icons.outlined.ThumbUp
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Bookmark
import androidx.compose.material.icons.rounded.Celebration
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.Lightbulb
import androidx.compose.material.icons.rounded.MoreHoriz
import androidx.compose.material.icons.rounded.QuestionAnswer
import androidx.compose.material.icons.rounded.ThumbUp
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material.icons.rounded.VolunteerActivism
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material.icons.rounded.WorkspacePremium
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
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
import androidx.compose.ui.input.pointer.positionChanged
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.IntOffset
import androidx.compose.ui.unit.IntSize
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.DialogProperties
import androidx.compose.ui.window.Popup
import androidx.compose.ui.window.PopupProperties
import coil.compose.AsyncImage
import coil.request.ImageRequest
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AppLocale
import com.dorr.freelance.net.CommunityAuthorDto
import com.dorr.freelance.net.CommunityBadgeDto
import com.dorr.freelance.net.CommunityChange
import com.dorr.freelance.net.CommunityHub
import com.dorr.freelance.net.CommunityImageDto
import com.dorr.freelance.net.CommunityPostDto
import com.dorr.freelance.net.CommunityReactionRequest
import com.dorr.freelance.net.CommunityReportRequest
import com.dorr.freelance.net.CommunityWorkDto
import com.dorr.freelance.net.apiDone
import com.dorr.freelance.net.errorText
import com.dorr.freelance.net.optimisticReaction
import com.dorr.freelance.ui.components.AvatarImage
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.SkeletonBlock
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.ConfirmDialog
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsCard
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface
import com.dorr.freelance.ui.locale.LocaleAwareBottomSheet
import com.dorr.freelance.ui.locale.LocaleAwareDialog
import java.time.Duration
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import kotlinx.coroutines.MainScope
import kotlinx.coroutines.launch

/*
 * FL-L12: the pieces the community screens share — the post card (LinkedIn-like), reactions and their picker, the plan badge, the image
 * grid and its full-screen viewer, the report sheet, relative times, and [CommunityActions] (optimistic react / save / follow / delete that
 * every screen showing the same post follows through [CommunityHub.changes]).
 */

/** Where the community screens lead. */
class CommunityNav(
    val openPost: (String) -> Unit,
    val openMember: (String) -> Unit,
    val editPost: (String) -> Unit,
    val compose: (String) -> Unit,
    val openPeople: (String, String) -> Unit,
    val openVerification: () -> Unit,
    val openPortfolio: () -> Unit,
)

// ===================================================================================================== reactions

internal class ReactionStyle(val key: String, val icon: ImageVector, val color: Color, val label: Int)

internal val REACTIONS = listOf(
    ReactionStyle("like", Icons.Rounded.ThumbUp, Color(0xFF2563EB), R.string.cm_react_like),
    ReactionStyle("celebrate", Icons.Rounded.Celebration, Color(0xFF16A34A), R.string.cm_react_celebrate),
    ReactionStyle("insightful", Icons.Rounded.Lightbulb, Color(0xFFD97706), R.string.cm_react_insightful),
    ReactionStyle("support", Icons.Rounded.VolunteerActivism, Color(0xFFDB2777), R.string.cm_react_support),
)

internal fun reactionStyle(key: String?): ReactionStyle? = REACTIONS.firstOrNull { it.key == key }

/** The reaction's name: the server's (in my language) when the home has been read, else the app's own. */
@Composable
internal fun reactionLabel(style: ReactionStyle): String {
    val home by CommunityHub.home.collectAsState()
    return home?.reactions?.firstOrNull { it.key == style.key }?.label?.takeIf { it.isNotBlank() } ?: stringResource(style.label)
}

/** Up to three reaction icons overlapping, as LinkedIn shows them over a post. */
@Composable
internal fun ReactionStack(top: List<String>, size: Dp = 18.dp) {
    val ring = settingsCard()
    Row {
        top.mapNotNull(::reactionStyle).take(3).forEachIndexed { i, s ->
            Box(
                Modifier.offset(x = (-5 * i).dp).size(size).clip(CircleShape).background(ring).padding(1.5.dp).clip(CircleShape).background(s.color),
                contentAlignment = Alignment.Center,
            ) { Icon(s.icon, contentDescription = null, tint = Color.White, modifier = Modifier.size(size * 0.58f)) }
        }
    }
}

/**
 * The react button: a tap likes (or takes my reaction back), a long press opens the picker of the four reactions. Shows my reaction in
 * its colour.
 */
@Composable
internal fun ReactButton(mine: String?, modifier: Modifier = Modifier, compact: Boolean = false, onReact: (String?) -> Unit) {
    var picking by remember { mutableStateOf(false) }
    val style = reactionStyle(mine)
    val color = style?.color ?: settingsMut()
    Box(modifier) {
        Row(
            Modifier.clip(RoundedCornerShape(10.dp))
                .combinedClickable(onClick = { onReact(if (mine == null) "like" else null) }, onLongClick = { picking = true })
                .padding(horizontal = if (compact) 4.dp else 8.dp, vertical = if (compact) 4.dp else 9.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center,
        ) {
            Icon(style?.icon ?: Icons.Outlined.ThumbUp, contentDescription = null, tint = color, modifier = Modifier.size(if (compact) 15.dp else 19.dp))
            Spacer(Modifier.width(5.dp))
            Text(
                if (style != null) reactionLabel(style) else stringResource(R.string.cm_react_like),
                color = color, fontSize = if (compact) 12.sp else 13.sp, fontWeight = FontWeight.Bold, maxLines = 1,
            )
        }
        if (picking) ReactionPicker(mine, onPick = { picking = false; onReact(if (it == mine) null else it) }, onDismiss = { picking = false })
    }
}

@Composable
private fun ReactionPicker(mine: String?, onPick: (String) -> Unit, onDismiss: () -> Unit) {
    val lift = with(LocalDensity.current) { 74.dp.roundToPx() }
    val labels = REACTIONS.map { reactionLabel(it) }
    Popup(alignment = Alignment.TopStart, offset = IntOffset(0, -lift), onDismissRequest = onDismiss, properties = PopupProperties(focusable = true)) {
        val grow = remember { Animatable(0.6f) }
        LaunchedEffect(Unit) { grow.animateTo(1f, spring(dampingRatio = Spring.DampingRatioMediumBouncy, stiffness = Spring.StiffnessMedium)) }
        Row(
            Modifier.scale(grow.value)
                .shadow(14.dp, RoundedCornerShape(999.dp), ambientColor = Color(0x33001B53), spotColor = Color(0x33001B53))
                .clip(RoundedCornerShape(999.dp)).background(settingsCard())
                .then(if (settingsNight()) Modifier.border(1.dp, AccountDark.line, RoundedCornerShape(999.dp)) else Modifier)
                .padding(horizontal = 8.dp, vertical = 6.dp),
            horizontalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            REACTIONS.forEachIndexed { i, s ->
                Column(
                    Modifier.clip(RoundedCornerShape(14.dp)).clickable { onPick(s.key) }.padding(horizontal = 4.dp, vertical = 2.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Box(
                        Modifier.size(if (s.key == mine) 44.dp else 40.dp).clip(CircleShape).background(s.color),
                        contentAlignment = Alignment.Center,
                    ) { Icon(s.icon, contentDescription = labels[i], tint = Color.White, modifier = Modifier.size(21.dp)) }
                    Text(labels[i], color = settingsInk(), fontSize = 10.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                }
            }
        }
    }
}

// ===================================================================================================== badges and pills

/** The plan badge next to a name: gold for Pro, indigo for Plus, the app colour for any other plan; nothing without a badge. */
@Composable
internal fun PlanBadge(badge: CommunityBadgeDto?, large: Boolean = false) {
    val name = badge?.name?.takeIf { it.isNotBlank() } ?: return
    val colors = when (badge.plan?.lowercase()) {
        "pro" -> listOf(Color(0xFFF59E0B), Color(0xFFB45309))
        "plus" -> listOf(Color(0xFF6366F1), Color(0xFF4338CA))
        else -> listOf(settingsAccent(), settingsAccent().copy(alpha = 0.75f))
    }
    Row(
        Modifier.clip(RoundedCornerShape(999.dp)).background(Brush.horizontalGradient(colors))
            .padding(horizontal = if (large) 9.dp else 6.dp, vertical = if (large) 3.dp else 1.5.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.WorkspacePremium, contentDescription = null, tint = Color.White, modifier = Modifier.size(if (large) 13.dp else 10.dp))
        Spacer(Modifier.width(3.dp))
        Text(name, color = Color.White, fontSize = if (large) 11.sp else 9.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
    }
}

/** A name that gives way to its badge: the name is cut, the badge always shows. */
@Composable
internal fun NameWithBadge(name: String?, badge: CommunityBadgeDto?, size: Int = 14, modifier: Modifier = Modifier) {
    Row(modifier, verticalAlignment = Alignment.CenterVertically) {
        Text(
            name?.takeIf { it.isNotBlank() } ?: stringResource(R.string.cm_member),
            color = settingsInk(), fontSize = size.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis,
            modifier = Modifier.weight(1f, fill = false),
        )
        if (badge?.name != null) {
            Spacer(Modifier.width(5.dp))
            PlanBadge(badge)
        }
    }
}

internal val QUESTION_TONE = Color(0xFF2563EB)
internal val WORK_TONE = Color(0xFF16A34A)

/** The kind of a post, styled: a question in blue, a shared work in green; a plain post has none. */
@Composable
internal fun KindPill(kind: String, label: String?) {
    val (icon, tone, fallback) = when (kind) {
        "question" -> Triple(Icons.Rounded.QuestionAnswer, QUESTION_TONE, R.string.cm_kind_question)
        "work" -> Triple(Icons.Rounded.Work, WORK_TONE, R.string.cm_kind_work)
        else -> return
    }
    SoftPill(label?.takeIf { it.isNotBlank() } ?: stringResource(fallback), tone, icon)
}

@Composable
internal fun SoftPill(text: String, tone: Color, icon: ImageVector? = null, solid: Boolean = false) {
    Row(
        Modifier.clip(RoundedCornerShape(999.dp)).background(if (solid) tone else tone.copy(alpha = if (settingsNight()) 0.22f else 0.11f))
            .padding(horizontal = 9.dp, vertical = 3.5.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (icon != null) {
            Icon(icon, contentDescription = null, tint = if (solid) Color.White else tone, modifier = Modifier.size(12.dp))
            Spacer(Modifier.width(4.dp))
        }
        Text(text, color = if (solid) Color.White else tone, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

// ===================================================================================================== time

/** "now", "5m", "3h", "2d", "1w", then the date — by the phone's clock. */
@Composable
internal fun cmRelativeTime(iso: String?): String {
    val then = remember(iso) { iso?.let { runCatching { OffsetDateTime.parse(it) }.getOrNull() } } ?: return ""
    val minutes = Duration.between(then.toInstant(), java.time.Instant.now()).toMinutes().coerceAtLeast(0)
    return when {
        minutes < 1 -> stringResource(R.string.cm_time_now)
        minutes < 60 -> stringResource(R.string.cm_time_minutes, minutes.toInt())
        minutes < 24 * 60 -> stringResource(R.string.cm_time_hours, (minutes / 60).toInt())
        minutes < 7 * 24 * 60 -> stringResource(R.string.cm_time_days, (minutes / (24 * 60)).toInt())
        minutes < 5 * 7 * 24 * 60 -> stringResource(R.string.cm_time_weeks, (minutes / (7 * 24 * 60)).toInt())
        else -> DateTimeFormatter.ofPattern("d MMM yyyy", AppLocale.java()).format(then)
    }
}

internal fun cmDate(iso: String?): String =
    iso?.let { runCatching { DateTimeFormatter.ofPattern("d MMM yyyy", AppLocale.java()).format(OffsetDateTime.parse(it)) }.getOrNull() }.orEmpty()

// ===================================================================================================== body

/** A post's text, cut after a few lines with «see more» (a tap opens it in place). */
@Composable
internal fun ExpandableBody(text: String, key: String, startExpanded: Boolean = false, collapsedLines: Int = 5, size: Int = 14) {
    var expanded by rememberSaveable(key) { mutableStateOf(startExpanded) }
    var cut by remember(text) { mutableStateOf(false) }
    Column(Modifier.fillMaxWidth().animateContentSize()) {
        Text(
            text, color = settingsInk(), fontSize = size.sp, lineHeight = (size * 1.55).sp,
            maxLines = if (expanded) Int.MAX_VALUE else collapsedLines, overflow = TextOverflow.Ellipsis,
            onTextLayout = { if (!expanded) cut = it.hasVisualOverflow },
        )
        if (!expanded && cut) {
            Text(
                stringResource(R.string.cm_see_more), color = settingsMut(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable { expanded = true }.padding(vertical = 4.dp),
            )
        }
    }
}

// ===================================================================================================== images

/** A post image. The link is signed and changes on every read, so the cache keys are the image's own id. */
@Composable
internal fun CommunityPhoto(image: CommunityImageDto, modifier: Modifier = Modifier, contentScale: ContentScale = ContentScale.Crop) {
    val context = LocalContext.current
    val request = remember(image.id, image.url) {
        ImageRequest.Builder(context).data(ApiClient.mediaUrl(image.url))
            .memoryCacheKey("community-image-${image.id}").diskCacheKey("community-image-${image.id}").crossfade(true).build()
    }
    AsyncImage(model = request, contentDescription = null, contentScale = contentScale, modifier = modifier)
}

/** One to four images laid out like LinkedIn: one wide, two side by side, one big and two stacked, or a 2×2 grid. */
@Composable
internal fun ImageGrid(images: List<CommunityImageDto>, onOpen: (Int) -> Unit) {
    if (images.isEmpty()) return
    val gap = 3.dp
    val fill = if (settingsNight()) AccountDark.well else settingsAccent().copy(alpha = 0.06f)
    @Composable
    fun cell(i: Int, modifier: Modifier) {
        Box(modifier.background(fill).clickable { onOpen(i) }) {
            CommunityPhoto(images[i], Modifier.fillMaxSize())
        }
    }
    Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp))) {
        when (images.size) {
            1 -> cell(0, Modifier.fillMaxWidth().aspectRatio(4f / 3f))
            2 -> Row(Modifier.fillMaxWidth().aspectRatio(2f / 1.1f), horizontalArrangement = Arrangement.spacedBy(gap)) {
                cell(0, Modifier.weight(1f).fillMaxHeight())
                cell(1, Modifier.weight(1f).fillMaxHeight())
            }
            3 -> Row(Modifier.fillMaxWidth().aspectRatio(4f / 3f), horizontalArrangement = Arrangement.spacedBy(gap)) {
                cell(0, Modifier.weight(1.5f).fillMaxHeight())
                Column(Modifier.weight(1f).fillMaxHeight(), verticalArrangement = Arrangement.spacedBy(gap)) {
                    cell(1, Modifier.weight(1f).fillMaxWidth())
                    cell(2, Modifier.weight(1f).fillMaxWidth())
                }
            }
            else -> Column(Modifier.fillMaxWidth().aspectRatio(1f), verticalArrangement = Arrangement.spacedBy(gap)) {
                Row(Modifier.weight(1f).fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(gap)) {
                    cell(0, Modifier.weight(1f).fillMaxHeight())
                    cell(1, Modifier.weight(1f).fillMaxHeight())
                }
                Row(Modifier.weight(1f).fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(gap)) {
                    cell(2, Modifier.weight(1f).fillMaxHeight())
                    cell(3, Modifier.weight(1f).fillMaxHeight())
                }
            }
        }
    }
}

/** The images full-screen, in the app: swipe between them, pinch or double-tap to zoom, drag a zoomed image. */
@Composable
internal fun ImageViewer(images: List<CommunityImageDto>, start: Int, onDismiss: () -> Unit) {
    LocaleAwareDialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false, decorFitsSystemWindows = false)) {
        val pager = rememberPagerState(initialPage = start.coerceIn(0, (images.size - 1).coerceAtLeast(0))) { images.size }
        Box(Modifier.fillMaxSize().background(Color.Black)) {
            HorizontalPager(state = pager, modifier = Modifier.fillMaxSize(), beyondViewportPageCount = 1) { page ->
                ZoomableImage(images[page])
            }
            Row(
                Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 14.dp, vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(
                    Modifier.size(40.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.16f)).clickable(onClick = onDismiss),
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.Rounded.Close, contentDescription = stringResource(R.string.common_close), tint = Color.White, modifier = Modifier.size(22.dp)) }
                Spacer(Modifier.weight(1f))
                if (images.size > 1) {
                    Text(
                        "\u200E${pager.currentPage + 1} / ${images.size}", color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(Color.White.copy(alpha = 0.16f)).padding(horizontal = 12.dp, vertical = 6.dp),
                    )
                }
            }
            if (images.size > 1) {
                Row(
                    Modifier.align(Alignment.BottomCenter).navigationBarsPadding().padding(bottom = 18.dp),
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    repeat(images.size) { i ->
                        Box(Modifier.size(if (i == pager.currentPage) 8.dp else 6.dp).clip(CircleShape).background(Color.White.copy(alpha = if (i == pager.currentPage) 1f else 0.45f)))
                    }
                }
            }
        }
    }
}

@Composable
private fun ZoomableImage(image: CommunityImageDto) {
    var scale by remember { mutableFloatStateOf(1f) }
    var offset by remember { mutableStateOf(Offset.Zero) }
    var box by remember { mutableStateOf(IntSize.Zero) }
    fun clamp(o: Offset, s: Float): Offset {
        val maxX = box.width * (s - 1f) / 2f
        val maxY = box.height * (s - 1f) / 2f
        return Offset(o.x.coerceIn(-maxX, maxX), o.y.coerceIn(-maxY, maxY))
    }
    Box(
        Modifier.fillMaxSize().onSizeChanged { box = it }
            .pointerInput(Unit) {
                detectTapGestures(onDoubleTap = { tap ->
                    if (scale > 1f) {
                        scale = 1f
                        offset = Offset.Zero
                    } else {
                        scale = 2.5f
                        val center = Offset(box.width / 2f, box.height / 2f)
                        offset = clamp((center - tap) * 1.5f, 2.5f)
                    }
                })
            }
            .pointerInput(Unit) {
                // Pinch zooms; a drag only moves a zoomed image, else it is left to the pager (swipe to the next image).
                awaitEachGesture {
                    awaitFirstDown(requireUnconsumed = false)
                    do {
                        val event = awaitPointerEvent()
                        val zoom = event.calculateZoom()
                        val pan = event.calculatePan()
                        if (event.changes.size > 1 || scale > 1f) {
                            scale = (scale * zoom).coerceIn(1f, 5f)
                            offset = if (scale <= 1f) Offset.Zero else clamp(offset + pan, scale)
                            event.changes.forEach { if (it.positionChanged()) it.consume() }
                        }
                    } while (event.changes.any { it.pressed })
                }
            },
        contentAlignment = Alignment.Center,
    ) {
        CommunityPhoto(
            image,
            Modifier.fillMaxSize().graphicsLayer { scaleX = scale; scaleY = scale; translationX = offset.x; translationY = offset.y },
            contentScale = ContentScale.Fit,
        )
    }
}

/** The finished work a `work` post shares, as a small card. */
@Composable
internal fun WorkCard(work: CommunityWorkDto) {
    val context = LocalContext.current
    val night = settingsNight()
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp))
            .background(if (night) AccountDark.well else WORK_TONE.copy(alpha = 0.06f))
            .border(1.dp, WORK_TONE.copy(alpha = 0.22f), RoundedCornerShape(14.dp)).padding(10.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(64.dp).clip(RoundedCornerShape(12.dp)).background(WORK_TONE.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            val url = work.imageUrl
            if (url != null) {
                val request = remember(work.uuid, url) {
                    ImageRequest.Builder(context).data(ApiClient.mediaUrl(url)).memoryCacheKey("community-work-${work.uuid}").diskCacheKey("community-work-${work.uuid}").crossfade(true).build()
                }
                AsyncImage(model = request, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
            } else {
                Icon(Icons.Rounded.Work, contentDescription = null, tint = WORK_TONE, modifier = Modifier.size(26.dp))
            }
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(stringResource(R.string.cm_work_from_portfolio), color = WORK_TONE, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold)
            Text(work.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
        }
    }
}

// ===================================================================================================== actions

/**
 * What a tap on a post does, optimistically: the change shows at once on every screen (through [CommunityHub.changes]), the server's answer
 * then settles it, and a refusal puts it back with the server's message. Runs in its own scope so leaving the screen does not cut it off.
 */
object CommunityActions {
    private val scope = MainScope()

    private fun toast(context: Context, text: String?) {
        if (!text.isNullOrBlank()) Toast.makeText(context, text, Toast.LENGTH_SHORT).show()
    }

    private fun offline(context: Context) = context.getString(R.string.login_error_offline)
    private fun generic(context: Context) = context.getString(R.string.login_error_generic)

    /** Why I can't write now, or null when I can (the home not read yet lets the server decide). */
    fun cannotWrite(context: Context): String? {
        val home = CommunityHub.home.value ?: return null
        if (home.canWrite) return null
        return home.muted?.let { context.getString(R.string.cm_muted_until, cmDate(it.until)) } ?: context.getString(R.string.cm_need_verified)
    }

    fun react(context: Context, post: CommunityPostDto, kind: String?) {
        cannotWrite(context)?.let { toast(context, it); return }
        val (n, top) = optimisticReaction(post.reactions, post.topReactions, post.myReaction, kind)
        CommunityHub.emit(CommunityChange.PostUpdated(post.copy(reactions = n, topReactions = top, myReaction = kind)))
        scope.launch {
            when (val r = apiCall { ApiClient.community.react("posts", post.uuid, CommunityReactionRequest(kind)) }) {
                is ApiResult.Ok -> CommunityHub.emit(CommunityChange.PostUpdated(post.copy(reactions = r.data.reactions, topReactions = r.data.top, myReaction = r.data.myReaction)))
                else -> {
                    CommunityHub.emit(CommunityChange.PostUpdated(post))
                    toast(context, r.errorText(offline(context)) ?: generic(context))
                }
            }
        }
    }

    fun save(context: Context, post: CommunityPostDto) {
        val on = !post.saved
        CommunityHub.emit(CommunityChange.PostUpdated(post.copy(saved = on)))
        scope.launch {
            val r = apiCall { if (on) ApiClient.community.save(post.uuid) else ApiClient.community.unsave(post.uuid) }
            if (r is ApiResult.Ok) {
                toast(context, r.message)
            } else {
                CommunityHub.emit(CommunityChange.PostUpdated(post))
                toast(context, r.errorText(offline(context)) ?: generic(context))
            }
        }
    }

    /** Follows / unfollows; [onCard] gets the member's new card (their counts) from the server. */
    fun follow(context: Context, member: String, on: Boolean, onCard: (com.dorr.freelance.net.CommunityMemberDto) -> Unit = {}, onFailed: () -> Unit = {}) {
        CommunityHub.emit(CommunityChange.FollowChanged(member, on))
        scope.launch {
            val r = apiCall { if (on) ApiClient.community.follow(member) else ApiClient.community.unfollow(member) }
            if (r is ApiResult.Ok) {
                onCard(r.data)
                CommunityHub.refresh()
            } else {
                CommunityHub.emit(CommunityChange.FollowChanged(member, !on))
                onFailed()
                toast(context, r.errorText(offline(context)) ?: generic(context))
            }
        }
    }

    fun delete(context: Context, post: CommunityPostDto, onFailed: () -> Unit, onDone: () -> Unit) {
        scope.launch {
            val failed = apiDone(offline(context), generic(context)) { ApiClient.community.delete(post.uuid) }
            if (failed == null) {
                onDone()
                CommunityHub.emit(CommunityChange.PostDeleted(post.uuid))
                toast(context, context.getString(R.string.cm_deleted))
                CommunityHub.refresh()
            } else {
                onFailed()
                toast(context, failed)
            }
        }
    }
}

// ===================================================================================================== the post card

/**
 * A post, LinkedIn-like: the author (photo, name, badge, headline, time, «edited», a quick «follow»), the kind and the specialty, the text
 * with «see more», the images, the shared work, what people reacted and how many commented, then React · Comment · Save. The menu holds
 * edit / delete for my own post and report for the others'.
 */
@Composable
internal fun PostCard(
    post: CommunityPostDto,
    nav: CommunityNav,
    expanded: Boolean = false,
    onComment: () -> Unit = { nav.openPost(post.uuid) },
    onDeleted: () -> Unit = {},
) {
    val context = LocalContext.current
    var viewing by remember { mutableStateOf<Int?>(null) }
    var menu by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf(false) }
    var deleting by remember { mutableStateOf(false) }
    var reporting by remember { mutableStateOf(false) }
    val a = post.author
    val open: (() -> Unit)? = if (expanded) null else ({ nav.openPost(post.uuid) })

    Column(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp), elevation = 4.dp)
            .then(if (open != null) Modifier.clickable(onClick = open) else Modifier)
            .padding(top = 14.dp),
    ) {
        // ---- the author
        Row(Modifier.padding(horizontal = 14.dp), verticalAlignment = Alignment.Top) {
            Box(Modifier.clip(CircleShape).clickable(enabled = a.uuid != null) { a.uuid?.let(nav.openMember) }) { AvatarImage(a.avatarUrl, 46.dp) }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f).clickable(enabled = a.uuid != null) { a.uuid?.let(nav.openMember) }) {
                NameWithBadge(a.name, a.badge)
                a.headline?.takeIf { it.isNotBlank() }?.let {
                    Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                val time = cmRelativeTime(post.createdAt)
                Text(
                    if (post.edited) "$time · ${stringResource(R.string.cm_edited)}" else time,
                    color = settingsMut(), fontSize = 11.5.sp, maxLines = 1,
                )
            }
            if (!a.isMe && a.isFollowing == false && a.uuid != null) {
                Row(
                    Modifier.clip(RoundedCornerShape(999.dp)).clickable { CommunityActions.follow(context, a.uuid, true) }.padding(horizontal = 8.dp, vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.Add, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
                    Text(stringResource(R.string.cm_follow), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                }
            }
            Box {
                Box(Modifier.size(34.dp).clip(CircleShape).clickable { menu = true }, contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.MoreHoriz, contentDescription = stringResource(R.string.cm_more), tint = settingsMut(), modifier = Modifier.size(22.dp))
                }
                DropdownMenu(expanded = menu, onDismissRequest = { menu = false }, containerColor = settingsCard()) {
                    if (post.can.edit) MenuLine(Icons.Rounded.Edit, stringResource(R.string.cm_edit_post)) { menu = false; nav.editPost(post.uuid) }
                    if (post.can.delete) MenuLine(Icons.Rounded.Delete, stringResource(R.string.cm_delete_post), danger = true) { menu = false; confirmDelete = true }
                    if (post.can.report) MenuLine(Icons.Rounded.Flag, stringResource(R.string.cm_report_post)) { menu = false; reporting = true }
                    if (!post.can.edit && !post.can.delete && !post.can.report) MenuLine(Icons.Rounded.Groups, stringResource(R.string.cm_view_profile)) { menu = false; a.uuid?.let(nav.openMember) }
                }
            }
        }

        // ---- kind, specialty, solved
        val solved = post.kind == "question" && post.acceptedComment != null
        if (post.kind != "post" || post.specialty != null || solved) {
            Row(Modifier.padding(horizontal = 14.dp).padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                KindPill(post.kind, post.kindLabel)
                if (solved) SoftPill(stringResource(R.string.cm_solved), WORK_TONE, Icons.Rounded.CheckCircle)
                post.specialty?.let { SoftPill(it.name, settingsMut()) }
            }
        }

        // ---- hidden (mine only)
        post.hidden?.let { h -> HiddenNote(h.underReview, h.reason, Modifier.padding(horizontal = 14.dp).padding(top = 10.dp)) }

        // ---- the text
        Box(Modifier.padding(horizontal = 14.dp).padding(top = 10.dp)) {
            ExpandableBody(post.body, "post-${post.uuid}", startExpanded = expanded, size = if (expanded) 15 else 14)
        }

        // ---- images and the shared work
        if (post.images.isNotEmpty()) {
            Box(Modifier.padding(horizontal = 10.dp).padding(top = 10.dp)) { ImageGrid(post.images) { viewing = it } }
        }
        post.work?.let { w -> Box(Modifier.padding(horizontal = 14.dp).padding(top = 10.dp)) { WorkCard(w) } }

        // ---- what people did
        if (post.reactions > 0 || post.comments > 0) {
            Row(Modifier.fillMaxWidth().padding(horizontal = 14.dp).padding(top = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                if (post.reactions > 0) {
                    ReactionStack(post.topReactions)
                    Spacer(Modifier.width(4.dp))
                    Text("${post.reactions}", color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                }
                Spacer(Modifier.weight(1f))
                if (post.comments > 0) {
                    Text(
                        stringResource(R.string.cm_comments_count, post.comments), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
                        modifier = Modifier.clip(RoundedCornerShape(6.dp)).clickable(onClick = onComment).padding(2.dp),
                    )
                }
            }
        }
        HorizontalDivider(Modifier.padding(horizontal = 14.dp).padding(top = 10.dp), color = if (settingsNight()) AccountDark.line else Color(0x14001B53))

        // ---- react · comment · save
        Row(Modifier.fillMaxWidth().padding(horizontal = 6.dp, vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.weight(1f), contentAlignment = Alignment.Center) { ReactButton(post.myReaction) { CommunityActions.react(context, post, it) } }
            ActionButton(Icons.Outlined.ChatBubbleOutline, stringResource(R.string.cm_comment), settingsMut(), Modifier.weight(1f), onComment)
            ActionButton(
                if (post.saved) Icons.Rounded.Bookmark else Icons.Outlined.BookmarkBorder,
                stringResource(if (post.saved) R.string.cm_saved else R.string.cm_save),
                if (post.saved) settingsAccent() else settingsMut(), Modifier.weight(1f),
            ) { CommunityActions.save(context, post) }
        }
    }

    viewing?.let { ImageViewer(post.images, it) { viewing = null } }
    if (reporting) ReportSheet("post", post.uuid) { reporting = false }
    if (confirmDelete) {
        ConfirmDialog(
            icon = Icons.Rounded.Delete, title = stringResource(R.string.cm_delete_post_title), message = stringResource(R.string.cm_delete_post_body),
            confirmLabel = stringResource(R.string.common_delete), loading = deleting,
            onConfirm = {
                deleting = true
                CommunityActions.delete(context, post, onFailed = { deleting = false }) { deleting = false; confirmDelete = false; onDeleted() }
            },
            onDismiss = { confirmDelete = false },
        )
    }
}

@Composable
internal fun HiddenNote(underReview: Boolean, reason: String?, modifier: Modifier = Modifier) {
    val tone = Color(0xFFD97706)
    Row(
        modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(tone.copy(alpha = 0.1f)).padding(horizontal = 10.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Icon(Icons.Rounded.VisibilityOff, contentDescription = null, tint = tone, modifier = Modifier.size(16.dp))
        Text(
            if (underReview) stringResource(R.string.cm_hidden_review) else stringResource(R.string.cm_hidden_staff, reason.orEmpty()),
            color = tone, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, lineHeight = 17.sp,
        )
    }
}

@Composable
private fun ActionButton(icon: ImageVector, label: String, tint: Color, modifier: Modifier, onClick: () -> Unit) {
    Row(
        modifier.clip(RoundedCornerShape(10.dp)).clickable(onClick = onClick).padding(vertical = 9.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center,
    ) {
        Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(19.dp))
        Spacer(Modifier.width(5.dp))
        Text(label, color = tint, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

@Composable
internal fun MenuLine(icon: ImageVector, label: String, danger: Boolean = false, onClick: () -> Unit) {
    val tint = if (danger) Color(0xFFDC2626) else settingsInk()
    DropdownMenuItem(
        text = { Text(label, color = tint, fontSize = 14.sp, fontWeight = FontWeight.SemiBold) },
        leadingIcon = { Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(19.dp)) },
        onClick = onClick,
    )
}

/** A post card while the feed loads: the shape of a post, softly pulsing. */
@Composable
internal fun PostSkeleton() {
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp), elevation = 4.dp).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            SkeletonBlock(Modifier.size(46.dp), height = 46.dp, radius = 23.dp)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                SkeletonBlock(Modifier.fillMaxWidth(0.5f), height = 12.dp)
                SkeletonBlock(Modifier.fillMaxWidth(0.75f), height = 10.dp)
                SkeletonBlock(Modifier.fillMaxWidth(0.25f), height = 9.dp)
            }
        }
        SkeletonBlock(Modifier.fillMaxWidth(), height = 11.dp)
        SkeletonBlock(Modifier.fillMaxWidth(0.92f), height = 11.dp)
        SkeletonBlock(Modifier.fillMaxWidth(0.6f), height = 11.dp)
        SkeletonBlock(Modifier.fillMaxWidth().height(150.dp), height = 150.dp, radius = 14.dp)
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            repeat(3) { SkeletonBlock(Modifier.weight(1f), height = 18.dp) }
        }
    }
}

// ===================================================================================================== report

/**
 * Report a post or a comment: one of the server's reasons and, if wanted, a few words. The author is not told who reported. A report
 * already sent, or one on my own words, comes back as the server's message, shown in the sheet.
 */
@Composable
internal fun ReportSheet(subjectType: String, subject: String, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val home by CommunityHub.home.collectAsState()
    val reasons = home?.reportReasons.orEmpty().ifEmpty {
        listOf("spam", "contact", "abuse", "off_topic", "misleading", "other").map { com.dorr.freelance.net.CommunityKeyLabel(it, it) }
    }
    var reason by rememberSaveable { mutableStateOf<String?>(null) }
    var details by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val danger = Color(0xFFDC2626)

    LocaleAwareBottomSheet(onDismissRequest = { if (!busy) onDismiss() }, containerColor = settingsCard(), shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp), fullHeight = true) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Box(Modifier.size(48.dp).clip(RoundedCornerShape(15.dp)).background(danger.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Flag, contentDescription = null, tint = danger, modifier = Modifier.size(24.dp))
                }
                Column(Modifier.weight(1f)) {
                    Text(
                        stringResource(if (subjectType == "post") R.string.cm_report_post_title else R.string.cm_report_comment_title),
                        color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold,
                    )
                    Text(stringResource(R.string.cm_report_hint), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
                }
            }
            Text(stringResource(R.string.cm_report_why), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
            reasons.forEach { r ->
                val on = reason == r.key
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp))
                        .background(if (on) settingsAccent().copy(alpha = 0.1f) else Color.Transparent)
                        .border(1.dp, if (on) settingsAccent() else (if (settingsNight()) AccountDark.line else Color(0xFFE5E7EB)), RoundedCornerShape(14.dp))
                        .clickable { reason = r.key; error = null }.padding(horizontal = 14.dp, vertical = 12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(r.label, color = settingsInk(), fontSize = 14.sp, fontWeight = if (on) FontWeight.ExtraBold else FontWeight.SemiBold, modifier = Modifier.weight(1f))
                    Box(
                        Modifier.size(20.dp).clip(CircleShape).border(2.dp, if (on) settingsAccent() else settingsMut().copy(alpha = 0.5f), CircleShape),
                        contentAlignment = Alignment.Center,
                    ) { if (on) Box(Modifier.size(10.dp).clip(CircleShape).background(settingsAccent())) }
                }
            }
            DorrTextField(
                details, { details = it.take(1000) }, placeholder = stringResource(R.string.cm_report_details), singleLine = false, minLines = 3,
                modifier = Modifier.fillMaxWidth(),
            )
            error?.let {
                Text(it, color = danger, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(danger.copy(alpha = 0.08f)).padding(10.dp))
            }
            SettingsPrimaryButton(stringResource(R.string.cm_report_send), enabled = reason != null, loading = busy) {
                busy = true
                error = null
                scope.launch {
                    val r = apiCall { ApiClient.community.report(CommunityReportRequest(subjectType, subject, reason.orEmpty(), details.trim().ifEmpty { null })) }
                    busy = false
                    if (r is ApiResult.Ok) {
                        Toast.makeText(context, r.message ?: context.getString(R.string.cm_report_done), Toast.LENGTH_SHORT).show()
                        onDismiss()
                    } else {
                        error = r.errorText(offlineText) ?: genericError
                    }
                }
            }
        }
    }
}

// ===================================================================================================== entry points

/**
 * The community's door on the home: a wide card on the app colour with my photo and «start a post», shown only while the feature is on
 * in my country ([CommunityHub.available]).
 */
@Composable
internal fun CommunityHomeCard(onOpen: () -> Unit, onCompose: () -> Unit) {
    val available by CommunityHub.available.collectAsState()
    if (available != true) return
    val home by CommunityHub.home.collectAsState()
    val me by com.dorr.freelance.net.Session.me.collectAsState()
    val accent = settingsAccent()
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp))
            .background(Brush.linearGradient(listOf(accent, Color(accent.red * 0.72f, accent.green * 0.72f, accent.blue * 0.78f))))
            .clickable(onClick = onOpen).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(Color.White.copy(alpha = 0.18f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Groups, contentDescription = null, tint = Color.White, modifier = Modifier.size(24.dp))
            }
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.cm_home_title), color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                Text(stringResource(R.string.cm_home_body), color = Color.White.copy(alpha = 0.88f), fontSize = 12.sp, lineHeight = 17.sp)
            }
        }
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(999.dp)).background(Color.White).clickable(onClick = onCompose).padding(5.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            AvatarImage(home?.me?.avatarUrl ?: me?.avatarUrl, 32.dp)
            Spacer(Modifier.width(10.dp))
            Text(stringResource(R.string.cm_start_post), color = Color(0xFF6B7280), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
            Icon(Icons.Rounded.Image, contentDescription = null, tint = accent, modifier = Modifier.padding(end = 10.dp).size(20.dp))
        }
        home?.me?.let { m ->
            Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                Text(stringResource(R.string.cm_stat_posts_n, m.posts), color = Color.White.copy(alpha = 0.92f), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
                Text(stringResource(R.string.cm_stat_followers_n, m.followers), color = Color.White.copy(alpha = 0.92f), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
                Text(stringResource(R.string.cm_stat_following_n, m.following), color = Color.White.copy(alpha = 0.92f), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}

/** A loading line for a button row (follow, send…). */
@Composable
internal fun SmallSpinner(color: Color = settingsAccent(), size: Dp = 18.dp) {
    CircularProgressIndicator(color = color, strokeWidth = 2.dp, modifier = Modifier.size(size))
}
