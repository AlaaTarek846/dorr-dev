package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyListScope
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Favorite
import androidx.compose.material.icons.rounded.FavoriteBorder
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceInviteRequest
import com.dorr.app.network.FreelanceProjectDto
import com.dorr.app.network.FreelanceTeamFavoriteDto
import com.dorr.app.network.FreelancerTeamBadgeDto
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

/*
 * FS-213: the freelancers' teams the client keeps (next to the freelancers he keeps), and inviting a team to one of his private projects.
 * The invitation goes to the team's Owner, who answers and offers in the team's name. A project takes invitations while it is private and
 * open for offers (FL-205) — the sheet lists those, and says so when there is none.
 */

private val TeamGreen = Color(0xFF16A34A)
private val TeamRed = Color(0xFFDC2626)
private val TeamAmber = Color(0xFFD97706)

/** The heart on a team's page: keeps the team or lets it go. */
@Composable
internal fun TeamFavoriteHeart(team: String, saved: Boolean, onChanged: (Boolean) -> Unit) {
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    Icon(
        if (saved) Icons.Rounded.Favorite else Icons.Rounded.FavoriteBorder,
        stringResource(if (saved) R.string.fl_favorite_remove else R.string.fl_favorite_add),
        tint = if (saved) TeamRed else settingsMut(),
        modifier = Modifier.size(40.dp).clip(CircleShape).clickable(enabled = !busy, role = Role.Button) {
            busy = true
            scope.launch {
                val ok = runCatching { if (saved) ApiClient.freelance.removeTeamFavorite(auth(), team) else ApiClient.freelance.addTeamFavorite(auth(), team) }.isSuccess
                if (ok) onChanged(!saved)
                busy = false
            }
        }.padding(8.dp),
    )
}

/** One kept team in the favorites list: logo, name, figures, «worked together»; tap for its page, the heart lets it go. */
internal fun LazyListScope.teamFavoriteRows(rows: List<FreelanceTeamFavoriteDto>, onOpen: (String) -> Unit, onInvite: (FreelanceTeamFavoriteDto) -> Unit, onRemove: (String) -> Unit) {
    items(rows, key = { "team:" + it.uuid }) { t ->
        Row(
            Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).clickable(role = Role.Button) { onOpen(t.uuid) }.padding(14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            TeamLogo(t.logoUrl, 46)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(t.name, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                    if (t.verified) Icon(Icons.Rounded.Verified, stringResource(R.string.fl_verified), tint = TeamGreen, modifier = Modifier.padding(start = 4.dp).size(16.dp))
                }
                Text(
                    listOfNotNull(
                        teamRatingText(FreelancerTeamBadgeDto(rating = t.rating, reviewsCount = t.reviewsCount)),
                        t.completedProjects?.takeIf { it > 0 }?.let { stringResource(R.string.fl_st_completed_n, it) },
                    ).joinToString("  ·  "),
                    color = settingsMut(), fontSize = 12.sp,
                )
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    FreelancerTeamPill()
                    if (t.workedWith) TeamTag(stringResource(R.string.fteam_worked_with), TeamGreen)
                    if (!t.acceptingProjects) TeamTag(stringResource(R.string.fteam_not_accepting_short), TeamAmber)
                }
            }
            if (t.acceptingProjects) Icon(
                Icons.AutoMirrored.Rounded.Send, stringResource(R.string.fteam_invite), tint = settingsAccent(),
                modifier = Modifier.size(36.dp).clip(CircleShape).clickable(role = Role.Button) { onInvite(t) }.padding(8.dp),
            )
            Icon(
                Icons.Rounded.Favorite, stringResource(R.string.fl_favorite_remove), tint = TeamRed,
                modifier = Modifier.size(36.dp).clip(CircleShape).clickable(role = Role.Button) { onRemove(t.uuid) }.padding(8.dp),
            )
        }
    }
}

@Composable
private fun TeamTag(text: String, color: Color) {
    Text(
        text, color = color, fontSize = 10.5.sp, fontWeight = FontWeight.Bold, maxLines = 1,
        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(color.copy(alpha = 0.12f)).padding(horizontal = 8.dp, vertical = 2.dp),
    )
}

/**
 * «Invite the team to a project»: my private projects that take invitations now; a tap sends the invitation to the team's Owner. Says what
 * happened (sent, or already invited / not taking new projects) and stays open, so the next project is a tap away.
 */
@Composable
internal fun InviteTeamSheet(team: String, teamName: String, onDismiss: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val sentText = stringResource(R.string.fteam_invite_sent, teamName)
    val alreadyText = stringResource(R.string.fteam_invite_already)
    val notAcceptingText = stringResource(R.string.fteam_not_accepting)
    val scope = rememberCoroutineScope()
    var projects by remember { mutableStateOf<List<FreelanceProjectDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var done by remember { mutableStateOf<Pair<String, Boolean>?>(null) }
    var busy by remember { mutableStateOf<String?>(null) }
    val invited = remember { mutableStateOf(setOf<String>()) }

    LaunchedEffect(team) {
        try {
            projects = ApiClient.freelance.projects(auth(), status = "published", perPage = 50).data.orEmpty()
                .filter { it.visibility == "private" && it.invitations?.canInvite == true }
            error = null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    FlSheet(Icons.AutoMirrored.Rounded.Send, stringResource(R.string.fteam_invite_title, teamName), stringResource(R.string.fteam_invite_body), onDismiss) {
        done?.let { (text, ok) -> FlNotice(text, if (ok) Icons.Rounded.CheckCircle else Icons.Rounded.ErrorOutline, if (ok) TeamGreen else TeamAmber) }
        val ps = projects
        when {
            ps == null && error == null -> repeat(3) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(56.dp), RoundedCornerShape(14.dp)) }
            ps == null -> FlNotice(error.orEmpty(), Icons.Rounded.ErrorOutline, TeamRed)
            ps.isEmpty() -> FlHint(stringResource(R.string.fteam_invite_none))
            else -> ps.forEach { p ->
                val sent = p.uuid in invited.value
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = if (sent) 0.14f else 0.06f))
                        .clickable(enabled = busy == null && !sent, role = Role.Button) {
                            busy = p.uuid
                            scope.launch {
                                try {
                                    val r = ApiClient.freelance.invite(auth(), p.uuid, FreelanceInviteRequest(teams = listOf(team))).data?.result
                                    val reason = r?.skipped?.firstOrNull()?.reason
                                    done = when {
                                        (r?.invited ?: 0) > 0 -> { invited.value = invited.value + p.uuid; sentText to true }
                                        reason == "already_invited" || reason == "already_offered" -> { invited.value = invited.value + p.uuid; alreadyText to false }
                                        reason == "team_not_accepting" -> notAcceptingText to false
                                        else -> generic to false
                                    }
                                } catch (e: CancellationException) {
                                    throw e
                                } catch (e: Exception) {
                                    done = e.message(generic) to false
                                }
                                busy = null
                            }
                        }.padding(horizontal = 14.dp, vertical = 12.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    Icon(Icons.Rounded.Lock, null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
                    Column(Modifier.weight(1f)) {
                        Text(p.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        p.invitations?.let { s -> Text(stringResource(R.string.fteam_invite_count, s.sent + s.accepted, s.max), color = settingsMut(), fontSize = 11.5.sp) }
                    }
                    when {
                        busy == p.uuid -> androidx.compose.material3.CircularProgressIndicator(Modifier.size(18.dp), color = settingsAccent(), strokeWidth = 2.dp)
                        sent -> Icon(Icons.Rounded.CheckCircle, null, tint = TeamGreen, modifier = Modifier.size(20.dp))
                        else -> Icon(Icons.AutoMirrored.Rounded.Send, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                    }
                }
            }
        }
        FlHint(stringResource(R.string.fteam_invite_hint))
    }
}

/** The «Invite to a project» button on a team's page (only while it takes new projects). */
@Composable
internal fun InviteTeamAction(team: String, teamName: String) {
    var open by remember { mutableStateOf(false) }
    FlAction(stringResource(R.string.fteam_invite), Icons.AutoMirrored.Rounded.Send, FlTone.Primary) { open = true }
    if (open) InviteTeamSheet(team, teamName) { open = false }
}

/** The kept teams, a page at a time, for the favorites page's «Teams» tab. */
@Composable
internal fun rememberTeamFavorites(): PagedRows<FreelanceTeamFavoriteDto> =
    remember { PagedRows({ it.uuid }, { page -> ApiClient.freelance.teamFavorites(auth(), page = page) }) }

/** The empty «Teams» tab. */
@Composable
internal fun TeamFavoritesEmpty() {
    com.dorr.app.ui.screens.wallet.WaEmpty(Icons.Rounded.Groups, com.dorr.app.ui.screens.wallet.Tone.Pink, stringResource(R.string.fteam_fav_title), stringResource(R.string.fteam_fav_empty))
}
