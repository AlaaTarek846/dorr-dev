package com.dorr.app.ui.screens.chat

import android.content.Context
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Done
import androidx.compose.material.icons.rounded.DoneAll
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.composed
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalInspectionMode
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.ImageLoader
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.ui.screens.wallet.Wa
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.temporal.ChronoUnit
import java.util.Locale
import kotlin.math.absoluteValue

/**
 * The chat's look: Dorr's red brand (shared with the wallet — [Wa]) carried into a messenger.
 * Outgoing bubbles wear the brand gradient, incoming ones are soft white cards; everything that
 * appears, changes or disappears is animated (springs for things you touch, eased fades for
 * things that just arrive). Every chat screen reads its colours, shapes and motion from here.
 */
object Ch {
    /**
     * Dark mode for the chat — set by ChatScreen from the app's theme (the user's choice, or the
     * system's). It's snapshot state, so every screen reading a colour below redraws on its own
     * when it flips; nothing else in the chat hard-codes light colours.
     */
    var dark by androidx.compose.runtime.mutableStateOf(false)

    val Red = Wa.Red
    val RedDeep = Color(0xFFC40812)
    val Ink get() = if (dark) Color(0xFFF3F4F6) else Wa.Ink
    val Mut get() = if (dark) Color(0xFF9CA3AF) else Wa.Mut
    val Soft get() = if (dark) Color(0xFF6B7280) else Wa.Soft
    val Line get() = if (dark) Color(0xFF273244) else Wa.Line
    val Bg get() = if (dark) Color(0xFF0B1220) else Color(0xFFF7F4F2)
    /** Cards, rows, sheets, the incoming bubble. */
    val Surface get() = if (dark) Color(0xFF172033) else Color.White
    /** Quiet wells inside a surface: quotes, file cards, chips, image placeholders. */
    val SurfaceMuted get() = if (dark) Color(0xFF223047) else Color(0xFFF4F4F6)
    /** Soft brand tint for banners (requests, sync). */
    val TintBrush get() = if (dark) Brush.horizontalGradient(listOf(Color(0xFF3A1016), Color(0xFF2A0D12)))
    else Brush.horizontalGradient(listOf(Color(0xFFFFF1F2), Color(0xFFFFE4E6)))
    val Online = Color(0xFF22C55E)
    val ReadTick = Color(0xFF38BDF8)
    val Mention = Color(0xFFF59E0B)

    val OutBubble = Brush.linearGradient(listOf(Color(0xFFF2202C), Color(0xFFD80A16), Color(0xFFB30710)), start = Offset(0f, 0f), end = Offset(600f, 400f))
    val InBubble get() = Surface
    val OutText = Color.White
    val InText get() = Ink

    val HeaderBrush = Brush.linearGradient(listOf(Color(0xFFF2202C), Color(0xFFC40812), Color(0xFF7A0410)), start = Offset(0f, 0f), end = Offset(1100f, 500f))
    val Wallpaper get() = if (dark) Brush.verticalGradient(listOf(Color(0xFF0E1626), Color(0xFF0B1220), Color(0xFF080E1A)))
    else Brush.verticalGradient(listOf(Color(0xFFFBF1EF), Color(0xFFF6EEEB), Color(0xFFF3ECEA)))

    val BubbleRadius = 20.dp
    val TailRadius = 6.dp

    /** Name colours in groups — each member keeps one colour, derived from their key. */
    val NamePalette = listOf(
        Color(0xFFE11D48), Color(0xFF7C3AED), Color(0xFF0891B2), Color(0xFF059669),
        Color(0xFFD97706), Color(0xFF2563EB), Color(0xFFDB2777), Color(0xFF65A30D),
    )

    /** Avatar backgrounds for people without a photo. */
    val AvatarGradients = listOf(
        listOf(Color(0xFFFB7185), Color(0xFFE11D48)),
        listOf(Color(0xFFA78BFA), Color(0xFF7C3AED)),
        listOf(Color(0xFF22D3EE), Color(0xFF0891B2)),
        listOf(Color(0xFF34D399), Color(0xFF059669)),
        listOf(Color(0xFFFBBF24), Color(0xFFD97706)),
        listOf(Color(0xFF60A5FA), Color(0xFF2563EB)),
        listOf(Color(0xFFF472B6), Color(0xFFDB2777)),
        listOf(Color(0xFFF87171), Color(0xFFB91C1C)),
    )

    fun colorFor(key: String?): Color = NamePalette[(key.orEmpty().hashCode().absoluteValue) % NamePalette.size]

    val springy = spring<Float>(dampingRatio = Spring.DampingRatioMediumBouncy, stiffness = Spring.StiffnessMediumLow)
}

internal fun chatAuth(): String = "Bearer ${AuthSession.token.orEmpty()}"

internal fun myKey(): String = "user:${AuthSession.user?.id ?: 0}"

// ------------------------------------------------------------------------------- images

private var chatImageLoader: ImageLoader? = null

/** One Coil loader for the chat (shares the API client, so media gets the dev Host header). */
internal fun chatImages(context: Context): ImageLoader =
    chatImageLoader ?: ImageLoader.Builder(context.applicationContext)
        .okHttpClient(ApiClient.okHttpClient)
        .crossfade(220)
        .build()
        .also { chatImageLoader = it }

// ------------------------------------------------------------------------------- avatar

/**
 * A round avatar: the photo when there is one, otherwise the initials on a gradient that's always
 * the same for the same person. `online` adds a breathing green dot; `ring` a brand-gradient ring
 * (unseen story / active call).
 */
@Composable
fun ChAvatar(
    url: String?,
    name: String?,
    key: String?,
    size: Dp = 52.dp,
    isGroup: Boolean = false,
    online: Boolean = false,
    ring: Boolean = false,
    modifier: Modifier = Modifier,
) {
    val context = LocalContext.current
    val gradient = remember(key) { Ch.AvatarGradients[(key.orEmpty().hashCode().absoluteValue) % Ch.AvatarGradients.size] }

    Box(modifier.size(size)) {
        val inner = if (ring) Modifier.padding(3.dp) else Modifier
        Box(
            Modifier
                .fillMaxSize()
                .then(
                    if (ring) Modifier.border(2.5.dp, Brush.sweepGradient(listOf(Ch.Red, Color(0xFFFF8A4C), Color(0xFFDB2777), Ch.Red)), CircleShape)
                    else Modifier,
                ),
        ) {
            Box(
                inner
                    .fillMaxSize()
                    .clip(CircleShape)
                    .background(Brush.linearGradient(gradient)),
                contentAlignment = Alignment.Center,
            ) {
                if (isGroup && url == null) {
                    Icon(Icons.Rounded.Groups, null, tint = Color.White, modifier = Modifier.size(size * 0.5f))
                } else {
                    Text(
                        initials(name),
                        color = Color.White,
                        fontWeight = FontWeight.ExtraBold,
                        fontSize = (size.value * 0.36f).sp,
                    )
                }
                if (url != null) {
                    AsyncImage(
                        model = ApiClient.mediaUrl(url),
                        imageLoader = chatImages(context),
                        contentDescription = name,
                        contentScale = ContentScale.Crop,
                        modifier = Modifier.fillMaxSize(),
                    )
                }
            }
        }
        if (online) OnlineDot(Modifier.align(Alignment.BottomEnd), size)
    }
}

@Composable
private fun OnlineDot(modifier: Modifier, avatarSize: Dp) {
    val dot = (avatarSize.value * 0.26f).coerceIn(10f, 16f).dp
    val pulse = rememberInfiniteTransition(label = "online")
    val halo by pulse.animateFloat(1f, 1.9f, infiniteRepeatable(tween(1400, easing = FastOutSlowInEasing), RepeatMode.Restart), label = "halo")
    val haloAlpha by pulse.animateFloat(0.45f, 0f, infiniteRepeatable(tween(1400, easing = FastOutSlowInEasing), RepeatMode.Restart), label = "haloAlpha")
    Box(modifier.size(dot), contentAlignment = Alignment.Center) {
        Box(Modifier.size(dot).scale(halo).graphicsLayer { alpha = haloAlpha }.background(Ch.Online, CircleShape))
        Box(Modifier.size(dot).border(2.dp, Color.White, CircleShape).padding(2.dp).background(Ch.Online, CircleShape))
    }
}

internal fun initials(name: String?): String {
    val words = name.orEmpty().trim().split(Regex("\\s+")).filter { it.isNotEmpty() && it.first().isLetterOrDigit() }
    return when {
        words.isEmpty() -> "؟"
        words.size == 1 -> words[0].take(1).uppercase()
        else -> (words[0].take(1) + words[1].take(1)).uppercase()
    }
}

// ------------------------------------------------------------------------------- badges & ticks

/** Unread count that pops (spring) whenever the number changes. */
@Composable
fun ChBadge(count: Int, muted: Boolean = false, modifier: Modifier = Modifier) {
    val scale = remember { Animatable(0.4f) }
    LaunchedEffect(count) {
        scale.snapTo(0.6f)
        scale.animateTo(1f, spring(dampingRatio = 0.35f, stiffness = Spring.StiffnessMedium))
    }
    Box(
        modifier
            .scale(scale.value)
            .defaultMinSize(minWidth = 22.dp, minHeight = 22.dp)
            .clip(CircleShape)
            .background(if (muted) Ch.Soft else Ch.Red)
            .padding(horizontal = 6.dp),
        contentAlignment = Alignment.Center,
    ) {
        AnimatedContent(targetState = count, transitionSpec = {
            (scaleIn(tween(220)) + fadeIn(tween(220))) togetherWith (scaleOut(tween(160)) + fadeOut(tween(160)))
        }, label = "badge") { value ->
            Text(if (value > 99) "99+" else value.toString(), color = Color.White, fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold)
        }
    }
}

/**
 * ⏱ → ✓ → ✓✓ → blue ✓✓ — the icon swaps with a little pop and the colour glides to blue.
 * `pending` is a message still on its way up.
 */
@Composable
fun ChTicks(status: String?, onBubble: Boolean, modifier: Modifier = Modifier, size: Dp = 16.dp) {
    val base = if (onBubble) Color.White.copy(alpha = 0.78f) else Ch.Soft
    val readColor = if (onBubble) Color(0xFFBAE6FD) else Ch.ReadTick
    val tint by animateColorAsState(if (status == "read") readColor else base, tween(450), label = "tick")
    AnimatedContent(targetState = status, transitionSpec = {
        (scaleIn(spring(dampingRatio = 0.4f), initialScale = 0.3f) + fadeIn(tween(160))) togetherWith fadeOut(tween(120))
    }, label = "ticks", modifier = modifier) { value ->
        val icon = when (value) {
            "pending" -> Icons.Rounded.Schedule
            "sent" -> Icons.Rounded.Done
            else -> Icons.Rounded.DoneAll
        }
        Icon(icon, null, tint = tint, modifier = Modifier.size(if (value == "pending") size - 3.dp else size))
    }
}

/** Three dots rising one after another — "typing…" / "recording…". */
@Composable
fun TypingDots(color: Color = Ch.Red, dot: Dp = 6.dp, modifier: Modifier = Modifier) {
    val transition = rememberInfiniteTransition(label = "typing")
    Row(modifier, horizontalArrangement = Arrangement.spacedBy(dot * 0.6f), verticalAlignment = Alignment.CenterVertically) {
        repeat(3) { i ->
            val y by transition.animateFloat(
                initialValue = 0f, targetValue = 1f,
                animationSpec = infiniteRepeatable(tween(560, delayMillis = i * 140, easing = FastOutSlowInEasing), RepeatMode.Reverse),
                label = "dot$i",
            )
            Box(Modifier.size(dot).graphicsLayer { translationY = -y * dot.toPx() * 0.9f; alpha = 0.45f + 0.55f * y }.background(color, CircleShape))
        }
    }
}

// ------------------------------------------------------------------------------- motion

/** Pop-in for things that just arrived (a new bubble, a list row): scale + rise + fade. */
fun Modifier.chPopIn(enabled: Boolean, fromEnd: Boolean = false): Modifier = composed {
    val inspecting = LocalInspectionMode.current
    val progress = remember { Animatable(if (enabled && !inspecting) 0f else 1f) }
    LaunchedEffect(Unit) {
        if (progress.value < 1f) progress.animateTo(1f, spring(dampingRatio = 0.62f, stiffness = 420f))
    }
    graphicsLayer {
        val p = progress.value
        alpha = p.coerceIn(0f, 1f)
        scaleX = 0.82f + 0.18f * p
        scaleY = 0.82f + 0.18f * p
        translationY = (1f - p) * 22.dp.toPx()
        transformOrigin = androidx.compose.ui.graphics.TransformOrigin(if (fromEnd) 1f else 0f, 1f)
    }
}

/** Staggered entrance for list rows (first page only). */
fun Modifier.chStagger(index: Int, enabled: Boolean = true): Modifier = composed {
    val inspecting = LocalInspectionMode.current
    val rtl = androidx.compose.ui.platform.LocalLayoutDirection.current == androidx.compose.ui.unit.LayoutDirection.Rtl
    val progress = remember { Animatable(if (enabled && !inspecting && index < 14) 0f else 1f) }
    LaunchedEffect(Unit) {
        if (progress.value < 1f) progress.animateTo(1f, tween(420, delayMillis = 40 + index * 45, easing = FastOutSlowInEasing))
    }
    graphicsLayer {
        alpha = progress.value
        translationX = (1f - progress.value) * 28.dp.toPx() * (if (rtl) -1 else 1)
    }
}

// ------------------------------------------------------------------------------- wallpaper

/**
 * The conversation background: a warm wash with a faint doodle pattern (bubbles, hearts, stars)
 * drawn on a Canvas — no image assets, crisp at every density.
 */
@Composable
fun ChWallpaper(modifier: Modifier = Modifier, tint: Color = Ch.Red) {
    Canvas(modifier.fillMaxSize().background(Ch.Wallpaper)) {
        val step = 92.dp.toPx()
        val stroke = Stroke(width = 1.4.dp.toPx())
        val c = tint.copy(alpha = 0.07f)
        var row = 0
        var y = -step / 2
        while (y < size.height + step) {
            var x = if (row % 2 == 0) 0f else step / 2
            var i = row
            while (x < size.width + step) {
                val s = 11.dp.toPx()
                when (i % 4) {
                    0 -> { // chat bubble
                        drawRoundRect(c, Offset(x - s, y - s * 0.7f), androidx.compose.ui.geometry.Size(s * 2, s * 1.4f), androidx.compose.ui.geometry.CornerRadius(s * 0.6f), style = stroke)
                        drawLine(c, Offset(x - s * 0.4f, y + s * 0.7f), Offset(x - s * 0.8f, y + s * 1.2f), strokeWidth = stroke.width)
                    }
                    1 -> { // heart
                        val p = Path().apply {
                            moveTo(x, y + s * 0.8f)
                            cubicTo(x - s * 1.4f, y - s * 0.1f, x - s * 0.6f, y - s * 1.1f, x, y - s * 0.3f)
                            cubicTo(x + s * 0.6f, y - s * 1.1f, x + s * 1.4f, y - s * 0.1f, x, y + s * 0.8f)
                        }
                        drawPath(p, c, style = stroke)
                    }
                    2 -> { // sparkle
                        drawLine(c, Offset(x, y - s), Offset(x, y + s), strokeWidth = stroke.width)
                        drawLine(c, Offset(x - s, y), Offset(x + s, y), strokeWidth = stroke.width)
                        drawCircle(c, s * 0.28f, Offset(x, y), style = stroke)
                    }
                    else -> drawCircle(c, s * 0.75f, Offset(x, y), style = stroke) // bubble dot
                }
                x += step
                i++
            }
            y += step * 0.72f
            row++
        }
    }
}

// ------------------------------------------------------------------------------- time

private val zone: ZoneId get() = ZoneId.systemDefault()

internal fun parseInstant(iso: String?): Instant? = iso?.let { runCatching { java.time.OffsetDateTime.parse(it).toInstant() }.getOrNull() }

/** "14:05" */
internal fun clockTime(iso: String?): String = parseInstant(iso)?.let {
    DateTimeFormatter.ofPattern("HH:mm", Locale.getDefault()).withZone(zone).format(it)
}.orEmpty()

/** Chat list: today → time, yesterday → "Yesterday", this week → weekday, else the date. */
@Composable
internal fun listTime(iso: String?): String {
    val instant = parseInstant(iso) ?: return ""
    val day = instant.atZone(zone).toLocalDate()
    val today = LocalDate.now(zone)
    return when {
        day == today -> clockTime(iso)
        day == today.minusDays(1) -> androidx.compose.ui.res.stringResource(R.string.ch_yesterday)
        ChronoUnit.DAYS.between(day, today) < 7 -> DateTimeFormatter.ofPattern("EEEE", Locale.getDefault()).format(day)
        else -> DateTimeFormatter.ofPattern("d/M/yyyy", Locale.getDefault()).format(day)
    }
}

internal fun dayOf(iso: String?): LocalDate? = parseInstant(iso)?.atZone(zone)?.toLocalDate()

/** The floating date chip in a conversation. */
@Composable
internal fun dayLabel(day: LocalDate): String {
    val today = LocalDate.now(zone)
    return when (day) {
        today -> androidx.compose.ui.res.stringResource(R.string.ch_today)
        today.minusDays(1) -> androidx.compose.ui.res.stringResource(R.string.ch_yesterday)
        else -> DateTimeFormatter.ofPattern("d MMMM yyyy", Locale.getDefault()).format(day)
    }
}

internal fun durationText(ms: Long): String {
    val total = (ms / 1000).coerceAtLeast(0)
    return "%d:%02d".format(total / 60, total % 60)
}

internal fun fileSizeText(bytes: Long?): String {
    val b = bytes ?: return ""
    return when {
        b >= 1_048_576 -> "%.1f MB".format(b / 1_048_576.0)
        b >= 1024 -> "%d KB".format(b / 1024)
        else -> "$b B"
    }
}

/** A small rounded chip (date separators, system lines). */
@Composable
fun ChChip(text: String, modifier: Modifier = Modifier, dark: Boolean = false) {
    Box(
        modifier
            .clip(RoundedCornerShape(12.dp))
            .background(if (dark) Color(0xCC111928) else Color.White.copy(alpha = 0.92f))
            .padding(horizontal = 12.dp, vertical = 5.dp),
    ) {
        Text(text, color = if (dark) Color.White else Ch.Mut, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
    }
}

/** Thin brand line used under headers while something loads. */
@Composable
fun ChLoadingBar(visible: Boolean, modifier: Modifier = Modifier) {
    if (!visible) return
    val t = rememberInfiniteTransition(label = "bar")
    val x by t.animateFloat(-0.4f, 1.1f, infiniteRepeatable(tween(1100, easing = FastOutSlowInEasing)), label = "x")
    Canvas(modifier.height(2.dp).width(9999.dp)) {
        val w = size.width * 0.35f
        drawRect(Brush.horizontalGradient(listOf(Color.Transparent, Ch.Red, Color.Transparent), startX = size.width * x, endX = size.width * x + w), topLeft = Offset(size.width * x, 0f), size = androidx.compose.ui.geometry.Size(w, size.height))
    }
}
