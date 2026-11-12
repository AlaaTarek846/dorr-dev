package com.dorr.provider.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Business
import androidx.compose.material.icons.rounded.GroupAdd
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.LockOpen
import androidx.compose.material.icons.rounded.MarkEmailUnread
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.MembershipDto
import com.dorr.provider.net.Session
import com.dorr.provider.net.Workspace
import com.dorr.provider.net.apiDone
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.ui.components.AvatarImage
import com.dorr.provider.ui.components.Card
import com.dorr.provider.ui.components.DorrLogo
import com.dorr.provider.ui.components.EmptyState
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.components.Page
import com.dorr.provider.ui.kit.AccountDark
import com.dorr.provider.ui.kit.ConfirmDialog
import com.dorr.provider.ui.kit.PinkBackdrop
import com.dorr.provider.ui.kit.SettingsPrimaryButton
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import com.dorr.provider.ui.kit.settingsNight
import kotlinx.coroutines.launch

/**
 * After the sign-in (and on every cold start): reads `workspace` once and opens the establishment I work in, the invitations
 * waiting for me, or the onboarding that creates my own establishment. A failed read shows a retry (nothing is asked again by itself
 * except when the connection comes back).
 */
@Composable
fun WorkspaceGateScreen(onHome: () -> Unit, onInvitations: () -> Unit, onOnboarding: () -> Unit) {
    val reconnect = collectReconnectTick()
    var error by remember { mutableStateOf<String?>(null) }
    var attempt by remember { mutableStateOf(0) }
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)

    LaunchedEffect(reconnect, attempt) {
        error = null
        when (val result = Workspace.reload()) {
            is ApiResult.Ok -> when {
                Workspace.pick(result.data) != null -> onHome()
                result.data.invitations.isNotEmpty() -> onInvitations()
                else -> onOnboarding()
            }
            // A 401 already cleared the session (the navigation goes back to sign-in by itself).
            else -> error = result.errorText(offlineText) ?: genericError
        }
    }

    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.padding(24.dp).widthIn(max = 440.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            DorrLogo(width = 120.dp, onDark = settingsNight())
            Spacer(Modifier.height(24.dp))
            if (error == null) {
                CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.size(28.dp))
                Spacer(Modifier.height(12.dp))
                Text(stringResource(R.string.ws_loading), color = settingsMut(), fontSize = 13.sp, textAlign = TextAlign.Center)
            } else {
                Text(error.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center)
                Spacer(Modifier.height(16.dp))
                SettingsPrimaryButton(text = stringResource(R.string.common_retry), onClick = { attempt++ })
            }
        }
    }
}

/**
 * The invitations waiting for my answer (§14): which establishment, and — before I accept — whether the seat is exclusive
 * («حصري»: while I am in that establishment my own establishment cannot send offers). Accepting opens that establishment; with
 * no seat anywhere I can still create my own.
 */
@Composable
fun InvitationsScreen(onBack: () -> Unit, onJoined: () -> Unit, onCreateOwn: () -> Unit, onNothingLeft: () -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val workspace by Workspace.state.collectAsState()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var accepting by remember { mutableStateOf<MembershipDto?>(null) }
    var declining by remember { mutableStateOf<MembershipDto?>(null) }

    LaunchedEffect(reconnect) { Workspace.reload() }

    fun accept(invitation: MembershipDto) {
        busy = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.workspace.accept(invitation.uuid) }
            busy = false
            accepting = null
            if (result is ApiResult.Ok) {
                Session.chooseEstablishment(result.data.establishment?.uuid ?: invitation.establishment?.uuid)
                Workspace.reload()
                onJoined()
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    fun decline(invitation: MembershipDto) {
        busy = true
        error = null
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.workspace.decline(invitation.uuid) }
            busy = false
            declining = null
            if (failed != null) {
                error = failed
                return@launch
            }
            val now = (Workspace.reload() as? ApiResult.Ok)?.data
            if (now != null && now.invitations.isEmpty() && now.memberships.isEmpty()) onNothingLeft()
        }
    }

    val invitations = workspace?.invitations
    Page(title = stringResource(R.string.ws_invitations_title), onBack = onBack) {
        when {
            invitations == null -> CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 40.dp).size(28.dp))
            invitations.isEmpty() -> EmptyState(
                Icons.Rounded.MarkEmailUnread, stringResource(R.string.ws_invitations_empty_title), stringResource(R.string.ws_invitations_empty_body),
                modifier = Modifier.padding(top = 40.dp),
            )
            else -> {
                Text(stringResource(R.string.ws_invitations_intro), color = settingsMut(), fontSize = 13.sp, lineHeight = 20.sp, modifier = Modifier.fillMaxWidth())
                invitations.forEach { invitation ->
                    InvitationCard(invitation, busy, onAccept = { accepting = invitation }, onDecline = { declining = invitation })
                }
            }
        }
        // Someone with no establishment of their own can still open one (C-02), instead of or besides joining.
        if (workspace?.ownsEstablishment == false) {
            Card {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Business, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(24.dp))
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.ws_create_own_title), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                        Text(stringResource(R.string.ws_create_own_body), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
                    }
                }
                Text(
                    stringResource(R.string.ws_create_own_cta), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.clickable(enabled = !busy, onClick = onCreateOwn),
                )
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    accepting?.let { invitation ->
        ConfirmDialog(
            icon = if (invitation.isExclusive) Icons.Rounded.Lock else Icons.Rounded.GroupAdd,
            title = stringResource(R.string.ws_accept_title, invitation.establishment?.name.orEmpty()),
            message = stringResource(if (invitation.isExclusive) R.string.ws_accept_exclusive_message else R.string.ws_accept_message),
            confirmLabel = stringResource(R.string.ws_accept),
            loading = busy,
            onConfirm = { accept(invitation) },
            onDismiss = { if (!busy) accepting = null },
        )
    }
    declining?.let { invitation ->
        ConfirmDialog(
            icon = Icons.Rounded.MarkEmailUnread,
            title = stringResource(R.string.ws_decline_title),
            message = stringResource(R.string.ws_decline_message, invitation.establishment?.name.orEmpty()),
            confirmLabel = stringResource(R.string.ws_decline),
            loading = busy,
            onConfirm = { decline(invitation) },
            onDismiss = { if (!busy) declining = null },
        )
    }
}

@Composable
private fun InvitationCard(invitation: MembershipDto, busy: Boolean, onAccept: () -> Unit, onDecline: () -> Unit) {
    val night = settingsNight()
    val accent = settingsAccent()
    Card {
        Row(verticalAlignment = Alignment.CenterVertically) {
            AvatarImage(url = invitation.establishment?.logo, size = 44.dp)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(
                    invitation.establishment?.name.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold,
                    maxLines = 1, overflow = TextOverflow.Ellipsis,
                )
                Text(establishmentTypeLabel(invitation.establishment?.type), color = settingsMut(), fontSize = 12.sp)
            }
        }
        // The exclusivity, said plainly before the answer (owner's rule, §14 point 5).
        val exclusive = invitation.isExclusive
        val tone = if (exclusive) Color(0xFFD97706) else Color(0xFF16A34A)
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp))
                .background(if (night) AccountDark.well else tone.copy(alpha = 0.10f))
                .border(1.dp, tone.copy(alpha = 0.35f), RoundedCornerShape(14.dp))
                .padding(12.dp),
            verticalAlignment = Alignment.Top,
        ) {
            Icon(if (exclusive) Icons.Rounded.Lock else Icons.Rounded.LockOpen, contentDescription = null, tint = tone, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(8.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(
                    stringResource(if (exclusive) R.string.ws_exclusive else R.string.ws_not_exclusive),
                    color = tone, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold,
                )
                Text(
                    stringResource(if (exclusive) R.string.ws_exclusive_body else R.string.ws_not_exclusive_body),
                    color = settingsInk(), fontSize = 12.sp, lineHeight = 18.sp,
                )
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier.weight(1f).height(44.dp).clip(RoundedCornerShape(14.dp)).background(accent.copy(alpha = if (busy) 0.6f else 1f))
                    .clickable(enabled = !busy, onClick = onAccept),
                contentAlignment = Alignment.Center,
            ) { Text(stringResource(R.string.ws_accept), color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.Bold) }
            Box(
                Modifier.weight(1f).height(44.dp).clip(RoundedCornerShape(14.dp))
                    .border(1.5.dp, if (night) AccountDark.line else accent.copy(alpha = 0.25f), RoundedCornerShape(14.dp))
                    .clickable(enabled = !busy, onClick = onDecline),
                contentAlignment = Alignment.Center,
            ) { Text(stringResource(R.string.ws_decline), color = accent, fontSize = 14.sp, fontWeight = FontWeight.Bold) }
        }
    }
}

/** «فرد» / «شركة». */
@Composable
internal fun establishmentTypeLabel(type: String?): String = when (type) {
    "company" -> stringResource(R.string.est_type_company)
    "individual" -> stringResource(R.string.est_type_individual)
    else -> type.orEmpty()
}

/** The establishment's review status in the app language (EstablishmentStatus). */
@Composable
internal fun establishmentStatusLabel(status: String?): String = when (status) {
    "draft" -> stringResource(R.string.est_status_draft)
    "under_review" -> stringResource(R.string.est_status_under_review)
    "approved" -> stringResource(R.string.est_status_approved)
    "rejected" -> stringResource(R.string.est_status_rejected)
    "suspended" -> stringResource(R.string.est_status_suspended)
    else -> status.orEmpty()
}

/** The colour that goes with a status: green approved, amber waiting, red refused or stopped. */
internal fun establishmentStatusColor(status: String?): Color = when (status) {
    "approved" -> Color(0xFF16A34A)
    "under_review" -> Color(0xFFD97706)
    "rejected", "suspended" -> Color(0xFFDC2626)
    else -> Color(0xFF64748B)
}
