package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.snapshots.SnapshotStateList
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.R
import okhttp3.RequestBody.Companion.toRequestBody
import com.dorr.freelance.net.OfferStaffInput
import com.dorr.freelance.net.OfferTeamDto
import com.dorr.freelance.net.TeamMemberDto
import com.dorr.freelance.net.TeamOfferQuotaDto
import com.dorr.freelance.net.TeamPersonRefDto
import com.dorr.freelance.net.TeamSummaryDto
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.FieldLabel
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.FilterChips
import com.dorr.freelance.ui.kit.FilterOption
import com.dorr.freelance.ui.kit.PillChip
import com.dorr.freelance.ui.kit.appCard
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut

/** The most people on one team offer (the server's limit), and the most words about what one takes on. */
private const val STAFF_MAX = 20
private const val RESPONSIBILITY_MAX = 200

/** One person put on a team's offer in the form: `role` = pm | executor. */
data class StaffPick(val freelancer: String, val role: String = "executor", val responsibility: String = "")

internal fun List<StaffPick>.toInputs(): List<OfferStaffInput> = map { OfferStaffInput(it.freelancer, it.role, it.responsibility.trim().ifEmpty { null }) }

/** The multipart fields of a team's offer (`team`, `signer`, `staff[i][...]`) the server reads next to the price and the details. */
internal fun teamOfferFields(team: String?, signer: String?, staff: List<StaffPick>, plain: okhttp3.MediaType?): Map<String, okhttp3.RequestBody> {
    if (team == null) return emptyMap()
    fun text(v: String) = v.toRequestBody(plain)
    val out = linkedMapOf("team" to text(team))
    signer?.let { out["signer"] = text(it) }
    staff.forEachIndexed { i, s ->
        out["staff[$i][freelancer]"] = text(s.freelancer)
        out["staff[$i][role]"] = text(s.role)
        if (s.responsibility.isNotBlank()) out["staff[$i][responsibility]"] = text(s.responsibility.trim())
    }
    return out
}

/**
 * «Send the offer as»: me, or a team I am an Owner or a PM of. For a team it shows the team's offers left this month, who signs and is paid
 * (a PM names the Owner), and who works on the offer. [members] are the team's active people (null while they load).
 */
@Composable
internal fun TeamOfferPicker(
    teams: List<TeamSummaryDto>, teamUuid: String?, onTeam: (String?) -> Unit,
    quota: TeamOfferQuotaDto?, members: List<TeamMemberDto>?, staff: SnapshotStateList<StaffPick>,
    signer: String?, onSigner: (String?) -> Unit, myUuid: String?, compact: Boolean = false,
) {
    val team = teams.firstOrNull { it.uuid == teamUuid }
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        FieldLabel(stringResource(R.string.tw_offer_as))
        FilterChips(
            options = listOf(FilterOption("", stringResource(R.string.tw_scope_me), Icons.Rounded.Person)) + teams.map { FilterOption(it.uuid, it.name, Icons.Rounded.Groups) },
            selected = teamUuid ?: "", onSelect = { onTeam(it.ifEmpty { null }) },
        )
        if (team == null) return@Column

        quota?.let { q ->
            val text = if (q.limit != null && q.left != null) stringResource(R.string.tw_offer_quota_left, q.left, q.limit) else stringResource(R.string.tw_offer_quota_free, q.used)
            Text(
                text, color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.10f)).padding(horizontal = 12.dp, vertical = 5.dp),
            )
        }

        if (compact) return@Column
        val owners = members.orEmpty().filter { it.role == "owner" && it.freelancer.uuid != null }
        val asPm = team.role == "pm"
        if (asPm || owners.size > 1) {
            FieldLabel(stringResource(R.string.tw_offer_signer))
            Text(stringResource(if (asPm) R.string.tw_offer_signer_pm else R.string.tw_offer_signer_owner), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
            FilterChips(
                options = owners.map { FilterOption(it.freelancer.uuid.orEmpty(), it.freelancer.name.orEmpty()) },
                selected = signer ?: if (asPm) "" else myUuid.orEmpty(),
                onSelect = { onSigner(if (!asPm && it == myUuid) null else it) },
            )
        }

        FieldLabel(stringResource(R.string.tw_offer_staff))
        Text(stringResource(R.string.tw_offer_staff_hint), color = settingsMut(), fontSize = 12.5.sp, lineHeight = 18.sp)
        if (members != null) TeamStaffEditor(members, staff)
    }
}

/** The team's people with a tick each; a ticked person gets a role (PM — only one — or executor) and a few words on what they take on. */
@Composable
internal fun TeamStaffEditor(members: List<TeamMemberDto>, staff: SnapshotStateList<StaffPick>) {
    Column(Modifier.fillMaxWidth().appCard(Corners.card).padding(vertical = 4.dp)) {
        members.forEach { m ->
            val uuid = m.freelancer.uuid ?: return@forEach
            val index = staff.indexOfFirst { it.freelancer == uuid }
            val on = index >= 0
            Column(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Row(
                    Modifier.fillMaxWidth().clip(Corners.small).clickable {
                        if (on) staff.removeAt(index) else if (staff.size < STAFF_MAX) staff.add(StaffPick(uuid))
                    },
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
                if (on) {
                    val pick = staff[index]
                    val pmElsewhere = staff.any { it.role == "pm" && it.freelancer != uuid }
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        PillChip(stringResource(R.string.tw_offer_role_exec), pick.role == "executor") { staff[index] = pick.copy(role = "executor") }
                        if (!pmElsewhere) PillChip(stringResource(R.string.tw_offer_role_pm), pick.role == "pm") { staff[index] = pick.copy(role = "pm") }
                    }
                    DorrTextField(
                        pick.responsibility, { v -> staff.getOrNull(index)?.let { staff[index] = it.copy(responsibility = v.take(RESPONSIBILITY_MAX)) } },
                        label = stringResource(R.string.tw_offer_responsibility), icon = Icons.Rounded.Notes, modifier = Modifier.fillMaxWidth(),
                    )
                }
            }
        }
    }
}

/**
 * On an offer in a team's name: the team's name, who sent it when a PM did, and the people on it with their role and what each takes on.
 * [people] are the offer's own list (the client sees first names; I see full names).
 */
@Composable
internal fun TeamOfferInfo(team: OfferTeamDto, submittedBy: TeamPersonRefDto?, showPeople: Boolean = true) {
    Column(Modifier.fillMaxWidth().clip(Corners.card).background(settingsAccent().copy(alpha = 0.08f)).padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(Icons.Rounded.Groups, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
            Text(stringResource(R.string.tw_offer_team_badge, team.name), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
        }
        submittedBy?.name?.let { Text(stringResource(R.string.tw_offer_sent_by, it), color = settingsMut(), fontSize = 12.sp) }
        if (showPeople && team.members.isNotEmpty()) {
            Text(stringResource(R.string.tw_offer_team_people), color = settingsMut(), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
            team.members.forEach { m ->
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    InitialsBadge(m.name, 30.dp)
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(1.dp)) {
                        Text(m.name, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        m.responsibility?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp) }
                    }
                    Text(
                        stringResource(if (m.role == "pm") R.string.tw_offer_role_pm else R.string.tw_offer_role_exec), color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.12f)).padding(horizontal = 9.dp, vertical = 3.dp),
                    )
                }
            }
        }
    }
}
