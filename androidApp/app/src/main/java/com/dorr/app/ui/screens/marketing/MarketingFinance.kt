@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.dorr.app.ui.screens.marketing

import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Logout
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.PersonAdd
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
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
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MkMemberDto
import com.dorr.app.network.MkMemberRequest
import com.dorr.app.network.MkMetricDto
import com.dorr.app.network.MkPlanDto
import com.dorr.app.network.MkProjectDto
import com.dorr.app.network.MkRoleRequest
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.chat.ChSheet
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaDivider
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaIconWell
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.watchearn.WeChip
import com.dorr.app.ui.screens.watchearn.WeConfirmDialog
import com.google.gson.JsonObject
import com.google.gson.JsonPrimitive
import kotlinx.coroutines.launch

/** Money inputs (minor units) and count inputs of the calculator, in the server's order. */
private val MONEY_INPUTS = listOf(
    "price_minor" to R.string.mktg_in_price,
    "variable_cost_minor" to R.string.mktg_in_variable_cost,
    "fixed_costs_minor" to R.string.mktg_in_fixed_costs,
    "marketing_spend_minor" to R.string.mktg_in_marketing_spend,
    "attributed_revenue_minor" to R.string.mktg_in_revenue,
)
private val COUNT_INPUTS = listOf(
    "expected_new_customers" to R.string.mktg_in_new_customers,
    "expected_conversions" to R.string.mktg_in_conversions,
)
private val METRICS = listOf(
    "contribution_margin_per_unit_minor" to R.string.mktg_m_contribution_margin_per_unit_minor,
    "break_even_units" to R.string.mktg_m_break_even_units,
    "cac_minor" to R.string.mktg_m_cac_minor,
    "cpa_minor" to R.string.mktg_m_cpa_minor,
    "roas" to R.string.mktg_m_roas,
    "ltv_minor" to R.string.mktg_m_ltv_minor,
    "monthly_profit_minor" to R.string.mktg_m_monthly_profit_minor,
)

/**
 * Finance (spec 09): the person enters figures, the server computes the three scenarios (the model never does the
 * arithmetic). A metric without what it needs says «N/A», never a made-up number. Saving is a new plan version.
 */
@Composable
fun MarketingFinance(planId: String) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val saved = stringResource(R.string.mktg_saved)
    var plan by remember { mutableStateOf<MkPlanDto?>(null) }
    val drafts = remember { mutableStateMapOf<String, String>() }
    var busy by remember { mutableStateOf(false) }
    var invalid by remember { mutableStateOf(false) }

    LaunchedEffect(planId) {
        runCatching { ApiClient.marketing.plan(mkAuth(), planId).data }
            .onSuccess { p ->
                plan = p
                val inputs = p?.currentVersion?.scenarios?.get("base")?.inputs.orEmpty()
                (MONEY_INPUTS + COUNT_INPUTS).forEach { (key, _) ->
                    inputs[key]?.let { v -> drafts[key] = if (key.endsWith("_minor")) mkMoney(v.toLong(), "").replace(",", "") else v.toLong().toString() }
                }
                inputs["monthly_churn_pct"]?.let { drafts["monthly_churn_pct"] = it.toString().removeSuffix(".0") }
            }
            .onFailure { host.fail(it, generic) }
    }
    val p = plan
    val version = p?.currentVersion
    val currency = version?.content?.getAsJsonObject("finance")?.str("currency").orEmpty()

    WaPage2(
        title = stringResource(R.string.mktg_finance),
        cta = if (version != null) {
            {
                WaButton(
                    stringResource(R.string.mktg_save),
                    onClick = {
                        val inputs = JsonObject()
                        var bad = false
                        MONEY_INPUTS.forEach { (key, _) ->
                            val t = drafts[key].orEmpty()
                            if (t.isNotBlank()) mkParseMinor(t)?.let { inputs.add(key, JsonPrimitive(it)) } ?: run { bad = true }
                        }
                        COUNT_INPUTS.forEach { (key, _) ->
                            val t = drafts[key].orEmpty().trim()
                            if (t.isNotBlank()) t.toLongOrNull()?.takeIf { it >= 0 }?.let { inputs.add(key, JsonPrimitive(it)) } ?: run { bad = true }
                        }
                        drafts["monthly_churn_pct"]?.trim()?.takeIf { it.isNotBlank() }?.let { t ->
                            t.toDoubleOrNull()?.takeIf { it in 0.0..100.0 }?.let { inputs.add("monthly_churn_pct", JsonPrimitive(it)) } ?: run { bad = true }
                        }
                        invalid = bad
                        if (bad) return@WaButton
                        busy = true
                        scope.launch {
                            val body = JsonObject().apply {
                                add("version", JsonPrimitive(version.version))
                                add("inputs", inputs)
                            }
                            runCatching { ApiClient.marketing.setFinance(mkAuth(), planId, body).data }
                                .onSuccess { if (it != null) { plan = it; host.showToast(saved) } }
                                .onFailure { host.fail(it, generic) }
                            busy = false
                        }
                    },
                    loading = busy,
                )
            }
        } else {
            null
        },
    ) {
        if (version == null) {
            repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(90.dp).padding(bottom = 12.dp), RoundedCornerShape(22.dp)) }
            return@WaPage2
        }
        WaNote(stringResource(R.string.mktg_finance_note))
        if (invalid) {
            Spacer(Modifier.height(8.dp))
            Text(stringResource(R.string.mktg_answer_invalid), color = Wa.Danger, fontSize = 12.5.sp)
        }
        Spacer(Modifier.height(12.dp))
        WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                MONEY_INPUTS.forEach { (key, label) ->
                    DorrTextField(
                        value = drafts[key].orEmpty(), onValueChange = { drafts[key] = it.take(16) }, label = stringResource(label), placeholder = "0.00",
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                        trailing = { Text(currency, color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold) },
                    )
                }
                COUNT_INPUTS.forEach { (key, label) ->
                    DorrTextField(
                        value = drafts[key].orEmpty(), onValueChange = { drafts[key] = it.filter(Char::isDigit).take(9) }, label = stringResource(label), placeholder = "0",
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    )
                }
                DorrTextField(
                    value = drafts["monthly_churn_pct"].orEmpty(), onValueChange = { drafts["monthly_churn_pct"] = it.take(6) }, label = stringResource(R.string.mktg_in_churn), placeholder = "0",
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                )
            }
        }

        WaSectionTitle(stringResource(R.string.mktg_finance_sub))
        val scenarios = version.scenarios
        if (scenarios.isEmpty()) {
            Text(stringResource(R.string.mktg_no_finance), color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.padding(horizontal = 4.dp))
        } else {
            ScenarioTable(scenarios.mapValues { it.value.outputs }, currency)
        }
        // Phase D: which input moves the profit most (shown only when the country switch is on).
        if (scenarios.isNotEmpty() && host.status?.features?.get("optimisation") != false) {
            SimulationBlock(planId, currency)
        }
    }
}

/** Metric × scenario. Wide on a phone, so it scrolls sideways; amounts read left-to-right. */
@Composable
private fun ScenarioTable(outputs: Map<String, Map<String, MkMetricDto>>, currency: String) {
    val names = listOf("conservative" to R.string.mktg_scenario_conservative, "base" to R.string.mktg_scenario_base, "optimistic" to R.string.mktg_scenario_optimistic)
    val na = stringResource(R.string.mktg_unavailable)
    WaCard(Modifier.fillMaxWidth(), padding = 12.dp) {
        Row(Modifier.horizontalScroll(rememberScrollState())) {
            Column {
                Text("", fontSize = 12.sp, modifier = Modifier.height(30.dp))
                METRICS.forEach { (_, label) ->
                    Text(stringResource(label), color = Wa.Mut, fontSize = 12.5.sp, modifier = Modifier.height(44.dp).width(130.dp).padding(top = 12.dp))
                }
            }
            names.forEach { (key, label) ->
                Column(Modifier.width(110.dp)) {
                    Text(stringResource(label), color = if (key == "base") Wa.Red else Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().height(30.dp))
                    METRICS.forEach { (metric, _) ->
                        val m = outputs[key]?.get(metric)
                        val text = when {
                            m?.value == null -> na
                            metric.endsWith("_minor") -> mkMoney(m.value.toLong(), "")
                            metric == "roas" -> "${m.value}×"
                            else -> m.value.toLong().toString()
                        }
                        androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                            Text(
                                text,
                                color = if (m?.value == null) Wa.Soft else if ((m.value) < 0) Wa.Danger else Wa.Ink,
                                fontSize = 13.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                                modifier = Modifier.fillMaxWidth().height(44.dp).padding(top = 12.dp),
                            )
                        }
                    }
                }
            }
        }
        WaDivider()
        Text(stringResource(R.string.mktg_estimated) + (if (currency.isNotBlank()) " · $currency" else ""), color = Wa.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 8.dp))
    }
}

// =============================================================================== team

/** The project team (U13): the owner adds people by phone and gives a role; anyone else may leave. */
@Composable
fun MarketingTeam(projectId: String) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val removed = stringResource(R.string.mktg_member_removed)
    val changed = stringResource(R.string.mktg_role_changed)
    var project by remember { mutableStateOf<MkProjectDto?>(null) }
    var members by remember { mutableStateOf<List<MkMemberDto>?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    var adding by remember { mutableStateOf(false) }
    var editing by remember { mutableStateOf<MkMemberDto?>(null) }
    var removing by remember { mutableStateOf<MkMemberDto?>(null) }
    var leaving by remember { mutableStateOf(false) }

    LaunchedEffect(projectId, reload) {
        runCatching {
            project = ApiClient.marketing.project(mkAuth(), projectId).data
            members = ApiClient.marketing.members(mkAuth(), projectId).data.orEmpty()
        }.onFailure { host.fail(it, generic) }
    }
    val owner = project?.abilities?.manageTeam == true

    WaPage2(
        title = stringResource(R.string.mktg_team),
        cta = if (owner) {
            { WaButton(stringResource(R.string.mktg_add_member), { adding = true }, icon = Icons.Rounded.PersonAdd) }
        } else {
            null
        },
    ) {
        val list = members
        when {
            list == null -> repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(64.dp).padding(bottom = 10.dp), RoundedCornerShape(22.dp)) }
            list.isEmpty() -> WaEmpty(Icons.Rounded.Groups, Tone.Blue, stringResource(R.string.mktg_team), "")
            else -> WaCard(Modifier.fillMaxWidth(), padding = 6.dp) {
                list.forEachIndexed { i, m ->
                    Row(Modifier.fillMaxWidth().padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
                        WaIconWell(Icons.Rounded.Person, if (m.role == "owner") Tone.Red else Tone.Gray, size = 38.dp, iconSize = 19.dp)
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text((m.name ?: m.phone.orEmpty()) + if (m.isMe) " · " + stringResource(R.string.mktg_you) else "", color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold)
                            androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                                Text(m.phone.orEmpty(), color = Wa.Mut, fontSize = 12.sp)
                            }
                        }
                        MkSmallTag(stringResource(mkRoleLabel(m.role)), if (m.role == "owner") Wa.Red else Wa.Mut)
                    }
                    if (owner && m.role != "owner") {
                        Row(Modifier.fillMaxWidth().padding(start = 58.dp, end = 10.dp, bottom = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            WaButton(stringResource(R.string.mktg_role), { editing = m }, style = WaButtonStyle.Ghost, compact = true, modifier = Modifier.weight(1f))
                            WaButton(stringResource(R.string.mktg_remove), { removing = m }, style = WaButtonStyle.Quiet, compact = true, icon = Icons.Rounded.Delete, modifier = Modifier.weight(1f))
                        }
                    }
                    if (i < list.lastIndex) WaDivider()
                }
            }
        }
        if (project != null && !owner) {
            Spacer(Modifier.height(16.dp))
            WaButton(stringResource(R.string.mktg_leave), { leaving = true }, style = WaButtonStyle.Quiet, icon = Icons.Rounded.Logout)
        }
    }

    if (adding) {
        AddMemberSheet(projectId, onDismiss = { adding = false }) { adding = false; reload++ }
    }
    editing?.let { m ->
        RoleSheet(m.role, onDismiss = { editing = null }) { role ->
            editing = null
            scope.launch {
                runCatching { ApiClient.marketing.changeRole(mkAuth(), projectId, m.id, MkRoleRequest(role)) }
                    .onSuccess { host.showToast(changed); reload++ }
                    .onFailure { host.fail(it, generic) }
            }
        }
    }
    removing?.let { m ->
        WeConfirmDialog(
            title = stringResource(R.string.mktg_remove), text = m.name ?: m.phone.orEmpty(), confirm = stringResource(R.string.mktg_remove), danger = true,
            onDismiss = { removing = null },
            onConfirm = {
                removing = null
                scope.launch {
                    runCatching { ApiClient.marketing.removeMember(mkAuth(), projectId, m.id) }
                        .onSuccess { host.showToast(removed); reload++ }
                        .onFailure { host.fail(it, generic) }
                }
            },
        )
    }
    if (leaving) {
        WeConfirmDialog(
            title = stringResource(R.string.mktg_leave), text = project?.name.orEmpty(), confirm = stringResource(R.string.mktg_leave), danger = true,
            onDismiss = { leaving = false },
            onConfirm = {
                leaving = false
                scope.launch {
                    runCatching { ApiClient.marketing.leave(mkAuth(), projectId) }
                        .onSuccess { host.open(MkRoute.Home) }
                        .onFailure { host.fail(it, generic) }
                }
            },
        )
    }
}

private val ASSIGNABLE = listOf(
    Triple("manager", R.string.mktg_role_manager, R.string.mktg_role_manager_sub),
    Triple("analyst", R.string.mktg_role_analyst, R.string.mktg_role_analyst_sub),
    Triple("viewer", R.string.mktg_role_viewer, R.string.mktg_role_viewer_sub),
)

@Composable
private fun RolePicker(selected: String, onPick: (String) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            ASSIGNABLE.forEach { (key, label, _) -> WeChip(stringResource(label), selected = selected == key, onClick = { onPick(key) }) }
        }
        ASSIGNABLE.firstOrNull { it.first == selected }?.let { Text(stringResource(it.third), color = Wa.Mut, fontSize = 12.5.sp) }
    }
}

@Composable
private fun AddMemberSheet(projectId: String, onDismiss: () -> Unit, onAdded: () -> Unit) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val added = stringResource(R.string.mktg_member_added)
    var phone by remember { mutableStateOf("") }
    var role by remember { mutableStateOf("viewer") }
    var busy by remember { mutableStateOf(false) }
    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 20.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(R.string.mktg_add_member), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                DorrTextField(
                    value = phone, onValueChange = { phone = it.filter { c -> c.isDigit() || c == '+' }.take(16) },
                    label = stringResource(R.string.mktg_phone), placeholder = "+9665XXXXXXXX", icon = Icons.Rounded.Phone,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
                )
            }
            Text(stringResource(R.string.mktg_role), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
            RolePicker(role) { role = it }
            WaButton(
                stringResource(R.string.mktg_add_member),
                onClick = {
                    busy = true
                    scope.launch {
                        runCatching { ApiClient.marketing.addMember(mkAuth(), projectId, MkMemberRequest(phone, role)) }
                            .onSuccess { host.showToast(added); onAdded() }
                            .onFailure { host.fail(it, generic) }
                        busy = false
                    }
                },
                enabled = phone.length >= 8, loading = busy,
            )
        }
    }
}

@Composable
private fun RoleSheet(current: String, onDismiss: () -> Unit, onPick: (String) -> Unit) {
    var role by remember { mutableStateOf(current) }
    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(stringResource(R.string.mktg_role), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            RolePicker(role) { role = it }
            WaButton(stringResource(R.string.mktg_save), { onPick(role) }, enabled = role != current)
        }
    }
}
