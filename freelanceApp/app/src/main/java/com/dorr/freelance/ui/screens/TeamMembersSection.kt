package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import com.dorr.freelance.ui.components.DorrTextField
import androidx.compose.runtime.setValue
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.getValue
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.PersonAdd
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
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
import com.dorr.freelance.R
import com.dorr.freelance.net.TeamMemberDto
import com.dorr.freelance.net.TeamMembersDto
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.FilterChips
import com.dorr.freelance.ui.kit.FilterOption
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.appCard
import com.dorr.freelance.ui.locale.LocaleAwareBottomSheet
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsCard
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut

/** The abilities an Owner may switch for a PM or a member, in the order the sheet lists them (the server names them in `switches`). */
private val SWITCH_ORDER = listOf("view_price", "client_chat", "internal_files", "invite_members")

/**
 * The «Members» part of a team's page: everybody in the team with their role and job title (Owners first, as the server sends them). An Owner
 * taps a row to manage that person; anybody else taps his own row to write his job title (FS-213); somebody who may invite gets a shortcut to
 * the invitations.
 */
@Composable
internal fun TeamMembersSection(
    people: TeamMembersDto, myUuid: String?, isOwner: Boolean, canInvite: Boolean, onManage: (TeamMemberDto) -> Unit, onInvite: () -> Unit,
    onEditTitle: (TeamMemberDto) -> Unit = {},
) {
    Column(Modifier.fillMaxWidth().appCard(Corners.card).padding(vertical = 6.dp)) {
        people.members.forEachIndexed { index, m ->
            if (index > 0) Box(Modifier.fillMaxWidth().padding(start = 72.dp).height(1.dp).background(settingsMut().copy(alpha = 0.12f)))
            val isMe = m.freelancer.uuid != null && m.freelancer.uuid == myUuid
            MemberRow(m, isMe = isMe, canManage = isOwner || isMe, onClick = { if (isOwner) onManage(m) else onEditTitle(m) })
        }
    }
    if (canInvite) SettingsPrimaryButton(text = stringResource(R.string.team_invite_title), onClick = onInvite)
}

@Composable
private fun MemberRow(m: TeamMemberDto, isMe: Boolean, canManage: Boolean, onClick: () -> Unit) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    Row(
        Modifier.fillMaxWidth().clickable(enabled = canManage, onClick = onClick).padding(horizontal = 14.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        InitialsBadge(m.freelancer.name, 44.dp, roleTint(m.role))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(m.freelancer.name.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                if (isMe) Text(stringResource(R.string.team_you), color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.Bold)
            }
            // FS-213: what the person does in the team; the date he joined when there is none.
            m.jobTitle?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsAccent(), fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis) }
                ?: if (isMe) Text(stringResource(R.string.team_title_add_mine), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                else teamDate(m.joinedAt)?.let { Text(stringResource(R.string.team_member_since, it), color = settingsMut(), fontSize = 11.5.sp) }
        }
        RolePill(m.role)
        if (canManage) Icon(Icons.Rounded.ChevronRight, contentDescription = stringResource(R.string.team_manage), tint = settingsMut(), modifier = Modifier.size(18.dp).graphicsLayer { if (rtl) scaleX = -1f })
    }
}

/**
 * An Owner's sheet for one person: the role (promoting somebody to Owner is how ownership is passed on), the four abilities an Owner switches
 * for a PM or a member, and taking the person out of the team. Each change goes to the server at once; [member] is the latest copy of the person.
 */
@Composable
internal fun ManageMemberSheet(
    member: TeamMemberDto,
    switches: List<String>,
    isMe: Boolean,
    busy: Boolean,
    onRole: (String) -> Unit,
    onPermission: (String, Boolean) -> Unit,
    onRemove: () -> Unit,
    onDismiss: () -> Unit,
    /** FS-213: saves the person's job title (empty clears it). */
    onTitle: (String) -> Unit = {},
) {
    val keys = SWITCH_ORDER.filter { it in switches }
    // FS-212: an Owner who signed agreements of the team that have not ended, or has offers in its name still open, stays an Owner until they
    // end or are taken back - the server refuses the rest.
    val held = member.role == "owner" && member.signedAgreements + member.openOffers > 0
    LocaleAwareBottomSheet(onDismissRequest = { if (!busy) onDismiss() }, containerColor = settingsCard(), shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp)) {
        Column(
            Modifier.fillMaxWidth().heightIn(max = 640.dp).verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                InitialsBadge(member.freelancer.name, 52.dp, roleTint(member.role))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(member.freelancer.name.orEmpty(), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(roleLabel(member.role), color = roleTint(member.role), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                }
            }

            JobTitleField(member.jobTitle, busy, onTitle)

            Text(stringResource(R.string.team_role_label), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            FilterChips(
                options = listOf("owner", "pm", "member").map { FilterOption(it, roleLabel(it)) },
                selected = member.role,
                onSelect = { if (!busy && it != member.role && !(held && it != "owner")) onRole(it) },
            )
            Text(roleHint(member.role), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
            if (held) Text(stringResource(R.string.team_signer_hold, member.freelancer.name.orEmpty().substringBefore(' ')), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)

            if (member.role == "owner") {
                Text(stringResource(R.string.team_perm_owner_all), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
            } else if (keys.isNotEmpty()) {
                Text(stringResource(R.string.team_perm_title), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                Column(Modifier.fillMaxWidth().clip(Corners.card).border(1.dp, settingsMut().copy(alpha = 0.16f), Corners.card).padding(horizontal = 14.dp, vertical = 6.dp)) {
                    keys.forEach { key ->
                        val on = member.abilities[key] == true
                        TeamSwitchRow(permTitle(key), permSub(key), on = on, enabled = !busy, onToggle = { onPermission(key, !on) })
                    }
                }
            }

            if (!isMe) Box(
                Modifier.fillMaxWidth().height(50.dp).clip(RoundedCornerShape(16.dp)).border(1.5.dp, Color(0xFFDC2626).copy(alpha = if (held) 0.2f else 0.45f), RoundedCornerShape(16.dp))
                    .clickable(enabled = !busy && !held, onClick = onRemove),
                contentAlignment = Alignment.Center,
            ) { Text(stringResource(R.string.team_remove), color = Color(0xFFDC2626).copy(alpha = if (held) 0.4f else 1f), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold) }
        }
    }
}

/** FS-213: the most letters of a job title (the server's limit). */
private const val JOB_TITLE_MAX = 60

/** «Job title» with its save button: what the person does in the team, shown next to his name on the team's profile when he agreed. */
@Composable
internal fun JobTitleField(current: String?, busy: Boolean, onSave: (String) -> Unit) {
    var text by rememberSaveable(current) { mutableStateOf(current.orEmpty()) }
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(stringResource(R.string.team_title_label), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
        DorrTextField(text, { text = it.take(JOB_TITLE_MAX) }, placeholder = stringResource(R.string.team_title_placeholder), icon = Icons.Rounded.Badge, modifier = Modifier.fillMaxWidth())
        Text(stringResource(R.string.team_title_hint), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
        if (text.trim() != current.orEmpty()) SettingsPrimaryButton(text = stringResource(R.string.team_title_save), loading = busy, onClick = { onSave(text.trim()) })
    }
}

/** A person (not an Owner) writing his own job title. */
@Composable
internal fun JobTitleSheet(member: TeamMemberDto, busy: Boolean, onSave: (String) -> Unit, onDismiss: () -> Unit) {
    LocaleAwareBottomSheet(onDismissRequest = { if (!busy) onDismiss() }, containerColor = settingsCard(), shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().padding(horizontal = 18.dp).padding(bottom = 24.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                InitialsBadge(member.freelancer.name, 52.dp, roleTint(member.role))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(member.freelancer.name.orEmpty(), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(roleLabel(member.role), color = roleTint(member.role), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                }
            }
            JobTitleField(member.jobTitle, busy, onSave)
        }
    }
}

@Composable
private fun permTitle(key: String): String = stringResource(
    when (key) {
        "view_price" -> R.string.team_perm_view_price
        "client_chat" -> R.string.team_perm_client_chat
        "internal_files" -> R.string.team_perm_internal_files
        else -> R.string.team_perm_invite_members
    },
)

@Composable
private fun permSub(key: String): String = stringResource(
    when (key) {
        "view_price" -> R.string.team_perm_view_price_sub
        "client_chat" -> R.string.team_perm_client_chat_sub
        "internal_files" -> R.string.team_perm_internal_files_sub
        else -> R.string.team_perm_invite_members_sub
    },
)
