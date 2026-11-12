package com.dorr.freelance.ui.screens

import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material3.Icon
import androidx.compose.material.icons.rounded.Apps
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Person
import androidx.compose.runtime.collectAsState
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.PlayCircle
import com.dorr.freelance.ui.components.SkeletonList
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Handyman
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.width
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.MyProjectDto
import com.dorr.freelance.net.PagedRows
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.PagedFooter
import com.dorr.freelance.ui.components.TabPage
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface
import kotlinx.coroutines.launch

private val groups = listOf("all", "active", "completed", "disputes", "closed")

/**
 * My work (a tab): the projects I was chosen for - the ones I am working on, the finished ones and the ones that ended without being done -
 * newest first, fifteen at a time while I scroll. Read when the tab opens, when a notice arrives and when the connection returns.
 */
@Composable
fun MyWorkTab(onBack: () -> Unit, onOpenProject: (String) -> Unit, onOpenAgreement: (String) -> Unit = onOpenProject, embedded: Boolean = false) {
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val scope = rememberCoroutineScope()
    var group by rememberSaveable { mutableStateOf("all") }
    // FS-209b: «Me | My team» - the team's projects I reach, next to the ones I signed myself (only where the teams switch is on and I am in one).
    val home by com.dorr.freelance.net.HomeHub.home.collectAsState()
    val teamsOn = home?.on("teams") == true
    var teams by remember { mutableStateOf<List<com.dorr.freelance.net.TeamSummaryDto>>(emptyList()) }
    var scopeKey by rememberSaveable { mutableStateOf("me") }
    var teamPick by rememberSaveable { mutableStateOf<String?>(null) }
    val paged = remember(group) { PagedRows<MyProjectDto>(15, { it.uuid }, { page -> apiCall { ApiClient.agreements.myProjects(page = page, perPage = 15, group = group) } }) }

    LaunchedEffect(reconnect, group) { paged.reload() }
    LaunchedEffect(group) { Realtime.changed.collect { paged.reload() } }
    suspend fun loadTeams() {
        if (!teamsOn) { teams = emptyList(); return }
        (apiCall { ApiClient.teams.mine() } as? com.dorr.core.network.ApiResult.Ok)?.data?.let { d -> teams = d.teams.filter { it.status == "active" } }
    }
    LaunchedEffect(teamsOn, reconnect) { loadTeams() }
    LaunchedEffect(teamsOn) { Realtime.changed.collect { loadTeams() } }
    val team = teams.firstOrNull { it.uuid == teamPick } ?: teams.firstOrNull()
    val teamScope = scopeKey == "team" && team != null

    TabPage(title = stringResource(R.string.work_title), onBack = onBack, onNearEnd = { scope.launch { paged.loadMore() } }, embedded = embedded) {
        if (teams.isNotEmpty()) com.dorr.freelance.ui.kit.SegmentTabs(
            listOf(
                com.dorr.freelance.ui.kit.Segment("me", stringResource(R.string.tw_scope_me), Icons.Rounded.Person),
                com.dorr.freelance.ui.kit.Segment("team", stringResource(R.string.tw_scope_team), Icons.Rounded.Groups),
            ),
            if (teamScope) "team" else "me", { scopeKey = it },
        )
        if (teamScope && team != null) {
            if (teams.size > 1) com.dorr.freelance.ui.kit.FilterChips(teams.map { com.dorr.freelance.ui.kit.FilterOption(it.uuid, it.name) }, team.uuid, { teamPick = it })
            TeamProjectsList(teamUuid = team.uuid, role = team.role, onOpenProject = onOpenAgreement)
            return@TabPage
        }
        com.dorr.freelance.ui.kit.FilterChips(groups.map { com.dorr.freelance.ui.kit.FilterOption(it, stringResource(workGroupLabel(it)), workGroupIcon(it)) }, group, { group = it })
        when {
            !paged.loaded && paged.failure == null -> SkeletonList()
            paged.loaded && paged.rows.isEmpty() && paged.failure == null -> EmptyState(
                icon = Icons.Rounded.Handyman,
                title = stringResource(workEmptyTitle(group)),
                body = stringResource(workEmptyBody(group)),
                modifier = Modifier.padding(top = 90.dp),
            )
            else -> paged.rows.toList().forEach { p -> MyProjectCard(p) { p.project.uuid?.let(onOpenProject) } }
        }
        PagedFooter(paged) { scope.launch { paged.loadMore() } }
        ErrorBanner(paged.failure?.let { it.errorText(offlineText) ?: genericError }, Modifier.fillMaxWidth())
    }
}

private fun workGroupIcon(group: String) = when (group) {
    "active" -> Icons.Rounded.PlayCircle
    "completed" -> Icons.Rounded.CheckCircle
    "disputes" -> Icons.Rounded.Gavel
    "closed" -> Icons.Rounded.Block
    else -> Icons.Rounded.Apps
}

private fun workGroupLabel(group: String) = when (group) {
    "all" -> R.string.work_all
    "disputes" -> R.string.work_disputes
    "completed" -> R.string.work_completed
    "closed" -> R.string.work_closed
    else -> R.string.work_active
}

private fun workEmptyTitle(group: String) = when (group) {
    "all" -> R.string.work_empty_all_title
    "disputes" -> R.string.work_empty_disputes_title
    "completed" -> R.string.work_empty_completed_title
    "closed" -> R.string.work_empty_closed_title
    else -> R.string.work_empty_active_title
}

private fun workEmptyBody(group: String) = when (group) {
    "all" -> R.string.work_empty_all_body
    "disputes" -> R.string.work_empty_disputes_body
    "completed" -> R.string.work_empty_completed_body
    "closed" -> R.string.work_empty_closed_body
    else -> R.string.work_empty_active_body
}

internal fun workStatusColor(status: String): Color = when (status) {
    "active" -> Color(0xFF2563EB)
    "completed" -> Color(0xFF16A34A)
    "pending_approval" -> Color(0xFFD97706)
    else -> Color(0xFF6B7280)
}

@Composable
internal fun workStatusLabel(status: String): String = stringResource(
    when (status) {
        "active" -> R.string.work_status_active
        "completed" -> R.string.work_status_completed
        "pending_approval" -> R.string.work_status_pending
        "declined" -> R.string.work_status_declined
        "cancelled" -> R.string.work_status_cancelled
        else -> R.string.work_status_expired
    },
)

/**
 * One project I was chosen for: its title and state (and a dispute when there is one), the price and the days, and how many of its parts are
 * done on a bar in the state's colour; the colour also runs down the card's starting edge.
 */
@Composable
private fun MyProjectCard(p: MyProjectDto, onClick: () -> Unit) {
    val tint = workStatusColor(p.status)
    val red = Color(0xFFDC2626)
    Row(Modifier.fillMaxWidth().height(androidx.compose.foundation.layout.IntrinsicSize.Min).settingsSurface(RoundedCornerShape(20.dp)).clickable(onClick = onClick)) {
        Box(Modifier.width(5.dp).fillMaxHeight().background(tint))
        Column(Modifier.weight(1f).padding(start = 12.dp, end = 14.dp, top = 14.dp, bottom = 14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(verticalAlignment = Alignment.Top) {
                Text(p.project.title.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis, lineHeight = 21.sp, modifier = Modifier.weight(1f))
                Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(20.dp))
            }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                com.dorr.freelance.ui.kit.StatusPill(workStatusLabel(p.status), tint)
                if (p.hasDispute) com.dorr.freelance.ui.kit.StatusPill(stringResource(R.string.work_dispute_badge), red, Icons.Rounded.Gavel)
            }
            Row(horizontalArrangement = Arrangement.spacedBy(16.dp), verticalAlignment = Alignment.CenterVertically) {
                com.dorr.freelance.ui.kit.FactLine(Icons.Rounded.Payments, "‎${formatMinor(p.priceMinor, null)} ${p.currency}", strong = true)
                com.dorr.freelance.ui.kit.FactLine(Icons.Rounded.Schedule, stringResource(R.string.projects_days, p.durationDays))
            }
            if (p.milestonesCount > 0) {
                val fraction = p.milestonesDone.toFloat() / p.milestonesCount
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(stringResource(R.string.work_parts_done, p.milestonesDone, p.milestonesCount), color = settingsMut(), fontSize = 11.5.sp, modifier = Modifier.weight(1f))
                        Text("‎${(fraction * 100).toInt()}%", color = tint, fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold)
                    }
                    Box(Modifier.fillMaxWidth().height(6.dp).clip(RoundedCornerShape(999.dp)).background(settingsMut().copy(alpha = 0.18f))) {
                        Box(Modifier.fillMaxWidth(fraction).height(6.dp).clip(RoundedCornerShape(999.dp)).background(tint))
                    }
                }
            }
        }
    }
}
