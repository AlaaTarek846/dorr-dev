package com.dorr.provider.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.CameraAlt
import androidx.compose.material.icons.rounded.Category
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.ColorLens
import androidx.compose.material.icons.rounded.DirectionsCar
import androidx.compose.material.icons.rounded.LocalShipping
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.WarningAmber
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.BranchDto
import com.dorr.provider.net.Realtime
import com.dorr.provider.net.Session
import com.dorr.provider.net.VehicleBody
import com.dorr.provider.net.VehicleDto
import com.dorr.provider.net.VehiclePageDto
import com.dorr.provider.net.VehicleTypeDto
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.net.jsonBody
import com.dorr.provider.net.plate
import com.dorr.provider.ui.components.Card
import com.dorr.provider.ui.components.ChipRow
import com.dorr.provider.ui.components.DorrTextField
import com.dorr.provider.ui.components.EmptyState
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.components.FieldLabel
import com.dorr.provider.ui.components.FormGroup
import com.dorr.provider.ui.components.FormIntro
import com.dorr.provider.ui.components.Page
import com.dorr.provider.ui.kit.AccountDark
import com.dorr.provider.ui.kit.ConfirmDialog
import com.dorr.provider.ui.kit.SettingsPrimaryButton
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsCard
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import com.dorr.provider.ui.kit.settingsNight
import kotlinx.coroutines.launch

/**
 * The establishment's vehicles (C-03, `fleet.vehicles`): each with its review status. No offer goes out without a vehicle that
 * is approved with its papers and its photos (owner's rule, docs/moving_business.md §6). Adding one opens its page to upload them.
 */
@Composable
fun VehiclesScreen(onBack: () -> Unit, onOpenVehicle: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var vehicles by remember { mutableStateOf<List<VehicleDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var adding by rememberSaveable { mutableStateOf(false) }

    suspend fun reload() {
        when (val result = apiCall { ApiClient.manage.vehicles() }) {
            is ApiResult.Ok -> {
                vehicles = result.data
                error = null
            }
            else -> error = result.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) { reload() }
    // A vehicle reviewed by Dorr arrives as a notice: one reload.
    LaunchedEffect(Unit) { Realtime.changed.collect { reload() } }

    if (adding) {
        BackHandler { adding = false }
        VehicleForm(vehicle = null, onBack = { adding = false }, onSaved = { uuid ->
            adding = false
            scope.launch { reload() }
            onOpenVehicle(uuid)
        })
        return
    }

    val list = vehicles
    Page(title = stringResource(R.string.vh_title), onBack = onBack) {
        Text(stringResource(R.string.vh_intro), color = settingsMut(), fontSize = 13.sp, lineHeight = 20.sp, modifier = Modifier.fillMaxWidth())
        when {
            list == null -> if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 40.dp).size(28.dp))
            list.isEmpty() -> EmptyState(Icons.Rounded.LocalShipping, stringResource(R.string.vh_empty_title), stringResource(R.string.vh_empty_body), Modifier.padding(top = 30.dp))
            else -> list.forEach { vehicle ->
                RowCard(
                    Icons.Rounded.LocalShipping,
                    vehicle.vehicleType?.name ?: stringResource(R.string.vh_vehicle),
                    listOfNotNull("‎" + vehicle.plate(), listOfNotNull(vehicle.make, vehicle.model).joinToString(" ").takeIf { it.isNotBlank() }).joinToString(" · "),
                    pill = { StatusPill(vehicleStatusLabel(vehicle.status), vehicleStatusColor(vehicle.status)) },
                    onClick = { onOpenVehicle(vehicle.uuid) },
                )
            }
        }
        SettingsPrimaryButton(text = stringResource(R.string.vh_add), onClick = { adding = true })
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/**
 * One vehicle: its data, its review, and what it still needs — **the photos first** (the one showing the plate matters most),
 * then the papers — each uploaded on its own. Editing an approved vehicle's type or plate sends it back to review.
 */
@Composable
fun VehicleScreen(uuid: String, onBack: () -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var data by remember { mutableStateOf<VehiclePageDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var formTypeId by rememberSaveable { mutableStateOf<Int?>(null) }
    var editing by rememberSaveable { mutableStateOf(false) }
    var deactivating by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }

    suspend fun reload() {
        when (val result = apiCall { ApiClient.manage.vehicle(uuid) }) {
            is ApiResult.Ok -> {
                data = result.data
                error = null
            }
            else -> error = result.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) { reload() }
    LaunchedEffect(Unit) { Realtime.changed.collect { reload() } }

    val page = data
    val typeInForm = formTypeId?.let { id -> page?.required?.firstOrNull { it.id == id } }
    if (formTypeId != null && typeInForm != null) {
        BackHandler { formTypeId = null }
        DocumentForm(typeInForm, subject = "vehicle", subjectUuid = uuid, onBack = { formTypeId = null }, onUploaded = { scope.launch { reload(); formTypeId = null } })
        return
    }
    val editedVehicle = page?.vehicle
    if (editing && editedVehicle != null) {
        BackHandler { editing = false }
        VehicleForm(vehicle = editedVehicle, onBack = { editing = false }, onSaved = { scope.launch { reload(); editing = false } })
        return
    }

    fun deactivate() {
        busy = true
        scope.launch {
            val result = apiCall { ApiClient.manage.deactivateVehicle(uuid) }
            busy = false
            deactivating = false
            if (result is ApiResult.Ok) data = result.data else error = result.errorText(offlineText) ?: genericError
        }
    }

    val vehicle = page?.vehicle
    Page(title = vehicle?.vehicleType?.name ?: stringResource(R.string.vh_vehicle), onBack = onBack) {
        if (page == null || vehicle == null) {
            if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 40.dp).size(28.dp))
        } else {
            Card {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("‎" + vehicle.plate(), color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                    StatusPill(vehicleStatusLabel(vehicle.status), vehicleStatusColor(vehicle.status))
                }
                InfoLine(stringResource(R.string.vh_make), vehicle.make)
                InfoLine(stringResource(R.string.vh_model), vehicle.model)
                InfoLine(stringResource(R.string.vh_color), vehicle.color)
                InfoLine(stringResource(R.string.vh_year), vehicle.year?.toString())
                if (vehicle.status == "rejected") vehicle.statusReason?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.pd_reason, it), color = Red, fontSize = 13.sp) }
                if (vehicle.status == "pending") Text(stringResource(R.string.vh_pending_hint), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
            }

            // The photos first: the vehicle with its plate showing, then its corners; then the papers.
            val photos = page.required.filter { it.kind == "photo" }
            val papers = page.required.filter { it.kind != "photo" }
            val editable = vehicle.status != "inactive"
            if (photos.isNotEmpty()) {
                FieldLabel(stringResource(R.string.vh_photos))
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Amber.copy(alpha = 0.10f)).padding(12.dp),
                    verticalAlignment = Alignment.Top,
                ) {
                    Icon(Icons.Rounded.CameraAlt, contentDescription = null, tint = Amber, modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(R.string.vh_plate_photo_first), color = settingsInk(), fontSize = 12.sp, lineHeight = 18.sp, fontWeight = FontWeight.SemiBold)
                }
                photos.forEach { type ->
                    val doc = page.documents.firstOrNull { it.documentTypeId == type.id }
                    RequiredRow(type, doc, missing = type.code in page.missing, editable = editable, busy = busy, onAdd = { formTypeId = type.id })
                }
            }
            if (papers.isNotEmpty()) {
                FieldLabel(stringResource(R.string.vh_papers))
                papers.forEach { type ->
                    val doc = page.documents.firstOrNull { it.documentTypeId == type.id }
                    RequiredRow(type, doc, missing = type.code in page.missing, editable = editable, busy = busy, onAdd = { formTypeId = type.id })
                }
            }
            if (page.missing.isNotEmpty()) Text(stringResource(R.string.pd_missing_count, page.missing.size), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.fillMaxWidth())

            if (editable) {
                SettingsPrimaryButton(text = stringResource(R.string.vh_edit), onClick = { editing = true })
                Row(Modifier.clickable(enabled = !busy) { deactivating = true }, verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Block, contentDescription = null, tint = Red, modifier = Modifier.size(18.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(stringResource(R.string.vh_deactivate), color = Red, fontSize = 14.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    if (deactivating) {
        ConfirmDialog(
            icon = Icons.Rounded.Block,
            title = stringResource(R.string.vh_deactivate),
            message = stringResource(R.string.vh_deactivate_message),
            confirmLabel = stringResource(R.string.vh_deactivate_confirm),
            loading = busy,
            onConfirm = ::deactivate,
            onDismiss = { if (!busy) deactivating = false },
        )
    }
}

/**
 * Adding a vehicle ([vehicle] null) or editing one: its type (the admin's types with their images), the plate (letters + number),
 * make, model, colour, year and branch. [onSaved] gets the vehicle's uuid.
 */
@Composable
private fun VehicleForm(vehicle: VehicleDto?, onBack: () -> Unit, onSaved: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var types by remember { mutableStateOf<List<VehicleTypeDto>?>(null) }
    var branches by remember { mutableStateOf<List<BranchDto>>(emptyList()) }
    var typeId by rememberSaveable { mutableStateOf(vehicle?.vehicleTypeId) }
    var letters by rememberSaveable { mutableStateOf(vehicle?.plateLetters.orEmpty()) }
    var number by rememberSaveable { mutableStateOf(vehicle?.plateNumber.orEmpty()) }
    var make by rememberSaveable { mutableStateOf(vehicle?.make.orEmpty()) }
    var model by rememberSaveable { mutableStateOf(vehicle?.model.orEmpty()) }
    var color by rememberSaveable { mutableStateOf(vehicle?.color.orEmpty()) }
    var year by rememberSaveable { mutableStateOf(vehicle?.year?.toString().orEmpty()) }
    var branchId by rememberSaveable { mutableStateOf(vehicle?.branchId) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) {
        (apiCall { ApiClient.workspace.options(Session.me.value?.countryId) } as? ApiResult.Ok)?.data?.let { types = it.vehicleTypes }
        (apiCall { ApiClient.manage.branches() } as? ApiResult.Ok)?.data?.let { branches = it }
    }

    val yearValue = year.toIntOrNull()
    val yearOk = year.isBlank() || (yearValue != null && yearValue in 1950..2100)
    val ready = typeId != null && number.isNotBlank() && yearOk

    fun save() {
        val type = typeId ?: return
        busy = true
        error = null
        val body = VehicleBody(
            vehicleTypeId = type, branchId = branchId, plateLetters = letters.trim().ifEmpty { null }, plateNumber = number.trim(),
            make = make.trim().ifEmpty { null }, model = model.trim().ifEmpty { null }, color = color.trim().ifEmpty { null }, year = yearValue,
        )
        scope.launch {
            val result = if (vehicle == null) apiCall { ApiClient.manage.addVehicle(jsonBody(body)) } else apiCall { ApiClient.manage.updateVehicle(vehicle.uuid, jsonBody(body)) }
            busy = false
            if (result is ApiResult.Ok) onSaved(result.data.vehicle?.uuid ?: vehicle?.uuid.orEmpty()) else error = result.errorText(offlineText) ?: genericError
        }
    }

    Page(title = stringResource(if (vehicle == null) R.string.vh_add else R.string.vh_edit), onBack = onBack) {
        FormIntro(Icons.Rounded.LocalShipping, stringResource(if (vehicle == null) R.string.vh_add else R.string.vh_edit), stringResource(R.string.vh_form_body))
        if (vehicle?.status == "approved") {
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Amber.copy(alpha = 0.10f)).padding(12.dp),
                verticalAlignment = Alignment.Top,
            ) {
                Icon(Icons.Rounded.WarningAmber, contentDescription = null, tint = Amber, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.vh_edit_review_warning), color = settingsInk(), fontSize = 12.sp, lineHeight = 18.sp)
            }
        }

        FieldLabel(stringResource(R.string.vh_type))
        val list = types
        when {
            list == null -> CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.size(24.dp))
            list.isEmpty() -> Text(stringResource(R.string.vh_no_types), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.fillMaxWidth())
            else -> list.chunked(3).forEach { row ->
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    row.forEach { type -> TypeTile(type, type.id == typeId, Modifier.weight(1f)) { typeId = type.id } }
                    repeat(3 - row.size) { Spacer(Modifier.weight(1f)) }
                }
            }
        }

        FormGroup {
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                DorrTextField(letters, { letters = it.take(32) }, label = stringResource(R.string.vh_plate_letters), placeholder = stringResource(R.string.onb_optional), modifier = Modifier.weight(1f))
                DorrTextField(number, { number = it.take(20) }, label = stringResource(R.string.vh_plate_number), icon = Icons.Rounded.Badge, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii), modifier = Modifier.weight(1f))
            }
            DorrTextField(make, { make = it.take(60) }, label = stringResource(R.string.vh_make), icon = Icons.Rounded.DirectionsCar, modifier = Modifier.fillMaxWidth())
            DorrTextField(model, { model = it.take(60) }, label = stringResource(R.string.vh_model), icon = Icons.Rounded.Category, modifier = Modifier.fillMaxWidth())
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                DorrTextField(color, { color = it.take(40) }, label = stringResource(R.string.vh_color), icon = Icons.Rounded.ColorLens, modifier = Modifier.weight(1f))
                DorrTextField(
                    year, { year = it.filter(Char::isDigit).take(4) }, label = stringResource(R.string.vh_year), icon = Icons.Rounded.CalendarMonth,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), error = !yearOk, modifier = Modifier.weight(1f),
                )
            }
        }
        if (branches.size > 1) {
            FieldLabel(stringResource(R.string.vh_branch))
            ChipRow(
                options = branches.map { it.id.toString() to it.name.orEmpty() },
                selected = setOfNotNull(branchId?.toString()),
                onToggle = { id -> branchId = id.toLongOrNull()?.takeIf { it != branchId } },
            )
        }
        SettingsPrimaryButton(text = stringResource(if (vehicle == null) R.string.vh_add_next else R.string.common_save), enabled = ready, loading = busy, onClick = ::save)
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/** One vehicle type with its image from the admin, a tick when chosen (the onboarding's service tile). */
@Composable
private fun TypeTile(type: VehicleTypeDto, selected: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val night = settingsNight()
    val accent = settingsAccent()
    val shape = RoundedCornerShape(18.dp)
    Box(modifier) {
        Column(
            Modifier.fillMaxWidth().clip(shape)
                .background(if (selected) accent.copy(alpha = if (night) 0.22f else 0.10f) else settingsCard())
                .border(if (selected) 2.dp else 1.dp, if (selected) accent else if (night) AccountDark.line else Color(0xFFE5E7EB), shape)
                .clickable(onClick = onClick).padding(horizontal = 8.dp, vertical = 12.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Box(Modifier.fillMaxWidth(0.72f).aspectRatio(1f), contentAlignment = Alignment.Center) {
                val image = ApiClient.mediaUrl(type.image)
                if (image != null) AsyncImage(model = image, contentDescription = null, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxWidth().aspectRatio(1f))
                else Icon(Icons.Rounded.LocalShipping, contentDescription = null, tint = accent, modifier = Modifier.size(28.dp))
            }
            Text(type.name.orEmpty(), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, maxLines = 2, overflow = TextOverflow.Ellipsis)
        }
        if (selected) {
            Box(Modifier.align(Alignment.TopEnd).padding(6.dp).size(20.dp).clip(RoundedCornerShape(50)).background(accent), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Check, contentDescription = null, tint = Color.White, modifier = Modifier.size(14.dp))
            }
        }
    }
}
