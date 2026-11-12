package com.dorr.provider.ui.screens

import android.Manifest
import android.content.pm.PackageManager
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.animateContentSize
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddAPhoto
import androidx.compose.material.icons.rounded.Autorenew
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.CloudUpload
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.EditNote
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.EventBusy
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.PhotoCamera
import androidx.compose.material.icons.rounded.PhotoLibrary
import androidx.compose.material.icons.rounded.PictureAsPdf
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.Update
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.composed
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import coil.compose.AsyncImage
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.AppLocale
import com.dorr.provider.net.DocumentTypeDto
import com.dorr.provider.net.DocumentsDto
import com.dorr.provider.net.EstablishmentDto
import com.dorr.provider.net.ProviderDocumentDto
import com.dorr.provider.net.Realtime
import com.dorr.provider.net.Session
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.ui.components.DateField
import com.dorr.provider.ui.components.DorrTextField
import com.dorr.provider.ui.components.EmptyState
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.components.FormIntro
import com.dorr.provider.ui.components.SkeletonList
import com.dorr.provider.ui.kit.AccountDark
import com.dorr.provider.ui.kit.PinkBackdrop
import com.dorr.provider.ui.kit.SettingsPrimaryButton
import com.dorr.provider.ui.kit.SettingsScreenTitle
import com.dorr.provider.ui.kit.SubHeader
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsCard
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import com.dorr.provider.ui.kit.settingsNight
import com.dorr.provider.ui.kit.settingsSurface
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import okio.BufferedSink
import java.io.File
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.time.temporal.ChronoUnit

/** The server takes up to 10MB a side (DocumentUploadRequest); a scaled photo is far smaller. */
private const val MAX_DOC_BYTES = 10L * 1024 * 1024

/** An approved paper that ends within this many days shows as "expires soon". */
private const val EXPIRING_DAYS = 30L

/** The orange of "expires soon" (between the amber of "missing" and the red of "expired"). */
private val Orange = Color(0xFFEA580C)

/**
 * The establishment's papers (C-02, A-06): what is required for its country and services, the state of each upload as a badge,
 * how many are ready, uploading one (front and back from the camera or the gallery, its number and expiry when the type asks for
 * them), and sending the establishment for review. Read once when the page opens, when the connection returns and when a live
 * notice arrives (a paper reviewed); an upload and the submit each answer with one request. Only the owner uploads the
 * establishment's papers (the server checks it too). With [driverUuid], one driver's licence (C-03) instead.
 */
@Composable
fun DocumentsScreen(onBack: () -> Unit, driverUuid: String? = null) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val seat by Session.membership.collectAsState()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val submittedOk = stringResource(R.string.pd_submitted_ok)

    var docs by remember { mutableStateOf<DocumentsDto?>(null) }
    var establishment by remember { mutableStateOf<EstablishmentDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var info by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    // null = the list; else the type being uploaded (kept across the app being killed while a picker or the camera is open).
    var formTypeId by rememberSaveable { mutableStateOf<Int?>(null) }

    suspend fun reload() {
        val result = apiCall { ApiClient.workspace.documents(driverUuid) }
        if (result is ApiResult.Ok) {
            docs = result.data
            error = null
        } else {
            error = result.errorText(offlineText) ?: genericError
        }
        (apiCall { ApiClient.workspace.establishment() } as? ApiResult.Ok)?.data?.let {
            establishment = it.establishment
            Session.setMembership(it.membership)
        }
    }

    LaunchedEffect(reconnect) { reload() }
    LaunchedEffect(Unit) { Realtime.changed.collect { reload() } }

    val current = docs
    val typeInForm = formTypeId?.let { id -> current?.required?.firstOrNull { it.id == id } }
    if (formTypeId != null && typeInForm != null) {
        BackHandler { formTypeId = null }
        DocumentForm(
            type = typeInForm,
            subject = if (driverUuid != null) "driver" else "establishment",
            subjectUuid = driverUuid,
            existing = current?.documents?.firstOrNull { it.documentTypeId == typeInForm.id },
            onBack = { formTypeId = null },
            onUploaded = {
                scope.launch {
                    reload()
                    formTypeId = null
                }
            },
        )
        return
    }

    fun submit() {
        busy = true
        error = null
        info = null
        scope.launch {
            val result = apiCall { ApiClient.workspace.submit() }
            busy = false
            if (result is ApiResult.Ok) {
                establishment = result.data
                info = submittedOk
            } else {
                // `provider_documents_missing` comes with the server's own sentence ("complete the papers first").
                error = result.errorText(offlineText) ?: genericError
            }
            reload()
        }
    }

    val isOwner = seat?.isOwner == true
    // A driver's licence (C-03): the driver themself or someone with fleet.drivers uploads it; the establishment's own status
    // and review are not part of that page.
    val forDriver = driverUuid != null
    val status = if (forDriver) null else establishment?.status
    // Papers can change until the review starts, and again after a refusal; an approved establishment may replace one.
    val editable = if (forDriver) true else isOwner && status != "under_review"
    val canSubmit = current != null && !forDriver && isOwner && (status == "draft" || status == "rejected")

    PaperScaffold(
        title = stringResource(if (forDriver) R.string.drv_docs_title else R.string.pd_title),
        onBack = onBack,
        bottom = if (canSubmit && current != null) {
            {
                AnimatedVisibility(current.missing.isNotEmpty(), enter = fadeIn() + expandVertically(), exit = fadeOut() + shrinkVertically()) {
                    Text(stringResource(R.string.pp_submit_need), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp, modifier = Modifier.fillMaxWidth())
                }
                SettingsPrimaryButton(text = stringResource(R.string.pd_submit), enabled = current.missing.isEmpty(), loading = busy, onClick = ::submit)
            }
        } else null,
    ) {
        if (current == null) {
            if (error == null) SkeletonList(4, Modifier.fillMaxWidth())
        } else {
            if (status != null) {
                EstablishmentStatusCard(status, establishment?.statusReason, Modifier.paperStagger(0))
            } else {
                Box(Modifier.paperStagger(0)) {
                    FormIntro(Icons.Rounded.Badge, stringResource(R.string.pd_intro_title), stringResource(R.string.drv_docs_intro))
                }
            }
            if (!forDriver && !isOwner) PaperNote(stringResource(R.string.pd_owner_only), Slate, Icons.Rounded.Info, Modifier.paperStagger(1))
            if (current.required.isEmpty()) {
                EmptyState(Icons.Rounded.TaskAlt, stringResource(R.string.pd_intro_title), stringResource(R.string.pd_none_required), Modifier.padding(top = 24.dp))
            } else {
                PaperProgressCard(current.required, current.documents, current.missing, Modifier.paperStagger(1))
            }
            current.required.forEachIndexed { i, type ->
                val doc = current.documents.firstOrNull { it.documentTypeId == type.id }
                PaperCard(type, doc, missing = type.code in current.missing, editable = editable, busy = busy, modifier = Modifier.paperStagger(i + 2)) { formTypeId = type.id }
            }
        }
        info?.let { PaperNote(it, Green, Icons.Rounded.CheckCircle) }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/** The establishment's review state on top of its papers: an icon that breathes while under review, the state and what to do next. */
@Composable
private fun EstablishmentStatusCard(status: String, reason: String?, modifier: Modifier = Modifier) {
    val color = establishmentStatusColor(status)
    val icon = when (status) {
        "approved" -> Icons.Rounded.Verified
        "under_review" -> Icons.Rounded.HourglassTop
        "rejected", "suspended" -> Icons.Rounded.ErrorOutline
        else -> Icons.Rounded.EditNote
    }
    val pulse = rememberInfiniteTransition(label = "status")
    val scale by pulse.animateFloat(1f, if (status == "under_review") 1.08f else 1f, infiniteRepeatable(tween(1100), RepeatMode.Reverse), label = "status-scale")
    val hint = when (status) {
        "under_review" -> stringResource(R.string.pd_under_review)
        "approved" -> stringResource(R.string.pp_status_approved_hint)
        "rejected", "suspended" -> reason?.takeIf { it.isNotBlank() }?.let { stringResource(R.string.pd_reason, it) } ?: stringResource(R.string.pp_status_rejected_hint)
        else -> stringResource(R.string.pd_intro_body)
    }
    Row(
        modifier.fillMaxWidth().widthIn(max = 440.dp).settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        Box(
            Modifier.size(54.dp).graphicsLayer { scaleX = scale; scaleY = scale }.clip(CircleShape).background(color.copy(alpha = 0.14f)),
            contentAlignment = Alignment.Center,
        ) { Icon(icon, contentDescription = null, tint = color, modifier = Modifier.size(28.dp)) }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(establishmentStatusLabel(status), color = color, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
            Text(hint, color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
        }
    }
}

// ---------------------------------------------------------------- the papers kit (shared with the vehicle pages)

/** A paper's state, from the server's status and the `missing` list. */
internal enum class PaperState { Missing, Pending, Approved, Expiring, Rejected, Expired }

internal fun paperState(doc: ProviderDocumentDto?, missing: Boolean): PaperState = when {
    doc == null -> PaperState.Missing
    doc.status == "rejected" -> PaperState.Rejected
    doc.status == "expired" -> PaperState.Expired
    doc.status == "pending" -> PaperState.Pending
    // Approved but no longer counted (its date passed since): it has to be renewed.
    doc.status == "approved" && missing -> PaperState.Expired
    doc.status == "approved" && expiresSoon(doc.expiresOn) -> PaperState.Expiring
    doc.status == "approved" -> PaperState.Approved
    missing -> PaperState.Missing
    else -> PaperState.Pending
}

/** Whether a state needs the provider to do something (upload or upload again). */
internal fun PaperState.needsAction(): Boolean = this == PaperState.Missing || this == PaperState.Rejected || this == PaperState.Expired

private fun expiresSoon(date: String?): Boolean {
    val day = runCatching { LocalDate.parse(date ?: return false) }.getOrNull() ?: return false
    val today = LocalDate.now()
    return !day.isBefore(today) && ChronoUnit.DAYS.between(today, day) <= EXPIRING_DAYS
}

/** `YYYY-MM-DD` strictly after today (the server's `after:today` on `expires_on`). */
internal fun isAfterToday(date: String?): Boolean =
    runCatching { LocalDate.parse(date ?: return false).isAfter(LocalDate.now()) }.getOrDefault(false)

/** "5 Jan 2027" / "٥ يناير ٢٠٢٧"-style in the app language (Latin digits); the raw text if it isn't a date. */
internal fun paperDate(date: String?): String {
    if (date.isNullOrBlank()) return ""
    return runCatching { DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(AppLocale.java()).format(LocalDate.parse(date)) }.getOrDefault(date)
}

/** A state in words, colour and icon (never colour alone). */
internal data class PaperLook(val label: String, val color: Color, val icon: ImageVector)

@Composable
internal fun paperLook(state: PaperState): PaperLook = when (state) {
    PaperState.Missing -> PaperLook(stringResource(R.string.pd_state_missing), Amber, Icons.Rounded.CloudUpload)
    PaperState.Pending -> PaperLook(stringResource(R.string.pd_state_pending), Blue, Icons.Rounded.HourglassTop)
    PaperState.Approved -> PaperLook(stringResource(R.string.pd_state_approved), Green, Icons.Rounded.CheckCircle)
    PaperState.Expiring -> PaperLook(stringResource(R.string.pp_state_expiring), Orange, Icons.Rounded.Update)
    PaperState.Rejected -> PaperLook(stringResource(R.string.pd_state_rejected), Red, Icons.Rounded.ErrorOutline)
    PaperState.Expired -> PaperLook(stringResource(R.string.pd_state_expired), Red, Icons.Rounded.EventBusy)
}

/** The state badge: icon + word on a soft pill of its colour; it fades to the new state when the state changes. [solid] sits on a photo. */
@Composable
internal fun PaperStateBadge(state: PaperState, modifier: Modifier = Modifier, solid: Boolean = false) {
    val look = paperLook(state)
    val color by animateColorAsState(look.color, tween(300), label = "badge-color")
    Box(
        modifier.clip(RoundedCornerShape(50)).background(if (solid) Color.White.copy(alpha = 0.94f) else color.copy(alpha = 0.12f)).animateContentSize(),
    ) {
        AnimatedContent(targetState = state, transitionSpec = { fadeIn(tween(220)) togetherWith fadeOut(tween(120)) }, label = "badge") { shown ->
            val l = paperLook(shown)
            Row(Modifier.padding(horizontal = 8.dp, vertical = 3.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                Icon(l.icon, contentDescription = null, tint = color, modifier = Modifier.size(13.dp))
                Text(l.label, color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1)
            }
        }
    }
}

/** "3 of 5 ready": a ring and a bar that fill as papers are uploaded, and one badge per state count. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun PaperProgressCard(required: List<DocumentTypeDto>, documents: List<ProviderDocumentDto>, missing: List<String>, modifier: Modifier = Modifier) {
    val total = required.size
    val states = required.map { type -> paperState(documents.firstOrNull { it.documentTypeId == type.id }, type.code in missing) }
    val ready = required.count { it.code !in missing }
    val approved = states.count { it == PaperState.Approved || it == PaperState.Expiring }
    val pending = states.count { it == PaperState.Pending }
    val action = states.count { it.needsAction() }
    val done = total > 0 && ready == total
    val shown by animateFloatAsState(if (total == 0) 1f else ready / total.toFloat(), tween(700, easing = FastOutSlowInEasing), label = "papers-progress")
    val tint by animateColorAsState(if (done) Green else settingsAccent(), tween(300), label = "papers-tint")
    val track = if (settingsNight()) AccountDark.well else Color(0xFFF1F2F4)
    Column(
        modifier.fillMaxWidth().widthIn(max = 440.dp).settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            Box(Modifier.size(58.dp), contentAlignment = Alignment.Center) {
                CircularProgressIndicator(progress = { shown }, color = tint, trackColor = track, strokeWidth = 5.dp, modifier = Modifier.fillMaxSize())
                if (done) Icon(Icons.Rounded.Check, contentDescription = null, tint = Green, modifier = Modifier.size(26.dp))
                else Text("$ready/$total", color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(
                    if (done) stringResource(R.string.pp_progress_all) else stringResource(R.string.pp_progress_title, ready, total),
                    color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold,
                )
                Text(
                    stringResource(if (done) R.string.pp_progress_done_hint else R.string.pp_progress_hint),
                    color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp,
                )
            }
        }
        LinearProgressIndicator(progress = { shown }, color = tint, trackColor = track, modifier = Modifier.fillMaxWidth().height(6.dp).clip(CircleShape))
        FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            if (approved > 0) CountBadge(Icons.Rounded.CheckCircle, stringResource(R.string.pp_count_approved, approved), Green)
            if (pending > 0) CountBadge(Icons.Rounded.HourglassTop, stringResource(R.string.pp_count_pending, pending), Blue)
            if (action > 0) CountBadge(Icons.Rounded.CloudUpload, stringResource(R.string.pp_count_action, action), Amber)
        }
    }
}

@Composable
private fun CountBadge(icon: ImageVector, text: String, color: Color) {
    Row(
        Modifier.clip(RoundedCornerShape(50)).background(color.copy(alpha = 0.10f)).padding(horizontal = 10.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp),
    ) {
        Icon(icon, contentDescription = null, tint = color, modifier = Modifier.size(14.dp))
        Text(text, color = color, fontSize = 12.sp, fontWeight = FontWeight.Bold)
    }
}

/**
 * One required paper as a card: an icon tile in its state's colour, the name and the state badge, the number and the date it is
 * valid until, the refusal reason, the sides already sent, and what tapping does (upload, upload again or replace).
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun PaperCard(
    type: DocumentTypeDto,
    doc: ProviderDocumentDto?,
    missing: Boolean,
    editable: Boolean,
    busy: Boolean,
    modifier: Modifier = Modifier,
    onOpen: () -> Unit,
) {
    val state = paperState(doc, missing)
    val look = paperLook(state)
    val tileColor by animateColorAsState(look.color, tween(300), label = "paper-tile")
    val shape = RoundedCornerShape(20.dp)
    val ring by animateColorAsState(
        if (state == PaperState.Rejected || state == PaperState.Expired) Red.copy(alpha = 0.45f) else Color.Transparent, tween(300), label = "paper-ring",
    )
    Column(
        modifier.fillMaxWidth().widthIn(max = 440.dp).paperPress(enabled = editable && !busy, onClick = onOpen)
            .settingsSurface(shape).border(1.5.dp, ring, shape).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Box(Modifier.size(46.dp).clip(RoundedCornerShape(14.dp)).background(tileColor.copy(alpha = 0.13f)), contentAlignment = Alignment.Center) {
                Icon(if (type.kind == "photo") Icons.Rounded.PhotoCamera else Icons.Rounded.Description, contentDescription = null, tint = tileColor, modifier = Modifier.size(23.dp))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(type.name, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                PaperStateBadge(state)
            }
            if (editable) Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(20.dp))
        }
        if (state == PaperState.Missing) type.instructions?.takeIf { it.isNotBlank() }?.let {
            Text(it, color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
        }
        if (doc != null && (!doc.number.isNullOrBlank() || !doc.expiresOn.isNullOrBlank())) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                doc.number?.takeIf { it.isNotBlank() }?.let { MetaChip(Icons.Rounded.Badge, stringResource(R.string.pd_number_line, it), settingsMut()) }
                doc.expiresOn?.takeIf { it.isNotBlank() }?.let {
                    MetaChip(Icons.Rounded.Event, stringResource(R.string.pp_until, paperDate(it)), if (state == PaperState.Expiring || state == PaperState.Expired) look.color else settingsMut())
                }
            }
        }
        doc?.takeIf { it.status == "rejected" }?.rejectionReason?.takeIf { it.isNotBlank() }?.let {
            PaperNote(stringResource(R.string.pd_reason, it), Red, Icons.Rounded.ErrorOutline)
        }
        val shots = listOfNotNull(doc?.front, doc?.back)
        if (shots.isNotEmpty()) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) { shots.forEach { PaperThumb(it) } }
        }
        if (editable) {
            val (icon, label) = when {
                state == PaperState.Missing -> Icons.Rounded.CloudUpload to stringResource(R.string.pp_upload_now)
                state.needsAction() -> Icons.Rounded.Autorenew to stringResource(R.string.pp_reupload)
                else -> Icons.Rounded.Autorenew to stringResource(R.string.pd_replace)
            }
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(17.dp))
                Text(label, color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}

@Composable
private fun MetaChip(icon: ImageVector, text: String, color: Color) {
    val night = settingsNight()
    Row(
        Modifier.clip(RoundedCornerShape(10.dp)).background(if (night) AccountDark.well else Color(0xFFF5F6F8)).padding(horizontal = 8.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp),
    ) {
        Icon(icon, contentDescription = null, tint = color, modifier = Modifier.size(13.dp))
        Text(text, color = color, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
    }
}

/** A side already sent: its picture (a PDF shows its icon). */
@Composable
private fun PaperThumb(url: String) {
    val night = settingsNight()
    Box(
        Modifier.size(width = 86.dp, height = 58.dp).clip(RoundedCornerShape(10.dp)).background(if (night) AccountDark.well else Color(0xFFF1F2F4)),
        contentAlignment = Alignment.Center,
    ) {
        if (url.substringBefore('?').endsWith(".pdf", ignoreCase = true)) {
            Icon(Icons.Rounded.PictureAsPdf, contentDescription = null, tint = Red, modifier = Modifier.size(26.dp))
        } else {
            Icon(Icons.Rounded.Description, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(20.dp))
            AsyncImage(model = ApiClient.mediaUrl(url), contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
        }
    }
}

/** A soft note in a colour: an icon and a line (a hint, a warning, a refusal reason). */
@Composable
internal fun PaperNote(text: String, color: Color, icon: ImageVector, modifier: Modifier = Modifier) {
    Row(
        modifier.fillMaxWidth().widthIn(max = 440.dp).clip(RoundedCornerShape(14.dp)).background(color.copy(alpha = 0.10f)).padding(12.dp),
        verticalAlignment = Alignment.Top,
    ) {
        Icon(icon, contentDescription = null, tint = color, modifier = Modifier.size(19.dp))
        Spacer(Modifier.width(8.dp))
        Text(text, color = settingsInk(), fontSize = 12.sp, lineHeight = 18.sp, fontWeight = FontWeight.SemiBold)
    }
}

/** A section title inside a form: the app colour's small bar and the words. */
@Composable
internal fun PaperSectionTitle(text: String, modifier: Modifier = Modifier) {
    Row(modifier.fillMaxWidth().widthIn(max = 440.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Box(Modifier.size(width = 4.dp, height = 16.dp).clip(RoundedCornerShape(2.dp)).background(settingsAccent()))
        Text(text, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
    }
}

/**
 * A page of the papers screens: [Page]'s backdrop and header (no back button when [onBack] is null), the scrolling content, and an
 * optional [bottom] bar fixed under it for the page's main action.
 */
@Composable
internal fun PaperScaffold(
    title: String,
    onBack: (() -> Unit)?,
    bottom: (@Composable ColumnScope.() -> Unit)? = null,
    content: @Composable ColumnScope.() -> Unit,
) {
    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding().imePadding()) {
            if (onBack != null) {
                SubHeader(title = title, onBack = onBack)
            } else {
                Box(Modifier.fillMaxWidth().padding(horizontal = 14.dp).padding(top = 14.dp, bottom = 12.dp)) { SettingsScreenTitle(title) }
            }
            Column(
                Modifier.weight(1f).fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(top = 2.dp, bottom = 24.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) { content() }
            if (bottom != null) {
                val night = settingsNight()
                val shape = RoundedCornerShape(topStart = 24.dp, topEnd = 24.dp)
                Column(
                    Modifier.fillMaxWidth()
                        .then(if (night) Modifier else Modifier.shadow(18.dp, shape, ambientColor = Color(0x14001B53), spotColor = Color(0x14001B53)))
                        .clip(shape).background(settingsCard())
                        .then(if (night) Modifier.border(1.dp, AccountDark.line, shape) else Modifier)
                        .padding(horizontal = 18.dp).padding(top = 14.dp, bottom = 12.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                ) { bottom() }
            }
        }
    }
}

/** Rises and fades in the first time it is shown, one after another by [index] (the captain app's `staggerIn`). */
internal fun Modifier.paperStagger(index: Int = 0): Modifier = composed {
    val progress = remember { Animatable(0f) }
    LaunchedEffect(Unit) {
        delay(index.coerceAtMost(8) * 45L)
        progress.animateTo(1f, tween(380, easing = FastOutSlowInEasing))
    }
    this.graphicsLayer {
        alpha = progress.value
        translationY = (1f - progress.value) * 22.dp.toPx()
    }
}

/** A tap that answers with a small press-in instead of a flat ripple (the captain app's `bounceClick`). */
internal fun Modifier.paperPress(enabled: Boolean = true, onClick: () -> Unit): Modifier = composed {
    val source = remember { MutableInteractionSource() }
    val pressed by source.collectIsPressedAsState()
    val scale by animateFloatAsState(if (pressed) 0.97f else 1f, spring(stiffness = Spring.StiffnessMedium), label = "press")
    this.graphicsLayer { scaleX = scale; scaleY = scale }
        .clickable(interactionSource = source, indication = null, enabled = enabled, onClick = onClick)
}

/** A dashed rounded outline (an empty photo slot). */
internal fun Modifier.dashedOutline(color: Color, radius: Dp, width: Dp = 1.5.dp): Modifier = drawBehind {
    val stroke = width.toPx()
    drawRoundRect(
        color = color,
        topLeft = Offset(stroke / 2, stroke / 2),
        size = Size(size.width - stroke, size.height - stroke),
        cornerRadius = CornerRadius(radius.toPx()),
        style = Stroke(width = stroke, pathEffect = PathEffect.dashPathEffect(floatArrayOf(10.dp.toPx(), 7.dp.toPx()))),
    )
}

// ---------------------------------------------------------------- picking and uploading

/** Opens the camera or the gallery for a [key] (a side, or a type id); the picked file comes back to `onPicked` with that key. */
internal class PaperPicker(val camera: (key: String) -> Unit, val gallery: (key: String, types: Array<String>) -> Unit)

/**
 * The camera (with its permission) and the gallery / files picker, both turned into a scaled JPEG (or the PDF as it is) in the cache by
 * [prepareDocFile]. The key and the camera's file are kept across the app being killed while the camera is open.
 */
@Composable
internal fun rememberPaperPicker(onPicked: (key: String, file: File) -> Unit, onError: (String) -> Unit): PaperPicker {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val tooLarge = stringResource(R.string.pd_file_large)
    val wrongType = stringResource(R.string.pd_file_type)
    val picked by rememberUpdatedState(onPicked)
    val failed by rememberUpdatedState(onError)
    var pendingKey by rememberSaveable { mutableStateOf("front") }
    var cameraUriText by rememberSaveable { mutableStateOf<String?>(null) }

    fun take(uri: Uri, key: String) {
        scope.launch {
            val safe = key.filter { it.isLetterOrDigit() || it == '_' }
            val file = withContext(Dispatchers.IO) { prepareDocFile(context, uri, "doc_${safe}_${System.currentTimeMillis()}") }
            when {
                file == null -> failed(wrongType)
                file.length() > MAX_DOC_BYTES -> {
                    file.delete()
                    failed(tooLarge)
                }
                else -> picked(key, file)
            }
        }
    }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri -> uri?.let { take(it, pendingKey) } }
    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { taken -> if (taken) cameraUriText?.let { take(Uri.parse(it), pendingKey) } }
    fun launchCamera() {
        val safe = pendingKey.filter { it.isLetterOrDigit() || it == '_' }
        val file = File(context.cacheDir, "camera").apply { mkdirs() }.let { File(it, "doc_camera_$safe.jpg") }
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
        cameraUriText = uri.toString()
        camera.launch(uri)
    }
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted -> if (granted) launchCamera() }
    return PaperPicker(
        camera = { key ->
            pendingKey = key
            if (ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) launchCamera()
            else permission.launch(Manifest.permission.CAMERA)
        },
        gallery = { key, types ->
            pendingKey = key
            picker.launch(types)
        },
    )
}

/** Where a photo comes from: the camera, or the gallery (and the files, for a paper that may be a PDF). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun PaperSourceSheet(title: String, allowFiles: Boolean, onCamera: () -> Unit, onGallery: () -> Unit, onDismiss: () -> Unit) {
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = settingsCard()) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 24.dp).navigationBarsPadding(),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Text(title, color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            SourceRow(Icons.Rounded.PhotoCamera, stringResource(R.string.pd_camera), stringResource(R.string.pp_source_camera_hint), Modifier.paperStagger(0)) {
                onDismiss()
                onCamera()
            }
            SourceRow(
                Icons.Rounded.PhotoLibrary,
                stringResource(if (allowFiles) R.string.pd_gallery else R.string.pp_source_gallery),
                stringResource(if (allowFiles) R.string.pp_source_files_hint else R.string.pp_source_gallery_hint),
                Modifier.paperStagger(1),
            ) {
                onDismiss()
                onGallery()
            }
        }
    }
}

@Composable
private fun SourceRow(icon: ImageVector, title: String, hint: String, modifier: Modifier, onClick: () -> Unit) {
    val night = settingsNight()
    Row(
        modifier.fillMaxWidth().paperPress(onClick = onClick).clip(RoundedCornerShape(16.dp))
            .background(if (night) AccountDark.well else settingsAccent().copy(alpha = 0.06f)).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(42.dp).clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
        }
        Column(Modifier.weight(1f)) {
            Text(title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.Bold)
            Text(hint, color = settingsMut(), fontSize = 12.sp, lineHeight = 16.sp)
        }
    }
}

/**
 * Sends one paper or photo of [subject] (`establishment`, `vehicle` / `driver` with [subjectUuid]) to `POST documents`, saying how
 * much of the files has gone in [onProgress] (0..1, from OkHttp's thread).
 */
internal suspend fun uploadPaper(
    subject: String,
    subjectUuid: String?,
    type: DocumentTypeDto,
    number: String?,
    expires: String?,
    front: File,
    back: File?,
    onProgress: (Float) -> Unit,
): ApiResult<ProviderDocumentDto> {
    val text = "text/plain".toMediaType()
    val fields = mutableMapOf<String, RequestBody>(
        "subject" to subject.toRequestBody(text),
        "document_type_id" to type.id.toString().toRequestBody(text),
    )
    if (subjectUuid != null) fields["subject_uuid"] = subjectUuid.toRequestBody(text)
    if (type.hasNumber && !number.isNullOrBlank()) fields["number"] = number.trim().toRequestBody(text)
    if (type.hasExpiry && !expires.isNullOrBlank()) fields["expires_on"] = expires.toRequestBody(text)
    val frontSize = front.length()
    val total = (frontSize + (back?.length() ?: 0L)).coerceAtLeast(1L).toFloat()
    fun part(name: String, f: File, before: Long): MultipartBody.Part {
        val mime = (if (f.extension.equals("pdf", true)) "application/pdf" else "image/jpeg").toMediaTypeOrNull()
        return MultipartBody.Part.createFormData(name, f.name, CountingFileBody(f, mime) { written -> onProgress(((before + written) / total).coerceIn(0f, 1f)) })
    }
    val frontPart = part("front", front, 0L)
    val backPart = back?.let { part("back", it, frontSize) }
    return apiCall { ApiClient.workspace.upload(fields, frontPart, backPart) }
}

/** A file as a request body that says how much of it has been written (for the progress bar). */
private class CountingFileBody(private val file: File, private val type: MediaType?, private val onWritten: (Long) -> Unit) : RequestBody() {
    override fun contentType(): MediaType? = type
    override fun contentLength(): Long = file.length()
    override fun writeTo(sink: BufferedSink) {
        val buffer = ByteArray(16 * 1024)
        var written = 0L
        file.inputStream().use { input ->
            while (true) {
                val read = input.read(buffer)
                if (read == -1) break
                sink.write(buffer, 0, read)
                written += read
                onWritten(written)
            }
        }
    }
}

// ---------------------------------------------------------------- uploading one paper

/**
 * Uploading one paper or photo of [subject] (`establishment`, `vehicle` with [subjectUuid], `driver` with [subjectUuid]): a large
 * preview of each side picked (tap to change, X to remove) from the camera or the gallery, then its number and expiry when the type asks
 * for them (the expiry only after today, as the server says), and a fixed upload button with the upload's progress. [existing] is the
 * current upload of that type, when there is one (its state and refusal reason are shown; its number is kept).
 */
@Composable
internal fun DocumentForm(
    type: DocumentTypeDto,
    subject: String,
    subjectUuid: String?,
    onBack: () -> Unit,
    onUploaded: () -> Unit,
    existing: ProviderDocumentDto? = null,
) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)

    var number by rememberSaveable { mutableStateOf(existing?.number.orEmpty()) }
    var expires by rememberSaveable { mutableStateOf("") }
    var frontPath by rememberSaveable { mutableStateOf<String?>(null) }
    var backPath by rememberSaveable { mutableStateOf<String?>(null) }
    var tried by rememberSaveable { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var progress by remember { mutableFloatStateOf(0f) }
    var error by remember { mutableStateOf<String?>(null) }
    // Which side the source sheet is open for.
    var sheetFor by remember { mutableStateOf<String?>(null) }

    val photo = type.kind == "photo"
    // A photo is an image only; a paper may also be a PDF.
    val types = if (photo) arrayOf("image/jpeg", "image/png") else arrayOf("image/jpeg", "image/png", "application/pdf")
    val picker = rememberPaperPicker(
        onPicked = { key, file ->
            error = null
            if (key == "back") {
                backPath?.takeIf { it != file.absolutePath }?.let { File(it).delete() }
                backPath = file.absolutePath
            } else {
                frontPath?.takeIf { it != file.absolutePath }?.let { File(it).delete() }
                frontPath = file.absolutePath
            }
        },
        onError = { error = it },
    )

    val frontFile = frontPath?.let { File(it) }?.takeIf { it.exists() }
    val backFile = backPath?.let { File(it) }?.takeIf { it.exists() }
    val numberMissing = type.hasNumber && number.isBlank()
    val expiryBad = type.hasExpiry && !isAfterToday(expires)
    val backMissing = type.needsBackSide && backFile == null
    val ready = frontFile != null && !backMissing && !numberMissing && !expiryBad
    val shown by animateFloatAsState(progress, tween(250), label = "upload")
    val tomorrow = remember { LocalDate.now().plusDays(1) }

    fun upload() {
        tried = true
        val front = frontFile
        if (!ready || busy || front == null) return
        busy = true
        error = null
        progress = 0f
        val back = if (type.needsBackSide) backFile else null
        scope.launch {
            val result = uploadPaper(subject, subjectUuid, type, number, expires, front, back) { progress = it }
            busy = false
            if (result is ApiResult.Ok) {
                progress = 1f
                // The papers do not stay in the cache once they are on the server.
                front.delete()
                back?.delete()
                onUploaded()
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    PaperScaffold(
        title = type.name,
        onBack = { if (!busy) onBack() },
        bottom = {
            AnimatedVisibility(busy, enter = fadeIn() + expandVertically(), exit = fadeOut() + shrinkVertically()) {
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    LinearProgressIndicator(
                        progress = { shown }, color = settingsAccent(), trackColor = if (settingsNight()) AccountDark.well else Color(0xFFF1F2F4),
                        modifier = Modifier.fillMaxWidth().height(6.dp).clip(CircleShape),
                    )
                    Row(Modifier.fillMaxWidth()) {
                        Text(stringResource(R.string.pp_uploading), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.weight(1f))
                        Text("${(shown * 100).toInt()}%", color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }
            AnimatedVisibility(tried && !ready && !busy, enter = fadeIn() + expandVertically(), exit = fadeOut() + shrinkVertically()) {
                PaperNote(stringResource(R.string.pp_complete_first), Amber, Icons.Rounded.WarningAmber)
            }
            SettingsPrimaryButton(text = stringResource(if (photo) R.string.pp_send_photo else R.string.pd_send), loading = busy, onClick = ::upload)
        },
    ) {
        Box(Modifier.paperStagger(0)) {
            FormIntro(
                if (photo) Icons.Rounded.PhotoCamera else Icons.Rounded.Description, type.name,
                type.instructions?.takeIf { it.isNotBlank() } ?: stringResource(if (photo) R.string.vh_photo_hint else R.string.pp_form_intro),
            )
        }
        if (existing != null) {
            val state = paperState(existing, missing = false)
            Column(
                Modifier.fillMaxWidth().widthIn(max = 440.dp).paperStagger(1).settingsSurface(RoundedCornerShape(18.dp)).padding(14.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(stringResource(R.string.pp_current_state), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.weight(1f))
                    PaperStateBadge(state)
                }
                if (existing.status == "rejected") existing.rejectionReason?.takeIf { it.isNotBlank() }?.let {
                    PaperNote(stringResource(R.string.pd_reason, it), Red, Icons.Rounded.ErrorOutline)
                }
                Text(stringResource(R.string.pp_replace_note), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
            }
        }

        PaperSectionTitle(stringResource(if (photo) R.string.vh_photo_side else R.string.pp_form_photos), Modifier.paperStagger(2))
        SideSlot(
            label = stringResource(if (photo) R.string.vh_photo_side else R.string.pd_front),
            file = frontFile, error = tried && frontFile == null, photo = photo, modifier = Modifier.paperStagger(3),
            onCamera = { picker.camera("front") },
            onGallery = { picker.gallery("front", types) },
            onChange = { sheetFor = "front" },
            onRemove = { frontFile?.delete(); frontPath = null },
        )
        if (type.needsBackSide) {
            SideSlot(
                label = stringResource(R.string.pd_back),
                file = backFile, error = tried && backMissing, photo = photo, modifier = Modifier.paperStagger(4),
                onCamera = { picker.camera("back") },
                onGallery = { picker.gallery("back", types) },
                onChange = { sheetFor = "back" },
                onRemove = { backFile?.delete(); backPath = null },
            )
        }
        Text(
            stringResource(if (photo) R.string.vh_photo_hint else R.string.pp_camera_hint_doc), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp,
            modifier = Modifier.fillMaxWidth().widthIn(max = 440.dp),
        )

        if (type.hasNumber || type.hasExpiry) {
            PaperSectionTitle(stringResource(R.string.pp_form_details), Modifier.paperStagger(5))
            Column(
                Modifier.fillMaxWidth().widthIn(max = 440.dp).paperStagger(6).settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                if (type.hasNumber) {
                    DorrTextField(
                        number, { number = it.take(64) }, label = stringResource(R.string.pd_number), icon = Icons.Rounded.Badge,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii), error = tried && numberMissing, modifier = Modifier.fillMaxWidth(),
                    )
                }
                if (type.hasExpiry) {
                    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        DateField(
                            expires, { expires = it }, label = stringResource(R.string.pd_expires), modifier = Modifier.fillMaxWidth(),
                            minDate = tomorrow, error = tried && expiryBad,
                        )
                        Text(
                            stringResource(R.string.pp_expiry_hint), color = if (tried && expiryBad) Red else settingsMut(), fontSize = 12.sp,
                            modifier = Modifier.padding(horizontal = 6.dp),
                        )
                    }
                }
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    sheetFor?.let { side ->
        PaperSourceSheet(
            title = stringResource(if (side == "back") R.string.pd_back else if (photo) R.string.vh_photo_side else R.string.pd_front),
            allowFiles = !photo,
            onCamera = { picker.camera(side) },
            onGallery = { picker.gallery(side, types) },
            onDismiss = { sheetFor = null },
        )
    }
}

/**
 * One side of a paper: empty, a dashed box with the camera and the gallery; picked, the picture large (a PDF shows its icon) with a tick,
 * an X to remove it and "tap to change" (which opens the source sheet).
 */
@Composable
private fun SideSlot(
    label: String,
    file: File?,
    error: Boolean,
    photo: Boolean,
    modifier: Modifier,
    onCamera: () -> Unit,
    onGallery: () -> Unit,
    onChange: () -> Unit,
    onRemove: () -> Unit,
) {
    val accent = settingsAccent()
    val night = settingsNight()
    val shape = RoundedCornerShape(20.dp)
    val line by animateColorAsState(if (error) Red else accent.copy(alpha = 0.45f), tween(250), label = "slot-line")
    Column(modifier.fillMaxWidth().widthIn(max = 440.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(label, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
        Box(
            Modifier.fillMaxWidth().aspectRatio(1.6f).clip(shape).background(if (night) AccountDark.well else accent.copy(alpha = 0.05f)),
            contentAlignment = Alignment.Center,
        ) {
            AnimatedContent(
                targetState = file,
                transitionSpec = { (fadeIn(tween(240)) + scaleIn(tween(240), initialScale = 0.96f)) togetherWith fadeOut(tween(140)) },
                label = "slot",
            ) { picked ->
                if (picked == null) {
                    Column(
                        Modifier.fillMaxSize().dashedOutline(line, 20.dp).padding(14.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                        verticalArrangement = Arrangement.Center,
                    ) {
                        Box(Modifier.size(54.dp).clip(CircleShape).background(accent.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.AddAPhoto, contentDescription = null, tint = accent, modifier = Modifier.size(26.dp))
                        }
                        Text(
                            stringResource(R.string.pp_tap_add), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.padding(top = 8.dp, bottom = 10.dp),
                        )
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            SlotAction(Icons.Rounded.PhotoCamera, stringResource(R.string.pd_camera), onCamera)
                            SlotAction(Icons.Rounded.PhotoLibrary, stringResource(if (photo) R.string.pp_source_gallery else R.string.pd_gallery), onGallery)
                        }
                    }
                } else {
                    Box(Modifier.fillMaxSize().clickable(onClick = onChange)) {
                        if (picked.extension.equals("pdf", true)) {
                            Column(Modifier.fillMaxSize(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                                Icon(Icons.Rounded.PictureAsPdf, contentDescription = null, tint = Red, modifier = Modifier.size(46.dp))
                                Text(stringResource(R.string.pp_pdf_chosen), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 6.dp))
                            }
                        } else {
                            AsyncImage(model = picked, contentDescription = label, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                        }
                        // "Tap to change" on a soft dark strip at the bottom.
                        Row(
                            Modifier.align(Alignment.BottomCenter).fillMaxWidth()
                                .background(Brush.verticalGradient(listOf(Color.Transparent, Color.Black.copy(alpha = 0.55f))))
                                .padding(horizontal = 12.dp, vertical = 10.dp),
                            verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
                        ) {
                            Icon(Icons.Rounded.Autorenew, contentDescription = null, tint = Color.White, modifier = Modifier.size(16.dp))
                            Text(stringResource(R.string.pp_tap_change), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                        }
                        Box(
                            Modifier.align(Alignment.TopStart).padding(10.dp).size(28.dp).clip(CircleShape).background(Green),
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.Rounded.Check, contentDescription = null, tint = Color.White, modifier = Modifier.size(18.dp)) }
                        Box(
                            Modifier.align(Alignment.TopEnd).padding(10.dp).size(32.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.55f)).clickable(onClick = onRemove),
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.Rounded.Close, contentDescription = stringResource(R.string.pp_remove), tint = Color.White, modifier = Modifier.size(18.dp)) }
                    }
                }
            }
        }
    }
}

@Composable
private fun SlotAction(icon: ImageVector, label: String, onClick: () -> Unit) {
    val accent = settingsAccent()
    Row(
        Modifier.paperPress(onClick = onClick).clip(RoundedCornerShape(12.dp)).background(settingsCard()).border(1.dp, accent.copy(alpha = 0.25f), RoundedCornerShape(12.dp))
            .padding(horizontal = 12.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Icon(icon, contentDescription = null, tint = accent, modifier = Modifier.size(16.dp))
        Text(label, color = accent, fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}
