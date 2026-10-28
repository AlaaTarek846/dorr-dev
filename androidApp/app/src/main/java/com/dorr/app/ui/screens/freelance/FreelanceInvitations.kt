package com.dorr.app.ui.screens.freelance

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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FreelanceInvitationsDto
import com.dorr.app.network.FreelanceInviteRequest
import com.dorr.app.network.FreelanceInviteeDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.profile.SettingsPrimaryButton
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

@Composable
private fun inviteStatusLabel(status: String): String = stringResource(
    when (status) {
        "accepted" -> R.string.fl_inv_s_accepted
        "declined" -> R.string.fl_inv_s_declined
        "withdrawn" -> R.string.fl_inv_s_withdrawn
        "expired" -> R.string.fl_inv_s_expired
        else -> R.string.fl_inv_s_sent
    },
)

private fun inviteStatusColor(status: String): Color = when (status) {
    "accepted" -> Color(0xFF16A34A)
    "declined" -> Color(0xFFDC2626)
    "withdrawn", "expired" -> Color(0xFF6B7280)
    else -> Color(0xFFD97706)
}

/**
 * The invitations of a private project (FL-205): who was invited and what they said, inviting more (a search, the project's specialty
 * first), taking one back, and opening the project to everyone when nobody has accepted. Read when shown and when the connection comes back.
 */
@Composable
internal fun InvitationsPage(projectUuid: String, onBack: () -> Unit, onOpenOffer: (String) -> Unit, onMadePublic: () -> Unit) {
    val generic = stringResource(R.string.fl_error_generic)
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    var data by remember { mutableStateOf<FreelanceInvitationsDto?>(null) }
    var reloads by remember { mutableStateOf(0) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var confirmPublic by remember { mutableStateOf(false) }
    // The picker: the freelancers found, the ones ticked, the search.
    var picking by remember { mutableStateOf(false) }
    var query by remember { mutableStateOf("") }
    var all by remember { mutableStateOf(false) }
    var found by remember { mutableStateOf<List<FreelanceInviteeDto>>(emptyList()) }
    var searching by remember { mutableStateOf(false) }
    val ticked = remember { mutableStateListOf<String>() }
    val sentText = stringResource(R.string.fl_inv_sent)

    LaunchedEffect(projectUuid, reconnect, reloads) {
        try {
            data = ApiClient.freelance.invitations(auth(), projectUuid).data
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
    }
    // Searching runs when the picker opens and when the text or the "everyone" switch changes - not on a timer.
    LaunchedEffect(picking, query, all, reloads) {
        if (!picking) return@LaunchedEffect
        searching = true
        try {
            found = ApiClient.freelance.invitees(auth(), projectUuid, query.trim().ifBlank { null }, if (all) 1 else null).data.orEmpty()
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            error = e.message(generic)
        }
        searching = false
    }

    fun act(block: suspend () -> Unit) {
        busy = true
        error = null
        notice = null
        scope.launch {
            try {
                block()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                error = e.message(generic)
            }
            busy = false
        }
    }

    Column(Modifier.fillMaxSize()) {
        SubHeader(stringResource(R.string.fl_inv_title), onBack)
        val d = data
        if (d == null) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                if (error != null) Text(error.orEmpty(), color = settingsMut(), fontSize = 14.sp, modifier = Modifier.padding(24.dp))
                else CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
            }
            return@Column
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Card {
                Label(stringResource(R.string.fl_inv_summary, d.summary.sent, d.summary.accepted, d.summary.declined))
                Text(stringResource(R.string.fl_inv_how, d.summary.max), color = settingsMut(), fontSize = 12.sp)
                if (d.summary.declined > 0 && d.summary.accepted == 0 && d.summary.sent == 0) {
                    Text(stringResource(R.string.fl_inv_none_left), color = Color(0xFFD97706), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                }
            }
            notice?.let { Text(it, color = Color(0xFF16A34A), fontSize = 13.sp, fontWeight = FontWeight.Bold) }
            error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 13.sp) }

            if (d.invitations.isNotEmpty()) Card {
                Label(stringResource(R.string.fl_inv_list))
                d.invitations.forEach { i ->
                    Column(Modifier.padding(vertical = 4.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(i.freelancer?.name ?: "—", color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                                i.freelancer?.let { Text(inviteeLine(it), color = settingsMut(), fontSize = 11.sp) }
                            }
                            Text(inviteStatusLabel(i.status), color = inviteStatusColor(i.status), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                            i.offer?.let { TextAction(stringResource(R.string.fl_inv_see_offer)) { onOpenOffer(it) } }
                            if (i.canWithdraw) TextAction(stringResource(R.string.fl_inv_withdraw), danger = true) {
                                act { data = ApiClient.freelance.withdrawInvitation(auth(), projectUuid, i.uuid).data }
                            }
                        }
                    }
                }
            }

            if (d.summary.canInvite) {
                if (!picking) SettingsPrimaryButton(text = stringResource(R.string.fl_inv_add), onClick = { picking = true })
                else Card {
                    Label(stringResource(R.string.fl_inv_pick))
                    DorrTextField(query, { query = it.take(100) }, placeholder = stringResource(R.string.fl_inv_search), modifier = Modifier.fillMaxWidth())
                    Chip(stringResource(if (all) R.string.fl_inv_all else R.string.fl_inv_same_specialty), all) { all = !all }
                    if (searching) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
                    else if (found.isEmpty()) Text(stringResource(R.string.fl_inv_none_found), color = settingsMut(), fontSize = 13.sp)
                    found.forEach { f ->
                        val on = f.uuid in ticked
                        Row(Modifier.fillMaxWidth().clickable { if (on) ticked.remove(f.uuid) else ticked.add(f.uuid) }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                            Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) settingsAccent() else settingsMut(), modifier = Modifier.size(22.dp))
                            Column(Modifier.weight(1f).padding(start = 10.dp)) {
                                Text(f.name, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                                Text(inviteeLine(f), color = settingsMut(), fontSize = 11.sp)
                            }
                        }
                    }
                    SettingsPrimaryButton(text = stringResource(R.string.fl_inv_send, ticked.size), loading = busy, enabled = ticked.isNotEmpty(), onClick = {
                        act {
                            data = ApiClient.freelance.invite(auth(), projectUuid, FreelanceInviteRequest(ticked.toList())).data
                            ticked.clear()
                            picking = false
                            notice = sentText
                            reloads++
                        }
                    })
                    TextAction(stringResource(R.string.fl_not_now)) { picking = false; ticked.clear() }
                }
            }
            if (d.summary.canMakePublic) Card {
                Label(stringResource(R.string.fl_inv_public_title))
                Text(stringResource(R.string.fl_inv_public_hint), color = settingsMut(), fontSize = 12.sp)
                TextAction(stringResource(R.string.fl_inv_public)) { confirmPublic = true }
            }
            Spacer(Modifier.height(20.dp))
        }
    }

    if (confirmPublic) ConfirmDialog(
        icon = Icons.Rounded.Close, title = stringResource(R.string.fl_inv_public), message = stringResource(R.string.fl_inv_public_confirm),
        loading = busy, onDismiss = { confirmPublic = false },
        onConfirm = { act { ApiClient.freelance.makePublic(auth(), projectUuid); confirmPublic = false; onMadePublic() } },
    )
}

@Composable
private fun inviteeLine(f: FreelanceInviteeDto): String = listOfNotNull(
    f.rating?.let { stringResource(R.string.fl_inv_rating, it.toString()) },
    f.completedProjects.takeIf { it > 0 }?.let { stringResource(R.string.fl_inv_done, it) },
    f.specialties.firstOrNull()?.name,
).joinToString(" · ")
