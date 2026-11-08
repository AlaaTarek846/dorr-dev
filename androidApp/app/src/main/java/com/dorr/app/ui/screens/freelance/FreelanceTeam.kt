package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.*
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
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceAccessDto
import com.dorr.app.network.FreelanceProjectDto
import com.dorr.app.network.FreelanceTeamSummaryDto
import com.dorr.app.network.TeamInvitationDto
import com.dorr.app.network.TeamInviteRequest
import com.dorr.app.network.TeamMemberDto
import com.dorr.app.network.TeamPageDto
import com.dorr.app.network.TeamRoleRequest
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.components.PullToRefreshHost
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

// FS-104: a project's team (docs/modules/freelance/PERMISSIONS.md). The owner invites Dorr users with one role each; the person invited
// answers; a member opens the owner's project as usual and the server hides what his role may not see or do.

private val TeamRed = Color(0xFFDC2626)
private val TeamGreen = Color(0xFF16A34A)
private val TeamAmber = Color(0xFFD97706)

/** The roles in the order the owner picks from (the most common first). */
internal val TEAM_ROLES = listOf("reviewer", "manager", "collaborator", "finance", "delegate")

private val DELEGATE_DAYS = listOf(7, 14, 30, 90)

@Composable
internal fun teamRoleLabel(role: String): String = stringResource(
    when (role) {
        "manager" -> R.string.fs_tm_role_manager
        "reviewer" -> R.string.fs_tm_role_reviewer
        "finance" -> R.string.fs_tm_role_finance
        "delegate" -> R.string.fs_tm_role_delegate
        "owner" -> R.string.fs_tm_role_owner
        else -> R.string.fs_tm_role_collaborator
    },
)

/** What a role may do, told to the person who holds it (the invitation, the banner on the owner's project). */
@Composable
private fun teamRoleLineForMember(role: String): String = stringResource(
    when (role) {
        "manager" -> R.string.fs_tm_you_manager
        "reviewer" -> R.string.fs_tm_you_reviewer
        "finance" -> R.string.fs_tm_you_finance
        "delegate" -> R.string.fs_tm_you_delegate
        else -> R.string.fs_tm_you_collaborator
    },
)

@Composable
private fun teamRoleLine(role: String): String = stringResource(
    when (role) {
        "manager" -> R.string.fs_tm_role_manager_line
        "reviewer" -> R.string.fs_tm_role_reviewer_line
        "finance" -> R.string.fs_tm_role_finance_line
        "delegate" -> R.string.fs_tm_role_delegate_line
        else -> R.string.fs_tm_role_collaborator_line
    },
)

internal fun teamRoleIcon(role: String): ImageVector = when (role) {
    "manager" -> Icons.Rounded.ManageAccounts
    "reviewer" -> Icons.Rounded.FactCheck
    "finance" -> Icons.Rounded.AccountBalance
    "delegate" -> Icons.Rounded.Verified
    "owner" -> Icons.Rounded.Star
    else -> Icons.Rounded.Visibility
}

internal fun teamRoleTint(role: String): Color = when (role) {
    "manager" -> Color(0xFF3B82F6)
    "reviewer" -> Color(0xFF8B5CF6)
    "finance" -> Color(0xFF0EA5E9)
    "delegate" -> Color(0xFFD97706)
    "owner" -> Color(0xFFEC4899)
    else -> Color(0xFF64748B)
}

/** «7 days», «14 days» — Arabic counts 3 to 10 differently. */
@Composable
private fun teamDays(n: Int): String = stringResource(if (n in 3..10) R.string.fs_tm_days_few else R.string.fs_tm_days_many, n)

@Composable
private fun RolePill(role: String, small: Boolean = false) {
    val tint = teamRoleTint(role)
    Row(
        Modifier.clip(RoundedCornerShape(999.dp)).background(tint.copy(alpha = 0.12f)).padding(horizontal = if (small) 8.dp else 10.dp, vertical = if (small) 2.dp else 4.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Icon(teamRoleIcon(role), null, tint = tint, modifier = Modifier.size(if (small) 12.dp else 14.dp))
        Text(teamRoleLabel(role), color = tint, fontSize = if (small) 10.5.sp else 11.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

@Composable
private fun TeamAvatar(name: String, tint: Color) {
    Box(Modifier.size(44.dp).clip(CircleShape).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
        Text(name.trim().take(1).uppercase().ifEmpty { "؟" }, color = tint, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
    }
}

// ---------------------------------------------------------------- the owner's team page

@Composable
internal fun TeamPage(project: String, onBack: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val reconnect = collectReconnectTick()
    var page by remember { mutableStateOf<TeamPageDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var version by remember { mutableStateOf(0) }
    var inviting by rememberSaveable { mutableStateOf(false) }
    var editing by remember { mutableStateOf<TeamMemberDto?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(project, reconnect, version) {
        try {
            page = ApiClient.freelanceTeam.team(auth(), project).data
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    Column(Modifier.fillMaxSize()) {
        val p = page
        FlPageHeader(
            stringResource(R.string.fs_tm_title), onBack,
            trailing = { if (p != null && p.members.size < p.max) FlHeaderAction(stringResource(R.string.fs_tm_invite), Icons.Rounded.PersonAdd) { notice = null; inviting = true } },
        )
        PullToRefreshHost(Modifier.weight(1f).fillMaxWidth()) {
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(horizontal = 16.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                item { FlHint(stringResource(R.string.fs_tm_intro)) }
                notice?.let { n -> item { FlNotice(n, Icons.Rounded.CheckCircle, TeamGreen) } }
                when {
                    p == null && error == null -> items(3) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(84.dp), RoundedCornerShape(18.dp)) }
                    p == null -> item { FlRetry(error.orEmpty()) }
                    p.members.isEmpty() -> item {
                        Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                            com.dorr.app.ui.screens.wallet.WaEmpty(Icons.Rounded.Groups, com.dorr.app.ui.screens.wallet.Tone.Pink, stringResource(R.string.fs_tm_empty_title), stringResource(R.string.fs_tm_empty))
                            FlAction(stringResource(R.string.fs_tm_invite), Icons.Rounded.PersonAdd, FlTone.Primary) { inviting = true }
                        }
                    }
                    else -> {
                        item {
                            Text(stringResource(R.string.fs_tm_count, p.members.count { it.status == "active" }, p.max), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(horizontal = 4.dp))
                        }
                        items(p.members, key = { it.uuid }) { m -> MemberRow(m, p.currency) { notice = null; editing = m } }
                    }
                }
                item { RolesGuide() }
            }
        }
    }

    if (inviting) page?.let { p ->
        TeamRoleSheet(
            member = null, currency = p.currency, onDismiss = { inviting = false },
            submit = { phone, role, max, days ->
                ApiClient.freelanceTeam.invite(auth(), project, TeamInviteRequest(phone, role, max, days)).also { page = it.data; notice = it.message }
            },
        )
    }
    editing?.let { m ->
        TeamRoleSheet(
            member = m, currency = page?.currency.orEmpty(), onDismiss = { editing = null },
            submit = { _, role, max, days -> ApiClient.freelanceTeam.update(auth(), project, m.uuid, TeamRoleRequest(role, max, days)).also { page = it.data } },
            onRemove = { ApiClient.freelanceTeam.remove(auth(), project, m.uuid).also { page = it.data } },
        )
    }
}

@Composable
private fun MemberRow(m: TeamMemberDto, currency: String, onClick: () -> Unit) {
    val tint = teamRoleTint(m.role)
    Column(Modifier.fillMaxWidth().settingsSurface(FlCorners.card).clickable(onClick = onClick).padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            TeamAvatar(m.name, tint)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(m.name.ifBlank { "—" }, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text("‎" + m.phone, color = settingsMut(), fontSize = 12.sp, maxLines = 1)
            }
            Icon(Icons.Rounded.ChevronLeft, null, tint = settingsMut(), modifier = Modifier.size(20.dp))
        }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            RolePill(m.role)
            when (m.status) {
                "invited" -> StatusTag(stringResource(R.string.fs_tm_status_invited), TeamAmber, Icons.Rounded.HourglassTop)
                "expired" -> StatusTag(stringResource(R.string.fs_tm_status_expired), TeamRed, Icons.Rounded.EventBusy)
                else -> StatusTag(stringResource(R.string.fs_tm_status_active), TeamGreen, Icons.Rounded.CheckCircle)
            }
        }
        if (m.role == "delegate") DelegateLimitLine(m.maxAmountMinor, currency, m.endsAt, m.delegationOver)
    }
}

@Composable
private fun StatusTag(text: String, color: Color, icon: ImageVector) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(3.dp)) {
        Icon(icon, null, tint = color, modifier = Modifier.size(13.dp))
        Text(text, color = color, fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun DelegateLimitLine(max: Long?, currency: String, endsAt: String?, over: Boolean) {
    if (over) {
        FlNotice(stringResource(R.string.fs_tm_delegate_over), Icons.Rounded.EventBusy, TeamRed)
    } else if (max != null) {
        FlNotice(
            stringResource(R.string.fs_tm_delegate_line, "‎${money(max)} $currency", "‎" + com.dorr.app.ui.screens.rides.localDateTime(endsAt)),
            Icons.Rounded.Verified, TeamAmber,
        )
    }
}

/** Each role in one line — shown under the team so the owner picks knowing what he gives. */
@Composable
private fun RolesGuide() {
    var open by rememberSaveable { mutableStateOf(false) }
    Column(Modifier.fillMaxWidth().settingsSurface(FlCorners.card).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(Modifier.fillMaxWidth().clickable { open = !open }, verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Info, null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.fs_tm_roles_guide), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            Icon(if (open) Icons.Rounded.ExpandLess else Icons.Rounded.ExpandMore, null, tint = settingsMut())
        }
        if (open) {
            TEAM_ROLES.forEach { r ->
                Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    Icon(teamRoleIcon(r), null, tint = teamRoleTint(r), modifier = Modifier.padding(top = 2.dp).size(18.dp))
                    Column {
                        Text(teamRoleLabel(r), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                        Text(teamRoleLine(r), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
                    }
                }
            }
            Text(stringResource(R.string.fs_tm_owner_stays), color = settingsMut(), fontSize = 11.5.sp, lineHeight = 17.sp)
        }
    }
}

/**
 * Inviting someone (member = null: a phone first) or changing a member's role. A delegate needs his limit and length; the sheet says that
 * a delegate pays from the owner's wallet. [submit] throws what the server refused; the sheet shows it and stays open.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun TeamRoleSheet(
    member: TeamMemberDto?, currency: String, onDismiss: () -> Unit,
    submit: suspend (phone: String, role: String, max: Long?, days: Int?) -> Unit,
    onRemove: (suspend () -> Unit)? = null,
) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    var phone by rememberSaveable { mutableStateOf("") }
    var role by rememberSaveable { mutableStateOf(member?.role ?: "reviewer") }
    var max by rememberSaveable { mutableStateOf(member?.maxAmountMinor?.let { money(it) }.orEmpty()) }
    var days by rememberSaveable { mutableStateOf(30) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var removing by remember { mutableStateOf(false) }

    val maxMinor = parseMoney(max)
    val ready = (member != null || phone.count { it.isDigit() } >= 8) && (role != "delegate" || (maxMinor != null && maxMinor > 0))

    fun run(block: suspend () -> Unit) {
        busy = true
        error = null
        scope.launch {
            try {
                block()
                onDismiss()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message(generic)
            }
            busy = false
        }
    }

    FlSheet(
        if (member == null) Icons.Rounded.PersonAdd else Icons.Rounded.ManageAccounts,
        if (member == null) stringResource(R.string.fs_tm_invite_title) else member.name,
        if (member == null) stringResource(R.string.fs_tm_invite_body) else stringResource(R.string.fs_tm_change_role_body),
        onDismiss = { if (!busy) onDismiss() },
        footer = {
            error?.let { FlNotice(it, Icons.Rounded.ErrorOutline, TeamRed) }
            FlAction(
                stringResource(if (member == null) R.string.fs_tm_send else R.string.fs_tm_save),
                if (member == null) Icons.Rounded.Send else Icons.Rounded.Save, FlTone.Primary, enabled = ready && !busy, loading = busy,
            ) { run { submit(phone.trim(), role, if (role == "delegate") maxMinor else null, if (role == "delegate") days else null) } }
            if (onRemove != null) FlAction(stringResource(if (member?.status == "active") R.string.fs_tm_remove else R.string.fs_tm_cancel_invite), Icons.Rounded.PersonRemove, FlTone.Danger, enabled = !busy) { removing = true }
        },
    ) {
        if (member == null) DorrTextField(
            phone, { phone = it.filter { c -> c.isDigit() || c == '+' || c == ' ' }.take(20) },
            label = stringResource(R.string.fs_tm_phone), placeholder = "+9665…", icon = Icons.Rounded.Phone,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone), modifier = Modifier.fillMaxWidth(),
            textStyle = androidx.compose.material3.MaterialTheme.typography.bodyLarge.copy(textDirection = androidx.compose.ui.text.style.TextDirection.Ltr),
        )
        Text(stringResource(R.string.fs_tm_role), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
        TEAM_ROLES.forEach { r -> RoleOption(r, selected = role == r) { role = r } }
        if (role == "delegate") {
            DorrTextField(max, { max = it.take(12) }, label = stringResource(R.string.fs_tm_limit, currency), icon = Icons.Rounded.Payments, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth())
            Text(stringResource(R.string.fs_tm_days), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                DELEGATE_DAYS.forEach { d -> Chip(teamDays(d), selected = days == d, icon = Icons.Rounded.Event) { days = d } }
            }
            FlNotice(stringResource(R.string.fs_tm_delegate_warn), Icons.Rounded.WarningAmber, TeamAmber)
        }
    }

    if (removing && member != null && onRemove != null) ConfirmDialog(
        icon = Icons.Rounded.PersonRemove, title = stringResource(if (member.status == "active") R.string.fs_tm_remove else R.string.fs_tm_cancel_invite),
        message = stringResource(R.string.fs_tm_remove_confirm, member.name), loading = busy, onDismiss = { removing = false },
        confirmLabel = stringResource(if (member.status == "active") R.string.fs_tm_remove else R.string.fs_tm_cancel_invite),
        onConfirm = { removing = false; run { onRemove() } },
    )
}

@Composable
private fun RoleOption(role: String, selected: Boolean, onClick: () -> Unit) {
    val tint = teamRoleTint(role)
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp))
            .background(if (selected) tint.copy(alpha = 0.10f) else Color.Transparent)
            .border(BorderStroke(if (selected) 1.5.dp else 1.dp, if (selected) tint else settingsMut().copy(alpha = 0.25f)), RoundedCornerShape(16.dp))
            .clickable(onClick = onClick).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(teamRoleIcon(role), null, tint = tint, modifier = Modifier.size(20.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(teamRoleLabel(role), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
            Text(teamRoleLine(role), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
        }
        Icon(if (selected) Icons.Rounded.RadioButtonChecked else Icons.Rounded.RadioButtonUnchecked, null, tint = if (selected) tint else settingsMut(), modifier = Modifier.size(22.dp))
    }
}

// ---------------------------------------------------------------- on the owner's project page

/** The owner's way into his team, on the project's details. */
@Composable
internal fun ProjectTeamCard(team: FreelanceTeamSummaryDto, onOpen: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().settingsSurface(FlCorners.card).clickable(onClick = onOpen).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(FlTints.favorites.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Groups, null, tint = FlTints.favorites, modifier = Modifier.size(22.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(stringResource(R.string.fs_tm_title), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
            Text(
                if (team.members + team.invited == 0) stringResource(R.string.fs_tm_card_empty) else stringResource(R.string.fs_tm_card_line, team.members, team.invited),
                color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp,
            )
        }
        Icon(Icons.Rounded.ChevronLeft, null, tint = settingsMut(), modifier = Modifier.size(20.dp))
    }
}

/** On a page of someone else's project: whose it is, my role, and what that lets me do (a delegate: his limit and date). */
@Composable
internal fun TeamAccessBanner(access: FreelanceAccessDto, currency: String, onLeave: (() -> Unit)? = null) {
    if (access.isOwner) return
    val tint = teamRoleTint(access.role)
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(tint.copy(alpha = 0.08f))
            .border(BorderStroke(1.dp, tint.copy(alpha = 0.25f)), RoundedCornerShape(20.dp)).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Icon(teamRoleIcon(access.role), null, tint = tint, modifier = Modifier.size(22.dp))
            Text(
                stringResource(R.string.fs_tm_banner, access.owner?.name.orEmpty(), teamRoleLabel(access.role)),
                color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, lineHeight = 19.sp, modifier = Modifier.weight(1f),
            )
        }
        Text(teamRoleLineForMember(access.role), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
        if (access.role == "delegate") DelegateLimitLine(access.maxAmountMinor, currency, access.endsAt, access.delegationOver)
        if (!access.money) Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Icon(Icons.Rounded.VisibilityOff, null, tint = settingsMut(), modifier = Modifier.size(14.dp))
            Text(stringResource(R.string.fs_tm_money_hidden), color = settingsMut(), fontSize = 11.5.sp)
        }
        if (onLeave != null) Text(
            stringResource(R.string.fs_tm_leave), color = TeamRed, fontSize = 12.5.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable(onClick = onLeave).padding(vertical = 4.dp),
        )
    }
}

// ---------------------------------------------------------------- the projects I was brought into

@Composable
internal fun SharedProjectsPage(onBack: () -> Unit, onOpen: (String) -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var invitations by remember { mutableStateOf<List<TeamInvitationDto>?>(null) }
    var projects by remember { mutableStateOf<List<FreelanceProjectDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var version by remember { mutableStateOf(0) }
    var busy by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(reconnect, version) {
        try {
            invitations = ApiClient.freelanceTeam.invitations(auth()).data?.invitations.orEmpty()
            projects = ApiClient.freelanceTeam.shared(auth()).data.orEmpty()
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    fun answer(inv: TeamInvitationDto, answer: String) {
        busy = inv.uuid
        scope.launch {
            try {
                val r = ApiClient.freelanceTeam.respond(auth(), inv.uuid, answer)
                notice = r.message
                version++
                if (answer == "accept") r.data?.project?.let(onOpen)
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message(generic)
            }
            busy = null
        }
    }

    Column(Modifier.fillMaxSize()) {
        FlPageHeader(stringResource(R.string.fs_tm_shared_title), onBack)
        PullToRefreshHost(Modifier.weight(1f).fillMaxWidth()) {
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(horizontal = 16.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                notice?.let { n -> item { FlNotice(n, Icons.Rounded.CheckCircle, TeamGreen) } }
                val inv = invitations
                val list = projects
                when {
                    (inv == null || list == null) && error == null -> items(3) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(96.dp), RoundedCornerShape(18.dp)) }
                    inv == null || list == null -> item { FlRetry(error.orEmpty()) }
                    else -> {
                        error?.let { e -> item { FlNotice(e, Icons.Rounded.ErrorOutline, TeamRed) } }
                        if (inv.isNotEmpty()) {
                            item { FlSectionHeader(stringResource(R.string.fs_tm_invitations)) }
                            items(inv, key = { "i" + it.uuid }) { i -> InvitationCard(i, busy == i.uuid, onAccept = { answer(i, "accept") }, onDecline = { answer(i, "decline") }) }
                        }
                        if (list.isEmpty() && inv.isEmpty()) item {
                            com.dorr.app.ui.screens.wallet.WaEmpty(Icons.Rounded.Groups, com.dorr.app.ui.screens.wallet.Tone.Pink, stringResource(R.string.fs_tm_shared_empty_title), stringResource(R.string.fs_tm_shared_empty))
                        }
                        if (list.isNotEmpty()) {
                            item { FlSectionHeader(stringResource(R.string.fs_tm_shared_projects), Modifier.padding(top = 4.dp)) }
                            items(list, key = { it.uuid }) { p -> SharedProjectRow(p) { onOpen(p.uuid) } }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun InvitationCard(inv: TeamInvitationDto, busy: Boolean, onAccept: () -> Unit, onDecline: () -> Unit) {
    val tint = teamRoleTint(inv.role)
    Column(Modifier.fillMaxWidth().settingsSurface(FlCorners.card).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            TeamAvatar(inv.owner.name, tint)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(inv.project.title.ifBlank { stringResource(R.string.fl_untitled) }, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Text(stringResource(R.string.fs_tm_invited_by, inv.owner.name), color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
        RolePill(inv.role)
        Text(teamRoleLineForMember(inv.role), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
        if (inv.role == "delegate") DelegateLimitLine(inv.maxAmountMinor, inv.currency.orEmpty(), inv.endsAt, false)
        inv.expiresAt?.let { Text(stringResource(R.string.fs_tm_expires, "‎" + com.dorr.app.ui.screens.rides.localDateTime(it)), color = TeamAmber, fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold) }
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            FlAction(stringResource(R.string.fs_tm_accept), Icons.Rounded.Check, FlTone.Primary, enabled = !busy, loading = busy, modifier = Modifier.weight(1.4f), onClick = onAccept)
            FlAction(stringResource(R.string.fs_tm_decline), null, enabled = !busy, modifier = Modifier.weight(1f), onClick = onDecline)
        }
    }
}

@Composable
private fun SharedProjectRow(p: FreelanceProjectDto, onClick: () -> Unit) {
    val access = p.access
    Column(Modifier.fillMaxWidth().settingsSurface(FlCorners.card).clickable(onClick = onClick).padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(p.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.fl_untitled), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            Icon(Icons.Rounded.ChevronLeft, null, tint = settingsMut(), modifier = Modifier.size(20.dp))
        }
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            access?.let { RolePill(it.role, small = true) }
            Text(
                listOfNotNull(access?.owner?.name?.let { stringResource(R.string.fs_tm_owner_line, it) }, p.statusLabel.takeIf { it.isNotBlank() }).joinToString("  ·  "),
                color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
            )
        }
    }
}

/** Leaving a team, asked first; the owner is told. */
@Composable
internal fun LeaveTeamDialog(project: String, onDismiss: () -> Unit, onLeft: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    ConfirmDialog(
        icon = Icons.Rounded.Logout, title = stringResource(R.string.fs_tm_leave), message = error ?: stringResource(R.string.fs_tm_leave_confirm),
        loading = busy, onDismiss = onDismiss, confirmLabel = stringResource(R.string.fs_tm_leave),
        onConfirm = {
            busy = true
            scope.launch {
                try {
                    ApiClient.freelanceTeam.leave(auth(), project)
                    onLeft()
                } catch (e: CancellationException) {
                    throw e
                } catch (e: Exception) {
                    error = e.message(generic)
                }
                busy = false
            }
        },
    )
}
