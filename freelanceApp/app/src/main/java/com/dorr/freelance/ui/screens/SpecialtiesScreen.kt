package com.dorr.freelance.ui.screens

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckBox
import androidx.compose.material.icons.rounded.CheckBoxOutlineBlank
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.StarBorder
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
import kotlinx.coroutines.launch
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
import com.dorr.freelance.net.MySpecialtyDto
import com.dorr.freelance.net.SetSpecialtiesRequest
import com.dorr.freelance.net.SpecialtyNodeDto
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut

private const val MAX_SPECIALTIES = 5

/**
 * The freelancer's specialties: the active tree to pick from (up to 5), one starred as the main one. A specialty the admin
 * stopped after it was picked stays in the list, marked, until it is unchecked. Loaded once when the page opens (and when
 * the connection comes back); the save is one request.
 */
@Composable
fun SpecialtiesScreen(onBack: () -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val limitText = stringResource(R.string.specialties_limit, MAX_SPECIALTIES)

    var tree by remember { mutableStateOf<List<SpecialtyNodeDto>>(emptyList()) }
    var stopped by remember { mutableStateOf<List<MySpecialtyDto>>(emptyList()) }
    val selected = remember { mutableStateListOf<Int>() }
    var primary by remember { mutableStateOf<Int?>(null) }
    var loaded by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var saving by remember { mutableStateOf(false) }

    LaunchedEffect(reconnect) {
        val catalog = apiCall { ApiClient.lists.specialtyTree() }
        val mine = apiCall { ApiClient.lists.mySpecialties() }
        if (catalog is ApiResult.Ok && mine is ApiResult.Ok) {
            tree = catalog.data
            val activeIds = catalog.data.flatMap { listOf(it.id) + it.children.map { c -> c.id } }.toSet()
            stopped = mine.data.filter { it.id !in activeIds }
            selected.clear()
            selected.addAll(mine.data.map { it.id })
            primary = mine.data.firstOrNull { it.isPrimary }?.id ?: mine.data.firstOrNull()?.id
            loaded = true
            error = null
        } else {
            error = (catalog.errorText(offlineText) ?: mine.errorText(offlineText)) ?: genericError
        }
    }

    fun toggle(id: Int) {
        error = null
        if (id in selected) {
            selected.remove(id)
            if (primary == id) primary = selected.firstOrNull()
        } else if (selected.size >= MAX_SPECIALTIES) {
            error = limitText
        } else {
            selected.add(id)
            if (primary == null) primary = id
        }
    }

    fun save() {
        val main = primary ?: return
        saving = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.lists.setSpecialties(SetSpecialtiesRequest(selected.toList(), main)) }
            saving = false
            if (result is ApiResult.Ok) onBack() else error = result.errorText(offlineText) ?: genericError
        }
    }

    Page(title = stringResource(R.string.specialties_title), onBack = onBack) {
        Text(stringResource(R.string.specialties_hint, MAX_SPECIALTIES), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.fillMaxWidth())
        if (loaded) {
            tree.forEach { root ->
                Card {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(root.name, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                    }
                    (listOf(root) + root.children).forEach { node ->
                        SpecialtyRow(node.name, node.id in selected, node.id == primary, indented = node.id != root.id, onToggle = { toggle(node.id) }, onStar = { primary = node.id })
                    }
                }
            }
            if (stopped.isNotEmpty()) {
                Card {
                    stopped.forEach { s ->
                        SpecialtyRow(s.name + " — " + stringResource(R.string.specialties_inactive), s.id in selected, s.id == primary, indented = false, onToggle = { toggle(s.id) }, onStar = { primary = s.id })
                    }
                }
            }
            SettingsPrimaryButton(text = stringResource(R.string.profile_save), enabled = selected.isNotEmpty() && primary != null, loading = saving, onClick = ::save)
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

@Composable
private fun SpecialtyRow(label: String, checked: Boolean, isPrimary: Boolean, indented: Boolean, onToggle: () -> Unit, onStar: () -> Unit) {
    Row(
        modifier = Modifier.fillMaxWidth().clickable(onClick = onToggle).padding(start = if (indented) 16.dp else 0.dp, top = 4.dp, bottom = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Icon(if (checked) Icons.Rounded.CheckBox else Icons.Rounded.CheckBoxOutlineBlank, contentDescription = null, tint = if (checked) settingsAccent() else settingsMut(), modifier = Modifier.size(22.dp))
        Text(label, color = settingsInk(), fontSize = 14.sp, modifier = Modifier.weight(1f))
        if (checked) {
            Row(Modifier.clickable(onClick = onStar), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                Icon(if (isPrimary) Icons.Rounded.Star else Icons.Rounded.StarBorder, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
                if (isPrimary) Text(stringResource(R.string.specialties_primary), color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}
