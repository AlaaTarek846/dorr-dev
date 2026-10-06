package com.dorr.app.ui.screens.profile

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
import androidx.compose.material.icons.rounded.Chat
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.ConfirmationNumber
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
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.SupportTicketDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.network.serverMessage
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.AccountDark
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

@Composable
fun SupportTicketScreen(
    onBack: () -> Unit,
    onOpenChat: (SupportTicketDto) -> Unit = {},
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var creating by remember { mutableStateOf(false) }
    var tickets by remember { mutableStateOf<List<SupportTicketDto>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    val reconnectTick = collectReconnectTick()

    BackHandler(enabled = creating) { creating = false }

    suspend fun load(pageToLoad: Int) {
        if (pageToLoad == 1) loading = true else loadingMore = true
        runCatching {
            ApiClient.support.listTickets("Bearer ${AuthSession.token.orEmpty()}", page = pageToLoad)
        }.onSuccess { envelope ->
            val rows = envelope.data.orEmpty()
            tickets = if (pageToLoad == 1) rows else tickets + rows
            hasMore = envelope.pagination?.hasMorePages == true
            page = pageToLoad
        }
        loading = false
        loadingMore = false
    }

    LaunchedEffect(reconnectTick) { load(1) }

    if (creating) {
        SupportTicketCreateForm(
            onBack = { creating = false },
            onSubmitted = { message, ticket ->
                creating = false
                ticket?.let { created ->
                    tickets = listOf(created) + tickets.filter { it.id != created.id }
                }
                Toast.makeText(context, message, Toast.LENGTH_SHORT).show()
                scope.launch { load(1) }
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
                tickets.isEmpty() -> Box(Modifier.weight(1f).fillMaxWidth()) {
                    SupportTicketEmpty { creating = true }
                }
                else -> {
                    LazyColumn(
                        modifier = Modifier.weight(1f),
                        contentPadding = PaddingValues(start = 14.dp, end = 14.dp, bottom = 16.dp),
                        verticalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        items(tickets, key = { it.id }) { ticket ->
                            SupportTicketCard(ticket, onOpenChat = onOpenChat)
                        }
                        if (hasMore) {
                            item("more") {
                                Box(
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .clip(RoundedCornerShape(14.dp))
                                        .clickable(enabled = !loadingMore) {
                                            scope.launch { load(page + 1) }
                                        }
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
private fun SupportTicketCard(ticket: SupportTicketDto, onOpenChat: (SupportTicketDto) -> Unit) {
    val shape = RoundedCornerShape(16.dp)
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .then(Modifier.settingsSurface(shape))
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text(
                stringResource(R.string.support_ticket_number, ticket.id),
                color = settingsMut(),
                fontSize = 11.sp,
                fontWeight = FontWeight.SemiBold,
            )
            Spacer(Modifier.height(2.dp))
            Text(
                ticket.title.orEmpty(),
                color = settingsInk(),
                fontSize = 15.sp,
                fontWeight = FontWeight.Bold,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            ticketWhen(ticket.createdAt)?.let { whenText ->
                Spacer(Modifier.height(2.dp))
                Text(whenText, color = settingsMut(), fontSize = 11.sp)
            }
        }
        Spacer(Modifier.width(10.dp))
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Text(
                statusLabel(ticket.status),
                color = settingsAccent(),
                fontSize = 10.sp,
                fontWeight = FontWeight.Bold,
                modifier = Modifier
                    .clip(RoundedCornerShape(7.dp))
                    .background(settingsAccent().copy(alpha = 0.12f))
                    .padding(horizontal = 7.dp, vertical = 3.dp),
            )
            Spacer(Modifier.height(6.dp))
            Box(
                modifier = Modifier
                    .size(36.dp)
                    .clip(CircleShape)
                    .background(if (settingsNight()) AccountDark.well else settingsAccent().copy(alpha = 0.14f))
                    .clickable(onClick = { onOpenChat(ticket) }),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    Icons.Rounded.Chat,
                    contentDescription = stringResource(R.string.support_ticket_chat),
                    tint = settingsAccent(),
                    modifier = Modifier.size(18.dp),
                )
            }
        }
    }
}

@Composable
private fun statusLabel(status: String?): String = when (status) {
    "open" -> stringResource(R.string.support_ticket_status_open)
    else -> status.orEmpty()
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
                            val imagePart = imageUri?.let { uri ->
                                withContext(Dispatchers.IO) {
                                    val file = File(context.cacheDir, "support-ticket.jpg")
                                    context.contentResolver.openInputStream(uri)?.use { input ->
                                        file.outputStream().use { input.copyTo(it) }
                                    } ?: return@withContext null
                                    MultipartBody.Part.createFormData(
                                        "image",
                                        file.name,
                                        file.asRequestBody("image/jpeg".toMediaType()),
                                    )
                                }
                            }
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
                            Toast.makeText(context, e.serverMessage() ?: failed, Toast.LENGTH_LONG).show()
                        } finally {
                            sending = false
                        }
                    }
                }
            }
        }
    }
}
