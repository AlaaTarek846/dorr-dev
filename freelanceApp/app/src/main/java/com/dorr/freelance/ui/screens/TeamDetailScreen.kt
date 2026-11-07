package com.dorr.freelance.ui.screens

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AdminPanelSettings
import androidx.compose.material.icons.rounded.CameraAlt
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Forum
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.PhotoLibrary
import androidx.compose.material.icons.rounded.Handyman
import androidx.compose.material.icons.rounded.Speed
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Logout
import androidx.compose.material.icons.rounded.PersonAdd
import androidx.compose.material.icons.rounded.PersonRemove
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.WorkspacePremium
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AppLocale
import com.dorr.freelance.net.HomeHub
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.Session
import com.dorr.freelance.net.TeamConsentRequest
import com.dorr.freelance.net.TeamDto
import com.dorr.freelance.net.TeamInvitationDto
import com.dorr.freelance.net.TeamInvitationsDto
import com.dorr.freelance.net.TeamInviteRequest
import com.dorr.freelance.net.TeamMembersDto
import com.dorr.freelance.net.TeamPermissionsRequest
import com.dorr.freelance.net.TeamProfileRequest
import com.dorr.freelance.net.TeamRoleRequest
import com.dorr.freelance.net.TeamThreadsDto
import com.dorr.freelance.net.apiDone
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.MessageToast
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.SkeletonList
import com.dorr.freelance.ui.kit.ConfirmDialog
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.Segment
import com.dorr.freelance.ui.kit.SegmentTabs
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.Tints
import com.dorr.freelance.ui.kit.appCard
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsCard
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.locale.LocaleAwareBottomSheet
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * One team's page (FS-209) for somebody in it: the team's head (logo, name, badge, numbers), then its parts — its profile (what clients see
 * and the switches for it), its people (an Owner manages them here), its projects and workload (FS-209b), and its invitations (for the people who may invite). A change goes to the
 * server at once and the page is read again. Nothing runs on a timer: it reads when shown, when a notice arrives and when the connection returns.
 */
@Composable
fun TeamScreen(
    uuid: String, onBack: () -> Unit, onOpenProject: (String) -> Unit = {},
    onOpenThread: (String) -> Unit = {}, onOpenPortfolio: () -> Unit = {}, onOpenVerification: () -> Unit = {}, onOpenPlan: () -> Unit = {},
) {
    val reconnect = collectReconnectTick()
    val context = LocalContext.current
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val savedText = stringResource(R.string.common_saved)
    val leftText = stringResource(R.string.team_left_toast)
    val removedText = stringResource(R.string.team_removed_toast)
    val sentText = stringResource(R.string.team_invite_sent_toast)
    val codeReadyText = stringResource(R.string.team_invite_code_ready)
    val cancelledText = stringResource(R.string.team_invite_cancelled_toast)
    val copiedText = stringResource(R.string.team_invite_copied)
    val photoFailed = stringResource(R.string.login_error_generic)
    val me by Session.me.collectAsState()
    val scope = rememberCoroutineScope()

    var team by remember { mutableStateOf<TeamDto?>(null) }
    var people by remember { mutableStateOf<TeamMembersDto?>(null) }
    var invites by remember { mutableStateOf<TeamInvitationsDto?>(null) }
    // FS-209c: the team's inside chat (its threads and what is unread), read with the page.
    var chat by remember { mutableStateOf<TeamThreadsDto?>(null) }
    var chatFailure by remember { mutableStateOf<String?>(null) }
    var failure by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var uploading by remember { mutableStateOf(false) }
    var logoVersion by remember { mutableLongStateOf(0L) }
    var segment by rememberSaveable { mutableStateOf("profile") }
    var editing by rememberSaveable { mutableStateOf(false) }
    var managing by rememberSaveable { mutableStateOf<String?>(null) }
    var askOwner by rememberSaveable { mutableStateOf<String?>(null) }
    var askRemove by rememberSaveable { mutableStateOf<String?>(null) }
    var askLeave by rememberSaveable { mutableStateOf(false) }

    fun <T> ApiResult<T>.problem(): String? = if (this is ApiResult.Ok) null else errorText(offlineText) ?: genericError

    suspend fun load() {
        val profile = apiCall { ApiClient.teams.profile(uuid) }
        if (profile is ApiResult.Ok) {
            team = profile.data.team
            failure = null
        } else if (profile is ApiResult.Failed && profile.error.httpStatus == 404 && team != null) {
            // I am not in this team any more (removed, or it was deleted): there is nothing to show.
            onBack()
            return
        } else if (team == null) {
            failure = profile.problem()
        }
        val members = apiCall { ApiClient.teams.members(uuid) }
        if (members is ApiResult.Ok) {
            people = members.data
            if (members.data.me.abilities["invite_members"] == true) {
                val open = apiCall { ApiClient.teams.invitations(uuid) }
                if (open is ApiResult.Ok) invites = open.data
            } else {
                invites = null
            }
        }
        val threads = apiCall { ApiClient.teams.chatThreads(uuid) }
        if (threads is ApiResult.Ok) {
            chat = threads.data
            chatFailure = null
        } else if (chat == null) {
            chatFailure = threads.problem()
        }
    }

    /** Runs a change: [block] returns the text of what went wrong (null = it went through); then the page is read again. */
    fun change(done: String? = null, block: suspend () -> String?) {
        notice = null
        busy = true
        scope.launch {
            val problem = block()
            if (problem != null) notice = problem else if (done != null) notice = done
            load()
            busy = false
        }
    }

    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }

    fun saveProfile(request: TeamProfileRequest, done: String? = savedText) =
        change(done) { apiCall { ApiClient.teams.update(uuid, request) }.problem() }

    fun uploadLogo(uri: Uri) {
        uploading = true
        notice = null
        scope.launch {
            val part = withContext(Dispatchers.IO) { logoPart(context, uri) }
            if (part == null) {
                notice = photoFailed
            } else {
                val result = apiCall { ApiClient.teams.logo(uuid, part) }
                if (result is ApiResult.Ok) {
                    team = team?.copy(logoUrl = result.data.logoUrl)
                    logoVersion = System.currentTimeMillis()
                    notice = savedText
                } else {
                    notice = result.problem()
                }
            }
            uploading = false
        }
    }

    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri -> uri?.let(::uploadLogo) }

    val t = team
    val members = people
    val isOwner = t?.me?.role == "owner"
    val canInvite = members?.me?.abilities?.get("invite_members") == true
    val shown = if (segment == "invites" && !canInvite) "profile" else segment
    val managed = members?.members?.firstOrNull { it.freelancer.uuid == managing }

    Page(title = stringResource(R.string.team_title), onBack = onBack) {
        if (t == null) {
            if (failure == null) SkeletonList(count = 2) else Column(
                Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                EmptyState(icon = Icons.Rounded.Groups, title = stringResource(R.string.common_loading_failed), body = failure.orEmpty())
                SettingsPrimaryButton(text = stringResource(R.string.common_retry), onClick = { scope.launch { load() } })
            }
        } else {
            TeamHeader(t, canEdit = isOwner, uploading = uploading, version = logoVersion, onPickLogo = { gallery.launch("image/*") })
            SegmentTabs(
                segments = buildList {
                    add(Segment("profile", stringResource(R.string.team_tab_profile), Icons.Rounded.Info))
                    add(Segment("members", stringResource(R.string.team_tab_members), Icons.Rounded.Groups, count = members?.members?.size ?: 0))
                    add(Segment("projects", stringResource(R.string.tw_tab_projects), Icons.Rounded.Handyman))
                    add(Segment("workload", stringResource(R.string.tw_tab_workload), Icons.Rounded.Speed))
                    add(Segment("chat", stringResource(R.string.tc_tab_chat), Icons.Rounded.Forum, count = chat?.threads?.sumOf { it.unread } ?: 0))
                    if (canInvite) add(Segment("invites", stringResource(R.string.team_tab_invites), Icons.Rounded.PersonAdd, count = invites?.invitations?.size ?: 0))
                },
                selected = shown, onSelect = { segment = it },
            )
            when (shown) {
                "members" -> if (members != null) TeamMembersSection(
                    people = members, myUuid = me?.uuid, isOwner = isOwner, canInvite = canInvite,
                    onManage = { managing = it.freelancer.uuid }, onInvite = { segment = "invites" },
                )
                "projects" -> TeamProjectsList(teamUuid = uuid, role = t.me.role, onOpenProject = onOpenProject)
                "workload" -> TeamWorkloadSection(teamUuid = uuid, role = t.me.role)
                "chat" -> TeamChatSection(teamUuid = uuid, data = chat, failure = chatFailure, onOpenThread = onOpenThread, onReload = { load() })
                "invites" -> TeamInvitesSection(
                    teamName = t.name, data = invites, isOwner = isOwner, busy = busy,
                    onInvite = { phone, role ->
                        change(if (phone == null) codeReadyText else sentText) {
                            apiCall { ApiClient.teams.invite(uuid, TeamInviteRequest(phone, role)) }.problem()
                        }
                    },
                    onCancel = { i: TeamInvitationDto ->
                        change(cancelledText) { apiDone(offlineText, genericError) { ApiClient.teams.cancelInvitation(uuid, i.uuid) } }
                    },
                    onCopied = { notice = null; notice = copiedText },
                )
                else -> TeamProfileSection(
                    t = t, lastOwner = isOwner && (members?.members?.count { it.role == "owner" } ?: 0) <= 1, busy = busy,
                    isOwner = isOwner, onOpenPortfolio = onOpenPortfolio, onOpenVerification = onOpenVerification, onOpenPlan = onOpenPlan,
                    onEdit = { editing = true },
                    onToggleVisibility = {
                        team = t.copy(visibility = if (t.visibility == "public") "private" else "public")
                        saveProfile(TeamProfileRequest(visibility = team?.visibility), done = null)
                    },
                    onToggleAccepting = {
                        team = t.copy(acceptingProjects = !t.acceptingProjects)
                        saveProfile(TeamProfileRequest(acceptingProjects = team?.acceptingProjects), done = null)
                    },
                    onToggleShowMe = {
                        val next = !t.me.showInProfile
                        team = t.copy(me = t.me.copy(showInProfile = next))
                        change { apiCall { ApiClient.teams.consent(uuid, TeamConsentRequest(next)) }.problem() }
                    },
                    onLeave = { askLeave = true },
                )
            }
        }
    }

    if (editing && t != null) EditTeamSheet(
        team = t, busy = busy, uploading = uploading,
        onSave = { name, bio -> editing = false; saveProfile(TeamProfileRequest(name = name, bio = bio)) },
        onPickLogo = { gallery.launch("image/*") },
        onRemoveLogo = {
            change(savedText) { apiCall { ApiClient.teams.removeLogo(uuid) }.problem() }
            team = team?.copy(logoUrl = null)
        },
        onDismiss = { editing = false },
    )

    if (isOwner && managed != null && members != null) ManageMemberSheet(
        member = managed, switches = members.switches, isMe = managed.freelancer.uuid == me?.uuid, busy = busy,
        onRole = { role -> if (role == "owner") askOwner = managed.freelancer.uuid else change(savedText) { apiCall { ApiClient.teams.setRole(uuid, managed.freelancer.uuid.orEmpty(), TeamRoleRequest(role)) }.problem() } },
        onPermission = { key, on ->
            change(null) { apiCall { ApiClient.teams.setPermissions(uuid, managed.freelancer.uuid.orEmpty(), TeamPermissionsRequest(mapOf(key to on))) }.problem() }
        },
        onRemove = { askRemove = managed.freelancer.uuid },
        onDismiss = { managing = null },
    )

    members?.members?.firstOrNull { it.freelancer.uuid == askOwner }?.let { target ->
        ConfirmDialog(
            icon = Icons.Rounded.AdminPanelSettings,
            title = stringResource(R.string.team_role_owner_title, target.freelancer.name.orEmpty()),
            message = stringResource(R.string.team_role_owner_body),
            confirmLabel = stringResource(R.string.team_role_owner_confirm), loading = busy,
            onConfirm = {
                askOwner = null
                change(savedText) { apiCall { ApiClient.teams.setRole(uuid, target.freelancer.uuid.orEmpty(), TeamRoleRequest("owner")) }.problem() }
            },
            onDismiss = { askOwner = null },
        )
    }

    members?.members?.firstOrNull { it.freelancer.uuid == askRemove }?.let { target ->
        ConfirmDialog(
            icon = Icons.Rounded.PersonRemove,
            title = stringResource(R.string.team_remove_title, target.freelancer.name.orEmpty()),
            message = stringResource(R.string.team_remove_body),
            confirmLabel = stringResource(R.string.team_remove), loading = busy,
            onConfirm = {
                askRemove = null
                managing = null
                change(removedText) { apiDone(offlineText, genericError) { ApiClient.teams.remove(uuid, target.freelancer.uuid.orEmpty()) } }
            },
            onDismiss = { askRemove = null },
        )
    }

    if (askLeave) ConfirmDialog(
        icon = Icons.Rounded.Logout,
        title = stringResource(R.string.team_leave_title),
        message = stringResource(R.string.team_leave_body),
        confirmLabel = stringResource(R.string.team_leave), loading = busy,
        onConfirm = {
            notice = null
            busy = true
            scope.launch {
                val problem = apiDone(offlineText, genericError) { ApiClient.teams.leave(uuid) }
                busy = false
                askLeave = false
                if (problem == null) {
                    launch { HomeHub.refresh() }
                    notice = leftText
                    onBack()
                } else {
                    notice = problem
                }
            }
        },
        onDismiss = { askLeave = false },
    )

    MessageToast(notice)
}

/** The head of a team's page: its logo (an Owner changes it from the camera), name and badge, who I am in it, and four numbers. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun TeamHeader(t: TeamDto, canEdit: Boolean, uploading: Boolean, version: Long, onPickLogo: () -> Unit) {
    val accent = settingsAccent()
    val deep = Color(accent.red * 0.62f, accent.green * 0.62f, accent.blue * 0.62f)
    Box(Modifier.fillMaxWidth().clip(Corners.hero).background(Brush.linearGradient(listOf(accent, deep)))) {
        Box(Modifier.size(180.dp).align(Alignment.TopEnd).padding(start = 40.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.07f)))
        Box(Modifier.size(110.dp).align(Alignment.BottomStart).clip(CircleShape).background(Color.White.copy(alpha = 0.06f)))
        Column(Modifier.fillMaxWidth().padding(18.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                Box {
                    TeamLogo(t.logoUrl, 72.dp, onBrand = true, version = version)
                    if (canEdit) Box(
                        Modifier.align(Alignment.BottomEnd).size(28.dp).clip(CircleShape).background(Color.White).clickable(enabled = !uploading, onClick = onPickLogo),
                        contentAlignment = Alignment.Center,
                    ) {
                        if (uploading) CircularProgressIndicator(color = accent, strokeWidth = 2.dp, modifier = Modifier.size(16.dp))
                        else Icon(Icons.Rounded.CameraAlt, contentDescription = stringResource(R.string.team_logo_change), tint = accent, modifier = Modifier.size(16.dp))
                    }
                }
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(t.name, color = Color.White, fontSize = 20.sp, fontWeight = FontWeight.Black, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f, fill = false))
                        if (t.verified) {
                            Spacer(Modifier.width(6.dp))
                            Icon(Icons.Rounded.Verified, contentDescription = stringResource(R.string.team_verified), tint = Color.White, modifier = Modifier.size(20.dp))
                        }
                    }
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        BrandPill(roleLabel(t.me.role ?: "member"))
                        BrandPill(stringResource(if (t.visibility == "public") R.string.team_public else R.string.team_private))
                        when (t.status) {
                            "suspended" -> BrandPill(stringResource(R.string.team_status_suspended))
                            "archived" -> BrandPill(stringResource(R.string.team_status_archived))
                        }
                    }
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                HeaderNumber(t.membersCount.toString(), stringResource(R.string.team_stat_members), Modifier.weight(1f))
                HeaderNumber(t.runningProjects.toString(), stringResource(R.string.team_stat_running), Modifier.weight(1f))
                HeaderNumber(t.stats.completedProjects.toString(), stringResource(R.string.team_stat_done), Modifier.weight(1f))
                HeaderNumber(t.stats.rating?.let { String.format(AppLocale.java(), "%.1f", it) } ?: "—", stringResource(R.string.team_stat_rating), Modifier.weight(1f))
            }
        }
    }
}

@Composable
private fun BrandPill(text: String) {
    Text(
        text, color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1,
        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(Color.White.copy(alpha = 0.20f)).padding(horizontal = 10.dp, vertical = 4.dp),
    )
}

@Composable
private fun HeaderNumber(value: String, label: String, modifier: Modifier) {
    Column(
        modifier.clip(RoundedCornerShape(14.dp)).background(Color.White.copy(alpha = 0.14f)).padding(vertical = 10.dp, horizontal = 4.dp),
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(2.dp),
    ) {
        Text(value, color = Color.White, fontSize = 18.sp, fontWeight = FontWeight.Black, maxLines = 1)
        Text(label, color = Color.White.copy(alpha = 0.85f), fontSize = 10.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/**
 * The «Profile» part: the team's description, the switches (an Owner changes the two about the team; every person decides alone whether
 * they are shown), what the team covers (the union of its people's work), the verification state, and leaving.
 */
@Composable
private fun TeamProfileSection(
    t: TeamDto,
    lastOwner: Boolean,
    busy: Boolean,
    isOwner: Boolean,
    onOpenPortfolio: () -> Unit,
    onOpenVerification: () -> Unit,
    onOpenPlan: () -> Unit,
    onEdit: () -> Unit,
    onToggleVisibility: () -> Unit,
    onToggleAccepting: () -> Unit,
    onToggleShowMe: () -> Unit,
    onLeave: () -> Unit,
) {
    val canEdit = t.me.canEdit
    Column(Modifier.fillMaxWidth().appCard(Corners.card).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.team_about), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            if (canEdit) Row(
                Modifier.clip(RoundedCornerShape(50)).clickable(onClick = onEdit).padding(horizontal = 10.dp, vertical = 6.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Icon(Icons.Rounded.Edit, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(15.dp))
                Text(stringResource(R.string.team_edit), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            }
        }
        val bio = t.bio?.takeIf { it.isNotBlank() }
        Text(
            bio ?: stringResource(if (canEdit) R.string.team_no_bio else R.string.team_no_bio_member),
            color = if (bio != null) settingsInk() else settingsMut(), fontSize = 13.5.sp, lineHeight = 20.sp,
        )
    }

    Column(Modifier.fillMaxWidth().appCard(Corners.card).padding(horizontal = 16.dp, vertical = 12.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(stringResource(R.string.team_settings), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(bottom = 4.dp))
        TeamSwitchRow(
            title = stringResource(R.string.team_set_public),
            subtitle = stringResource(if (t.visibility == "public") R.string.team_set_public_on else R.string.team_set_public_off),
            on = t.visibility == "public", enabled = canEdit && !busy, onToggle = onToggleVisibility,
        )
        TeamSwitchRow(
            title = stringResource(R.string.team_set_accepting), subtitle = stringResource(R.string.team_set_accepting_sub),
            on = t.acceptingProjects, enabled = canEdit && !busy, onToggle = onToggleAccepting,
        )
        Box(Modifier.fillMaxWidth().padding(vertical = 4.dp).height(1.dp).background(settingsMut().copy(alpha = 0.12f)))
        TeamSwitchRow(
            title = stringResource(R.string.team_set_show_me), subtitle = stringResource(R.string.team_set_show_me_sub),
            on = t.me.showInProfile, enabled = !busy, onToggle = onToggleShowMe,
        )
    }

    if (t.specialties.isNotEmpty() || t.skills.isNotEmpty() || t.languages.isNotEmpty()) {
        Column(Modifier.fillMaxWidth().appCard(Corners.card).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(stringResource(R.string.team_expertise), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
            ChipGroup(R.string.team_specialties, t.specialties.mapNotNull { it.name })
            ChipGroup(R.string.team_skills, t.skills.mapNotNull { it.name })
            ChipGroup(R.string.team_languages, t.languages)
        }
    }

    // FS-209c: the team's work (everybody in the team reads it) and its verification (the Owners send the papers).
    TeamNavRow(
        icon = Icons.Rounded.PhotoLibrary, tint = settingsAccent(), title = stringResource(R.string.tpf_row_title),
        subtitle = stringResource(if (isOwner) R.string.tpf_row_owner else R.string.tpf_row_member), onClick = onOpenPortfolio,
    )
    if (isOwner || t.verificationStatus != "none") {
        val (text, tint) = when (t.verificationStatus) {
            "verified" -> R.string.team_ver_verified to Tints.blue
            "rejected" -> R.string.team_ver_rejected to Tints.rose
            "submitted" -> R.string.team_ver_submitted to Tints.amber
            else -> R.string.tv_status_none to Tints.amber
        }
        TeamNavRow(
            icon = Icons.Rounded.Verified, tint = tint, title = stringResource(R.string.team_verification_title), subtitle = stringResource(text),
            onClick = if (isOwner) onOpenVerification else null,
        )
    }

    // FS-211: the team's plan - what it opens for the whole team. The Owners subscribe (an Owner pays from their own wallet), so only they see it.
    if (isOwner) {
        TeamNavRow(
            icon = Icons.Rounded.WorkspacePremium, tint = Tints.blue, title = stringResource(R.string.tpl_row_title),
            subtitle = stringResource(R.string.tpl_row_sub), onClick = onOpenPlan,
        )
    }

    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Box(
            Modifier.fillMaxWidth().height(50.dp).clip(RoundedCornerShape(16.dp))
                .border(1.5.dp, Color(0xFFDC2626).copy(alpha = if (lastOwner) 0.2f else 0.45f), RoundedCornerShape(16.dp))
                .clickable(enabled = !lastOwner && !busy, onClick = onLeave),
            contentAlignment = Alignment.Center,
        ) { Text(stringResource(R.string.team_leave), color = Color(0xFFDC2626).copy(alpha = if (lastOwner) 0.4f else 1f), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold) }
        if (lastOwner) Text(stringResource(R.string.team_leave_last_owner), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
    }
}

/** A card that leads to another page of the team (its works, its verification): an icon, a title, one line under it, and an arrow. */
@Composable
private fun TeamNavRow(icon: androidx.compose.ui.graphics.vector.ImageVector, tint: Color, title: String, subtitle: String, onClick: (() -> Unit)?) {
    Row(
        Modifier.fillMaxWidth().appCard(Corners.card).then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier).padding(16.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(40.dp).clip(Corners.small).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(21.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(title, color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
            Text(subtitle, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 19.sp)
        }
        if (onClick != null) Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(20.dp))
    }
}

/** A small heading and the names under it as soft pills (nothing when there are none). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ChipGroup(title: Int, names: List<String>) {
    if (names.isEmpty()) return
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Text(stringResource(title), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            names.forEach { n ->
                Text(
                    n, color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.10f)).padding(horizontal = 10.dp, vertical = 5.dp),
                )
            }
        }
    }
}

/** An Owner's sheet to edit the team: its name and description, and its logo. */
@Composable
private fun EditTeamSheet(
    team: TeamDto,
    busy: Boolean,
    uploading: Boolean,
    onSave: (name: String, bio: String) -> Unit,
    onPickLogo: () -> Unit,
    onRemoveLogo: () -> Unit,
    onDismiss: () -> Unit,
) {
    var name by rememberSaveable(team.uuid) { mutableStateOf(team.name) }
    var bio by rememberSaveable(team.uuid) { mutableStateOf(team.bio.orEmpty()) }
    LocaleAwareBottomSheet(onDismissRequest = { if (!busy) onDismiss() }, containerColor = settingsCard(), shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp)) {
        Column(
            Modifier.fillMaxWidth().heightIn(max = 640.dp).imePadding().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            Text(stringResource(R.string.team_edit_title), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                TeamLogo(team.logoUrl, 64.dp)
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    SheetLink(stringResource(if (team.logoUrl == null) R.string.team_logo_add else R.string.team_logo_change), enabled = !uploading && !busy, onClick = onPickLogo)
                    if (team.logoUrl != null) SheetLink(stringResource(R.string.team_logo_remove), enabled = !uploading && !busy, danger = true, onClick = onRemoveLogo)
                }
            }
            DorrTextField(
                value = name, onValueChange = { name = it.take(80) }, modifier = Modifier.fillMaxWidth(),
                label = stringResource(R.string.team_name_label), enabled = !busy,
            )
            DorrTextField(
                value = bio, onValueChange = { bio = it.take(2000) }, modifier = Modifier.fillMaxWidth(),
                label = stringResource(R.string.team_bio_label), placeholder = stringResource(R.string.team_bio_hint),
                singleLine = false, minLines = 4, enabled = !busy,
            )
            SettingsPrimaryButton(
                text = stringResource(R.string.common_save), loading = busy, enabled = name.trim().length >= 2,
                onClick = { onSave(name.trim(), bio.trim()) },
            )
        }
    }
}

@Composable
private fun SheetLink(text: String, enabled: Boolean, danger: Boolean = false, onClick: () -> Unit) {
    val tint = if (danger) Color(0xFFDC2626) else settingsAccent()
    Text(
        text, color = tint.copy(alpha = if (enabled) 1f else 0.5f), fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold,
        modifier = Modifier.clip(RoundedCornerShape(50)).clickable(enabled = enabled, onClick = onClick).padding(vertical = 4.dp),
    )
}
