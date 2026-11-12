package com.dorr.app.ui.screens.marketing

import androidx.annotation.StringRes
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiFailure
import com.dorr.app.network.AuthSession
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaIconWell
import com.dorr.app.ui.screens.wallet.rememberPressScale
import com.google.gson.JsonElement
import com.google.gson.JsonObject
import java.math.BigDecimal
import java.text.DecimalFormat
import java.text.DecimalFormatSymbols
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale
import kotlin.coroutines.cancellation.CancellationException

internal fun mkAuth(): String = "Bearer ${AuthSession.token.orEmpty()}"

/**
 * Turns a failed call into what the person sees: the server's message as a red toast; with no package the packages
 * page opens (402 `marketing_package_required`). Returns the failure for pages that branch on it.
 */
internal fun MkHost.fail(e: Throwable, fallback: String): ApiFailure {
    if (e is CancellationException) throw e
    val failure = e.apiFailure()
    showToast(failure.message ?: fallback, error = true)
    if (failure.errorCode == "marketing_package_required" && current != MkRoute.Packages) push(MkRoute.Packages)
    return failure
}

/** Minor units → "1,234.50 SAR". BigDecimal only: no float touches money. */
internal fun mkMoney(minor: Long?, currency: String, decimals: Int = 2): String {
    if (minor == null) return "—"
    val pattern = if (decimals == 0) "#,##0" else "#,##0." + "0".repeat(decimals)
    val amount = DecimalFormat(pattern, DecimalFormatSymbols(Locale.US)).format(BigDecimal(minor).movePointLeft(decimals))
    return if (currency.isBlank()) amount else "$amount $currency"
}

/** "1,234.5" typed by a person → minor units; null when it isn't a number. */
internal fun mkParseMinor(text: String, decimals: Int = 2): Long? {
    val clean = text.trim().replace(",", "").replace('٫', '.')
        .map { ch -> if (ch in '٠'..'٩') ('0' + (ch - '٠')) else ch }.joinToString("")
    if (clean.isEmpty()) return null
    return runCatching { BigDecimal(clean).movePointRight(decimals).setScale(0, java.math.RoundingMode.HALF_UP).longValueExact() }
        .getOrNull()?.takeIf { it >= 0 }
}

internal fun mkDate(iso: String?): String {
    if (iso.isNullOrBlank()) return ""
    return runCatching {
        OffsetDateTime.parse(iso).format(DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(Locale.getDefault()))
    }.getOrElse { iso.take(10) }
}

@StringRes
internal fun mkStatusLabel(status: String): Int = when (status) {
    "QUEUED" -> R.string.mktg_status_QUEUED
    "RUNNING" -> R.string.mktg_status_RUNNING
    "EVIDENCE_CHECK" -> R.string.mktg_status_EVIDENCE_CHECK
    "COMPLETED" -> R.string.mktg_status_COMPLETED
    "PARTIAL" -> R.string.mktg_status_PARTIAL
    "FAILED" -> R.string.mktg_status_FAILED
    "CANCELLED" -> R.string.mktg_status_CANCELLED
    "READY_FOR_REVIEW" -> R.string.mktg_status_READY_FOR_REVIEW
    "APPROVED" -> R.string.mktg_status_APPROVED
    "SUPERSEDED" -> R.string.mktg_status_SUPERSEDED
    "ARCHIVED" -> R.string.mktg_status_ARCHIVED
    else -> R.string.mktg_status_DRAFT
}

internal fun mkStatusColor(status: String): Color = when (status) {
    "COMPLETED", "APPROVED" -> Color(0xFF16A34A)
    "PARTIAL", "READY_FOR_REVIEW" -> Color(0xFFD97706)
    "FAILED", "CANCELLED" -> Color(0xFFDC2626)
    "QUEUED", "RUNNING", "EVIDENCE_CHECK" -> Color(0xFF2563EB)
    else -> Color(0xFF6B7280)
}

@StringRes
internal fun mkPlanLabel(type: String): Int = when (type) {
    "business" -> R.string.mktg_plan_business
    "marketing" -> R.string.mktg_plan_marketing
    else -> R.string.mktg_plan_gtm
}

@StringRes
internal fun mkKindLabel(kind: String): Int = when (kind) {
    "competitor" -> R.string.mktg_research_competitor
    "audience" -> R.string.mktg_research_audience
    else -> R.string.mktg_research_market
}

@StringRes
internal fun mkRoleLabel(role: String?): Int = when (role) {
    "owner" -> R.string.mktg_role_owner
    "manager" -> R.string.mktg_role_manager
    "analyst" -> R.string.mktg_role_analyst
    else -> R.string.mktg_role_viewer
}

@StringRes
internal fun mkStageLabel(stage: String): Int = when (stage) {
    "launch" -> R.string.mktg_stage_launch
    "growth" -> R.string.mktg_stage_growth
    else -> R.string.mktg_stage_idea
}

/** A small rounded status pill with a dot. */
@Composable
internal fun MkBadge(text: String, color: Color, modifier: Modifier = Modifier) {
    Row(
        modifier.clip(RoundedCornerShape(99.dp)).background(color.copy(alpha = 0.13f)).padding(horizontal = 9.dp, vertical = 3.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(6.dp).clip(RoundedCornerShape(99.dp)).background(color))
        Spacer(Modifier.width(5.dp))
        Text(text, color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

@Composable
internal fun MkStatusBadge(status: String, modifier: Modifier = Modifier) {
    MkBadge(stringResource(mkStatusLabel(status)), mkStatusColor(status), modifier)
}

/** A thin rounded progress bar (job progress, evidence quality). */
@Composable
internal fun MkBar(fraction: Float, color: Color, modifier: Modifier = Modifier) {
    val shown by animateFloatAsState(fraction.coerceIn(0f, 1f), tween(500), label = "mkBar")
    Box(modifier.fillMaxWidth().height(6.dp).clip(RoundedCornerShape(99.dp)).background(Wa.Line)) {
        Box(Modifier.fillMaxWidth(shown).fillMaxHeight().clip(RoundedCornerShape(99.dp)).background(color))
    }
}

/** A tappable card row: icon well, title, subtitle, trailing content, and the reading-direction chevron. */
@Composable
internal fun MkNavCard(
    icon: ImageVector,
    tone: Tone,
    title: String,
    subtitle: String?,
    modifier: Modifier = Modifier,
    trailing: (@Composable () -> Unit)? = null,
    onClick: () -> Unit,
) {
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.97f)
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    WaCard(modifier.fillMaxWidth().scale(press).clickable(interactionSource = source, indication = null, onClick = onClick), padding = 14.dp) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            WaIconWell(icon, tone, size = 42.dp, iconSize = 21.dp)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(title, color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                if (!subtitle.isNullOrBlank()) {
                    Text(subtitle, color = Wa.Mut, fontSize = 12.5.sp, lineHeight = 18.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
                }
            }
            if (trailing != null) {
                Spacer(Modifier.width(8.dp))
                trailing()
            }
            Icon(
                if (rtl) Icons.AutoMirrored.Rounded.KeyboardArrowLeft else Icons.AutoMirrored.Rounded.KeyboardArrowRight,
                null, tint = Wa.Soft, modifier = Modifier.size(22.dp),
            )
        }
    }
}

/** A titled block inside a card. */
@Composable
internal fun MkBlock(title: String, modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    WaCard(modifier.fillMaxWidth(), padding = 16.dp) {
        Text(title, color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold)
        Spacer(Modifier.height(10.dp))
        Column(verticalArrangement = Arrangement.spacedBy(8.dp), content = content)
    }
}

/** "• text" lines. */
@Composable
internal fun MkBullets(items: List<String>, color: Color = Wa.Ink) {
    items.forEach { item ->
        Row {
            Text("•", color = Wa.Red, fontSize = 14.sp, fontWeight = FontWeight.Black)
            Spacer(Modifier.width(8.dp))
            Text(item, color = color, fontSize = 13.5.sp, lineHeight = 21.sp, modifier = Modifier.weight(1f))
        }
    }
}

// ------------------------------------------------------------------------------- plan JSON

internal fun JsonObject.str(key: String): String? = get(key)?.takeIf { it.isJsonPrimitive }?.asString?.takeIf { it.isNotBlank() }

internal fun JsonObject.int(key: String): Int? = get(key)?.takeIf { it.isJsonPrimitive && it.asJsonPrimitive.isNumber }?.asInt

internal fun JsonObject.strings(key: String): List<String> =
    get(key)?.takeIf { it.isJsonArray }?.asJsonArray?.mapNotNull { it.takeIf { e -> e.isJsonPrimitive }?.asString }.orEmpty()

internal fun JsonObject.objects(key: String): List<JsonObject> =
    get(key)?.takeIf { it.isJsonArray }?.asJsonArray?.mapNotNull { it.takeIf { e -> e.isJsonObject }?.asJsonObject }.orEmpty()

/** A discovery answer as the text field shows it (lists one per line). */
internal fun JsonElement?.answerText(type: String, currency: String): String = when {
    this == null || isJsonNull -> ""
    isJsonArray -> asJsonArray.mapNotNull { it.takeIf { e -> e.isJsonPrimitive }?.asString }.joinToString("\n")
    type == "money" && isJsonPrimitive -> mkMoney(asLong, "").replace(",", "")
    isJsonPrimitive -> asString
    else -> ""
}

@Composable
internal fun MkSmallTag(text: String, tint: Color) {
    Text(
        text,
        color = tint,
        fontSize = 10.5.sp,
        fontWeight = FontWeight.Bold,
        modifier = Modifier.clip(RoundedCornerShape(8.dp)).background(tint.copy(alpha = 0.12f)).padding(horizontal = 7.dp, vertical = 2.dp),
    )
}
