package com.dorr.provider.ui.screens

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.LocalShipping
import androidx.compose.material.icons.rounded.Navigation
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.QrCodeScanner
import androidx.compose.material.icons.rounded.Repeat
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import coil.compose.AsyncImage
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.duty.MoveTracking
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.DeliverBody
import com.dorr.provider.net.InventoryItemDto
import com.dorr.provider.net.MovingEndDto
import com.dorr.provider.net.MovingRequestDto
import com.dorr.provider.net.StepBody
import com.dorr.provider.net.apiDone
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.ui.components.Card
import com.dorr.provider.ui.components.ChipRow
import com.dorr.provider.ui.components.DorrTextField
import com.dorr.provider.ui.components.EmptyState
import com.dorr.provider.ui.components.FieldLabel
import com.dorr.provider.ui.components.FormIntro
import com.dorr.provider.ui.components.InlineErrorBanner
import com.dorr.provider.ui.components.Page
import com.dorr.provider.ui.kit.ConfirmDialog
import com.dorr.provider.ui.kit.SettingsPrimaryButton
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import java.io.File
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody

/*
 * The driver's moving day (C-05), on the job's page when the job is theirs: where it stands, ONE main action for the next step, the way
 * to the place, the numbered pieces and the delivery. Every refusal of the server is shown word for word and stays on the page.
 */

/** The next step of a status: `null` for [step] is the delivery, which has a page of its own. */
private class NextStep(val step: String?, val label: Int, val hint: Int)

private fun nextStepOf(status: String?): NextStep? = when (status) {
    "assigned" -> NextStep("set_out", R.string.day_set_out, R.string.day_hint_assigned)
    "driver_on_way" -> NextStep("arrived", R.string.day_arrived, R.string.day_hint_on_way)
    "arrived" -> NextStep("loading", R.string.day_loading, R.string.day_hint_arrived)
    "loading" -> NextStep("departed", R.string.day_departed, R.string.day_hint_loading)
    "in_transit" -> NextStep("unloading", R.string.day_unloading, R.string.day_hint_in_transit)
    "unloading" -> NextStep(null, R.string.day_deliver, R.string.day_hint_unloading)
    else -> null
}

/** The phone's position now, at most ten seconds' wait; null without the permission or a fix. */
private suspend fun positionOrNull(context: Context): Pair<Double, Double>? =
    if (!hasLocationPermission(context)) null else withTimeoutOrNull(10_000) { currentPosition(context) }

/** The location permissions, and on Android 13+ the notification of the running job. */
private fun trackingPermissions(): Array<String> = buildList {
    add(Manifest.permission.ACCESS_FINE_LOCATION)
    add(Manifest.permission.ACCESS_COARSE_LOCATION)
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) add(Manifest.permission.POST_NOTIFICATIONS)
}.toTypedArray()

/** Turn-by-turn in Google Maps, else any map app, else the browser (driverApp's order). */
private fun openNavigation(context: Context, end: MovingEndDto) {
    val lat = end.lat
    val lng = end.lng
    val target = if (lat != null && lng != null) "$lat,$lng" else Uri.encode(end.address.orEmpty())
    if (target.isBlank()) return
    fun open(uri: String, app: String? = null): Boolean = runCatching {
        context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(uri)).apply {
            app?.let { setPackage(it) }
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        })
    }.isSuccess
    open("google.navigation:q=$target&mode=d", "com.google.android.apps.maps") ||
        open("geo:0,0?q=$target") ||
        open("https://www.google.com/maps/dir/?api=1&destination=$target")
}

/**
 * The top of the job's page for its driver: a big status, the one action that comes next, the way to the place and the pieces. A job
 * not accepted yet shows accept / decline first. The location service follows the job's status from here (it starts on set_out).
 */
@Composable
internal fun DriverDayPanel(
    move: MovingRequestDto,
    onReload: suspend () -> Unit,
    onOpenInventory: () -> Unit,
    onOpenDeliver: () -> Unit,
    onDeclined: () -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var declining by remember { mutableStateOf(false) }
    var askNote by rememberSaveable { mutableStateOf(false) }
    var note by rememberSaveable { mutableStateOf("") }
    var pendingStep by remember { mutableStateOf<String?>(null) }
    var permissionTick by remember { mutableIntStateOf(0) }
    val status = move.status

    // On for the running states, off for the rest; asked again when the permission changes.
    LaunchedEffect(move.uuid, status, permissionTick) { MoveTracking.follow(context, move.uuid, status) }
    val permitted = remember(permissionTick, status) { hasLocationPermission(context) }

    fun send(step: String, lat: Double? = null, lng: Double? = null, withNote: String? = null) {
        busy = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.moving.step(move.uuid, StepBody(step, lat, lng, withNote)) }
            if (result is ApiResult.Ok) {
                askNote = false
                note = ""
                onReload()
            } else {
                // Word for word: «اقبل المهمة أولًا», «سجّل قطع الجرد بصورها قبل التحرك»…
                error = result.errorText(offlineText) ?: genericError
            }
            busy = false
        }
    }

    /** At the door the position is the proof; without one, a short note is (a person looks at it). */
    fun arrive() {
        busy = true
        error = null
        scope.launch {
            val here = positionOrNull(context)
            if (here != null) {
                send("arrived", here.first, here.second)
            } else {
                busy = false
                askNote = true
            }
        }
    }

    fun go(step: String) = if (step == "arrived") arrive() else send(step)

    val askPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        permissionTick++
        pendingStep?.let { go(it) }
        pendingStep = null
    }

    fun accept() {
        busy = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.moving.acceptJob(move.uuid) }
            if (result is ApiResult.Ok) onReload() else error = result.errorText(offlineText) ?: genericError
            busy = false
        }
    }

    fun decline() {
        busy = true
        error = null
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.moving.declineJob(move.uuid) }
            busy = false
            declining = false
            if (failed == null) onDeclined() else error = failed
        }
    }

    val next = nextStepOf(status)
    val waitingForAnswer = status == "assigned" && !move.driverAccepted
    Card {
        Text(moveStatusLabel(status), color = moveStatusColor(status), fontSize = 24.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 30.sp)
        Text(routeText(move), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
        val hint = when {
            waitingForAnswer -> R.string.day_hint_answer
            next != null -> next.hint
            status == "delivered" || status == "completed" -> R.string.day_hint_done
            else -> null
        }
        hint?.let { Text(stringResource(it), color = settingsMut(), fontSize = 13.sp, lineHeight = 20.sp) }

        when {
            waitingForAnswer -> {
                SettingsPrimaryButton(text = stringResource(R.string.mv_accept_job), loading = busy, onClick = ::accept)
                Text(
                    stringResource(R.string.mv_decline_job), color = Red, fontSize = 14.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.clickable(enabled = !busy) { declining = true },
                )
            }
            askNote -> {
                // No location at the door: the note is the other proof.
                Text(stringResource(R.string.day_no_location), color = Amber, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, lineHeight = 20.sp)
                DorrTextField(note, { note = it.take(500) }, label = stringResource(R.string.day_note), icon = Icons.Rounded.Notes, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                SettingsPrimaryButton(text = stringResource(R.string.day_arrived), enabled = note.isNotBlank(), loading = busy, onClick = { send("arrived", withNote = note.trim()) })
                Text(
                    stringResource(R.string.day_try_location), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                    modifier = Modifier.clickable(enabled = !busy) { arrive() },
                )
            }
            next != null -> SettingsPrimaryButton(
                text = stringResource(next.label),
                loading = busy,
                onClick = {
                    val step = next.step
                    when {
                        step == null -> onOpenDeliver()
                        (step == "set_out" || step == "arrived") && !hasLocationPermission(context) -> {
                            pendingStep = step
                            askPermission.launch(trackingPermissions())
                        }
                        step == "set_out" && Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
                            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED -> {
                            pendingStep = step
                            askPermission.launch(trackingPermissions())
                        }
                        else -> go(step)
                    }
                },
            )
        }
        InlineErrorBanner(error, Modifier.fillMaxWidth())
    }

    // Running without the location: the customer can't follow the truck. Said plainly, with the way to fix it.
    if (status in MoveTracking.TRACKED && !permitted) {
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Amber.copy(alpha = 0.10f))
                .clickable { askPermission.launch(trackingPermissions()) }.padding(12.dp),
            verticalAlignment = Alignment.Top,
        ) {
            Icon(Icons.Rounded.WarningAmber, contentDescription = null, tint = Amber, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.day_location_off), color = settingsInk(), fontSize = 12.sp, lineHeight = 18.sp)
        }
    }

    // The way there: to the pickup until the truck is loading, then to the drop-off.
    val toDropoff = status == "loading" || status == "in_transit" || status == "unloading"
    val end = if (toDropoff) move.dropoff else move.pickup
    if (next != null && !waitingForAnswer && end != null && (end.lat != null || !end.address.isNullOrBlank())) {
        RowCard(
            Icons.Rounded.Navigation,
            stringResource(if (toDropoff) R.string.day_nav_dropoff else R.string.day_nav_pickup),
            end.address ?: end.area,
            onClick = { openNavigation(context, end) },
        )
    }
    // The pieces: while they are numbered, and again at the delivery.
    if (status == "arrived" || status == "loading" || status == "unloading") {
        RowCard(
            Icons.Rounded.Inventory2,
            stringResource(R.string.day_inventory),
            stringResource(if (status == "unloading") R.string.day_inventory_unloading_sub else R.string.day_inventory_sub),
            onClick = onOpenInventory,
        )
    }

    if (declining) {
        ConfirmDialog(
            icon = Icons.Rounded.LocalShipping,
            title = stringResource(R.string.mv_decline_job),
            message = stringResource(R.string.mv_decline_job_message),
            confirmLabel = stringResource(R.string.mv_decline_job),
            loading = busy,
            onConfirm = ::decline,
            onDismiss = { if (!busy) declining = false },
        )
    }
}

// ---------------------------------------------------------------- a photo: the camera first

private class PhotoPicker(val camera: () -> Unit, val gallery: () -> Unit)

/** A photo from the camera or the gallery, scaled and saved as [name] in the cache; [onFile] gets it (or [onError] says why not). */
@Composable
private fun rememberPhotoPicker(name: String, onFile: (File) -> Unit, onError: (String) -> Unit): PhotoPicker {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val wrongType = stringResource(R.string.day_photo_type)
    var cameraUri by rememberSaveable { mutableStateOf<String?>(null) }

    fun take(uri: Uri) {
        scope.launch {
            val file = withContext(Dispatchers.IO) { prepareDocFile(context, uri, name) }
            if (file == null) onError(wrongType) else onFile(file)
        }
    }

    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri -> uri?.let { take(it) } }
    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { taken -> if (taken) cameraUri?.let { take(Uri.parse(it)) } }
    fun launchCamera() {
        val file = File(context.cacheDir, "camera").apply { mkdirs() }.let { File(it, "$name.jpg") }
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
        cameraUri = uri.toString()
        camera.launch(uri)
    }
    val cameraPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted -> if (granted) launchCamera() }

    return PhotoPicker(
        camera = {
            if (ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) launchCamera()
            else cameraPermission.launch(Manifest.permission.CAMERA)
        },
        gallery = { gallery.launch(arrayOf("image/jpeg", "image/png")) },
    )
}

/** The chosen photo (if any) with the two ways to take it, the camera first — the papers' upload row. */
@Composable
private fun PhotoRow(title: String, file: File?, picker: PhotoPicker) {
    FieldLabel(title)
    Row(verticalAlignment = Alignment.CenterVertically) {
        if (file != null) {
            AsyncImage(model = file, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.size(64.dp).clip(RoundedCornerShape(12.dp)))
            Spacer(Modifier.width(12.dp))
        }
        Text(stringResource(R.string.pd_camera), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { picker.camera() })
        Spacer(Modifier.width(20.dp))
        Text(stringResource(R.string.pd_gallery), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { picker.gallery() })
    }
}

private fun photoPart(file: File): MultipartBody.Part =
    MultipartBody.Part.createFormData("photo", file.name, file.asRequestBody("image/jpeg".toMediaType()))

private fun textPart(value: String): RequestBody = value.toRequestBody("text/plain".toMediaType())

// ---------------------------------------------------------------- the pieces

/**
 * The job's numbered pieces (REQ-029, REQ-031). While arrived or loading the driver adds them, each with its photo; while unloading,
 * each gets its after-photo or a reservation. Anyone else who opens the job sees them read-only.
 */
@Composable
internal fun InventoryPage(move: MovingRequestDto, isDriver: Boolean, onBack: () -> Unit) {
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var items by remember { mutableStateOf<List<InventoryItemDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var deliveringUuid by rememberSaveable { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    suspend fun reload() {
        when (val result = apiCall { ApiClient.moving.inventory(move.uuid) }) {
            is ApiResult.Ok -> {
                items = result.data
                error = null
            }
            else -> error = result.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) { reload() }

    val canAdd = isDriver && (move.status == "arrived" || move.status == "loading")
    val canDeliver = isDriver && move.status == "unloading"

    val delivering = items?.firstOrNull { it.uuid == deliveringUuid }
    if (delivering != null) {
        BackHandler { deliveringUuid = null }
        DeliverItemPage(move.uuid, delivering, onBack = { deliveringUuid = null }, onDone = { scope.launch { reload(); deliveringUuid = null } })
        return
    }

    val list = items
    Page(title = stringResource(R.string.day_inventory), onBack = onBack) {
        FormIntro(
            Icons.Rounded.Inventory2, stringResource(R.string.day_inventory),
            stringResource(if (canDeliver) R.string.day_inventory_unloading_body else if (canAdd) R.string.day_inventory_body else R.string.day_inventory_read_body),
        )
        if (canAdd) AddItemCard(move.uuid, onAdded = { scope.launch { reload() } })
        when {
            list == null -> if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 24.dp).size(28.dp))
            list.isEmpty() -> EmptyState(Icons.Rounded.Inventory2, stringResource(R.string.day_inventory_empty_title), stringResource(R.string.day_inventory_empty_body), Modifier.padding(top = 12.dp))
            else -> list.forEach { item -> ItemCard(item, canDeliver) { deliveringUuid = item.uuid } }
        }
        InlineErrorBanner(error, Modifier.fillMaxWidth())
    }
}

@Composable
private fun AddItemCard(jobUuid: String, onAdded: () -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var label by rememberSaveable { mutableStateOf("") }
    var trip by rememberSaveable { mutableStateOf("") }
    var note by rememberSaveable { mutableStateOf("") }
    var photoPath by rememberSaveable { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val photo = photoPath?.let { File(it) }?.takeIf { it.exists() }
    val tripValue = trip.toIntOrNull()
    val tripOk = trip.isBlank() || (tripValue != null && tripValue in 1..50)
    val picker = rememberPhotoPicker("piece_before", onFile = { photoPath = it.absolutePath; error = null }, onError = { error = it })

    fun add() {
        val file = photo ?: return
        busy = true
        error = null
        val fields = mutableMapOf("label" to textPart(label.trim()))
        tripValue?.let { fields["trip_no"] = textPart(it.toString()) }
        note.trim().takeIf { it.isNotEmpty() }?.let { fields["note"] = textPart(it) }
        scope.launch {
            val result = apiCall { ApiClient.moving.addItem(jobUuid, fields, photoPart(file)) }
            busy = false
            if (result is ApiResult.Ok) {
                file.delete()
                label = ""
                note = ""
                photoPath = null
                onAdded()
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    Card {
        Text(stringResource(R.string.day_add_piece), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
        DorrTextField(label, { label = it.take(150) }, label = stringResource(R.string.day_piece_label), placeholder = stringResource(R.string.day_piece_label_hint), icon = Icons.Rounded.Inventory2, modifier = Modifier.fillMaxWidth())
        PhotoRow(stringResource(R.string.day_piece_photo), photo, picker)
        DorrTextField(
            trip, { trip = it.filter(Char::isDigit).take(2) }, label = stringResource(R.string.day_piece_trip), placeholder = stringResource(R.string.onb_optional), icon = Icons.Rounded.Repeat,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), error = !tripOk, modifier = Modifier.fillMaxWidth(),
        )
        DorrTextField(note, { note = it.take(500) }, label = stringResource(R.string.day_piece_note), placeholder = stringResource(R.string.day_piece_note_hint), icon = Icons.Rounded.Notes, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
        SettingsPrimaryButton(text = stringResource(R.string.day_add_piece), enabled = label.isNotBlank() && photo != null && tripOk, loading = busy, onClick = ::add)
        InlineErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/** One piece: its number, photo before (and after), what it is and what was noted about it. */
@Composable
private fun ItemCard(item: InventoryItemDto, canDeliver: Boolean, onDeliver: () -> Unit) {
    Card {
        Row(verticalAlignment = Alignment.Top) {
            Thumb(item.before)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(stringResource(R.string.day_piece_line, item.number, item.label.orEmpty()), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                item.tripNo?.takeIf { it > 1 }?.let { Text(stringResource(R.string.day_piece_trip_line, it), color = settingsMut(), fontSize = 12.sp) }
                item.conditionNote?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp) }
                item.deliveredNote?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.day_piece_reservation_line, it), color = Amber, fontSize = 12.sp, lineHeight = 17.sp) }
            }
            if (item.after != null) {
                Spacer(Modifier.width(10.dp))
                Thumb(item.after)
            }
        }
        if (canDeliver) {
            Text(
                stringResource(if (item.after != null || !item.deliveredNote.isNullOrBlank()) R.string.day_piece_update else R.string.day_piece_deliver),
                color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(onClick = onDeliver),
            )
        }
    }
}

@Composable
private fun Thumb(url: String?) {
    val image = ApiClient.mediaUrl(url)
    if (image != null) {
        AsyncImage(model = image, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.size(64.dp).clip(RoundedCornerShape(12.dp)))
    } else {
        Box(Modifier.size(64.dp).clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.10f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Inventory2, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(24.dp))
        }
    }
}

/** A piece at the delivery: its after-photo, a reservation, or both (at least one). */
@Composable
private fun DeliverItemPage(jobUuid: String, item: InventoryItemDto, onBack: () -> Unit, onDone: () -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var note by rememberSaveable { mutableStateOf(item.deliveredNote.orEmpty()) }
    var photoPath by rememberSaveable { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val photo = photoPath?.let { File(it) }?.takeIf { it.exists() }
    val picker = rememberPhotoPicker("piece_after", onFile = { photoPath = it.absolutePath; error = null }, onError = { error = it })

    fun save() {
        busy = true
        error = null
        val fields = mutableMapOf<String, RequestBody>()
        note.trim().takeIf { it.isNotEmpty() }?.let { fields["note"] = textPart(it) }
        scope.launch {
            val result = apiCall { ApiClient.moving.deliverItem(jobUuid, item.uuid, fields, photo?.let(::photoPart)) }
            busy = false
            if (result is ApiResult.Ok) {
                photo?.delete()
                onDone()
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    Page(title = stringResource(R.string.day_piece_line, item.number, item.label.orEmpty()), onBack = onBack) {
        Card {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Thumb(item.before)
                Spacer(Modifier.width(10.dp))
                Text(item.conditionNote?.takeIf { it.isNotBlank() } ?: stringResource(R.string.day_piece_before), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp, modifier = Modifier.weight(1f))
            }
        }
        Card {
            PhotoRow(stringResource(R.string.day_piece_after_photo), photo, picker)
            DorrTextField(note, { note = it.take(500) }, label = stringResource(R.string.day_piece_reservation), placeholder = stringResource(R.string.onb_optional), icon = Icons.Rounded.Notes, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
        }
        // The server needs something to record, and an empty form has nothing to send.
        SettingsPrimaryButton(text = stringResource(R.string.common_save), enabled = photo != null || note.isNotBlank(), loading = busy, onClick = ::save)
        InlineErrorBanner(error, Modifier.fillMaxWidth())
    }
}

// ---------------------------------------------------------------- the delivery

/** The customer's QR `dorr://moving/{uuid}/{code}` → the code; null when it is not this job's. */
private fun codeFromQr(contents: String, jobUuid: String): String? {
    val uri = runCatching { Uri.parse(contents) }.getOrNull() ?: return null
    if (uri.scheme != "dorr" || uri.host != "moving") return null
    val parts = uri.pathSegments
    if (parts.size != 2 || parts[0] != jobUuid) return null
    return parts[1].takeIf { it.isNotEmpty() && it.all(Char::isDigit) }
}

private const val CODE_LENGTH = 4

/**
 * Handing over the load: the customer's 4-digit code (typed in the OTP boxes or read from their QR) with who received it; or, without
 * the code, why (refused / absent) and a required note — recorded as not confirmed by the customer, and said so before the tap.
 */
@Composable
internal fun DeliverPage(move: MovingRequestDto, onBack: () -> Unit, onDone: (String) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val confirmedText = stringResource(R.string.day_delivered_ok)
    val unconfirmedText = stringResource(R.string.day_delivered_unconfirmed)
    val qrWrong = stringResource(R.string.day_qr_wrong)
    val scanPrompt = stringResource(R.string.day_scan_prompt)

    var withCode by rememberSaveable { mutableStateOf(true) }
    val digits = remember { mutableStateListOf(*Array(CODE_LENGTH) { "" }) }
    val focus = remember { List(CODE_LENGTH) { FocusRequester() } }
    var recipient by rememberSaveable { mutableStateOf("") }
    var reason by rememberSaveable { mutableStateOf<String?>(null) }
    var note by rememberSaveable { mutableStateOf("") }
    var confirming by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    val scanner = rememberLauncherForActivityResult(ScanContract()) { result ->
        val contents = result.contents ?: return@rememberLauncherForActivityResult
        val code = codeFromQr(contents, move.uuid)
        if (code == null || code.length != CODE_LENGTH) {
            error = qrWrong
        } else {
            error = null
            code.forEachIndexed { index, c -> digits[index] = c.toString() }
        }
    }

    val code = digits.joinToString("")
    val ready = if (withCode) code.length == CODE_LENGTH else reason != null && note.isNotBlank()

    fun send() {
        busy = true
        error = null
        scope.launch {
            val here = positionOrNull(context)
            val body = if (withCode) {
                DeliverBody(code = code, recipientName = recipient.trim().ifEmpty { null }, lat = here?.first, lng = here?.second)
            } else {
                DeliverBody(recipientName = recipient.trim().ifEmpty { null }, withoutCode = reason, note = note.trim(), lat = here?.first, lng = here?.second)
            }
            val result = apiCall { ApiClient.moving.deliver(move.uuid, body) }
            busy = false
            confirming = false
            if (result is ApiResult.Ok) {
                // The job left the running states: no more positions.
                MoveTracking.stop(context)
                onDone(if (withCode) confirmedText else unconfirmedText)
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    Page(title = stringResource(R.string.day_deliver), onBack = onBack) {
        FormIntro(Icons.Rounded.TaskAlt, stringResource(R.string.day_deliver), stringResource(R.string.day_deliver_body))
        ChipRow(
            options = listOf("code" to stringResource(R.string.day_with_code), "without" to stringResource(R.string.day_without_code)),
            selected = setOf(if (withCode) "code" else "without"),
            onToggle = { withCode = it == "code"; error = null },
            modifier = Modifier.fillMaxWidth(),
        )
        if (withCode) {
            Card {
                FieldLabel(stringResource(R.string.day_code))
                // The digits are always left-to-right, even in Arabic.
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.CenterHorizontally)) {
                        for (i in 0 until CODE_LENGTH) {
                            DigitBox(
                                value = digits[i],
                                onValueChange = { v ->
                                    digits[i] = v
                                    if (v.isNotEmpty() && i < CODE_LENGTH - 1) focus[i + 1].requestFocus()
                                },
                                onBackspaceOnEmpty = {
                                    if (i > 0) {
                                        digits[i - 1] = ""
                                        focus[i - 1].requestFocus()
                                    }
                                },
                                focusRequester = focus[i],
                                overrideBorderColor = null,
                            )
                        }
                    }
                }
                Row(
                    Modifier.clickable { scanner.launch(ScanOptions().setPrompt(scanPrompt).setBeepEnabled(true).setOrientationLocked(true)) },
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.QrCodeScanner, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(stringResource(R.string.day_scan), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                }
            }
        } else {
            Card {
                FieldLabel(stringResource(R.string.day_without_reason))
                PickRow(selected = reason == "refused", title = stringResource(R.string.day_reason_refused), subtitle = null) { reason = "refused" }
                PickRow(selected = reason == "absent", title = stringResource(R.string.day_reason_absent), subtitle = null) { reason = "absent" }
                DorrTextField(note, { note = it.take(1000) }, label = stringResource(R.string.day_what_happened), icon = Icons.Rounded.Notes, singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
            }
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Amber.copy(alpha = 0.10f)).padding(12.dp),
                verticalAlignment = Alignment.Top,
            ) {
                Icon(Icons.Rounded.WarningAmber, contentDescription = null, tint = Amber, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.day_without_warning), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, lineHeight = 18.sp)
            }
        }
        DorrTextField(recipient, { recipient = it.take(120) }, label = stringResource(R.string.day_recipient), placeholder = stringResource(R.string.onb_optional), icon = Icons.Rounded.Person, modifier = Modifier.fillMaxWidth())
        SettingsPrimaryButton(
            text = stringResource(R.string.day_deliver),
            enabled = ready,
            loading = busy,
            onClick = { if (withCode) send() else confirming = true },
        )
        InlineErrorBanner(error, Modifier.fillMaxWidth())
        Spacer(Modifier.height(4.dp))
    }

    if (confirming) {
        ConfirmDialog(
            icon = Icons.Rounded.WarningAmber,
            title = stringResource(R.string.day_without_code),
            message = stringResource(R.string.day_without_warning),
            confirmLabel = stringResource(R.string.common_yes),
            loading = busy,
            onConfirm = ::send,
            onDismiss = { if (!busy) confirming = false },
        )
    }
}
