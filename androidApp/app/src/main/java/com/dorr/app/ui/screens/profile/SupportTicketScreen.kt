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
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddPhotoAlternate
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.ConfirmationNumber
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Title
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
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
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.SupportTicketDto
import com.dorr.app.network.apiFailure
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.AccountDark
import com.google.gson.Gson
import java.io.File
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody

/** Which tickets have a support reply the customer has not looked at yet (kept on the phone). */
internal object SupportSeen {
    private const val PREFS = "dorr_support_seen"

    fun mark(context: Context, ticketId: Int, lastMessageAt: String) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString("t$ticketId", lastMessageAt).apply()
    }

    fun hasNewReply(context: Context, ticket: SupportTicketDto): Boolean {
        val last = ticket.lastMessage ?: return false
        if (last.sender != "support") return false
        val seen = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString("t${ticket.id}", null)
        return seen == null || (last.createdAt != null && last.createdAt > seen)
    }
}

/** Copies a picked photo into the cache and wraps it as a multipart part named `image`. */
internal suspend fun cacheImagePart(context: Context, uri: Uri, name: String): MultipartBody.Part? =
    withContext(Dispatchers.IO) {
        val file = File(context.cacheDir, "$name.jpg")
        context.contentResolver.openInputStream(uri)?.use { input -> file.outputStream().use { input.copyTo(it) } }
            ?: return@withContext null
        MultipartBody.Part.createFormData("image", file.name, file.asRequestBody("image/jpeg".toMediaType()))
    }

/** Status colours shared by the list and the conversation: opened blue, reopened teal, resolved green, closed red. */
@Composable
internal fun supportStatusColor(status: String?): Color = when (status) {
    "resolved" -> Color(0xFF16A34A)
    "closed" -> Color(0xFFDC2626)
    "reopened" -> Color(0xFF0891B2)
    else -> settingsAccent()
}

@Composable
private fun supportStatusLabel(status: String?): String = when (status) {
    "opened" -> stringResource(R.string.support_status_opened)
    "reopened" -> stringResource(R.string.support_status_reopened)
    "resolved" -> stringResource(R.string.support_status_resolved)
    "closed" -> stringResource(R.string.support_status_closed)
    else -> status.orEmpty()
}

@Composable
internal fun SupportStatusChip(status: String?) {
    val color = supportStatusColor(status)
    Text(
        supportStatusLabel(status),
        color = color,
        fontSize = 10.sp,
        fontWeight = FontWeight.Bold,
        modifier = Modifier
            .clip(RoundedCornerShape(7.dp))
            .background(color.copy(alpha = 0.13f))
            .padding(horizontal = 8.dp, vertical = 3.dp),
    )
}

/**
 * Support, the whole flow in one place: my tickets (live), opening a new one, and each ticket's
 * conversation. [openTicketId] jumps straight into a ticket (a tapped push notification).
 */
@Composable
fun SupportTicketScreen(
    onBack: () -> Unit,
    openTicketId: Int? = null,
    onTicketOpened: () -> Unit = {},
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val gson = remember { Gson() }
    var creating by remember { mutableStateOf(false) }
    var chat by remember { mutableStateOf<SupportTicketDto?>(null) }
    var tickets by remember { mutableStateOf<List<SupportTicketDto>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    val reconnectTick = collectReconnectTick()
    val replyToast = stringResource(R.string.support_reply_toast)

    BackHandler(enabled = creating || chat != null) {
        if (chat != null) chat = null else creating = false
    }

    fun upsert(ticket: SupportTicketDto) {
        tickets = listOf(ticket) + tickets.filter { it.id != ticket.id }
    }

    suspend fun load(pageToLoad: Int) {
        if (pageToLoad == 1) loading = true else loadingMore = true
        runCatching {
            ApiClient.support.listTickets("Bearer ${AuthSession.token.orEmpty()}", page = pageToLoad)
        }.onSuccess { envelope ->
            val rows = envelope.data.orEmpty()
            tickets = if (pageToLoad == 1) rows else tickets + rows.filter { r -> tickets.none { it.id == r.id } }
            hasMore = envelope.pagination?.hasMorePages == true
            page = pageToLoad
        }
        loading = false
        loadingMore = false
    }

    LaunchedEffect(reconnectTick) { load(1) }

    // A tapped notification: straight into that ticket.
    LaunchedEffect(openTicketId) {
        val id = openTicketId ?: return@LaunchedEffect
        runCatching { ApiClient.support.ticket("Bearer ${AuthSession.token.orEmpty()}", id).data }.getOrNull()?.let {
            upsert(it)
            chat = it
        }
        onTicketOpened()
    }

    // Live: a reply or a status change from support updates the list on its own.
    LaunchedEffect(Unit) {
        ChatRealtime.events.collect { event ->
            if (!event.name.startsWith("support.")) return@collect
            val fresh = runCatching { gson.fromJson(event.data.get("ticket"), SupportTicketDto::class.java) }.getOrNull() ?: return@collect
            upsert(fresh)
            if (event.name == "support.message" && fresh.lastMessage?.sender == "support" && chat?.id != fresh.id) {
                Toast.makeText(context, String.format(replyToast, fresh.id), Toast.LENGTH_SHORT).show()
            }
        }
    }

    chat?.let { current ->
        SupportChatScreen(
            initial = current,
            onBack = { chat = null },
            onTicketChanged = { upsert(it) },
        )
        return
    }

    if (creating) {
        SupportTicketCreateForm(
            onBack = { creating = false },
            onSubmitted = { message, ticket ->
                creating = false
                Toast.makeText(context, message, Toast.LENGTH_SHORT).show()
                if (ticket != null) {
                    upsert(ticket)
                    chat = ticket
                } else {
                    scope.launch { load(1) }
                }
            },
        )
        return
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(R.string.support_ticket_title), onBack)
            when {
                loading && tickets.isEmpty() -> Box(
                    Modifier.weight(1f).fillMaxWidth(),
                    contentAlignment = Alignment.Center,
                ) {
                    CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
                }
                tickets.isEmpty() -> Box(Modifier.weight(1f)) {
                    SupportTicketEmpty { creating = true }
                }
                else -> {
                    LazyColumn(
                        modifier = Modifier.weight(1f),
                        contentPadding = PaddingValues(start = 14.dp, end = 14.dp, bottom = 16.dp),
                        verticalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        items(tickets, key = { it.id }) { ticket ->
                            SupportTicketCard(ticket, onOpen = { chat = ticket }, modifier = Modifier.animateItem())
                        }
                        if (hasMore) {
                            item("more") {
                                Box(
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .clip(RoundedCornerShape(14.dp))
                                        .clickable(enabled = !loadingMore) { scope.launch { load(page + 1) } }
                                        .padding(vertical = 12.dp),
                                    contentAlignment = Alignment.Center,
                                ) {
                                    if (loadingMore) {
                                        CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
                                    } else {
                                        Text(
                                            stringResource(R.string.support_ticket_more),
                                            color = settingsAccent(),
                                            fontWeight = FontWeight.Bold,
                                            fontSize = 14.sp,
                                        )
                                    }
                                }
                            }
                        }
                    }
                    SettingsPrimaryButton(
                        text = stringResource(R.string.support_ticket_new),
                        modifier = Modifier.padding(horizontal = 14.dp).padding(bottom = 18.dp),
                    ) { creating = true }
                }
            }
        }
    }
}

@Composable
private fun SupportTicketEmpty(onCreate: () -> Unit) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(horizontal = 28.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        PinkIcon(Icons.Rounded.ConfirmationNumber, 56.dp)
        Spacer(Modifier.height(16.dp))
        Text(
            stringResource(R.string.support_ticket_empty),
            color = settingsInk(),
            fontSize = 18.sp,
            fontWeight = FontWeight.ExtraBold,
        )
        Spacer(Modifier.height(6.dp))
        Text(
            stringResource(R.string.support_ticket_empty_sub),
            color = settingsMut(),
            fontSize = 14.sp,
            lineHeight = 20.sp,
        )
        Spacer(Modifier.height(22.dp))
        SettingsPrimaryButton(text = stringResource(R.string.support_ticket_new), onClick = onCreate)
    }
}

@Composable
private fun SupportTicketCard(ticket: SupportTicketDto, onOpen: () -> Unit, modifier: Modifier = Modifier) {
    val context = LocalContext.current
    val shape = RoundedCornerShape(16.dp)
    val color = supportStatusColor(ticket.status)
    val unread = SupportSeen.hasNewReply(context, ticket)
    Row(
        modifier = modifier
            .fillMaxWidth()
            .settingsSurface(shape)
            .clip(shape)
            .clickable(onClick = onOpen),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        // The status colour runs down the card's edge.
        Box(Modifier.width(4.dp).fillMaxHeight().background(color))
        Column(Modifier.weight(1f).padding(horizontal = 12.dp, vertical = 10.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    stringResource(R.string.support_ticket_number, ticket.id),
                    color = settingsMut(),
                    fontSize = 11.sp,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.weight(1f),
                )
                SupportStatusChip(ticket.status)
            }
            Spacer(Modifier.height(3.dp))
            Text(
                ticket.title.orEmpty(),
                color = settingsInk(),
                fontSize = 15.sp,
                fontWeight = FontWeight.Bold,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            val last = ticket.lastMessage
            if (last != null) {
                Spacer(Modifier.height(3.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    if (last.body.isNullOrBlank() && last.hasImage) {
                        Icon(Icons.Rounded.Image, null, tint = settingsMut(), modifier = Modifier.size(14.dp).padding(end = 2.dp))
                    }
                    Text(
                        (when (last.sender) {
                            "support" -> stringResource(R.string.support_agent_prefix)
                            "system" -> stringResource(R.string.support_auto_prefix)
                            else -> ""
                        }) +
                            (last.body?.takeIf { it.isNotBlank() } ?: stringResource(R.string.support_photo)),
                        color = if (unread) settingsInk() else settingsMut(),
                        fontSize = 12.sp,
                        fontWeight = if (unread) FontWeight.Bold else FontWeight.Normal,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f),
                    )
                }
            }
            ticketWhen(ticket.lastMessageAt ?: ticket.createdAt)?.let { whenText ->
                Spacer(Modifier.height(3.dp))
                Text(whenText, color = settingsMut(), fontSize = 11.sp)
            }
        }
        if (unread) {
            Box(Modifier.padding(end = 14.dp).size(10.dp).clip(CircleShape).background(settingsAccent()))
        }
    }
}

private fun ticketWhen(iso: String?): String? {
    val instant = runCatching { Instant.parse(iso) }.getOrNull()
        ?: runCatching { java.time.OffsetDateTime.parse(iso).toInstant() }.getOrNull()
        ?: return null
    val zoned = instant.atZone(ZoneId.systemDefault())
    return DateTimeFormatter.ofLocalizedDateTime(FormatStyle.MEDIUM, FormatStyle.SHORT).format(zoned)
}

@Composable
private fun SupportTicketCreateForm(
    onBack: () -> Unit,
    onSubmitted: (String, SupportTicketDto?) -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var title by remember { mutableStateOf("") }
    var body by remember { mutableStateOf("") }
    var imageUri by remember { mutableStateOf<Uri?>(null) }
    var sending by remember { mutableStateOf(false) }
    val needFields = stringResource(R.string.support_ticket_need_fields)
    val failed = stringResource(R.string.support_ticket_failed)
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        imageUri = uri
    }
    val fieldShape = RoundedCornerShape(20.dp)

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(R.string.support_ticket_new), onBack)
            Column(
                modifier = Modifier
                    .weight(1f)
                    .verticalScroll(rememberScrollState())
                    .padding(horizontal = 14.dp)
                    .padding(bottom = 24.dp),
            ) {
                Text(
                    stringResource(R.string.support_ticket_intro),
                    color = settingsMut(),
                    fontSize = 14.sp,
                    lineHeight = 20.sp,
                    modifier = Modifier.padding(bottom = 16.dp),
                )
                DorrTextField(
                    value = title,
                    onValueChange = { if (it.length <= 120) title = it },
                    label = stringResource(R.string.support_ticket_subject),
                    placeholder = stringResource(R.string.support_ticket_subject_hint),
                    icon = Icons.Rounded.Title,
                    enabled = !sending,
                )
                Spacer(Modifier.height(14.dp))
                DorrTextField(
                    value = body,
                    onValueChange = { if (it.length <= 4000) body = it },
                    label = stringResource(R.string.support_ticket_body),
                    placeholder = stringResource(R.string.support_ticket_body_hint),
                    icon = Icons.Rounded.Notes,
                    singleLine = false,
                    minLines = 5,
                    minHeight = 140.dp,
                    enabled = !sending,
                )
                Spacer(Modifier.height(14.dp))
                Text(
                    stringResource(R.string.support_ticket_photo),
                    color = settingsInk(),
                    fontSize = 13.sp,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier.padding(bottom = 8.dp),
                )
                if (imageUri == null) {
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .clip(fieldShape)
                            .background(if (settingsNight()) AccountDark.bg else Color(0xFFFBF7F8))
                            .border(
                                1.dp,
                                if (settingsNight()) AccountDark.line else Color(0xFFF3D5DB),
                                fieldShape,
                            )
                            .clickable(enabled = !sending) {
                                picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly))
                            }
                            .padding(horizontal = 14.dp, vertical = 12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(
                            Icons.Rounded.AddPhotoAlternate,
                            contentDescription = null,
                            tint = settingsAccent(),
                            modifier = Modifier.size(20.dp),
                        )
                        Spacer(Modifier.width(12.dp))
                        Text(
                            stringResource(R.string.support_ticket_photo_add),
                            color = settingsMut(),
                            fontSize = 14.sp,
                        )
                    }
                } else {
                    Box(
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(160.dp)
                            .clip(fieldShape),
                    ) {
                        AsyncImage(
                            model = imageUri,
                            contentDescription = null,
                            contentScale = ContentScale.Crop,
                            modifier = Modifier.fillMaxSize(),
                        )
                        Box(
                            modifier = Modifier
                                .align(Alignment.TopEnd)
                                .padding(8.dp)
                                .size(28.dp)
                                .clip(RoundedCornerShape(8.dp))
                                .background(Color.Black.copy(alpha = 0.5f))
                                .clickable(enabled = !sending) { imageUri = null },
                            contentAlignment = Alignment.Center,
                        ) {
                            Icon(Icons.Rounded.Close, contentDescription = null, tint = Color.White, modifier = Modifier.size(16.dp))
                        }
                    }
                }
                Spacer(Modifier.height(22.dp))
                SettingsPrimaryButton(
                    text = stringResource(R.string.support_ticket_send),
                    loading = sending,
                    enabled = !sending,
                ) {
                    if (title.isBlank() || body.isBlank()) {
                        Toast.makeText(context, needFields, Toast.LENGTH_SHORT).show()
                        return@SettingsPrimaryButton
                    }
                    sending = true
                    scope.launch {
                        try {
                            val text = "text/plain".toMediaType()
                            val imagePart = imageUri?.let { cacheImagePart(context, it, "support-ticket") }
                            val response = ApiClient.support.createTicket(
                                "Bearer ${AuthSession.token.orEmpty()}",
                                title.trim().toRequestBody(text),
                                body.trim().toRequestBody(text),
                                imagePart,
                            )
                            onSubmitted(
                                response.message.ifBlank { context.getString(R.string.support_ticket_sent) },
                                response.data,
                            )
                        } catch (e: Exception) {
                            Toast.makeText(context, e.apiFailure().message ?: failed, Toast.LENGTH_LONG).show()
                        } finally {
                            sending = false
                        }
                    }
                }
            }
        }
    }
}
