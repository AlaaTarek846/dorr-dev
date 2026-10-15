package com.dorr.driver.ui

import android.content.Context
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
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
import androidx.compose.material.icons.filled.ArrowDownward
import androidx.compose.material.icons.filled.ArrowUpward
import androidx.compose.material.icons.filled.FiberNew
import androidx.compose.material.icons.filled.Remove
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.core.ui.DorrBrand
import com.dorr.driver.R
import com.dorr.driver.net.ChangeDto
import com.dorr.driver.net.CurrencyDto
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.EarningsReportDto
import java.util.Locale
import kotlin.math.abs

private val PRESETS = listOf("3m" to R.string.earn_rp_3m, "6m" to R.string.earn_rp_6m, "9m" to R.string.earn_rp_9m, "12m" to R.string.earn_rp_12m, "custom" to R.string.earn_rp_custom)

/**
 * The earnings report (D-193..D-202): the last 3 / 6 / 9 / 12 months or two dates the captain picks, the
 * figures of that period, and each set against the period of the same length right before it — the
 * difference in money and in percent, said in words with an arrow, so it never rests on colour alone.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun EarningsReportCard(currency: CurrencyDto?) {
    val context = LocalContext.current
    var period by remember { mutableStateOf("3m") }
    var from by remember { mutableStateOf<String?>(null) }
    var to by remember { mutableStateOf<String?>(null) }
    var picking by remember { mutableStateOf<String?>(null) }
    var report by remember { mutableStateOf<EarningsReportDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var loading by remember { mutableStateOf(false) }

    // A preset loads when it is chosen; custom dates when both are picked.
    LaunchedEffect(period, from, to) {
        val custom = period == "custom"
        if (custom && (from == null || to == null)) return@LaunchedEffect
        loading = true
        when (val r = apiCall { DriverBackend.api.earningsReport(period, if (custom) from else null, if (custom) to else null) }) {
            is ApiResult.Ok -> { report = r.data; error = null }
            is ApiResult.Failed -> { report = null; error = r.error.displayMessage }
            is ApiResult.Offline -> { report = null; error = context.getString(R.string.error_offline) }
        }
        loading = false
    }

    Card {
        Text(stringResource(R.string.earn_report_title), fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(10.dp))
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            PRESETS.forEach { (key, label) -> PeriodChip(stringResource(label), period == key) { period = key } }
        }
        if (period == "custom") {
            Spacer(Modifier.height(10.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                DateChip(stringResource(R.string.earn_rp_from), from, Modifier.weight(1f)) { picking = "from" }
                DateChip(stringResource(R.string.earn_rp_to), to, Modifier.weight(1f)) { picking = "to" }
            }
        }
        Spacer(Modifier.height(14.dp))

        val r = report
        when {
            loading && r == null -> Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(24.dp), strokeWidth = 2.dp, color = DorrBrand.orange) }
            error != null -> Text(error.orEmpty(), color = DorrBrand.danger, fontSize = 13.sp)
            r != null -> ReportBody(r, currency ?: r.currency)
        }
    }

    picking?.let { which ->
        DatePickDialog(
            onPicked = { if (which == "from") from = it else to = it },
            onDismiss = { picking = null },
        )
    }
}

@Composable
private fun ReportBody(r: EarningsReportDto, currency: CurrencyDto?) {
    val context = LocalContext.current
    val empty = r.totals.netMinor == 0L && r.totals.trips == 0 && r.previous.totals.netMinor == 0L
    Text("${dateLabel(r.period.from)} – ${dateLabel(r.period.to)}", fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
    Text(money(r.totals.netMinor, currency), fontWeight = FontWeight.ExtraBold, fontSize = 28.sp)
    ChangeLine(r.change.net) { signed(it, currency) }
    Text(
        stringResource(R.string.earn_rp_vs, r.period.days, dateLabel(r.previous.from), dateLabel(r.previous.to)),
        fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.5f), modifier = Modifier.padding(top = 2.dp),
    )
    if (empty) {
        Text(stringResource(R.string.earn_rp_empty), fontSize = 13.sp, modifier = Modifier.padding(top = 12.dp))
        return
    }

    Spacer(Modifier.height(14.dp))
    Series(r)
    Spacer(Modifier.height(14.dp))
    StatRow(stringResource(R.string.earn_rp_gross), money(r.totals.grossMinor, currency), r.change.gross) { signed(it, currency) }
    StatRow(stringResource(R.string.earn_rp_trips), stringResource(R.string.earn_rp_trips_count, r.totals.trips), r.change.trips) { signedCount(it) }
    StatRow(stringResource(R.string.earn_rp_tips), money(r.totals.tipsMinor, currency), r.change.tips) { signed(it, currency) }
    StatRow(stringResource(R.string.earn_rp_online), hoursText(context, r.totals.onlineS), r.change.online) { (if (it < 0) "−" else "+") + hoursText(context, abs(it)) }
}

/** The period's bars, by month or by day; every slot has its place, empty ones stay short. */
@Composable
private fun Series(r: EarningsReportDto) {
    val points = r.series.points
    if (points.isEmpty()) return
    Text(stringResource(if (r.series.unit == "month") R.string.earn_rp_by_month else R.string.earn_rp_by_day), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
    Spacer(Modifier.height(6.dp))
    val max = (points.maxOfOrNull { it.netMinor } ?: 0).coerceAtLeast(1)
    Row(Modifier.fillMaxWidth().height(110.dp), horizontalArrangement = Arrangement.spacedBy(if (points.size > 20) 1.dp else 4.dp), verticalAlignment = Alignment.Bottom) {
        points.forEachIndexed { i, p ->
            val grow by animateFloatAsState(p.netMinor.toFloat() / max, tween(600), label = "report-bar")
            Column(Modifier.weight(1f).fillMaxHeight(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Bottom) {
                Box(Modifier.fillMaxWidth().fillMaxHeight(0.8f * grow.coerceAtLeast(0.03f)).clip(RoundedCornerShape(3.dp)).background(DorrBrand.orange))
                Text(if (points.size <= 12 || i % 5 == 0) p.key.takeLast(2) else "", fontSize = 9.sp, maxLines = 1)
            }
        }
    }
}

@Composable
private fun StatRow(label: String, value: String, change: ChangeDto, valueText: (Long) -> String) {
    Column(Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(label, fontSize = 13.sp, modifier = Modifier.weight(1f), color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f))
            Text(value, fontWeight = FontWeight.Bold, fontSize = 14.sp)
        }
        ChangeLine(change, valueText)
    }
}

/** An arrow, and the same thing in words — "Up 12.5% (+30.00 SAR)" — so colour is never the only signal. */
@Composable
private fun ChangeLine(change: ChangeDto, valueText: (Long) -> String) {
    val color = when (change.direction) { "up", "new" -> DorrBrand.success; "down" -> DorrBrand.danger; else -> MaterialTheme.colorScheme.onSurface.copy(alpha = 0.55f) }
    val icon = when (change.direction) { "up" -> Icons.Filled.ArrowUpward; "down" -> Icons.Filled.ArrowDownward; "new" -> Icons.Filled.FiberNew; else -> Icons.Filled.Remove }
    val pct = change.pctBp?.let { String.format(Locale.getDefault(), "%.1f%%", abs(it) / 100.0) }
    val text = when (change.direction) {
        "up" -> if (pct != null) stringResource(R.string.earn_rp_up, pct, valueText(change.value)) else stringResource(R.string.earn_rp_up_plain, valueText(change.value))
        "down" -> if (pct != null) stringResource(R.string.earn_rp_down, pct, valueText(change.value)) else stringResource(R.string.earn_rp_down_plain, valueText(change.value))
        "new" -> stringResource(R.string.earn_rp_new)
        else -> stringResource(R.string.earn_rp_same)
    }
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = color, modifier = Modifier.size(15.dp))
        Spacer(Modifier.width(4.dp))
        Text(text, color = color, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun PeriodChip(label: String, selected: Boolean, onClick: () -> Unit) {
    val bg by animateColorAsState(if (selected) DorrBrand.navy else DorrBrand.navy.copy(alpha = 0.07f), tween(200), label = "period-chip")
    Text(
        label, color = if (selected) Color.White else DorrBrand.navy, fontWeight = FontWeight.SemiBold, fontSize = 13.sp,
        modifier = Modifier.clip(RoundedCornerShape(50)).background(bg).clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 7.dp),
    )
}

@Composable
private fun DateChip(label: String, value: String?, modifier: Modifier, onClick: () -> Unit) {
    Column(modifier.clip(RoundedCornerShape(14.dp)).background(DorrBrand.navy.copy(alpha = 0.07f)).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 8.dp)) {
        Text(label, fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
        Text(value?.let { dateLabel(it) } ?: stringResource(R.string.earn_rp_pick), fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
    }
}

/** "+30.00 SAR" / "−12.50 SAR" — the sign is part of the text, not only the colour. */
private fun signed(minor: Long, currency: CurrencyDto?): String = (if (minor < 0) "−" else "+") + money(abs(minor), currency)

private fun signedCount(n: Long): String = (if (n < 0) "−" else "+") + abs(n)

private fun hoursText(context: Context, seconds: Long): String = context.getString(R.string.earn_hours, (seconds / 3600).toInt(), ((seconds % 3600) / 60).toInt())
