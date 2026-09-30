package com.dorr.app.ui.screens.aichat

import android.content.Intent
import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ArrowBackIosNew
import androidx.compose.material.icons.rounded.AttachFile
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.DownloadForOffline
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.WorkspacePremium
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.AiMessageDto
import com.dorr.app.network.AiUsageDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.chat.VoicePlayer
import com.dorr.app.ui.screens.chat.LocalFile
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.screens.chat.chatImages
import com.dorr.app.ui.screens.chat.copyToCache
import com.dorr.app.ui.screens.chat.durationText
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.util.UUID

/**
 * The AI Assistant conversation: message thread + composer, talking to
 * /api/user/v1/ai-chat/conversations/{id}/messages (Modules/AI's AiChatController).
 */
@Composable
internal fun AiConversationPage(
    conversationId: Int,
    night: Boolean,
    onExit: () -> Unit,
    onOpenHistory: () -> Unit,
    onNewChat: () -> Unit,
    onOpenVoice: () -> Unit,
    onOpenSubscription: () -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val listState = rememberLazyListState()

    val bg = if (night) Ai.bgDark else Ai.bgLight
    val ink = if (night) Ai.inkDark else Ai.inkLight
    val mut = if (night) Ai.mutDark else Ai.mutLight
    val surface = if (night) Ai.surfaceDark else Ai.surfaceLight

    var title by remember(conversationId) { mutableStateOf<String?>(null) }
    var messages by remember(conversationId) { mutableStateOf<List<AiMessageDto>>(emptyList()) }
    var loading by remember(conversationId) { mutableStateOf(true) }
    var loadError by remember(conversationId) { mutableStateOf<String?>(null) }
    var usage by remember(conversationId) { mutableStateOf<AiUsageDto?>(null) }

    var input by rememberSaveable(conversationId) { mutableStateOf("") }
    var pendingAttachment by remember(conversationId) { mutableStateOf<LocalFile?>(null) }
    var sending by remember(conversationId) { mutableStateOf(false) }
    var sendError by remember(conversationId) { mutableStateOf<String?>(null) }
    var idempotencyKey by remember(conversationId) { mutableStateOf(UUID.randomUUID().toString()) }
    var attachSheet by remember { mutableStateOf(false) }

    // Real, observed UX gap (2026-09-29): the composer's single static
    // "Thinking..." bubble never changed for the whole wait, including a
    // 30+ second image-generation call - nothing on screen showed the
    // request was still alive, so a user assumed the app had frozen and
    // tapped send again, colliding with the still-in-flight request's
    // idempotency key and getting a confusing rejection. typingElapsedMs
    // ticks up every second while `sending` is true (see the
    // LaunchedEffect below) and typingIsImageLike flags whether this
    // particular send looks like an image request, so AiTypingBubble can
    // narrate progress the way a normal AI chat does instead of sitting
    // frozen on one label the whole time.
    var typingElapsedMs by remember(conversationId) { mutableStateOf(0L) }
    var typingIsImageLike by remember(conversationId) { mutableStateOf(false) }

    LaunchedEffect(sending) {
        typingElapsedMs = 0L
        while (sending) {
            kotlinx.coroutines.delay(1000)
            typingElapsedMs += 1000
        }
    }

    suspend fun load() {
        loading = true
        loadError = null
        runCatching { ApiClient.aiChat.conversation(chatAuth(), conversationId).data }
            .onSuccess { conv ->
                title = conv?.title
                messages = conv?.messages.orEmpty().sortedBy { it.id }
            }
            .onFailure { loadError = it.apiFailure().message }
        runCatching { ApiClient.aiChat.usage(chatAuth()).data }.onSuccess { usage = it }
        loading = false
    }

    LaunchedEffect(conversationId) { load() }

    LaunchedEffect(messages.size, sending) {
        if (messages.isNotEmpty() || sending) runCatching { listState.animateScrollToItem(0) }
    }

    val sendFailedLabel = stringResource(R.string.ai_send_failed)
    val attachmentCaption = stringResource(R.string.ai_attachment_caption)
    val voiceCaption = stringResource(R.string.ai_voice_message_caption)

    // Shared by the composer's Send button and the mic's auto-send-on-stop — same request,
    // same failure handling, same idempotency key discipline either way it was triggered.
    fun sendPayload(typed: String, attachment: LocalFile?, restoreOnFailure: Boolean) {
        if (typed.isEmpty() && attachment == null) return
        if (sending) return
        if (usage?.allowed == false) return

        // The backend requires a non-empty `message` even on an attachment-only send
        // (AiChatMessageRequest: 'message' => 'required') — a short, honest caption
        // instead of silently failing or inventing a description of the file's content.
        val isVoice = attachment != null && attachment.mime.startsWith("audio/")
        val text = typed.ifEmpty { if (isVoice) voiceCaption else attachmentCaption }

        val imageKeywords = listOf("صورة", "صوره", "ارسم", "image", "picture", "photo", "draw")
        typingIsImageLike = attachment?.mime?.startsWith("image/") == true ||
            imageKeywords.any { typed.contains(it, ignoreCase = true) }

        sending = true
        sendError = null
        val key = idempotencyKey

        scope.launch {
            val fields = mapOf("message" to text.toRequestBody("text/plain".toMediaTypeOrNull()))
            val part = attachment?.let {
                MultipartBody.Part.createFormData("attachment", it.name, it.file.asRequestBody(it.mime.toMediaTypeOrNull()))
            }
            val result = runCatching { ApiClient.aiChat.sendMessage(chatAuth(), key, conversationId, fields, part) }
            result.onSuccess { envelope ->
                val data = envelope.data
                if (envelope.success && data != null) {
                    val merged = (messages + listOfNotNull(data.userMessage, data.assistantMessage))
                        .distinctBy { it.id }
                        .sortedBy { it.id }
                    messages = merged
                    data.conversation?.title?.let { title = it }
                    data.usage?.let { usage = it }
                    idempotencyKey = UUID.randomUUID().toString()
                } else {
                    sendError = envelope.message
                    if (restoreOnFailure) { input = typed; pendingAttachment = attachment }
                }
            }.onFailure {
                sendError = it.apiFailure().message ?: sendFailedLabel
                if (restoreOnFailure) { input = typed; pendingAttachment = attachment }
            }
            sending = false
        }
    }

    fun send() {
        val typed = input.trim()
        val attachment = pendingAttachment
        input = ""
        pendingAttachment = null
        sendPayload(typed, attachment, restoreOnFailure = true)
    }

    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        if (uri == null) return@rememberLauncherForActivityResult
        pendingAttachment = copyToCache(context, uri, "photo.jpg")
    }
    val document = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        if (uri == null) return@rememberLauncherForActivityResult
        pendingAttachment = copyToCache(context, uri, "document")
    }

    // ---------------------------------------------------------------- voice note
    val recorder = remember { com.dorr.app.chat.VoiceRecorder(context) }
    val micPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) recorder.start()
    }
    fun startVoiceRecording() {
        val granted = androidx.core.content.ContextCompat.checkSelfPermission(context, android.Manifest.permission.RECORD_AUDIO) ==
            android.content.pm.PackageManager.PERMISSION_GRANTED
        if (granted) recorder.start() else micPermission.launch(android.Manifest.permission.RECORD_AUDIO)
    }
    fun stopAndSendVoice(cancelled: Boolean) {
        if (cancelled) {
            recorder.cancel()
            return
        }
        val clip = recorder.finish() ?: return
        sendPayload("", LocalFile(clip.file, "audio/mp4", clip.file.name), restoreOnFailure = false)
    }

    Column(Modifier.fillMaxSize().background(bg)) {
        // ---------------------------------------------------------------- top bar
        Row(
            Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 8.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(Modifier.size(38.dp).clip(CircleShape).clickable(onClick = onExit), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.ArrowBackIosNew, contentDescription = null, tint = ink, modifier = Modifier.size(18.dp))
            }
            Box(
                Modifier.size(34.dp).clip(CircleShape).background(Ai.Red),
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.AutoAwesome, contentDescription = null, tint = Color.White, modifier = Modifier.size(16.dp)) }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(
                    title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.ai_title),
                    color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp,
                    maxLines = 1, overflow = TextOverflow.Ellipsis,
                )
                Text(stringResource(R.string.ai_subtitle), color = mut, fontSize = 11.sp)
            }
            Box(Modifier.size(38.dp).clip(CircleShape).clickable(onClick = onNewChat), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.AutoAwesome, contentDescription = stringResource(R.string.ai_new_chat), tint = mut, modifier = Modifier.size(18.dp))
            }
            Box(Modifier.size(38.dp).clip(CircleShape).clickable(onClick = onOpenVoice), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Call, contentDescription = stringResource(R.string.ai_voice_mode), tint = mut, modifier = Modifier.size(18.dp))
            }
            Box(Modifier.size(38.dp).clip(CircleShape).clickable(onClick = onOpenHistory), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.History, contentDescription = stringResource(R.string.ai_history_title), tint = mut, modifier = Modifier.size(19.dp))
            }
            Box(Modifier.size(38.dp).clip(CircleShape).clickable(onClick = onOpenSubscription), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.WorkspacePremium, contentDescription = stringResource(R.string.ai_subscription_manage), tint = mut, modifier = Modifier.size(19.dp))
            }
        }

        val blocked = usage?.allowed == false
        if (blocked) {
            val offersSubscribe = usage?.reason in setOf("trial_ended", "no_plan", "subscription_suspended")
            Row(
                Modifier.fillMaxWidth().background(Color(0xFFFEE2E2)).padding(horizontal = 16.dp, vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Lock, contentDescription = null, tint = Color(0xFFB91C1C), modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(8.dp))
                Text(aiUsageReasonText(usage?.reason), color = Color(0xFFB91C1C), fontSize = 12.5.sp, modifier = Modifier.weight(1f))
                if (offersSubscribe) {
                    Text(
                        stringResource(R.string.ai_subscription_subscribe),
                        color = Color(0xFFB91C1C),
                        fontWeight = FontWeight.Bold,
                        fontSize = 12.5.sp,
                        modifier = Modifier.clickable(onClick = onOpenSubscription),
                    )
                }
            }
        } else if (usage?.planIsTrial == true && (usage?.remainingSeconds ?: 0) > 0) {
            Row(
                Modifier.fillMaxWidth().background(Ai.Red.copy(alpha = 0.08f)).padding(horizontal = 16.dp, vertical = 7.dp),
            ) {
                Text(
                    stringResource(R.string.ai_trial_remaining, ((usage?.remainingSeconds ?: 0) / 60).toInt()),
                    color = Ai.Red, fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold,
                )
            }
        }

        // ---------------------------------------------------------------- messages
        Box(Modifier.weight(1f).fillMaxWidth()) {
            when {
                loading && messages.isEmpty() -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = Ai.Red, strokeWidth = 2.5.dp, modifier = Modifier.size(28.dp))
                }
                loadError != null && messages.isEmpty() -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Text(loadError ?: "", color = mut, fontSize = 13.sp)
                }
                messages.isEmpty() -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Column(horizontalAlignment = Alignment.CenterHorizontally) {
                        Icon(Icons.Rounded.AutoAwesome, contentDescription = null, tint = Ai.Red.copy(alpha = 0.5f), modifier = Modifier.size(40.dp))
                        Spacer(Modifier.height(10.dp))
                        Text(stringResource(R.string.ai_empty_conversation), color = mut, fontSize = 13.sp)
                    }
                }
                else -> LazyColumn(
                    state = listState,
                    reverseLayout = true,
                    modifier = Modifier.fillMaxSize(),
                    contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 12.dp, vertical = 10.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    if (sending) item(key = "typing") { AiTypingBubble(surface, mut, typingElapsedMs, typingIsImageLike) }
                    items(messages.asReversed(), key = { it.id }) { message ->
                        AiMessageBubble(message = message, night = night, ink = ink, mut = mut, surface = surface, onOpenUrl = { url ->
                            runCatching {
                                context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(ApiClient.mediaUrl(url))))
                            }
                        })
                    }
                }
            }
        }

        if (sendError != null) {
            Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp)) {
                Text(sendError ?: "", color = Color(0xFFB91C1C), fontSize = 12.sp, modifier = Modifier.weight(1f))
            }
        }

        // ---------------------------------------------------------------- composer
        pendingAttachment?.let { file ->
            Row(
                Modifier.fillMaxWidth().padding(start = 14.dp, top = 6.dp, end = 14.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Row(
                    Modifier.clip(RoundedCornerShape(10.dp)).background(surface).padding(horizontal = 10.dp, vertical = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(
                        if (file.mime.startsWith("image/")) Icons.Rounded.Image else Icons.Rounded.Description,
                        contentDescription = null, tint = Ai.Red, modifier = Modifier.size(16.dp),
                    )
                    Spacer(Modifier.width(6.dp))
                    Text(file.name, color = ink, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.widthIn(max = 160.dp))
                    Spacer(Modifier.width(6.dp))
                    Icon(
                        Icons.Rounded.Close, contentDescription = null, tint = mut,
                        modifier = Modifier.size(14.dp).clickable { pendingAttachment = null },
                    )
                }
            }
        }

        if (recorder.recording) {
            Row(
                Modifier.fillMaxWidth().navigationBarsPadding().imePadding().padding(10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(
                    Modifier.size(42.dp).clip(CircleShape).clickable { stopAndSendVoice(cancelled = true) },
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.Rounded.Close, contentDescription = stringResource(R.string.ai_cancel_recording), tint = mut, modifier = Modifier.size(20.dp)) }

                Row(
                    Modifier.weight(1f).heightIn(min = 42.dp).clip(RoundedCornerShape(21.dp)).background(surface).padding(horizontal = 16.dp, vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Box(Modifier.size(9.dp).clip(CircleShape).background(Color(0xFFDC2626)))
                    Spacer(Modifier.width(8.dp))
                    val seconds = recorder.elapsedMs / 1000
                    Text(String.format("%d:%02d", seconds / 60, seconds % 60), color = ink, fontSize = 14.sp)
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(R.string.ai_recording_hint), color = mut, fontSize = 12.sp)
                }

                Spacer(Modifier.width(8.dp))

                Box(
                    Modifier.size(42.dp).clip(CircleShape).background(Ai.Red).clickable { stopAndSendVoice(cancelled = false) },
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.Rounded.Send, contentDescription = stringResource(R.string.ai_stop_recording), tint = Color.White, modifier = Modifier.size(18.dp)) }
            }
        } else {
            Row(
                Modifier.fillMaxWidth().navigationBarsPadding().imePadding().padding(10.dp),
                verticalAlignment = Alignment.Bottom,
            ) {
                Box(
                    Modifier.size(42.dp).clip(CircleShape).clickable { attachSheet = true },
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.Rounded.AttachFile, contentDescription = stringResource(R.string.ai_attach), tint = mut, modifier = Modifier.size(20.dp)) }

                Box(
                    Modifier.weight(1f).heightIn(min = 42.dp).clip(RoundedCornerShape(21.dp)).background(surface).padding(horizontal = 16.dp, vertical = 10.dp),
                ) {
                    if (input.isEmpty()) Text(stringResource(R.string.ai_composer_hint), color = mut, fontSize = 14.sp)
                    BasicTextField(
                        value = input,
                        onValueChange = { input = it },
                        textStyle = androidx.compose.ui.text.TextStyle(color = ink, fontSize = 14.sp),
                        cursorBrush = androidx.compose.ui.graphics.SolidColor(Ai.Red),
                        modifier = Modifier.fillMaxWidth(),
                    )
                }

                Spacer(Modifier.width(8.dp))

                val hasContent = input.isNotBlank() || pendingAttachment != null
                val canSend = !sending && !blocked && hasContent
                Box(
                    Modifier.size(42.dp).clip(CircleShape)
                        .background(if (hasContent) (if (canSend) Ai.Red else mut.copy(alpha = 0.25f)) else if (blocked) mut.copy(alpha = 0.25f) else Ai.Red)
                        .clickable(enabled = if (hasContent) canSend else !sending && !blocked) {
                            if (hasContent) send() else startVoiceRecording()
                        },
                    contentAlignment = Alignment.Center,
                ) {
                    if (sending) CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
                    else if (hasContent) Icon(Icons.Rounded.Send, contentDescription = stringResource(R.string.ai_send), tint = Color.White, modifier = Modifier.size(18.dp))
                    else Icon(Icons.Rounded.Mic, contentDescription = stringResource(R.string.ai_record_voice), tint = Color.White, modifier = Modifier.size(20.dp))
                }
            }
        }
    }

    if (attachSheet) {
        AiAttachSheet(
            night = night,
            onPickImage = { attachSheet = false; gallery.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) },
            onPickDocument = {
                attachSheet = false
                // Matches AiChatMessageRequest's mimes:...,xlsx rule exactly — no legacy
                // .xls, which that validation rule does not accept, so a picked one would
                // just fail on send instead of ever working.
                document.launch(arrayOf(
                    "application/pdf", "application/msword",
                    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
                    "text/plain", "text/csv",
                    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
                ))
            },
            onDismiss = { attachSheet = false },
        )
    }
}

@Composable
private fun aiUsageReasonText(reason: String?): String = when (reason) {
    "trial_ended" -> stringResource(R.string.ai_usage_trial_ended)
    "subscription_suspended" -> stringResource(R.string.ai_subscription_suspended_banner)
    "cooldown" -> stringResource(R.string.ai_usage_cooldown)
    "limit_reached" -> stringResource(R.string.ai_usage_limit_reached)
    "blocked" -> stringResource(R.string.ai_usage_blocked)
    "no_plan" -> stringResource(R.string.ai_usage_no_plan)
    else -> stringResource(R.string.ai_usage_unavailable)
}

@Composable
private fun AiMessageBubble(
    message: AiMessageDto,
    night: Boolean,
    ink: Color,
    mut: Color,
    surface: Color,
    onOpenUrl: (String) -> Unit,
) {
    val isUser = message.isUser
    Row(Modifier.fillMaxWidth(), horizontalArrangement = if (isUser) Arrangement.End else Arrangement.Start) {
        Column(
            Modifier
                .widthIn(max = 300.dp)
                .clip(RoundedCornerShape(topStart = 18.dp, topEnd = 18.dp, bottomStart = if (isUser) 18.dp else 4.dp, bottomEnd = if (isUser) 4.dp else 18.dp))
                .background(
                    when {
                        message.isError -> Color(0xFFFEE2E2)
                        isUser -> Ai.Red
                        else -> surface
                    },
                )
                .padding(horizontal = 14.dp, vertical = 10.dp),
        ) {
            message.attachments.orEmpty().forEach { attachment ->
                if (attachment.isImage && attachment.url != null) {
                    AsyncImage(
                        model = ApiClient.mediaUrl(attachment.url),
                        imageLoader = chatImages(LocalContext.current),
                        contentDescription = attachment.fileName,
                        contentScale = androidx.compose.ui.layout.ContentScale.Crop,
                        modifier = Modifier
                            .heightIn(max = 180.dp)
                            .clip(RoundedCornerShape(12.dp))
                            .clickable { attachment.url?.let(onOpenUrl) }
                            .padding(bottom = 6.dp),
                    )
                } else if (attachment.url != null && attachment.mimeType?.startsWith("audio/") == true) {
                    val rawAudioUrl = attachment.url
                    val audioUrl = ApiClient.mediaUrl(rawAudioUrl) ?: rawAudioUrl
                    val playing = VoicePlayer.isPlaying(audioUrl)
                    val current = VoicePlayer.playingId == audioUrl
                    val progress = if (current) VoicePlayer.progress else 0f
                    Row(
                        Modifier
                            .widthIn(min = 160.dp)
                            .clip(RoundedCornerShape(20.dp))
                            .background(if (isUser) Color.White.copy(alpha = 0.16f) else Color.Black.copy(alpha = 0.06f))
                            .clickable { VoicePlayer.toggle(audioUrl, audioUrl) }
                            .padding(horizontal = 10.dp, vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(
                            if (playing) Icons.Rounded.Pause else Icons.Rounded.PlayArrow,
                            contentDescription = stringResource(if (playing) R.string.ai_stop_recording else R.string.ai_record_voice),
                            tint = if (isUser) Color.White else Ai.Red,
                            modifier = Modifier.size(20.dp),
                        )
                        Spacer(Modifier.width(8.dp))
                        Column(Modifier.width(120.dp)) {
                            LinearProgressIndicator(
                                progress = { progress },
                                modifier = Modifier.fillMaxWidth().height(3.dp).clip(RoundedCornerShape(2.dp)),
                                color = if (isUser) Color.White else Ai.Red,
                                trackColor = if (isUser) Color.White.copy(alpha = 0.3f) else Color.Black.copy(alpha = 0.12f),
                            )
                            Spacer(Modifier.height(4.dp))
                            Text(
                                durationText(if (current) VoicePlayer.positionMs else 0L),
                                color = if (isUser) Color.White.copy(alpha = 0.85f) else mut,
                                fontSize = 11.sp,
                            )
                        }
                    }
                    Spacer(Modifier.height(6.dp))
                } else if (attachment.url != null) {
                    Row(
                        Modifier.clip(RoundedCornerShape(10.dp)).background(Color.Black.copy(alpha = 0.06f))
                            .clickable { attachment.url?.let(onOpenUrl) }
                            .padding(horizontal = 10.dp, vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Rounded.Description, contentDescription = null, tint = if (isUser) Color.White else Ai.Red, modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(6.dp))
                        Text(attachment.fileName ?: "", color = if (isUser) Color.White else ink, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                    Spacer(Modifier.height(6.dp))
                }
            }

            if (message.content.isNotBlank()) {
                Text(
                    message.content,
                    color = if (message.isError) Color(0xFFB91C1C) else if (isUser) Color.White else ink,
                    fontSize = 14.sp,
                    lineHeight = 20.sp,
                )
            }

            message.generatedFile?.let { file ->
                if (file.url != null) {
                    Row(
                        Modifier.clip(RoundedCornerShape(10.dp)).background(Color.Black.copy(alpha = 0.06f))
                            .clickable { onOpenUrl(file.url) }
                            .padding(horizontal = 10.dp, vertical = 8.dp)
                            .padding(top = 6.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Rounded.DownloadForOffline, contentDescription = null, tint = if (isUser) Color.White else Ai.Red, modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(6.dp))
                        Text(file.name ?: "", color = if (isUser) Color.White else ink, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }

            message.verificationWarnings?.takeIf { it.isNotEmpty() }?.let { warnings ->
                Text(
                    warnings.joinToString(" • "),
                    color = if (isUser) Color.White.copy(alpha = 0.85f) else mut,
                    fontSize = 10.5.sp,
                    modifier = Modifier.padding(top = 6.dp),
                )
            }
        }
    }
}

@Composable
private fun AiTypingBubble(surface: Color, mut: Color, elapsedMs: Long, isImageLike: Boolean) {
    // Time-based, not real backend step events (the send endpoint is one
    // blocking call with no progress channel) - but escalating the copy
    // as the wait grows is what actually fixes the observed problem: the
    // user has continuous evidence the assistant is still working, not a
    // precise trace of its internal steps.
    val statusRes = when {
        elapsedMs < 3_000L -> R.string.ai_typing
        elapsedMs < 8_000L -> R.string.ai_typing_step_2
        elapsedMs < 20_000L -> if (isImageLike) R.string.ai_typing_step_image else R.string.ai_typing_step_3
        else -> if (isImageLike) R.string.ai_typing_step_image else R.string.ai_typing_step_4
    }

    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Start) {
        Row(
            Modifier.clip(RoundedCornerShape(topStart = 18.dp, topEnd = 18.dp, bottomStart = 4.dp, bottomEnd = 18.dp))
                .background(surface)
                .padding(horizontal = 16.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            CircularProgressIndicator(color = Ai.Red, strokeWidth = 2.dp, modifier = Modifier.size(14.dp))
            Spacer(Modifier.width(8.dp))
            Text(stringResource(statusRes), color = mut, fontSize = 12.5.sp)
        }
    }
}

@Composable
private fun AiAttachSheet(night: Boolean, onPickImage: () -> Unit, onPickDocument: () -> Unit, onDismiss: () -> Unit) {
    val surface = if (night) Ai.surfaceDark else Ai.surfaceLight
    val ink = if (night) Ai.inkDark else Ai.inkLight
    androidx.compose.ui.window.Dialog(onDismissRequest = onDismiss) {
        Column(Modifier.clip(RoundedCornerShape(20.dp)).background(surface).padding(18.dp)) {
            Row(
                Modifier.fillMaxWidth().clickable(onClick = onPickImage).padding(vertical = 12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Image, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(12.dp))
                Text(stringResource(R.string.ai_attach_photo), color = ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
            }
            Row(
                Modifier.fillMaxWidth().clickable(onClick = onPickDocument).padding(vertical = 12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Description, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(12.dp))
                Text(stringResource(R.string.ai_attach_document), color = ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}
