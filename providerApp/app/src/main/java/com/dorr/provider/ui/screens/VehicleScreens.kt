package com.dorr.provider.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.Crossfade
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddAPhoto
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.CameraAlt
import androidx.compose.material.icons.rounded.Category
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.ColorLens
import androidx.compose.material.icons.rounded.DirectionsCar
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.LocalShipping
import androidx.compose.material.icons.rounded.Numbers
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.TextFields
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.BranchDto
import com.dorr.provider.net.DocumentTypeDto
import com.dorr.provider.net.ProviderDocumentDto
import com.dorr.provider.net.Realtime
import com.dorr.provider.net.Session
import com.dorr.provider.net.VehicleBody
import com.dorr.provider.net.VehicleDto
import com.dorr.provider.net.VehiclePageDto
import com.dorr.provider.net.VehicleTypeDto
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.net.jsonBody
import com.dorr.provider.ui.components.ChipRow
import com.dorr.provider.ui.components.DorrTextField
import com.dorr.provider.ui.components.EmptyState
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.components.FormIntro
import com.dorr.provider.ui.components.SkeletonList
import com.dorr.provider.ui.kit.AccountDark
import com.dorr.provider.ui.kit.ConfirmDialog
import com.dorr.provider.ui.kit.SettingsPrimaryButton
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsCard
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import com.dorr.provider.ui.kit.settingsNight
import com.dorr.provider.ui.kit.settingsSurface
import kotlinx.coroutines.launch
import java.io.File
import java.time.LocalDate

/** `DocumentType::PLATE_PHOTO`: the photo of the vehicle with its plate showing, required for every vehicle. */
private const val PLATE_PHOTO = "vehicle_plate_photo"

/**
 * The establishment's vehicles (C-03, `fleet.vehicles`): each with its plate, its type's image and its review status. No offer goes out
 * without a vehicle that is approved with its papers and its photos (owner's rule, docs/moving_business.md §6). Adding one opens its page
 * to upload them.
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
    PaperScaffold(
        title = stringResource(R.string.vh_title),
        onBack = onBack,
        bottom = { SettingsPrimaryButton(text = stringResource(R.string.vh_add), onClick = { adding = true }) },
    ) {
        PaperNote(stringResource(R.string.vh_intro), Blue, Icons.Rounded.Info, Modifier.paperStagger(0))
        when {
            list == null -> if (error == null) SkeletonList(3, Modifier.fillMaxWidth())
            list.isEmpty() -> EmptyState(Icons.Rounded.LocalShipping, stringResource(R.string.vh_empty_title), stringResource(R.string.vh_empty_body), Modifier.padding(top = 30.dp))
            else -> list.forEachIndexed { i, vehicle ->
                VehicleRow(vehicle, Modifier.paperStagger(i + 1)) { onOpenVehicle(vehicle.uuid) }
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/** One vehicle in the list: its type's image, name and details, its status, and its plate as it looks. */
@Composable
private fun VehicleRow(vehicle: VehicleDto, modifier: Modifier, onClick: () -> Unit) {
    Column(
        modifier.fillMaxWidth().widthIn(max = 440.dp).paperPress(onClick = onClick).settingsSurface(RoundedCornerShape(20.dp)).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            TypeImage(vehicle.vehicleType?.image, 48.dp)
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(vehicle.vehicleType?.name ?: stringResource(R.string.vh_vehicle), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                vehicleDetails(vehicle)?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
            }
            StatusPill(vehicleStatusLabel(vehicle.status), vehicleStatusColor(vehicle.status))
        }
        VehiclePlate(vehicle.plateLetters.orEmpty(), vehicle.plateNumber.orEmpty(), Modifier.align(Alignment.CenterHorizontally), small = true)
    }
}

/** "Isuzu NPR · 2022 · White", or null when none of them is set. */
private fun vehicleDetails(vehicle: VehicleDto): String? =
    listOfNotNull(
        listOfNotNull(vehicle.make, vehicle.model).joinToString(" ").takeIf { it.isNotBlank() },
        vehicle.year?.toString(),
        vehicle.color?.takeIf { it.isNotBlank() },
    ).joinToString(" · ").takeIf { it.isNotBlank() }

/**
 * One vehicle: its plate and review, then what it still needs — **the photos first** (a grid of slots, the plate photo leading; tap one to
 * shoot or pick and it uploads at once with its progress), then the papers — each with its state. Editing an approved vehicle's type or
 * plate sends it back to review.
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
        DocumentForm(
            typeInForm, subject = "vehicle", subjectUuid = uuid,
            onBack = { formTypeId = null },
            onUploaded = { scope.launch { reload(); formTypeId = null } },
            existing = page?.documents?.firstOrNull { it.documentTypeId == typeInForm.id },
        )
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
    PaperScaffold(title = vehicle?.vehicleType?.name ?: stringResource(R.string.vh_vehicle), onBack = onBack) {
        if (page == null || vehicle == null) {
            if (error == null) SkeletonList(3, Modifier.fillMaxWidth())
        } else {
            val editable = vehicle.status != "inactive"
            VehicleHero(vehicle, Modifier.paperStagger(0))
            VehicleNeeds(
                page, uuid, editable = editable, firstIndex = 1,
                onOpenForm = { formTypeId = it },
                onReload = { reload() },
                onError = { error = it },
            )
            if (editable) {
                Row(Modifier.fillMaxWidth().widthIn(max = 440.dp).paperStagger(8), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    ActionPill(Icons.Rounded.Edit, stringResource(R.string.vh_edit), settingsAccent(), Modifier.weight(1f)) { editing = true }
                    ActionPill(Icons.Rounded.Block, stringResource(R.string.vh_deactivate), Red, Modifier.weight(1f), enabled = !busy) { deactivating = true }
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
 * The onboarding's vehicle step (moving needs at least one vehicle): the vehicle's data, then its required photos and papers. [onDone] is
 * called only once the vehicle is saved **and** nothing is left in its `missing` list (every required photo and paper uploaded). There is
 * no way to skip it: the first step has no back button (what the system back does there is the caller's), while the photos step goes
 * back to the vehicle's data and an open upload goes back to the photos. Opened again after the app was closed, it carries on with the
 * vehicle already added.
 */
@Composable
internal fun FirstVehicleScreen(onDone: () -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var vehicleUuid by rememberSaveable { mutableStateOf<String?>(null) }
    // Whether the establishment's vehicles were read once (a vehicle added before the app was closed).
    var looked by rememberSaveable { mutableStateOf(false) }
    var page by remember { mutableStateOf<VehiclePageDto?>(null) }
    var editing by rememberSaveable { mutableStateOf(false) }
    var formTypeId by rememberSaveable { mutableStateOf<Int?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    suspend fun reload() {
        if (vehicleUuid == null && !looked) {
            when (val list = apiCall { ApiClient.manage.vehicles() }) {
                is ApiResult.Ok -> {
                    looked = true
                    vehicleUuid = list.data.firstOrNull { it.status != "inactive" }?.uuid
                    error = null
                }
                else -> {
                    error = list.errorText(offlineText) ?: genericError
                    return
                }
            }
        }
        val uuid = vehicleUuid ?: return
        when (val result = apiCall { ApiClient.manage.vehicle(uuid) }) {
            is ApiResult.Ok -> {
                page = result.data
                error = null
            }
            else -> error = result.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) { reload() }
    LaunchedEffect(Unit) { Realtime.changed.collect { reload() } }

    val title = stringResource(R.string.pp_vh_first_title)
    val uuid = vehicleUuid
    if (uuid == null) {
        if (!looked) {
            PaperScaffold(title = title, onBack = null) {
                if (error == null) SkeletonList(3, Modifier.fillMaxWidth())
                ErrorBanner(error, Modifier.fillMaxWidth())
            }
        } else {
            // Step 1: the vehicle's data.
            VehicleForm(vehicle = null, onBack = null, first = true, onSaved = { saved ->
                vehicleUuid = saved
                scope.launch { reload() }
            })
        }
        return
    }

    val current = page
    val savedVehicle = current?.vehicle
    if (editing && savedVehicle != null) {
        BackHandler { editing = false }
        VehicleForm(vehicle = savedVehicle, onBack = { editing = false }, first = true, onSaved = { scope.launch { reload(); editing = false } })
        return
    }
    val typeInForm = formTypeId?.let { id -> current?.required?.firstOrNull { it.id == id } }
    if (formTypeId != null && typeInForm != null) {
        BackHandler { formTypeId = null }
        DocumentForm(
            typeInForm, subject = "vehicle", subjectUuid = uuid,
            onBack = { formTypeId = null },
            onUploaded = { scope.launch { reload(); formTypeId = null } },
            existing = current?.documents?.firstOrNull { it.documentTypeId == typeInForm.id },
        )
        return
    }

    // Step 2: its photos and papers. Back = to the vehicle's data.
    BackHandler { editing = true }
    val complete = current != null && current.missing.isEmpty()
    PaperScaffold(
        title = title,
        onBack = { editing = true },
        bottom = {
            AnimatedVisibility(current != null && !complete, enter = fadeIn() + expandVertically(), exit = fadeOut() + shrinkVertically()) {
                Text(stringResource(R.string.pp_vh_done_need), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp, modifier = Modifier.fillMaxWidth())
            }
            SettingsPrimaryButton(text = stringResource(R.string.pp_vh_done), enabled = complete, onClick = onDone)
        },
    ) {
        FirstVehicleSteps(1, Modifier.paperStagger(0))
        if (current == null || savedVehicle == null) {
            if (error == null) SkeletonList(3, Modifier.fillMaxWidth())
        } else {
            VehicleHero(savedVehicle, Modifier.paperStagger(1), onEdit = { editing = true })
            AnimatedVisibility(complete, enter = fadeIn() + expandVertically(), exit = fadeOut() + shrinkVertically()) {
                PaperNote(stringResource(R.string.pp_vh_all_sent), Green, Icons.Rounded.TaskAlt)
            }
            VehicleNeeds(
                current, uuid, editable = true, firstIndex = 2,
                onOpenForm = { formTypeId = it },
                onReload = { reload() },
                onError = { error = it },
            )
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/** The two steps of the first vehicle, the current one filled in the app colour (a tick on the one done). */
@Composable
private fun FirstVehicleSteps(current: Int, modifier: Modifier = Modifier) {
    val accent = settingsAccent()
    val track = if (settingsNight()) AccountDark.well else Color(0xFFEDEEF1)
    Row(modifier.fillMaxWidth().widthIn(max = 440.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        listOf(R.string.pp_vh_step_details, R.string.pp_vh_step_photos).forEachIndexed { i, label ->
            val on = i <= current
            val fill by animateColorAsState(if (on) accent else track, tween(400), label = "step-fill")
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Box(Modifier.fillMaxWidth().height(5.dp).clip(CircleShape).background(fill))
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Box(Modifier.size(22.dp).clip(CircleShape).background(fill), contentAlignment = Alignment.Center) {
                        if (i < current) Icon(Icons.Rounded.Check, contentDescription = null, tint = Color.White, modifier = Modifier.size(14.dp))
                        else Text("${i + 1}", color = if (on) Color.White else settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.Bold)
                    }
                    Text(stringResource(label), color = if (i == current) settingsInk() else settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

/** The vehicle on top of its page: its type's image and name, its details, its status, its plate, and the review's word. */
@Composable
private fun VehicleHero(vehicle: VehicleDto, modifier: Modifier = Modifier, onEdit: (() -> Unit)? = null) {
    Column(
        modifier.fillMaxWidth().widthIn(max = 440.dp).settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            TypeImage(vehicle.vehicleType?.image, 54.dp)
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(vehicle.vehicleType?.name ?: stringResource(R.string.vh_vehicle), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                vehicleDetails(vehicle)?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 2) }
            }
            StatusPill(vehicleStatusLabel(vehicle.status), vehicleStatusColor(vehicle.status))
        }
        VehiclePlate(vehicle.plateLetters.orEmpty(), vehicle.plateNumber.orEmpty(), Modifier.align(Alignment.CenterHorizontally))
        if (vehicle.status == "rejected") vehicle.statusReason?.takeIf { it.isNotBlank() }?.let {
            PaperNote(stringResource(R.string.pd_reason, it), Red, Icons.Rounded.ErrorOutline)
        }
        if (vehicle.status == "pending") PaperNote(stringResource(R.string.vh_pending_hint), Blue, Icons.Rounded.HourglassTop)
        if (onEdit != null) {
            Row(
                Modifier.align(Alignment.End).clip(RoundedCornerShape(10.dp)).paperPress(onClick = onEdit).padding(horizontal = 8.dp, vertical = 4.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp),
            ) {
                Icon(Icons.Rounded.Edit, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(15.dp))
                Text(stringResource(R.string.vh_edit), color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}

/**
 * What a vehicle needs, shared by its page and the first-vehicle step: how many are ready, the photos as a grid of slots (the plate photo
 * first; a plain photo uploads straight from the camera or the gallery with its progress in the slot, one with a number, a date or a back
 * side opens the full form), then the papers as cards.
 */
@Composable
private fun VehicleNeeds(
    page: VehiclePageDto,
    uuid: String,
    editable: Boolean,
    firstIndex: Int,
    onOpenForm: (Int) -> Unit,
    onReload: suspend () -> Unit,
    onError: (String) -> Unit,
) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var uploadingId by remember { mutableStateOf<Int?>(null) }
    var uploadPath by remember { mutableStateOf<String?>(null) }
    var progress by remember { mutableFloatStateOf(0f) }
    var sheetTypeId by remember { mutableStateOf<Int?>(null) }

    fun send(type: DocumentTypeDto, file: File) {
        if (uploadingId != null) {
            file.delete()
            return
        }
        uploadingId = type.id
        uploadPath = file.absolutePath
        progress = 0f
        scope.launch {
            val result = uploadPaper("vehicle", uuid, type, null, null, file, null) { progress = it }
            if (result is ApiResult.Ok) {
                progress = 1f
                onReload()
            } else {
                onError(result.errorText(offlineText) ?: genericError)
            }
            uploadingId = null
            uploadPath = null
            file.delete()
        }
    }
    val picker = rememberPaperPicker(
        onPicked = { key, file ->
            val type = page.required.firstOrNull { it.id.toString() == key }
            if (type != null) send(type, file) else file.delete()
        },
        onError = onError,
    )

    val photos = page.required.filter { it.kind == "photo" }.sortedBy { if (it.code == PLATE_PHOTO) 0 else 1 }
    val papers = page.required.filter { it.kind != "photo" }
    if (page.required.isNotEmpty()) PaperProgressCard(page.required, page.documents, page.missing, Modifier.paperStagger(firstIndex))
    if (photos.isNotEmpty()) {
        PaperSectionTitle(stringResource(R.string.vh_photos), Modifier.paperStagger(firstIndex + 1))
        PaperNote(stringResource(R.string.vh_plate_photo_first), Amber, Icons.Rounded.CameraAlt, Modifier.paperStagger(firstIndex + 1))
        photos.chunked(2).forEachIndexed { row, pair ->
            Row(Modifier.fillMaxWidth().widthIn(max = 440.dp).paperStagger(firstIndex + 2 + row), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                pair.forEach { type ->
                    val mine = uploadingId == type.id
                    PhotoSlot(
                        type = type,
                        doc = page.documents.firstOrNull { it.documentTypeId == type.id },
                        missing = type.code in page.missing,
                        uploading = mine,
                        progress = progress,
                        local = if (mine) uploadPath?.let { File(it) } else null,
                        editable = editable && uploadingId == null,
                        modifier = Modifier.weight(1f),
                    ) {
                        if (type.hasNumber || type.hasExpiry || type.needsBackSide) onOpenForm(type.id) else sheetTypeId = type.id
                    }
                }
                if (pair.size == 1) Spacer(Modifier.weight(1f))
            }
        }
    }
    if (papers.isNotEmpty()) {
        PaperSectionTitle(stringResource(R.string.vh_papers), Modifier.paperStagger(firstIndex + 4))
        papers.forEachIndexed { i, type ->
            PaperCard(
                type, page.documents.firstOrNull { it.documentTypeId == type.id }, missing = type.code in page.missing,
                editable = editable, busy = uploadingId != null, modifier = Modifier.paperStagger(firstIndex + 5 + i),
            ) { onOpenForm(type.id) }
        }
    }

    sheetTypeId?.let { id ->
        page.required.firstOrNull { it.id == id }?.let { type ->
            PaperSourceSheet(
                title = type.name,
                allowFiles = false,
                onCamera = { picker.camera(type.id.toString()) },
                onGallery = { picker.gallery(type.id.toString(), arrayOf("image/jpeg", "image/png")) },
                onDismiss = { sheetTypeId = null },
            )
        }
    }
}

/**
 * One required photo as a slot: empty (dashed, "required"), the photo sent with its state badge, or the photo being sent with its
 * progress over it. Tapping it shoots or picks a new one.
 */
@Composable
private fun PhotoSlot(
    type: DocumentTypeDto,
    doc: ProviderDocumentDto?,
    missing: Boolean,
    uploading: Boolean,
    progress: Float,
    local: File?,
    editable: Boolean,
    modifier: Modifier,
    onClick: () -> Unit,
) {
    val state = paperState(doc, missing)
    val accent = settingsAccent()
    val night = settingsNight()
    val shape = RoundedCornerShape(18.dp)
    val shown by animateFloatAsState(progress, tween(250), label = "slot-progress")
    val ring by animateColorAsState(
        if (state == PaperState.Rejected || state == PaperState.Expired) Red else Color.Transparent, tween(300), label = "slot-ring",
    )
    val frontUrl = doc?.front
    val key = when {
        local != null -> "local"
        frontUrl != null -> "doc:" + doc?.uuid + frontUrl
        else -> "empty"
    }
    Column(modifier, verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Box(
            Modifier.fillMaxWidth().aspectRatio(1.1f).paperPress(enabled = editable, onClick = onClick).clip(shape)
                .background(if (night) AccountDark.well else accent.copy(alpha = 0.05f)).border(1.5.dp, ring, shape),
            contentAlignment = Alignment.Center,
        ) {
            Crossfade(targetState = key, animationSpec = tween(300), label = "photo-slot") { k ->
                when {
                    k == "local" && local != null -> AsyncImage(model = local, contentDescription = type.name, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                    k.startsWith("doc:") && frontUrl != null ->
                        AsyncImage(model = ApiClient.mediaUrl(frontUrl), contentDescription = type.name, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                    else -> Column(
                        Modifier.fillMaxSize().dashedOutline(accent.copy(alpha = 0.45f), 18.dp).padding(10.dp),
                        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center,
                    ) {
                        Box(Modifier.size(46.dp).clip(CircleShape).background(accent.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.AddAPhoto, contentDescription = null, tint = accent, modifier = Modifier.size(22.dp))
                        }
                        Text(stringResource(R.string.pp_tap_add), color = accent, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 6.dp))
                    }
                }
            }
            if (uploading) {
                Box(Modifier.fillMaxSize().background(Color.Black.copy(alpha = 0.45f)), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(progress = { shown }, color = Color.White, trackColor = Color.White.copy(alpha = 0.25f), strokeWidth = 4.dp, modifier = Modifier.size(50.dp))
                    Text("${(shown * 100).toInt()}%", color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                }
            } else if (state == PaperState.Missing) {
                StatusPill(stringResource(R.string.pp_required), Amber, Modifier.align(Alignment.TopStart).padding(8.dp))
            } else {
                PaperStateBadge(state, Modifier.align(Alignment.TopStart).padding(8.dp), solid = true)
            }
        }
        Text(type.name, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis, lineHeight = 17.sp)
    }
}

@Composable
private fun ActionPill(icon: ImageVector, label: String, color: Color, modifier: Modifier, enabled: Boolean = true, onClick: () -> Unit) {
    Row(
        modifier.paperPress(enabled = enabled, onClick = onClick).clip(RoundedCornerShape(16.dp)).background(color.copy(alpha = 0.10f)).padding(vertical = 13.dp),
        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, contentDescription = null, tint = color, modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(6.dp))
        Text(label, color = color, fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

/** A type's image from the admin on a soft tile (a truck when it has none). */
@Composable
private fun TypeImage(image: String?, size: androidx.compose.ui.unit.Dp) {
    val accent = settingsAccent()
    Box(Modifier.size(size).clip(RoundedCornerShape(14.dp)).background(accent.copy(alpha = 0.10f)), contentAlignment = Alignment.Center) {
        val url = ApiClient.mediaUrl(image)
        if (url != null) AsyncImage(model = url, contentDescription = null, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize().padding(5.dp))
        else Icon(Icons.Rounded.LocalShipping, contentDescription = null, tint = accent, modifier = Modifier.size(size * 0.5f))
    }
}

/**
 * Adding a vehicle ([vehicle] null) or editing one, in numbered cards: 1. its type (the admin's types with their images), 2. the plate —
 * letters **and** numbers, both required, with the plate drawn live as it is typed (the captain app's plate), 3. make, model, colour and
 * year (optional), 4. the branch. Checked before sending; the server's own field errors show under their fields. [onSaved] gets the
 * vehicle's uuid. [onBack] null = no back button (the first vehicle); [first] shows the first vehicle's steps and words.
 */
@Composable
private fun VehicleForm(vehicle: VehicleDto?, onBack: (() -> Unit)?, onSaved: (String) -> Unit, first: Boolean = false) {
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
    var tried by rememberSaveable { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    // The server's field errors (`plate_letters` → its first sentence), shown under their fields until the field changes.
    var server by remember { mutableStateOf<Map<String, List<String>>>(emptyMap()) }

    LaunchedEffect(Unit) {
        (apiCall { ApiClient.workspace.options(Session.me.value?.countryId) } as? ApiResult.Ok)?.data?.let { types = it.vehicleTypes }
        (apiCall { ApiClient.manage.branches() } as? ApiResult.Ok)?.data?.let { branches = it }
    }

    val maxYear = remember { LocalDate.now().year + 1 }
    val yearValue = year.toIntOrNull()
    val yearBad = year.isNotBlank() && (yearValue == null || yearValue !in 1950..maxYear)
    val ready = typeId != null && letters.isNotBlank() && number.isNotBlank() && !yearBad
    val errType = stringResource(R.string.pp_err_type)
    val errLetters = stringResource(R.string.pp_err_letters)
    val errNumber = stringResource(R.string.pp_err_number)
    val errYear = stringResource(R.string.pp_err_year, maxYear)
    fun problem(bad: Boolean, text: String, key: String): String? = server[key]?.firstOrNull() ?: if (tried && bad) text else null

    fun save() {
        tried = true
        val type = typeId
        if (!ready || busy || type == null) return
        busy = true
        error = null
        server = emptyMap()
        val body = VehicleBody(
            vehicleTypeId = type, branchId = branchId, plateLetters = letters.trim(), plateNumber = number.trim(),
            make = make.trim().ifEmpty { null }, model = model.trim().ifEmpty { null }, color = color.trim().ifEmpty { null }, year = yearValue,
        )
        scope.launch {
            val result = if (vehicle == null) apiCall { ApiClient.manage.addVehicle(jsonBody(body)) } else apiCall { ApiClient.manage.updateVehicle(vehicle.uuid, jsonBody(body)) }
            busy = false
            when (result) {
                is ApiResult.Ok -> onSaved(result.data.vehicle?.uuid ?: vehicle?.uuid.orEmpty())
                is ApiResult.Failed -> {
                    server = result.error.fieldErrors
                    error = result.errorText(offlineText) ?: genericError
                }
                else -> error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    val title = stringResource(if (first) R.string.pp_vh_first_title else if (vehicle == null) R.string.vh_add else R.string.vh_edit)
    PaperScaffold(
        title = title,
        onBack = onBack,
        bottom = {
            AnimatedVisibility(tried && !ready, enter = fadeIn() + expandVertically(), exit = fadeOut() + shrinkVertically()) {
                PaperNote(stringResource(R.string.pp_complete_first), Amber, Icons.Rounded.WarningAmber)
            }
            SettingsPrimaryButton(
                text = stringResource(if (vehicle == null) R.string.pp_vh_save_next else R.string.common_save),
                loading = busy, onClick = ::save,
            )
        },
    ) {
        if (first) {
            FirstVehicleSteps(0, Modifier.paperStagger(0))
            Box(Modifier.paperStagger(1)) { FormIntro(Icons.Rounded.LocalShipping, stringResource(R.string.pp_vh_first_heading), stringResource(R.string.pp_vh_first_body)) }
        } else {
            Box(Modifier.paperStagger(0)) { FormIntro(Icons.Rounded.LocalShipping, title, stringResource(R.string.vh_form_body)) }
        }
        if (vehicle?.status == "approved") PaperNote(stringResource(R.string.vh_edit_review_warning), Amber, Icons.Rounded.WarningAmber, Modifier.paperStagger(1))

        // 1. The type.
        FormStep(1, stringResource(R.string.vh_type), Modifier.paperStagger(2)) {
            val list = types
            when {
                list == null -> CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.size(24.dp).align(Alignment.CenterHorizontally))
                list.isEmpty() -> Text(stringResource(R.string.vh_no_types), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.fillMaxWidth())
                else -> list.chunked(3).forEach { row ->
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        row.forEach { type ->
                            TypeTile(type, type.id == typeId, Modifier.weight(1f)) {
                                typeId = type.id
                                server = server - "vehicle_type_id"
                            }
                        }
                        repeat(3 - row.size) { Spacer(Modifier.weight(1f)) }
                    }
                }
            }
            FieldError(problem(typeId == null, errType, "vehicle_type_id"))
        }

        // 2. The plate, drawn as it is typed.
        FormStep(2, stringResource(R.string.pp_vh_section_plate), Modifier.paperStagger(3)) {
            VehiclePlate(letters, number, Modifier.align(Alignment.CenterHorizontally))
            Text(stringResource(R.string.pp_vh_plate_hint), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth())
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    val problemText = problem(letters.isBlank(), errLetters, "plate_letters")
                    DorrTextField(
                        letters,
                        { letters = it.filter { c -> c.isLetter() || c == ' ' }.uppercase().take(8); server = server - "plate_letters" },
                        label = stringResource(R.string.vh_plate_letters), placeholder = "ABC", icon = Icons.Rounded.TextFields,
                        keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Characters, imeAction = ImeAction.Next),
                        error = problemText != null, modifier = Modifier.fillMaxWidth(),
                    )
                    FieldError(problemText)
                }
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    val problemText = problem(number.isBlank(), errNumber, "plate_number")
                    DorrTextField(
                        number,
                        { number = latinDigits(it).filter(Char::isDigit).take(8); server = server - "plate_number" },
                        label = stringResource(R.string.vh_plate_number), placeholder = "1234", icon = Icons.Rounded.Numbers,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = ImeAction.Next),
                        error = problemText != null, modifier = Modifier.fillMaxWidth(),
                    )
                    FieldError(problemText)
                }
            }
        }

        // 3. The details (optional).
        FormStep(3, stringResource(R.string.pp_vh_section_details), Modifier.paperStagger(4)) {
            val optional = stringResource(R.string.onb_optional)
            DorrTextField(make, { make = it.take(60) }, label = stringResource(R.string.vh_make), placeholder = optional, icon = Icons.Rounded.DirectionsCar, modifier = Modifier.fillMaxWidth())
            DorrTextField(model, { model = it.take(60) }, label = stringResource(R.string.vh_model), placeholder = optional, icon = Icons.Rounded.Category, modifier = Modifier.fillMaxWidth())
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                DorrTextField(color, { color = it.take(40) }, label = stringResource(R.string.vh_color), placeholder = optional, icon = Icons.Rounded.ColorLens, modifier = Modifier.weight(1f))
                DorrTextField(
                    year, { year = latinDigits(it).filter(Char::isDigit).take(4); server = server - "year" }, label = stringResource(R.string.vh_year),
                    placeholder = optional, icon = Icons.Rounded.CalendarMonth,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), error = problem(yearBad, errYear, "year") != null, modifier = Modifier.weight(1f),
                )
            }
            FieldError(problem(yearBad, errYear, "year"))
        }

        // 4. The branch, when there is more than one.
        if (branches.size > 1) {
            FormStep(4, stringResource(R.string.vh_branch), Modifier.paperStagger(5)) {
                ChipRow(
                    options = branches.map { it.id.toString() to it.name.orEmpty() },
                    selected = setOfNotNull(branchId?.toString()),
                    onToggle = { id -> branchId = id.toLongOrNull()?.takeIf { it != branchId } },
                )
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/** Arabic-Indic and Persian digits as Latin ones (the server reads Latin digits). */
private fun latinDigits(text: String): String = text.map { c ->
    when (c) {
        in '٠'..'٩' -> '0' + (c - '٠')
        in '۰'..'۹' -> '0' + (c - '۰')
        else -> c
    }
}.joinToString("")

/** A numbered card of the vehicle form: the step's number on an app-colour disc, its title, then its fields. */
@Composable
private fun FormStep(number: Int, title: String, modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Column(
        modifier.fillMaxWidth().widthIn(max = 440.dp).settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(Modifier.size(26.dp).clip(CircleShape).background(settingsAccent()), contentAlignment = Alignment.Center) {
                Text("$number", color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
            }
            Text(title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
        }
        content()
    }
}

/** The line under a field that says what is wrong, sliding in and out. */
@Composable
private fun FieldError(text: String?) {
    AnimatedVisibility(text != null, enter = fadeIn() + expandVertically(), exit = fadeOut() + shrinkVertically()) {
        Text(text.orEmpty(), color = Red, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(horizontal = 6.dp))
    }
}

/** A number plate (the captain app's `PlatePreview`): the numbers and the letters on white, as on the vehicle — grey placeholders until typed. */
@Composable
internal fun VehiclePlate(letters: String, numbers: String, modifier: Modifier = Modifier, small: Boolean = false) {
    val ink = Color(0xFF111928)
    val shape = RoundedCornerShape(if (small) 8.dp else 12.dp)
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Row(
            modifier.widthIn(max = if (small) 190.dp else 290.dp).fillMaxWidth().height(if (small) 44.dp else 66.dp)
                .clip(shape).background(Color.White).border(2.dp, ink, shape).padding(4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            PlateHalf(numbers, "1234", small, Modifier.weight(1f))
            Box(Modifier.width(2.dp).fillMaxHeight().background(ink))
            PlateHalf(letters, "ABC", small, Modifier.weight(1f))
        }
    }
}

@Composable
private fun PlateHalf(value: String, placeholder: String, small: Boolean, modifier: Modifier) {
    val shown = value.trim().ifBlank { placeholder }.toList().joinToString(" ")
    Box(modifier.fillMaxHeight(), contentAlignment = Alignment.Center) {
        Text(
            shown, color = if (value.isBlank()) Color(0xFFB8BEC8) else Color(0xFF111928), maxLines = 1,
            fontSize = if (small) 15.sp else 22.sp, fontWeight = FontWeight.ExtraBold, letterSpacing = 1.sp,
        )
    }
}

/** One vehicle type with its image from the admin, a tick and a small spring when chosen (the onboarding's service tile). */
@Composable
private fun TypeTile(type: VehicleTypeDto, selected: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val night = settingsNight()
    val accent = settingsAccent()
    val shape = RoundedCornerShape(18.dp)
    val scale by animateFloatAsState(if (selected) 1.04f else 1f, spring(dampingRatio = Spring.DampingRatioMediumBouncy), label = "type-scale")
    val line by animateColorAsState(if (selected) accent else if (night) AccountDark.line else Color(0xFFE5E7EB), tween(200), label = "type-line")
    Box(modifier.graphicsLayer { scaleX = scale; scaleY = scale }) {
        Column(
            Modifier.fillMaxWidth().paperPress(onClick = onClick).clip(shape)
                .background(if (selected) accent.copy(alpha = if (night) 0.22f else 0.10f) else settingsCard())
                .border(if (selected) 2.dp else 1.dp, line, shape)
                .padding(horizontal = 8.dp, vertical = 12.dp),
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
            Box(Modifier.align(Alignment.TopEnd).padding(6.dp).size(20.dp).clip(CircleShape).background(accent), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Check, contentDescription = null, tint = Color.White, modifier = Modifier.size(14.dp))
            }
        }
    }
}
