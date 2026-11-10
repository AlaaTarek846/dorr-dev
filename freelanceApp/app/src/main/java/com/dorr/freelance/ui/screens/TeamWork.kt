package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.AssignmentInd
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Handyman
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
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
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AssigneesRequest
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.TeamAccessDto
import com.dorr.freelance.net.TeamLoadDto
import com.dorr.freelance.net.TeamMemberDto
import com.dorr.freelance.net.TeamPersonRefDto
import com.dorr.freelance.net.TeamProjectDto
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.SkeletonList
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.FactLine
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.StatusPill
import com.dorr.freelance.ui.kit.appCard
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsCard
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.locale.LocaleAwareBottomSheet
import kotlinx.coroutines.launch

private val AMBER = Color(0xFFD97706)
private val RED = Color(0xFFDC2626)
private val GREEN = Color(0xFF16A34A)

/**
 * FS-209b: on the page of a team's project — whose team it is, my place in it and what that means here (the server already hides what my
 * place may not see, so this only explains why parts of the page are missing).
 */
@Composable
internal fun TeamAccessBanner(access: TeamAccessDto) {
    val accent = settingsAccent()
    Row(
        Modifier.fillMaxWidth().clip(Corners.card).background(accent.copy(alpha = 0.08f)).padding(14.dp),
        horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.Top,
    ) {
        Box(Modifier.size(40.dp).clip(RoundedCornerShape(12.dp)).background(accent.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Groups, contentDescription = null, tint = accent, modifier = Modifier.size(22.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(5.dp)) {
            Text(stringResource(R.string.tw_banner_title, access.team.name), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 20.sp)
            RolePill(access.role)
            val limits = when {
                access.signer -> R.string.tw_banner_signer
                access.role == "pm" -> R.string.tw_banner_pm
                access.role == "member" -> R.string.tw_banner_member
                else -> null
            }
            limits?.let { Text(stringResource(it), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp) }
            if (!access.can("money")) Text(stringResource(R.string.tw_banner_no_money), color = AMBER, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, lineHeight = 18.sp)
        }
    }
}

/**
 * The «Projects» part of a team: the team's projects I reach (every one for an Owner or a PM, the ones I am on for a member), each with my
 * place on it. A card opens the ordinary agreement page. Read when shown, when a notice arrives and when the connection returns.
 */
@Composable
internal fun TeamProjectsList(teamUuid: String, role: String?, onOpenProject: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var rows by remember(teamUuid) { mutableStateOf<List<TeamProjectDto>?>(null) }
    var failure by remember(teamUuid) { mutableStateOf<String?>(null) }

    suspend fun load() {
        val r = apiCall { ApiClient.teams.projects(teamUuid) }
        if (r is ApiResult.Ok) { rows = r.data; failure = null } else if (rows == null) failure = r.errorText(offlineText) ?: genericError
    }
    LaunchedEffect(teamUuid, reconnect) { load() }
    LaunchedEffect(teamUuid) { Realtime.changed.collect { load() } }

    val list = rows
    when {
        list == null && failure == null -> SkeletonList(count = 3)
        list == null -> ErrorBanner(failure, Modifier.fillMaxWidth())
        list.isEmpty() -> EmptyState(
            icon = Icons.Rounded.Handyman,
            title = stringResource(R.string.tw_projects_empty_title),
            body = stringResource(if (role == "member") R.string.tw_projects_empty_member else R.string.tw_projects_empty_body),
            modifier = Modifier.padding(top = 30.dp),
        )
        else -> list.forEach { p -> TeamProjectCard(p) { p.project.uuid?.let(onOpenProject) } }
    }
}

/** One of the team's projects: its title and state, my place on it, the price when my place may see it, and who signed. */
@Composable
private fun TeamProjectCard(p: TeamProjectDto, onClick: () -> Unit) {
    val tint = workStatusColor(p.agreement.status)
    Column(Modifier.fillMaxWidth().appCard(Corners.card).clickable(onClick = onClick).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.Top) {
            Text(p.project.title.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis, lineHeight = 21.sp, modifier = Modifier.weight(1f))
            Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(20.dp))
        }
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
            StatusPill(workStatusLabel(p.agreement.status), tint)
            RolePill(p.teamAccess.role)
        }
        Row(horizontalArrangement = Arrangement.spacedBy(16.dp), verticalAlignment = Alignment.CenterVertically) {
            p.agreement.priceMinor?.let { FactLine(Icons.Rounded.Payments, "‎${formatMinor(it, null)} ${p.agreement.currency}", strong = true) }
            p.signedBy?.name?.takeIf { !p.teamAccess.signer }?.let { FactLine(Icons.Rounded.Person, stringResource(R.string.tw_signed_by, it)) }
        }
    }
}

/**
 * The «Workload» part of a team: what each member carries on the team's running projects — the projects, the parts not over, and the tasks
 * (open, late, blocked, done). An Owner or a PM reads everybody; a member reads his own row only.
 */
@Composable
internal fun TeamWorkloadSection(teamUuid: String, role: String?) {
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var rows by remember(teamUuid) { mutableStateOf<List<TeamLoadDto>?>(null) }
    var failure by remember(teamUuid) { mutableStateOf<String?>(null) }

    suspend fun load() {
        val r = apiCall { ApiClient.teams.workload(teamUuid) }
        if (r is ApiResult.Ok) { rows = r.data.members; failure = null } else if (rows == null) failure = r.errorText(offlineText) ?: genericError
    }
    LaunchedEffect(teamUuid, reconnect) { load() }
    LaunchedEffect(teamUuid) { Realtime.changed.collect { load() } }

    Text(stringResource(if (role == "member") R.string.tw_load_mine else R.string.tw_load_intro), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
    val list = rows
    when {
        list == null && failure == null -> SkeletonList(count = 3)
        list == null -> ErrorBanner(failure, Modifier.fillMaxWidth())
        list.isEmpty() -> EmptyState(icon = Icons.Rounded.Groups, title = stringResource(R.string.tw_load_empty_title), body = stringResource(R.string.tw_load_empty_body), modifier = Modifier.padding(top = 30.dp))
        else -> list.forEach { LoadCard(it) }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun LoadCard(l: TeamLoadDto) {
    Column(Modifier.fillMaxWidth().appCard(Corners.card).padding(14.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            InitialsBadge(l.freelancer.name, 42.dp, roleTint(l.role))
            Text(l.freelancer.name.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            RolePill(l.role)
        }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            LoadStat(l.projects, R.string.tw_load_projects, settingsAccent())
            LoadStat(l.partsOpen, R.string.tw_load_parts, settingsAccent())
            LoadStat(l.tasksOpen, R.string.tw_load_tasks, settingsAccent())
            LoadStat(l.tasksOverdue, R.string.tw_load_overdue, RED, hot = l.tasksOverdue > 0)
            LoadStat(l.tasksBlocked, R.string.tw_load_blocked, AMBER, hot = l.tasksBlocked > 0)
            LoadStat(l.tasksDone, R.string.tw_load_done, GREEN)
        }
    }
}

/** A number and what it counts; a late or blocked count turns on its colour when it is above zero. */
@Composable
private fun LoadStat(value: Int, label: Int, tint: Color, hot: Boolean = false) {
    val color = if (value == 0 && !hot) settingsMut() else tint
    Column(
        Modifier.clip(RoundedCornerShape(14.dp)).background(color.copy(alpha = if (hot) 0.12f else 0.07f)).padding(horizontal = 12.dp, vertical = 8.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text("‎$value", color = color, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
        Text(stringResource(label), color = settingsMut(), fontSize = 11.sp)
    }
}

/** The people on a part, as small chips under its title - and «Assign» for an Owner or a PM. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun AssigneesRow(people: List<TeamPersonRefDto>, canAssign: Boolean, onAssign: () -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            if (people.isEmpty()) Text(stringResource(R.string.tw_assign_none), color = settingsMut(), fontSize = 12.sp)
            people.forEach { p ->
                Row(
                    Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.10f)).padding(start = 4.dp, end = 10.dp, top = 3.dp, bottom = 3.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    InitialsBadge(p.name, 22.dp)
                    Text(p.name.orEmpty(), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                }
            }
        }
        if (canAssign) Row(
            Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.10f)).clickable(onClick = onAssign).padding(horizontal = 12.dp, vertical = 7.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Icon(Icons.Rounded.AssignmentInd, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
            Text(stringResource(R.string.tw_assign), color = settingsAccent(), fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
        }
    }
}

/**
 * An Owner's or a PM's sheet to put people of the team on one part: the team's active members with a tick each; Save sends exactly the
 * ticked people (none takes everybody off). A member sees the project once he is on a part.
 */
@Composable
internal fun AssigneesSheet(
    projectUuid: String, milestoneUuid: String, milestoneTitle: String, teamUuid: String,
    current: List<TeamPersonRefDto>, onDismiss: () -> Unit, onSaved: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var members by remember { mutableStateOf<List<TeamMemberDto>?>(null) }
    val picked = remember { mutableStateListOf<String>().also { l -> l.addAll(current.mapNotNull { it.uuid }) } }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(teamUuid) {
        val r = apiCall { ApiClient.teams.members(teamUuid) }
        if (r is ApiResult.Ok) members = r.data.members else error = r.errorText(offlineText) ?: genericError
    }

    LocaleAwareBottomSheet(onDismissRequest = { if (!busy) onDismiss() }, containerColor = settingsCard(), shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp)) {
        Column(
            Modifier.fillMaxWidth().heightIn(max = 640.dp).verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(stringResource(R.string.tw_assign_title), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(milestoneTitle, color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis)
            Text(stringResource(R.string.tw_assign_hint), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
            val list = members
            if (list == null && error == null) SkeletonList(count = 3)
            list?.forEach { m ->
                val uuid = m.freelancer.uuid ?: return@forEach
                val on = uuid in picked
                Row(
                    Modifier.fillMaxWidth().clip(Corners.small).border(1.dp, if (on) settingsAccent().copy(alpha = 0.55f) else settingsMut().copy(alpha = 0.16f), Corners.small)
                        .clickable(enabled = !busy) { if (on) picked.remove(uuid) else picked.add(uuid) }.padding(horizontal = 12.dp, vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    InitialsBadge(m.freelancer.name, 38.dp, roleTint(m.role))
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text(m.freelancer.name.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text(roleLabel(m.role), color = roleTint(m.role), fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
                    }
                    Box(
                        Modifier.size(24.dp).clip(RoundedCornerShape(8.dp)).background(if (on) settingsAccent() else Color.Transparent)
                            .border(1.5.dp, if (on) settingsAccent() else settingsMut().copy(alpha = 0.4f), RoundedCornerShape(8.dp)),
                        contentAlignment = Alignment.Center,
                    ) { if (on) Icon(Icons.Rounded.Check, contentDescription = null, tint = Color.White, modifier = Modifier.size(16.dp)) }
                }
            }
            ErrorBanner(error, Modifier.fillMaxWidth())
            SettingsPrimaryButton(
                text = stringResource(R.string.common_save), loading = busy, enabled = members != null,
                onClick = {
                    busy = true
                    error = null
                    scope.launch {
                        val r = apiCall { ApiClient.teams.setAssignees(projectUuid, milestoneUuid, AssigneesRequest(picked.toList())) }
                        busy = false
                        if (r is ApiResult.Ok) onSaved() else error = r.errorText(offlineText) ?: genericError
                    }
                },
            )
        }
    }
}
