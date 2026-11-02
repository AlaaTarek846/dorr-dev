@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.dorr.app.ui.screens.watchearn

import android.content.Context
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.ChatBubbleOutline
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.DateRange
import androidx.compose.material.icons.rounded.Download
import androidx.compose.material.icons.rounded.Favorite
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.TrendingUp
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.PictureAsPdf
import androidx.compose.material.icons.rounded.Stop
import androidx.compose.material.icons.rounded.TableChart
import androidx.compose.material.icons.rounded.ThumbUp
import androidx.compose.material.icons.rounded.TouchApp
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.drawscope.clipRect
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.ApiClient
import com.dorr.app.network.WeAdDto
import com.dorr.app.network.WeAmountRequest
import com.dorr.app.network.WeCommentDto
import com.dorr.app.network.WeDailyDto
import com.dorr.app.network.WeExtendRequest
import com.dorr.app.network.WeStatsResponseDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.chat.shareFile
import com.dorr.app.ui.screens.chat.ChSheet
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaDivider
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaKeyValue
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaOutlineCircleButton
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.time.LocalDate
import java.io.File
import java.time.ZoneId

/**
 * One ad's page (D15): its media, title, status and the actions its status allows; the numbers (counting up),
 * the money (a ring of spent vs remaining, the price, what is left by bucket) and the charts — per day, by city,
 * gender, platform, hour and reaction — all drawn on Canvas. A comments tab lets the advertiser report a comment.
 * It reloads once when the server says this ad changed; there is no timer.
 */
@Composable
fun AdStats(adId: String) {
    val host = LocalWe.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.we_error_generic)
    var data by remember { mutableStateOf<WeStatsResponseDto?>(null) }
    var failed by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    var tab by remember { mutableIntStateOf(0) }
    var busy by remember { mutableStateOf<String?>(null) }
    var confirmStop by remember { mutableStateOf(false) }
    var budgetOpen by remember { mutableStateOf(false) }
    var extendOpen by remember { mutableStateOf(false) }
    var exportMenu by remember { mutableStateOf(false) }
    var exporting by remember { mutableStateOf(false) }
    val context = LocalContext.current
    val exportFailed = stringResource(R.string.we_export_failed)

    LaunchedEffect(adId, reload) {
        runCatching { ApiClient.watchEarn.stats(weAuth(), adId).data }
            .onSuccess { if (it != null) { data = it; failed = false } else if (data == null) failed = true }
            .onFailure {
                if (data == null) failed = true
                host.showToast(it.weMessage(generic), error = true)
            }
    }
    LaunchedEffect(adId) {
        ChatRealtime.events.collect { event ->
            if (event.name == "watch_earn.ad.changed" && event.data.get("ad_id")?.takeIf { it.isJsonPrimitive }?.asString == adId) reload++
        }
    }

    /** An action on the ad (pause, resume, stop…): the answer is the ad itself, then the numbers are read once more. */
    fun act(name: String, call: suspend () -> Unit) {
        busy = name
        scope.launch {
            try {
                call()
                reload++
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                host.showToast(e.weMessage(generic), error = true)
            }
            busy = null
        }
    }

    /** The stats as a file (pdf / xlsx), downloaded with my sign-in to the cache, then the system share sheet. */
    fun export(format: String) {
        exportMenu = false
        if (exporting) return
        exporting = true
        scope.launch {
            val file = try {
                downloadAdExport(context, adId, format)
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                null
            }
            exporting = false
            if (file == null) host.showToast(exportFailed, error = true)
            else shareFile(context, file, if (format == "pdf") "application/pdf" else "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
        }
    }

    WaPage(
        title = stringResource(R.string.we_ad_stats),
        onBack = { host.pop() },
        actions = {
            if (data != null) {
                Box {
                    if (exporting) {
                        Box(Modifier.size(34.dp).clip(CircleShape).background(Wa.Surface), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator(color = Wa.Red, strokeWidth = 2.dp, modifier = Modifier.size(16.dp))
                        }
                    } else {
                        WaOutlineCircleButton(Icons.Rounded.Download, { exportMenu = true }, contentDescription = stringResource(R.string.we_export))
                    }
                    DropdownMenu(exportMenu, onDismissRequest = { exportMenu = false }, containerColor = Wa.Surface, shape = RoundedCornerShape(16.dp)) {
                        DropdownMenuItem(
                            text = { Text(stringResource(R.string.we_export_pdf), color = Wa.Ink) },
                            leadingIcon = { Icon(Icons.Rounded.PictureAsPdf, null, tint = Wa.Red) },
                            onClick = { export("pdf") },
                        )
                        DropdownMenuItem(
                            text = { Text(stringResource(R.string.we_export_excel), color = Wa.Ink) },
                            leadingIcon = { Icon(Icons.Rounded.TableChart, null, tint = We.Withdrawable) },
                            onClick = { export("xlsx") },
                        )
                    }
                }
            }
        },
    ) {
        val d = data
        if (d == null) {
            if (failed) {
                WaEmpty(
                    Icons.Rounded.Warning, Tone.Red, stringResource(R.string.we_load_failed), stringResource(R.string.we_load_failed_hint),
                    action = { WaButton(stringResource(R.string.we_retry), { reload++ }, modifier = Modifier.padding(horizontal = 40.dp)) },
                )
            } else {
                Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    WaSkeleton(Modifier.fillMaxWidth().height(170.dp), RoundedCornerShape(22.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) { repeat(3) { WaSkeleton(Modifier.weight(1f).height(96.dp), RoundedCornerShape(18.dp)) } }
                    WaSkeleton(Modifier.fillMaxWidth().height(200.dp), RoundedCornerShape(22.dp))
                }
            }
            return@WaPage
        }
        val ad = d.ad
        Header(ad, busy, Modifier.waRise(0),
            onPause = { act("pause") { ApiClient.watchEarn.pause(weAuth(), ad.id) } },
            onResume = { act("resume") { ApiClient.watchEarn.resume(weAuth(), ad.id) } },
            onStop = { confirmStop = true },
            onBudget = { budgetOpen = true },
            onExtend = { extendOpen = true },
            onEdit = { host.push(WeRoute.Wizard(AdForm(edit = ad))) },
        )
        Spacer(Modifier.height(14.dp))
        Row(Modifier.fillMaxWidth().waRise(1), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            WeChip(stringResource(R.string.we_tab_stats), tab == 0, { tab = 0 })
            WeChip(stringResource(R.string.we_tab_comments), tab == 1, { tab = 1 }, icon = Icons.Rounded.ChatBubbleOutline)
        }
        Spacer(Modifier.height(12.dp))
        AnimatedContent(tab, transitionSpec = { fadeIn(tween(240)) togetherWith fadeOut(tween(160)) }, label = "statsTab") { t ->
            if (t == 0) StatsBody(d) else AdComments(ad.id)
        }
    }

    val ad = data?.ad
    if (confirmStop && ad != null) {
        WeConfirmDialog(
            title = stringResource(R.string.we_stop_title),
            text = stringResource(R.string.we_stop_text),
            confirm = stringResource(R.string.we_stop),
            danger = true,
            onDismiss = { confirmStop = false },
            onConfirm = {
                confirmStop = false
                act("stop") { ApiClient.watchEarn.stop(weAuth(), ad.id) }
            },
        )
    }
    if (budgetOpen && ad != null) {
        BudgetSheet(ad, onDismiss = { budgetOpen = false }, onDone = { budgetOpen = false; reload++ })
    }
    if (extendOpen && ad != null) {
        val end = weInstant(ad.endsAt)?.atZone(ZoneId.systemDefault())?.toLocalDate() ?: LocalDate.now()
        WeDatePicker(initial = end.plusDays(7), min = maxOf(end.plusDays(1), LocalDate.now().plusDays(1)), onDismiss = { extendOpen = false }) { day ->
            extendOpen = false
            act("extend") { ApiClient.watchEarn.extend(weAuth(), ad.id, WeExtendRequest(weServerDate(day, endOfDay = true))) }
        }
    }
}

// ------------------------------------------------------------------------------- header and actions

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun Header(
    ad: WeAdDto,
    busy: String?,
    modifier: Modifier,
    onPause: () -> Unit,
    onResume: () -> Unit,
    onStop: () -> Unit,
    onBudget: () -> Unit,
    onExtend: () -> Unit,
    onEdit: () -> Unit,
) {
    WaCard(modifier.fillMaxWidth(), padding = 14.dp) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            if (ad.media.type == "image" && ad.media.url != null) {
                AsyncImage(
                    ApiClient.mediaUrl(ad.media.url), null, contentScale = ContentScale.Crop,
                    modifier = Modifier.size(78.dp).clip(RoundedCornerShape(18.dp)),
                )
            } else {
                AdThumb(ad, 78)
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(ad.title, color = Wa.Ink, fontSize = 15.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Spacer(Modifier.height(6.dp))
                WeStatusBadge(ad.status, ad.scheduled)
                Text(weDate(ad.startsAt) + " – " + weDate(ad.endsAt), color = Wa.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 6.dp))
            }
        }
        if (ad.status == "rejected" && !ad.rejectReason.isNullOrBlank()) {
            Spacer(Modifier.height(10.dp))
            WaNote(ad.rejectReason, icon = Icons.Rounded.Warning)
        }
        val actions = buildList<Triple<String, ImageVector, () -> Unit>> {
            when (ad.status) {
                "active" -> {
                    add(Triple("pause", Icons.Rounded.Pause, onPause))
                    add(Triple("budget", Icons.Rounded.Add, onBudget))
                    add(Triple("extend", Icons.Rounded.DateRange, onExtend))
                    add(Triple("stop", Icons.Rounded.Stop, onStop))
                }
                "paused" -> add(Triple("resume", Icons.Rounded.PlayArrow, onResume))
                "exhausted" -> add(Triple("budget", Icons.Rounded.Add, onBudget))
                "pending_review", "rejected" -> add(Triple("edit", Icons.Rounded.Edit, onEdit))
                else -> Unit
            }
        }
        if (actions.isNotEmpty()) {
            Spacer(Modifier.height(12.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                actions.forEach { (key, icon, onClick) ->
                    val label = when (key) {
                        "pause" -> R.string.we_pause
                        "resume" -> R.string.we_resume
                        "budget" -> R.string.we_add_budget
                        "extend" -> R.string.we_extend
                        "stop" -> R.string.we_stop
                        else -> R.string.we_edit
                    }
                    WaButton(
                        stringResource(label), onClick,
                        style = if (key == "stop") WaButtonStyle.Quiet else WaButtonStyle.Ghost,
                        icon = icon, compact = true, loading = busy == key,
                        modifier = Modifier.width(150.dp),
                    )
                }
            }
        }
    }
}

// ------------------------------------------------------------------------------- the numbers and the charts

@Composable
private fun StatsBody(d: WeStatsResponseDto) {
    val t = d.stats.totals
    val m = d.stats.money
    val currency = d.ad.currency
    Column {
        // Number cards, counting up.
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            WeNumberCard(stringResource(R.string.we_n_opens), t.impressions.toLong(), Icons.Rounded.Visibility, Modifier.weight(1f), tint = We.SpendOnly)
            WeNumberCard(stringResource(R.string.we_n_completed), t.completed.toLong(), Icons.Rounded.CheckCircle, Modifier.weight(1f), tint = We.Withdrawable)
            WeNumberCard(stringResource(R.string.we_n_completion), t.completionRate.toLong(), Icons.Rounded.TrendingUp, Modifier.weight(1f), suffix = "%", tint = We.Withdrawable)
        }
        Spacer(Modifier.height(10.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            WeNumberCard(stringResource(R.string.we_n_interactions), t.interactions.toLong(), Icons.Rounded.TouchApp, Modifier.weight(1f), tint = We.Interaction)
            WeNumberCard(stringResource(R.string.we_n_interaction_rate), t.interactionRate.toLong(), Icons.Rounded.TrendingUp, Modifier.weight(1f), suffix = "%", tint = We.Interaction)
        }
        Spacer(Modifier.height(10.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            WeNumberCard(stringResource(R.string.we_n_comments), t.comments.toLong(), Icons.Rounded.ChatBubbleOutline, Modifier.weight(1f), tint = Wa.Red)
            WeNumberCard(stringResource(R.string.we_n_reactions), t.reactions.toLong(), Icons.Rounded.Favorite, Modifier.weight(1f), tint = Color(0xFFDB2777))
        }

        // The money.
        WaSectionTitle(stringResource(R.string.we_money))
        WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(contentAlignment = Alignment.Center) {
                    SpentRing(m.spentMinor, m.budgetMinor, Modifier.size(118.dp))
                    Column(horizontalAlignment = Alignment.CenterHorizontally) {
                        WeCountUp(m.spentPercent.toLong(), { "$it%" }, fontSize = 20.sp)
                        Text(stringResource(R.string.we_spent), color = Wa.Mut, fontSize = 11.sp)
                    }
                }
                Spacer(Modifier.width(16.dp))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    LegendLine(Wa.Red, stringResource(R.string.we_spent), weMoney(m.spentMinor, currency))
                    LegendLine(Wa.Line, stringResource(R.string.we_remaining), weMoney(m.remainingMinor, currency))
                    LegendLine(Wa.Ink, stringResource(R.string.we_budget), weMoney(m.budgetMinor, currency))
                }
            }
            Spacer(Modifier.height(10.dp))
            WaDivider()
            WaKeyValue(stringResource(R.string.we_price_per_view), weMoney(m.pricePerViewMinor, currency), ltr = true)
            WaKeyValue(stringResource(R.string.we_views_left), "%,d".format(java.util.Locale.US, m.viewsLeft), ltr = true)
            WaKeyValue(stringResource(R.string.we_cost_per_interaction), m.costPerInteractionMinor?.let { weMoney(it, currency) } ?: "—", ltr = true)
            WaKeyValue(stringResource(R.string.we_daily_average), weMoney(m.dailyAverageMinor, currency), ltr = true)
            WaDivider()
            Text(stringResource(R.string.we_remaining_by_bucket), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 12.dp, bottom = 8.dp))
            WeBucketSplit(d.ad.budget.remainingByBucket, currency)
        }

        // Per day.
        WaSectionTitle(stringResource(R.string.we_chart_daily))
        WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
            if (d.stats.daily.all { it.completed == 0 && it.interactions == 0 }) {
                EmptyChart()
            } else {
                DailyChart(d.stats.daily, Modifier.fillMaxWidth().height(170.dp))
                Spacer(Modifier.height(8.dp))
                Row(Modifier.fillMaxWidth()) {
                    Text(shortDay(d.stats.daily.firstOrNull()?.date), color = Wa.Soft, fontSize = 10.5.sp, modifier = Modifier.weight(1f))
                    Text(shortDay(d.stats.daily.lastOrNull()?.date), color = Wa.Soft, fontSize = 10.5.sp)
                }
                Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                    LegendDot(We.Series[0], stringResource(R.string.we_n_completed))
                    LegendDot(We.Series[1], stringResource(R.string.we_n_interactions))
                }
            }
        }

        // By city.
        WaSectionTitle(stringResource(R.string.we_chart_cities))
        WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
            val rows = d.stats.byCity
            if (rows.isEmpty()) EmptyChart() else {
                val max = rows.maxOf { it.completed }.coerceAtLeast(1)
                rows.take(10).forEachIndexed { i, r ->
                    HBar(r.name ?: stringResource(R.string.we_unknown_city), r.completed, r.completed.toFloat() / max, We.Series[i % 2], i)
                }
            }
        }

        // Gender and platform.
        WaSectionTitle(stringResource(R.string.we_chart_audience))
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            WaCard(Modifier.weight(1f), padding = 14.dp) {
                Text(stringResource(R.string.we_gender), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                Spacer(Modifier.height(10.dp))
                DonutWithLegend(d.stats.byGender.map { (k, v) -> genderLabel(k) to v })
            }
            WaCard(Modifier.weight(1f), padding = 14.dp) {
                Text(stringResource(R.string.we_platform), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                Spacer(Modifier.height(10.dp))
                DonutWithLegend(d.stats.byPlatform.map { (k, v) -> platformLabel(k) to v })
            }
        }

        // By hour.
        WaSectionTitle(stringResource(R.string.we_chart_hours))
        WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
            val hours = d.stats.byHour
            if (hours.isEmpty() || hours.all { it == 0 }) EmptyChart() else {
                HourBars(hours, Modifier.fillMaxWidth().height(120.dp))
                Row(Modifier.fillMaxWidth().padding(top = 6.dp)) {
                    listOf("0", "6", "12", "18", "23").forEachIndexed { i, h ->
                        Text(h, color = Wa.Soft, fontSize = 10.sp, modifier = Modifier.weight(1f), textAlign = if (i == 4) TextAlign.End else TextAlign.Start)
                    }
                }
            }
        }

        // Reactions.
        WaSectionTitle(stringResource(R.string.we_chart_reactions))
        WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
            val reactions = d.stats.reactions.entries.sortedByDescending { it.value }
            if (reactions.isEmpty()) EmptyChart() else {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                    reactions.take(6).forEach { (emoji, n) ->
                        Column(
                            Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(Wa.Field).padding(vertical = 10.dp),
                            horizontalAlignment = Alignment.CenterHorizontally,
                        ) {
                            Text(emoji, fontSize = 22.sp)
                            WeCountUp(n.toLong(), { it.toString() }, fontSize = 13.sp)
                        }
                    }
                }
            }
        }
        Spacer(Modifier.height(12.dp))
        WaNote(stringResource(R.string.we_stats_privacy), icon = Icons.Rounded.Info)
    }
}

@Composable
private fun genderLabel(key: String): String = stringResource(
    when (key) {
        "male" -> R.string.we_gender_male
        "female" -> R.string.we_gender_female
        else -> R.string.we_unknown
    },
)

@Composable
private fun platformLabel(key: String): String = when (key) {
    "android" -> "Android"
    "ios" -> "iOS"
    "web" -> stringResource(R.string.we_platform_web)
    else -> stringResource(R.string.we_unknown)
}

private fun shortDay(iso: String?): String {
    val day = runCatching { LocalDate.parse(iso) }.getOrNull() ?: return ""
    return weDate(day)
}

@Composable
private fun EmptyChart() {
    Text(stringResource(R.string.we_no_data_yet), color = Wa.Soft, fontSize = 12.5.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(vertical = 18.dp))
}

@Composable
private fun LegendLine(color: Color, label: String, value: String) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        WeDot(color)
        Spacer(Modifier.width(6.dp))
        Text(label, color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.weight(1f))
        WeLtr { Text(value, color = Wa.Ink, fontSize = 12.sp, fontWeight = FontWeight.Bold) }
    }
}

@Composable
private fun LegendDot(color: Color, label: String) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        WeDot(color, 8.dp)
        Spacer(Modifier.width(5.dp))
        Text(label, color = Wa.Mut, fontSize = 11.sp)
    }
}

/** Grows from 0 to 1 once, when the chart first shows. */
@Composable
private fun rememberGrow(key: Any? = Unit, durationMs: Int = 900): Float {
    val grow = remember(key) { Animatable(0f) }
    LaunchedEffect(key) { grow.animateTo(1f, tween(durationMs, easing = FastOutSlowInEasing)) }
    return grow.value
}

/** The ring: what is spent, against the whole budget. */
@Composable
private fun SpentRing(spent: Long, budget: Long, modifier: Modifier) {
    val fraction = if (budget > 0) (spent.toFloat() / budget).coerceIn(0f, 1f) else 0f
    val grow = rememberGrow(fraction)
    val track = Wa.Line
    val color = Wa.Red
    Canvas(modifier) {
        val stroke = size.minDimension * 0.12f
        val inset = stroke / 2
        val arcSize = Size(size.width - stroke, size.height - stroke)
        drawArc(track, -90f, 360f, false, topLeft = Offset(inset, inset), size = arcSize, style = Stroke(stroke))
        drawArc(color, -90f, 360f * fraction * grow, false, topLeft = Offset(inset, inset), size = arcSize, style = Stroke(stroke, cap = StrokeCap.Round))
    }
}

/** Completed views and interactions per day: two lines with soft fills, drawn from the start to the end. */
@Composable
private fun DailyChart(days: List<WeDailyDto>, modifier: Modifier) {
    val reveal = rememberGrow(days.size, 1100)
    val grid = Wa.Line
    val c1 = We.Series[0]
    val c2 = We.Series[1]
    Canvas(modifier) {
        val max = (days.maxOfOrNull { maxOf(it.completed, it.interactions) } ?: 0).coerceAtLeast(1)
        val w = size.width
        val h = size.height
        for (i in 0..3) {
            val y = h * i / 3f
            drawLine(grid, Offset(0f, y), Offset(w, y), strokeWidth = 1.dp.toPx())
        }
        if (days.size == 1) {
            // One day: two bars side by side.
            val bw = w * 0.14f
            val d = days[0]
            val h1 = h * d.completed / max
            val h2 = h * d.interactions / max
            drawRoundRect(c1, Offset(w / 2 - bw - 4.dp.toPx(), h - h1 * reveal), Size(bw, h1 * reveal), CornerRadius(6.dp.toPx()))
            drawRoundRect(c2, Offset(w / 2 + 4.dp.toPx(), h - h2 * reveal), Size(bw, h2 * reveal), CornerRadius(6.dp.toPx()))
            return@Canvas
        }
        val step = w / (days.size - 1)
        // Follows the reading direction: in Arabic the first day is on the right, under its date label.
        val rtl = layoutDirection == androidx.compose.ui.unit.LayoutDirection.Rtl
        fun xOf(i: Int): Float = if (rtl) w - i * step else i * step
        fun pathOf(value: (WeDailyDto) -> Int): Path = Path().apply {
            days.forEachIndexed { i, d ->
                val x = xOf(i)
                val y = h - h * value(d) / max
                if (i == 0) moveTo(x, y) else lineTo(x, y)
            }
        }
        fun fillOf(line: Path): Path = Path().apply {
            addPath(line)
            lineTo(xOf(days.lastIndex), h)
            lineTo(xOf(0), h)
            close()
        }
        val p1 = pathOf { it.completed }
        val p2 = pathOf { it.interactions }
        // Revealed from the reading start to the end as it appears.
        val shown = w * reveal
        clipRect(left = if (rtl) w - shown else 0f, right = if (rtl) w else shown) {
            drawPath(fillOf(p1), c1.copy(alpha = 0.12f))
            drawPath(fillOf(p2), c2.copy(alpha = 0.10f))
            drawPath(p1, c1, style = Stroke(2.5.dp.toPx(), cap = StrokeCap.Round, join = StrokeJoin.Round))
            drawPath(p2, c2, style = Stroke(2.5.dp.toPx(), cap = StrokeCap.Round, join = StrokeJoin.Round))
            if (days.size <= 31) {
                days.forEachIndexed { i, d ->
                    drawCircle(c1, 3.dp.toPx(), Offset(xOf(i), h - h * d.completed / max))
                    drawCircle(c2, 3.dp.toPx(), Offset(xOf(i), h - h * d.interactions / max))
                }
            }
        }
    }
}

/** One horizontal bar (by city), its label above, growing in a little after the one before. */
@Composable
private fun HBar(label: String, value: Int, fraction: Float, color: Color, index: Int) {
    val grow = rememberGrow(fraction, 700 + index * 80)
    Column(Modifier.fillMaxWidth().padding(vertical = 5.dp)) {
        Row {
            Text(label, color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(value.toString(), color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        }
        Spacer(Modifier.height(4.dp))
        val track = Wa.Line
        Canvas(Modifier.fillMaxWidth().height(9.dp)) {
            val r = CornerRadius(size.height / 2)
            drawRoundRect(track, cornerRadius = r)
            // Mirrors in Arabic: the bar starts at the reading start.
            val width = size.width * fraction.coerceIn(0f, 1f) * grow
            val x = if (layoutDirection == androidx.compose.ui.unit.LayoutDirection.Rtl) size.width - width else 0f
            drawRoundRect(color, topLeft = Offset(x, 0f), size = Size(width, size.height), cornerRadius = r)
        }
    }
}

/** A donut of shares (gender, platform) with its legend under it. */
@Composable
private fun DonutWithLegend(slices: List<Pair<String, Int>>) {
    val total = slices.sumOf { it.second }
    if (total == 0) {
        EmptyChart()
        return
    }
    val grow = rememberGrow(total, 1000)
    val colors = We.Series
    Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
        Canvas(Modifier.size(104.dp)) {
            val stroke = size.minDimension * 0.18f
            val inset = stroke / 2
            val arcSize = Size(size.width - stroke, size.height - stroke)
            var start = -90f
            slices.forEachIndexed { i, (_, v) ->
                val sweep = 360f * v / total * grow
                drawArc(colors[i % colors.size], start, (sweep - 1.5f).coerceAtLeast(0f), false, topLeft = Offset(inset, inset), size = arcSize, style = Stroke(stroke))
                start += 360f * v / total * grow
            }
        }
        WeCountUp(total.toLong(), { it.toString() }, fontSize = 16.sp)
    }
    Spacer(Modifier.height(10.dp))
    slices.forEachIndexed { i, (label, v) ->
        Row(Modifier.fillMaxWidth().padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
            WeDot(colors[i % colors.size], 8.dp)
            Spacer(Modifier.width(5.dp))
            Text(label, color = Wa.Mut, fontSize = 11.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text("${(v * 100f / total).toInt()}%", color = Wa.Ink, fontSize = 11.sp, fontWeight = FontWeight.Bold)
        }
    }
}

/** 24 bars, one per hour of the day; the busiest hour in the brand colour. */
@Composable
private fun HourBars(hours: List<Int>, modifier: Modifier) {
    val grow = rememberGrow(hours.sum(), 900)
    val max = (hours.maxOrNull() ?: 0).coerceAtLeast(1)
    val busiest = hours.indexOf(hours.maxOrNull() ?: 0)
    val soft = We.Series[0].copy(alpha = 0.45f)
    val strong = Wa.Red
    Canvas(modifier) {
        val n = 24
        val gap = 3.dp.toPx()
        val bw = (size.width - gap * (n - 1)) / n
        val rtl = layoutDirection == androidx.compose.ui.unit.LayoutDirection.Rtl
        for (i in 0 until n) {
            val v = hours.getOrElse(i) { 0 }
            val bh = size.height * v / max * grow
            val slot = if (rtl) n - 1 - i else i
            drawRoundRect(
                if (i == busiest && v > 0) strong else soft,
                topLeft = Offset(slot * (bw + gap), size.height - bh),
                size = Size(bw, bh.coerceAtLeast(if (v > 0) 2f else 0f)),
                cornerRadius = CornerRadius(bw / 3),
            )
        }
    }
}

// ------------------------------------------------------------------------------- comments (advertiser)

/** The comments on my ad; ⋮ → report a comment (the advertiser can't delete it — D21). */
@Composable
private fun AdComments(adId: String) {
    val host = LocalWe.current
    val generic = stringResource(R.string.we_error_generic)
    val comments = remember { mutableStateListOf<WeCommentDto>() }
    var nextCursor by remember { mutableStateOf<String?>(null) }
    var loaded by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    var reportFor by remember { mutableStateOf<WeCommentDto?>(null) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(adId) {
        runCatching { ApiClient.watchEarn.comments(weAuth(), adId).data }
            .onSuccess { page -> if (page != null) { comments.clear(); comments.addAll(page.comments); nextCursor = page.nextCursor } }
            .onFailure { host.showToast(it.weMessage(generic), error = true) }
        loaded = true
    }

    WaCard(Modifier.fillMaxWidth(), padding = 12.dp) {
        when {
            !loaded -> Column(verticalArrangement = Arrangement.spacedBy(10.dp)) { repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(46.dp)) } }
            comments.isEmpty() -> EmptyChart()
            else -> comments.forEachIndexed { i, c ->
                WeCommentRow(c, menuLabel = R.string.we_report_comment, onMenu = { reportFor = c })
                if (i < comments.lastIndex) WaDivider()
            }
        }
    }
    val cursor = nextCursor
    if (cursor != null) {
        Spacer(Modifier.height(10.dp))
        WaButton(
            stringResource(R.string.we_load_more),
            onClick = {
                loadingMore = true
                scope.launch {
                    runCatching { ApiClient.watchEarn.comments(weAuth(), adId, cursor).data }.getOrNull()?.let { page ->
                        page.comments.forEach { n -> if (comments.none { it.id == n.id }) comments.add(n) }
                        nextCursor = page.nextCursor
                    }
                    loadingMore = false
                }
            },
            style = WaButtonStyle.Ghost, loading = loadingMore, compact = true,
        )
    }
    reportFor?.let { c ->
        WeReportCommentSheet(adId, c.id, onDismiss = { reportFor = null }) { message ->
            reportFor = null
            host.showToast(message)
        }
    }
}

// ------------------------------------------------------------------------------- export

/**
 * Downloads `advertiser/ads/{id}/export?format=…` (the client adds my sign-in and the wallet's `X-Country`) into the
 * cache's `exports/` folder — the one the app's FileProvider shares — under the server's file name. Null when the
 * server refused or sent nothing.
 */
private suspend fun downloadAdExport(context: Context, adId: String, format: String): File? = withContext(Dispatchers.IO) {
    val response = ApiClient.watchEarn.export(weAuth(), adId, format)
    val body = response.body()
    if (!response.isSuccessful || body == null) return@withContext null
    val ext = if (format == "pdf") "pdf" else "xlsx"
    val disposition = response.headers()["Content-Disposition"].orEmpty()
    val name = Regex("filename\\*?=(?:UTF-8'')?\"?([^\";]+)\"?", RegexOption.IGNORE_CASE).find(disposition)?.groupValues?.get(1)
        ?.let { runCatching { java.net.URLDecoder.decode(it, "UTF-8") }.getOrDefault(it) }
        ?.substringAfterLast('/')?.substringAfterLast('\\')
        ?.filter { it.isLetterOrDigit() || it in "._- " }
        ?.trim()
        ?.takeIf { it.contains('.') && !it.startsWith('.') }
        ?: "DORR-ad-$adId.$ext"
    val dir = File(context.cacheDir, "exports").apply { mkdirs() }
    File(dir, name).also { out -> body.byteStream().use { input -> out.outputStream().use { input.copyTo(it) } } }
}

// ------------------------------------------------------------------------------- more budget

/** «Increase the budget»: an amount from the ad wallet's free balance — or a way to fund it first. */
@Composable
private fun BudgetSheet(ad: WeAdDto, onDismiss: () -> Unit, onDone: () -> Unit) {
    val host = LocalWe.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.we_error_generic)
    val added = stringResource(R.string.we_budget_added)
    var free by remember { mutableStateOf<Long?>(null) }
    var text by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    val currency = ad.currency
    val amount = weParseAmount(text, currency.decimals)
    LaunchedEffect(Unit) { free = runCatching { ApiClient.watchEarn.options(weAuth()).data?.freeMinor }.getOrNull() }
    val short = amount != null && free != null && amount > (free ?: 0L)

    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding()
                .padding(horizontal = 20.dp).padding(bottom = 20.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(R.string.we_add_budget), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            DorrTextField(
                value = text,
                onValueChange = { text = it.filter { ch -> ch.isDigit() || ch == '.' || ch == ',' } },
                label = stringResource(R.string.we_amount),
                placeholder = "0",
                icon = Icons.Rounded.AccountBalanceWallet,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                error = text.isNotBlank() && amount == null,
                trailing = { Text(currency.code.orEmpty(), color = Wa.Mut, fontSize = 13.sp, fontWeight = FontWeight.Bold) },
            )
            WaCard(Modifier.fillMaxWidth(), padding = 12.dp) {
                WaKeyValue(stringResource(R.string.we_free_balance), free?.let { weMoney(it, currency) } ?: "…", ltr = true, bold = true, valueColor = if (short) Wa.Danger else We.Withdrawable)
                if (amount != null && ad.budget.pricePerViewMinor > 0) {
                    WaKeyValue(stringResource(R.string.we_enough_for), "%,d".format(java.util.Locale.US, amount / ad.budget.pricePerViewMinor) + " " + stringResource(R.string.we_views_word))
                }
            }
            if (short) {
                WaNote(stringResource(R.string.we_wallet_short, weMoney(amount ?: 0L, currency)), icon = Icons.Rounded.Warning)
                WaButton(
                    stringResource(R.string.we_fund_ad_wallet),
                    {
                        onDismiss()
                        host.push(WeRoute.AdWallet(fundMinor = ((amount ?: 0L) - (free ?: 0L)).coerceAtLeast(1)))
                    },
                    style = WaButtonStyle.Ghost, icon = Icons.Rounded.AccountBalanceWallet, compact = true,
                )
            }
            WaButton(
                stringResource(R.string.we_add_budget),
                onClick = {
                    val minor = amount ?: return@WaButton
                    busy = true
                    scope.launch {
                        try {
                            ApiClient.watchEarn.addBudget(weAuth(), ad.id, WeAmountRequest(minor))
                            host.showToast(added)
                            onDone()
                        } catch (e: CancellationException) {
                            throw e
                        } catch (e: Exception) {
                            host.showToast(e.apiFailure().message ?: generic, error = true)
                        }
                        busy = false
                    }
                },
                enabled = amount != null && !short,
                loading = busy,
                icon = Icons.Rounded.Add,
            )
        }
    }
}
