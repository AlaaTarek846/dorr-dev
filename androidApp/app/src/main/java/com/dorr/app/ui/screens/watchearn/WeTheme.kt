package com.dorr.app.ui.screens.watchearn

import androidx.annotation.StringRes
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.MutableTransitionState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
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
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.PathMeasure
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalInspectionMode
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AppLocale
import com.dorr.app.network.AuthSession
import com.dorr.app.network.WeBucketsDto
import com.dorr.app.network.WeCurrencyDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.rememberPressScale
import com.dorr.app.ui.screens.wallet.walletNight
import java.math.BigDecimal
import java.math.RoundingMode
import java.text.DecimalFormat
import java.text.DecimalFormatSymbols
import java.time.Instant
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale

/**
 * Watch & Earn's look, on top of the wallet's (WaTheme): the same cards, buttons and page frame, plus the three
 * bucket colours used on every page (rewards / not withdrawable / withdrawable), status badges, chips that animate
 * in and out, numbers that count up, and money in minor units with the currency's own decimals.
 */
object We {
    /** Rewards bucket (Watch & Earn earnings) — amber everywhere. */
    val Reward = Color(0xFFD97706)
    /** Not withdrawable (spend-only) bucket — blue everywhere. */
    val SpendOnly = Color(0xFF2563EB)
    /** Withdrawable bucket — green everywhere. */
    val Withdrawable = Color(0xFF16A34A)
    val Interaction = Color(0xFF7C3AED)
    val Gold = Color(0xFFF59E0B)

    /** The two series in the daily chart and the donut slices. */
    val Series = listOf(Color(0xFF2563EB), Color(0xFF7C3AED), Color(0xFFDB2777), Color(0xFF16A34A), Color(0xFFD97706), Color(0xFF6B7280))

    val RewardBrush: Brush get() = Brush.linearGradient(listOf(Color(0xFFF59E0B), Color(0xFFD97706), Color(0xFFB45309)))
}

/** Bearer header for the Watch & Earn calls. */
internal fun weAuth(): String = "Bearer ${AuthSession.token.orEmpty()}"

/** The server's own message for a failed call (already in the app's language), or [fallback]. */
internal fun Throwable.weMessage(fallback: String): String = apiFailure().message ?: fallback

// ------------------------------------------------------------------------------- buckets

/** The three wallet buckets, always in this order, with the same label and colour on every page. */
enum class WeBucket(val color: Color, @StringRes val label: Int) {
    Reward(We.Reward, R.string.we_bucket_reward),
    SpendOnly(We.SpendOnly, R.string.we_bucket_spend_only),
    Withdrawable(We.Withdrawable, R.string.we_bucket_withdrawable);

    fun of(b: WeBucketsDto): Long = when (this) {
        Reward -> b.rewardMinor
        SpendOnly -> b.spendOnlyMinor
        Withdrawable -> b.withdrawableMinor
    }
}

// ------------------------------------------------------------------------------- money

/** Minor units → "1,234.50" with the currency's decimals. BigDecimal only: no float ever touches money. */
fun weAmount(minor: Long, decimals: Int): String {
    val d = decimals.coerceIn(0, 4)
    val pattern = if (d == 0) "#,##0" else "#,##0." + "0".repeat(d)
    return DecimalFormat(pattern, DecimalFormatSymbols(Locale.US)).format(BigDecimal(minor).movePointLeft(d))
}

/** "1,234.50 SAR". */
fun weMoney(minor: Long, currency: WeCurrencyDto?): String {
    val text = weAmount(minor, currency?.decimals ?: 2)
    val code = currency?.code
    return if (code.isNullOrBlank()) text else "$text $code"
}

/** What was typed → minor units, or null when it isn't a positive amount with at most [decimals] decimals. */
fun weParseAmount(text: String, decimals: Int): Long? {
    val value = text.trim().replace(',', '.').toBigDecimalOrNull() ?: return null
    val d = decimals.coerceIn(0, 4)
    if (value.signum() <= 0 || value.scale() > d) return null
    return runCatching { value.setScale(d, RoundingMode.UNNECESSARY).movePointRight(d).longValueExact() }.getOrNull()
}

/** Minor units → the text a field starts with ("150.00" → "150"). */
fun weAmountInput(minor: Long, decimals: Int): String =
    BigDecimal(minor).movePointLeft(decimals.coerceIn(0, 4)).stripTrailingZeros().toPlainString()

// ------------------------------------------------------------------------------- dates

internal fun weInstant(iso: String?): Instant? {
    if (iso.isNullOrBlank()) return null
    return runCatching { Instant.parse(iso) }.getOrNull() ?: runCatching { OffsetDateTime.parse(iso).toInstant() }.getOrNull()
}

/** "2 Nov 2026" / "٢ نوفمبر ٢٠٢٦" in the app's language. */
internal fun weDate(iso: String?): String {
    val day = weInstant(iso)?.atZone(ZoneId.systemDefault())?.toLocalDate() ?: return ""
    return weDate(day)
}

internal fun weDate(day: LocalDate): String = DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(AppLocale.java()).format(day)

/** "2 Nov, 14:05". */
internal fun weDateTime(iso: String?): String {
    val at = weInstant(iso)?.atZone(ZoneId.systemDefault()) ?: return ""
    return DateTimeFormatter.ofLocalizedDateTime(FormatStyle.MEDIUM, FormatStyle.SHORT).withLocale(AppLocale.java()).format(at)
}

/** A day for the server: its start, or its very end (an end date counts the whole day). */
internal fun weServerDate(day: LocalDate, endOfDay: Boolean = false): String =
    day.toString() + if (endOfDay) " 23:59:59" else " 00:00:00"

/** "mm:ss" left until [untilMs] (device clock). */
internal fun weCountdown(leftMs: Long): String {
    val s = (leftMs / 1000).coerceAtLeast(0)
    return "%02d:%02d".format(Locale.US, s / 60, s % 60)
}

// ------------------------------------------------------------------------------- building blocks

/** Numbers and amounts always read left to right, even in an Arabic layout. */
@Composable
fun WeLtr(content: @Composable () -> Unit) {
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr, content = content)
}

/** A number that counts up from where it was (from zero the first time) whenever it appears or changes. */
@Composable
fun WeCountUp(
    target: Long,
    format: (Long) -> String,
    modifier: Modifier = Modifier,
    color: Color = Wa.Ink,
    fontSize: TextUnit = 22.sp,
    fontWeight: FontWeight = FontWeight.ExtraBold,
    durationMs: Int = 900,
) {
    val inspecting = LocalInspectionMode.current
    val value = remember { Animatable(if (inspecting) target.toFloat() else 0f) }
    // A Float can't hold every large amount exactly: once the count is over, the exact number is shown.
    var counting by remember { mutableStateOf(!inspecting) }
    LaunchedEffect(target) {
        counting = true
        value.animateTo(target.toFloat(), tween(durationMs, easing = FastOutSlowInEasing))
        counting = false
    }
    WeLtr {
        Text(format(if (counting) value.value.toLong() else target), modifier = modifier, color = color, fontSize = fontSize, fontWeight = fontWeight, maxLines = 1)
    }
}

/** A small round dot in a bucket's colour. */
@Composable
fun WeDot(color: Color, size: Dp = 9.dp) {
    Box(Modifier.size(size).clip(CircleShape).background(color))
}

/**
 * An amount split by bucket: a stacked bar (its parts grow in) and one row per bucket — the same three colours
 * and labels on every page. [hideZero] leaves out the empty buckets.
 */
@Composable
fun WeBucketSplit(buckets: WeBucketsDto, currency: WeCurrencyDto?, modifier: Modifier = Modifier, hideZero: Boolean = false, bar: Boolean = true) {
    val total = buckets.totalMinor
    Column(modifier) {
        if (bar && total > 0) {
            val grow = remember { Animatable(0f) }
            LaunchedEffect(total) { grow.snapTo(0f); grow.animateTo(1f, tween(800, easing = FastOutSlowInEasing)) }
            Row(Modifier.fillMaxWidth().height(10.dp).clip(RoundedCornerShape(99.dp)).background(Wa.Line)) {
                WeBucket.entries.forEach { b ->
                    val part = b.of(buckets).toFloat() / total
                    if (part > 0f) Box(Modifier.weight(part * grow.value + 0.0001f).fillMaxHeight().background(b.color))
                }
                if (grow.value < 1f) Spacer(Modifier.weight(1f - grow.value + 0.0001f))
            }
            Spacer(Modifier.height(10.dp))
        }
        WeBucket.entries.forEach { b ->
            val amount = b.of(buckets)
            if (hideZero && amount == 0L) return@forEach
            Row(Modifier.fillMaxWidth().padding(vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
                WeDot(b.color)
                Spacer(Modifier.width(8.dp))
                Text(stringResource(b.label), color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.weight(1f))
                WeLtr { Text(weMoney(amount, currency), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold) }
            }
        }
    }
}

/** Bucket chips in a row ("● 1.00  ● 2.00"), for statement lines. */
@Composable
fun WeBucketChips(buckets: WeBucketsDto, currency: WeCurrencyDto?, modifier: Modifier = Modifier) {
    Row(modifier, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        WeBucket.entries.forEach { b ->
            val amount = b.of(buckets)
            if (amount == 0L) return@forEach
            Row(
                Modifier.clip(RoundedCornerShape(99.dp)).background(b.color.copy(alpha = 0.12f)).padding(horizontal = 8.dp, vertical = 3.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                WeDot(b.color, 6.dp)
                Spacer(Modifier.width(4.dp))
                WeLtr { Text(weAmount(amount, currency?.decimals ?: 2), color = b.color, fontSize = 10.5.sp, fontWeight = FontWeight.Bold) }
            }
        }
    }
}

/** A choice chip: the brand colour when picked, a soft outline when not; colours and size change smoothly. */
@Composable
fun WeChip(text: String, selected: Boolean, onClick: () -> Unit, modifier: Modifier = Modifier, icon: ImageVector? = null) {
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.94f)
    val bg by animateColorAsState(if (selected) Wa.Red else Wa.Surface, tween(220), label = "chipBg")
    val fg by animateColorAsState(if (selected) Color.White else Wa.Ink, tween(220), label = "chipFg")
    val shape = RoundedCornerShape(99.dp)
    Row(
        modifier
            .scale(press)
            .clip(shape)
            .background(bg)
            .border(1.dp, if (selected) Color.Transparent else Wa.Line, shape)
            .clickable(interactionSource = source, indication = null, onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (icon != null) {
            Icon(icon, null, tint = fg, modifier = Modifier.size(15.dp))
            Spacer(Modifier.width(6.dp))
        }
        Text(text, color = fg, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

/**
 * A picked value with a ×: it pops in when added, and shrinks away before [onRemoved] takes it out of the list
 * (the city chips of the wizard).
 */
@Composable
fun WeRemovableChip(text: String, onRemoved: () -> Unit) {
    val state = remember { MutableTransitionState(false).apply { targetState = true } }
    LaunchedEffect(state.currentState, state.isIdle) {
        if (state.isIdle && !state.currentState && !state.targetState) onRemoved()
    }
    AnimatedVisibility(
        visibleState = state,
        enter = scaleIn(spring(dampingRatio = 0.5f, stiffness = 500f)) + fadeIn(),
        exit = scaleOut(tween(180)) + fadeOut(tween(180)),
    ) {
        Row(
            Modifier
                .padding(end = 6.dp, bottom = 6.dp)
                .clip(RoundedCornerShape(99.dp))
                .background(Wa.Red.copy(alpha = 0.12f))
                .border(1.dp, Wa.Red.copy(alpha = 0.3f), RoundedCornerShape(99.dp))
                .padding(start = 12.dp, end = 6.dp, top = 5.dp, bottom = 5.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(text, color = Wa.Red, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Spacer(Modifier.width(4.dp))
            Box(
                Modifier.size(20.dp).clip(CircleShape).background(Wa.Red.copy(alpha = 0.16f)).clickable { state.targetState = false },
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.Close, stringResource(R.string.we_remove), tint = Wa.Red, modifier = Modifier.size(13.dp)) }
        }
    }
}

// ------------------------------------------------------------------------------- statuses

@StringRes
internal fun weStatusLabel(status: String, scheduled: Boolean = false): Int = when {
    status == "active" && scheduled -> R.string.we_status_scheduled
    status == "draft" -> R.string.we_status_draft
    status == "pending_review" -> R.string.we_status_pending_review
    status == "rejected" -> R.string.we_status_rejected
    status == "active" -> R.string.we_status_active
    status == "paused" -> R.string.we_status_paused
    status == "exhausted" -> R.string.we_status_exhausted
    status == "suspended" -> R.string.we_status_suspended
    status == "ended" -> R.string.we_status_ended
    status == "stopped" -> R.string.we_status_stopped
    else -> R.string.we_status_unknown
}

internal fun weStatusColor(status: String, scheduled: Boolean = false): Color = when {
    status == "active" && scheduled -> Color(0xFF2563EB)
    status == "active" -> Color(0xFF16A34A)
    status == "pending_review" -> Color(0xFFD97706)
    status == "paused" -> Color(0xFF6B7280)
    status == "exhausted" -> Color(0xFFEA580C)
    status == "rejected" || status == "suspended" -> Color(0xFFDC2626)
    else -> Color(0xFF6B7280)
}

/** The ad's status as a tinted pill with a dot. */
@Composable
fun WeStatusBadge(status: String, scheduled: Boolean = false, modifier: Modifier = Modifier) {
    val color = weStatusColor(status, scheduled)
    Row(
        modifier.clip(RoundedCornerShape(99.dp)).background(color.copy(alpha = 0.13f)).padding(horizontal = 9.dp, vertical = 3.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        WeDot(color, 6.dp)
        Spacer(Modifier.width(5.dp))
        Text(stringResource(weStatusLabel(status, scheduled)), color = color, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
    }
}

// ------------------------------------------------------------------------------- cards

/** A number card that counts up when it appears: icon, value, label. */
@Composable
fun WeNumberCard(label: String, value: Long, icon: ImageVector, modifier: Modifier = Modifier, suffix: String = "", tint: Color = Wa.Red) {
    WaCard(modifier, padding = 14.dp) {
        Box(Modifier.size(32.dp).clip(RoundedCornerShape(10.dp)).background(tint.copy(alpha = 0.13f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = tint, modifier = Modifier.size(17.dp))
        }
        Spacer(Modifier.height(10.dp))
        WeCountUp(value, { it.toString() + suffix }, fontSize = 20.sp)
        Text(label, color = Wa.Mut, fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
    }
}

/** A tile on the landing page (my rewards / my ads / the ad wallet). */
@Composable
fun WeTile(icon: ImageVector, title: String, subtitle: String?, tint: Color, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.95f)
    WaCard(modifier.scale(press).clickable(interactionSource = source, indication = null, onClick = onClick), padding = 14.dp) {
        Box(Modifier.size(40.dp).clip(RoundedCornerShape(13.dp)).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = tint, modifier = Modifier.size(21.dp))
        }
        Spacer(Modifier.height(10.dp))
        Text(title, color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
        if (!subtitle.isNullOrBlank()) Text(subtitle, color = Wa.Mut, fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/** A thin rounded progress bar that fills in when it appears. */
@Composable
fun WeProgressBar(fraction: Float, color: Color = Wa.Red, modifier: Modifier = Modifier, height: Dp = 7.dp) {
    val shown by animateFloatAsState(fraction.coerceIn(0f, 1f), tween(900, easing = FastOutSlowInEasing), label = "bar")
    Box(modifier.fillMaxWidth().height(height).clip(RoundedCornerShape(99.dp)).background(Wa.Line)) {
        Box(Modifier.fillMaxHeight().fillMaxWidth(shown).clip(RoundedCornerShape(99.dp)).background(color))
    }
}

/** The success mark: a circle draws itself, then the check, with a little bounce. */
@Composable
fun WeSuccessCheck(modifier: Modifier = Modifier, size: Dp = 110.dp, color: Color = We.Withdrawable, onDone: () -> Unit = {}) {
    val ring = remember { Animatable(0f) }
    val tick = remember { Animatable(0f) }
    val pop = remember { Animatable(0.6f) }
    LaunchedEffect(Unit) {
        ring.animateTo(1f, tween(520, easing = FastOutSlowInEasing))
        tick.animateTo(1f, tween(380, easing = FastOutSlowInEasing))
        pop.animateTo(1f, spring(dampingRatio = 0.4f, stiffness = 420f))
        onDone()
    }
    Canvas(modifier.size(size).scale(0.85f + 0.15f * pop.value)) {
        val stroke = this.size.minDimension * 0.07f
        drawCircle(color.copy(alpha = 0.12f * ring.value), radius = this.size.minDimension / 2f)
        drawArc(
            color = color,
            startAngle = -90f,
            sweepAngle = 360f * ring.value,
            useCenter = false,
            topLeft = Offset(stroke / 2, stroke / 2),
            size = androidx.compose.ui.geometry.Size(this.size.width - stroke, this.size.height - stroke),
            style = Stroke(stroke, cap = StrokeCap.Round),
        )
        if (tick.value > 0f) {
            val w = this.size.width
            val h = this.size.height
            val path = Path().apply {
                moveTo(w * 0.28f, h * 0.52f)
                lineTo(w * 0.44f, h * 0.67f)
                lineTo(w * 0.73f, h * 0.36f)
            }
            val measure = PathMeasure().apply { setPath(path, false) }
            val part = Path()
            measure.getSegment(0f, measure.length * tick.value, part, true)
            drawPath(part, color, style = Stroke(stroke * 1.1f, cap = StrokeCap.Round, join = StrokeJoin.Round))
        }
    }
}

/** Night-aware soft well behind icons, like the wallet's. */
@Composable
internal fun weWell(tint: Color): Color = if (walletNight()) com.dorr.app.ui.screens.AccountDark.well else tint.copy(alpha = 0.13f)
