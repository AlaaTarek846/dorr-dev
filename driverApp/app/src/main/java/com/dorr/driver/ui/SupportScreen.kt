package com.dorr.driver.ui

import android.content.Context
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.AttachFile
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.rounded.HelpOutline
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.SupportAgent
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
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
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.HelpNodeDto
import com.dorr.driver.net.LostItemDto
import com.dorr.driver.net.SupportHelpDto
import com.dorr.driver.net.SupportMessageDto
import com.dorr.driver.net.SupportTicketDto
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.DorrField
import com.dorr.driver.ui.kit.PrimaryButton
import com.dorr.driver.ui.kit.ScreenScaffold
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.Pal
import com.google.gson.JsonObject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody
import okio.source

/** A ticket to open from elsewhere (a wallet transaction that isn't mine — D-214, D-298): its category, reference and title. */
internal object SupportPrefill {
    val open = MutableStateFlow<Triple<String, String?, String>?>(null)
}

private enum class SupportPage { Home, New, Ticket }

/**
 * The captain's support (docs/rides_tasks.md D-285..D-293): whether the team is there now, the help centre with
 * a search, my tickets, a new ticket (category, my last trip, a photo / PDF) and the conversation inside each.
 */
@Composable
fun SupportScreen(onBack: () -> Unit, onLostItems: () -> Unit) {
    val prefill by SupportPrefill.open.collectAsState()
    var page by remember { mutableStateOf(if (prefill != null) SupportPage.New else SupportPage.Home) }
    var ticket by remember { mutableStateOf<SupportTicketDto?>(null) }
    var help by remember { mutableStateOf<SupportHelpDto?>(null) }
    var tickets by remember { mutableStateOf<List<SupportTicketDto>?>(null) }
    suspend fun reload() {
        help = (apiCall { DriverBackend.api.supportHelp(null) } as? ApiResult.Ok)?.data ?: help
        tickets = (apiCall { DriverBackend.api.supportTickets() } as? ApiResult.Ok)?.data.orEmpty()
    }
    LaunchedEffect(Unit) {
        reload()
        com.dorr.driver.live.DriverLive.events.collect { e -> if (e.name.startsWith("support")) reload() }
    }
    BackHandler { if (page != SupportPage.Home) page = SupportPage.Home else onBack() }
    val scope = rememberCoroutineScope()

    ScreenScaffold(stringResource(R.string.sp_title), onBack = { if (page != SupportPage.Home) page = SupportPage.Home else onBack() }) {
        AnimatedContent(page, transitionSpec = { fadeIn() togetherWith fadeOut() }, label = "support") { p ->
            when (p) {
                SupportPage.Home -> SupportHome(help, tickets, onNew = { page = SupportPage.New }, onOpen = { ticket = it; page = SupportPage.Ticket }, onLostItems = onLostItems)
                SupportPage.New -> NewTicket(help, prefill, onDone = { t ->
                    SupportPrefill.open.value = null
                    ticket = t
                    page = SupportPage.Ticket
                    scope.launch { reload() }
                })
                SupportPage.Ticket -> ticket?.let { TicketConversation(it) }
            }
        }
    }
}

@Composable
private fun SupportHome(help: SupportHelpDto?, tickets: List<SupportTicketDto>?, onNew: () -> Unit, onOpen: (SupportTicketDto) -> Unit, onLostItems: () -> Unit) {
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<HelpNodeDto>>(emptyList()) }
    LaunchedEffect(query) {
        results = if (query.trim().length < 2) emptyList()
        else (apiCall { DriverBackend.api.supportHelp(query.trim()) } as? ApiResult.Ok)?.data?.results.orEmpty()
    }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        // D-285: is the team there now.
        help?.let { h ->
            Row(Modifier.fillMaxWidth().surface(RoundedCornerShape(18.dp)).padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(12.dp).clip(CircleShape).background(if (h.openNow) Pal.success else Pal.warning))
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(if (h.openNow) R.string.sp_open_now else R.string.sp_closed_now), color = Pal.ink, fontWeight = FontWeight.Bold)
                    h.hours?.let { Text(it, color = Pal.mut, fontSize = 12.sp) }
                }
                Icon(Icons.Rounded.SupportAgent, null, tint = Pal.accent)
            }
        }
        PrimaryButton(stringResource(R.string.sp_new), icon = Icons.Rounded.SupportAgent, onClick = onNew)
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Pal.accent.copy(alpha = 0.08f)).clickable(onClick = onLostItems).padding(14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.Inventory2, null, tint = Pal.accent)
            Spacer(Modifier.width(10.dp))
            Text(stringResource(R.string.lf_title), color = Pal.accent, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
        }
        // D-286, D-287: the help centre and its search.
        Text(stringResource(R.string.sp_help), color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 16.sp)
        DorrField(query, { query = it.take(60) }, placeholder = { Text(stringResource(R.string.sp_search)) })
        val shown = if (query.trim().length >= 2) results else help?.items.orEmpty()
        if (shown.isEmpty()) Text(stringResource(if (query.isBlank()) R.string.sp_no_help else R.string.sp_no_results), color = Pal.mut, fontSize = 13.sp)
        shown.forEach { HelpNode(it, 0) }
        // My tickets.
        Text(stringResource(R.string.sp_tickets), color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 16.sp)
        when {
            tickets == null -> CircularProgressIndicator(color = Pal.accent)
            tickets.isEmpty() -> Text(stringResource(R.string.sp_no_tickets), color = Pal.mut, fontSize = 13.sp)
            else -> tickets.forEach { t -> TicketRow(t) { onOpen(t) } }
        }
    }
}

@Composable
private fun HelpNode(n: HelpNodeDto, depth: Int) {
    var open by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxWidth().padding(start = (depth * 12).dp).surface(RoundedCornerShape(14.dp)).clickable { open = !open }.padding(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.HelpOutline, null, tint = Pal.accent, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
            Text(n.title.orEmpty(), color = Pal.ink, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
            Icon(Icons.Rounded.ExpandMore, null, tint = Pal.mut)
        }
        if (open) {
            n.answer?.takeIf { it.isNotBlank() }?.let { Text(it, color = Pal.mut, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp)) }
            n.children.forEach { HelpNode(it, depth + 1) }
        }
    }
}

@Composable
private fun TicketRow(t: SupportTicketDto, onClick: () -> Unit) {
    val (label, color) = statusLook(t.status)
    Row(Modifier.fillMaxWidth().surface(RoundedCornerShape(16.dp)).clickable(onClick = onClick).padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(t.title, color = Pal.ink, fontWeight = FontWeight.Bold)
            Text("#${t.number} · ${t.lastMessage?.body.orEmpty().take(40)}", color = Pal.mut, fontSize = 12.sp, maxLines = 1)
        }
        Text(stringResource(label), color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp))
    }
}

private fun statusLook(status: String): Pair<Int, Color> = when (status) {
    "resolved" -> R.string.sp_st_resolved to Pal.success
    "closed" -> R.string.sp_st_closed to Pal.mut
    "reopened" -> R.string.sp_st_reopened to Pal.warning
    else -> R.string.sp_st_opened to Pal.accent
}

/** D-288..D-291: a new ticket — the category, my last trip if it's about it, a photo / PDF. */
@Composable
private fun NewTicket(help: SupportHelpDto?, prefill: Triple<String, String?, String>?, onDone: (SupportTicketDto) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var category by remember { mutableStateOf(prefill?.first ?: "trip") }
    var title by remember { mutableStateOf(prefill?.third.orEmpty()) }
    var body by remember { mutableStateOf("") }
    var aboutLastTrip by remember { mutableStateOf(false) }
    var file by remember { mutableStateOf<Uri?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val pick = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri -> if (uri != null) file = uri }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding().imePadding(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text(stringResource(R.string.sp_category), color = Pal.ink, fontWeight = FontWeight.Bold)
        Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            help?.categories.orEmpty().forEach { c ->
                val on = c.key == category
                Text(c.label, color = if (on) Color.White else Pal.ink, fontSize = 13.sp, fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.clip(CircleShape).background(if (on) Pal.accent else Pal.card).border(1.dp, if (on) Pal.accent else Pal.line, CircleShape).clickable { category = c.key }.padding(horizontal = 14.dp, vertical = 8.dp))
            }
        }
        DorrField(title, { title = it.take(150) }, placeholder = { Text(stringResource(R.string.sp_subject)) })
        DorrField(body, { body = it.take(5000) }, placeholder = { Text(stringResource(R.string.sp_body)) }, singleLine = false, minLines = 4)
        // D-290: about my last trip.
        Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable { aboutLastTrip = !aboutLastTrip }.padding(6.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(if (aboutLastTrip) Icons.Rounded.CheckCircle else Icons.Rounded.HelpOutline, null, tint = if (aboutLastTrip) Pal.accent else Pal.mut)
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.sp_about_last_trip), color = Pal.ink, fontSize = 13.sp)
        }
        // D-291: a photo or a document.
        Row(Modifier.clip(CircleShape).background(Pal.accent.copy(alpha = 0.1f)).clickable { pick.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }.padding(horizontal = 14.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.AttachFile, null, tint = Pal.accent, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(6.dp))
            Text(stringResource(if (file == null) R.string.sp_attach else R.string.sp_attached), color = Pal.accent, fontWeight = FontWeight.Bold, fontSize = 13.sp)
        }
        Banner(error)
        PrimaryButton(stringResource(R.string.sp_send), enabled = title.isNotBlank() && body.trim().length >= 5, busy = busy) {
            busy = true; error = null
            scope.launch {
                val rideUuid = if (aboutLastTrip) (apiCall { DriverBackend.api.rideHistory(1) } as? ApiResult.Ok)?.data?.items?.firstOrNull()?.uuid else null
                val fields = buildMap {
                    put("title", title.trim().toBody()); put("body", body.trim().toBody()); put("category", category.toBody())
                    rideUuid?.let { put("ride_uuid", it.toBody()) }
                    prefill?.second?.let { put("reference", it.toBody()) }
                }
                when (val r = apiCall { DriverBackend.api.openSupportTicket(fields, file?.let { imagePart(context, it, "image") }) }) {
                    is ApiResult.Ok -> onDone(r.data)
                    is ApiResult.Failed -> error = r.error.displayMessage
                    is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                }
                busy = false
            }
        }
    }
}

/** D-292, D-293: the conversation inside a ticket — my messages and the team's, a photo, close / reopen. */
@Composable
private fun TicketConversation(initial: SupportTicketDto) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var ticket by remember { mutableStateOf(initial) }
    var messages by remember { mutableStateOf<List<SupportMessageDto>>(emptyList()) }
    var text by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    val pick = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        if (uri != null) scope.launch { apiCall { DriverBackend.api.sendSupportMessage(ticket.id, emptyMap(), imagePart(context, uri, "image")) }; messages = (apiCall { DriverBackend.api.supportMessages(ticket.id) } as? ApiResult.Ok)?.data.orEmpty() }
    }
    suspend fun reload() {
        messages = (apiCall { DriverBackend.api.supportMessages(ticket.id) } as? ApiResult.Ok)?.data.orEmpty()
        (apiCall { DriverBackend.api.supportTicket(ticket.id) } as? ApiResult.Ok)?.data?.let { ticket = it }
    }
    LaunchedEffect(ticket.id) {
        reload()
        com.dorr.driver.live.DriverLive.events.collect { e -> if (e.name.startsWith("support")) reload() }
    }
    Column(Modifier.fillMaxSize()) {
        Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(ticket.title, color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 16.sp)
                Text("#${ticket.number}", color = Pal.mut, fontSize = 12.sp)
            }
            val closed = ticket.status == "closed" || ticket.status == "resolved"
            Text(stringResource(if (closed) R.string.sp_reopen else R.string.sp_close), color = Pal.accent, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                modifier = Modifier.clip(CircleShape).clickable {
                    scope.launch { apiCall { DriverBackend.api.setSupportStatus(ticket.id, JsonObject().apply { addProperty("status", if (closed) "reopened" else "closed") }) }; reload() }
                }.padding(horizontal = 10.dp, vertical = 6.dp))
        }
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            messages.forEach { m ->
                val mine = m.sender == "user"
                Box(Modifier.fillMaxWidth(), contentAlignment = if (mine) Alignment.CenterEnd else Alignment.CenterStart) {
                    Column(
                        Modifier.widthIn(max = 300.dp).clip(RoundedCornerShape(16.dp)).background(if (mine) Pal.accent else Pal.card).padding(12.dp),
                    ) {
                        if (!mine) Text(m.agentName ?: stringResource(R.string.sp_team), color = Pal.accent, fontSize = 11.sp, fontWeight = FontWeight.Bold)
                        m.body?.takeIf { it.isNotBlank() }?.let { Text(it, color = if (mine) Color.White else Pal.ink, fontSize = 14.sp) }
                        if (m.imageUrl != null) Text("📎 " + stringResource(R.string.sp_attached), color = if (mine) Color.White else Pal.accent, fontSize = 12.sp)
                        Text(m.createdAt?.take(16)?.replace('T', ' ').orEmpty(), color = (if (mine) Color.White else Pal.mut).copy(alpha = 0.7f), fontSize = 10.sp)
                    }
                }
            }
        }
        if (ticket.acceptsReplies) {
            Row(Modifier.fillMaxWidth().padding(10.dp).navigationBarsPadding().imePadding(), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(42.dp).clip(CircleShape).background(Pal.card).clickable { pick.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }, contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.AttachFile, null, tint = Pal.mut)
                }
                Spacer(Modifier.width(8.dp))
                Box(Modifier.weight(1f)) { DorrField(text, { text = it.take(5000) }, placeholder = { Text(stringResource(R.string.sp_write)) }) }
                Spacer(Modifier.width(8.dp))
                Box(Modifier.size(44.dp).clip(CircleShape).background(if (text.isNotBlank() && !busy) Pal.accent else Pal.line).clickable(enabled = text.isNotBlank() && !busy) {
                    busy = true
                    scope.launch {
                        apiCall { DriverBackend.api.sendSupportMessage(ticket.id, mapOf("body" to text.trim().toBody()), null) }
                        text = ""
                        reload()
                        busy = false
                    }
                }, contentAlignment = Alignment.Center) { Icon(Icons.AutoMirrored.Rounded.Send, null, tint = Color.White) }
            }
        }
    }
}

/** D-294..D-297: riders' things left in my car — answer, agree the return in messages, record it with a photo. */
@Composable
fun LostItemsScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var items by remember { mutableStateOf<List<LostItemDto>?>(null) }
    var open by remember { mutableStateOf<LostItemDto?>(null) }
    suspend fun reload() {
        items = (apiCall { DriverBackend.api.lostItems() } as? ApiResult.Ok)?.data.orEmpty()
        open = open?.let { o -> items?.firstOrNull { it.uuid == o.uuid } }
    }
    LaunchedEffect(Unit) {
        reload()
        com.dorr.driver.live.DriverLive.events.collect { e -> if (e.name.startsWith("lost.")) reload() }
    }
    BackHandler { if (open != null) open = null else onBack() }
    ScreenScaffold(stringResource(R.string.lf_title), onBack = { if (open != null) open = null else onBack() }) {
        val chosen = open
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding().imePadding(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (chosen == null) {
                val list = items
                when {
                    list == null -> CircularProgressIndicator(color = Pal.accent)
                    list.isEmpty() -> Text(stringResource(R.string.lf_none), color = Pal.mut)
                    else -> list.forEach { i ->
                        Row(Modifier.fillMaxWidth().surface(RoundedCornerShape(16.dp)).clickable { open = i }.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                            Icon(Icons.Rounded.Inventory2, null, tint = Pal.accent)
                            Spacer(Modifier.width(10.dp))
                            Column(Modifier.weight(1f)) {
                                Text(i.description, color = Pal.ink, fontWeight = FontWeight.Bold, maxLines = 1)
                                Text(stringResource(R.string.lf_ride, i.ride.number.orEmpty()), color = Pal.mut, fontSize = 12.sp)
                            }
                            Text(stringResource(lostStatus(i.status)), color = Pal.accent, fontSize = 11.sp, fontWeight = FontWeight.Bold)
                        }
                    }
                }
            } else {
                LostItemDetail(chosen, context) { scope.launch { reload() } }
            }
        }
    }
}

@Composable
private fun LostItemDetail(i: LostItemDto, context: Context, onChanged: () -> Unit) {
    val scope = rememberCoroutineScope()
    var plan by remember(i.uuid) { mutableStateOf(i.returnPlan.orEmpty()) }
    var text by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    val pick = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        scope.launch {
            when (val r = apiCall { DriverBackend.api.lostItemReturned(i.uuid, uri?.let { imagePart(context, it, "photo") }) }) {
                is ApiResult.Ok -> onChanged()
                is ApiResult.Failed -> error = r.error.displayMessage
                else -> error = context.getString(R.string.error_offline)
            }
        }
    }
    fun answer(found: Boolean) = scope.launch {
        when (val r = apiCall { DriverBackend.api.answerLostItem(i.uuid, JsonObject().apply { addProperty("found", found); if (plan.isNotBlank()) addProperty("plan", plan.trim()) }) }) {
            is ApiResult.Ok -> onChanged()
            is ApiResult.Failed -> error = r.error.displayMessage
            else -> error = context.getString(R.string.error_offline)
        }
    }
    Column(Modifier.fillMaxWidth().surface(RoundedCornerShape(18.dp)).padding(14.dp)) {
        Text(i.description, color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 17.sp)
        Text(stringResource(R.string.lf_ride, i.ride.number.orEmpty()) + " · " + stringResource(lostStatus(i.status)), color = Pal.mut, fontSize = 12.sp)
    }
    Banner(error)
    if (i.status in setOf("reported", "found", "not_found")) {
        DorrField(plan, { plan = it.take(300) }, placeholder = { Text(stringResource(R.string.lf_plan)) })
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Box(Modifier.weight(1f)) { PrimaryButton(stringResource(R.string.lf_found), color = Pal.success) { answer(true) } }
            Box(Modifier.weight(1f)) { PrimaryButton(stringResource(R.string.lf_not_found), color = Pal.danger) { answer(false) } }
        }
    }
    if (i.status == "found") {
        PrimaryButton(stringResource(R.string.lf_returned), icon = Icons.Rounded.CheckCircle) { pick.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }
    }
    // D-296: messages, never numbers.
    Text(stringResource(R.string.lf_messages), color = Pal.ink, fontWeight = FontWeight.Black)
    i.messages.forEach { m ->
        Box(Modifier.fillMaxWidth(), contentAlignment = if (m.mine) Alignment.CenterEnd else Alignment.CenterStart) {
            Text(m.body, color = if (m.mine) Color.White else Pal.ink, fontSize = 14.sp,
                modifier = Modifier.widthIn(max = 280.dp).clip(RoundedCornerShape(14.dp)).background(if (m.mine) Pal.accent else Pal.card).padding(10.dp))
        }
    }
    if (i.status !in setOf("returned", "closed")) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.weight(1f)) { DorrField(text, { text = it.take(1000) }, placeholder = { Text(stringResource(R.string.sp_write)) }) }
            Spacer(Modifier.width(8.dp))
            Box(Modifier.size(44.dp).clip(CircleShape).background(if (text.isNotBlank()) Pal.accent else Pal.line).clickable(enabled = text.isNotBlank()) {
                scope.launch { apiCall { DriverBackend.api.lostItemMessage(i.uuid, JsonObject().apply { addProperty("body", text.trim()) }) }; text = ""; onChanged() }
            }, contentAlignment = Alignment.Center) { Icon(Icons.AutoMirrored.Rounded.Send, null, tint = Color.White) }
        }
    }
}

private fun lostStatus(s: String): Int = when (s) {
    "found" -> R.string.lf_st_found
    "not_found" -> R.string.lf_st_not_found
    "returned" -> R.string.lf_st_returned
    "closed" -> R.string.lf_st_closed
    else -> R.string.lf_st_reported
}

private fun String.toBody() = toRequestBody("text/plain".toMediaType())

private fun imagePart(context: Context, uri: Uri, name: String): MultipartBody.Part {
    val type = context.contentResolver.getType(uri) ?: "image/jpeg"
    val ext = when { type.contains("png") -> "png"; type.contains("webp") -> "webp"; type.contains("pdf") -> "pdf"; else -> "jpg" }
    val size = runCatching { context.contentResolver.openAssetFileDescriptor(uri, "r")?.use { it.length } }.getOrNull() ?: -1L
    val body = object : okhttp3.RequestBody() {
        override fun contentType() = type.toMediaType()
        override fun contentLength(): Long = size
        override fun writeTo(sink: okio.BufferedSink) { context.contentResolver.openInputStream(uri)?.use { input -> sink.writeAll(input.source()) } }
    }
    return MultipartBody.Part.createFormData(name, "file.$ext", body)
}
