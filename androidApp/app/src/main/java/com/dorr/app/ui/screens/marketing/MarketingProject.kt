package com.dorr.app.ui.screens.marketing

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Archive
import androidx.compose.material.icons.rounded.Assignment
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.AutoGraph
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Campaign
import androidx.compose.material.icons.rounded.Hub
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Insights
import androidx.compose.material.icons.rounded.QuestionAnswer
import androidx.compose.material.icons.rounded.Savings
import androidx.compose.material.icons.rounded.Science
import androidx.compose.material.icons.rounded.Summarize
import androidx.compose.material.icons.rounded.Unarchive
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MkArchiveRequest
import com.dorr.app.network.MkDiscoveryDto
import com.dorr.app.network.MkJobDto
import com.dorr.app.network.MkPlanDto
import com.dorr.app.network.MkPlanRequest
import com.dorr.app.network.MkProjectDto
import com.dorr.app.network.MkResearchRequest
import com.dorr.app.network.MkRunDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import com.dorr.app.ui.screens.watchearn.WeConfirmDialog
import kotlinx.coroutines.launch

/**
 * One project's board: the discovery progress, the research runs, the three plans and the team. Reloads once per
 * `marketing.job.changed` of this project and on reconnect (the catch-up), never on a timer.
 */
@Composable
fun MarketingProjectPage(projectId: String) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val reconnect = collectReconnectTick()

    var project by remember { mutableStateOf<MkProjectDto?>(null) }
    var discovery by remember { mutableStateOf<MkDiscoveryDto?>(null) }
    var runs by remember { mutableStateOf<List<MkRunDto>>(emptyList()) }
    var plans by remember { mutableStateOf<List<MkPlanDto>>(emptyList()) }
    var jobs by remember { mutableStateOf<List<MkJobDto>>(emptyList()) }
    var failed by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }
    val relevantChange = if (host.changedProject == null || host.changedProject == projectId) host.changes else -1

    LaunchedEffect(projectId, reload, reconnect, relevantChange) {
        runCatching {
            val api = ApiClient.marketing
            project = api.project(mkAuth(), projectId).data
            discovery = api.discovery(mkAuth(), projectId).data
            runs = api.researchRuns(mkAuth(), projectId).data.orEmpty()
            plans = api.plans(mkAuth(), projectId).data.orEmpty()
            jobs = api.jobs(mkAuth(), projectId).data.orEmpty()
            failed = false
        }.onFailure {
            if (project == null) failed = true
            host.fail(it, generic)
        }
    }

    var confirmDelete by remember { mutableStateOf(false) }
    val p = project

    WaPage2(title = p?.name ?: stringResource(R.string.mktg_title)) {
        when {
            p == null && failed -> WaEmpty(
                Icons.Rounded.Assignment, Tone.Gray, generic, "",
                action = { WaButton(stringResource(R.string.mktg_retry), { reload++ }, style = WaButtonStyle.Ghost, compact = true) },
            )
            p == null -> Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                repeat(4) { WaSkeleton(Modifier.fillMaxWidth().height(84.dp), RoundedCornerShape(22.dp)) }
            }
            else -> {
                val abilities = p.abilities
                ProjectHeader(p)

                // Discovery.
                Spacer(Modifier.height(12.dp))
                val d = discovery
                MkNavCard(
                    Icons.Rounded.QuestionAnswer, if (d?.complete == true) Tone.Green else Tone.Amber,
                    stringResource(R.string.mktg_discovery),
                    if (d == null) null else stringResource(if (d.complete) R.string.mktg_discovery_done else R.string.mktg_discovery_missing),
                    Modifier.waRise(1),
                    trailing = { if (d != null) MkSmallTag("${d.answered}/${d.total}", if (d.complete) Wa.Green else MkTint.Amber) },
                ) { host.push(MkRoute.Discovery(projectId)) }

                // Research.
                WaSectionTitle(stringResource(R.string.mktg_research))
                if (abilities?.research == true && p.status == "active") {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
                        listOf("market", "competitor", "audience").forEach { kind ->
                            var busy by remember(kind) { mutableStateOf(false) }
                            val started = stringResource(R.string.mktg_research_started)
                            WaButton(
                                stringResource(mkKindLabel(kind)),
                                onClick = {
                                    busy = true
                                    scope.launch {
                                        runCatching { ApiClient.marketing.startResearch(mkAuth(), projectId, MkResearchRequest(kind)) }
                                            .onSuccess { host.showToast(started); reload++ }
                                            .onFailure { host.fail(it, generic) }
                                        busy = false
                                    }
                                },
                                style = WaButtonStyle.Ghost, compact = true, loading = busy, modifier = Modifier.weight(1f),
                            )
                        }
                    }
                    Spacer(Modifier.height(10.dp))
                }
                if (runs.isEmpty()) {
                    Text(stringResource(R.string.mktg_no_research), color = Wa.Mut, fontSize = 13.sp, lineHeight = 20.sp, modifier = Modifier.padding(horizontal = 4.dp))
                } else {
                    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        runs.take(6).forEach { run -> RunRow(run, jobs.firstOrNull { it.type == "research" && !it.finished }) { host.push(MkRoute.Research(run.id)) } }
                    }
                }

                // Plans.
                WaSectionTitle(stringResource(R.string.mktg_plans))
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    listOf("business", "marketing", "gtm").forEach { type ->
                        val plan = plans.firstOrNull { it.type == type }
                        val job = jobs.firstOrNull { it.type == "plan" && it.planType == type }
                        PlanRow(projectId, type, plan, job, canWrite = abilities?.edit == true && p.status == "active", onChanged = { reload++ })
                    }
                }

                // Execution (phase B): calendar, ad studio, budgets, results, report — each behind its country switch.
                val features = host.status?.features
                fun on(key: String) = features?.get(key) != false
                WaSectionTitle(stringResource(R.string.mktg_execution))
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    if (on("calendar")) MkNavCard(Icons.Rounded.CalendarMonth, Tone.Blue, stringResource(R.string.mktg_calendar), stringResource(R.string.mktg_calendar_sub)) { host.push(MkRoute.Calendar(projectId)) }
                    if (on("content")) MkNavCard(Icons.Rounded.AutoAwesome, Tone.Pink, stringResource(R.string.mktg_studio), stringResource(R.string.mktg_studio_sub)) { host.push(MkRoute.Studio(projectId)) }
                    if (on("budgets")) MkNavCard(Icons.Rounded.Savings, Tone.Green, stringResource(R.string.mktg_budgets), stringResource(R.string.mktg_budgets_sub)) { host.push(MkRoute.Budgets(projectId)) }
                    if (on("results")) {
                        MkNavCard(Icons.Rounded.AutoGraph, Tone.Amber, stringResource(R.string.mktg_results), stringResource(R.string.mktg_results_sub)) { host.push(MkRoute.Results(projectId)) }
                        MkNavCard(Icons.Rounded.Summarize, Tone.Red, stringResource(R.string.mktg_report), stringResource(R.string.mktg_report_sub)) { host.push(MkRoute.Report(projectId)) }
                    }
                    if (on("campaigns")) MkNavCard(Icons.Rounded.Campaign, Tone.Pink, stringResource(R.string.mktg_campaigns), stringResource(R.string.mktg_campaigns_sub)) { host.push(MkRoute.Campaigns(projectId)) }
                    if (on("platforms")) MkNavCard(Icons.Rounded.Hub, Tone.Blue, stringResource(R.string.mktg_platforms), stringResource(R.string.mktg_platforms_sub)) { host.push(MkRoute.Platforms(projectId)) }
                    if (on("optimisation")) MkNavCard(Icons.Rounded.Science, Tone.Green, stringResource(R.string.mktg_optimise), stringResource(R.string.mktg_optimise_sub)) { host.push(MkRoute.Optimise(projectId)) }
                }

                // Team.
                WaSectionTitle(stringResource(R.string.mktg_team))
                MkNavCard(
                    Icons.Rounded.Groups, Tone.Blue, stringResource(R.string.mktg_team),
                    stringResource(R.string.mktg_team_sub, p.membersCount ?: 1),
                    trailing = { MkSmallTag(stringResource(mkRoleLabel(p.myRole)), Wa.Red) },
                ) { host.push(MkRoute.Team(projectId)) }

                // The owner's project actions.
                if (abilities?.manageTeam == true) {
                    Spacer(Modifier.height(18.dp))
                    var archiving by remember { mutableStateOf(false) }
                    val archived = p.status == "archived"
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        WaButton(
                            stringResource(if (archived) R.string.mktg_unarchive else R.string.mktg_archive),
                            onClick = {
                                archiving = true
                                scope.launch {
                                    runCatching { ApiClient.marketing.archiveProject(mkAuth(), projectId, MkArchiveRequest(!archived)).data }
                                        .onSuccess { project = it; host.reloadStatus() }
                                        .onFailure { host.fail(it, generic) }
                                    archiving = false
                                }
                            },
                            style = WaButtonStyle.Ghost, compact = true, loading = archiving,
                            icon = if (archived) Icons.Rounded.Unarchive else Icons.Rounded.Archive, modifier = Modifier.weight(1f),
                        )
                        WaButton(
                            stringResource(R.string.mktg_delete), { confirmDelete = true },
                            style = WaButtonStyle.Quiet, compact = true, icon = Icons.Rounded.Delete, modifier = Modifier.weight(1f),
                        )
                    }
                }
            }
        }
    }

    if (confirmDelete) {
        val deleted = stringResource(R.string.mktg_deleted)
        WeConfirmDialog(
            title = stringResource(R.string.mktg_delete_confirm_title),
            text = stringResource(R.string.mktg_delete_confirm_text),
            confirm = stringResource(R.string.mktg_delete),
            danger = true,
            onDismiss = { confirmDelete = false },
            onConfirm = {
                confirmDelete = false
                scope.launch {
                    runCatching { ApiClient.marketing.deleteProject(mkAuth(), projectId) }
                        .onSuccess { host.showToast(deleted); host.reloadStatus(); host.open(MkRoute.Home) }
                        .onFailure { host.fail(it, generic) }
                }
            },
        )
    }
}

/** The page frame every marketing page uses (the wallet's page, back on the reading side). */
@Composable
internal fun WaPage2(
    title: String,
    cta: (@Composable androidx.compose.foundation.layout.ColumnScope.() -> Unit)? = null,
    content: @Composable androidx.compose.foundation.layout.ColumnScope.() -> Unit,
) {
    val host = LocalMk.current
    com.dorr.app.ui.screens.wallet.WaPage(title = title, onBack = { host.pop() }, cta = cta, content = content)
}

@Composable
private fun ProjectHeader(p: MkProjectDto) {
    WaCard(Modifier.fillMaxWidth().waRise(0), padding = 16.dp) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(p.industry, color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                val place = listOfNotNull(p.cities.joinToString("، ").takeIf { it.isNotBlank() }, stringResource(mkStageLabel(p.stage))).joinToString(" · ")
                Text(place, color = Wa.Mut, fontSize = 12.5.sp)
            }
            if (p.status == "archived") MkSmallTag(stringResource(R.string.mktg_archived_badge), Wa.Mut)
        }
        val budget = p.profile?.monthlyBudgetMinor
        if (budget != null) {
            Spacer(Modifier.height(10.dp))
            Text(mkMoney(budget, p.currency), color = Wa.Red, fontSize = 16.sp, fontWeight = FontWeight.Black)
        }
    }
}

@Composable
private fun RunRow(run: MkRunDto, job: MkJobDto?, onClick: () -> Unit) {
    val subtitle = if (run.finished) {
        stringResource(R.string.mktg_sources_count, run.sourcesCount ?: 0, run.findingsCount ?: 0)
    } else {
        stringResource(mkStatusLabel(run.status))
    }
    Column {
        MkNavCard(
            Icons.Rounded.Insights, Tone.Pink, stringResource(mkKindLabel(run.kind)), "$subtitle · ${mkDate(run.createdAt)}",
            trailing = { MkStatusBadge(run.status) }, onClick = onClick,
        )
        if (!run.finished && job != null) {
            Spacer(Modifier.height(6.dp))
            MkBar(job.progressPct / 100f, MkTint.Blue, Modifier.padding(horizontal = 18.dp))
        }
    }
}

/** One plan type: open it, or write it; while a job writes it, its progress; a failed job says why. */
@Composable
private fun PlanRow(projectId: String, type: String, plan: MkPlanDto?, job: MkJobDto?, canWrite: Boolean, onChanged: () -> Unit) {
    val host = LocalMk.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.mktg_error_generic)
    val started = stringResource(R.string.mktg_plan_started)
    var busy by remember { mutableStateOf(false) }
    val writing = job != null && !job.finished
    val version = plan?.currentVersion

    if (version != null && plan != null) {
        MkNavCard(
            Icons.Rounded.Assignment, Tone.Red, stringResource(mkPlanLabel(type)),
            if (writing) stringResource(R.string.mktg_plan_writing) else stringResource(R.string.mktg_version, version.version) + " · " + mkDate(version.createdAt),
            trailing = { MkStatusBadge(plan.status) },
        ) { host.push(MkRoute.Plan(plan.id)) }
    } else {
        WaCard(Modifier.fillMaxWidth(), padding = 14.dp) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(stringResource(mkPlanLabel(type)), color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold)
                    when {
                        writing -> Text(stringResource(R.string.mktg_plan_writing), color = Wa.Mut, fontSize = 12.5.sp)
                        job?.status == "FAILED" -> Text(stringResource(R.string.mktg_job_failed, job.errorCode.orEmpty()), color = Wa.Danger, fontSize = 12.sp)
                    }
                }
                if (canWrite && !writing) {
                    Spacer(Modifier.width(8.dp))
                    WaButton(
                        stringResource(if (job?.status == "FAILED") R.string.mktg_retry else R.string.mktg_plan_write),
                        onClick = {
                            busy = true
                            scope.launch {
                                runCatching { ApiClient.marketing.generatePlan(mkAuth(), projectId, MkPlanRequest(type)) }
                                    .onSuccess { host.showToast(started); onChanged() }
                                    .onFailure { host.fail(it, generic) }
                                busy = false
                            }
                        },
                        compact = true, loading = busy,
                    )
                }
            }
            if (writing) {
                Spacer(Modifier.height(10.dp))
                MkBar((job?.progressPct ?: 0) / 100f, Wa.Red)
            }
        }
    }
}

private object MkTint {
    val Amber = androidx.compose.ui.graphics.Color(0xFFD97706)
    val Blue = androidx.compose.ui.graphics.Color(0xFF2563EB)
}
