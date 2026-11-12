package com.dorr.provider.ui.screens

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
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Business
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Inbox
import androidx.compose.material.icons.rounded.ConfirmationNumber
import androidx.compose.material.icons.rounded.LocalShipping
import androidx.compose.material.icons.rounded.PersonPin
import androidx.compose.material.icons.rounded.RequestQuote
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material.icons.rounded.Today
import androidx.compose.material.icons.rounded.MarkEmailUnread
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.DocumentsDto
import com.dorr.provider.net.EstablishmentDto
import com.dorr.provider.net.EstablishmentServiceDto
import com.dorr.provider.net.MovingDashboardDto
import com.dorr.provider.net.can
import com.dorr.provider.net.Realtime
import com.dorr.provider.net.Session
import com.dorr.provider.net.Workspace
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.components.SkeletonCard
import com.dorr.provider.ui.kit.AccountDark
import com.dorr.provider.ui.kit.SettingsPrimaryButton
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsCard
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import com.dorr.provider.ui.kit.settingsNight
import com.dorr.provider.ui.kit.settingsSurface
import kotlinx.coroutines.launch

/**
 * The Home tab, in the look of the DORR app home (under the shared header of [com.dorr.provider.ui.components.AppShell]): where the
 * establishment stands (draft, under review, approved, refused with the reason, suspended), the papers still missing with the way to
 * upload them, sending it for review, the invitations waiting for me, and the place the moving requests will take (C-04).
 */
@Composable
fun HomeScreen(establishment: EstablishmentDto?, failed: String?, actions: MainActions, onReload: suspend () -> Unit) {
    val seat by Session.membership.collectAsState()
    val workspace by Workspace.state.collectAsState()
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val submittedOk = stringResource(R.string.pd_submitted_ok)
    val isOwner = seat?.isOwner == true
    val status = establishment?.status

    // The papers are the owner's (the server refuses them to anyone else), read with the home and after any notice.
    var docs by remember { mutableStateOf<DocumentsDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var info by remember { mutableStateOf<String?>(null) }
    suspend fun loadDocs() {
        if (isOwner) (apiCall { ApiClient.workspace.documents() } as? ApiResult.Ok)?.data?.let { docs = it }
    }
    LaunchedEffect(reconnect, isOwner, status) { loadDocs() }
    LaunchedEffect(Unit) { Realtime.changed.collect { loadDocs() } }

    fun submit() {
        busy = true
        error = null
        info = null
        scope.launch {
            val result = apiCall { ApiClient.workspace.submit() }
            busy = false
            if (result is ApiResult.Ok) info = submittedOk else error = result.errorText(offlineText) ?: genericError
            onReload()
            loadDocs()
        }
    }

    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState())) {
        Spacer(Modifier.height(14.dp))
        Column(Modifier.padding(horizontal = 20.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            val invitations = workspace?.invitations.orEmpty()
            if (invitations.isNotEmpty()) {
                BannerRow(Icons.Rounded.MarkEmailUnread, stringResource(R.string.home_invitations_banner, invitations.size), actions.openInvitations)
            }

            if (establishment == null) {
                if (failed == null) {
                    SkeletonCard()
                    SkeletonCard()
                }
            } else {
                StatusCard(establishment)

                // What is still missing before the review, and the way there (the owner's part).
                val required = docs?.required.orEmpty()
                val missingNames = docs?.let { d -> d.required.filter { it.code in d.missing }.map { it.name } }.orEmpty()
                if (isOwner && (status == "draft" || status == "rejected")) {
                    PapersCard(missingNames, hasRequired = required.isNotEmpty(), busy = busy, onOpen = actions.openDocuments, onSubmit = ::submit)
                } else if (isOwner) {
                    BannerRow(Icons.Rounded.Description, stringResource(R.string.home_papers_open), actions.openDocuments)
                }

                establishment.services?.takeIf { it.isNotEmpty() }?.let { ServicesRow(it) }

                // The moving work (C-04): a tile for each number the seat may see.
                MovingDashboard(actions)
            }
            info?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
            ErrorBanner(error ?: failed)
            Spacer(Modifier.height(20.dp))
        }
    }
}

/** The establishment, its type and where its review stands; the reason when it was refused or suspended. */
@Composable
private fun StatusCard(establishment: EstablishmentDto) {
    val status = establishment.status
    val tone = establishmentStatusColor(status)
    val icon = when (status) {
        "approved" -> Icons.Rounded.Verified
        "under_review" -> Icons.Rounded.HourglassTop
        "rejected", "suspended" -> Icons.Rounded.WarningAmber
        else -> Icons.Rounded.Description
    }
    Column(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(if (establishment.type == "company") Icons.Rounded.Business else Icons.Rounded.Person, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(24.dp))
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(establishment.name.orEmpty(), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(establishmentTypeLabel(establishment.type), color = settingsMut(), fontSize = 12.sp)
            }
        }
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(tone.copy(alpha = 0.10f)).padding(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(icon, contentDescription = null, tint = tone, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(8.dp))
            Column(Modifier.weight(1f)) {
                Text(establishmentStatusLabel(status), color = tone, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                Text(statusHint(status), color = settingsInk(), fontSize = 12.sp, lineHeight = 18.sp)
                if (status == "rejected" || status == "suspended") {
                    establishment.statusReason?.takeIf { it.isNotBlank() }?.let {
                        Text(stringResource(R.string.pd_reason, it), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, lineHeight = 18.sp)
                    }
                }
            }
        }
    }
}

@Composable
private fun statusHint(status: String?): String = when (status) {
    "draft" -> stringResource(R.string.home_status_draft_hint)
    "under_review" -> stringResource(R.string.home_status_review_hint)
    "approved" -> stringResource(R.string.home_status_approved_hint)
    "rejected" -> stringResource(R.string.home_status_rejected_hint)
    "suspended" -> stringResource(R.string.home_status_suspended_hint)
    else -> ""
}

/** The papers still missing (by name), the page to upload them, and sending the establishment for review once none is missing. */
@Composable
private fun PapersCard(missing: List<String>, hasRequired: Boolean, busy: Boolean, onOpen: () -> Unit, onSubmit: () -> Unit) {
    Column(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Description, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
            Spacer(Modifier.width(10.dp))
            Text(stringResource(R.string.home_papers_title), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
        }
        when {
            missing.isNotEmpty() -> {
                Text(stringResource(R.string.home_papers_missing), color = settingsMut(), fontSize = 13.sp)
                missing.forEach { Text("• $it", color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold) }
            }
            hasRequired -> Text(stringResource(R.string.home_papers_complete), color = Color(0xFF16A34A), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
            else -> Text(stringResource(R.string.pd_none_required), color = settingsMut(), fontSize = 13.sp)
        }
        Text(
            stringResource(R.string.home_papers_open), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.clickable(onClick = onOpen),
        )
        SettingsPrimaryButton(text = stringResource(R.string.pd_submit), enabled = missing.isEmpty(), loading = busy, onClick = onSubmit)
    }
}

/** The services the establishment works in: the same tiles as the registration (a service switched off shows faded). */
@Composable
private fun ServicesRow(services: List<EstablishmentServiceDto>) {
    Text(stringResource(R.string.home_services_title), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
    ServiceTileGrid(services) { service, tileModifier ->
        ServiceTile(service.name, service.image, selected = false, modifier = tileModifier, dimmed = !service.on)
    }
}

/**
 * «الطلبات» on the home: the numbers of `moving/dashboard`, each a tile that opens its list. A number the seat's permissions don't
 * cover comes back null and its tile is hidden. Read with the home, when the connection returns and on every notice — no timer.
 */
@Composable
private fun MovingDashboard(actions: MainActions) {
    val reconnect = collectReconnectTick()
    val seat by Session.membership.collectAsState()
    var numbers by remember { mutableStateOf<MovingDashboardDto?>(null) }
    suspend fun load() {
        (apiCall { ApiClient.moving.dashboard() } as? ApiResult.Ok)?.data?.let { numbers = it }
    }
    LaunchedEffect(reconnect, seat?.uuid) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }

    val d = numbers ?: return
    val tiles = listOfNotNull(
        d.newRequests?.let { Triple(Icons.Rounded.Inbox, it, R.string.dash_new_requests) to actions.openRequests },
        d.offersAwaitingApproval?.let { Triple(Icons.Rounded.RequestQuote, it, R.string.dash_offers_approval) to { actions.openOffers(true) } },
        d.jobsUnassigned?.let { Triple(Icons.Rounded.PersonPin, it, R.string.dash_jobs_unassigned) to { actions.openJobs(true) } },
        d.jobsRunning?.let { Triple(Icons.Rounded.LocalShipping, it, R.string.dash_jobs_running) to { actions.openJobs(false) } },
        d.jobsToday?.let { Triple(Icons.Rounded.Today, it, R.string.dash_jobs_today) to { actions.openJobs(false) } },
        d.myJobs?.let { Triple(Icons.Rounded.Work, it, R.string.dash_my_jobs) to actions.openMyJobs },
        d.openTickets?.let { Triple(Icons.Rounded.ConfirmationNumber, it, R.string.dash_open_tickets) to actions.openTickets },
    )
    Text(stringResource(R.string.home_requests_title), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
    tiles.chunked(2).forEach { row ->
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            row.forEach { (tile, open) -> CountTile(tile.first, tile.second, stringResource(tile.third), Modifier.weight(1f), onClick = open) }
            if (row.size == 1) Spacer(Modifier.weight(1f))
        }
    }
    // The offers themselves (no number on the dashboard): for whoever sees the requests.
    if (seat.can("requests.view")) BannerRow(Icons.Rounded.RequestQuote, stringResource(R.string.dash_my_offers)) { actions.openOffers(false) }
}

/** A one-line notice on the home that opens a page. */
@Composable
private fun BannerRow(icon: ImageVector, text: String, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).clickable(onClick = onClick).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
        }
        Text(text, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
        Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(20.dp))
    }
}
