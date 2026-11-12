@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.dorr.app.ui.screens.marketing

import androidx.annotation.StringRes
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
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Lightbulb
import androidx.compose.material.icons.rounded.NotificationsActive
import androidx.compose.material.icons.rounded.Science
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MkActiveRequest
import com.dorr.app.network.MkAlertRuleDto
import com.dorr.app.network.MkAlertRuleRequest
import com.dorr.app.network.MkExperimentDto
import com.dorr.app.network.MkExperimentRequest
import com.dorr.app.network.MkExperimentResultRequest
import com.dorr.app.network.MkOptimisationsDto
import com.dorr.app.network.MkProjectDto
import com.dorr.app.network.MkSimulateRequest
import com.dorr.app.network.MkSimulationDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.chat.ChSheet
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaDivider
import com.dorr.app.ui.screens.wallet.WaIconWell
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.watchearn.WeChip
import kotlinx.coroutines.launch

private val ALERT_METRICS = listOf("cpa", "roas", "ctr", "spend", "conversions")

@StringRes
private fun alertMetricLabel(m: String): Int = when (m) {
    "roas" -> R.string.mktg_k_roas
    "ctr" -> R.string.mktg_k_ctr
    "spend" -> R.string.mktg_k_spend
    "conversions" -> R.string.mktg_k_conversions
    else -> R.string.mktg_k_cpa
}

/** Money metrics are entered in major units and compared in minor units on the server. */
private fun isMoney(metric: String) = metric == "cpa" || metric == "spend"

/**
 * Optimisation (phase D): suggestions with their numbers, alert rules, and A/B experiments judged in code. Nothing here
 * changes a budget or a campaign — every line is for a person to decide.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun MarketingOptimise(projectId: String) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val reconnect = collectReconnectTick()
    var project by remember { mutableStateOf<MkProjectDto?>(null) }
    var advice by remember { mutableStateOf<MkOptimisationsDto?>(null) }
    var rules by remember { mutableStateOf<List<MkAlertRuleDto>>(emptyList()) }
    var experiments by remember { mutableStateOf<List<MkExperimentDto>>(emptyList()) }
    var reload by remember { mutableIntStateOf(0) }
    var addingRule by remember { mutableStateOf(false) }
    var addingTest by remember { mutableStateOf(false) }
    var recording by remember { mutableStateOf<MkExperimentDto?>(null) }

    LaunchedEffect(projectId, reload, reconnect) {
        runCatching {
            if (project == null) project = ApiClient.marketing.project(mkAuth(), projectId).data
            advice = ApiClient.marketing.optimisations(mkAuth(), projectId).data
            rules = ApiClient.marketing.alertRules(mkAuth(), projectId).data.orEmpty()
            experiments = ApiClient.marketing.experiments(mkAuth(), projectId).data.orEmpty()
        }.onFailure { host.fail(it, generic) }
    }
    val canEdit = project?.abilities?.edit == true
    val canResults = project?.abilities?.results == true
    val currency = project?.currency.orEmpty()

    WaPage2(title = stringResource(R.string.mktg_optimise)) {
        val a = advice
        if (a == null) {
            repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(80.dp).padding(bottom = 12.dp), RoundedCornerShape(22.dp)) }
            return@WaPage2
        }
        a.note?.let { WaNote(it) }

        WaSectionTitle(stringResource(R.string.mktg_suggestions))
        WaCard(Modifier.fillMaxWidth(), padding = 6.dp) {
            a.suggestions.forEachIndexed { i, s ->
                if (i > 0) WaDivider()
                Row(Modifier.fillMaxWidth().padding(10.dp), verticalAlignment = Alignment.Top) {
                    Icon(Icons.Rounded.Lightbulb, null, tint = when (s.priority) { "high" -> Wa.Danger; "medium" -> MkAmber; else -> MkBlue }, modifier = Modifier.size(18.dp).padding(top = 1.dp))
                    Spacer(Modifier.width(10.dp))
                    Text(s.text, color = Wa.Ink, fontSize = 13.5.sp, lineHeight = 20.sp, modifier = Modifier.weight(1f))
                }
            }
        }

        WaSectionTitle(stringResource(R.string.mktg_alert_rules), trailing = {
            if (canEdit) Text(stringResource(R.string.mktg_add), color = Wa.Red, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.clickable { addingRule = true }.padding(6.dp))
        })
        if (rules.isEmpty()) {
            Text(stringResource(R.string.mktg_no_alert_rules), color = Wa.Soft, fontSize = 12.5.sp, modifier = Modifier.padding(horizontal = 4.dp))
        } else {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                rules.forEach { r ->
                    WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            WaIconWell(Icons.Rounded.NotificationsActive, if (r.active) Tone.Amber else Tone.Gray, size = 38.dp, iconSize = 19.dp)
                            Spacer(Modifier.width(10.dp))
                            Column(Modifier.weight(1f)) {
                                val threshold = if (isMoney(r.metric)) mkMoney(r.threshold.toLong(), currency) else r.threshold.toString().removeSuffix(".0")
                                Text(stringResource(alertMetricLabel(r.metric)) + " " + stringResource(if (r.comparison == "above") R.string.mktg_above else R.string.mktg_below) + " " + threshold, color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold)
                                val now = r.current?.value?.let { v -> if (isMoney(r.metric)) mkMoney(v.toLong(), currency) else v.toString().removeSuffix(".0") } ?: stringResource(R.string.mktg_r_missing_data)
                                Text(stringResource(R.string.mktg_last_n_days, r.windowDays) + " · " + stringResource(R.string.mktg_now_value, now), color = Wa.Mut, fontSize = 12.sp)
                            }
                            if (canEdit) {
                                Switch(r.active, { on ->
                                    scope.launch { runCatching { ApiClient.marketing.toggleAlertRule(mkAuth(), r.id, MkActiveRequest(on)) }.onSuccess { reload++ }.onFailure { host.fail(it, generic) } }
                                }, colors = SwitchDefaults.colors(checkedTrackColor = Wa.Red))
                                Icon(Icons.Rounded.Delete, stringResource(R.string.mktg_delete), tint = Wa.Soft, modifier = Modifier.size(34.dp).clip(CircleShape).clickable {
                                    scope.launch { runCatching { ApiClient.marketing.deleteAlertRule(mkAuth(), r.id) }.onSuccess { reload++ }.onFailure { host.fail(it, generic) } }
                                }.padding(7.dp))
                            }
                        }
                    }
                }
            }
        }

        WaSectionTitle(stringResource(R.string.mktg_experiments), trailing = {
            if (canEdit) Text(stringResource(R.string.mktg_add), color = Wa.Red, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.clickable { addingTest = true }.padding(6.dp))
        })
        if (experiments.isEmpty()) {
            Text(stringResource(R.string.mktg_no_experiments), color = Wa.Soft, fontSize = 12.5.sp, modifier = Modifier.padding(horizontal = 4.dp))
        } else {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                experiments.forEach { e ->
                    val verdict = e.conclusion ?: e.preview
                    WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            WaIconWell(Icons.Rounded.Science, Tone.Blue, size = 38.dp, iconSize = 19.dp)
                            Spacer(Modifier.width(10.dp))
                            Column(Modifier.weight(1f)) {
                                Text(e.name, color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                                Text(e.hypothesis, color = Wa.Mut, fontSize = 12.sp, lineHeight = 17.sp)
                            }
                            MkBadge(stringResource(if (e.status == "CONCLUDED") R.string.mktg_concluded else R.string.mktg_running), if (e.status == "CONCLUDED") Wa.Green else MkBlue)
                        }
                        Spacer(Modifier.height(10.dp))
                        e.variants.forEach { v ->
                            val rate = verdict?.rates?.get(v.key)
                            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(vertical = 3.dp)) {
                                Text(v.key, color = if (verdict?.winner == v.key) Wa.Green else Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Black, modifier = Modifier.width(22.dp))
                                Box(Modifier.weight(1f).height(8.dp).clip(RoundedCornerShape(99.dp)).background(Wa.Line)) {
                                    Box(Modifier.fillMaxWidth(((rate ?: 0.0) / (verdict?.rates?.values?.filterNotNull()?.maxOrNull()?.takeIf { it > 0 } ?: 1.0)).toFloat().coerceIn(0f, 1f)).fillMaxHeight().clip(RoundedCornerShape(99.dp)).background(if (verdict?.winner == v.key) Wa.Green else MkBlue))
                                }
                                Spacer(Modifier.width(8.dp))
                                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                                    Text(rate?.let { "$it%" } ?: "—", color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.width(56.dp))
                                }
                            }
                        }
                        verdict?.text?.takeIf { it.isNotBlank() }?.let {
                            Spacer(Modifier.height(8.dp))
                            Text(it, color = if (verdict.verdict == "winner") Wa.Green else Wa.Mut, fontSize = 12.5.sp, lineHeight = 18.sp, fontWeight = FontWeight.Bold)
                        }
                        if (e.status != "CONCLUDED") {
                            Spacer(Modifier.height(10.dp))
                            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                if (canResults) WaButton(stringResource(R.string.mktg_results_add), { recording = e }, style = WaButtonStyle.Ghost, compact = true, modifier = Modifier.weight(1f))
                                if (canEdit) WaButton(stringResource(R.string.mktg_conclude), {
                                    scope.launch { runCatching { ApiClient.marketing.concludeExperiment(mkAuth(), e.id) }.onSuccess { reload++ }.onFailure { host.fail(it, generic) } }
                                }, style = WaButtonStyle.Quiet, compact = true, modifier = Modifier.weight(1f))
                            }
                        }
                    }
                }
            }
        }
    }

    if (addingRule) AlertRuleSheet(projectId, currency, onDismiss = { addingRule = false }) { addingRule = false; reload++ }
    if (addingTest) ExperimentSheet(projectId, onDismiss = { addingTest = false }) { addingTest = false; reload++ }
    recording?.let { e -> ExperimentResultSheet(e, onDismiss = { recording = null }) { recording = null; reload++ } }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun AlertRuleSheet(projectId: String, currency: String, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    var metric by remember { mutableStateOf("cpa") }
    var above by remember { mutableStateOf(true) }
    var value by remember { mutableStateOf("") }
    var window by remember { mutableIntStateOf(7) }
    var busy by remember { mutableStateOf(false) }
    val parsed: Double? = if (isMoney(metric)) mkParseMinor(value)?.toDouble() else value.trim().replace(',', '.').toDoubleOrNull()?.takeIf { it >= 0 }
    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(stringResource(R.string.mktg_alert_new), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                ALERT_METRICS.forEach { m -> WeChip(stringResource(alertMetricLabel(m)), selected = metric == m, onClick = { metric = m; value = "" }) }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                WeChip(stringResource(R.string.mktg_above), selected = above, onClick = { above = true })
                WeChip(stringResource(R.string.mktg_below), selected = !above, onClick = { above = false })
            }
            DorrTextField(
                value = value, onValueChange = { value = it.take(14) }, label = stringResource(R.string.mktg_threshold),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), error = value.isNotBlank() && parsed == null,
                trailing = { Text(if (isMoney(metric)) currency else if (metric == "ctr") "%" else if (metric == "roas") "×" else "", color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold) },
            )
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf(1, 7, 14, 30).forEach { d -> WeChip(stringResource(R.string.mktg_last_n_days, d), selected = window == d, onClick = { window = d }) }
            }
            WaButton(stringResource(R.string.mktg_save), {
                busy = true
                scope.launch {
                    runCatching { ApiClient.marketing.createAlertRule(mkAuth(), projectId, MkAlertRuleRequest(metric, if (above) "above" else "below", parsed ?: 0.0, window)) }
                        .onSuccess { onSaved() }.onFailure { host.fail(it, generic) }
                    busy = false
                }
            }, enabled = parsed != null, loading = busy)
        }
    }
}

@Composable
private fun ExperimentSheet(projectId: String, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    var name by remember { mutableStateOf("") }
    var hypothesis by remember { mutableStateOf("") }
    var metric by remember { mutableStateOf("ctr") }
    var busy by remember { mutableStateOf(false) }
    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(stringResource(R.string.mktg_experiment_new), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            DorrTextField(value = name, onValueChange = { name = it.take(160) }, label = stringResource(R.string.mktg_item_title))
            DorrTextField(value = hypothesis, onValueChange = { hypothesis = it.take(500) }, label = stringResource(R.string.mktg_hypothesis), placeholder = stringResource(R.string.mktg_hypothesis_hint), singleLine = false, minLines = 2, minHeight = 76.dp)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                WeChip(stringResource(R.string.mktg_k_ctr), selected = metric == "ctr", onClick = { metric = "ctr" })
                WeChip(stringResource(R.string.mktg_conversion_rate), selected = metric == "conversion_rate", onClick = { metric = "conversion_rate" })
            }
            WaButton(stringResource(R.string.mktg_save), {
                busy = true
                scope.launch {
                    runCatching { ApiClient.marketing.createExperiment(mkAuth(), projectId, MkExperimentRequest(name.trim(), hypothesis.trim(), metric)) }
                        .onSuccess { onSaved() }.onFailure { host.fail(it, generic) }
                    busy = false
                }
            }, enabled = name.trim().length >= 2 && hypothesis.trim().length >= 5, loading = busy)
        }
    }
}

@Composable
private fun ExperimentResultSheet(e: MkExperimentDto, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    var variant by remember { mutableStateOf(e.variants.firstOrNull()?.key ?: "A") }
    val f = remember { mutableStateMapOf<String, String>() }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(variant) {
        val r = e.results[variant].orEmpty()
        listOf("impressions", "clicks", "conversions").forEach { k -> f[k] = r[k]?.toString().orEmpty() }
    }
    fun n(k: String) = f[k]?.trim()?.toLongOrNull()
    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(e.name, color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                e.variants.forEach { v -> WeChip(stringResource(R.string.mktg_variant, v.key), selected = variant == v.key, onClick = { variant = v.key }) }
            }
            listOf("impressions" to R.string.mktg_k_impressions, "clicks" to R.string.mktg_k_clicks, "conversions" to R.string.mktg_k_conversions).forEach { (k, label) ->
                DorrTextField(value = f[k].orEmpty(), onValueChange = { f[k] = it.filter(Char::isDigit).take(12) }, label = stringResource(label), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
            }
            val bad = (n("clicks") ?: 0) > (n("impressions") ?: Long.MAX_VALUE) || (n("conversions") ?: 0) > (n("clicks") ?: Long.MAX_VALUE)
            if (bad) Text(stringResource(R.string.mktg_clicks_too_many), color = Wa.Danger, fontSize = 12.sp)
            Text(stringResource(R.string.mktg_experiment_min), color = Wa.Soft, fontSize = 11.5.sp, lineHeight = 16.sp)
            WaButton(stringResource(R.string.mktg_save), {
                busy = true
                scope.launch {
                    runCatching { ApiClient.marketing.experimentResults(mkAuth(), e.id, MkExperimentResultRequest(variant, n("impressions"), n("clicks"), n("conversions"))) }
                        .onSuccess { onSaved() }.onFailure { host.fail(it, generic) }
                    busy = false
                }
            }, enabled = !bad && listOf("impressions", "clicks", "conversions").any { !f[it].isNullOrBlank() }, loading = busy)
        }
    }
}

/** On the finance page: which input moves the monthly profit most (estimates from the person's own inputs). */
@Composable
internal fun SimulationBlock(planId: String, currency: String) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    var swing by remember { mutableIntStateOf(20) }
    var sim by remember { mutableStateOf<MkSimulationDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    WaSectionTitle(stringResource(R.string.mktg_simulation))
    WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
        Text(stringResource(R.string.mktg_simulation_note), color = Wa.Mut, fontSize = 12.5.sp, lineHeight = 18.sp)
        Spacer(Modifier.height(10.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            listOf(10, 20, 30).forEach { s -> WeChip("±$s%", selected = swing == s, onClick = { swing = s }) }
            Spacer(Modifier.weight(1f))
            WaButton(stringResource(R.string.mktg_simulate), {
                busy = true
                scope.launch {
                    runCatching { ApiClient.marketing.simulate(mkAuth(), planId, MkSimulateRequest(swing)).data }
                        .onSuccess { sim = it }.onFailure { host.fail(it, generic) }
                    busy = false
                }
            }, compact = true, loading = busy)
        }
        sim?.let { s ->
            Spacer(Modifier.height(12.dp))
            if (s.tornado.isEmpty()) {
                Text(stringResource(R.string.mktg_no_finance), color = Wa.Mut, fontSize = 12.5.sp)
            } else {
                Text(stringResource(R.string.mktg_base_profit, mkMoney(s.baseProfitMinor, currency)), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(8.dp))
                val max = s.tornado.maxOf { it.swingMinor }.coerceAtLeast(1)
                s.tornado.forEach { t ->
                    Column(Modifier.padding(vertical = 4.dp)) {
                        Text(stringResource(inputLabel(t.input)), color = Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                        MkBar(t.swingMinor.toFloat() / max, MkAmber)
                        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                            Text(mkMoney(t.lowProfitMinor, "") + "  …  " + mkMoney(t.highProfitMinor, ""), color = Wa.Mut, fontSize = 11.5.sp)
                        }
                    }
                }
            }
        }
    }
}

@StringRes
private fun inputLabel(key: String): Int = when (key) {
    "price_minor" -> R.string.mktg_in_price
    "variable_cost_minor" -> R.string.mktg_in_variable_cost
    "fixed_costs_minor" -> R.string.mktg_in_fixed_costs
    "marketing_spend_minor" -> R.string.mktg_in_marketing_spend
    "expected_new_customers" -> R.string.mktg_in_new_customers
    else -> R.string.mktg_in_churn
}
