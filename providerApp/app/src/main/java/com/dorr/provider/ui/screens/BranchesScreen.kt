package com.dorr.provider.ui.screens

import android.Manifest
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.DeleteOutline
import androidx.compose.material.icons.rounded.Explore
import androidx.compose.material.icons.rounded.MyLocation
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.Store
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.BranchBody
import com.dorr.provider.net.BranchDto
import com.dorr.provider.net.apiDone
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.net.jsonBody
import com.dorr.provider.ui.components.Card
import com.dorr.provider.ui.components.DorrTextField
import com.dorr.provider.ui.components.EmptyState
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.components.FormGroup
import com.dorr.provider.ui.components.FormIntro
import com.dorr.provider.ui.components.Page
import com.dorr.provider.ui.kit.ConfirmDialog
import com.dorr.provider.ui.kit.RedToggle
import com.dorr.provider.ui.kit.SettingsPrimaryButton
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import kotlinx.coroutines.launch

/**
 * The establishment's branches (C-03, `branches.manage`): each with its place, the range it serves and whether it takes requests.
 * Adding or editing one sets its place from the phone's current location (a map comes later); the main branch can't be switched
 * off or deleted. Read once on open and when the connection returns.
 */
@Composable
fun BranchesScreen(onBack: () -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var branches by remember { mutableStateOf<List<BranchDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    // null = the list; "new" = a new branch; else the uuid being edited.
    var editing by rememberSaveable { mutableStateOf<String?>(null) }
    var deleting by remember { mutableStateOf<BranchDto?>(null) }
    var busy by remember { mutableStateOf(false) }

    suspend fun reload() {
        when (val result = apiCall { ApiClient.manage.branches() }) {
            is ApiResult.Ok -> {
                branches = result.data
                error = null
            }
            else -> error = result.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) { reload() }

    val list = branches
    if (editing != null) {
        val branch = list?.firstOrNull { it.uuid == editing }
        BackHandler { editing = null }
        BranchForm(branch, onBack = { editing = null }, onSaved = { scope.launch { reload(); editing = null } })
        return
    }

    fun delete(branch: BranchDto) {
        busy = true
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.manage.deleteBranch(branch.uuid.orEmpty()) }
            busy = false
            deleting = null
            if (failed != null) error = failed else reload()
        }
    }

    Page(title = stringResource(R.string.br_title), onBack = onBack) {
        when {
            list == null -> if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 40.dp).size(28.dp))
            list.isEmpty() -> EmptyState(Icons.Rounded.Store, stringResource(R.string.br_empty_title), stringResource(R.string.br_empty_body), Modifier.padding(top = 30.dp))
            else -> list.forEach { branch ->
                Card {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(branch.name.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                            branch.address?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
                            branch.radiusKm?.let { Text(stringResource(R.string.br_radius_line, formatKm(it)), color = settingsMut(), fontSize = 12.sp) }
                        }
                        if (branch.isMain) StatusPill(stringResource(R.string.br_main), settingsAccent())
                        else StatusPill(stringResource(if (branch.status) R.string.br_active else R.string.br_inactive), if (branch.status) Green else Slate)
                    }
                    Row {
                        Text(
                            stringResource(R.string.common_edit), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.clickable(enabled = !busy) { editing = branch.uuid },
                        )
                        if (!branch.isMain) {
                            Spacer(Modifier.width(20.dp))
                            Text(
                                stringResource(R.string.common_delete), color = Red, fontSize = 14.sp, fontWeight = FontWeight.Bold,
                                modifier = Modifier.clickable(enabled = !busy) { deleting = branch },
                            )
                        }
                    }
                }
            }
        }
        SettingsPrimaryButton(text = stringResource(R.string.br_add), onClick = { editing = "new" })
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    deleting?.let { branch ->
        ConfirmDialog(
            icon = Icons.Rounded.DeleteOutline,
            title = stringResource(R.string.br_delete_title),
            message = stringResource(R.string.br_delete_message, branch.name.orEmpty()),
            confirmLabel = stringResource(R.string.common_delete),
            loading = busy,
            onConfirm = { delete(branch) },
            onDismiss = { if (!busy) deleting = null },
        )
    }
}

/** "12.5" without a trailing ".0". */
internal fun formatKm(km: Double): String = if (km % 1.0 == 0.0) km.toLong().toString() else km.toString()

/** Adding a branch ([branch] null) or editing one: name, place (current location, or the one it has), address, range, on / off. */
@Composable
private fun BranchForm(branch: BranchDto?, onBack: () -> Unit, onSaved: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val locationFailed = stringResource(R.string.onb_location_failed)

    var name by rememberSaveable { mutableStateOf(branch?.name.orEmpty()) }
    var address by rememberSaveable { mutableStateOf(branch?.address.orEmpty()) }
    var radius by rememberSaveable { mutableStateOf(branch?.radiusKm?.let(::formatKm).orEmpty()) }
    var lat by rememberSaveable { mutableStateOf(branch?.lat) }
    var lng by rememberSaveable { mutableStateOf(branch?.lng) }
    var active by rememberSaveable { mutableStateOf(branch?.status ?: true) }
    var locating by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun locate() {
        locating = true
        scope.launch {
            val here = currentPosition(context)
            locating = false
            if (here == null) error = locationFailed else {
                lat = here.first
                lng = here.second
            }
        }
    }
    val askLocation = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted ->
        if (granted.values.any { it }) locate() else error = locationFailed
    }

    val radiusValue = radius.replace(',', '.').toDoubleOrNull()
    val radiusOk = radius.isBlank() || (radiusValue != null && radiusValue in 0.5..500.0)
    val ready = name.isNotBlank() && lat != null && lng != null && radiusOk

    fun save() {
        val latitude = lat ?: return
        val longitude = lng ?: return
        busy = true
        error = null
        scope.launch {
            val body = BranchBody(
                name = name.trim(), lat = latitude, lng = longitude, radiusKm = radiusValue, address = address.trim().ifEmpty { null },
                // The main branch always takes requests.
                status = if (branch?.isMain == true) true else active,
            )
            val result = if (branch == null) apiCall { ApiClient.manage.addBranch(jsonBody(body)) } else apiCall { ApiClient.manage.updateBranch(branch.uuid.orEmpty(), jsonBody(body)) }
            busy = false
            if (result is ApiResult.Ok) onSaved() else error = result.errorText(offlineText) ?: genericError
        }
    }

    Page(title = stringResource(if (branch == null) R.string.br_add else R.string.br_edit), onBack = onBack) {
        FormIntro(Icons.Rounded.Store, stringResource(if (branch == null) R.string.br_add else R.string.br_edit), stringResource(R.string.br_form_body))
        FormGroup {
            DorrTextField(name, { name = it.take(150) }, label = stringResource(R.string.br_name), icon = Icons.Rounded.Store, modifier = Modifier.fillMaxWidth())
        }
        Card {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.MyLocation, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(if (lat != null) R.string.onb_location_set else R.string.onb_location_none), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                    if (lat != null && lng != null) Text("‎%.5f, %.5f".format(java.util.Locale.US, lat, lng), color = settingsMut(), fontSize = 12.sp)
                }
                if (locating) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
            }
            Text(
                stringResource(if (lat != null) R.string.onb_location_again else R.string.onb_location_use), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clickable(enabled = !locating && !busy) {
                    if (hasLocationPermission(context)) locate()
                    else askLocation.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
                },
            )
            Text(stringResource(R.string.onb_map_later), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
        }
        FormGroup {
            DorrTextField(address, { address = it.take(255) }, label = stringResource(R.string.onb_address), placeholder = stringResource(R.string.onb_optional), icon = Icons.Rounded.Place, modifier = Modifier.fillMaxWidth())
            DorrTextField(
                radius, { radius = it.filter { c -> c.isDigit() || c == '.' || c == ',' }.take(5) },
                label = stringResource(R.string.onb_radius), placeholder = stringResource(R.string.onb_radius_hint), icon = Icons.Rounded.Explore,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), error = !radiusOk, modifier = Modifier.fillMaxWidth(),
            )
            if (!radiusOk) Text(stringResource(R.string.onb_radius_invalid), color = Red, fontSize = 12.sp)
        }
        if (branch?.isMain != true) {
            Card {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.br_takes_requests), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                        Text(stringResource(R.string.br_takes_requests_hint), color = settingsMut(), fontSize = 12.sp)
                    }
                    RedToggle(on = active) { active = !active }
                }
            }
        } else {
            Text(stringResource(R.string.br_main_hint), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.fillMaxWidth())
        }
        SettingsPrimaryButton(text = stringResource(R.string.common_save), enabled = ready, loading = busy, onClick = ::save)
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}
