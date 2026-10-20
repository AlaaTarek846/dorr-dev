package com.dorr.driver.ui

import android.content.Context
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.AttachFile
import androidx.compose.material.icons.filled.Gavel
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.ui.kit.RoundIconButton
import com.dorr.driver.ui.kit.ScreenScaffold
import com.dorr.driver.ui.theme.DorrBrand
import com.dorr.driver.ui.kit.DorrField
import com.dorr.driver.R
import com.dorr.driver.net.CaseDto
import com.dorr.driver.net.CaseOptionsDto
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.DriverRideItemDto
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okio.source
import okhttp3.RequestBody.Companion.toRequestBody

/**
 * The captain's disputes and "the rider left without paying" reports (D-229..D-238, D-285..D-292): each is a
 * case of its own — opened on a finished ride, with the system's evidence shown, photos the captain adds
 * (D-232), the conversation with the team, the decision in plain words (D-236), and one request for another
 * look where the country allows it (D-237). A report is only a report: it never becomes a debt by itself (D-230).
 * It follows the team through notifications; nothing here asks the server on a timer.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun CasesScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    var options by remember { mutableStateOf<CaseOptionsDto?>(null) }
    var cases by remember { mutableStateOf<List<CaseDto>?>(null) }
    var openUuid by remember { mutableStateOf<String?>(null) }
    var creating by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    suspend fun load() {
        (apiCall { DriverBackend.api.caseOptions() } as? ApiResult.Ok)?.let { options = it.data }
        when (val r = apiCall { DriverBackend.api.cases() }) {
            is ApiResult.Ok -> { cases = r.data; error = null }
            is ApiResult.Failed -> error = r.error.displayMessage
            is ApiResult.Offline -> error = context.getString(R.string.error_offline)
        }
    }
    // Back from a case (or from the form) shows the list as the server has it now.
    LaunchedEffect(openUuid, creating) { if (openUuid == null && !creating) load() }
    BackHandler { if (openUuid != null) openUuid = null else if (creating) creating = false else onBack() }

    ScreenScaffold(
        stringResource(R.string.cs_title),
        onBack = { if (openUuid != null) openUuid = null else if (creating) creating = false else onBack() },
        actions = {
            if (openUuid == null && !creating && options?.available == true) {
                RoundIconButton(Icons.Filled.Add, stringResource(R.string.cs_new), onClick = { creating = true })
            }
        },
    ) {
        when {
            openUuid != null -> CaseDetail(openUuid.orEmpty())
            creating -> NewCase(options, onCreated = { uuid -> creating = false; openUuid = uuid })
            else -> {
                error?.let { Text(it, color = DorrBrand.danger, modifier = Modifier.padding(16.dp)) }
                val list = cases
                when {
                    list == null && error == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = DorrBrand.orange) }
                    options?.available == false -> Hint(stringResource(R.string.cs_unavailable))
                    list.isNullOrEmpty() -> Hint(stringResource(R.string.cs_empty))
                    else -> Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        list.forEach { CaseRow(it) { openUuid = it.uuid } }
                    }
                }
            }
        }
    }
}

@Composable
private fun Hint(text: String) {
    Box(Modifier.fillMaxSize().padding(32.dp), contentAlignment = Alignment.Center) {
        Text(text, color = MaterialTheme.colorScheme.onBackground.copy(alpha = 0.6f), fontSize = 14.sp)
    }
}

@Composable
private fun statusColor(status: String): Color = when (status) {
    "confirmed", "partially_confirmed" -> DorrBrand.success
    "rejected" -> DorrBrand.danger
    "needs_info" -> DorrBrand.warning
    else -> DorrBrand.navy
}

@Composable
private fun CaseRow(c: CaseDto, onClick: () -> Unit) {
    val color = statusColor(c.status)
    Card(Modifier.clickable(onClick = onClick)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(40.dp).clip(CircleShape).background(color.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) { Icon(Icons.Filled.Gavel, null, tint = color, modifier = Modifier.size(20.dp)) }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(c.kindLabel + if (c.categoryLabel.isNotBlank() && c.categoryLabel != c.kindLabel) " · ${c.categoryLabel}" else "", fontWeight = FontWeight.Bold, fontSize = 14.sp)
                Text(listOfNotNull(c.number, c.ride?.number).joinToString(" · "), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
            }
            Column(horizontalAlignment = Alignment.End) {
                c.disputedMinor?.let { Text(money(it, c.currency), fontWeight = FontWeight.ExtraBold, fontSize = 14.sp) }
                // The state in words (a colour is only a help).
                Text(c.statusLabel, color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp))
            }
        }
    }
}

/** Open a dispute or an unpaid report on one of the captain's finished rides. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun NewCase(options: CaseOptionsDto?, onCreated: (String) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var rides by remember { mutableStateOf<List<DriverRideItemDto>?>(null) }
    var kind by remember { mutableStateOf(options?.kinds?.firstOrNull()?.code) }
    var reason by remember { mutableStateOf<String?>(null) }
    var ride by remember { mutableStateOf<DriverRideItemDto?>(null) }
    var summary by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    // O-63: attachments chosen with the report (photos, a dash cam's video).
    var files by remember { mutableStateOf<List<Uri>>(emptyList()) }
    val pickFiles = rememberLauncherForActivityResult(ActivityResultContracts.PickMultipleVisualMedia(6)) { uris -> files = (files + uris).distinct().take(6) }
    LaunchedEffect(Unit) { rides = (apiCall { DriverBackend.api.rideHistory() } as? ApiResult.Ok)?.data?.items.orEmpty() }

    Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding(), verticalArrangement = Arrangement.spacedBy(14.dp)) {
        Card {
            Text(stringResource(R.string.cs_what), fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(8.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                options?.kinds.orEmpty().forEach { k -> CasePill(k.label, kind == k.code) { kind = k.code; reason = null } }
            }
            if (kind == "dispute") {
                Spacer(Modifier.height(10.dp))
                Text(stringResource(R.string.cs_which_charge), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                Spacer(Modifier.height(6.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    options?.disputeReasons.orEmpty().forEach { r -> CasePill(r.label, reason == r.code) { reason = r.code } }
                }
            }
            if (kind == "unpaid_report") {
                Text(stringResource(R.string.cs_unpaid_hint), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.65f), modifier = Modifier.padding(top = 8.dp))
            }
        }
        Card {
            Text(stringResource(R.string.cs_which_ride), fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(8.dp))
            val list = rides
            when {
                list == null -> CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp, color = DorrBrand.orange)
                list.isEmpty() -> Text(stringResource(R.string.cs_no_rides), fontSize = 13.sp)
                else -> list.take(15).forEach { r ->
                    val on = ride?.uuid == r.uuid
                    Row(
                        Modifier.fillMaxWidth().padding(vertical = 3.dp).clip(RoundedCornerShape(12.dp))
                            .background(if (on) DorrBrand.navy.copy(alpha = 0.1f) else Color.Transparent).clickable { ride = r }.padding(10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text(listOfNotNull(r.number, r.category).joinToString(" · "), fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
                            Text(listOfNotNull(r.originAddress, r.destinationAddress).joinToString(" → "), fontSize = 11.sp, maxLines = 1, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                        }
                        Text(paymentWord(r.paymentMethod), fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
                    }
                }
            }
        }
        Card {
            DorrField(
                value = summary, onValueChange = { summary = it }, label = { Text(stringResource(R.string.cs_summary)) },
                modifier = Modifier.fillMaxWidth(), minLines = 3,
            )
            Spacer(Modifier.height(10.dp))
            Row(
                Modifier.clip(RoundedCornerShape(50)).background(DorrBrand.navy.copy(alpha = 0.07f))
                    .clickable(enabled = !busy) { pickFiles.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo)) }.padding(horizontal = 14.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Filled.AttachFile, null, tint = DorrBrand.navy, modifier = Modifier.size(16.dp))
                Text(stringResource(R.string.cs_attach_media, files.size), color = DorrBrand.navy, fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
            }
            files.forEach { f ->
                Row(Modifier.padding(top = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    val video = context.contentResolver.getType(f)?.startsWith("video/") == true
                    Text((if (video) "🎬 " else "🖼 ") + (f.lastPathSegment ?: "file"), fontSize = 12.sp, maxLines = 1, modifier = Modifier.weight(1f))
                    Text("✕", color = DorrBrand.danger, modifier = Modifier.clickable { files = files - f }.padding(6.dp))
                }
            }
            error?.let { Text(it, color = DorrBrand.danger, fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp)) }
        }
        BigButton(
            stringResource(R.string.cs_send), busy = busy,
            enabled = kind != null && ride != null && summary.trim().length >= 3 && (kind != "dispute" || reason != null),
        ) {
            busy = true
            error = null
            scope.launch {
                val body = JsonObject().apply {
                    addProperty("kind", kind)
                    addProperty("ride_uuid", ride?.uuid)
                    reason?.let { addProperty("category", it) }
                    addProperty("summary", summary.trim())
                }
                when (val r = apiCall { DriverBackend.api.openCase(body) }) {
                    is ApiResult.Ok -> {
                        // O-63: the attachments go up with the new report (one by one; a failed one is said, the report stays).
                        for (f in files) {
                            val up = apiCall { DriverBackend.api.caseFile(r.data.uuid, filePart(context, f)) }
                            if (up is ApiResult.Failed) error = up.error.displayMessage
                        }
                        onCreated(r.data.uuid)
                    }
                    is ApiResult.Failed -> error = r.error.displayMessage
                    is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                }
                busy = false
            }
        }
    }
}

@Composable
private fun paymentWord(method: String?): String = when (method) {
    "cash" -> stringResource(R.string.pf_pay_cash)
    "wallet" -> stringResource(R.string.pf_pay_wallet)
    "card" -> stringResource(R.string.pf_pay_card)
    else -> ""
}

@Composable
private fun CaseDetail(uuid: String) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var c by remember { mutableStateOf<CaseDto?>(null) }
    var text by remember { mutableStateOf("") }
    var appealing by remember { mutableStateOf(false) }
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val pick = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri: Uri? ->
        if (uri == null) return@rememberLauncherForActivityResult
        busy = true
        scope.launch {
            when (val r = apiCall { DriverBackend.api.caseFile(uuid, filePart(context, uri)) }) {
                is ApiResult.Ok -> { c = r.data; error = null }
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }
    LaunchedEffect(uuid) { (apiCall { DriverBackend.api.caseOf(uuid) } as? ApiResult.Ok)?.let { c = it.data } }
    val d = c ?: return Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = DorrBrand.orange) }

    fun act(call: suspend () -> ApiResult<CaseDto>) {
        busy = true
        error = null
        scope.launch {
            when (val r = call()) {
                is ApiResult.Ok -> { c = r.data; text = ""; note = ""; appealing = false }
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }

    Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding(), verticalArrangement = Arrangement.spacedBy(14.dp)) {
        val color = statusColor(d.status)
        Card {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(d.kindLabel + if (d.categoryLabel.isNotBlank() && d.categoryLabel != d.kindLabel) " · ${d.categoryLabel}" else "", fontWeight = FontWeight.Bold)
                    Text(listOfNotNull(d.number, d.ride?.number).joinToString(" · "), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                }
                Text(d.statusLabel, color = color, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).padding(horizontal = 12.dp, vertical = 4.dp))
            }
            d.disputedMinor?.let { Text(money(it, d.currency), fontWeight = FontWeight.ExtraBold, fontSize = 22.sp, modifier = Modifier.padding(top = 8.dp)) }
            d.summary?.let { Text(it, fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp)) }
        }

        d.decision?.let { dec ->
            Card {
                Text(stringResource(R.string.cs_decision), fontWeight = FontWeight.Bold)
                Text(d.statusLabel, color = color, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 4.dp))
                dec.reason?.let { Text(it, fontSize = 14.sp, modifier = Modifier.padding(top = 6.dp)) }
            }
        }
        if (d.appealedAt != null && d.decision == null) Text(stringResource(R.string.cs_appealed), fontSize = 13.sp, color = DorrBrand.warning, fontWeight = FontWeight.SemiBold)

        d.evidence?.let { e ->
            Card {
                Text(stringResource(R.string.cs_evidence), fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(6.dp))
                e.timeline.forEach { m ->
                    Row(Modifier.padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(milestoneWord(m.event), fontWeight = FontWeight.SemiBold, fontSize = 13.sp, modifier = Modifier.weight(1f))
                        m.distanceToPickupM?.let { Text(stringResource(R.string.cs_from_pickup, it), fontSize = 11.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f)) }
                        Spacer(Modifier.width(8.dp))
                        Text(clockOf(m.at), fontSize = 12.sp)
                    }
                }
                e.waiting?.let { Text(stringResource(R.string.cs_waiting, (it.pickupS / 60).toInt(), (it.freeS / 60).toInt()), fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp)) }
                e.route?.let { r -> Text(stringResource(R.string.cs_route, km(r.drivenM), km(r.plannedM)), fontSize = 13.sp) }
                e.lines.forEach { l -> Row(Modifier.fillMaxWidth().padding(top = 2.dp)) { Text(l.code, Modifier.weight(1f), fontSize = 12.sp); Text(money(l.amountMinor, d.currency), fontSize = 12.sp, fontWeight = FontWeight.SemiBold) } }
            }
        }

        Card {
            Text(stringResource(R.string.cs_conversation), fontWeight = FontWeight.Bold)
            if (d.messages.isEmpty()) Text(stringResource(R.string.cs_no_messages), fontSize = 13.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f), modifier = Modifier.padding(top = 4.dp))
            d.messages.forEach { m ->
                val mine = m.from == "me"
                Column(Modifier.fillMaxWidth().padding(top = 8.dp), horizontalAlignment = if (mine) Alignment.End else Alignment.Start) {
                    Text(
                        m.body, fontSize = 13.sp,
                        modifier = Modifier.clip(RoundedCornerShape(14.dp)).background(if (mine) DorrBrand.navy.copy(alpha = 0.1f) else DorrBrand.orange.copy(alpha = 0.12f)).padding(horizontal = 12.dp, vertical = 8.dp),
                    )
                    Text(if (mine) clockOf(m.at) else stringResource(R.string.cs_team) + " · " + clockOf(m.at), fontSize = 10.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.5f))
                }
            }
            if (d.files.isNotEmpty()) {
                Spacer(Modifier.height(8.dp))
                d.files.forEach { f -> Text("📎 ${f.name}", fontSize = 12.sp, color = DorrBrand.navy) }
            }
            if (d.canMessage) {
                Spacer(Modifier.height(10.dp))
                DorrField(value = text, onValueChange = { text = it }, label = { Text(stringResource(R.string.cs_write)) }, modifier = Modifier.fillMaxWidth(), minLines = 2)
                Row(Modifier.fillMaxWidth().padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    // D-232: photos of what the captain has (a screenshot, a receipt…).
                    Row(
                        Modifier.clip(RoundedCornerShape(50)).background(DorrBrand.navy.copy(alpha = 0.07f))
                            .clickable(enabled = !busy) { pick.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo)) }.padding(horizontal = 14.dp, vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Filled.AttachFile, null, tint = DorrBrand.navy, modifier = Modifier.size(16.dp))
                        Text(stringResource(R.string.cs_attach), color = DorrBrand.navy, fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
                    }
                    Spacer(Modifier.weight(1f))
                    Text(
                        stringResource(R.string.cs_reply), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                        modifier = Modifier.clip(RoundedCornerShape(50)).background(if (text.isBlank() || busy) DorrBrand.orange.copy(alpha = 0.4f) else DorrBrand.orange)
                            .clickable(enabled = text.isNotBlank() && !busy) { act { apiCall { DriverBackend.api.caseMessage(uuid, JsonObject().apply { addProperty("body", text.trim()) }) } } }.padding(horizontal = 18.dp, vertical = 8.dp),
                    )
                }
            }
        }

        // D-237: another look — only where the country allows it, once.
        if (d.canAppeal) {
            Card {
                if (!appealing) {
                    Text(stringResource(R.string.cs_appeal_hint), fontSize = 13.sp)
                    Spacer(Modifier.height(8.dp))
                    BigButton(stringResource(R.string.cs_appeal), color = DorrBrand.navy) { appealing = true }
                } else {
                    DorrField(value = note, onValueChange = { note = it }, label = { Text(stringResource(R.string.cs_appeal_why)) }, modifier = Modifier.fillMaxWidth(), minLines = 3)
                    Spacer(Modifier.height(8.dp))
                    BigButton(stringResource(R.string.cs_appeal_send), color = DorrBrand.navy, enabled = note.trim().length >= 3, busy = busy) {
                        act { apiCall { DriverBackend.api.appealCase(uuid, JsonObject().apply { addProperty("note", note.trim()) }) } }
                    }
                }
            }
        }
        error?.let { Text(it, color = DorrBrand.danger, fontSize = 13.sp) }
    }
}

@Composable
private fun milestoneWord(event: String): String = stringResource(
    when (event) {
        "accepted" -> R.string.cs_ev_accepted
        "arrived" -> R.string.cs_ev_arrived
        "started" -> R.string.cs_ev_started
        "completed" -> R.string.cs_ev_completed
        "canceled" -> R.string.cs_ev_canceled
        else -> R.string.cs_ev_no_show
    },
)

@Composable
private fun CasePill(label: String, selected: Boolean, onClick: () -> Unit) {
    val bg by animateColorAsState(if (selected) DorrBrand.navy else DorrBrand.navy.copy(alpha = 0.07f), tween(200), label = "case-pill")
    Text(
        label, color = if (selected) Color.White else DorrBrand.navy, fontWeight = FontWeight.SemiBold, fontSize = 13.sp,
        modifier = Modifier.clip(RoundedCornerShape(50)).background(bg).clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 8.dp),
    )
}

private fun km(m: Long?): String = m?.let { String.format("%.1f", it / 1000.0) } ?: "—"

/** The clock time of a server time, in the phone's zone; empty if missing. */
private fun clockOf(iso: String?): String = instantOf(iso)?.let { timeOfDay(it) }.orEmpty()

/** O-63: a photo or a video (a dash cam's clip), streamed from the phone straight to the request. */
private fun filePart(context: Context, uri: Uri): MultipartBody.Part {
    val type = context.contentResolver.getType(uri) ?: "image/jpeg"
    val ext = when {
        type.contains("png") -> "png"
        type.contains("webp") -> "webp"
        type.contains("pdf") -> "pdf"
        type.contains("quicktime") -> "mov"
        type.contains("3gpp") -> "3gp"
        type.contains("webm") -> "webm"
        type.startsWith("video/") -> "mp4"
        else -> "jpg"
    }
    val size = runCatching { context.contentResolver.openAssetFileDescriptor(uri, "r")?.use { it.length } }.getOrNull() ?: -1L
    val body = object : okhttp3.RequestBody() {
        override fun contentType() = type.toMediaType()
        override fun contentLength(): Long = size
        override fun writeTo(sink: okio.BufferedSink) {
            context.contentResolver.openInputStream(uri)?.use { input -> sink.writeAll(input.source()) }
        }
    }
    return MultipartBody.Part.createFormData("file", "evidence.$ext", body)
}
