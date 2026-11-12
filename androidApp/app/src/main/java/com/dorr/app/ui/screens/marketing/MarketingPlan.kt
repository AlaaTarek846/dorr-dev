@file:OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)

package com.dorr.app.ui.screens.marketing

import android.content.Context
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Archive
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Calculate
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.PictureAsPdf
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.WarningAmber
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MkCriticDto
import com.dorr.app.network.MkPlanDto
import com.dorr.app.network.MkPlanRequest
import com.dorr.app.network.MkProjectDto
import com.dorr.app.network.MkVersionDto
import com.dorr.app.network.MkVersionRequest
import com.dorr.app.network.MkVersionRowDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.chat.ChSheet
import com.dorr.app.ui.screens.chat.shareFile
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaDivider
import com.dorr.app.ui.screens.wallet.WaKeyValue
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.watchearn.WeConfirmDialog
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import com.google.gson.JsonPrimitive
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File

/**
 * One plan (U04/U05): its current version as the type lays it out, the critic's notes, and the steps its status
 * allows — send for review, approve this exact version (owner or manager), back to draft, edit (a new version), finance,
 * versions, PDF/Word, write again with AI, archive.
 */
@Composable
fun MarketingPlanPage(planId: String) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val generic = stringResource(R.string.mktg_error_generic)
    val exportFailed = stringResource(R.string.mktg_export_failed)
    val started = stringResource(R.string.mktg_plan_started)
    val reconnect = collectReconnectTick()

    var plan by remember { mutableStateOf<MkPlanDto?>(null) }
    var project by remember { mutableStateOf<MkProjectDto?>(null) }
    var projectId by remember { mutableStateOf<String?>(null) }
    var reload by remember { mutableIntStateOf(0) }
    var busy by remember { mutableStateOf<String?>(null) }
    var editing by remember { mutableStateOf(false) }
    var showVersions by remember { mutableStateOf(false) }
    var viewing by remember { mutableStateOf<MkVersionDto?>(null) }
    var confirmApprove by remember { mutableStateOf(false) }

    LaunchedEffect(planId, reload, reconnect, host.changes) {
        runCatching { ApiClient.marketing.plan(mkAuth(), planId).data }
            .onSuccess { plan = it }
            .onFailure { host.fail(it, generic) }
    }
    // The project, for the abilities and the currency: the plan page is opened from the project board.
    LaunchedEffect(Unit) {
        val board = host.stack.lastOrNull { it is MkRoute.Project } as? MkRoute.Project
        projectId = board?.id
        if (board != null) project = runCatching { ApiClient.marketing.project(mkAuth(), board.id).data }.getOrNull()
    }

    val p = plan
    val version = viewing ?: p?.currentVersion
    val abilities = project?.abilities
    val isCurrent = viewing == null

    fun act(key: String, call: suspend () -> MkPlanDto?) {
        busy = key
        scope.launch {
            runCatching { call() }
                .onSuccess { if (it != null) plan = it }
                .onFailure { host.fail(it, generic) }
            busy = null
        }
    }

    WaPage2(title = p?.let { stringResource(mkPlanLabel(it.type)) } ?: stringResource(R.string.mktg_plans)) {
        if (p == null || version == null) {
            repeat(4) { WaSkeleton(Modifier.fillMaxWidth().height(110.dp).padding(bottom = 12.dp), RoundedCornerShape(22.dp)) }
            return@WaPage2
        }

        // Status and approval.
        WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(version.content.str("title") ?: stringResource(mkPlanLabel(p.type)), color = Wa.Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 23.sp)
                    val origin = stringResource(if (version.origin == "edit") R.string.mktg_origin_edit else R.string.mktg_origin_ai)
                    Text(stringResource(R.string.mktg_version, version.version) + " · $origin · " + mkDate(version.createdAt), color = Wa.Mut, fontSize = 12.sp)
                }
                MkStatusBadge(if (isCurrent) p.status else "SUPERSEDED")
            }
            val approval = p.approval
            if (approval != null && isCurrent) {
                Spacer(Modifier.height(10.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    androidx.compose.material3.Icon(Icons.Rounded.Verified, null, tint = Wa.Green, modifier = Modifier.padding(end = 6.dp))
                    Text(stringResource(R.string.mktg_approved_on, approval.version, mkDate(approval.approvedAt)), color = Wa.Green, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                }
            }
            if (!isCurrent) {
                Spacer(Modifier.height(10.dp))
                WaButton(stringResource(R.string.mktg_close), { viewing = null }, style = WaButtonStyle.Ghost, compact = true)
            }
        }

        // Workflow steps for the current version.
        if (isCurrent && abilities != null) {
            Spacer(Modifier.height(12.dp))
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                when (p.status) {
                    "DRAFT" -> if (abilities.edit) WaButton(
                        stringResource(R.string.mktg_submit),
                        { act("submit") { ApiClient.marketing.submitPlan(mkAuth(), planId, MkVersionRequest(version.version)).data } },
                        loading = busy == "submit",
                        enabled = version.critic.none { it.severity == "blocking" },
                    )
                    "READY_FOR_REVIEW" -> if (abilities.approve) {
                        WaButton(stringResource(R.string.mktg_approve, version.version), { confirmApprove = true }, icon = Icons.Rounded.Verified, loading = busy == "approve")
                        WaButton(
                            stringResource(R.string.mktg_return),
                            { act("return") { ApiClient.marketing.returnPlan(mkAuth(), planId, MkVersionRequest(version.version)).data } },
                            style = WaButtonStyle.Ghost, loading = busy == "return",
                        )
                    }
                }
            }
        }

        // The critic.
        if (version.critic.isNotEmpty()) {
            Spacer(Modifier.height(12.dp))
            CriticCard(version.critic)
        }

        Spacer(Modifier.height(12.dp))
        PlanContent(p.type, version.content)

        // Tools.
        Spacer(Modifier.height(16.dp))
        Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            MkNavCard(Icons.Rounded.Calculate, Tone.Green, stringResource(R.string.mktg_finance), stringResource(R.string.mktg_finance_sub)) {
                host.push(MkRoute.Finance(planId))
            }
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                listOf("pdf" to R.string.mktg_export_pdf, "docx" to R.string.mktg_export_docx).forEach { (format, label) ->
                    WaButton(
                        stringResource(label),
                        onClick = {
                            busy = format
                            scope.launch {
                                val file = runCatching { downloadPlan(context, planId, version.version, format) }.getOrNull()
                                busy = null
                                if (file == null) host.showToast(exportFailed, error = true)
                                else shareFile(context, file, if (format == "pdf") "application/pdf" else "application/vnd.openxmlformats-officedocument.wordprocessingml.document")
                            }
                        },
                        style = WaButtonStyle.Ghost, compact = true, loading = busy == format,
                        icon = if (format == "pdf") Icons.Rounded.PictureAsPdf else Icons.Rounded.Description,
                        modifier = Modifier.weight(1f),
                    )
                }
            }
            WaButton(stringResource(R.string.mktg_versions) + " (${p.versionsCount ?: version.version})", { showVersions = true }, style = WaButtonStyle.Ghost, compact = true, icon = Icons.Rounded.History)
            if (isCurrent && abilities?.edit == true && p.status != "ARCHIVED") {
                WaButton(stringResource(R.string.mktg_edit), { editing = true }, style = WaButtonStyle.Ghost, compact = true, icon = Icons.Rounded.Edit)
                val pid = projectId
                if (pid != null) {
                    WaButton(
                        stringResource(R.string.mktg_plan_rewrite),
                        onClick = {
                            busy = "rewrite"
                            scope.launch {
                                runCatching { ApiClient.marketing.generatePlan(mkAuth(), pid, MkPlanRequest(p.type)) }
                                    .onSuccess { host.showToast(started) }
                                    .onFailure { host.fail(it, generic) }
                                busy = null
                            }
                        },
                        style = WaButtonStyle.Quiet, compact = true, icon = Icons.Rounded.AutoAwesome, loading = busy == "rewrite",
                    )
                }
            }
            if (isCurrent && abilities?.manageTeam == true && p.status != "ARCHIVED") {
                WaButton(
                    stringResource(R.string.mktg_archive_plan),
                    { act("archive") { ApiClient.marketing.archivePlan(mkAuth(), planId).data } },
                    style = WaButtonStyle.Quiet, compact = true, icon = Icons.Rounded.Archive, loading = busy == "archive",
                )
            }
        }
    }

    if (confirmApprove && version != null) {
        WeConfirmDialog(
            title = stringResource(R.string.mktg_approve_confirm_title),
            text = stringResource(R.string.mktg_approve_confirm_text),
            confirm = stringResource(R.string.mktg_approve, version.version),
            onDismiss = { confirmApprove = false },
            onConfirm = {
                confirmApprove = false
                act("approve") { ApiClient.marketing.approvePlan(mkAuth(), planId, MkVersionRequest(version.version)).data }
            },
        )
    }
    if (editing && p?.currentVersion != null) {
        EditPlanSheet(planId, p.type, p.currentVersion, onDismiss = { editing = false }) { updated ->
            editing = false
            plan = updated
        }
    }
    if (showVersions) {
        VersionsSheet(planId, onDismiss = { showVersions = false }) { picked ->
            showVersions = false
            viewing = if (picked.version == p?.currentVersion?.version) null else picked
        }
    }
}

@Composable
private fun CriticCard(critic: List<MkCriticDto>) {
    WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            androidx.compose.material3.Icon(Icons.Rounded.WarningAmber, null, tint = androidx.compose.ui.graphics.Color(0xFFD97706))
            Spacer(Modifier.padding(start = 8.dp))
            Text(stringResource(R.string.mktg_issues), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
        }
        Spacer(Modifier.height(8.dp))
        critic.distinctBy { it.code }.forEach { c ->
            Row(Modifier.padding(vertical = 3.dp)) {
                Text("•", color = if (c.severity == "blocking") Wa.Danger else androidx.compose.ui.graphics.Color(0xFFD97706), fontWeight = FontWeight.Black)
                Spacer(Modifier.padding(start = 8.dp))
                Column(Modifier.weight(1f)) {
                    Text(criticText(c.code), color = Wa.Ink, fontSize = 13.sp, lineHeight = 20.sp)
                    if (c.severity == "blocking") Text(stringResource(R.string.mktg_issue_blocking), color = Wa.Danger, fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

@Composable
private fun criticText(code: String): String = stringResource(
    when (code) {
        "budget_missing" -> R.string.mktg_issue_budget_missing
        "invented_budget_removed" -> R.string.mktg_issue_invented_budget_removed
        "unknown_sources_removed" -> R.string.mktg_issue_unknown_sources_removed
        "budget_share_over_100" -> R.string.mktg_issue_budget_share_over_100
        "invalid_phase_range" -> R.string.mktg_issue_invalid_phase_range
        "overlapping_phases" -> R.string.mktg_issue_overlapping_phases
        "phase_without_go_no_go" -> R.string.mktg_issue_phase_without_go_no_go
        else -> R.string.mktg_issue_numbers_without_sources
    },
)

/** The version's content, laid out by plan type; every list is shown only when it has items. */
@Composable
private fun PlanContent(type: String, c: JsonObject) {
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        c.str("summary")?.let { MkBlock(stringResource(R.string.mktg_summary)) { Text(it, color = Wa.Ink, fontSize = 14.sp, lineHeight = 22.sp) } }
        when (type) {
            "business" -> c.objects("sections").forEach { s ->
                MkBlock(s.str("title") ?: s.str("key").orEmpty()) { Text(s.str("body").orEmpty(), color = Wa.Ink, fontSize = 13.5.sp, lineHeight = 22.sp) }
            }
            "marketing" -> {
                c.str("positioning")?.let { MkBlock(stringResource(R.string.mktg_positioning)) { Text(it, color = Wa.Ink, fontSize = 13.5.sp, lineHeight = 22.sp) } }
                c.objects("segments").takeIf { it.isNotEmpty() }?.let { rows ->
                    MkBlock(stringResource(R.string.mktg_segments)) {
                        rows.forEach { r ->
                            Text(r.str("name").orEmpty(), color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
                            r.str("description")?.let { Text(it, color = Wa.Mut, fontSize = 13.sp, lineHeight = 20.sp) }
                        }
                    }
                }
                c.objects("channels").takeIf { it.isNotEmpty() }?.let { rows ->
                    MkBlock(stringResource(R.string.mktg_channels)) {
                        rows.forEachIndexed { i, r ->
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(r.str("channel").orEmpty(), color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                                r.get("budget_share_pct")?.takeIf { it.isJsonPrimitive }?.asDouble?.let { MkSmallTag("${it.toInt()}%", Wa.Red) }
                            }
                            r.str("rationale")?.let { Text(it, color = Wa.Mut, fontSize = 13.sp, lineHeight = 20.sp) }
                            if (i < rows.lastIndex) WaDivider()
                        }
                    }
                }
                c.strings("messages").takeIf { it.isNotEmpty() }?.let { MkBlock(stringResource(R.string.mktg_messages)) { MkBullets(it) } }
                c.objects("kpis").takeIf { it.isNotEmpty() }?.let { rows ->
                    MkBlock(stringResource(R.string.mktg_kpis)) { rows.forEach { r -> WaKeyValue(r.str("name").orEmpty(), r.str("target").orEmpty()) } }
                }
            }
            else -> {
                c.int("horizon_days")?.let { days ->
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        MkSmallTag(stringResource(R.string.mktg_horizon, days), Wa.Red)
                        c.strings("channels").take(4).forEach { MkSmallTag(it, Wa.Mut) }
                    }
                }
                c.objects("phases").forEach { phase ->
                    val title = listOfNotNull(
                        phase.int("day_from")?.let { from -> phase.int("day_to")?.let { to -> stringResource(R.string.mktg_phase_days, from, to) } },
                        phase.str("title"),
                    ).joinToString(" · ")
                    MkBlock(title) {
                        phase.strings("tasks").takeIf { it.isNotEmpty() }?.let {
                            Text(stringResource(R.string.mktg_tasks), color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                            MkBullets(it)
                        }
                        phase.strings("kpis").takeIf { it.isNotEmpty() }?.let {
                            Text(stringResource(R.string.mktg_kpis), color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                            MkBullets(it)
                        }
                        phase.strings("go_no_go_criteria").takeIf { it.isNotEmpty() }?.let {
                            Text(stringResource(R.string.mktg_go_no_go), color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                            MkBullets(it)
                        }
                    }
                }
            }
        }
        c.strings("assumptions").takeIf { it.isNotEmpty() }?.let { MkBlock(stringResource(R.string.mktg_assumptions)) { MkBullets(it) } }
        c.strings("risks").takeIf { it.isNotEmpty() }?.let { MkBlock(stringResource(R.string.mktg_risks)) { MkBullets(it) } }
        c.strings("confidence_notes").takeIf { it.isNotEmpty() }?.let { MkBlock(stringResource(R.string.mktg_confidence_notes)) { MkBullets(it, Wa.Mut) } }
        c.strings("sources").takeIf { it.isNotEmpty() }?.let {
            Text(stringResource(R.string.mktg_cited_sources, it.joinToString(", ")), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.padding(horizontal = 4.dp))
        }
    }
}

/**
 * Edits what a person writes on a phone: title, summary, assumptions, risks (one per line) — and for a GTM plan
 * each phase's tasks. The rest of the content is sent back unchanged. Saving is a new version.
 */
@Composable
private fun EditPlanSheet(planId: String, type: String, version: MkVersionDto, onDismiss: () -> Unit, onSaved: (MkPlanDto) -> Unit) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val saved = stringResource(R.string.mktg_saved)
    val c = version.content
    var title by remember { mutableStateOf(c.str("title").orEmpty()) }
    var summary by remember { mutableStateOf(c.str("summary").orEmpty()) }
    var assumptions by remember { mutableStateOf(c.strings("assumptions").joinToString("\n")) }
    var risks by remember { mutableStateOf(c.strings("risks").joinToString("\n")) }
    val phases = c.objects("phases")
    val phaseTasks = remember { mutableStateListOf(*phases.map { it.strings("tasks").joinToString("\n") }.toTypedArray()) }
    var busy by remember { mutableStateOf(false) }

    fun lines(text: String) = JsonArray().apply { text.lines().map { it.trim() }.filter { it.isNotEmpty() }.distinct().forEach { add(it) } }

    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(
            Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).imePadding().navigationBarsPadding()
                .padding(horizontal = 20.dp).padding(bottom = 20.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(R.string.mktg_edit_plan), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            WaNote(stringResource(R.string.mktg_edit_note))
            DorrTextField(value = title, onValueChange = { title = it.take(200) }, label = stringResource(R.string.mktg_title_field))
            DorrTextField(value = summary, onValueChange = { summary = it.take(4000) }, label = stringResource(R.string.mktg_summary), singleLine = false, minLines = 3)
            if (type == "gtm") {
                phases.forEachIndexed { i, phase ->
                    DorrTextField(
                        value = phaseTasks[i],
                        onValueChange = { phaseTasks[i] = it },
                        label = (phase.str("title") ?: "") + " · " + stringResource(R.string.mktg_tasks),
                        placeholder = stringResource(R.string.mktg_one_per_line),
                        singleLine = false, minLines = 2,
                    )
                }
            }
            DorrTextField(value = assumptions, onValueChange = { assumptions = it }, label = stringResource(R.string.mktg_assumptions), placeholder = stringResource(R.string.mktg_one_per_line), singleLine = false, minLines = 2)
            DorrTextField(value = risks, onValueChange = { risks = it }, label = stringResource(R.string.mktg_risks), placeholder = stringResource(R.string.mktg_one_per_line), singleLine = false, minLines = 2)
            WaButton(
                stringResource(R.string.mktg_save),
                onClick = {
                    val content = c.deepCopy().apply {
                        addProperty("title", title.trim())
                        addProperty("summary", summary.trim())
                        add("assumptions", lines(assumptions))
                        add("risks", lines(risks))
                        if (type == "gtm") {
                            val arr = JsonArray()
                            phases.forEachIndexed { i, ph -> arr.add(ph.deepCopy().apply { add("tasks", lines(phaseTasks[i])) }) }
                            add("phases", arr)
                        }
                    }
                    busy = true
                    scope.launch {
                        val body = JsonObject().apply {
                            add("version", JsonPrimitive(version.version))
                            add("content", content)
                        }
                        runCatching { ApiClient.marketing.editPlan(mkAuth(), planId, body).data }
                            .onSuccess { if (it != null) { host.showToast(saved); onSaved(it) } }
                            .onFailure { host.fail(it, generic) }
                        busy = false
                    }
                },
                enabled = title.isNotBlank() && summary.isNotBlank(), loading = busy,
            )
        }
    }
}

@Composable
private fun VersionsSheet(planId: String, onDismiss: () -> Unit, onPick: (MkVersionDto) -> Unit) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    var rows by remember { mutableStateOf<List<MkVersionRowDto>?>(null) }
    LaunchedEffect(planId) {
        runCatching { ApiClient.marketing.versions(mkAuth(), planId).data }.onSuccess { rows = it.orEmpty().reversed() }.onFailure { host.fail(it, generic) }
    }
    ChSheet(onDismissRequest = onDismiss, containerColor = Wa.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).navigationBarsPadding().padding(horizontal = 20.dp).padding(bottom = 20.dp)) {
            Text(stringResource(R.string.mktg_versions), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(10.dp))
            val list = rows
            if (list == null) {
                repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(52.dp).padding(bottom = 8.dp)) }
            } else {
                list.forEachIndexed { i, v ->
                    val origin = stringResource(if (v.origin == "edit") R.string.mktg_origin_edit else R.string.mktg_origin_ai)
                    MkNavCard(Icons.Rounded.History, Tone.Gray, stringResource(R.string.mktg_version, v.version), "$origin · ${mkDate(v.createdAt)}", Modifier.padding(bottom = 8.dp)) {
                        scope.launch {
                            runCatching { ApiClient.marketing.version(mkAuth(), planId, v.version).data }
                                .onSuccess { if (it != null) onPick(it) }
                                .onFailure { host.fail(it, generic) }
                        }
                    }
                    if (i == 0) Spacer(Modifier.height(2.dp))
                }
            }
        }
    }
}

/** Downloads one version's PDF / Word into the cache's `exports/` folder (the one the FileProvider shares). */
private suspend fun downloadPlan(context: Context, planId: String, version: Int, format: String): File? = withContext(Dispatchers.IO) {
    val response = ApiClient.marketing.export(mkAuth(), planId, version, format)
    val body = response.body()
    if (!response.isSuccessful || body == null) return@withContext null
    val dir = File(context.cacheDir, "exports").apply { mkdirs() }
    File(dir, "DORR-plan-v$version.$format").also { out -> body.byteStream().use { input -> out.outputStream().use { input.copyTo(it) } } }
}
