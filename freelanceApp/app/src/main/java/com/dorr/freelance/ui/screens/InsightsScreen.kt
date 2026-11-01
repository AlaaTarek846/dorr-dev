package com.dorr.freelance.ui.screens

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
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
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.automirrored.rounded.TrendingUp
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Insights
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.ArrowDownward
import androidx.compose.material.icons.rounded.ArrowUpward
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.coerceAtLeast
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.InsightEarningsDto
import com.dorr.freelance.net.InsightOffersDto
import com.dorr.freelance.net.InsightReputationDto
import com.dorr.freelance.net.InsightServicesDto
import com.dorr.freelance.net.InsightSpecialtyDto
import com.dorr.freelance.net.InsightViewsDto
import com.dorr.freelance.net.InsightsDataDto
import com.dorr.freelance.net.InsightsDto
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.SkeletonDetail
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale

/**
 * FL-L01, phase 4: «My performance». One request gives everything for the days chosen (30 / 90 / a year, as far as my plan looks back):
 * what I earned, the funnel of my offers (sent → seen → shortlisted → accepted), the orders on my ready-made services, my name with
 * clients and — on the full level — the earnings over time and the specialties I do best in. A plan without it sees what it opens and
 * the button to its plan, not an error. Read when the page opens, when the days change, when the connection returns and when a notice
 * arrives — never on a timer.
 */
@Composable
fun InsightsScreen(onBack: () -> Unit, onOpenPlans: () -> Unit) {
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var days by rememberSaveable { mutableStateOf<Int?>(null) }
    var insights by remember { mutableStateOf<InsightsDto?>(null) }
    var failure by remember { mutableStateOf<String?>(null) }

    suspend fun load() {
        when (val result = apiCall { ApiClient.insights.insights(days) }) {
            is ApiResult.Ok -> {
                insights = result.data
                failure = null
            }
            else -> if (insights == null) failure = result.errorText(offlineText) ?: genericError
        }
    }

    LaunchedEffect(days, reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }

    Page(title = stringResource(R.string.insights_title), onBack = onBack) {
        val dto = insights
        when {
            dto == null && failure != null -> EmptyState(Icons.Rounded.Insights, stringResource(R.string.insights_title), failure.orEmpty(), Modifier.padding(top = 70.dp))
            dto == null -> SkeletonDetail()
            dto.locked || dto.data == null -> LockedInsights(dto, onOpenPlans)
            else -> InsightsBody(dto, dto.data, days ?: dto.days, { days = it }, onOpenPlans)
        }
        ErrorBanner(if (insights != null) failure else null, Modifier.fillMaxWidth())
    }
}

/** A plan without the numbers: what they are, and the plan that opens them (or that plans are not sold here). */
@Composable
private fun LockedInsights(dto: InsightsDto, onOpenPlans: () -> Unit) {
    InfoCard {
        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(Modifier.size(64.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Lock, null, tint = settingsAccent(), modifier = Modifier.size(30.dp))
            }
            Text(stringResource(R.string.insights_locked_title), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
            Text(stringResource(R.string.insights_locked_body), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp, textAlign = TextAlign.Center)
        }
        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf(R.string.insights_locked_b1, R.string.insights_locked_b2, R.string.insights_locked_b3).forEach { Perk(stringResource(it)) }
        }
        val plan = dto.upgrade
        if (plan != null) {
            SettingsPrimaryButton(text = stringResource(R.string.insights_unlock, plan.name ?: plan.slug), onClick = onOpenPlans)
        } else {
            Text(stringResource(R.string.insights_no_plans), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth())
        }
    }
}

@Composable
private fun Perk(text: String) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Icon(Icons.Rounded.CheckCircle, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        Text(text, color = settingsInk(), fontSize = 13.sp)
    }
}

@Composable
private fun InsightsBody(dto: InsightsDto, data: InsightsDataDto, chosen: Int?, onDays: (Int) -> Unit, onOpenPlans: () -> Unit) {
    val period = dto.days ?: chosen ?: dto.allowedDays.firstOrNull() ?: 30
    if (dto.allowedDays.size > 1) {
        Segmented(dto.allowedDays.map { it.toString() to periodLabel(it) }, period.toString()) { onDays(it.toInt()) }
    }

    Hero(data, dto, period)

    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        StatTile(Icons.AutoMirrored.Rounded.Send, data.offers.sent.toString(), stringResource(R.string.insights_stat_sent), Modifier.weight(1f))
        StatTile(Icons.AutoMirrored.Rounded.TrendingUp, percent(data.offers.acceptRate), stringResource(R.string.insights_stat_accept), Modifier.weight(1f))
    }
    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        StatTile(Icons.Rounded.Storefront, data.services.received.toString(), stringResource(R.string.insights_services_received), Modifier.weight(1f))
        StatTile(
            Icons.Rounded.Star,
            data.reputation.rating?.let { String.format(Locale.US, "%.1f", it) } ?: stringResource(R.string.insights_rating_new),
            stringResource(R.string.insights_stat_rating), Modifier.weight(1f),
        )
    }

    ViewsCard(data.views, period)
    OffersCard(data.offers)
    ServicesCard(data.services)
    EarningsCard(data.earnings, dto)
    ReputationCard(data.reputation)
    data.specialties?.takeIf { it.isNotEmpty() }?.let { SpecialtiesCard(it) }

    // The basic level sees where the rest is.
    val next = dto.upgrade
    if (dto.level < 2 && next != null) {
        InfoCard(title = stringResource(R.string.insights_more_title), icon = Icons.Rounded.Insights) {
            Text(stringResource(R.string.insights_more_body, next.name ?: next.slug), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp)
            SettingsPrimaryButton(text = stringResource(R.string.insights_unlock, next.name ?: next.slug), onClick = onOpenPlans)
        }
    }
}

@Composable
private fun periodLabel(days: Int): String = when (days) {
    30 -> stringResource(R.string.insights_period_30)
    90 -> stringResource(R.string.insights_period_90)
    365 -> stringResource(R.string.insights_period_365)
    else -> stringResource(R.string.insights_period_other, days)
}

/** What was earned, large, on the accent colour — the one number most freelancers open this page for. */
@Composable
private fun Hero(data: InsightsDataDto, dto: InsightsDto, period: Int) {
    val accent = settingsAccent()
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).clip(RoundedCornerShape(24.dp))
            .background(Brush.linearGradient(listOf(accent, accent.copy(alpha = 0.72f)))).padding(20.dp),
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Text(stringResource(R.string.insights_summary, period), color = Color.White.copy(alpha = 0.85f), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        Text(stringResource(R.string.insights_stat_earned), color = Color.White.copy(alpha = 0.85f), fontSize = 13.sp)
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text(svcMoney(data.earnings.earnedMinor, dto.decimals, dto.currency), color = Color.White, fontSize = 30.sp, fontWeight = FontWeight.ExtraBold)
        }
        if (data.reputation.completedInPeriod > 0) {
            Text(stringResource(R.string.insights_rep_in_period) + " · " + data.reputation.completedInPeriod, color = Color.White.copy(alpha = 0.85f), fontSize = 12.sp)
        }
    }
}

@Composable
private fun StatTile(icon: ImageVector, value: String, label: String, modifier: Modifier = Modifier) {
    Column(modifier.settingsSurface(RoundedCornerShape(20.dp)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Box(Modifier.size(34.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        }
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text(value, color = settingsInk(), fontSize = 24.sp, fontWeight = FontWeight.ExtraBold)
        }
        Text(label, color = settingsMut(), fontSize = 12.sp, lineHeight = 16.sp)
    }
}

private fun percent(value: Int?): String = if (value == null) "—" else "$value%"

// ---------------------------------------------------------------- visits (FL-L11)

/**
 * How often my profile and services were looked at: the two numbers large with how they moved against the period before, where the visits
 * came from, and — on the full level — the visits over time and the services looked at most with the orders they brought. Only numbers: who
 * looked is never kept.
 */
@Composable
private fun ViewsCard(v: InsightViewsDto, period: Int) {
    InfoCard(title = stringResource(R.string.insights_views_title), icon = Icons.Rounded.Visibility) {
        Text(stringResource(R.string.insights_views_sub, period), color = settingsMut(), fontSize = 12.sp)
        if (v.profile + v.service + v.profileBefore + v.serviceBefore == 0) {
            Text(stringResource(R.string.insights_views_none), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp)
            return@InfoCard
        }
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            ViewsFigure(Icons.Rounded.Person, v.profile, v.profileBefore, stringResource(R.string.insights_views_profile), Modifier.weight(1f))
            ViewsFigure(Icons.Rounded.Storefront, v.service, v.serviceBefore, stringResource(R.string.insights_views_service), Modifier.weight(1f))
        }
        val total = v.profile + v.service
        if (total > 0) SourceBar(v.bySource, total)
        v.series?.takeIf { s -> s.any { it.profile + it.service > 0 } }?.let { series ->
            Text(stringResource(R.string.insights_views_over_time), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 4.dp))
            PairBars(series.map { Triple(chartLabel(it.from, v.bucket), it.profile, it.service) })
        }
        v.topServices?.takeIf { it.isNotEmpty() }?.let { top ->
            Text(stringResource(R.string.insights_views_top), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 4.dp))
            top.forEach { t ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(t.title, color = settingsInk(), fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
                    Text(stringResource(R.string.insights_views_top_row, t.views, t.orders), color = settingsMut(), fontSize = 12.sp)
                }
            }
        }
    }
}

/** One of the two big numbers, with a chip for how it moved against the period before (nothing to compare with → «new»). */
@Composable
private fun ViewsFigure(icon: ImageVector, now: Int, before: Int, label: String, modifier: Modifier = Modifier) {
    Column(modifier.clip(RoundedCornerShape(16.dp)).background(settingsAccent().copy(alpha = 0.07f)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
            Text(label, color = settingsMut(), fontSize = 12.sp, maxLines = 1)
        }
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text("$now", color = settingsInk(), fontSize = 26.sp, fontWeight = FontWeight.ExtraBold)
        }
        Trend(now, before)
    }
}

@Composable
private fun Trend(now: Int, before: Int) {
    val up = Color(0xFF16A34A)
    val down = Color(0xFFDC2626)
    when {
        now == 0 && before == 0 -> Text(stringResource(R.string.insights_views_flat), color = settingsMut(), fontSize = 11.sp)
        before == 0 -> SvcPill(stringResource(R.string.insights_views_new), SvcTone(up, up.copy(alpha = 0.12f)))
        else -> {
            val change = ((now - before) * 100f / before).toInt()
            val tone = when { change > 0 -> up; change < 0 -> down; else -> settingsMut() }
            Row(Modifier.clip(RoundedCornerShape(999.dp)).background(tone.copy(alpha = 0.12f)).padding(horizontal = 8.dp, vertical = 3.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(3.dp)) {
                if (change != 0) Icon(if (change > 0) Icons.Rounded.ArrowUpward else Icons.Rounded.ArrowDownward, null, tint = tone, modifier = Modifier.size(12.dp))
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Text(if (change == 0) "0%" else "${kotlin.math.abs(change)}%", color = tone, fontSize = 11.sp, fontWeight = FontWeight.Bold)
                }
                Text(stringResource(R.string.insights_views_vs_before), color = tone, fontSize = 10.5.sp)
            }
        }
    }
}

/** Where the visits came from, as one bar cut in parts with a line of names under it. */
@Composable
private fun SourceBar(by: Map<String, Int>, total: Int) {
    val parts = listOf(
        Triple("offer", R.string.insights_views_src_offer, settingsAccent()),
        Triple("portfolio", R.string.insights_views_src_portfolio, Color(0xFF0EA5E9)),
        Triple("market", R.string.insights_views_src_market, Color(0xFFF59E0B)),
    ).map { (key, label, color) -> Triple(by[key] ?: 0, label, color) }.filter { it.first > 0 }
    if (parts.isEmpty()) return
    var shown by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { shown = true }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Row(Modifier.fillMaxWidth().height(10.dp).clip(RoundedCornerShape(999.dp)), horizontalArrangement = Arrangement.spacedBy(2.dp)) {
                parts.forEach { (n, _, color) ->
                    val w by animateFloatAsState(if (shown) n.toFloat() / total else 0f, tween(700), label = "src")
                    if (w > 0f) Box(Modifier.weight(w).fillMaxHeight().background(color))
                }
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            parts.forEach { (n, label, color) ->
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp)) {
                    Box(Modifier.size(8.dp).clip(CircleShape).background(color))
                    Text(stringResource(label) + " · $n", color = settingsMut(), fontSize = 11.5.sp)
                }
            }
        }
    }
}

/** Two bars per bucket — the profile and the services — time running left to right in either language. */
@Composable
private fun PairBars(points: List<Triple<String, Int, Int>>) {
    val top = points.maxOf { maxOf(it.second, it.third) }.coerceAtLeast(1)
    var shown by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { shown = true }
    val service = Color(0xFFF59E0B)
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Row(Modifier.fillMaxWidth().height(150.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.Bottom) {
            points.forEach { (label, profileViews, serviceViews) ->
                Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Bottom) {
                    Row(horizontalArrangement = Arrangement.spacedBy(3.dp), verticalAlignment = Alignment.Bottom) {
                        listOf(profileViews to settingsAccent(), serviceViews to service).forEach { (value, color) ->
                            val fraction by animateFloatAsState(if (shown) value.toFloat() / top else 0f, tween(700), label = "pair")
                            Box(Modifier.width(12.dp).height((fraction * 100f).dp.coerceAtLeast(3.dp)).clip(RoundedCornerShape(topStart = 5.dp, topEnd = 5.dp)).background(if (value > 0) color else settingsMut().copy(alpha = 0.25f)))
                        }
                    }
                    Spacer(Modifier.height(6.dp))
                    Text(label, color = settingsMut(), fontSize = 10.sp, maxLines = 1)
                }
            }
        }
    }
    Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
        listOf(R.string.insights_views_profile to settingsAccent(), R.string.insights_views_service to service).forEach { (label, color) ->
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp)) {
                Box(Modifier.size(8.dp).clip(CircleShape).background(color))
                Text(stringResource(label), color = settingsMut(), fontSize = 11.5.sp)
            }
        }
    }
}

// ---------------------------------------------------------------- offers

@Composable
private fun OffersCard(o: InsightOffersDto) {
    InfoCard(title = stringResource(R.string.insights_offers_title), icon = Icons.AutoMirrored.Rounded.Send) {
        if (o.sent == 0) {
            Text(stringResource(R.string.insights_offers_none), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp)
            return@InfoCard
        }
        val base = o.sent.coerceAtLeast(1)
        FunnelRow(Icons.AutoMirrored.Rounded.Send, stringResource(R.string.insights_funnel_sent), o.sent, base)
        FunnelRow(Icons.Rounded.Visibility, stringResource(R.string.insights_funnel_viewed), o.viewed, base)
        FunnelRow(Icons.Rounded.Verified, stringResource(R.string.insights_funnel_shortlisted), o.shortlisted, base)
        FunnelRow(Icons.Rounded.CheckCircle, stringResource(R.string.insights_funnel_accepted), o.accepted, base)
        o.viewRate?.let { Text(stringResource(R.string.insights_view_rate, it), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold) }
        if (o.withdrawn > 0 || o.rejected > 0) {
            Text(stringResource(R.string.insights_offers_extra, o.withdrawn, o.rejected), color = settingsMut(), fontSize = 12.sp)
        }
    }
}

/** One step of the funnel: its label and count, and a bar as long as its share of the offers sent. */
@Composable
private fun FunnelRow(icon: ImageVector, label: String, count: Int, base: Int) {
    val share by animateFloatAsState((count.toFloat() / base).coerceIn(0f, 1f), tween(600), label = "funnel")
    Column(verticalArrangement = Arrangement.spacedBy(5.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
            Text(label, color = settingsInk(), fontSize = 13.sp, modifier = Modifier.weight(1f))
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Text("$count", color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
        Box(Modifier.fillMaxWidth().height(8.dp).clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.12f))) {
            Box(Modifier.fillMaxWidth(share).height(8.dp).clip(RoundedCornerShape(999.dp)).background(settingsAccent()))
        }
    }
}

// ---------------------------------------------------------------- services

@Composable
private fun ServicesCard(s: InsightServicesDto) {
    InfoCard(title = stringResource(R.string.insights_services_title), icon = Icons.Rounded.Storefront) {
        if (s.received == 0) {
            Text(stringResource(R.string.insights_services_none), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp)
            return@InfoCard
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            MiniCount(s.awaiting, stringResource(R.string.insights_services_awaiting), SvcTone(Color(0xFFD97706), Color(0xFFD97706).copy(alpha = 0.12f)), Modifier.weight(1f))
            MiniCount(s.active, stringResource(R.string.insights_services_active), serviceTone("active"), Modifier.weight(1f))
            MiniCount(s.completed, stringResource(R.string.insights_services_completed), SvcTone(settingsAccent(), settingsAccent().copy(alpha = 0.12f)), Modifier.weight(1f))
            MiniCount(s.declined + s.expired, stringResource(R.string.insights_services_declined), serviceTone("declined"), Modifier.weight(1f))
        }
        s.acceptRate?.let { Text(stringResource(R.string.insights_services_rate, it), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold) }
    }
}

@Composable
private fun MiniCount(count: Int, label: String, tone: SvcTone, modifier: Modifier = Modifier) {
    Column(modifier.clip(RoundedCornerShape(14.dp)).background(tone.fill).padding(vertical = 10.dp, horizontal = 4.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text("$count", color = tone.ink, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold)
        Text(label, color = tone.ink, fontSize = 10.5.sp, lineHeight = 13.sp, textAlign = TextAlign.Center, maxLines = 2)
    }
}

// ---------------------------------------------------------------- earnings

@Composable
private fun EarningsCard(e: InsightEarningsDto, dto: InsightsDto) {
    InfoCard(title = stringResource(R.string.insights_earn_title), icon = Icons.Rounded.Payments) {
        Text(stringResource(R.string.insights_earn_sub), color = settingsMut(), fontSize = 12.sp)
        val series = e.series
        if (series == null || series.none { it.earnedMinor > 0 }) {
            Text(
                if (e.earnedMinor > 0) svcMoney(e.earnedMinor, dto.decimals, dto.currency) else stringResource(R.string.insights_earn_none),
                color = settingsInk(), fontSize = if (e.earnedMinor > 0) 18.sp else 13.sp, fontWeight = if (e.earnedMinor > 0) FontWeight.ExtraBold else FontWeight.Normal,
            )
            return@InfoCard
        }
        BarChart(series.map { chartLabel(it.from, e.bucket) to it.earnedMinor }, dto.decimals)
    }
}

/** Month (`Oct`) or day (`12/10`) under a bar, in the app's language. */
@Composable
private fun chartLabel(from: String, bucket: String?): String {
    val locale = LocalConfiguration.current.locales[0] ?: Locale.getDefault()
    val date = runCatching { LocalDate.parse(from) }.getOrNull() ?: return from
    return if (bucket == "month") date.format(DateTimeFormatter.ofPattern("MMM", locale)) else "${date.dayOfMonth}/${date.monthValue}"
}

/** Money without the clutter, for the top of a bar: 950, 1.2k, 3.4M. */
private fun compactMoney(minor: Long, decimals: Int): String {
    val major = minor / Math.pow(10.0, decimals.toDouble())
    return when {
        major >= 1_000_000 -> String.format(Locale.US, "%.1fM", major / 1_000_000)
        major >= 1_000 -> String.format(Locale.US, "%.1fk", major / 1_000)
        else -> String.format(Locale.US, "%.0f", major)
    }
}

/** Bars of the earnings, time running left to right in either language; they grow in when the card appears. */
@Composable
private fun BarChart(points: List<Pair<String, Long>>, decimals: Int) {
    val top = points.maxOf { it.second }.coerceAtLeast(1)
    var shown by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { shown = true }
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Row(Modifier.fillMaxWidth().height(170.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.Bottom) {
            points.forEach { (label, value) ->
                val fraction by animateFloatAsState(if (shown) value.toFloat() / top else 0f, tween(700), label = "bar")
                Column(Modifier.weight(1f), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Bottom) {
                    if (value > 0) Text(compactMoney(value, decimals), color = settingsInk(), fontSize = 10.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                    Spacer(Modifier.height(4.dp))
                    Box(
                        Modifier.width(22.dp).height((fraction * 110f).dp.coerceAtLeast(3.dp)).clip(RoundedCornerShape(topStart = 7.dp, topEnd = 7.dp))
                            .background(if (value > 0) settingsAccent() else settingsMut().copy(alpha = 0.3f)),
                    )
                    Spacer(Modifier.height(6.dp))
                    Text(label, color = settingsMut(), fontSize = 10.sp, maxLines = 1)
                }
            }
        }
    }
}

// ---------------------------------------------------------------- reputation and specialties

@Composable
private fun ReputationCard(r: InsightReputationDto) {
    InfoCard(title = stringResource(R.string.insights_rep_title), icon = Icons.Rounded.Star) {
        RepLine(
            stringResource(R.string.insights_stat_rating),
            r.rating?.let { String.format(Locale.US, "%.1f", it) + "  ·  " + stringResource(R.string.insights_rep_reviews, r.reviewsCount) } ?: stringResource(R.string.insights_rating_new),
        )
        RepLine(stringResource(R.string.insights_rep_completed), r.completedProjects.toString())
        RepLine(stringResource(R.string.insights_rep_completion), percent(r.completionRate))
        RepLine(stringResource(R.string.insights_rep_ontime), percent(r.onTimeRate))
    }
}

@Composable
private fun RepLine(label: String, value: String) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = settingsMut(), fontSize = 13.sp, modifier = Modifier.weight(1f))
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text(value, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
        }
    }
}

@Composable
private fun SpecialtiesCard(rows: List<InsightSpecialtyDto>) {
    InfoCard(title = stringResource(R.string.insights_spec_title), icon = Icons.AutoMirrored.Rounded.TrendingUp) {
        rows.forEach { s ->
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(s.name.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                    Text(stringResource(R.string.insights_spec_row, s.sent, s.accepted), color = settingsMut(), fontSize = 12.sp)
                }
                Spacer(Modifier.width(8.dp))
                SvcPill(percent(s.acceptRate), SvcTone(settingsAccent(), settingsAccent().copy(alpha = 0.12f)))
            }
        }
    }
}
