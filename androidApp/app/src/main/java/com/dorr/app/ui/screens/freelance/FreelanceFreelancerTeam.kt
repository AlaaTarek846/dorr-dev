package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Collections
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.Bolt
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelancerTeamBadgeDto
import com.dorr.app.network.FreelancerTeamPersonDto
import com.dorr.app.network.FreelancerTeamProfileDto
import com.dorr.app.network.FreelancerTeamStaffDto
import com.dorr.app.network.FreelancerTeamViewDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.profile.settingsSurface
import kotlinx.coroutines.CancellationException

/*
 * FS-210: a freelancers' team, as the client sees it. On an offer and on the agreement: the team's name and logo, its badge and its own figures
 * (never its Owner's personal ones), and who works on the job — first name, role, what each takes on (D10). On its own page: the team's profile
 * (about, the people who agreed to be shown, skills, work, what clients said). Read once when a page opens and when the connection returns.
 */

private val Green = Color(0xFF16A34A)
private val Amber = Color(0xFFD97706)

// ---------------------------------------------------------------- the small parts

/** A team's logo, drawn as a rounded square (a person is a circle); its icon until it has one. */
@Composable
internal fun TeamLogo(logoUrl: String?, size: Int = 46) {
    Box(Modifier.size(size.dp).clip(RoundedCornerShape((size * 0.3f).dp)).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
        if (!logoUrl.isNullOrBlank()) AsyncImage(model = logoUrl, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
        else Icon(Icons.Rounded.Groups, null, tint = settingsAccent(), modifier = Modifier.size((size * 0.5f).dp))
    }
}

/** «Team» — what tells a team's offer from a person's at a glance. */
@Composable
internal fun FreelancerTeamPill(modifier: Modifier = Modifier) {
    val accent = settingsAccent()
    Row(
        modifier.clip(RoundedCornerShape(999.dp)).background(accent.copy(alpha = 0.12f)).padding(horizontal = 8.dp, vertical = 2.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(3.dp),
    ) {
        Icon(Icons.Rounded.Groups, null, tint = accent, modifier = Modifier.size(12.dp))
        Text(stringResource(R.string.fteam_label), color = accent, fontSize = 10.5.sp, fontWeight = FontWeight.ExtraBold)
    }
}

/** «New on DORR» until the team has three ratings, like a freelancer. */
@Composable
internal fun teamRatingText(b: FreelancerTeamBadgeDto): String =
    b.rating?.let { stringResource(R.string.fl_rating, it, b.reviewsCount ?: 0) }
        ?: b.reviewsCount?.takeIf { it > 0 }?.let { stringResource(R.string.fl_rating_few, it) }
        ?: stringResource(R.string.fl_rating_new)

/** The line under a team's name in a list: its rating, the projects it finished and how many people are on the offer. */
@Composable
internal fun teamFigures(t: FreelancerTeamViewDto): String = listOfNotNull(
    teamRatingText(t.profile),
    t.profile.completedProjects?.takeIf { it > 0 }?.let { stringResource(R.string.fl_st_completed_n, it) },
    t.members.size.takeIf { it > 0 }?.let { stringResource(R.string.fteam_staff_count, it) },
).joinToString("  ·  ")

@Composable
private fun roleLabel(role: String): String = stringResource(
    when (role) {
        "pm" -> R.string.fteam_role_pm
        "executor" -> R.string.fteam_role_executor
        "owner" -> R.string.fteam_role_owner
        else -> R.string.fteam_role_member
    },
)

@Composable
private fun RolePill(role: String) {
    val tint = if (role == "pm" || role == "owner") settingsAccent() else settingsMut()
    Text(
        roleLabel(role), color = tint, fontSize = 10.5.sp, fontWeight = FontWeight.Bold, maxLines = 1,
        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(tint.copy(alpha = 0.12f)).padding(horizontal = 8.dp, vertical = 2.dp),
    )
}

/** A person without a photo: the first letter of their name on the app's colour. */
@Composable
private fun NameBubble(name: String, avatarUrl: String? = null, size: Int = 38, ring: Color? = null) {
    val base = Modifier.size(size.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.14f))
    Box(if (ring != null) base.border(2.dp, ring, CircleShape) else base, contentAlignment = Alignment.Center) {
        if (!avatarUrl.isNullOrBlank()) AsyncImage(model = avatarUrl, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
        else Text(name.take(1), color = settingsAccent(), fontSize = (size / 2.4f).sp, fontWeight = FontWeight.ExtraBold)
    }
}

/** The people of an offer as overlapping first letters, and «+n» for the rest — the quick look in a list. */
@Composable
internal fun StaffStack(members: List<FreelancerTeamStaffDto>, modifier: Modifier = Modifier, max: Int = 4) {
    if (members.isEmpty()) return
    val ring = if (settingsNight()) AccountDark.card else Color.White
    Row(modifier, verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy((-8).dp)) {
        members.take(max).forEach { NameBubble(it.name, size = 28, ring = ring) }
        if (members.size > max) Box(
            Modifier.size(28.dp).clip(CircleShape).background(settingsMut().copy(alpha = 0.18f)).border(2.dp, ring, CircleShape), contentAlignment = Alignment.Center,
        ) { Text("+${members.size - max}", color = settingsMut(), fontSize = 10.sp, fontWeight = FontWeight.ExtraBold) }
    }
}

// ---------------------------------------------------------------- on an offer and on the agreement

/**
 * The card that says who the job is with: the team (tap for its profile). [extra] adds a line under the figures (the offer's status).
 */
@Composable
internal fun TeamIdentityCard(t: FreelancerTeamViewDto, onOpen: () -> Unit, extra: @Composable ColumnScope.() -> Unit = {}) {
    Row(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).clickable(role = Role.Button, onClick = onOpen).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        TeamLogo(t.profile.logoUrl, 52)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(t.name, color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f, fill = false))
                if (t.profile.verified) Icon(Icons.Rounded.Verified, stringResource(R.string.fl_verified), tint = Green, modifier = Modifier.padding(start = 4.dp).size(18.dp))
            }
            FreelancerTeamPill()
            Text(teamFigures(t), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
            Text(stringResource(R.string.fteam_view_profile), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            extra()
        }
    }
}

/** Each person of the offer: first name, role and what they take on. */
@Composable
internal fun TeamStaffRows(members: List<FreelancerTeamStaffDto>) {
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        members.forEach { m ->
            Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                NameBubble(m.name)
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Text(m.name, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                        RolePill(m.role)
                    }
                    m.responsibility?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.5.sp, lineHeight = 19.sp) }
                }
            }
        }
    }
}

/**
 * «Who works on it»: the people the offer names, and — when it is known — who signs for the team (tap for that person's profile). The same
 * card sits on the agreement, where the people are the ones that were named when the work started.
 */
@Composable
internal fun TeamWorkCard(
    members: List<FreelancerTeamStaffDto>, signer: String? = null, onSigner: (() -> Unit)? = null, title: String = stringResource(R.string.fteam_work_title),
    /** A button to the team's profile at the foot (null: none — the page already has the team's card). */
    onProfile: (() -> Unit)? = null,
) {
    Card(Icons.Rounded.Groups, title) {
        if (members.isEmpty()) Text(stringResource(R.string.fteam_staff_empty), color = settingsMut(), fontSize = 13.sp)
        else TeamStaffRows(members)
        if (!signer.isNullOrBlank()) Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.07f))
                .then(if (onSigner != null) Modifier.clickable(role = Role.Button, onClick = onSigner) else Modifier).padding(horizontal = 12.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Icon(Icons.Rounded.Edit, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.fteam_signs), color = settingsMut(), fontSize = 11.sp)
                Text(signer, color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            if (onSigner != null) Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = settingsMut(), modifier = Modifier.size(20.dp))
        }
        Text(stringResource(R.string.fteam_work_hint), color = settingsMut(), fontSize = 11.5.sp, lineHeight = 17.sp)
        if (onProfile != null) FlAction(stringResource(R.string.fteam_view_profile), Icons.Rounded.Groups, onClick = onProfile)
    }
}

// ---------------------------------------------------------------- the team's own page

@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun FreelancerTeamPage(team: String, onBack: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val reconnect = collectReconnectTick()
    var profile by remember { mutableStateOf<FreelancerTeamProfileDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    // FL-L13: a piece of the team's work being reported as not theirs.
    var reporting by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(team, reconnect) {
        try {
            val read = ApiClient.freelance.freelancerTeam(auth(), team).data?.team
            profile = read
            error = if (read == null) generic else null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }

    Column(Modifier.fillMaxSize()) {
        FlPageHeader(stringResource(R.string.fteam_profile_title), onBack)
        val p = profile
        if (p == null) {
            FlLoading(error)
            return@Column
        }
        val s = p.stats
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            // Who: logo, name, badge, what it is taking on, its figures.
            Column(Modifier.fillMaxWidth().settingsSurface(FlCorners.card).padding(16.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(6.dp)) {
                TeamLogo(p.logoUrl, 84)
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center) {
                    Text(p.name, color = settingsInk(), fontSize = 19.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, modifier = Modifier.weight(1f, fill = false))
                    if (p.verified) Icon(Icons.Rounded.Verified, stringResource(R.string.fl_verified), tint = Green, modifier = Modifier.padding(start = 4.dp).size(19.dp))
                }
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
                    FreelancerTeamPill()
                    val open = p.acceptingProjects
                    Text(
                        stringResource(if (open) R.string.fteam_accepting else R.string.fteam_not_accepting), color = if (open) Green else Amber, fontSize = 10.5.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background((if (open) Green else Amber).copy(alpha = 0.12f)).padding(horizontal = 8.dp, vertical = 2.dp),
                    )
                }
                Text(
                    listOfNotNull(
                        s.rating?.let { stringResource(R.string.fl_rating, it, s.reviewsCount ?: 0) } ?: s.reviewsCount?.takeIf { it > 0 }?.let { stringResource(R.string.fl_rating_few, it) } ?: stringResource(R.string.fl_rating_new),
                        p.runningProjects.takeIf { it > 0 }?.let { "${stringResource(R.string.fteam_running_n)}: $it" },
                    ).joinToString("  ·  "),
                    color = settingsMut(), fontSize = 12.sp, textAlign = TextAlign.Center,
                )
                Text(stringResource(R.string.fl_contact_in_dorr), color = settingsMut(), fontSize = 11.sp, textAlign = TextAlign.Center)
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FlStat(Icons.Rounded.TaskAlt, stringResource(R.string.fl_st_completed), "${s.completedProjects ?: 0}", Modifier.weight(1f))
                FlStat(Icons.Rounded.Schedule, stringResource(R.string.fl_st_on_time), s.onTimeRate?.let { "$it%" } ?: "—", Modifier.weight(1f))
                FlStat(Icons.Rounded.Bolt, stringResource(R.string.fteam_completion), s.completionRate?.let { "$it%" } ?: "—", Modifier.weight(1f))
            }
            p.bio?.takeIf { it.isNotBlank() }?.let { Card(Icons.Rounded.Info, stringResource(R.string.fl_about)) { Text(it, color = settingsInk(), fontSize = 13.sp, lineHeight = 20.sp) } }
            // The people: only the ones who agreed to be shown, by first name (D10) — the rest are counted.
            Card(Icons.Rounded.Groups, stringResource(R.string.fteam_members_title)) {
                if (p.members.isNotEmpty()) Column(verticalArrangement = Arrangement.spacedBy(12.dp)) { p.members.forEach { PersonRow(it) } }
                Text(stringResource(R.string.fteam_members_hint, p.membersCount), color = settingsMut(), fontSize = 11.5.sp, lineHeight = 17.sp)
            }
            if (p.specialties.isNotEmpty() || p.skills.isNotEmpty()) Card(Icons.Rounded.Bolt, stringResource(R.string.fl_skills)) {
                if (p.specialties.isNotEmpty()) Field(stringResource(R.string.fl_specialty), p.specialties.joinToString("، ") { it.name })
                if (p.skills.isNotEmpty()) FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    p.skills.forEach { sk ->
                        Text(
                            sk.name, color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
                            modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.08f)).padding(horizontal = 10.dp, vertical = 4.dp),
                        )
                    }
                }
            }
            if (p.languages.isNotEmpty() || !p.since.isNullOrBlank()) Card(Icons.Rounded.Language, stringResource(R.string.fl_languages)) {
                if (p.languages.isNotEmpty()) Field(stringResource(R.string.fl_languages), p.languages.joinToString("، "))
                p.since?.takeIf { it.isNotBlank() }?.let { Field(stringResource(R.string.fl_member_since), "‎" + it.take(7)) }
            }
            if (p.portfolio.isNotEmpty()) Card(Icons.Rounded.Collections, stringResource(R.string.fl_portfolio) + " (${p.portfolio.size})") {
                p.portfolio.forEach { w ->
                    Row(Modifier.padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        val cover = w.images.firstOrNull()?.url
                        Box(Modifier.size(56.dp).clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.08f))) {
                            if (cover != null) AsyncImage(model = cover, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                        }
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(w.title.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            w.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
                        }
                        w.uuid?.let { id -> Icon(Icons.Rounded.Flag, stringResource(R.string.rights_report), tint = settingsMut(), modifier = Modifier.size(34.dp).clip(RoundedCornerShape(50)).clickable { reporting = id }.padding(8.dp)) }
                    }
                }
            }
            // FL-701: what clients said about the team's work.
            if (p.reviews.isNotEmpty() || (s.completedProjects ?: 0) > 0) Card(Icons.Rounded.Star, stringResource(R.string.fl_rv_about)) {
                Text(
                    listOfNotNull(s.completedProjects?.let { stringResource(R.string.fl_st_completed_n, it) }, s.onTimeRate?.let { stringResource(R.string.fl_st_on_time_n, it) }).joinToString("  ·  "),
                    color = settingsMut(), fontSize = 12.sp,
                )
                p.reviews.forEach { r ->
                    Column(Modifier.padding(vertical = 4.dp)) {
                        ReviewStars(r.stars)
                        r.comment?.let { Text(it, color = settingsInk(), fontSize = 13.sp) }
                        Text(listOfNotNull(r.project, r.at?.take(10)?.let { "‎$it" }).joinToString(" · "), color = settingsMut(), fontSize = 11.sp)
                    }
                }
            }
            Spacer(Modifier.height(20.dp))
        }
    }

    reporting?.let { id -> ReportWorkSheet("portfolio_item", id, onDismiss = { reporting = null }) }
}

/** A person who agreed to be shown on the team's profile. */
@Composable
private fun PersonRow(m: FreelancerTeamPersonDto) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        NameBubble(m.name, m.avatarUrl, 44)
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(m.name, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                RolePill(m.role)
            }
            m.headline?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis) }
        }
    }
}
