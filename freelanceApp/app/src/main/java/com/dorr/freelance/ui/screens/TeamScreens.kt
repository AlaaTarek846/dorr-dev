package com.dorr.freelance.ui.screens

import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.VpnKey
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material3.Icon
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
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.HomeHub
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.TeamInvitationDto
import com.dorr.freelance.net.TeamCreateRequest
import com.dorr.freelance.net.TeamSummaryDto
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.MessageToast
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.SkeletonList
import com.dorr.freelance.ui.kit.AlertDialog
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.DialogButton
import com.dorr.freelance.ui.kit.FactLine
import com.dorr.freelance.ui.kit.HeroNumber
import com.dorr.freelance.ui.kit.MenuEntry
import com.dorr.freelance.ui.kit.MenuRow
import com.dorr.freelance.ui.kit.SectionHeader
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.StatusPill
import com.dorr.freelance.ui.kit.StoreHero
import com.dorr.freelance.ui.kit.Tints
import com.dorr.freelance.ui.kit.appCard
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import kotlinx.coroutines.launch

/**
 * My teams (FS-209): the invitations waiting for me, the teams I am in (each a card that opens the team's page), a way to make a team and one
 * to join with an invitation code. Read when shown, when a notice arrives and when the connection comes back — nothing on a timer.
 */
@Composable
fun TeamsScreen(onBack: () -> Unit, onOpenTeam: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val acceptedText = stringResource(R.string.team_accepted_toast)
    val declinedText = stringResource(R.string.team_declined_toast)
    val createdText = stringResource(R.string.team_created_toast)
    val scope = rememberCoroutineScope()
    var teams by remember { mutableStateOf<List<TeamSummaryDto>?>(null) }
    var invites by remember { mutableStateOf<List<TeamInvitationDto>>(emptyList()) }
    var failure by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var answering by remember { mutableStateOf<String?>(null) }
    var creating by remember { mutableStateOf(false) }
    var showCreate by rememberSaveable { mutableStateOf(false) }
    var showJoin by rememberSaveable { mutableStateOf(false) }

    suspend fun load() {
        val mine = apiCall { ApiClient.teams.mine() }
        val waiting = apiCall { ApiClient.teams.myInvitations() }
        if (mine is ApiResult.Ok) {
            teams = mine.data.teams
            failure = null
        } else if (teams == null) {
            failure = mine.errorText(offlineText) ?: genericError
        }
        if (waiting is ApiResult.Ok) invites = waiting.data.invitations
    }

    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }

    fun answer(invite: TeamInvitationDto, accept: Boolean) {
        notice = null
        answering = invite.uuid
        scope.launch {
            val result = apiCall { if (accept) ApiClient.teams.accept(invite.uuid) else ApiClient.teams.decline(invite.uuid) }
            if (result is ApiResult.Ok) {
                notice = if (accept) acceptedText else declinedText
                load()
                launch { HomeHub.refresh() }
                if (accept) (result.data.invitation.team?.uuid ?: invite.team?.uuid)?.let(onOpenTeam)
            } else {
                notice = result.errorText(offlineText) ?: genericError
            }
            answering = null
        }
    }

    fun create(name: String) {
        notice = null
        creating = true
        scope.launch {
            val result = apiCall { ApiClient.teams.create(TeamCreateRequest(name)) }
            if (result is ApiResult.Ok) {
                showCreate = false
                notice = createdText
                load()
                launch { HomeHub.refresh() }
                onOpenTeam(result.data.team.uuid)
            } else {
                notice = result.errorText(offlineText) ?: genericError
            }
            creating = false
        }
    }

    Page(title = stringResource(R.string.team_title), onBack = onBack) {
        val list = teams
        StoreHero(
            icon = Icons.Rounded.Groups,
            title = stringResource(R.string.team_hero_title),
            body = stringResource(R.string.team_hero_body),
            numbers = listOf(
                HeroNumber((list?.size ?: 0).toString(), stringResource(R.string.team_stat_teams)),
                HeroNumber(invites.size.toString(), stringResource(R.string.team_stat_invites)),
            ),
            action = stringResource(R.string.team_create),
            actionIcon = Icons.Rounded.Add,
            onAction = { showCreate = true },
        )
        MenuRow(MenuEntry(Icons.Rounded.VpnKey, R.string.team_join_code, R.string.team_join_code_sub, onClick = { showJoin = true }))

        if (invites.isNotEmpty()) {
            SectionHeader(stringResource(R.string.team_section_invites))
            invites.forEach { i -> TeamInviteCard(i, busy = answering == i.uuid, onAnswer = { accept -> answer(i, accept) }) }
        }

        when {
            list == null && failure == null -> SkeletonList(count = 2)
            list == null -> Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(14.dp)) {
                EmptyState(icon = Icons.Rounded.Groups, title = stringResource(R.string.common_loading_failed), body = failure.orEmpty())
                SettingsPrimaryButton(text = stringResource(R.string.common_retry), onClick = { scope.launch { load() } })
            }
            list.isEmpty() -> if (invites.isEmpty()) EmptyState(
                icon = Icons.Rounded.Groups,
                title = stringResource(R.string.team_empty_title),
                body = stringResource(R.string.team_empty_body),
                modifier = Modifier.padding(top = 24.dp),
            )
            else -> {
                SectionHeader(stringResource(R.string.team_section_mine))
                list.forEach { t -> TeamCard(t, onClick = { onOpenTeam(t.uuid) }) }
            }
        }
    }

    if (showCreate) CreateTeamDialog(busy = creating, onCreate = ::create, onDismiss = { showCreate = false })
    if (showJoin) JoinCodeDialog(
        onDismiss = { showJoin = false },
        onJoined = { uuid ->
            showJoin = false
            notice = acceptedText
            scope.launch { load() }
            scope.launch { HomeHub.refresh() }
            uuid?.let(onOpenTeam)
        },
    )
    MessageToast(notice)
}

/** One team of mine: its logo, name and badge, my role, its size, and a note when it is not working now. */
@Composable
private fun TeamCard(t: TeamSummaryDto, onClick: () -> Unit) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    Row(
        Modifier.fillMaxWidth().appCard(Corners.card).clickable(onClick = onClick).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        TeamLogo(t.logoUrl, 56.dp)
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(t.name, color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                if (t.verified) {
                    Spacer(Modifier.width(4.dp))
                    Icon(Icons.Rounded.Verified, contentDescription = stringResource(R.string.team_verified), tint = Tints.blue, modifier = Modifier.size(18.dp))
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                RolePill(t.role)
                when (t.status) {
                    "suspended" -> StatusPill(stringResource(R.string.team_status_suspended), Tints.rose)
                    "archived" -> StatusPill(stringResource(R.string.team_status_archived), Tints.amber)
                }
            }
            FactLine(Icons.Rounded.Groups, stringResource(R.string.team_members_line, t.membersCount))
        }
        Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(20.dp).graphicsLayer { if (rtl) scaleX = -1f })
    }
}

/** An invitation into a team that waits for my answer: who invited me, the role it offers and what that role is, then yes or no. */
@Composable
private fun TeamInviteCard(i: TeamInvitationDto, busy: Boolean, onAnswer: (Boolean) -> Unit) {
    Column(Modifier.fillMaxWidth().appCard(Corners.card).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            TeamLogo(null, 48.dp)
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(i.team?.name.orEmpty(), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                i.invitedBy?.name?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.team_invited_by, it), color = settingsMut(), fontSize = 12.5.sp) }
            }
            RolePill(i.role)
        }
        Text(roleHint(i.role), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
        teamDate(i.expiresAt)?.let { Text(stringResource(R.string.team_invite_expires, it), color = settingsMut(), fontSize = 11.5.sp) }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier.weight(1f).height(54.dp).clip(RoundedCornerShape(18.dp)).border(1.5.dp, Color(0xFFDC2626).copy(alpha = 0.45f), RoundedCornerShape(18.dp))
                    .clickable(enabled = !busy) { onAnswer(false) },
                contentAlignment = Alignment.Center,
            ) { Text(stringResource(R.string.team_decline), color = Color(0xFFDC2626), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold) }
            SettingsPrimaryButton(text = stringResource(R.string.team_accept), loading = busy, modifier = Modifier.weight(1.4f), onClick = { onAnswer(true) })
        }
    }
}

/** Asks for the team's name; the server makes me its Owner. */
@Composable
private fun CreateTeamDialog(busy: Boolean, onCreate: (String) -> Unit, onDismiss: () -> Unit) {
    var name by rememberSaveable { mutableStateOf("") }
    val clean = name.trim()
    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        icon = Icons.Rounded.Groups,
        title = { Text(stringResource(R.string.team_create_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
                Text(stringResource(R.string.team_create_body), modifier = Modifier.fillMaxWidth())
                DorrTextField(
                    value = name, onValueChange = { name = it.take(80) }, modifier = Modifier.fillMaxWidth(),
                    label = stringResource(R.string.team_name_label), placeholder = stringResource(R.string.team_name_hint),
                )
            }
        },
        confirmButton = { DialogButton(onClick = { onCreate(clean) }, enabled = clean.length >= 2 && !busy) { Text(stringResource(R.string.team_create_confirm)) } },
        dismissButton = { DialogButton(onClick = onDismiss, enabled = !busy) { Text(stringResource(R.string.common_cancel)) } },
    )
}

/**
 * Joining with the code a team gave me: paste it, see which team and role it is for, then join. A link is accepted too (its last part is the
 * code). Only the first person to use a code is let in.
 */
@Composable
private fun JoinCodeDialog(onDismiss: () -> Unit, onJoined: (String?) -> Unit) {
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val scope = rememberCoroutineScope()
    var code by rememberSaveable { mutableStateOf("") }
    var found by remember { mutableStateOf<TeamInvitationDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val token = code.trim().substringBefore('?').substringAfterLast('/').trim()

    fun step() {
        busy = true
        error = null
        scope.launch {
            val current = found
            if (current == null) {
                val result = apiCall { ApiClient.teams.preview(token) }
                if (result is ApiResult.Ok) found = result.data.invitation else error = result.errorText(offlineText) ?: genericError
            } else {
                val result = apiCall { ApiClient.teams.acceptCode(token) }
                if (result is ApiResult.Ok) onJoined(result.data.invitation.team?.uuid ?: current.team?.uuid) else error = result.errorText(offlineText) ?: genericError
            }
            busy = false
        }
    }

    AlertDialog(
        onDismissRequest = { if (!busy) onDismiss() },
        icon = Icons.Rounded.VpnKey,
        title = { Text(stringResource(R.string.team_join_title)) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(14.dp)) {
                Text(stringResource(R.string.team_join_body), modifier = Modifier.fillMaxWidth())
                DorrTextField(
                    value = code, onValueChange = { code = it; found = null; error = null }, modifier = Modifier.fillMaxWidth(),
                    label = stringResource(R.string.team_code_label), enabled = !busy,
                )
                found?.let { f ->
                    Row(
                        Modifier.fillMaxWidth().clip(Corners.small).border(1.dp, roleTint(f.role).copy(alpha = 0.35f), Corners.small).padding(12.dp),
                        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
                    ) {
                        TeamLogo(null, 40.dp)
                        Text(
                            stringResource(R.string.team_join_found, f.team?.name.orEmpty(), roleLabel(f.role)),
                            color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f),
                        )
                    }
                }
                error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.fillMaxWidth()) }
            }
        },
        confirmButton = {
            DialogButton(onClick = ::step, enabled = token.length >= 6 && !busy) {
                Text(stringResource(if (found == null) R.string.team_join_check else R.string.team_join_confirm))
            }
        },
        dismissButton = { DialogButton(onClick = onDismiss, enabled = !busy) { Text(stringResource(R.string.common_cancel)) } },
    )
}
