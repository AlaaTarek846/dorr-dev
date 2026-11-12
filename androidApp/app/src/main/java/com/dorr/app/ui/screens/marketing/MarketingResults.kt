@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.dorr.app.ui.screens.marketing

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.annotation.StringRes
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
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
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AutoGraph
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Lightbulb
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Savings
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.UploadFile
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.Icon
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
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MkBudgetDto
import com.dorr.app.network.MkBudgetRequest
import com.dorr.app.network.MkBudgetUpdateRequest
import com.dorr.app.network.MkKpiBoardDto
import com.dorr.app.network.MkKpiGroupDto
import com.dorr.app.network.MkLockRequest
import com.dorr.app.network.MkMetricInput
import com.dorr.app.network.MkMetricRowDto
import com.dorr.app.network.MkMetricsRequest
import com.dorr.app.network.MkProjectDto
import com.dorr.app.network.MkRateDto
import com.dorr.app.network.MkReportDto
import com.dorr.app.network.MkReportLineDto
import com.dorr.app.network.apiFailure
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.calendar.CalDatePickerDialog
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
import com.dorr.app.ui.screens.wallet.waRise
import com.dorr.app.ui.screens.watchearn.WeChip
import com.dorr.app.ui.screens.watchearn.WeConfirmDialog
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale

private fun levelColor(level: String): Color = when (level) {
    "exceeded" -> Color(0xFFDC2626)
    "warning" -> Color(0xFFD97706)
    else -> Color(0xFF16A34A)
}

@StringRes
private fun budgetStatusLabel(status: String): Int = when (status) {
    "APPROVED" -> R.string.mktg_bs_APPROVED
    "CLOSED" -> R.string.mktg_bs_CLOSED
    else -> R.string.mktg_bs_PENDING
}

@StringRes
private fun reasonLabel(reason: String?): Int = when (reason) {
    "no_impressions" -> R.string.mktg_r_no_impressions
    "no_clicks" -> R.string.mktg_r_no_clicks
    "no_conversions" -> R.string.mktg_r_no_conversions
    "no_spend" -> R.string.mktg_r_no_spend
    "no_revenue_data" -> R.string.mktg_r_no_revenue_data
    else -> R.string.mktg_r_missing_data
}

private fun shortDate(iso: String): String = runCatching { LocalDate.parse(iso).format(DateTimeFormatter.ofPattern("d MMM", Locale.getDefault())) }.getOrElse { iso }

// =============================================================================== budgets (U08)

/**
 * Two caps that never mix: ad spend (money, counted from the results entered) and AI (marketing AI calls, refused
 * by the server once used up). The owner sets or approves; a manager's cap waits for the owner.
 */
@Composable
fun MarketingBudgets(projectId: String) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val reconnect = collectReconnectTick()
    var project by remember { mutableStateOf<MkProjectDto?>(null) }
    var budgets by remember { mutableStateOf<List<MkBudgetDto>?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    var adding by remember { mutableStateOf(false) }
    var changing by remember { mutableStateOf<MkBudgetDto?>(null) }
    var closing by remember { mutableStateOf<MkBudgetDto?>(null) }
    var busy by remember { mutableStateOf<String?>(null) }
    val relevantChange = if (host.changedProject == null || host.changedProject == projectId) host.changes else -1

    LaunchedEffect(projectId, reload, reconnect, relevantChange) {
        runCatching {
            if (project == null) project = ApiClient.marketing.project(mkAuth(), projectId).data
            budgets = ApiClient.marketing.budgets(mkAuth(), projectId).data.orEmpty()
        }.onFailure { host.fail(it, generic) }
    }
    val abilities = project?.abilities
    val currency = project?.currency.orEmpty()
    val approvedToast = stringResource(R.string.mktg_budget_approved)

    WaPage2(title = stringResource(R.string.mktg_budgets)) {
        WaNote(stringResource(R.string.mktg_budgets_note))
        if (abilities?.edit == true) {
            Spacer(Modifier.height(12.dp))
            WaButton(stringResource(R.string.mktg_budget_add), { adding = true }, icon = Icons.Rounded.Add, compact = true)
        }
        Spacer(Modifier.height(12.dp))
        val list = budgets
        when {
            list == null -> repeat(2) { WaSkeleton(Modifier.fillMaxWidth().height(120.dp).padding(bottom = 12.dp), RoundedCornerShape(22.dp)) }
            list.isEmpty() -> WaEmpty(Icons.Rounded.Savings, Tone.Gray, stringResource(R.string.mktg_budgets_empty), stringResource(R.string.mktg_budgets_empty_sub))
            else -> Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                list.forEachIndexed { i, b ->
                    BudgetCard(
                        b, currency, Modifier.waRise(i),
                        canApprove = abilities?.approveBudget == true, canEdit = abilities?.edit == true, canClose = abilities?.manageTeam == true, busy = busy,
                        onApprove = {
                            busy = "approve:${b.id}"
                            scope.launch {
                                runCatching { ApiClient.marketing.approveBudget(mkAuth(), b.id, MkLockRequest(b.lockVersion)) }
                                    .onSuccess { host.showToast(approvedToast); reload++ }
                                    .onFailure { host.fail(it, generic); reload++ }
                                busy = null
                            }
                        },
                        onChange = { changing = b },
                        onClose = { closing = b },
                    )
                }
            }
        }
    }

    if (adding) {
        BudgetSheet(projectId, null, currency, onDismiss = { adding = false }) { adding = false; reload++ }
    }
    changing?.let { b -> BudgetSheet(projectId, b, currency, onDismiss = { changing = null }) { changing = null; reload++ } }
    closing?.let { b ->
        WeConfirmDialog(
            title = stringResource(R.string.mktg_budget_close), text = stringResource(R.string.mktg_budget_close_text), confirm = stringResource(R.string.mktg_budget_close),
            onDismiss = { closing = null },
            onConfirm = {
                closing = null
                scope.launch {
                    runCatching { ApiClient.marketing.closeBudget(mkAuth(), b.id) }.onSuccess { reload++ }.onFailure { host.fail(it, generic) }
                }
            },
        )
    }
}

@Composable
private fun BudgetCard(
    b: MkBudgetDto, currency: String, modifier: Modifier, canApprove: Boolean, canEdit: Boolean, canClose: Boolean, busy: String?,
    onApprove: () -> Unit, onChange: () -> Unit, onClose: () -> Unit,
) {
    val ads = b.kind == "ads"
    WaCard(modifier.fillMaxWidth(), padding = 16.dp) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            WaIconWell(if (ads) Icons.Rounded.Savings else Icons.Rounded.AutoGraph, if (ads) Tone.Green else Tone.Blue, size = 42.dp, iconSize = 21.dp)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(if (ads) R.string.mktg_budget_ads else R.string.mktg_budget_ai), color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold)
                Text(shortDate(b.periodStart) + " – " + shortDate(b.periodEnd), color = Wa.Mut, fontSize = 12.5.sp)
            }
            MkBadge(stringResource(budgetStatusLabel(b.status)), if (b.status == "APPROVED") Wa.Green else if (b.status == "CLOSED") Wa.Mut else MkAmber)
        }
        Spacer(Modifier.height(14.dp))
        Row(verticalAlignment = Alignment.Bottom) {
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Text(if (ads) mkMoney(b.used, b.currency ?: currency) else b.used.toString(), color = Wa.Ink, fontSize = 20.sp, fontWeight = FontWeight.Black)
            }
            Spacer(Modifier.width(6.dp))
            Text(stringResource(R.string.mktg_of_cap, if (ads) mkMoney(b.cap, b.currency ?: currency) else stringResource(R.string.mktg_calls, b.cap.toInt())), color = Wa.Mut, fontSize = 12.5.sp, modifier = Modifier.padding(bottom = 3.dp))
            Spacer(Modifier.weight(1f))
            Text("${b.pct ?: 0.0}%", color = levelColor(b.level), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
        }
        Spacer(Modifier.height(8.dp))
        MkBar(((b.pct ?: 0.0) / 100.0).toFloat(), levelColor(b.level))
        Spacer(Modifier.height(6.dp))
        Text(stringResource(R.string.mktg_alert_at, b.alertPct), color = Wa.Soft, fontSize = 11.5.sp)
        if (ads && (b.estimated ?: 0) > 0) {
            Text(stringResource(R.string.mktg_estimated_apart, mkMoney(b.estimated, b.currency ?: currency)), color = Wa.Mut, fontSize = 12.sp)
        }
        if (b.level != "ok" && b.status == "APPROVED") {
            Spacer(Modifier.height(8.dp))
            Text(stringResource(if (b.level == "exceeded") R.string.mktg_budget_exceeded else R.string.mktg_budget_warning), color = levelColor(b.level), fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
        }
        if (b.status == "PENDING") {
            Spacer(Modifier.height(8.dp))
            Text(stringResource(R.string.mktg_budget_pending_note), color = MkAmber, fontSize = 12.5.sp)
        }
        if (b.status != "CLOSED" && (canApprove || canEdit || canClose)) {
            Spacer(Modifier.height(12.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (b.status == "PENDING" && canApprove) {
                    WaButton(stringResource(R.string.mktg_approve_budget), onApprove, icon = Icons.Rounded.Verified, compact = true, loading = busy == "approve:${b.id}", modifier = Modifier.weight(1f))
                }
                if (canEdit) WaButton(stringResource(R.string.mktg_budget_change), onChange, style = WaButtonStyle.Ghost, compact = true, modifier = Modifier.weight(1f))
                if (canClose) WaButton(stringResource(R.string.mktg_budget_close), onClose, style = WaButtonStyle.Quiet, icon = Icons.Rounded.Lock, compact = true, modifier = Modifier.weight(1f))
            }
        }
    }
}

/** New budget, or a new cap for one (owner: applies now; manager: waits for the owner). */
@Composable
private fun BudgetSheet(projectId: String, existing: MkBudgetDto?, currency: String, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val savedText = stringResource(R.string.mktg_saved)
    var kind by remember { mutableStateOf(existing?.kind ?: "ads") }
    var start by remember { mutableStateOf(existing?.periodStart?.let { LocalDate.parse(it) } ?: LocalDate.now().withDayOfMonth(1)) }
    var end by remember { mutableStateOf(existing?.periodEnd?.let { LocalDate.parse(it) } ?: LocalDate.now().withDayOfMonth(LocalDate.now().lengthOfMonth())) }
    var cap by remember { mutableStateOf(existing?.let { if (it.kind == "ads") mkMoney(it.cap, "").replace(",", "") else it.cap.toString() }.orEmpty()) }
    var alert by remember { mutableIntStateOf(existing?.alertPct ?: 80) }
    var picking by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val capValue: Long? = if (kind == "ads") mkParseMinor(cap) else cap.trim().toLongOrNull()
    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 20.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(if (existing == null) R.string.mktg_budget_add else R.string.mktg_budget_change), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            if (existing == null) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    WeChip(stringResource(R.string.mktg_budget_ads), selected = kind == "ads", onClick = { kind = "ads"; cap = "" })
                    WeChip(stringResource(R.string.mktg_budget_ai), selected = kind == "ai", onClick = { kind = "ai"; cap = "" })
                }
                Text(stringResource(if (kind == "ads") R.string.mktg_budget_ads_sub else R.string.mktg_budget_ai_sub), color = Wa.Mut, fontSize = 12.5.sp, lineHeight = 18.sp)
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    DorrTextField(value = shortDate(start.toString()), onValueChange = {}, label = stringResource(R.string.mktg_from), icon = Icons.Rounded.CalendarMonth, onClick = { picking = "start" }, modifier = Modifier.weight(1f))
                    DorrTextField(value = shortDate(end.toString()), onValueChange = {}, label = stringResource(R.string.mktg_to), icon = Icons.Rounded.CalendarMonth, onClick = { picking = "end" }, modifier = Modifier.weight(1f))
                }
            }
            DorrTextField(
                value = cap,
                onValueChange = { cap = if (kind == "ads") it.take(14) else it.filter(Char::isDigit).take(6) },
                label = stringResource(if (kind == "ads") R.string.mktg_cap_money else R.string.mktg_cap_calls),
                placeholder = if (kind == "ads") "0.00" else "0",
                keyboardOptions = KeyboardOptions(keyboardType = if (kind == "ads") KeyboardType.Decimal else KeyboardType.Number),
                trailing = if (kind == "ads") ({ Text(currency, color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold) }) else null,
                error = cap.isNotBlank() && (capValue == null || capValue <= 0),
            )
            Text(stringResource(R.string.mktg_alert_line), color = Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf(70, 80, 90).forEach { p -> WeChip("$p%", selected = alert == p, onClick = { alert = p }) }
            }
            WaButton(
                stringResource(R.string.mktg_save),
                onClick = {
                    val value = capValue ?: return@WaButton
                    busy = true
                    scope.launch {
                        runCatching {
                            if (existing == null) {
                                ApiClient.marketing.createBudget(mkAuth(), projectId, MkBudgetRequest(kind, start.toString(), end.toString(), if (kind == "ads") value else null, if (kind == "ai") value else null, alert))
                            } else {
                                ApiClient.marketing.updateBudget(mkAuth(), existing.id, MkBudgetUpdateRequest(existing.lockVersion, if (kind == "ads") value else null, if (kind == "ai") value else null, alert))
                            }
                        }.onSuccess { host.showToast(savedText); onSaved() }
                            .onFailure { host.fail(it, generic) }
                        busy = false
                    }
                },
                enabled = capValue != null && capValue > 0 && !end.isBefore(start), loading = busy,
            )
        }
    }
    when (picking) {
        "start" -> CalDatePickerDialog(start, onDismiss = { picking = null }) { start = it; if (end.isBefore(it)) end = it; picking = null }
        "end" -> CalDatePickerDialog(end, onDismiss = { picking = null }) { end = if (it.isBefore(start)) start else it; picking = null }
    }
}

// =============================================================================== results + KPI board (U11)

/**
 * The KPI board: what was measured (copied from the platforms' reports) and what was estimated are never added
 * together; a rate without what it needs says why instead of showing 0. Results come by hand or from a CSV file.
 */
@Composable
fun MarketingResultsPage(projectId: String) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val generic = stringResource(R.string.mktg_error_generic)
    val reconnect = collectReconnectTick()
    var project by remember { mutableStateOf<MkProjectDto?>(null) }
    var days by remember { mutableIntStateOf(30) }
    var board by remember { mutableStateOf<MkKpiBoardDto?>(null) }
    var rows by remember { mutableStateOf<List<MkMetricRowDto>>(emptyList()) }
    var reload by remember { mutableIntStateOf(0) }
    var adding by remember { mutableStateOf(false) }
    var importing by remember { mutableStateOf(false) }
    var importErrors by remember { mutableStateOf<String?>(null) }
    var removing by remember { mutableStateOf<MkMetricRowDto?>(null) }
    val imported = stringResource(R.string.mktg_results_imported)
    val tooBig = stringResource(R.string.mktg_file_too_big)
    val lineText = stringResource(R.string.mktg_file_line)

    LaunchedEffect(projectId, days, reload, reconnect) {
        runCatching {
            if (project == null) project = ApiClient.marketing.project(mkAuth(), projectId).data
            val to = LocalDate.now()
            board = ApiClient.marketing.kpis(mkAuth(), projectId, to.minusDays(days - 1L).toString(), to.toString()).data
            rows = ApiClient.marketing.metrics(mkAuth(), projectId).data.orEmpty()
        }.onFailure { host.fail(it, generic) }
    }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri == null) return@rememberLauncherForActivityResult
        importing = true
        importErrors = null
        scope.launch {
            val bytes = withContext(Dispatchers.IO) { runCatching { context.contentResolver.openInputStream(uri)?.use { it.readBytes() } }.getOrNull() }
            if (bytes == null || bytes.size > 1_000_000) {
                host.showToast(if (bytes == null) generic else tooBig, error = true)
                importing = false
                return@launch
            }
            val part = MultipartBody.Part.createFormData("file", "results.csv", bytes.toRequestBody("text/csv".toMediaType()))
            runCatching { ApiClient.marketing.importMetrics(mkAuth(), projectId, part).data }
                .onSuccess { r -> host.showToast(imported + " (" + ((r?.created ?: 0) + (r?.updated ?: 0)) + ")"); reload++ }
                .onFailure { e ->
                    val failure = host.fail(e, generic)
                    val errors = failure.data?.get("errors")?.takeIf { it.isJsonArray }?.asJsonArray
                    importErrors = errors?.take(6)?.joinToString("\n") { el ->
                        val o = el.asJsonObject
                        lineText.format(o.get("line")?.asInt ?: 0, o.get("field")?.asString.orEmpty())
                    }
                }
            importing = false
        }
    }

    val canEnter = project?.abilities?.results == true
    val currency = board?.currency ?: project?.currency.orEmpty()

    WaPage2(title = stringResource(R.string.mktg_results)) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf(7, 30, 90).forEach { d -> WeChip(stringResource(R.string.mktg_last_n_days, d), selected = days == d, onClick = { days = d }) }
        }
        if (canEnter) {
            Spacer(Modifier.height(12.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                WaButton(stringResource(R.string.mktg_results_add), { adding = true }, icon = Icons.Rounded.Add, compact = true, modifier = Modifier.weight(1f))
                WaButton(stringResource(R.string.mktg_results_import), { picker.launch("*/*") }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.UploadFile, compact = true, loading = importing, modifier = Modifier.weight(1f))
            }
            Text(stringResource(R.string.mktg_import_hint), color = Wa.Soft, fontSize = 11.5.sp, lineHeight = 16.sp, modifier = Modifier.padding(top = 6.dp, start = 4.dp, end = 4.dp))
        }
        importErrors?.let {
            Spacer(Modifier.height(10.dp))
            WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
                Text(stringResource(R.string.mktg_file_refused), color = Wa.Danger, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(4.dp))
                Text(it, color = Wa.Ink, fontSize = 12.5.sp, lineHeight = 18.sp)
            }
        }

        val b = board
        if (b == null) {
            Spacer(Modifier.height(12.dp))
            repeat(2) { WaSkeleton(Modifier.fillMaxWidth().height(110.dp).padding(bottom = 12.dp), RoundedCornerShape(22.dp)) }
            return@WaPage2
        }

        WaSectionTitle(stringResource(R.string.mktg_measured), trailing = { MkSmallTag(stringResource(R.string.mktg_basis_imported), Wa.Green) })
        if (b.gaps.daysWithData == 0) {
            WaEmpty(Icons.Rounded.AutoGraph, Tone.Gray, stringResource(R.string.mktg_no_results), stringResource(R.string.mktg_no_results_sub))
        } else {
            KpiGrid(b.measured, currency)
            Spacer(Modifier.height(10.dp))
            SpendChart(b)
            Spacer(Modifier.height(8.dp))
            val updated = b.lastUpdatedAt?.let { mkDateTime(it) }.orEmpty()
            Text(stringResource(R.string.mktg_coverage, b.gaps.daysWithData, b.gaps.days) + if (updated.isNotBlank()) " · " + stringResource(R.string.mktg_updated, updated) else "", color = Wa.Mut, fontSize = 12.sp)
            if (b.gaps.channelsWithoutRevenue.isNotEmpty()) {
                Spacer(Modifier.height(8.dp))
                WaNote(stringResource(R.string.mktg_no_revenue_for, b.gaps.channelsWithoutRevenue.map { ctxChannel(it) }.joinToString("، ")))
            }
        }

        b.estimated?.let { est ->
            WaSectionTitle(stringResource(R.string.mktg_estimated_section), trailing = { MkSmallTag(stringResource(R.string.mktg_basis_estimated), MkAmber) })
            Text(stringResource(R.string.mktg_estimated_note), color = Wa.Mut, fontSize = 12.5.sp, lineHeight = 18.sp, modifier = Modifier.padding(horizontal = 4.dp, vertical = 4.dp))
            KpiGrid(est, currency)
        }

        if (b.byChannel.isNotEmpty()) {
            WaSectionTitle(stringResource(R.string.mktg_by_channel))
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                b.byChannel.forEach { ch ->
                    WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(stringResource(mkChannelLabel(ch.channel)), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                            ch.lastDate?.let { Text(shortDate(it), color = Wa.Soft, fontSize = 11.5.sp) }
                        }
                        Spacer(Modifier.height(8.dp))
                        Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                            MiniStat(stringResource(R.string.mktg_k_spend), mkMoney(ch.measured.totals["spend_minor"], ""), Modifier.weight(1f))
                            MiniStat(stringResource(R.string.mktg_k_conversions), ch.measured.totals["conversions"]?.toString() ?: "—", Modifier.weight(1f))
                            MiniStat(stringResource(R.string.mktg_k_roas), rateText(ch.measured.rates["roas"], "×"), Modifier.weight(1f))
                        }
                    }
                }
            }
        }

        if (rows.isNotEmpty()) {
            WaSectionTitle(stringResource(R.string.mktg_entries))
            WaCard(Modifier.fillMaxWidth(), padding = 6.dp) {
                rows.take(30).forEachIndexed { i, r ->
                    if (i > 0) WaDivider()
                    Row(Modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(shortDate(r.date) + " · " + stringResource(mkChannelLabel(r.channel)), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                            val parts = listOfNotNull(
                                r.spendMinor?.let { stringResource(R.string.mktg_k_spend) + " " + mkMoney(it, "") },
                                r.clicks?.let { stringResource(R.string.mktg_k_clicks) + " " + it },
                                r.conversions?.let { stringResource(R.string.mktg_k_conversions) + " " + it },
                            )
                            Text(parts.joinToString(" · "), color = Wa.Mut, fontSize = 12.sp)
                        }
                        MkSmallTag(stringResource(if (r.basis == "estimated") R.string.mktg_basis_estimated else R.string.mktg_basis_imported), if (r.basis == "estimated") MkAmber else Wa.Green)
                        if (canEnter) {
                            Icon(Icons.Rounded.Delete, stringResource(R.string.mktg_delete), tint = Wa.Soft, modifier = Modifier.padding(start = 6.dp).size(32.dp).clip(CircleShape).clickable { removing = r }.padding(6.dp))
                        }
                    }
                }
            }
        }
    }

    if (adding) {
        AddResultsSheet(projectId, currency, onDismiss = { adding = false }) { adding = false; reload++ }
    }
    removing?.let { r ->
        WeConfirmDialog(
            title = stringResource(R.string.mktg_delete), text = shortDate(r.date) + " · " + stringResource(mkChannelLabel(r.channel)), confirm = stringResource(R.string.mktg_delete), danger = true,
            onDismiss = { removing = null },
            onConfirm = {
                removing = null
                scope.launch { runCatching { ApiClient.marketing.deleteMetric(mkAuth(), r.id) }.onSuccess { reload++ }.onFailure { host.fail(it, generic) } }
            },
        )
    }
}

@Composable
private fun ctxChannel(channel: String): String = stringResource(mkChannelLabel(channel))

private fun rateText(rate: MkRateDto?, suffix: String): String = rate?.value?.let { v -> (if (v % 1.0 == 0.0) v.toLong().toString() else v.toString()) + suffix } ?: "—"

@Composable
private fun MiniStat(label: String, value: String, modifier: Modifier = Modifier) {
    Column(modifier) {
        Text(label, color = Wa.Mut, fontSize = 11.5.sp, maxLines = 1)
        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text(value, color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
        }
    }
}

/** Six tiles: totals and rates; a rate that can't be computed says why. */
@Composable
internal fun KpiGrid(g: MkKpiGroupDto, currency: String) {
    data class Tile(val label: Int, val value: String, val reason: String?)
    val t = g.totals
    val tiles = listOf(
        Tile(R.string.mktg_k_spend, mkMoney(t["spend_minor"], currency), null),
        Tile(R.string.mktg_k_revenue, mkMoney(t["revenue_minor"], currency), null),
        Tile(R.string.mktg_k_roas, rateText(g.rates["roas"], "×"), g.rates["roas"]?.takeIf { it.value == null }?.reason),
        Tile(R.string.mktg_k_cpa, g.rates["cpa_minor"]?.value?.let { mkMoney(it.toLong(), currency) } ?: "—", g.rates["cpa_minor"]?.takeIf { it.value == null }?.reason),
        Tile(R.string.mktg_k_ctr, rateText(g.rates["ctr_pct"], "%"), g.rates["ctr_pct"]?.takeIf { it.value == null }?.reason),
        Tile(R.string.mktg_k_conversions, t["conversions"]?.toString() ?: "—", null),
    )
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        tiles.chunked(2).forEach { pair ->
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                pair.forEach { tile ->
                    WaCard(Modifier.weight(1f), padding = 14.dp) {
                        Text(stringResource(tile.label), color = Wa.Mut, fontSize = 12.sp, maxLines = 1)
                        Spacer(Modifier.height(4.dp))
                        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                            Text(tile.value, color = if (tile.value == "—") Wa.Soft else Wa.Ink, fontSize = 17.sp, fontWeight = FontWeight.Black, maxLines = 1)
                        }
                        if (tile.reason != null) {
                            Text(stringResource(reasonLabel(tile.reason)), color = Wa.Soft, fontSize = 11.sp, lineHeight = 14.sp, maxLines = 2)
                        }
                    }
                }
            }
        }
    }
}

/** Daily measured spend as bars (no axis numbers: the totals above carry the figures). */
@Composable
private fun SpendChart(b: MkKpiBoardDto) {
    val points = b.series
    if (points.size < 2) return
    val max = points.maxOf { it.spendMinor ?: 0L }.coerceAtLeast(1L)
    val bar = Wa.Red
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
        Text(stringResource(R.string.mktg_spend_per_day), color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(10.dp))
        Canvas(Modifier.fillMaxWidth().height(80.dp)) {
            val n = points.size
            val gap = 3.dp.toPx()
            val w = ((size.width - gap * (n - 1)) / n).coerceAtLeast(2f)
            points.forEachIndexed { i, p ->
                val h = ((p.spendMinor ?: 0L).toFloat() / max) * size.height
                val index = if (rtl) n - 1 - i else i
                drawRoundRect(bar, Offset(index * (w + gap), size.height - h), Size(w, h.coerceAtLeast(2f)), CornerRadius(3.dp.toPx()))
            }
        }
        Row(Modifier.fillMaxWidth()) {
            Text(shortDate(points.first().date), color = Wa.Soft, fontSize = 10.5.sp)
            Spacer(Modifier.weight(1f))
            Text(shortDate(points.last().date), color = Wa.Soft, fontSize = 10.5.sp)
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun AddResultsSheet(projectId: String, currency: String, onDismiss: () -> Unit, onSaved: () -> Unit) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val savedText = stringResource(R.string.mktg_results_saved)
    var date by remember { mutableStateOf(LocalDate.now().minusDays(1)) }
    var channel by remember { mutableStateOf("instagram") }
    var basis by remember { mutableStateOf("imported") }
    val f = remember { mutableStateMapOf<String, String>() }
    var pickDate by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }

    fun count(key: String): Long? = f[key]?.trim()?.takeIf { it.isNotEmpty() }?.toLongOrNull()
    fun money(key: String): Long? = f[key]?.trim()?.takeIf { it.isNotEmpty() }?.let { mkParseMinor(it) }
    val badMoney = listOf("spend", "revenue").any { k -> f[k].orEmpty().isNotBlank() && money(k) == null }
    val clicksTooMany = (count("clicks") ?: 0) > (count("impressions") ?: Long.MAX_VALUE)
    val empty = listOf("impressions", "clicks", "spend", "conversions", "revenue").all { f[it].isNullOrBlank() }

    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 20.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(R.string.mktg_results_add), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            DorrTextField(value = date.format(DateTimeFormatter.ofPattern("EEE d MMM yyyy", Locale.getDefault())), onValueChange = {}, label = stringResource(R.string.mktg_day), icon = Icons.Rounded.CalendarMonth, onClick = { pickDate = true })
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                MK_CHANNELS.filter { it != "offline" }.forEach { ch -> WeChip(stringResource(mkChannelLabel(ch)), selected = channel == ch, onClick = { channel = ch }) }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                WeChip(stringResource(R.string.mktg_basis_imported), selected = basis == "imported", onClick = { basis = "imported" })
                WeChip(stringResource(R.string.mktg_basis_estimated), selected = basis == "estimated", onClick = { basis = "estimated" })
            }
            Text(stringResource(if (basis == "imported") R.string.mktg_basis_imported_sub else R.string.mktg_basis_estimated_sub), color = Wa.Mut, fontSize = 12.sp, lineHeight = 17.sp)
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                DorrTextField(value = f["impressions"].orEmpty(), onValueChange = { f["impressions"] = it.filter(Char::isDigit).take(12) }, label = stringResource(R.string.mktg_k_impressions), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.weight(1f))
                DorrTextField(value = f["clicks"].orEmpty(), onValueChange = { f["clicks"] = it.filter(Char::isDigit).take(12) }, label = stringResource(R.string.mktg_k_clicks), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), error = clicksTooMany, modifier = Modifier.weight(1f))
            }
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                DorrTextField(value = f["spend"].orEmpty(), onValueChange = { f["spend"] = it.take(14) }, label = stringResource(R.string.mktg_k_spend), placeholder = "0.00", keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), error = f["spend"].orEmpty().isNotBlank() && money("spend") == null, modifier = Modifier.weight(1f))
                DorrTextField(value = f["revenue"].orEmpty(), onValueChange = { f["revenue"] = it.take(14) }, label = stringResource(R.string.mktg_k_revenue), placeholder = "0.00", keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), error = f["revenue"].orEmpty().isNotBlank() && money("revenue") == null, modifier = Modifier.weight(1f))
            }
            DorrTextField(value = f["conversions"].orEmpty(), onValueChange = { f["conversions"] = it.filter(Char::isDigit).take(9) }, label = stringResource(R.string.mktg_k_conversions), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number))
            Text(stringResource(R.string.mktg_results_blank_hint, currency), color = Wa.Soft, fontSize = 11.5.sp, lineHeight = 16.sp)
            if (clicksTooMany) Text(stringResource(R.string.mktg_clicks_too_many), color = Wa.Danger, fontSize = 12.sp)
            WaButton(
                stringResource(R.string.mktg_save),
                onClick = {
                    busy = true
                    scope.launch {
                        val row = MkMetricInput(date.toString(), channel, basis, count("impressions"), count("clicks"), money("spend"), count("conversions"), money("revenue"))
                        runCatching { ApiClient.marketing.saveMetrics(mkAuth(), projectId, MkMetricsRequest(listOf(row))) }
                            .onSuccess { host.showToast(savedText); onSaved() }
                            .onFailure { host.fail(it, generic) }
                        busy = false
                    }
                },
                enabled = !empty && !badMoney && !clicksTooMany, loading = busy,
            )
        }
    }
    if (pickDate) {
        CalDatePickerDialog(date, onDismiss = { pickDate = false }) { picked -> date = if (picked.isAfter(LocalDate.now())) LocalDate.now() else picked; pickDate = false }
    }
}

// =============================================================================== executive report (U12)

/** Today's decisions, the period's results against the one before, risks and next steps — built by rules, opens without the AI. */
@Composable
fun MarketingReport(projectId: String) {
    val host = LocalMk.current
    val generic = stringResource(R.string.mktg_error_generic)
    val reconnect = collectReconnectTick()
    var period by remember { mutableStateOf("week") }
    var report by remember { mutableStateOf<MkReportDto?>(null) }
    var failed by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    val relevantChange = if (host.changedProject == null || host.changedProject == projectId) host.changes else -1

    LaunchedEffect(projectId, period, reload, reconnect, relevantChange) {
        runCatching { ApiClient.marketing.report(mkAuth(), projectId, period).data }
            .onSuccess { report = it; failed = false }
            .onFailure { failed = report == null; host.fail(it, generic) }
    }

    WaPage2(title = stringResource(R.string.mktg_report)) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf("day" to R.string.mktg_period_day, "week" to R.string.mktg_period_week, "month" to R.string.mktg_period_month).forEach { (key, label) ->
                WeChip(stringResource(label), selected = period == key, onClick = { if (period != key) { period = key; report = null } })
            }
        }
        val r = report
        if (r == null) {
            Spacer(Modifier.height(12.dp))
            if (failed) {
                WaEmpty(Icons.Rounded.AutoGraph, Tone.Gray, generic, "", action = { WaButton(stringResource(R.string.mktg_retry), { reload++ }, style = WaButtonStyle.Ghost, compact = true) })
            } else {
                repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(96.dp).padding(bottom = 12.dp), RoundedCornerShape(22.dp)) }
            }
            return@WaPage2
        }
        Spacer(Modifier.height(6.dp))
        Text(shortDate(r.from) + " – " + shortDate(r.to), color = Wa.Mut, fontSize = 12.5.sp, modifier = Modifier.padding(horizontal = 4.dp))

        WaSectionTitle(stringResource(R.string.mktg_decisions))
        ReportLines(r.decisions, Icons.Rounded.TaskAlt, stringResource(R.string.mktg_nothing_to_decide)) { line ->
            line.itemId?.let { host.push(MkRoute.Content(projectId, it)) }
        }

        WaSectionTitle(stringResource(R.string.mktg_results), trailing = { MkSmallTag(stringResource(R.string.mktg_basis_imported), Wa.Green) })
        if (r.results.daysWithData == 0) {
            Text(stringResource(R.string.mktg_no_results_sub), color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.padding(horizontal = 4.dp))
        } else {
            WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
                listOf(
                    "spend_minor" to R.string.mktg_k_spend,
                    "revenue_minor" to R.string.mktg_k_revenue,
                    "conversions" to R.string.mktg_k_conversions,
                    "clicks" to R.string.mktg_k_clicks,
                ).forEachIndexed { i, (key, label) ->
                    if (i > 0) WaDivider()
                    val value = r.results.current.totals[key]
                    val change = r.results.changePct[key]
                    Row(Modifier.fillMaxWidth().padding(vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(stringResource(label), color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.weight(1f))
                        CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                            Text(if (key.endsWith("_minor")) mkMoney(value, r.currency) else value?.toString() ?: "—", color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                        }
                        if (change != null) {
                            Spacer(Modifier.width(8.dp))
                            val up = change >= 0
                            MkSmallTag((if (up) "▲ " else "▼ ") + "${kotlin.math.abs(change)}%", if (up) Wa.Green else Wa.Danger)
                        }
                    }
                }
            }
            Spacer(Modifier.height(4.dp))
            Text(stringResource(R.string.mktg_vs_previous), color = Wa.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(horizontal = 4.dp))
        }

        WaSectionTitle(stringResource(R.string.mktg_risks))
        ReportLines(r.risks, Icons.Rounded.WarningAmber, stringResource(R.string.mktg_no_risks))

        WaSectionTitle(stringResource(R.string.mktg_next_steps))
        ReportLines(r.recommendations, Icons.Rounded.Lightbulb, stringResource(R.string.mktg_nothing_to_suggest))

        r.note?.let {
            Spacer(Modifier.height(14.dp))
            Text(it, color = Wa.Soft, fontSize = 11.5.sp, lineHeight = 16.sp, modifier = Modifier.padding(horizontal = 4.dp))
        }
    }
}

@Composable
private fun ReportLines(lines: List<MkReportLineDto>, icon: androidx.compose.ui.graphics.vector.ImageVector, empty: String, onClick: ((MkReportLineDto) -> Unit)? = null) {
    if (lines.isEmpty()) {
        Text(empty, color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.padding(horizontal = 4.dp))
        return
    }
    WaCard(Modifier.fillMaxWidth(), padding = 6.dp) {
        lines.forEachIndexed { i, line ->
            if (i > 0) WaDivider()
            val color = when (line.level) {
                "high" -> Wa.Danger
                "medium", "action" -> MkAmber
                "low" -> Wa.Mut
                else -> MkBlue
            }
            Row(
                Modifier.fillMaxWidth().then(if (onClick != null && line.itemId != null) Modifier.clickable { onClick(line) } else Modifier).padding(horizontal = 10.dp, vertical = 12.dp),
                verticalAlignment = Alignment.Top,
            ) {
                Icon(icon, null, tint = color, modifier = Modifier.size(18.dp).padding(top = 1.dp))
                Spacer(Modifier.width(10.dp))
                Text(line.text, color = Wa.Ink, fontSize = 13.5.sp, lineHeight = 20.sp, modifier = Modifier.weight(1f))
            }
        }
    }
}
