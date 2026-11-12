@file:OptIn(ExperimentalLayoutApi::class)

package com.dorr.app.ui.screens.moving

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowForward
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AddPhotoAlternate
import androidx.compose.material.icons.rounded.Build
import androidx.compose.material.icons.rounded.Chair
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Construction
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Elevator
import androidx.compose.material.icons.rounded.Engineering
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.rounded.FactCheck
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.Layers
import androidx.compose.material.icons.rounded.LocalShipping
import androidx.compose.material.icons.rounded.MyLocation
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.PlayCircleFilled
import androidx.compose.material.icons.rounded.Remove
import androidx.compose.material.icons.rounded.Store
import androidx.compose.material.icons.rounded.SwapVert
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MovingMediaDto
import com.dorr.app.network.MovingOptionsDto
import com.dorr.app.network.MovingRequestDto
import com.dorr.app.network.apiFailure
import com.dorr.app.network.movingRefusal
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.rides.AccentButton
import com.dorr.app.ui.screens.rides.RidePoint
import com.dorr.app.ui.screens.rides.StepperLine
import com.dorr.app.ui.screens.rides.addressOf
import com.dorr.app.ui.screens.rides.currentLocation
import com.dorr.app.ui.screens.rides.parseTime
import com.dorr.app.ui.screens.rides.pressable
import com.dorr.app.ui.screens.rides.rememberWhenLabels
import com.dorr.app.ui.screens.rides.staggerIn
import com.dorr.app.ui.screens.rides.whenText
import com.dorr.app.ui.theme.AppColors
import com.google.gson.JsonObject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import java.io.File
import java.time.ZonedDateTime
import java.util.Locale

/*
 * A new request (REQ-081), one draft kept on the server so a lost connection loses nothing. The customer starts with
 * what they know: from and to (on a map, with the road distance) · the load, what is moved and its photos · vehicle,
 * workers and extras · the time and notes · review and publish. Nothing is required before the review: the server says
 * what is missing (`data.missing`), and that is shown beside the step it belongs to.
 *
 * The server makes the draft with its load type (`POST requests` needs `load_type`), so the places typed before it stay
 * on the phone; choosing the load type creates the draft (photos need it), and every "next" after that saves it
 * (`PUT requests/{uuid}`).
 */

private const val STEP_PLACES = 0
private const val STEP_LOAD = 1
private const val STEP_VEHICLE = 2
private const val STEP_TIME = 3
private const val STEP_REVIEW = 4
private const val STEP_COUNT = 5

private val IMAGE_OR_VIDEO = setOf("image/jpeg", "image/png", "image/webp", "video/mp4", "video/quicktime")

/** Which step a field the server says is missing belongs to. */
private fun stepOfMissing(key: String): Int = when (key) {
    "pickup", "dropoff" -> STEP_PLACES
    "load_type", "description_or_photo" -> STEP_LOAD
    "vehicle_type_id" -> STEP_VEHICLE
    else -> STEP_TIME
}

/** One end of the move being filled in. */
@Stable
internal class MovingEnd {
    var point by mutableStateOf<RidePoint?>(null)
    var address by mutableStateOf("")
    var area by mutableStateOf("")
    var floor by mutableStateOf("")
    var elevator by mutableStateOf(false)
    var entrance by mutableStateOf("")

    fun fill(dto: com.dorr.app.network.MovingEndDto?) {
        val d = dto ?: return
        val lat = d.lat
        val lng = d.lng
        if (lat != null && lng != null) point = RidePoint(lat, lng, d.address.orEmpty())
        address = d.address.orEmpty()
        area = d.area.orEmpty()
        floor = d.floor?.toString().orEmpty()
        elevator = d.elevator == true
        entrance = d.entrance.orEmpty()
    }

    /** Pickup and drop-off change places, with all their details. */
    fun swapWith(other: MovingEnd) {
        val p = point
        val a = address
        val ar = area
        val f = floor
        val e = elevator
        val en = entrance
        point = other.point
        address = other.address
        area = other.area
        floor = other.floor
        elevator = other.elevator
        entrance = other.entrance
        other.point = p
        other.address = a
        other.area = ar
        other.floor = f
        other.elevator = e
        other.entrance = en
    }

    /** An empty text clears the field on the server (Laravel reads it as null). */
    fun into(body: JsonObject, end: String) {
        point?.let {
            body.addProperty("${end}_lat", it.lat)
            body.addProperty("${end}_lng", it.lng)
        }
        body.addProperty("${end}_address", address.trim().take(255))
        body.addProperty("${end}_area", area.trim().take(120))
        val level = floor.trim().toIntOrNull()
        if (level != null) body.addProperty("${end}_floor", level) else body.addProperty("${end}_floor", "")
        body.addProperty("${end}_elevator", elevator)
        body.addProperty("${end}_entrance", entrance.trim().take(255))
    }
}

/** The draft on this phone, until each step saves it. */
@Stable
internal class MovingDraft {
    var uuid by mutableStateOf<String?>(null)
    var loadType by mutableStateOf<String?>(null)
    var description by mutableStateOf("")
    var pieces by mutableStateOf("")
    var dimensions by mutableStateOf("")
    /** The vehicle types the customer accepts, in the order picked: the first is their first choice. */
    val vehicleTypeIds = mutableStateListOf<Int>()
    var workers by mutableIntStateOf(0)
    var packing by mutableStateOf(false)
    var disassembly by mutableStateOf(false)
    var crane by mutableStateOf(false)
    var technician by mutableStateOf(false)
    val pickup = MovingEnd()
    val dropoff = MovingEnd()
    var scheduledAt by mutableStateOf<ZonedDateTime?>(null)
    var notes by mutableStateOf("")
    val photos = mutableStateListOf<MovingMediaDto>()
    val videos = mutableStateListOf<MovingMediaDto>()
    /** The voice note about the load (one; a new one replaces it). */
    var voice by mutableStateOf<MovingMediaDto?>(null)
    var missing by mutableStateOf<List<String>>(emptyList())

    fun fill(r: MovingRequestDto) {
        uuid = r.uuid
        loadType = r.loadType
        description = r.description.orEmpty()
        pieces = r.piecesCount?.toString().orEmpty()
        dimensions = r.dimensions.orEmpty()
        vehicleTypeIds.clear()
        vehicleTypeIds.addAll(r.vehicleTypeIds.ifEmpty { listOfNotNull(r.vehicleTypeId) })
        workers = r.workersCount ?: 0
        packing = r.extras?.packing == true
        disassembly = r.extras?.disassembly == true
        crane = r.extras?.crane == true
        technician = r.extras?.technician == true
        pickup.fill(r.pickup)
        dropoff.fill(r.dropoff)
        scheduledAt = parseTime(r.scheduledAt)
        notes = r.notes.orEmpty()
        photos.clear()
        photos.addAll(r.photos)
        videos.clear()
        videos.addAll(r.videos)
        voice = r.voice
    }

    fun body(): JsonObject = JsonObject().apply {
        loadType?.let { addProperty("load_type", it) }
        addProperty("description", description.trim().take(3000))
        val count = pieces.trim().toIntOrNull()
        if (count != null) addProperty("pieces_count", count) else addProperty("pieces_count", "")
        addProperty("dimensions", dimensions.trim().take(255))
        add("vehicle_type_ids", com.google.gson.JsonArray().apply { vehicleTypeIds.forEach { add(it) } })
        addProperty("workers_count", workers)
        addProperty("packing", packing)
        addProperty("disassembly", disassembly)
        addProperty("crane", crane)
        addProperty("technician", technician)
        addProperty("notes", notes.trim().take(2000))
        pickup.into(this, "pickup")
        dropoff.into(this, "dropoff")
        // A time that has passed since is not sent (the server refuses a past one); the review asks for a new one.
        scheduledAt?.takeIf { it.isAfter(ZonedDateTime.now()) }?.let { addProperty("scheduled_at", it.toOffsetDateTime().toString()) }
    }
}

/** Copies a picked file to the cache and makes it the `file` part (the phone's copy, so a big video stays out of memory). */
private suspend fun mediaFile(context: Context, uri: Uri, mime: String): File? = withContext(Dispatchers.IO) {
    runCatching {
        val ext = when (mime) {
            "image/png" -> "png"
            "image/webp" -> "webp"
            "video/mp4" -> "mp4"
            "video/quicktime" -> "mov"
            else -> "jpg"
        }
        val file = File(context.cacheDir, "moving_${System.currentTimeMillis()}.$ext")
        val copied = context.contentResolver.openInputStream(uri)?.use { input -> file.outputStream().use { input.copyTo(it) } } != null
        if (copied) file else null
    }.getOrNull()
}

private fun Double.megabytes(): String = if (this % 1.0 == 0.0) toInt().toString() else String.format(Locale.US, "%.1f", this)

private fun hasLocationPermission(context: Context): Boolean =
    ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

@Composable
internal fun MovingWizard(initialUuid: String?, nav: MovingNav, atReview: Boolean = false) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val draft = remember { MovingDraft() }
    var step by remember { mutableIntStateOf(if (atReview) STEP_REVIEW else STEP_PLACES) }
    var forward by remember { mutableStateOf(true) }
    var loaded by remember { mutableStateOf(initialUuid == null) }
    var options by remember { mutableStateOf<MovingOptionsDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var picker by remember { mutableStateOf<String?>(null) }
    var timeOpen by remember { mutableStateOf(false) }
    var pickupIsHere by remember { mutableStateOf(false) }
    var hasLocation by remember { mutableStateOf(hasLocationPermission(context)) }
    val askLocation = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted -> hasLocation = granted.values.any { it } }
    // One save at a time: two quick taps never make two drafts (the second one finds the uuid and updates).
    val saving = remember { Mutex() }
    val failed = stringResource(R.string.rd_error)
    val saveFailed = stringResource(R.string.mv_save_failed)

    LaunchedEffect(Unit) {
        options = runCatching { ApiClient.moving.options(mvAuth()).data }.getOrNull()
        if (initialUuid != null) {
            runCatching { ApiClient.moving.show(mvAuth(), initialUuid).data }
                .onSuccess { show ->
                    if (show != null) {
                        draft.fill(show.request)
                        // Published again: said at once what it still needs (a time that has passed was cleared).
                        draft.missing = if (atReview) show.missing else emptyList()
                    }
                }
                .onFailure { error = it.apiFailure().message ?: failed }
            loaded = true
        }
    }

    // A new request starts from where the customer is: the pickup is the phone's place when location is allowed, so
    // most people only choose the drop-off. Asked once; without it they choose both.
    LaunchedEffect(loaded) {
        if (loaded && initialUuid == null && draft.pickup.point == null && !hasLocation) {
            runCatching { askLocation.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION)) }
        }
    }
    LaunchedEffect(loaded, hasLocation) {
        if (!loaded || !hasLocation || initialUuid != null || draft.pickup.point != null) return@LaunchedEffect
        val here = currentLocation(context) ?: return@LaunchedEffect
        if (draft.pickup.point != null) return@LaunchedEffect
        val address = addressOf(context, here) ?: String.format(Locale.US, "%.5f, %.5f", here.latitude, here.longitude)
        if (draft.pickup.point != null) return@LaunchedEffect
        draft.pickup.point = RidePoint(here.latitude, here.longitude, address)
        draft.pickup.address = address
        pickupIsHere = true
        areaOf(context, here)?.takeIf { it.isNotBlank() }?.let { draft.pickup.area = it }
    }

    // The road between the two ends: asked once per pair, kept here so the places step and the review share it.
    val route = rememberMovingRoute(draft.pickup.point?.latLng, draft.dropoff.point?.latLng)

    /** Saves what is on the phone; false when it couldn't (and says why, the server's words first). */
    suspend fun save(): Boolean = saving.withLock {
        // No load type yet: the server can't make the draft, so what is typed stays on the phone until it is chosen.
        if (draft.uuid == null && draft.loadType == null) return@withLock true
        val body = draft.body()
        val uuid = draft.uuid
        runCatching {
            if (uuid == null) ApiClient.moving.create(mvAuth(), body).data else ApiClient.moving.update(mvAuth(), uuid, body).data
        }.fold(
            onSuccess = { fresh ->
                fresh?.let { draft.uuid = it.uuid }
                error = null
                true
            },
            onFailure = {
                val refusal = it.movingRefusal()
                if (refusal.code == "moving_service_off") {
                    nav.serviceOff = true
                    nav.pop()
                } else {
                    error = refusal.message ?: saveFailed
                }
                false
            },
        )
    }

    fun go(to: Int) {
        forward = to > step
        step = to
    }

    fun next() {
        if (busy) return
        busy = true
        scope.launch {
            if (save()) {
                if (step < STEP_REVIEW) go(step + 1)
            }
            busy = false
        }
    }

    fun publish() {
        if (busy) return
        busy = true
        error = null
        scope.launch {
            if (save()) {
                val uuid = draft.uuid
                if (uuid != null) {
                    runCatching { ApiClient.moving.publish(mvAuth(), uuid).data }
                        .onSuccess { nav.replace(MvRoute.Request(uuid)) }
                        .onFailure {
                            val refusal = it.movingRefusal()
                            draft.missing = refusal.missing
                            error = refusal.message ?: failed
                        }
                } else {
                    // Never saved (no load type): the review shows what is missing.
                    draft.missing = listOf("load_type")
                }
            }
            busy = false
        }
    }

    fun swapEnds() {
        draft.pickup.swapWith(draft.dropoff)
        pickupIsHere = false
        draft.missing = draft.missing.filterNot { (it == "pickup" && draft.pickup.point != null) || (it == "dropoff" && draft.dropoff.point != null) }
        scope.launch { save() }
    }

    fun chooseType(type: String) {
        draft.loadType = type
        draft.missing = draft.missing.filterNot { it == "load_type" }
        // The first choice makes the draft on the server, so photos can be added right away.
        if (draft.uuid == null) scope.launch { save() }
    }

    // Back steps through the wizard before it leaves it; what was saved stays in «طلباتي» as a draft.
    BackHandler(enabled = step > STEP_PLACES && picker == null) { go(step - 1) }

    val missingSteps = draft.missing.map { stepOfMissing(it) }.toSet()
    Box(Modifier.fillMaxSize()) {
        MvPage(
            title = stringResource(R.string.mv_new_title),
            onBack = { if (step > STEP_PLACES) go(step - 1) else nav.pop() },
            bottom = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    MvError(error)
                    if (step == STEP_REVIEW) {
                        AccentButton(stringResource(R.string.mv_publish), busy = busy, icon = Icons.Rounded.CheckCircle) { publish() }
                    } else {
                        AccentButton(
                            stringResource(R.string.mv_next),
                            enabled = step != STEP_LOAD || draft.loadType != null,
                            busy = busy,
                            icon = Icons.AutoMirrored.Rounded.ArrowForward,
                        ) { next() }
                    }
                }
            },
        ) {
            if (!loaded) {
                MvLoading()
                return@MvPage
            }
            Column(Modifier.fillMaxSize()) {
                MovingStepBar(step, missingSteps) { target -> if (target <= step) go(target) }
                AnimatedContent(
                    targetState = step,
                    transitionSpec = { val side = if (forward) 1 else -1; (slideInHorizontally(tween(300)) { side * it / 4 } + fadeIn(tween(240))) togetherWith fadeOut(tween(160)) },
                    label = "mv-steps",
                    modifier = Modifier.weight(1f).fillMaxWidth(),
                ) { current ->
                    when (current) {
                        STEP_PLACES -> PlacesStep(draft, route, pickupIsHere, onPick = { picker = it }, onSwap = { swapEnds() })
                        STEP_LOAD -> LoadStep(draft, options, onType = { chooseType(it) }, scope = { block -> scope.launch { block() } }, context = context)
                        STEP_VEHICLE -> VehicleStep(draft, options)
                        STEP_TIME -> TimeStep(draft, onTime = { timeOpen = true })
                        else -> ReviewStep(draft, options, route, onEdit = { go(it) })
                    }
                }
            }
        }

        // One end of the move: over the wizard. Picking it saves the draft (once there is one), so a lost connection keeps it too.
        picker?.let { which ->
            val end = if (which == "pickup") draft.pickup else draft.dropoff
            MovingPlacePicker(
                title = stringResource(if (which == "pickup") R.string.mv_pickup else R.string.mv_dropoff),
                start = end.point,
                near = (draft.pickup.point ?: draft.dropoff.point)?.latLng,
                onClose = { picker = null },
                onPick = { place ->
                    end.point = place
                    end.address = place.address
                    if (which == "pickup") pickupIsHere = false
                    picker = null
                    scope.launch {
                        val found = areaOf(context, place.latLng)
                        if (!found.isNullOrBlank()) end.area = found
                        draft.missing = draft.missing.filterNot { it == which }
                        save()
                    }
                },
            )
        }

        if (timeOpen) MovingTimeSheet(
            initial = draft.scheduledAt,
            title = stringResource(R.string.mv_when),
            onClose = { timeOpen = false },
            onPick = {
                draft.scheduledAt = it
                draft.missing = draft.missing.filterNot { key -> key == "scheduled_at" }
                timeOpen = false
                scope.launch { save() }
            },
        )
    }
}

// ---------------------------------------------------------------- the step bar

private fun stepIcon(i: Int): ImageVector = when (i) {
    STEP_PLACES -> Icons.Rounded.Place
    STEP_LOAD -> Icons.Rounded.Inventory2
    STEP_VEHICLE -> Icons.Rounded.LocalShipping
    STEP_TIME -> Icons.Rounded.Event
    else -> Icons.Rounded.FactCheck
}

@Composable
private fun stepShortLabel(i: Int): String = stringResource(
    when (i) {
        STEP_PLACES -> R.string.mv_step_short_places
        STEP_LOAD -> R.string.mv_step_short_load
        STEP_VEHICLE -> R.string.mv_step_short_vehicle
        STEP_TIME -> R.string.mv_step_short_time
        else -> R.string.mv_step_short_review
    },
)

/**
 * The five steps as icons joined by a line, each with its short name: done ones filled with a tick, the current one
 * filled and a little bigger, the rest waiting. A step the server says is missing something turns red. A step already
 * reached can be tapped.
 */
@Composable
private fun MovingStepBar(step: Int, missing: Set<Int>, onTap: (Int) -> Unit) {
    val accent = settingsAccent()
    val idleLine = settingsInk().copy(alpha = 0.10f)
    Row(Modifier.fillMaxWidth().padding(horizontal = 8.dp).padding(bottom = 12.dp), verticalAlignment = Alignment.Top) {
        repeat(STEP_COUNT) { i ->
            val done = i < step
            val now = i == step
            val bad = i in missing
            val fill by animateColorAsState(
                when {
                    bad -> AppColors.danger
                    done || now -> accent
                    else -> mvInsetColor()
                },
                tween(260), label = "mv-step-fill",
            )
            val pop by animateFloatAsState(if (now) 1.12f else 1f, spring(dampingRatio = 0.5f), label = "mv-step-pop")
            Column(
                Modifier.weight(1f).clip(RoundedCornerShape(12.dp)).clickable(enabled = i <= step) { onTap(i) }.padding(vertical = 2.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Box(Modifier.fillMaxWidth().height(40.dp), contentAlignment = Alignment.Center) {
                    // The line to the step before (start side) and to the one after (end side).
                    if (i > 0) Box(Modifier.align(Alignment.CenterStart).fillMaxWidth(0.5f).height(3.dp).background(if (i <= step) accent else idleLine))
                    if (i < STEP_COUNT - 1) Box(Modifier.align(Alignment.CenterEnd).fillMaxWidth(0.5f).height(3.dp).background(if (i < step) accent else idleLine))
                    Box(
                        Modifier.scale(pop).size(34.dp).clip(CircleShape).background(fill)
                            .border(if (now) 3.dp else 0.dp, if (now) fill.copy(alpha = 0.25f) else Color.Transparent, CircleShape),
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(
                            if (done && !bad) Icons.Rounded.Check else stepIcon(i), null,
                            tint = if (done || now || bad) Color.White else settingsMut(),
                            modifier = Modifier.size(17.dp),
                        )
                    }
                }
                Spacer(Modifier.height(4.dp))
                Text(
                    stepShortLabel(i),
                    color = when {
                        bad -> AppColors.danger
                        now -> settingsInk()
                        else -> settingsMut()
                    },
                    fontSize = 10.5.sp, fontWeight = if (now) FontWeight.Bold else FontWeight.Medium,
                    maxLines = 1, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center,
                )
            }
        }
    }
}

/** A step's heading and the line under it. */
@Composable
private fun StepTitle(title: String, hint: String? = null) {
    Column(Modifier.staggerIn(0)) {
        Text(title, color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 22.sp)
        hint?.let { Text(it, color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(top = 2.dp)) }
    }
}

/** What the server says this step still needs. */
@Composable
private fun MissingNote(draft: MovingDraft, key: String) {
    if (key in draft.missing) Text(stringResource(missingText(key)), color = AppColors.danger, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold)
}

private fun missingText(key: String): Int = when (key) {
    "load_type" -> R.string.mv_missing_load_type
    "description_or_photo" -> R.string.mv_missing_description
    "pickup" -> R.string.mv_missing_pickup
    "dropoff" -> R.string.mv_missing_dropoff
    "scheduled_at" -> R.string.mv_missing_time
    else -> R.string.mv_missing_vehicle
}

// ---------------------------------------------------------------- 1. from and to

@Composable
private fun PlacesStep(draft: MovingDraft, route: MovingRouteState, pickupIsHere: Boolean, onPick: (String) -> Unit, onSwap: () -> Unit) {
    val from = draft.pickup.point?.latLng
    val to = draft.dropoff.point?.latLng
    MvScroll {
        StepTitle(stringResource(R.string.mv_places_title), stringResource(R.string.mv_places_hint))
        MvCard(Modifier.staggerIn(1)) {
            MovingRouteMap(
                pickup = from, dropoff = to, route = route.route,
                modifier = Modifier.fillMaxWidth().height(200.dp),
                onTap = { onPick(if (from == null) "pickup" else "dropoff") },
            )
            MovingRouteChips(
                route, bothEnds = from != null && to != null,
                fallbackKm = if (from != null && to != null) straightKm(from, to) else null,
                modifier = Modifier.padding(top = 10.dp),
            )
            Spacer(Modifier.height(12.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    EndRow(
                        title = stringResource(R.string.mv_pickup), end = draft.pickup, dot = AppColors.brandNavy,
                        badge = if (pickupIsHere) stringResource(R.string.mv_current_location) else null,
                    ) { onPick("pickup") }
                    MissingNote(draft, "pickup")
                    Box(Modifier.padding(start = 16.dp).width(2.dp).height(12.dp).background(settingsInk().copy(alpha = 0.12f)))
                    EndRow(title = stringResource(R.string.mv_dropoff), end = draft.dropoff, dot = AppColors.brandOrange, badge = null) { onPick("dropoff") }
                    MissingNote(draft, "dropoff")
                }
                if (from != null && to != null) {
                    Spacer(Modifier.width(8.dp))
                    Box(
                        Modifier.size(40.dp).clip(CircleShape).background(mvInsetColor()).pressable(onClick = onSwap),
                        contentAlignment = Alignment.Center,
                    ) { Icon(Icons.Rounded.SwapVert, stringResource(R.string.mv_swap), tint = settingsAccent(), modifier = Modifier.size(22.dp)) }
                }
            }
        }
        if (draft.pickup.point != null) EndDetailsCard(stringResource(R.string.mv_pickup), AppColors.brandNavy, draft.pickup, 2)
        if (draft.dropoff.point != null) EndDetailsCard(stringResource(R.string.mv_dropoff), AppColors.brandOrange, draft.dropoff, 3)
    }
}

/** One end as a row that opens the place picker: its coloured dot, its name and the chosen address. */
@Composable
private fun EndRow(title: String, end: MovingEnd, dot: Color, badge: String?, onClick: () -> Unit) {
    val chosen = end.point
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).pressable(onClick = onClick).padding(vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(34.dp).clip(CircleShape).background(dot.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(if (badge != null) Icons.Rounded.MyLocation else Icons.Rounded.Place, null, tint = dot, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(title, color = settingsMut(), fontSize = 11.sp)
                badge?.let {
                    Spacer(Modifier.width(6.dp))
                    Text(
                        it, color = settingsAccent(), fontSize = 10.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)).padding(horizontal = 6.dp, vertical = 1.dp),
                    )
                }
            }
            Text(
                chosen?.let { end.address.ifBlank { it.address } } ?: stringResource(R.string.mv_pick_place),
                color = if (chosen == null) settingsAccent() else settingsInk(), fontWeight = FontWeight.SemiBold,
                maxLines = 1, overflow = TextOverflow.Ellipsis,
            )
        }
        Icon(if (chosen == null) Icons.Rounded.Add else Icons.Rounded.Edit, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
    }
}

/** An end's building: the floor on a stepper, the elevator as a chip, and the rest folded under «تفاصيل أكثر». */
@Composable
private fun EndDetailsCard(title: String, dot: Color, end: MovingEnd, index: Int) {
    var more by remember { mutableStateOf(end.entrance.isNotBlank()) }
    val turn by animateFloatAsState(if (more) 180f else 0f, tween(220), label = "mv-more")
    MvCard(Modifier.staggerIn(index)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(10.dp).clip(CircleShape).background(dot))
            Spacer(Modifier.width(8.dp))
            MvCardTitle(title)
            Spacer(Modifier.width(8.dp))
            Text(end.area, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
        }
        Spacer(Modifier.height(8.dp))
        FloorStepper(end)
        Spacer(Modifier.height(6.dp))
        MvToggleChip(Icons.Rounded.Elevator, stringResource(R.string.mv_elevator), end.elevator) { end.elevator = !end.elevator }
        Spacer(Modifier.height(6.dp))
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { more = !more }.padding(vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(stringResource(R.string.mv_more_details), color = settingsAccent(), fontWeight = FontWeight.SemiBold, fontSize = 13.sp, modifier = Modifier.weight(1f))
            Icon(Icons.Rounded.ExpandMore, null, tint = settingsAccent(), modifier = Modifier.size(20.dp).rotate(turn))
        }
        AnimatedVisibility(visible = more, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(top = 4.dp)) {
                DorrTextField(
                    value = end.area, onValueChange = { end.area = it.take(120) },
                    label = stringResource(R.string.mv_area), placeholder = stringResource(R.string.mv_area_hint), modifier = Modifier.fillMaxWidth(),
                )
                DorrTextField(value = end.address, onValueChange = { end.address = it.take(255) }, label = stringResource(R.string.mv_address), modifier = Modifier.fillMaxWidth())
                DorrTextField(
                    value = end.entrance, onValueChange = { end.entrance = it.take(255) },
                    label = stringResource(R.string.mv_entrance), placeholder = stringResource(R.string.mv_entrance_hint), modifier = Modifier.fillMaxWidth(),
                )
            }
        }
    }
}

/** The floor with − and +: "—" until touched (unknown), then the ground floor first; −5 to 200 as the server takes. */
@Composable
private fun FloorStepper(end: MovingEnd) {
    val level = end.floor.trim().toIntOrNull()
    val shown = when (level) {
        null -> "—"
        0 -> stringResource(R.string.mv_floor_ground)
        else -> level.toString()
    }
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Rounded.Layers, null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
        Spacer(Modifier.width(10.dp))
        Text(stringResource(R.string.mv_floor), color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 14.sp, modifier = Modifier.weight(1f))
        MvStepCircle(Icons.Rounded.Remove, level == null || level > -5) { end.floor = ((level ?: 1) - 1).toString() }
        AnimatedContent(
            targetState = shown,
            transitionSpec = { (slideInVertically { it / 2 } + fadeIn(tween(160))) togetherWith (slideOutVertically { -it / 2 } + fadeOut(tween(120))) },
            label = "mv-floor",
            modifier = Modifier.width(64.dp),
        ) { text ->
            Text(text, color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 15.sp, textAlign = TextAlign.Center, maxLines = 1, modifier = Modifier.fillMaxWidth())
        }
        MvStepCircle(Icons.Rounded.Add, level == null || level < 200) { end.floor = ((level ?: -1) + 1).toString() }
    }
}

@Composable
private fun MvStepCircle(icon: ImageVector, enabled: Boolean, onClick: () -> Unit) {
    Box(
        Modifier.size(34.dp).clip(CircleShape).background(if (enabled) settingsAccent().copy(alpha = 0.12f) else mvInsetColor()).pressable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = if (enabled) settingsAccent() else settingsMut().copy(alpha = 0.4f), modifier = Modifier.size(18.dp)) }
}

/** A yes/no as a chip: filled in the app colour with a tick when on. */
@Composable
private fun MvToggleChip(icon: ImageVector, label: String, on: Boolean, onClick: () -> Unit) {
    val tint = settingsAccent()
    val bg by animateColorAsState(if (on) tint.copy(alpha = 0.12f) else mvInsetColor(), tween(200), label = "mv-chip")
    Row(
        Modifier.clip(CircleShape).background(bg).border(1.5.dp, if (on) tint else Color.Transparent, CircleShape)
            .pressable(onClick = onClick).padding(horizontal = 14.dp, vertical = 9.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = if (on) tint else settingsMut(), modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(8.dp))
        Text(label, color = settingsInk(), fontWeight = if (on) FontWeight.Bold else FontWeight.Medium, fontSize = 13.sp)
        AnimatedVisibility(visible = on) {
            Row {
                Spacer(Modifier.width(6.dp))
                Icon(Icons.Rounded.CheckCircle, null, tint = tint, modifier = Modifier.size(16.dp))
            }
        }
    }
}

// ---------------------------------------------------------------- 2. the load, what is moved, with photos

@Composable
private fun LoadStep(draft: MovingDraft, options: MovingOptionsDto?, onType: (String) -> Unit, scope: (suspend () -> Unit) -> Unit, context: Context) {
    var uploading by remember { mutableIntStateOf(0) }
    var warning by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)
    val maxMb = options?.mediaMaxMb
    val tooBig = maxMb?.let { stringResource(R.string.mv_media_too_big, it.megabytes()) }
    val wrongType = stringResource(R.string.mv_media_type)
    val typeFirst = stringResource(R.string.mv_media_first)

    fun upload(uris: List<Uri>) {
        val uuid = draft.uuid ?: return
        warning = null
        uris.forEach { uri ->
            val mime = context.contentResolver.getType(uri)?.lowercase()
            // Said before anything is sent: a file over the country's limit is never uploaded.
            val size = uriSize(context, uri)
            when {
                mime == null || mime !in IMAGE_OR_VIDEO -> warning = wrongType
                maxMb != null && size != null && size > maxMb * 1024 * 1024 -> warning = tooBig
                else -> {
                    uploading += 1
                    scope {
                        val file = mediaFile(context, uri, mime)
                        if (file == null) {
                            warning = failed
                        } else {
                            val part = MultipartBody.Part.createFormData("file", file.name, file.asRequestBody(mime.toMediaTypeOrNull()))
                            runCatching { ApiClient.moving.addMedia(mvAuth(), uuid, part).data }
                                .onSuccess { media ->
                                    if (media != null) {
                                        if (media.collection == "videos") draft.videos.add(media) else draft.photos.add(media)
                                        draft.missing = draft.missing.filterNot { it == "description_or_photo" }
                                    }
                                }
                                .onFailure { warning = it.apiFailure().message ?: failed }
                            file.delete()
                        }
                        uploading -= 1
                    }
                }
            }
        }
    }

    val pick = rememberLauncherForActivityResult(ActivityResultContracts.PickMultipleVisualMedia(10)) { uris -> upload(uris) }

    fun remove(media: MovingMediaDto) {
        val uuid = draft.uuid ?: return
        draft.photos.remove(media)
        draft.videos.remove(media)
        scope { runCatching { ApiClient.moving.removeMedia(mvAuth(), uuid, media.id) } }
    }

    MvScroll {
        StepTitle(stringResource(R.string.mv_type_title))
        MissingNote(draft, "load_type")
        Row(Modifier.fillMaxWidth().height(IntrinsicSize.Min).staggerIn(1), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            LoadTile(Icons.Rounded.Chair, stringResource(R.string.mv_load_single), stringResource(R.string.mv_load_single_hint), draft.loadType == "single_piece", Modifier.weight(1f)) { onType("single_piece") }
            LoadTile(Icons.Rounded.Home, stringResource(R.string.mv_load_home), stringResource(R.string.mv_load_home_hint), draft.loadType == "home", Modifier.weight(1f)) { onType("home") }
            LoadTile(Icons.Rounded.Store, stringResource(R.string.mv_load_commercial), stringResource(R.string.mv_load_commercial_hint), draft.loadType == "commercial", Modifier.weight(1f)) { onType("commercial") }
        }
        MvCard(Modifier.staggerIn(2)) {
            MvCardTitle(stringResource(R.string.mv_items_title))
            Spacer(Modifier.height(10.dp))
            DorrTextField(
                value = draft.description,
                onValueChange = { draft.description = it.take(3000); draft.missing = draft.missing.filterNot { k -> k == "description_or_photo" } },
                label = stringResource(R.string.mv_description), placeholder = stringResource(R.string.mv_description_hint),
                singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth(),
            )
            // Or say it: a voice note on the same page as the description.
            Spacer(Modifier.height(10.dp))
            MvVoiceNote(draft, scope, context)
            MissingNote(draft, "description_or_photo")
            Spacer(Modifier.height(10.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                DorrTextField(
                    value = draft.pieces, onValueChange = { draft.pieces = it.filter(Char::isDigit).take(5) },
                    label = stringResource(R.string.mv_pieces), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    modifier = Modifier.weight(0.4f),
                )
                DorrTextField(
                    value = draft.dimensions, onValueChange = { draft.dimensions = it.take(255) },
                    label = stringResource(R.string.mv_dimensions), placeholder = stringResource(R.string.mv_dimensions_hint),
                    modifier = Modifier.weight(0.6f),
                )
            }
        }
        MvCard(Modifier.staggerIn(3)) {
            MvCardTitle(stringResource(R.string.mv_media_title))
            Text(
                maxMb?.let { stringResource(R.string.mv_media_limit, it.megabytes()) } ?: stringResource(R.string.mv_media_hint),
                color = settingsMut(), fontSize = 12.sp,
            )
            Spacer(Modifier.height(10.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                val tile = RoundedCornerShape(16.dp)
                draft.photos.forEach { media ->
                    Box(Modifier.size(92.dp).clip(tile).background(settingsBackground())) {
                        AsyncImage(ApiClient.mediaUrl(media.url), null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                        MediaRemove(Modifier.align(Alignment.TopEnd)) { remove(media) }
                    }
                }
                draft.videos.forEach { media ->
                    Box(Modifier.size(92.dp).clip(tile).background(settingsBackground()), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.PlayCircleFilled, null, tint = settingsAccent(), modifier = Modifier.size(36.dp))
                        MediaRemove(Modifier.align(Alignment.TopEnd)) { remove(media) }
                    }
                }
                repeat(uploading) {
                    Box(Modifier.size(92.dp).clip(tile).background(settingsBackground()), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(24.dp))
                    }
                }
                Box(
                    Modifier.size(92.dp).clip(tile).background(settingsAccent().copy(alpha = 0.08f)).border(1.5.dp, settingsAccent().copy(alpha = 0.35f), tile)
                        .pressable {
                            // Photos belong to the draft on the server, made when the load type is chosen.
                            if (draft.uuid == null) warning = typeFirst
                            else pick.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo))
                        },
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.Rounded.AddPhotoAlternate, null, tint = settingsAccent(), modifier = Modifier.size(30.dp)) }
            }
            warning?.let {
                Spacer(Modifier.height(8.dp))
                MvNote(it, AppColors.warning)
            }
        }
    }
}

/** A load type as a tile: its icon in a soft square, the name and a hint; the chosen one washed, bordered and popped. */
@Composable
private fun LoadTile(icon: ImageVector, title: String, hint: String, on: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val tint = settingsAccent()
    val bg by animateColorAsState(if (on) tint.copy(alpha = 0.12f) else mvCardColor(), tween(200), label = "mv-tile")
    val pop by animateFloatAsState(if (on) 1.04f else 1f, spring(dampingRatio = 0.5f), label = "mv-tile-pop")
    val iconBg by animateColorAsState(if (on) tint else tint.copy(alpha = 0.12f), tween(200), label = "mv-tile-icon")
    Box(
        modifier.fillMaxHeight().scale(pop).clip(RoundedCornerShape(20.dp)).background(bg)
            .border(1.5.dp, if (on) tint else Color.Transparent, RoundedCornerShape(20.dp)).pressable(onClick = onClick),
    ) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 14.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(52.dp).clip(RoundedCornerShape(16.dp)).background(iconBg), contentAlignment = Alignment.Center) {
                Icon(icon, null, tint = if (on) Color.White else tint, modifier = Modifier.size(28.dp))
            }
            Spacer(Modifier.height(8.dp))
            Text(title, color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp, textAlign = TextAlign.Center, maxLines = 2)
            Spacer(Modifier.height(2.dp))
            Text(hint, color = settingsMut(), fontSize = 11.sp, textAlign = TextAlign.Center, maxLines = 3, lineHeight = 14.sp)
        }
        if (on) Icon(Icons.Rounded.CheckCircle, null, tint = tint, modifier = Modifier.align(Alignment.TopEnd).padding(6.dp).size(18.dp))
    }
}

@Composable
private fun MediaRemove(modifier: Modifier, onClick: () -> Unit) {
    Box(modifier.padding(4.dp).size(26.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.55f)).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(Icons.Rounded.Close, null, tint = Color.White, modifier = Modifier.size(15.dp))
    }
}

// ---------------------------------------------------------------- 3. the vehicle and the services

@Composable
private fun VehicleStep(draft: MovingDraft, options: MovingOptionsDto?) {
    MvScroll {
        StepTitle(stringResource(R.string.mv_vehicle_title), stringResource(R.string.mv_vehicle_hint))
        MissingNote(draft, "vehicle_type_id")
        val types = options?.vehicleTypes.orEmpty()
        if (options != null && types.isEmpty()) MvNote(stringResource(R.string.mv_no_vehicle_types))
        types.forEachIndexed { index, type ->
            val order = draft.vehicleTypeIds.indexOf(type.id)
            val on = order >= 0
            val tint = settingsAccent()
            val bg by animateColorAsState(if (on) tint.copy(alpha = 0.10f) else mvCardColor(), tween(200), label = "mv-vehicle")
            val pop by animateFloatAsState(if (on) 1.02f else 1f, spring(dampingRatio = 0.5f), label = "mv-vehicle-pop")
            Row(
                Modifier.fillMaxWidth().staggerIn(index + 1).scale(pop).clip(RoundedCornerShape(20.dp)).background(bg)
                    .border(1.5.dp, if (on) tint else Color.Transparent, RoundedCornerShape(20.dp))
                    .pressable {
                        // Several types may fit (a quarter or a half truck…): each tap adds or removes one, in the order picked.
                        if (on) draft.vehicleTypeIds.remove(type.id) else draft.vehicleTypeIds.add(type.id)
                        draft.missing = draft.missing.filterNot { it == "vehicle_type_id" }
                    }.padding(12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(Modifier.size(width = 96.dp, height = 68.dp).clip(RoundedCornerShape(14.dp)).background(tint.copy(alpha = 0.08f)), contentAlignment = Alignment.Center) {
                    if (type.image != null) AsyncImage(ApiClient.mediaUrl(type.image), null, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize().padding(4.dp))
                    else Icon(Icons.Rounded.LocalShipping, null, tint = tint, modifier = Modifier.size(34.dp))
                }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(type.name.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 15.sp)
                    type.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 3, overflow = TextOverflow.Ellipsis) }
                    if (order == 0 && draft.vehicleTypeIds.size > 1) {
                        Text(stringResource(R.string.mv_first_choice), color = tint, fontWeight = FontWeight.Bold, fontSize = 11.sp)
                    }
                }
                // Picked: its place in the customer's order (1 = first choice); not picked: an empty box.
                Box(
                    Modifier.size(26.dp).clip(RoundedCornerShape(8.dp)).background(if (on) tint else Color.Transparent)
                        .border(1.5.dp, if (on) tint else settingsMut().copy(alpha = 0.4f), RoundedCornerShape(8.dp)),
                    contentAlignment = Alignment.Center,
                ) {
                    if (on) {
                        if (draft.vehicleTypeIds.size > 1) Text("${order + 1}", color = Color.White, fontWeight = FontWeight.Black, fontSize = 13.sp)
                        else Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(15.dp))
                    }
                }
            }
        }
        MvCard(Modifier.staggerIn(types.size + 1)) {
            StepperLine(Icons.Rounded.Groups, stringResource(R.string.mv_workers), stringResource(R.string.mv_workers_hint), draft.workers, 0..50) { draft.workers = it }
        }
        Text(stringResource(R.string.mv_extras_title), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 15.sp, modifier = Modifier.staggerIn(types.size + 2))
        Column(Modifier.staggerIn(types.size + 3), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(Modifier.fillMaxWidth().height(IntrinsicSize.Min), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                ExtraTile(Icons.Rounded.Inventory2, stringResource(R.string.mv_extra_packing), stringResource(R.string.mv_extra_packing_hint), draft.packing, Modifier.weight(1f)) { draft.packing = !draft.packing }
                ExtraTile(Icons.Rounded.Build, stringResource(R.string.mv_extra_disassembly), stringResource(R.string.mv_extra_disassembly_hint), draft.disassembly, Modifier.weight(1f)) { draft.disassembly = !draft.disassembly }
            }
            Row(Modifier.fillMaxWidth().height(IntrinsicSize.Min), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                ExtraTile(Icons.Rounded.Construction, stringResource(R.string.mv_extra_crane), stringResource(R.string.mv_extra_crane_hint), draft.crane, Modifier.weight(1f)) { draft.crane = !draft.crane }
                ExtraTile(Icons.Rounded.Engineering, stringResource(R.string.mv_extra_technician), stringResource(R.string.mv_extra_technician_hint), draft.technician, Modifier.weight(1f)) { draft.technician = !draft.technician }
            }
        }
    }
}

/** An extra service as a selectable tile: icon, name, a short line; a tick in the corner when chosen. */
@Composable
private fun ExtraTile(icon: ImageVector, title: String, hint: String, on: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val tint = settingsAccent()
    val bg by animateColorAsState(if (on) tint.copy(alpha = 0.12f) else mvCardColor(), tween(200), label = "mv-extra")
    val iconBg by animateColorAsState(if (on) tint else tint.copy(alpha = 0.12f), tween(200), label = "mv-extra-icon")
    Box(
        modifier.fillMaxHeight().clip(RoundedCornerShape(18.dp)).background(bg)
            .border(1.5.dp, if (on) tint else Color.Transparent, RoundedCornerShape(18.dp)).pressable(onClick = onClick),
    ) {
        Column(Modifier.fillMaxWidth().padding(12.dp)) {
            Box(Modifier.size(40.dp).clip(RoundedCornerShape(12.dp)).background(iconBg), contentAlignment = Alignment.Center) {
                Icon(icon, null, tint = if (on) Color.White else tint, modifier = Modifier.size(22.dp))
            }
            Spacer(Modifier.height(8.dp))
            Text(title, color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp)
            Text(hint, color = settingsMut(), fontSize = 11.5.sp, lineHeight = 15.sp)
        }
        if (on) Icon(Icons.Rounded.CheckCircle, null, tint = tint, modifier = Modifier.align(Alignment.TopEnd).padding(8.dp).size(20.dp))
    }
}

// ---------------------------------------------------------------- 4. the time and the notes

@Composable
private fun TimeStep(draft: MovingDraft, onTime: () -> Unit) {
    val (today, tomorrow) = rememberWhenLabels()
    MvScroll {
        StepTitle(stringResource(R.string.mv_time_title), stringResource(R.string.mv_time_hint))
        val at = draft.scheduledAt
        val tint = if ("scheduled_at" in draft.missing) AppColors.danger else settingsAccent()
        Row(
            Modifier.fillMaxWidth().staggerIn(1).clip(RoundedCornerShape(20.dp)).background(mvCardColor())
                .border(1.5.dp, if (at == null) tint.copy(alpha = 0.35f) else Color.Transparent, RoundedCornerShape(20.dp))
                .pressable(onClick = onTime).padding(16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(Modifier.size(52.dp).clip(RoundedCornerShape(16.dp)).background(tint.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Event, null, tint = tint, modifier = Modifier.size(26.dp))
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.mv_when), color = settingsMut(), fontSize = 12.sp)
                Text(
                    at?.let { whenText(it, today, tomorrow) } ?: stringResource(R.string.mv_pick_time),
                    color = if (at == null) tint else settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 17.sp,
                )
            }
            Icon(if (at == null) Icons.AutoMirrored.Rounded.KeyboardArrowRight else Icons.Rounded.Edit, null, tint = tint, modifier = Modifier.size(20.dp))
        }
        MissingNote(draft, "scheduled_at")
        at?.takeIf { !it.isAfter(ZonedDateTime.now()) }?.let { Text(stringResource(R.string.mv_time_passed), color = AppColors.danger, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold) }
        MvCard(Modifier.staggerIn(2)) {
            DorrTextField(
                value = draft.notes, onValueChange = { draft.notes = it.take(2000) },
                label = stringResource(R.string.mv_notes), singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth(),
            )
        }
    }
}

// ---------------------------------------------------------------- 5. the review

@Composable
private fun ReviewStep(draft: MovingDraft, options: MovingOptionsDto?, route: MovingRouteState, onEdit: (Int) -> Unit) {
    val (today, tomorrow) = rememberWhenLabels()
    val from = draft.pickup.point?.latLng
    val to = draft.dropoff.point?.latLng
    MvScroll {
        StepTitle(stringResource(R.string.mv_review_title), stringResource(R.string.mv_review_hint))
        ReviewCard(stringResource(R.string.mv_step_places), Modifier.staggerIn(1), onEdit = { onEdit(STEP_PLACES) }) {
            MovingRouteMap(from, to, route.route, Modifier.fillMaxWidth().height(160.dp), onTap = { onEdit(STEP_PLACES) })
            MovingRouteChips(
                route, bothEnds = from != null && to != null,
                fallbackKm = if (from != null && to != null) straightKm(from, to) else null,
                modifier = Modifier.padding(top = 10.dp, bottom = 4.dp),
            )
            ReviewEnd(stringResource(R.string.mv_pickup), draft.pickup, AppColors.brandNavy)
            MissingNote(draft, "pickup")
            ReviewEnd(stringResource(R.string.mv_dropoff), draft.dropoff, AppColors.brandOrange)
            MissingNote(draft, "dropoff")
        }
        ReviewCard(stringResource(R.string.mv_step_load), Modifier.staggerIn(2), onEdit = { onEdit(STEP_LOAD) }) {
            Text(draft.loadType?.let { loadTypeLabel(it) } ?: "—", color = settingsInk(), fontWeight = FontWeight.SemiBold)
            MissingNote(draft, "load_type")
            if (draft.description.isNotBlank()) Text(draft.description.trim(), color = settingsInk(), fontSize = 14.sp, modifier = Modifier.padding(top = 4.dp))
            if (draft.pieces.isNotBlank()) MvLine(stringResource(R.string.mv_pieces), draft.pieces)
            if (draft.dimensions.isNotBlank()) MvLine(stringResource(R.string.mv_dimensions), draft.dimensions)
            MvLine(stringResource(R.string.mv_media_title), stringResource(R.string.mv_media_counts, draft.photos.size, draft.videos.size))
            draft.voice?.let { MvLine(stringResource(R.string.mv_voice_title), voiceTime(it.durationMs ?: 0L)) }
            MissingNote(draft, "description_or_photo")
        }
        ReviewCard(stringResource(R.string.mv_step_vehicle), Modifier.staggerIn(3), onEdit = { onEdit(STEP_VEHICLE) }) {
            val names = draft.vehicleTypeIds.mapNotNull { id -> options?.vehicleTypes?.firstOrNull { it.id == id }?.name }
            MvLine(stringResource(R.string.mv_vehicle_title), names.joinToString(" / ").ifEmpty { "—" })
            MvLine(stringResource(R.string.mv_workers), draft.workers.toString())
            val extras = listOfNotNull(
                "packing".takeIf { draft.packing },
                "disassembly".takeIf { draft.disassembly },
                "crane".takeIf { draft.crane },
                "technician".takeIf { draft.technician },
            )
            if (extras.isNotEmpty()) {
                Spacer(Modifier.height(4.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    extras.forEach { MvChip(extraLabel(it), settingsAccent()) }
                }
            }
            MissingNote(draft, "vehicle_type_id")
        }
        ReviewCard(stringResource(R.string.mv_step_time), Modifier.staggerIn(4), onEdit = { onEdit(STEP_TIME) }) {
            MvLine(stringResource(R.string.mv_when), draft.scheduledAt?.let { whenText(it, today, tomorrow) } ?: "—")
            MissingNote(draft, "scheduled_at")
            if (draft.notes.isNotBlank()) Text(draft.notes.trim(), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(top = 2.dp))
        }
    }
}

@Composable
private fun ReviewCard(title: String, modifier: Modifier = Modifier, onEdit: () -> Unit, content: @Composable ColumnScope.() -> Unit) {
    MvCard(modifier) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            MvCardTitle(title, Modifier.weight(1f))
            Text(
                stringResource(R.string.mv_edit), color = settingsAccent(), fontWeight = FontWeight.Bold, fontSize = 13.sp,
                modifier = Modifier.clip(CircleShape).clickable(onClick = onEdit).padding(horizontal = 8.dp, vertical = 4.dp),
            )
        }
        Spacer(Modifier.height(8.dp))
        content()
    }
}

@Composable
private fun ReviewEnd(title: String, end: MovingEnd, dot: Color) {
    Row(Modifier.padding(vertical = 4.dp), verticalAlignment = Alignment.Top) {
        Box(Modifier.padding(top = 5.dp).size(9.dp).clip(CircleShape).background(dot))
        Spacer(Modifier.width(10.dp))
        Column {
            Text(title, color = settingsMut(), fontSize = 11.sp)
            Text(end.point?.let { end.address.ifBlank { it.address } } ?: "—", color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
            val level = end.floor.trim().toIntOrNull()
            val details = listOfNotNull(
                end.area.takeIf { it.isNotBlank() },
                level?.let { if (it == 0) stringResource(R.string.mv_floor_ground) else stringResource(R.string.mv_floor_value, it.toString()) },
                stringResource(if (end.elevator) R.string.mv_has_elevator else R.string.mv_no_elevator).takeIf { end.point != null },
                end.entrance.takeIf { it.isNotBlank() },
            )
            if (details.isNotEmpty()) Text(details.joinToString(" · "), color = settingsMut(), fontSize = 12.sp)
        }
    }
}
