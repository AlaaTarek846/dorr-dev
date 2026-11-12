package com.dorr.app.ui.screens.profile

import android.content.Context
import android.net.Uri
import android.widget.Toast
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddPhotoAlternate
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Phone
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Timer
import androidx.compose.material.icons.rounded.VerifiedUser
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AppLocale
import com.dorr.app.network.AuthSession
import com.dorr.app.network.VerificationBadgeDto
import com.dorr.app.network.VerificationDocumentTypeDto
import com.dorr.app.network.VerificationRequestDto
import com.dorr.app.network.VerificationTrackDto
import com.dorr.app.network.apiFailure
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.AccountDark
import java.io.File
import java.time.Instant
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.async
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody

/**
 * Account → Verification (docs/verification_tasks.md, batch 1 — AV-011, AV-013, AV-014, AV-019): choose a track,
 * send the request with its documents, then follow it — its status, the next step, the deadline counted on the
 * phone's own clock, what was asked for and the decision — send what was asked, or cancel.
 *
 * Loads once when it opens and on reconnect, then reloads only on `chat.verification.updated` (real time) — never
 * on a timer.
 */
@Composable
fun VerificationScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val reconnectTick = collectReconnectTick()
    var loading by remember { mutableStateOf(true) }
    var tracks by remember { mutableStateOf<List<VerificationTrackDto>>(emptyList()) }
    var request by remember { mutableStateOf<VerificationRequestDto?>(null) }
    var badge by remember { mutableStateOf<VerificationBadgeDto?>(null) }
    var formTrack by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.verification_failed)

    suspend fun load() {
        val auth = "Bearer ${AuthSession.token.orEmpty()}"
        try {
            val options = scope.async { ApiClient.verification.options(auth).data?.tracks.orEmpty() }
            val current = scope.async { ApiClient.verification.current(auth).data }
            val mine = scope.async { ApiClient.verification.badge(auth).data }
            tracks = options.await()
            request = current.await()
            badge = mine.await()
            // The Account screen's tick reads the cached account: keep it in step with the badge.
            val verified = badge?.isVerified == true
            AuthSession.user?.let { if (it.isVerified != verified) AuthSession.user = it.copy(isVerified = verified) }
        } catch (e: Exception) {
            Toast.makeText(context, e.apiFailure().message ?: failed, Toast.LENGTH_SHORT).show()
        } finally {
            loading = false
        }
    }

    LaunchedEffect(reconnectTick) { load() }
    LaunchedEffect(Unit) {
        ChatRealtime.events.collect { event -> if (event.name == "chat.verification.updated") load() }
    }
    BackHandler(enabled = formTrack != null) { formTrack = null }

    val current = request
    // A request in progress (or a completed one) is followed; otherwise the tracks are offered.
    val following = current != null && (current.isOpen || current.status == "completed")

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(R.string.verification_title)) { if (formTrack != null) formTrack = null else onBack() }
            when {
                loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
                }
                following && current != null -> VerificationStatus(
                    request = current,
                    badge = badge,
                    documentTypes = tracks.firstOrNull { it.track == current.track }?.documents.orEmpty(),
                    onChanged = { request = it },
                )
                formTrack != null -> VerificationForm(
                    track = tracks.first { it.track == formTrack },
                    onSent = { request = it; formTrack = null },
                )
                else -> VerificationTracks(tracks = tracks, last = current, badge = badge, onChoose = { formTrack = it })
            }
        }
    }
}

// ------------------------------------------------------------------ choosing a track

@Composable
private fun VerificationTracks(tracks: List<VerificationTrackDto>, last: VerificationRequestDto?, badge: VerificationBadgeDto?, onChoose: (String) -> Unit) {
    Column(
        modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 14.dp).padding(bottom = 24.dp),
    ) {
        if (badge != null) {
            BadgeCard(badge)
            Spacer(Modifier.height(12.dp))
        }
        Text(stringResource(R.string.verification_intro), color = settingsMut(), fontSize = 14.sp, lineHeight = 20.sp, modifier = Modifier.padding(bottom = 16.dp))
        // The last request, closed: why, before trying again.
        if (last != null && !last.isOpen && last.status != "completed") {
            VCard {
                Text(statusLabel(last.status), color = statusColor(last.status), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                last.decisionReason?.takeIf { it.isNotBlank() }?.let {
                    Spacer(Modifier.height(4.dp))
                    Text(it, color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp)
                }
                Spacer(Modifier.height(4.dp))
                Text(stringResource(R.string.verification_try_again), color = settingsMut(), fontSize = 12.sp)
            }
            Spacer(Modifier.height(12.dp))
        }
        tracks.forEach { track ->
            val celebrity = track.track == "celebrity"
            VCard(onClick = if (track.available) ({ onChoose(track.track) }) else null) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    PinkIcon(if (celebrity) Icons.Rounded.Star else Icons.Rounded.VerifiedUser)
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(trackLabel(track.track), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.Bold)
                        Text(
                            stringResource(if (celebrity) R.string.verification_track_celebrity_sub else R.string.verification_track_paid_sub),
                            color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp,
                        )
                    }
                }
                if (celebrity) {
                    Spacer(Modifier.height(8.dp))
                    Text(stringResource(R.string.verification_celebrity_no_promise), color = settingsMut(), fontSize = 11.sp, lineHeight = 16.sp)
                }
                if (!track.available) {
                    Spacer(Modifier.height(8.dp))
                    Text(stringResource(R.string.verification_track_unavailable), color = Color(0xFFDC2626), fontSize = 12.sp)
                }
            }
            Spacer(Modifier.height(12.dp))
        }
    }
}

// ------------------------------------------------------------------ the form

/** What the account picked for one document side: typeId to (side to uri). */
private typealias Picks = MutableMap<String, Uri>

@Composable
private fun VerificationForm(track: VerificationTrackDto, onSent: (VerificationRequestDto) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val user = AuthSession.user
    var fullName by remember { mutableStateOf(user?.name.orEmpty()) }
    var phone by remember { mutableStateOf(user?.phone.orEmpty()) }
    var email by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    val picks = remember { mutableStateMapOf<String, Uri>() }
    var sending by remember { mutableStateOf(false) }
    val missingText = stringResource(R.string.verification_missing)
    val failed = stringResource(R.string.verification_failed)

    Column(
        modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 14.dp).padding(bottom = 24.dp),
    ) {
        Text(trackLabel(track.track), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
        Spacer(Modifier.height(12.dp))
        DorrTextField(fullName, { if (it.length <= 191) fullName = it }, label = stringResource(R.string.verification_full_name), icon = Icons.Rounded.Person, enabled = !sending)
        Spacer(Modifier.height(12.dp))
        DorrTextField(phone, { if (it.length <= 32) phone = it }, label = stringResource(R.string.verification_contact_phone), icon = Icons.Rounded.Phone, enabled = !sending)
        Spacer(Modifier.height(12.dp))
        DorrTextField(email, { if (it.length <= 191) email = it }, label = stringResource(R.string.verification_contact_email), icon = Icons.Rounded.Email, enabled = !sending)
        Spacer(Modifier.height(12.dp))
        DorrTextField(
            notes, { if (it.length <= 2000) notes = it }, label = stringResource(R.string.verification_notes),
            icon = Icons.Rounded.Notes, singleLine = false, minLines = 3, minHeight = 96.dp, enabled = !sending,
        )
        Spacer(Modifier.height(18.dp))
        Text(stringResource(R.string.verification_documents), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(8.dp))
        track.documents.forEach { doc ->
            DocumentPicker(doc, picks, enabled = !sending)
            Spacer(Modifier.height(10.dp))
        }
        Spacer(Modifier.height(12.dp))
        SettingsPrimaryButton(text = stringResource(R.string.verification_send), loading = sending, enabled = !sending) {
            val missing = track.documents.any { d -> d.isRequired && (picks["${d.id}:front"] == null || (d.needsBackSide && picks["${d.id}:back"] == null)) }
            if (fullName.isBlank() || missing) {
                Toast.makeText(context, missingText, Toast.LENGTH_SHORT).show()
                return@SettingsPrimaryButton
            }
            sending = true
            scope.launch {
                try {
                    val fields = textFields(
                        "track" to track.track, "full_name" to fullName.trim(), "contact_phone" to phone.trim(),
                        "contact_email" to email.trim(), "notes" to notes.trim(),
                    )
                    val response = ApiClient.verification.submit("Bearer ${AuthSession.token.orEmpty()}", fields, documentParts(context, picks))
                    response.data?.let(onSent)
                    Toast.makeText(context, context.getString(R.string.verification_sent), Toast.LENGTH_SHORT).show()
                } catch (e: Exception) {
                    Toast.makeText(context, e.apiFailure().message ?: failed, Toast.LENGTH_LONG).show()
                } finally {
                    sending = false
                }
            }
        }
    }
}

/** One document: its front (and back), each picked from the gallery and shown before sending. */
@Composable
private fun DocumentPicker(doc: VerificationDocumentTypeDto, picks: Picks, enabled: Boolean) {
    var side by remember { mutableStateOf("front") }
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        if (uri != null) picks["${doc.id}:$side"] = uri
    }
    VCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(doc.name.orEmpty(), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            Text(
                stringResource(if (doc.isRequired) R.string.verification_required else R.string.verification_optional),
                color = if (doc.isRequired) settingsAccent() else settingsMut(), fontSize = 11.sp,
            )
        }
        doc.instructions?.takeIf { it.isNotBlank() }?.let {
            Spacer(Modifier.height(2.dp))
            Text(it, color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
        }
        Spacer(Modifier.height(8.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            listOfNotNull("front", if (doc.needsBackSide) "back" else null).forEach { s ->
                val uri = picks["${doc.id}:$s"]
                Box(
                    modifier = Modifier
                        .weight(1f)
                        .height(96.dp)
                        .clip(RoundedCornerShape(14.dp))
                        .background(if (settingsNight()) AccountDark.bg else Color(0xFFFBF7F8))
                        .border(1.dp, if (settingsNight()) AccountDark.line else Color(0xFFF3D5DB), RoundedCornerShape(14.dp))
                        .clickable(enabled = enabled) {
                            side = s
                            picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly))
                        },
                    contentAlignment = Alignment.Center,
                ) {
                    if (uri != null) {
                        AsyncImage(model = uri, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                        Box(
                            modifier = Modifier.align(Alignment.TopEnd).padding(6.dp).size(24.dp).clip(CircleShape)
                                .background(Color.Black.copy(alpha = 0.5f)).clickable(enabled = enabled) { picks.remove("${doc.id}:$s") },
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.Rounded.Close, contentDescription = null, tint = Color.White, modifier = Modifier.size(14.dp)) }
                    } else {
                        Column(horizontalAlignment = Alignment.CenterHorizontally) {
                            Icon(Icons.Rounded.AddPhotoAlternate, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
                            Text(stringResource(if (s == "front") R.string.verification_side_front else R.string.verification_side_back), color = settingsMut(), fontSize = 12.sp)
                        }
                    }
                }
            }
        }
    }
}

// ------------------------------------------------------------------ following the request

@Composable
private fun VerificationStatus(
    request: VerificationRequestDto,
    badge: VerificationBadgeDto?,
    documentTypes: List<VerificationDocumentTypeDto>,
    onChanged: (VerificationRequestDto) -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var confirmCancel by remember { mutableStateOf(false) }
    var cancelling by remember { mutableStateOf(false) }
    val failed = stringResource(R.string.verification_failed)

    Column(
        modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 14.dp).padding(bottom = 24.dp),
    ) {
        if (badge != null) {
            BadgeCard(badge)
            Spacer(Modifier.height(12.dp))
        }
        VCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                PinkIcon(if (request.status == "completed") Icons.Rounded.CheckCircle else Icons.Rounded.Badge)
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(trackLabel(request.track), color = settingsMut(), fontSize = 12.sp)
                    Text(statusLabel(request.status), color = statusColor(request.status), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                }
            }
            Spacer(Modifier.height(10.dp))
            Text(nextStepLabel(request.nextStep), color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp)
            DeadlineLine(request)
        }

        request.decisionReason?.takeIf { it.isNotBlank() }?.let { reason ->
            Spacer(Modifier.height(12.dp))
            VCard {
                Text(stringResource(R.string.verification_decision), color = settingsMut(), fontSize = 12.sp)
                Text(reason, color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp)
            }
        }

        if (request.canComplete) {
            Spacer(Modifier.height(12.dp))
            CompletionCard(request, documentTypes, onChanged)
        }

        if (request.documents.isNotEmpty()) {
            Spacer(Modifier.height(16.dp))
            Text(stringResource(R.string.verification_documents), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(8.dp))
            VCard {
                request.documents.forEachIndexed { i, doc ->
                    if (i > 0) Spacer(Modifier.height(10.dp))
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(doc.type?.name.orEmpty(), color = settingsInk(), fontSize = 13.sp, modifier = Modifier.weight(1f))
                        Text(docStatusLabel(doc.status), color = docStatusColor(doc.status), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    }
                    doc.reviewNote?.takeIf { it.isNotBlank() }?.let { Text(it, color = Color(0xFFDC2626), fontSize = 12.sp) }
                }
            }
        }

        if (request.history.isNotEmpty()) {
            Spacer(Modifier.height(16.dp))
            Text(stringResource(R.string.verification_history), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(8.dp))
            VCard {
                request.history.asReversed().forEachIndexed { i, h ->
                    if (i > 0) Spacer(Modifier.height(8.dp))
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(8.dp).clip(CircleShape).background(statusColor(h.status)))
                        Spacer(Modifier.width(10.dp))
                        Text(statusLabel(h.status), color = settingsInk(), fontSize = 13.sp, modifier = Modifier.weight(1f))
                        Text(whenText(h.at).orEmpty(), color = settingsMut(), fontSize = 11.sp)
                    }
                }
            }
        }

        if (request.canCancel) {
            Spacer(Modifier.height(20.dp))
            TextButton(onClick = { confirmCancel = true }, enabled = !cancelling, modifier = Modifier.fillMaxWidth()) {
                Text(stringResource(R.string.verification_cancel), color = Color(0xFFDC2626), fontWeight = FontWeight.Bold)
            }
        }
    }

    if (confirmCancel) {
        AlertDialog(
            onDismissRequest = { confirmCancel = false },
            title = { Text(stringResource(R.string.verification_cancel)) },
            text = { Text(stringResource(R.string.verification_cancel_confirm)) },
            confirmButton = {
                TextButton(onClick = {
                    confirmCancel = false
                    cancelling = true
                    scope.launch {
                        try {
                            ApiClient.verification.cancel("Bearer ${AuthSession.token.orEmpty()}", request.uuid).data?.let(onChanged)
                        } catch (e: Exception) {
                            Toast.makeText(context, e.apiFailure().message ?: failed, Toast.LENGTH_LONG).show()
                        } finally {
                            cancelling = false
                        }
                    }
                }) { Text(stringResource(R.string.verification_cancel_yes), color = Color(0xFFDC2626)) }
            },
            dismissButton = { TextButton(onClick = { confirmCancel = false }) { Text(stringResource(R.string.verification_keep)) } },
        )
    }
}

/** The deadline as the account reads it, counted down on the phone's own clock (one tick a minute, no request). */
@Composable
private fun DeadlineLine(request: VerificationRequestDto) {
    if (request.deadlinePaused) {
        Spacer(Modifier.height(8.dp))
        Text(stringResource(R.string.verification_deadline_paused), color = settingsMut(), fontSize = 12.sp)
        return
    }
    val until = parseInstant(request.deadlineAt) ?: return
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(until) {
        while (true) {
            now = System.currentTimeMillis()
            delay(60_000)
        }
    }
    val left = (until.toEpochMilli() - now).coerceAtLeast(0)
    val days = left / 86_400_000
    val hours = (left % 86_400_000) / 3_600_000
    Spacer(Modifier.height(8.dp))
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Rounded.Timer, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(
            stringResource(
                if (request.deadlineOwner == "user") R.string.verification_deadline_you else R.string.verification_deadline_staff,
                stringResource(R.string.verification_left, days, hours),
            ),
            color = settingsMut(), fontSize = 12.sp,
        )
    }
}

/** AV-014: what was asked for, the documents to upload again, a note — and send. */
@Composable
private fun CompletionCard(request: VerificationRequestDto, documentTypes: List<VerificationDocumentTypeDto>, onChanged: (VerificationRequestDto) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val picks = remember { mutableStateMapOf<String, Uri>() }
    var note by remember { mutableStateOf("") }
    var sending by remember { mutableStateOf(false) }
    val failed = stringResource(R.string.verification_failed)
    val missingText = stringResource(R.string.verification_missing)
    val asked = documentTypes.filter { it.id in request.completionDocumentTypeIds }

    VCard {
        Text(stringResource(R.string.verification_asked_for), color = Color(0xFFD97706), fontSize = 13.sp, fontWeight = FontWeight.Bold)
        request.completionReason?.let { Text(it, color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp) }
    }
    Spacer(Modifier.height(10.dp))
    (asked.ifEmpty { documentTypes }).forEach { doc ->
        DocumentPicker(doc.copy(isRequired = doc.id in request.completionDocumentTypeIds), picks, enabled = !sending)
        Spacer(Modifier.height(10.dp))
    }
    DorrTextField(
        note, { if (it.length <= 2000) note = it }, label = stringResource(R.string.verification_note),
        icon = Icons.Rounded.Notes, singleLine = false, minLines = 2, minHeight = 72.dp, enabled = !sending,
    )
    Spacer(Modifier.height(12.dp))
    SettingsPrimaryButton(text = stringResource(R.string.verification_send_completion), loading = sending, enabled = !sending) {
        val missing = asked.any { d -> picks["${d.id}:front"] == null || (d.needsBackSide && picks["${d.id}:back"] == null) }
        if (missing || (picks.isEmpty() && note.isBlank())) {
            Toast.makeText(context, missingText, Toast.LENGTH_SHORT).show()
            return@SettingsPrimaryButton
        }
        sending = true
        scope.launch {
            try {
                val response = ApiClient.verification.complete(
                    "Bearer ${AuthSession.token.orEmpty()}", request.uuid, textFields("note" to note.trim()), documentParts(context, picks),
                )
                response.data?.let(onChanged)
                Toast.makeText(context, context.getString(R.string.verification_sent), Toast.LENGTH_SHORT).show()
            } catch (e: Exception) {
                Toast.makeText(context, e.apiFailure().message ?: failed, Toast.LENGTH_LONG).show()
            } finally {
                sending = false
            }
        }
    }
}

// ------------------------------------------------------------------ pieces

/** AV-024: my badge — its status, type, since and until, and the reason of its last change. */
@Composable
private fun BadgeCard(badge: VerificationBadgeDto) {
    VCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.verification_my_badge), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.weight(1f))
            if (badge.isVerified) com.dorr.app.ui.components.VerifiedTick(18.dp)
        }
        Text(
            badgeStatusLabel(badge.status),
            color = if (badge.isVerified) Color(0xFF16A34A) else if (badge.status == "suspended") Color(0xFFDC2626) else settingsMut(),
            fontSize = 16.sp, fontWeight = FontWeight.ExtraBold,
        )
        Text(
            stringResource(if (badge.type == "celebrity") R.string.verification_badge_type_celebrity else R.string.verification_badge_type_standard),
            color = settingsMut(), fontSize = 12.sp,
        )
        whenText(badge.activeFrom)?.let { Text(stringResource(R.string.verification_badge_since, it), color = settingsMut(), fontSize = 12.sp) }
        Text(
            whenText(badge.expiresAt)?.let { stringResource(R.string.verification_badge_until, it) } ?: stringResource(R.string.verification_badge_no_end),
            color = settingsMut(), fontSize = 12.sp,
        )
        badge.reason?.takeIf { it.isNotBlank() && !badge.isVerified }?.let {
            Spacer(Modifier.height(4.dp))
            Text(it, color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp)
        }
    }
}

@Composable
private fun badgeStatusLabel(status: String): String = stringResource(
    when (status) {
        "temporary" -> R.string.verification_badge_temporary
        "active" -> R.string.verification_badge_active
        "suspended" -> R.string.verification_badge_suspended
        "expired" -> R.string.verification_badge_expired
        else -> R.string.verification_badge_withdrawn
    },
)

@Composable
private fun VCard(onClick: (() -> Unit)? = null, content: @Composable () -> Unit) {
    val shape = RoundedCornerShape(16.dp)
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .settingsSurface(shape)
            .clip(shape)
            .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier)
            .padding(14.dp),
    ) { content() }
}

private fun textFields(vararg pairs: Pair<String, String>): Map<String, RequestBody> =
    pairs.filter { it.second.isNotBlank() }.associate { (k, v) -> k to v.toRequestBody("text/plain".toMediaType()) }

/** The picked pictures as `documents[{typeId}][front|back]`, copied to the cache first (content URIs can't be streamed twice). */
private suspend fun documentParts(context: Context, picks: Map<String, Uri>): List<MultipartBody.Part> = withContext(Dispatchers.IO) {
    picks.mapNotNull { (key, uri) ->
        val (typeId, side) = key.split(":").let { it[0] to it[1] }
        val mime = context.contentResolver.getType(uri) ?: "image/jpeg"
        val ext = if (mime.endsWith("png")) "png" else if (mime.endsWith("webp")) "webp" else "jpg"
        val file = File(context.cacheDir, "verification-$typeId-$side.$ext")
        context.contentResolver.openInputStream(uri)?.use { input -> file.outputStream().use { input.copyTo(it) } } ?: return@mapNotNull null
        MultipartBody.Part.createFormData("documents[$typeId][$side]", file.name, file.asRequestBody(mime.toMediaType()))
    }
}

private fun parseInstant(iso: String?): Instant? = iso?.let {
    runCatching { Instant.parse(it) }.getOrNull() ?: runCatching { OffsetDateTime.parse(it).toInstant() }.getOrNull()
}

private fun whenText(iso: String?): String? = parseInstant(iso)?.let {
    DateTimeFormatter.ofLocalizedDateTime(FormatStyle.MEDIUM, FormatStyle.SHORT).withLocale(AppLocale.java()).format(it.atZone(ZoneId.systemDefault()))
}

@Composable
private fun trackLabel(track: String): String =
    stringResource(if (track == "celebrity") R.string.verification_track_celebrity else R.string.verification_track_paid)

@Composable
private fun statusLabel(status: String): String = stringResource(
    when (status) {
        "new" -> R.string.verification_status_new
        "under_review" -> R.string.verification_status_under_review
        "needs_completion" -> R.string.verification_status_needs_completion
        "preliminary_approved" -> R.string.verification_status_preliminary_approved
        "in_execution" -> R.string.verification_status_in_execution
        "awaiting_evidence" -> R.string.verification_status_awaiting_evidence
        "final_review" -> R.string.verification_status_final_review
        "completed" -> R.string.verification_status_completed
        "rejected" -> R.string.verification_status_rejected
        "cancelled" -> R.string.verification_status_cancelled
        "expired" -> R.string.verification_status_expired
        else -> R.string.verification_status_suspended
    },
)

@Composable
private fun statusColor(status: String): Color = when (status) {
    "completed", "preliminary_approved" -> Color(0xFF16A34A)
    "rejected", "suspended" -> Color(0xFFDC2626)
    "needs_completion", "awaiting_evidence" -> Color(0xFFD97706)
    "cancelled", "expired" -> settingsMut()
    else -> settingsAccent()
}

@Composable
private fun nextStepLabel(step: String?): String = stringResource(
    when (step) {
        "complete" -> R.string.verification_next_complete
        "pay" -> R.string.verification_next_pay
        "agreement" -> R.string.verification_next_agreement
        "publish_ad" -> R.string.verification_next_publish_ad
        "upload_proof" -> R.string.verification_next_upload_proof
        "wait_final" -> R.string.verification_next_wait_final
        "wait_staff" -> R.string.verification_next_wait_staff
        "done" -> R.string.verification_next_done
        "rejected" -> R.string.verification_next_rejected
        "closed" -> R.string.verification_next_closed
        else -> R.string.verification_next_wait_review
    },
)

@Composable
private fun docStatusLabel(status: String): String = stringResource(
    when (status) {
        "accepted" -> R.string.verification_doc_accepted
        "rejected" -> R.string.verification_doc_rejected
        else -> R.string.verification_doc_pending
    },
)

@Composable
private fun docStatusColor(status: String): Color = when (status) {
    "accepted" -> Color(0xFF16A34A)
    "rejected" -> Color(0xFFDC2626)
    else -> settingsMut()
}
