package com.dorr.freelance.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.ExperienceDto
import com.dorr.freelance.net.apiDone
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.DateField
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.kit.RedToggle
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import kotlinx.coroutines.launch

private const val MAX_EXPERIENCES = 20

/** The freelancer's work history: the list (current jobs first), and a form to add, edit or delete one. Up to 20. */
@Composable
fun ExperiencesScreen(onBack: () -> Unit) {
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val items = remember { mutableStateListOf<ExperienceDto>() }
    var loaded by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    // null = the list; 0 = a new one; else the id being edited (kept across the app being killed).
    var formId by rememberSaveable { mutableStateOf<Int?>(null) }

    LaunchedEffect(reconnect) {
        val result = apiCall { ApiClient.lists.experiences() }
        if (result is ApiResult.Ok) {
            items.clear()
            items.addAll(result.data)
            loaded = true
            error = null
        } else {
            error = result.errorText(offlineText) ?: genericError
        }
    }

    val editing = formId?.let { id -> if (id == 0) ExperienceDto(id = 0) else items.firstOrNull { it.id == id } }
    if (editing != null) {
        BackHandler { formId = null }
        ExperienceForm(
            initial = editing,
            onBack = { formId = null },
            onChanged = { saved, removedId ->
                if (removedId != null) items.removeAll { it.id == removedId }
                if (saved != null) {
                    val at = items.indexOfFirst { it.id == saved.id }
                    if (at >= 0) items[at] = saved else items.add(0, saved)
                }
                formId = null
            },
        )
        return
    }

    Page(title = stringResource(R.string.experiences_title), onBack = onBack) {
        if (loaded) {
            if (items.size < MAX_EXPERIENCES) SettingsPrimaryButton(text = stringResource(R.string.experiences_add), onClick = { formId = 0 })
            if (items.isEmpty()) Text(stringResource(R.string.common_empty), color = settingsMut(), fontSize = 13.sp)
            items.forEach { e ->
                Card {
                    Column(Modifier.fillMaxWidth().clickable { formId = e.id }, verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(e.title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                        e.company?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 13.sp) }
                        Text(
                            "${e.startedOn.orEmpty()} → " + if (e.isCurrent) stringResource(R.string.experience_now) else e.endedOn.orEmpty(),
                            color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
                        )
                    }
                }
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

@Composable
private fun ExperienceForm(initial: ExperienceDto, onBack: () -> Unit, onChanged: (saved: ExperienceDto?, removedId: Int?) -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var title by rememberSaveable { mutableStateOf(initial.title) }
    var company by rememberSaveable { mutableStateOf(initial.company.orEmpty()) }
    var start by rememberSaveable { mutableStateOf(initial.startedOn.orEmpty()) }
    var end by rememberSaveable { mutableStateOf(initial.endedOn.orEmpty()) }
    var current by rememberSaveable { mutableStateOf(initial.isCurrent) }
    var description by rememberSaveable { mutableStateOf(initial.description.orEmpty()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var confirmDelete by remember { mutableStateOf(false) }

    fun save() {
        busy = true
        error = null
        val body = mutableMapOf<String, Any>(
            "title" to title.trim(),
            "company" to company.trim(),
            "started_on" to start,
            "is_current" to current,
            "description" to description.trim(),
        )
        // A current job has no end; the others send theirs (or none).
        if (!current && end.isNotEmpty()) body["ended_on"] = end
        scope.launch {
            val result = if (initial.id == 0) apiCall { ApiClient.lists.addExperience(body) } else apiCall { ApiClient.lists.updateExperience(initial.id, body) }
            busy = false
            if (result is ApiResult.Ok) onChanged(result.data, null) else error = result.errorText(offlineText) ?: genericError
        }
    }

    fun delete() {
        busy = true
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.lists.deleteExperience(initial.id) }
            busy = false
            if (failed == null) onChanged(null, initial.id) else error = failed
        }
    }

    Page(title = stringResource(if (initial.id == 0) R.string.experiences_add else R.string.common_edit), onBack = onBack) {
        Card {
            DorrTextField(title, { title = it }, label = stringResource(R.string.experience_title), modifier = Modifier.fillMaxWidth())
            DorrTextField(company, { company = it }, label = stringResource(R.string.experience_company), modifier = Modifier.fillMaxWidth())
            DateField(start, { start = it }, label = stringResource(R.string.experience_start), modifier = Modifier.fillMaxWidth())
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                RedToggle(on = current, onClick = { current = !current })
                Text(stringResource(R.string.experience_current), color = settingsInk(), fontSize = 14.sp)
            }
            if (!current) DateField(end, { end = it }, label = stringResource(R.string.experience_end), modifier = Modifier.fillMaxWidth())
            DorrTextField(description, { description = it.take(1000) }, label = stringResource(R.string.experience_description), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
        }
        SettingsPrimaryButton(text = stringResource(R.string.profile_save), enabled = title.trim().length >= 2 && start.isNotEmpty(), loading = busy, onClick = ::save)
        if (initial.id != 0) {
            Text(
                stringResource(R.string.common_delete), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clickable { confirmDelete = true }.padding(8.dp),
            )
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    if (confirmDelete) {
        AlertDialog(
            onDismissRequest = { confirmDelete = false },
            text = { Text(stringResource(R.string.common_delete_confirm)) },
            confirmButton = { TextButton(onClick = { confirmDelete = false; delete() }) { Text(stringResource(R.string.common_delete), color = settingsAccent()) } },
            dismissButton = { TextButton(onClick = { confirmDelete = false }) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
        )
    }
}
