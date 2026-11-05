@file:OptIn(ExperimentalLayoutApi::class)

package com.dorr.app.ui.screens.moving

import android.content.Context
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddPhotoAlternate
import androidx.compose.material.icons.rounded.Build
import androidx.compose.material.icons.rounded.Chair
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Elevator
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.LocalShipping
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.PlayCircleFilled
import androidx.compose.material.icons.rounded.Store
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
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
import com.dorr.app.ui.screens.rides.ToggleLine
import com.dorr.app.ui.screens.rides.parseTime
import com.dorr.app.ui.screens.rides.rememberWhenLabels
import com.dorr.app.ui.screens.rides.whenText
import com.dorr.app.ui.theme.AppColors
import com.google.android.gms.maps.model.LatLng
import com.google.gson.JsonObject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import java.io.File
import java.time.ZonedDateTime
import java.util.Locale

/*
 * A new request (REQ-081), one draft kept on the server so a lost connection loses nothing: the first "next" creates
 * it (`POST requests`) and every step after it saves it (`PUT requests/{uuid}`). Steps: load type · description, pieces
 * and media · vehicle, workers and extras · pickup, drop-off and time · review and publish. Nothing is required before
 * the review: the server says what is missing (`data.missing`), and that is shown beside the step it belongs to.
 */

private const val STEP_TYPE = 0
private const val STEP_ITEMS = 1
private const val STEP_VEHICLE = 2
private const val STEP_PLACE = 3
private const val STEP_REVIEW = 4
private const val STEP_COUNT = 5

private val IMAGE_OR_VIDEO = setOf("image/jpeg", "image/png", "image/webp", "video/mp4", "video/quicktime")

/** Which step a field the server says is missing belongs to. */
private fun stepOfMissing(key: String): Int = when (key) {
    "load_type" -> STEP_TYPE
    "description_or_photo" -> STEP_ITEMS
    "vehicle_type_id" -> STEP_VEHICLE
    else -> STEP_PLACE
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
    var vehicleTypeId by mutableStateOf<Int?>(null)
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
    var missing by mutableStateOf<List<String>>(emptyList())

    fun fill(r: MovingRequestDto) {
        uuid = r.uuid
        loadType = r.loadType
        description = r.description.orEmpty()
        pieces = r.piecesCount?.toString().orEmpty()
        dimensions = r.dimensions.orEmpty()
        vehicleTypeId = r.vehicleTypeId
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
    }

    fun body(): JsonObject = JsonObject().apply {
        loadType?.let { addProperty("load_type", it) }
        addProperty("description", description.trim().take(3000))
        val count = pieces.trim().toIntOrNull()
        if (count != null) addProperty("pieces_count", count) else addProperty("pieces_count", "")
        addProperty("dimensions", dimensions.trim().take(255))
        vehicleTypeId?.let { addProperty("vehicle_type_id", it) }
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

@Composable
internal fun MovingWizard(initialUuid: String?, nav: MovingNav, atReview: Boolean = false) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val draft = remember { MovingDraft() }
    var step by remember { mutableIntStateOf(if (atReview) STEP_REVIEW else STEP_TYPE) }
    var forward by remember { mutableStateOf(true) }
    var loaded by remember { mutableStateOf(initialUuid == null) }
    var options by remember { mutableStateOf<MovingOptionsDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var picker by remember { mutableStateOf<String?>(null) }
    var timeOpen by remember { mutableStateOf(false) }
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

    /** Saves what is on the phone; false when it couldn't (and says why, the server's words first). */
    suspend fun save(): Boolean {
        val body = draft.body()
        val uuid = draft.uuid
        return runCatching {
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
                }
            }
            busy = false
        }
    }

    // Back steps through the wizard before it leaves it; what was saved stays in «طلباتي» as a draft.
    BackHandler(enabled = step > STEP_TYPE && picker == null) { go(step - 1) }

    val missingSteps = draft.missing.map { stepOfMissing(it) }.toSet()
    Box(Modifier.fillMaxSize()) {
        MvPage(
            title = stringResource(R.string.mv_new_title),
            onBack = { if (step > STEP_TYPE) go(step - 1) else nav.pop() },
            bottom = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    MvError(error)
                    if (step == STEP_REVIEW) {
                        AccentButton(stringResource(R.string.mv_publish), busy = busy, icon = Icons.Rounded.CheckCircle) { publish() }
                    } else {
                        AccentButton(stringResource(R.string.mv_next), enabled = step != STEP_TYPE || draft.loadType != null, busy = busy) { next() }
                    }
                }
            },
        ) {
            if (!loaded) {
                MvLoading()
                return@MvPage
            }
            Column(Modifier.fillMaxSize()) {
                StepBar(step, missingSteps) { target -> if (target <= step) go(target) }
                AnimatedContent(
                    targetState = step,
                    transitionSpec = { val side = if (forward) 1 else -1; (slideInHorizontally(tween(300)) { side * it / 4 } + fadeIn(tween(240))) togetherWith fadeOut(tween(160)) },
                    label = "mv-steps",
                    modifier = Modifier.weight(1f).fillMaxWidth(),
                ) { current ->
                    when (current) {
                        STEP_TYPE -> TypeStep(draft)
                        STEP_ITEMS -> ItemsStep(draft, options, scope = { block -> scope.launch { block() } }, context = context)
                        STEP_VEHICLE -> VehicleStep(draft, options)
                        STEP_PLACE -> PlaceStep(draft, onPick = { picker = it }, onTime = { timeOpen = true })
                        else -> ReviewStep(draft, options, onEdit = { go(it) })
                    }
                }
            }
        }

        // One end of the move: over the wizard. Picking it saves the draft, so a lost connection keeps it too.
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
                    picker = null
                    scope.launch {
                        val latLng: LatLng = place.latLng
                        val found = areaOf(context, latLng)
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

/** The five steps as a bar; a step the server says is missing something turns red. */
@Composable
private fun StepBar(step: Int, missing: Set<Int>, onTap: (Int) -> Unit) {
    Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp).padding(bottom = 10.dp)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            repeat(STEP_COUNT) { i ->
                val color by animateColorAsState(
                    when {
                        i in missing -> AppColors.danger
                        i <= step -> settingsAccent()
                        else -> settingsInk().copy(alpha = 0.12f)
                    },
                    tween(240), label = "mv-step",
                )
                Box(Modifier.weight(1f).height(5.dp).clip(CircleShape).background(color).clickable { onTap(i) })
            }
        }
        Spacer(Modifier.height(6.dp))
        Text(
            stringResource(
                R.string.mv_step_of, step + 1, STEP_COUNT,
                stringResource(
                    when (step) {
                        STEP_TYPE -> R.string.mv_step_type
                        STEP_ITEMS -> R.string.mv_step_items
                        STEP_VEHICLE -> R.string.mv_step_vehicle
                        STEP_PLACE -> R.string.mv_step_place
                        else -> R.string.mv_step_review
                    },
                ),
            ),
            color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
        )
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

// ---------------------------------------------------------------- 1. the load

@Composable
private fun TypeStep(draft: MovingDraft) {
    MvScroll {
        Text(stringResource(R.string.mv_type_title), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 22.sp)
        MissingNote(draft, "load_type")
        TypeTile(Icons.Rounded.Chair, stringResource(R.string.mv_load_single), stringResource(R.string.mv_load_single_hint), draft.loadType == "single_piece") { draft.loadType = "single_piece" }
        TypeTile(Icons.Rounded.Home, stringResource(R.string.mv_load_home), stringResource(R.string.mv_load_home_hint), draft.loadType == "home") { draft.loadType = "home" }
        TypeTile(Icons.Rounded.Store, stringResource(R.string.mv_load_commercial), stringResource(R.string.mv_load_commercial_hint), draft.loadType == "commercial") { draft.loadType = "commercial" }
    }
}

/** A choice as a card: a soft wash and a border when it is the chosen one, with a small pop (the rides speed tiles). */
@Composable
private fun TypeTile(icon: ImageVector, title: String, hint: String, on: Boolean, onClick: () -> Unit) {
    val tint = settingsAccent()
    val bg by animateColorAsState(if (on) tint.copy(alpha = 0.12f) else mvCardColor(), tween(200), label = "mv-tile")
    val pop by animateFloatAsState(if (on) 1.02f else 1f, spring(dampingRatio = 0.5f), label = "mv-tile-pop")
    Row(
        Modifier.fillMaxWidth().scale(pop).clip(RoundedCornerShape(20.dp)).background(bg).border(1.5.dp, if (on) tint else Color.Transparent, RoundedCornerShape(20.dp))
            .clickable(onClick = onClick).padding(16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(52.dp).clip(RoundedCornerShape(16.dp)).background(tint.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = tint, modifier = Modifier.size(28.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 16.sp)
            Text(hint, color = settingsMut(), fontSize = 12.5.sp)
        }
        if (on) Icon(Icons.Rounded.CheckCircle, null, tint = tint, modifier = Modifier.size(22.dp))
    }
}

// ---------------------------------------------------------------- 2. what is moved, with photos

@Composable
private fun ItemsStep(draft: MovingDraft, options: MovingOptionsDto?, scope: (suspend () -> Unit) -> Unit, context: Context) {
    var uploading by remember { mutableIntStateOf(0) }
    var warning by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)
    val maxMb = options?.mediaMaxMb
    val tooBig = maxMb?.let { stringResource(R.string.mv_media_too_big, it.megabytes()) }
    val wrongType = stringResource(R.string.mv_media_type)

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
        MvCard {
            MvCardTitle(stringResource(R.string.mv_items_title))
            Spacer(Modifier.height(10.dp))
            DorrTextField(
                value = draft.description,
                onValueChange = { draft.description = it.take(3000); draft.missing = draft.missing.filterNot { k -> k == "description_or_photo" } },
                label = stringResource(R.string.mv_description), placeholder = stringResource(R.string.mv_description_hint),
                singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth(),
            )
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
        MvCard {
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
                    Modifier.size(92.dp).clip(tile).background(settingsBackground()).border(1.5.dp, settingsInk().copy(alpha = 0.12f), tile)
                        .clickable { pick.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo)) },
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
        MvCard {
            MvCardTitle(stringResource(R.string.mv_vehicle_title))
            MissingNote(draft, "vehicle_type_id")
            Spacer(Modifier.height(10.dp))
            val types = options?.vehicleTypes.orEmpty()
            if (options != null && types.isEmpty()) Text(stringResource(R.string.mv_no_vehicle_types), color = settingsMut(), fontSize = 13.sp)
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                types.forEach { type ->
                    val on = draft.vehicleTypeId == type.id
                    val tint = settingsAccent()
                    val bg by animateColorAsState(if (on) tint.copy(alpha = 0.10f) else settingsBackground(), tween(200), label = "mv-vehicle")
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(bg).border(1.5.dp, if (on) tint else Color.Transparent, RoundedCornerShape(16.dp))
                            .clickable { draft.vehicleTypeId = type.id; draft.missing = draft.missing.filterNot { it == "vehicle_type_id" } }.padding(10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Box(Modifier.size(64.dp).clip(RoundedCornerShape(12.dp)).background(tint.copy(alpha = 0.08f)), contentAlignment = Alignment.Center) {
                            if (type.image != null) AsyncImage(ApiClient.mediaUrl(type.image), null, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize().padding(4.dp))
                            else Icon(Icons.Rounded.LocalShipping, null, tint = tint, modifier = Modifier.size(30.dp))
                        }
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(type.name.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold)
                            type.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
                        }
                        if (on) Icon(Icons.Rounded.CheckCircle, null, tint = tint, modifier = Modifier.size(22.dp))
                    }
                }
            }
        }
        MvCard {
            StepperLine(Icons.Rounded.Groups, stringResource(R.string.mv_workers), stringResource(R.string.mv_workers_hint), draft.workers, 0..50) { draft.workers = it }
        }
        MvCard {
            MvCardTitle(stringResource(R.string.mv_extras_title))
            Spacer(Modifier.height(4.dp))
            ToggleLine(Icons.Rounded.Inventory2, stringResource(R.string.mv_extra_packing), null, draft.packing) { draft.packing = it }
            ToggleLine(Icons.Rounded.Build, stringResource(R.string.mv_extra_disassembly), null, draft.disassembly) { draft.disassembly = it }
            ToggleLine(Icons.Rounded.LocalShipping, stringResource(R.string.mv_extra_crane), null, draft.crane) { draft.crane = it }
            ToggleLine(Icons.Rounded.Build, stringResource(R.string.mv_extra_technician), null, draft.technician) { draft.technician = it }
        }
    }
}

// ---------------------------------------------------------------- 4. the place and the time

@Composable
private fun PlaceStep(draft: MovingDraft, onPick: (String) -> Unit, onTime: () -> Unit) {
    val (today, tomorrow) = rememberWhenLabels()
    MvScroll {
        EndCard(draft, "pickup", draft.pickup, stringResource(R.string.mv_pickup), AppColors.brandNavy, onPick)
        EndCard(draft, "dropoff", draft.dropoff, stringResource(R.string.mv_dropoff), AppColors.brandOrange, onPick)
        MvCard {
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable(onClick = onTime).padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(34.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Event, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                }
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.mv_when), color = settingsMut(), fontSize = 11.sp)
                    val at = draft.scheduledAt
                    Text(at?.let { whenText(it, today, tomorrow) } ?: stringResource(R.string.mv_pick_time), color = if (at == null) settingsMut() else settingsInk(), fontWeight = FontWeight.SemiBold)
                }
                Icon(Icons.Rounded.Edit, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
            }
            MissingNote(draft, "scheduled_at")
            draft.scheduledAt?.takeIf { !it.isAfter(ZonedDateTime.now()) }?.let { Text(stringResource(R.string.mv_time_passed), color = AppColors.danger, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold) }
        }
        MvCard {
            DorrTextField(
                value = draft.notes, onValueChange = { draft.notes = it.take(2000) },
                label = stringResource(R.string.mv_notes), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth(),
            )
        }
    }
}

@Composable
private fun EndCard(draft: MovingDraft, key: String, end: MovingEnd, title: String, dot: Color, onPick: (String) -> Unit) {
    MvCard {
        Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { onPick(key) }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(34.dp).clip(CircleShape).background(dot.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Place, null, tint = dot, modifier = Modifier.size(18.dp))
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(title, color = settingsMut(), fontSize = 11.sp)
                Text(
                    end.point?.let { end.address.ifBlank { it.address } } ?: stringResource(R.string.mv_pick_place),
                    color = if (end.point == null) settingsMut() else settingsInk(), fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis,
                )
            }
            Icon(Icons.Rounded.Edit, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        }
        MissingNote(draft, key)
        if (end.point != null) {
            Spacer(Modifier.height(8.dp))
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                DorrTextField(
                    value = end.area, onValueChange = { end.area = it.take(120) },
                    label = stringResource(R.string.mv_area), placeholder = stringResource(R.string.mv_area_hint), modifier = Modifier.fillMaxWidth(),
                )
                DorrTextField(value = end.address, onValueChange = { end.address = it.take(255) }, label = stringResource(R.string.mv_address), modifier = Modifier.fillMaxWidth())
                DorrTextField(
                    value = end.entrance, onValueChange = { end.entrance = it.take(255) },
                    label = stringResource(R.string.mv_entrance), placeholder = stringResource(R.string.mv_entrance_hint), modifier = Modifier.fillMaxWidth(),
                )
                DorrTextField(
                    value = end.floor,
                    onValueChange = { typed -> end.floor = typed.filterIndexed { i, c -> c.isDigit() || (i == 0 && c == '-') }.take(4) },
                    label = stringResource(R.string.mv_floor), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth(),
                )
                ToggleLine(Icons.Rounded.Elevator, stringResource(R.string.mv_elevator), null, end.elevator) { end.elevator = it }
            }
        }
    }
}

// ---------------------------------------------------------------- 5. the review

@Composable
private fun ReviewStep(draft: MovingDraft, options: MovingOptionsDto?, onEdit: (Int) -> Unit) {
    val (today, tomorrow) = rememberWhenLabels()
    MvScroll {
        Text(stringResource(R.string.mv_review_title), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 22.sp)
        Text(stringResource(R.string.mv_review_hint), color = settingsMut(), fontSize = 13.sp)
        ReviewCard(stringResource(R.string.mv_step_type), onEdit = { onEdit(STEP_TYPE) }) {
            Text(draft.loadType?.let { loadTypeLabel(it) } ?: "—", color = settingsInk(), fontWeight = FontWeight.SemiBold)
            MissingNote(draft, "load_type")
        }
        ReviewCard(stringResource(R.string.mv_step_items), onEdit = { onEdit(STEP_ITEMS) }) {
            if (draft.description.isNotBlank()) Text(draft.description.trim(), color = settingsInk(), fontSize = 14.sp)
            if (draft.pieces.isNotBlank()) MvLine(stringResource(R.string.mv_pieces), draft.pieces)
            if (draft.dimensions.isNotBlank()) MvLine(stringResource(R.string.mv_dimensions), draft.dimensions)
            MvLine(stringResource(R.string.mv_media_title), stringResource(R.string.mv_media_counts, draft.photos.size, draft.videos.size))
            MissingNote(draft, "description_or_photo")
        }
        ReviewCard(stringResource(R.string.mv_step_vehicle), onEdit = { onEdit(STEP_VEHICLE) }) {
            val type = options?.vehicleTypes?.firstOrNull { it.id == draft.vehicleTypeId }
            MvLine(stringResource(R.string.mv_vehicle_title), type?.name ?: "—")
            MvLine(stringResource(R.string.mv_workers), draft.workers.toString())
            val extras = listOfNotNull(
                stringResource(R.string.mv_extra_packing).takeIf { draft.packing },
                stringResource(R.string.mv_extra_disassembly).takeIf { draft.disassembly },
                stringResource(R.string.mv_extra_crane).takeIf { draft.crane },
                stringResource(R.string.mv_extra_technician).takeIf { draft.technician },
            )
            if (extras.isNotEmpty()) Text(extras.joinToString(" · "), color = settingsInk(), fontSize = 13.sp)
            MissingNote(draft, "vehicle_type_id")
        }
        ReviewCard(stringResource(R.string.mv_step_place), onEdit = { onEdit(STEP_PLACE) }) {
            ReviewEnd(stringResource(R.string.mv_pickup), draft.pickup)
            MissingNote(draft, "pickup")
            ReviewEnd(stringResource(R.string.mv_dropoff), draft.dropoff)
            MissingNote(draft, "dropoff")
            MvLine(stringResource(R.string.mv_when), draft.scheduledAt?.let { whenText(it, today, tomorrow) } ?: "—")
            MissingNote(draft, "scheduled_at")
        }
    }
}

@Composable
private fun ReviewCard(title: String, onEdit: () -> Unit, content: @Composable ColumnScope.() -> Unit) {
    MvCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            MvCardTitle(title, Modifier.weight(1f))
            Text(
                stringResource(R.string.mv_edit), color = settingsAccent(), fontWeight = FontWeight.Bold, fontSize = 13.sp,
                modifier = Modifier.clip(CircleShape).clickable(onClick = onEdit).padding(horizontal = 8.dp, vertical = 4.dp),
            )
        }
        Spacer(Modifier.height(6.dp))
        content()
    }
}

@Composable
private fun ReviewEnd(title: String, end: MovingEnd) {
    Column(Modifier.padding(vertical = 3.dp)) {
        Text(title, color = settingsMut(), fontSize = 11.sp)
        Text(end.point?.let { end.address.ifBlank { it.address } } ?: "—", color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
        val details = listOfNotNull(
            end.area.takeIf { it.isNotBlank() },
            end.floor.takeIf { it.isNotBlank() }?.let { stringResource(R.string.mv_floor_value, it) },
            stringResource(if (end.elevator) R.string.mv_has_elevator else R.string.mv_no_elevator).takeIf { end.point != null },
        )
        if (details.isNotEmpty()) Text(details.joinToString(" · "), color = settingsMut(), fontSize = 12.sp)
    }
}
