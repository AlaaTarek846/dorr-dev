package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Remove
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.R
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface
import java.math.BigDecimal
import java.math.RoundingMode
import java.time.Duration
import java.time.Instant
import java.time.OffsetDateTime
import java.util.Locale

/*
 * FL-L04: the shared pieces of the ready-made service screens — money in the currency's own decimals, the status pill, the numbered
 * step cards of the form, the − / + stepper and the segmented picker. Colours follow the appearance tokens, light and dark.
 */

internal val SERVICE_LEVELS = listOf("basic", "standard", "premium")

/** "1,250.00 SAR" — digits stay Latin and left-to-right in Arabic text too. */
internal fun svcMoney(minor: Long, decimals: Int, currency: String?): String {
    val text = String.format(Locale.US, "%,.${decimals}f", BigDecimal.valueOf(minor, decimals))
    return if (currency.isNullOrBlank()) text else "$text $currency"
}

/** Minor units → what goes in the price field ("250", "99.5"). */
internal fun minorToInput(minor: Long?, decimals: Int): String =
    if (minor == null || minor <= 0) "" else BigDecimal.valueOf(minor, decimals).stripTrailingZeros().toPlainString()

/** What was typed → minor units, or null when it is not a positive amount in the currency's decimals (never rounded quietly). */
internal fun inputToMinor(text: String, decimals: Int): Long? {
    val value = text.trim().replace(',', '.').replace('٫', '.').toBigDecimalOrNull() ?: return null
    if (value.signum() <= 0 || value.scale() > decimals) return null
    return runCatching { value.setScale(decimals, RoundingMode.UNNECESSARY).movePointRight(decimals).longValueExact() }.getOrNull()
}

private fun instantOf(iso: String?): Instant? = runCatching { Instant.parse(iso) }.getOrNull() ?: runCatching { OffsetDateTime.parse(iso).toInstant() }.getOrNull()

/** The time left until [iso] by the phone's clock (read when the screen draws — nothing is asked of the server on a timer). */
internal fun timeLeft(iso: String?): Duration? = instantOf(iso)?.let { Duration.between(Instant.now(), it) }

@Composable
internal fun levelLabel(level: String): String = when (level) {
    "standard" -> stringResource(R.string.svc_level_standard)
    "premium" -> stringResource(R.string.svc_level_premium)
    else -> stringResource(R.string.svc_level_basic)
}

@Composable
internal fun daysLabel(days: Int): String = if (days == 1) stringResource(R.string.svc_day_one) else stringResource(R.string.svc_days, days)

/** A tone: its ink and its soft fill. */
internal data class SvcTone(val ink: Color, val fill: Color)

@Composable
internal fun serviceTone(status: String): SvcTone {
    val c = when (status) {
        "published", "active", "completed" -> Color(0xFF16A34A)
        "pending_review", "awaiting" -> Color(0xFFD97706)
        "rejected", "declined" -> Color(0xFFDC2626)
        "suspended" -> if (settingsNight()) Color(0xFFCBD5E1) else Color(0xFF334155)
        else -> Color(0xFF64748B)
    }
    return SvcTone(c, c.copy(alpha = if (settingsNight()) 0.22f else 0.12f))
}

@Composable
internal fun serviceStatusLabel(status: String): String = when (status) {
    "pending_review" -> stringResource(R.string.svc_status_pending_review)
    "published" -> stringResource(R.string.svc_status_published)
    "paused" -> stringResource(R.string.svc_status_paused)
    "rejected" -> stringResource(R.string.svc_status_rejected)
    "suspended" -> stringResource(R.string.svc_status_suspended)
    else -> stringResource(R.string.svc_status_draft)
}

/** A small rounded label in the colour of what it says. */
@Composable
internal fun SvcPill(text: String, tone: SvcTone, modifier: Modifier = Modifier, solid: Boolean = false) {
    Row(
        modifier.clip(RoundedCornerShape(999.dp)).background(if (solid) tone.ink else tone.fill).padding(horizontal = 10.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(6.dp).clip(CircleShape).background(if (solid) Color.White else tone.ink))
        Box(Modifier.size(6.dp))
        Text(text, color = if (solid) Color.White else tone.ink, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

/** One part of the form: a numbered disc, its title and a line under it, then the fields. */
@Composable
internal fun StepCard(number: Int, title: String, subtitle: String, content: @Composable () -> Unit) {
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(22.dp)).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(Modifier.size(32.dp).clip(CircleShape).background(settingsAccent()), contentAlignment = Alignment.Center) {
                Text("$number", color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            }
            Column(Modifier.weight(1f)) {
                Text(title, color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                Text(subtitle, color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
            }
        }
        content()
    }
}

/** A white card without the numbered head (the details pages). */
@Composable
internal fun InfoCard(title: String? = null, icon: ImageVector? = null, content: @Composable () -> Unit) {
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        if (title != null) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (icon != null) Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                Text(title, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
        content()
    }
}

/** − value + for small whole numbers (days, revisions). */
@Composable
internal fun Stepper(label: String, value: Int, range: IntRange, enabled: Boolean = true, modifier: Modifier = Modifier, onChange: (Int) -> Unit) {
    val night = settingsNight()
    val line = if (night) AccountDark.line else Color(0xFFE5E7EB)
    Column(modifier, verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(label, color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        Row(
            Modifier.fillMaxWidth().height(44.dp).clip(RoundedCornerShape(14.dp)).border(1.dp, line, RoundedCornerShape(14.dp)),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            StepButton(Icons.Rounded.Remove, enabled && value > range.first) { onChange(value - 1) }
            Text("$value", color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, modifier = Modifier.weight(1f))
            StepButton(Icons.Rounded.Add, enabled && value < range.last) { onChange(value + 1) }
        }
    }
}

@Composable
private fun StepButton(icon: ImageVector, enabled: Boolean, onClick: () -> Unit) {
    Box(
        Modifier.size(44.dp).clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, contentDescription = null, tint = if (enabled) settingsAccent() else settingsMut().copy(alpha = 0.4f), modifier = Modifier.size(20.dp)) }
}

/** A segmented picker: one choice of a few, the chosen one filled. [marks] puts a dot on the choices that are on. */
@Composable
internal fun Segmented(options: List<Pair<String, String>>, selected: String, marks: Set<String> = emptySet(), onSelect: (String) -> Unit) {
    val night = settingsNight()
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(if (night) AccountDark.well else settingsAccent().copy(alpha = 0.08f)).padding(4.dp),
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        options.forEach { (value, label) ->
            val on = value == selected
            Row(
                Modifier.weight(1f).height(38.dp).clip(RoundedCornerShape(12.dp))
                    .background(if (on) settingsAccent() else Color.Transparent)
                    .clickable { onSelect(value) },
                horizontalArrangement = Arrangement.Center,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(label, color = if (on) Color.White else settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                if (value in marks) {
                    Box(Modifier.padding(start = 6.dp).size(6.dp).clip(CircleShape).background(if (on) Color.White else Color(0xFF16A34A)))
                }
            }
        }
    }
}

/** A row in a money card: the label at the start, the amount at the end (left-to-right digits). */
@Composable
internal fun MoneyLine(label: String, amount: String, strong: Boolean = false, muted: Boolean = false) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = if (muted) settingsMut() else settingsInk(), fontSize = if (strong) 15.sp else 13.sp, fontWeight = if (strong) FontWeight.ExtraBold else FontWeight.Medium, modifier = Modifier.weight(1f))
        androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text(amount, color = if (strong) settingsAccent() else if (muted) settingsMut() else settingsInk(), fontSize = if (strong) 17.sp else 13.sp, fontWeight = if (strong) FontWeight.ExtraBold else FontWeight.SemiBold)
        }
    }
}
