package com.dorr.freelance.ui.screens

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.InvitationDto
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import kotlinx.coroutines.launch

@Composable
fun invitationStatusLabel(status: String): String = stringResource(
    when (status) {
        "accepted" -> R.string.inv_s_accepted
        "declined" -> R.string.inv_s_declined
        else -> R.string.inv_s_sent
    },
)

/** The yes / no buttons of an invitation; [onAnswered] gets the new status. Used on the list and on the project page. */
@Composable
fun InvitationAnswer(uuid: String, onAnswered: (String) -> Unit, onError: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var busy by remember { mutableStateOf(false) }
    fun answer(accept: Boolean) {
        busy = true
        scope.launch {
            val result = apiCall { if (accept) ApiClient.invitations.accept(uuid) else ApiClient.invitations.decline(uuid) }
            if (result is ApiResult.Ok) onAnswered(result.data.status) else onError(result.errorText(offlineText) ?: genericError)
            busy = false
        }
    }
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        SettingsPrimaryButton(text = stringResource(R.string.inv_accept), loading = busy, modifier = Modifier.weight(1f), onClick = { answer(true) })
        Text(
            stringResource(R.string.inv_decline), color = Color(0xFFDC2626), fontSize = 14.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.clickable(enabled = !busy) { answer(false) }.padding(12.dp),
        )
    }
}

/** My invitations (FL-205). Read when shown, when a notice arrives and when the connection comes back - nothing on a timer. */
@Composable
fun InvitationsScreen(onBack: () -> Unit, onOpenProject: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var rows by remember { mutableStateOf<List<InvitationDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    suspend fun load() {
        val result = apiCall { ApiClient.invitations.list() }
        if (result is ApiResult.Ok) rows = result.data else error = result.errorText(offlineText) ?: genericError
    }
    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { com.dorr.freelance.net.Realtime.changed.collect { load() } }

    Page(title = stringResource(R.string.inv_title), onBack = onBack) {
        val list = rows
        when {
            list == null && error == null -> CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
            list != null && list.isEmpty() -> Text(stringResource(R.string.inv_none), color = settingsMut(), fontSize = 14.sp)
            list != null -> list.forEach { i ->
                Card {
                    Row(Modifier.fillMaxWidth().clickable { i.project?.uuid?.let(onOpenProject) }) {
                        Text(i.project?.title.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                        Text(invitationStatusLabel(i.status), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    }
                    i.project?.summary?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 13.sp, maxLines = 3) }
                    if (!i.open && i.status == "sent") Text(stringResource(R.string.inv_closed), color = settingsMut(), fontSize = 12.sp)
                    if (i.can.accept) InvitationAnswer(
                        i.uuid,
                        onAnswered = { s -> rows = rows?.map { if (it.uuid == i.uuid) it.copy(status = s, can = it.can.copy(accept = false, decline = false, offer = s == "accepted" && !it.offered)) else it } },
                        onError = { error = it },
                    )
                    if (i.can.offer) Text(
                        stringResource(R.string.inv_send_offer), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clickable { i.project?.uuid?.let(onOpenProject) }.padding(vertical = 4.dp),
                    )
                }
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}
