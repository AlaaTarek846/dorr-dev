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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.Share
import androidx.compose.material.icons.rounded.VpnKey
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.R
import com.dorr.freelance.net.TeamInvitationDto
import com.dorr.freelance.net.TeamInvitationsDto
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.FilterChips
import com.dorr.freelance.ui.kit.FilterOption
import com.dorr.freelance.ui.kit.SectionHeader
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.StatusPill
import com.dorr.freelance.ui.kit.Tints
import com.dorr.freelance.ui.kit.appCard
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut

/**
 * The «Invitations» part of a team's page, for the people who may invite: a card to invite by phone number or to make a code (no phone needed),
 * and the invitations still open with their code to copy or share. Only an Owner invites a PM; anybody else invites members.
 */
@Composable
internal fun TeamInvitesSection(
    teamName: String,
    data: TeamInvitationsDto?,
    isOwner: Boolean,
    busy: Boolean,
    onInvite: (phone: String?, role: String) -> Unit,
    onCancel: (TeamInvitationDto) -> Unit,
    onCopied: () -> Unit,
) {
    var role by rememberSaveable { mutableStateOf("member") }
    var phone by rememberSaveable { mutableStateOf("") }
    val chosen = if (isOwner) role else "member"
    val clean = phone.trim()

    Column(Modifier.fillMaxWidth().appCard(Corners.card).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(stringResource(R.string.team_invite_title), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
        data?.days?.let { Text(stringResource(R.string.team_invite_sub, it), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp) }
        if (isOwner) {
            Text(stringResource(R.string.team_invite_role), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            FilterChips(
                options = listOf("pm", "member").map { FilterOption(it, roleLabel(it)) },
                selected = chosen,
                onSelect = { role = it },
            )
            Text(roleHint(chosen), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
        }
        DorrTextField(
            value = phone, onValueChange = { phone = it.take(30) }, modifier = Modifier.fillMaxWidth(),
            label = stringResource(R.string.team_invite_phone), placeholder = stringResource(R.string.team_invite_phone_hint),
            icon = Icons.Rounded.Phone, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone), enabled = !busy,
        )
        SettingsPrimaryButton(
            text = stringResource(R.string.team_invite_send), loading = busy, enabled = clean.length >= 6,
            onClick = { onInvite(clean, chosen); phone = "" },
        )
        Box(
            Modifier.fillMaxWidth().height(50.dp).clip(androidx.compose.foundation.shape.RoundedCornerShape(16.dp))
                .border(1.5.dp, settingsAccent().copy(alpha = 0.45f), androidx.compose.foundation.shape.RoundedCornerShape(16.dp))
                .clickable(enabled = !busy) { onInvite(null, chosen) },
            contentAlignment = Alignment.Center,
        ) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Icon(Icons.Rounded.VpnKey, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                Text(stringResource(R.string.team_invite_code_btn), color = settingsAccent(), fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
    }

    SectionHeader(stringResource(R.string.team_invite_open))
    val open = data?.invitations.orEmpty()
    if (open.isEmpty()) {
        Text(stringResource(R.string.team_invite_none), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.fillMaxWidth().padding(vertical = 8.dp))
    } else {
        open.forEach { i -> OpenInvitation(teamName, i, busy, onCancel = { onCancel(i) }, onCopied = onCopied) }
    }
}

/** An invitation still open: to a person (their name) or by code (the code, to copy or share), its role and when it ends, and a way to withdraw it. */
@Composable
private fun OpenInvitation(teamName: String, i: TeamInvitationDto, busy: Boolean, onCancel: () -> Unit, onCopied: () -> Unit) {
    val context = LocalContext.current
    val code = i.token
    Column(Modifier.fillMaxWidth().appCard(Corners.card).padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(Modifier.size(40.dp).clip(Corners.small).background(Tints.blue.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(if (i.byCode) Icons.Rounded.VpnKey else Icons.Rounded.Phone, contentDescription = null, tint = Tints.blue, modifier = Modifier.size(20.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(
                    if (i.byCode) stringResource(R.string.team_invite_by_code) else i.invited?.name.orEmpty(),
                    color = settingsInk(), fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1,
                )
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                    RolePill(i.role)
                    if (i.status == "expired") StatusPill(stringResource(R.string.team_inv_s_expired), Tints.rose)
                }
                teamDate(i.expiresAt)?.let { Text(stringResource(R.string.team_invite_expires, it), color = settingsMut(), fontSize = 11.5.sp) }
            }
            Text(
                stringResource(R.string.team_invite_cancel), color = Color(0xFFDC2626), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(androidx.compose.foundation.shape.RoundedCornerShape(50)).clickable(enabled = !busy, onClick = onCancel).padding(horizontal = 10.dp, vertical = 8.dp),
            )
        }
        if (code != null) {
            Text(
                code, color = settingsInk(), fontSize = 12.5.sp, fontFamily = FontFamily.Monospace, fontWeight = FontWeight.SemiBold,
                modifier = Modifier.fillMaxWidth().clip(Corners.small).background(settingsMut().copy(alpha = 0.10f)).padding(horizontal = 12.dp, vertical = 10.dp),
            )
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                CodeAction(Icons.Rounded.ContentCopy, stringResource(R.string.team_invite_copy), Modifier.weight(1f)) { copyText(context, code); onCopied() }
                CodeAction(Icons.Rounded.Share, stringResource(R.string.team_invite_share), Modifier.weight(1f)) {
                    shareText(context, context.getString(R.string.team_invite_share_text, teamName, code))
                }
            }
        }
    }
}

@Composable
private fun CodeAction(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, modifier: Modifier, onClick: () -> Unit) {
    Row(
        modifier.height(42.dp).clip(Corners.small).background(settingsAccent().copy(alpha = 0.10f)).clickable(onClick = onClick),
        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(17.dp))
        Text(label, color = settingsAccent(), fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(start = 6.dp))
    }
}
