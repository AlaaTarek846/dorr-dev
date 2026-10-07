package com.dorr.app.ui.screens.aichat

import android.app.DownloadManager
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.media.MediaMetadataRetriever
import android.net.Uri
import android.widget.Toast
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.gestures.detectTransformGestures
import androidx.compose.foundation.gestures.detectVerticalDragGestures
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
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.ExpandLess
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material.icons.rounded.DownloadForOffline
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.produceState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.AiAttachmentDto
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
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
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
    onOpenSettings: () -> Unit,
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
    // Which attachment (if any) is open in the full-screen image
    // viewer below - null means closed. Scoped per-conversation like the
    // other UI state here so switching conversations never leaves a
    // stale viewer open.
    var viewerAttachment by remember(conversationId) { mutableStateOf<AiAttachmentDto?>(null) }
    // Root-cause fix - real, observed bug: while the FIRST message in a
    // brand-new conversation is in flight, `messages` is still an empty
    // list (the only thing that ever populates it is a server response),
    // so the screen kept showing the static "ask the assistant anything"
    // placeholder with no sign the tap was even registered until the
    // reply came back - looked frozen for however long the request took.
    // This holds a locally-built copy of what the user just sent so it
    // renders immediately (see the LazyColumn below), cleared the moment
    // the real persisted messages come back (success) or the send fails
    // (the sendError banner takes over at that point).
    var pendingUserMessage by remember(conversationId) { mutableStateOf<AiMessageDto?>(null) }

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
        // Voice-only sends stay empty here on purpose: the backend now
        // accepts an empty 'message' whenever an attachment is present
        // (AiChatMessageRequest), and for audio it replaces this with the
        // real transcript server-side. Forcing a placeholder caption in
        // used to leak into the stored message content and the
        // conversation's auto-generated title (both used to show "رسالة
        // صوتية…" instead of what was actually said).
        val text = if (isVoice) typed else typed.ifEmpty { attachmentCaption }

        val imageKeywords = listOf("صورة", "صوره", "ارسم", "image", "picture", "photo", "draw")
        typingIsImageLike = attachment?.mime?.startsWith("image/") == true ||
            imageKeywords.any { typed.contains(it, ignoreCase = true) }

        sending = true
        sendError = null
        pendingUserMessage = AiMessageDto(
            id = Int.MIN_VALUE,
            role = "user",
            content = text,
            isError = false,
            model = null,
            providerKey = null,
            attachments = attachment?.let {
                // localUri (Uri.fromFile, not the raw path) is what lets the
                // pending bubble below show this image/voice note immediately
                // from the local cache file - see AiAttachmentDto.localUri's
                // own docblock for the full "doesn't show until the AI
                // replies" root cause this fixes.
                listOf(
                    AiAttachmentDto(
                        id = -1,
                        messageId = null,
                        fileName = it.name,
                        mimeType = it.mime,
                        fileSize = null,
                        isImage = it.mime.startsWith("image/"),
                        url = null,
                        localUri = Uri.fromFile(it.file).toString(),
                    ),
                )
            }.orEmpty(),
            generatedFile = null,
            confidenceScore = null,
            verificationWarnings = null,
            createdAt = null,
        )
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
            pendingUserMessage = null
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

    // Phase 7 (Universal AI File Engine - Video Processing): widened from
    // ImageOnly to ImageAndVideo now that the backend genuinely processes
    // video attachments (VideoFileProcessor) - copyToCache() already
    // reads the real content-resolver MIME type regardless of the
    // "photo.jpg" fallback name, so a picked video is already copied and
    // tagged with its real video/* MIME with no further change needed
    // here. A rich video bubble (poster thumbnail, duration, inline
    // playback) is a disclosed limitation, not implemented this phase -
    // see this phase's Final Report - a picked/received video today
    // renders through the same generic document-style bubble any other
    // non-image/non-audio attachment does.
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
            // Business gap fix (2026-10-04): this used to open the
            // subscription screen directly; it now opens the consolidated
            // settings hub (AiSettingsScreen), which lists subscription AND
            // language/dialect (and room for more later) instead of forcing
            // a new top-bar icon per setting. onOpenSubscription is kept as
            // a direct shortcut for the blocked-usage banner link below.
            Box(Modifier.size(38.dp).clip(CircleShape).clickable(onClick = onOpenSettings), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Settings, contentDescription = stringResource(R.string.ai_settings_title), tint = mut, modifier = Modifier.size(19.dp))
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
                // Root-cause fix: this branch used to fire on `messages.isEmpty()`
                // alone, so the very first send in a new conversation - where
                // `messages` has nothing in it yet and only ever gets
                // populated by a server response - kept showing this static
                // placeholder for the entire round-trip, with no sign the tap
                // registered. Falling through to the LazyColumn branch
                // whenever a send is in flight (sending/pendingUserMessage)
                // is what actually surfaces the just-sent bubble + typing
                // indicator built below.
                messages.isEmpty() && !sending && pendingUserMessage == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
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
                    // The locally-built stand-in for the message just tapped
                    // "send" on - see pendingUserMessage's own docblock above.
                    // Rendered with the exact same AiMessageBubble as a real
                    // message so it looks identical, just without attachment
                    // thumbnails (no server URL exists for it yet).
                    pendingUserMessage?.let { pending ->
                        item(key = "pending") {
                            AiMessageBubble(message = pending, night = night, ink = ink, mut = mut, surface = surface, onOpenUrl = {}, onImageTap = {})
                        }
                    }
                    items(messages.asReversed(), key = { it.id }) { message ->
                        AiMessageBubble(
                            message = message, night = night, ink = ink, mut = mut, surface = surface,
                            onOpenUrl = { url ->
                                runCatching {
                                    context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(ApiClient.mediaUrl(url))))
                                }
                            },
                            onImageTap = { attachment -> viewerAttachment = attachment },
                        )
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
            onPickImage = { attachSheet = false; gallery.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo)) },
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

    viewerAttachment?.let { attachment ->
        AiImageViewer(attachment = attachment, onClose = { viewerAttachment = null })
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

/**
 * Mirrors AiDocumentInlineFormatter.php's small, deliberate inline-markdown
 * subset (bold/italic/inline code, links collapsed to "label (url)") - the
 * exact same message.content that was showing literal "**bold**" asterisks
 * in generated PDF/DOCX files (see AiDocumentInlineFormatter and
 * AiChatService::structureContentForDocument()'s prompt) showed the
 * identical literal asterisks here too, since this Text() rendered
 * message.content as one plain, unstyled string with no markdown handling
 * at all - completely normal model output ("**bold**" for emphasis), not
 * an edge case.
 */
private fun inlineMarkdownAnnotatedString(text: String): AnnotatedString {
    val linked = Regex("""\[([^\]]+)]\(([^)\s]+)\)""").replace(text) { m ->
        "${m.groupValues[1]} (${m.groupValues[2]})"
    }

    val pattern = Regex(
        """(\*\*.+?\*\*|__.+?__|`.+?`|\*[^*\n]+?\*|_[^_\n]+?_)""",
        RegexOption.DOT_MATCHES_ALL,
    )

    return buildAnnotatedString {
        var lastIndex = 0
        for (match in pattern.findAll(linked)) {
            if (match.range.first > lastIndex) {
                append(linked.substring(lastIndex, match.range.first))
            }
            val token = match.value
            when {
                token.startsWith("**") -> withStyle(SpanStyle(fontWeight = FontWeight.Bold)) {
                    append(token.removeSurrounding("**"))
                }
                token.startsWith("__") -> withStyle(SpanStyle(fontWeight = FontWeight.Bold)) {
                    append(token.removeSurrounding("__"))
                }
                token.startsWith("`") -> withStyle(SpanStyle(fontFamily = FontFamily.Monospace)) {
                    append(token.removeSurrounding("`"))
                }
                token.startsWith("*") -> withStyle(SpanStyle(fontStyle = FontStyle.Italic)) {
                    append(token.removeSurrounding("*"))
                }
                token.startsWith("_") -> withStyle(SpanStyle(fontStyle = FontStyle.Italic)) {
                    append(token.removeSurrounding("_"))
                }
                else -> append(token)
            }
            lastIndex = match.range.last + 1
        }
        if (lastIndex < linked.length) {
            append(linked.substring(lastIndex))
        }
    }
}

/**
 * Reads a voice note's total length off the file itself (works for both a
 * remote http(s) url and a local file:// preview uri - see
 * AiAttachmentDto.localUri) since neither AiAttachmentDto nor the backend's
 * ai_conversation_attachments table stores a duration anywhere: there is
 * simply no other source of truth for "how long is this voice message"
 * to show before (or instead of) pressing play. The same
 * ngrok-skip-browser-warning header VoicePlayer already needs for actual
 * playback over the local dev tunnel is required here too, or metadata
 * extraction silently fails against an HTML interstitial page instead of
 * real audio bytes.
 */
private suspend fun probeAudioDurationMs(context: Context, url: String): Long? = withContext(Dispatchers.IO) {
    val retriever = MediaMetadataRetriever()
    try {
        if (url.startsWith("http://") || url.startsWith("https://")) {
            retriever.setDataSource(url, mapOf("ngrok-skip-browser-warning" to "1"))
        } else {
            retriever.setDataSource(context, Uri.parse(url))
        }
        retriever.extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)?.toLongOrNull()
    } catch (e: Exception) {
        null
    } finally {
        runCatching { retriever.release() }
    }
}

/**
 * A chat message mixes normal prose with ```fenced``` code blocks (the
 * only code-block syntax the assistant is prompted to use - see
 * structureContentForDocument()/AiDomainPipelineService's own code-fence
 * handling on the backend). Splitting the two apart lets code render in
 * its own professional, Claude/ChatGPT-style card (monospace, dark,
 * horizontally scrollable, its own explicit Copy button) instead of as
 * plain wrapped text with literal backtick characters showing - which is
 * what the single shared inlineMarkdownAnnotatedString() Text() call used
 * to produce, since its inline-code regex was never meant to (and does
 * not cleanly) handle a multi-line triple-backtick block.
 */
private data class AiContentSegment(val isCode: Boolean, val language: String?, val text: String)

private val aiCodeFenceRegex = Regex("""```([a-zA-Z0-9_+#.-]*)\n?([\s\S]*?)```""")

private fun splitAiContentIntoSegments(content: String): List<AiContentSegment> {
    val segments = mutableListOf<AiContentSegment>()
    var lastIndex = 0
    for (match in aiCodeFenceRegex.findAll(content)) {
        if (match.range.first > lastIndex) {
            segments.add(AiContentSegment(isCode = false, language = null, text = content.substring(lastIndex, match.range.first)))
        }
        val language = match.groupValues[1].trim().ifBlank { null }
        val code = match.groupValues[2].trim('\n')
        segments.add(AiContentSegment(isCode = true, language = language, text = code))
        lastIndex = match.range.last + 1
    }
    if (lastIndex < content.length) {
        segments.add(AiContentSegment(isCode = false, language = null, text = content.substring(lastIndex)))
    }
    return segments
}

@Composable
private fun MessageContentBlocks(content: String, textColor: Color, fontSize: TextUnit = 14.sp, lineHeight: TextUnit = 20.sp) {
    val segments = remember(content) { splitAiContentIntoSegments(content) }
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        segments.forEach { segment ->
            if (segment.isCode) {
                if (segment.text.isNotBlank()) CodeBlockCard(code = segment.text, language = segment.language)
            } else if (segment.text.isNotBlank()) {
                Text(inlineMarkdownAnnotatedString(segment.text.trim('\n')), color = textColor, fontSize = fontSize, lineHeight = lineHeight)
            }
        }
    }
}

/**
 * The code-block card itself: always rendered on a fixed dark "editor"
 * background regardless of the surrounding bubble's color (exactly like
 * Claude's and ChatGPT's own chat UIs) so code reads as code - a language
 * label plus an explicit Copy button up top (not just the whole-message
 * long-press copy, which copies the raw markdown fences and surrounding
 * prose together, not a clean, pasteable snippet), and a horizontally
 * scrolling body so long lines never wrap and break indentation.
 */
@Composable
private fun CodeBlockCard(code: String, language: String?) {
    val context = LocalContext.current
    val copiedLabel = stringResource(R.string.ai_code_copied)
    val copyLabel = stringResource(R.string.ai_copy)
    val copiedDoneLabel = stringResource(R.string.ai_copied_done)
    var justCopied by remember(code) { mutableStateOf(false) }

    LaunchedEffect(justCopied) {
        if (justCopied) {
            delay(1600)
            justCopied = false
        }
    }

    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .background(Color(0xFF1E1E2E)),
    ) {
        Row(
            Modifier.fillMaxWidth().background(Color(0xFF14141F)).padding(horizontal = 12.dp, vertical = 7.dp),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                language?.lowercase() ?: "code",
                color = Color(0xFF9CA3AF),
                fontSize = 11.5.sp,
                fontFamily = FontFamily.Monospace,
                fontWeight = FontWeight.SemiBold,
            )
            Row(
                Modifier
                    .clip(RoundedCornerShape(6.dp))
                    .clickable {
                        val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager
                        clipboard?.setPrimaryClip(ClipData.newPlainText("code", code))
                        Toast.makeText(context, copiedLabel, Toast.LENGTH_SHORT).show()
                        justCopied = true
                    }
                    .padding(horizontal = 7.dp, vertical = 3.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(
                    if (justCopied) Icons.Rounded.Check else Icons.Rounded.ContentCopy,
                    contentDescription = stringResource(R.string.ai_copy_code),
                    tint = if (justCopied) Color(0xFF34D399) else Color(0xFF9CA3AF),
                    modifier = Modifier.size(14.dp),
                )
                Spacer(Modifier.width(4.dp))
                Text(
                    if (justCopied) copiedDoneLabel else copyLabel,
                    color = if (justCopied) Color(0xFF34D399) else Color(0xFF9CA3AF),
                    fontSize = 11.sp,
                    fontWeight = FontWeight.Medium,
                )
            }
        }
        Box(Modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 12.dp, vertical = 10.dp)) {
            Text(
                code,
                color = Color(0xFFE5E7EB),
                fontFamily = FontFamily.Monospace,
                fontSize = 12.5.sp,
                lineHeight = 18.sp,
            )
        }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun AiMessageBubble(
    message: AiMessageDto,
    night: Boolean,
    ink: Color,
    mut: Color,
    surface: Color,
    onOpenUrl: (String) -> Unit,
    onImageTap: (AiAttachmentDto) -> Unit,
) {
    val isUser = message.isUser
    val context = LocalContext.current
    val haptic = LocalHapticFeedback.current
    val copiedLabel = stringResource(R.string.ai_message_copied)
    fun copyMessageText() {
        if (message.content.isBlank()) return
        val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager
        clipboard?.setPrimaryClip(ClipData.newPlainText("message", message.content))
        Toast.makeText(context, copiedLabel, Toast.LENGTH_SHORT).show()
    }
    // Only a voice attachment gets the collapsed "convert to text" toggle -
    // an image/document message still shows its caption text immediately,
    // unchanged. Keyed by message.id so each bubble keeps its own
    // expanded/collapsed state across recompositions/list scrolling.
    val hasAudioAttachment = message.attachments.orEmpty().any { it.mimeType?.startsWith("audio/") == true }
    var transcriptExpanded by remember(message.id) { mutableStateOf(false) }
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
                // Long-press anywhere on the bubble copies its text - the
                // same "long-press to copy" convention already used in the
                // person-to-person chat (MessageActions.kt); onClick is a
                // required no-op here so the long-press gesture recognizer
                // activates at all (combinedClickable needs both).
                .combinedClickable(
                    onClick = {},
                    onLongClick = {
                        haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                        copyMessageText()
                    },
                )
                .padding(horizontal = 14.dp, vertical = 10.dp),
        ) {
            message.attachments.orEmpty().forEach { attachment ->
                if (attachment.isImage && (attachment.url != null || attachment.localUri != null)) {
                    // url (real, uploaded) takes priority once the send
                    // succeeds; localUri (see AiAttachmentDto.localUri) is
                    // what lets the just-picked photo show immediately in
                    // the optimistic pending bubble, before the upload
                    // round-trip even starts.
                    AsyncImage(
                        model = ApiClient.mediaUrl(attachment.url ?: attachment.localUri),
                        imageLoader = chatImages(LocalContext.current),
                        contentDescription = attachment.fileName,
                        contentScale = androidx.compose.ui.layout.ContentScale.Crop,
                        modifier = Modifier
                            .heightIn(max = 180.dp)
                            .clip(RoundedCornerShape(12.dp))
                            // Tapping a chat image opens the full-screen zoomable
                            // viewer (AiImageViewer) instead of handing it off to
                            // onOpenUrl (an external browser/app) - see that
                            // composable for the pinch-zoom + download behavior.
                            .clickable { onImageTap(attachment) }
                            .padding(bottom = 6.dp),
                    )
                } else if ((attachment.url != null || attachment.localUri != null) && attachment.mimeType?.startsWith("audio/") == true) {
                    // Same local-preview fallback as the image branch above:
                    // MediaPlayer (VoicePlayer) plays a file:// Uri exactly
                    // like a real http(s) one, so the just-recorded note is
                    // tappable/playable immediately in the pending bubble.
                    val rawAudioUrl = attachment.url ?: attachment.localUri
                    val audioUrl = ApiClient.mediaUrl(rawAudioUrl) ?: rawAudioUrl!!
                    val playing = VoicePlayer.isPlaying(audioUrl)
                    val current = VoicePlayer.playingId == audioUrl
                    val progress = if (current) VoicePlayer.progress else 0f

                    // Neither AiAttachmentDto nor the backend stores a real
                    // duration (see probeAudioDurationMs's docblock), so the
                    // total length is read once off the file itself and
                    // cached for this attachment/url - the same "how long is
                    // this note" a WhatsApp-style voice bubble shows before
                    // it is ever played, not just a count-up after pressing
                    // play.
                    val durationMs by produceState<Long?>(initialValue = null, key1 = audioUrl) {
                        value = probeAudioDurationMs(context, audioUrl)
                    }

                    // No real per-sample waveform exists for an AI-chat voice
                    // note (unlike the person-to-person chat's VoiceNote,
                    // which can read one back from dto.meta) - a short,
                    // deterministic bar pattern seeded from the attachment's
                    // own id gives every note a distinct, natural silhouette
                    // instead of one identical flat bar repeated everywhere.
                    val waveform = remember(attachment.id) {
                        val seed = attachment.id
                        List(32) { i -> (18 + ((i * 37 + seed * 11) % 64)) }
                    }

                    val fg = if (isUser) Color.White else Ai.Red
                    val track = if (isUser) Color.White.copy(alpha = 0.35f) else Ai.Red.copy(alpha = 0.22f)
                    val buttonScale by animateFloatAsState(if (playing) 1.08f else 1f, spring(dampingRatio = 0.4f), label = "ai-voice-play")

                    Row(
                        Modifier
                            .widthIn(min = 220.dp)
                            .clip(RoundedCornerShape(20.dp))
                            .background(if (isUser) Color.White.copy(alpha = 0.16f) else Color.Black.copy(alpha = 0.06f))
                            .padding(horizontal = 10.dp, vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Box(
                            Modifier
                                .size(38.dp)
                                .scale(buttonScale)
                                .clip(CircleShape)
                                .background(if (isUser) Color.White else Ai.Red)
                                .clickable { VoicePlayer.toggle(audioUrl, audioUrl) },
                            contentAlignment = Alignment.Center,
                        ) {
                            Icon(
                                if (playing) Icons.Rounded.Pause else Icons.Rounded.PlayArrow,
                                contentDescription = stringResource(if (playing) R.string.ai_stop_recording else R.string.ai_record_voice),
                                tint = if (isUser) Ai.Red else Color.White,
                                modifier = Modifier.size(20.dp),
                            )
                        }
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.width(150.dp)) {
                            Canvas(
                                Modifier
                                    .fillMaxWidth()
                                    .height(26.dp)
                                    .pointerInput(audioUrl) {
                                        detectTapGestures { pos -> VoicePlayer.seek(audioUrl, (pos.x / size.width).coerceIn(0f, 1f)) }
                                    },
                            ) {
                                val bars = waveform.size
                                val gap = 2.dp.toPx()
                                val barWidth = (size.width - gap * (bars - 1)) / bars
                                waveform.forEachIndexed { i, v ->
                                    val barHeight = (size.height * (0.2f + 0.8f * v / 100f)).coerceAtLeast(3.dp.toPx())
                                    val x = i * (barWidth + gap)
                                    val filled = (i + 0.5f) / bars <= progress
                                    drawRoundRect(
                                        if (filled) fg else track,
                                        Offset(x, (size.height - barHeight) / 2),
                                        Size(barWidth, barHeight),
                                        CornerRadius(barWidth / 2),
                                    )
                                }
                            }
                            Spacer(Modifier.height(4.dp))
                            Text(
                                if (current) {
                                    durationText(VoicePlayer.positionMs)
                                } else {
                                    durationMs?.let { durationText(it) } ?: "--:--"
                                },
                                color = if (isUser) Color.White.copy(alpha = 0.85f) else mut,
                                fontSize = 11.sp,
                            )
                        }
                    }
                    Spacer(Modifier.height(6.dp))
                } else if (attachment.url != null || attachment.localUri != null) {
                    // Root-cause fix - same class of bug already fixed for
                    // images/voice notes (see localUri's docblock): a
                    // just-sent PDF/document attachment has no `url` yet
                    // (the server hasn't responded with one), only
                    // `localUri` (the local cache file) - this branch used
                    // to require `url` alone, so the pending bubble showed
                    // nothing at all for a document until the AI's reply
                    // came back and finally supplied a real `url`. Opening
                    // stays gated on a real `url` (a local cache file isn't
                    // meant to be opened via onOpenUrl), so the pending
                    // bubble is tappable only once the real attachment
                    // exists server-side.
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

            if (hasAudioAttachment && message.content.isNotBlank()) {
                Spacer(Modifier.height(6.dp))
                Row(
                    Modifier
                        .clickable { transcriptExpanded = !transcriptExpanded }
                        .padding(vertical = 2.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(
                        if (transcriptExpanded) Icons.Rounded.ExpandLess else Icons.Rounded.ExpandMore,
                        contentDescription = null,
                        tint = if (isUser) Color.White.copy(alpha = 0.85f) else mut,
                        modifier = Modifier.size(16.dp),
                    )
                    Spacer(Modifier.width(2.dp))
                    Text(
                        stringResource(if (transcriptExpanded) R.string.ai_voice_transcript_hide else R.string.ai_voice_transcript_show),
                        color = if (isUser) Color.White.copy(alpha = 0.85f) else mut,
                        fontSize = 12.sp,
                    )
                }
                if (transcriptExpanded) {
                    Spacer(Modifier.height(4.dp))
                    MessageContentBlocks(
                        content = message.content,
                        textColor = if (message.isError) Color(0xFFB91C1C) else if (isUser) Color.White else ink,
                    )
                }
            } else if (message.content.isNotBlank()) {
                MessageContentBlocks(
                    content = message.content,
                    textColor = if (message.isError) Color(0xFFB91C1C) else if (isUser) Color.White else ink,
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

            // Phase 11 (doc S14/S16): sources for this reply, collapsed by
            // default - never shown on a user's own message (citations
            // only ever describe the assistant's evidence for its answer).
            if (!isUser && message.citations.isNotEmpty()) {
                AiCitationsSection(citations = message.citations, mut = mut)
            }
        }
    }
}

/**
 * Doc S14/S16/S37: a compact, collapsible sources list - shows only real
 * citation metadata (file name + whichever location field the backend
 * actually computed for that citation's content type), never an invented
 * page/sheet/slide/timestamp, and never an internal chunk/embedding id.
 */
@Composable
private fun AiCitationsSection(citations: List<com.dorr.app.network.AiFileCitationDto>, mut: Color) {
    var expanded by remember(citations) { mutableStateOf(false) }

    Column(Modifier.padding(top = 6.dp)) {
        Row(
            Modifier.clickable { expanded = !expanded },
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(
                if (expanded) Icons.Rounded.ExpandLess else Icons.Rounded.ExpandMore,
                contentDescription = null,
                tint = mut,
                modifier = Modifier.size(14.dp),
            )
            Spacer(Modifier.width(2.dp))
            Text(
                stringResource(R.string.ai_sources_count, citations.size),
                color = mut,
                fontSize = 11.sp,
            )
        }

        if (expanded) {
            citations.forEach { citation ->
                Row(Modifier.padding(top = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Description, contentDescription = null, tint = mut, modifier = Modifier.size(12.dp))
                    Spacer(Modifier.width(4.dp))
                    Text(
                        buildString {
                            append(citation.fileName ?: "")
                            citationLocationLabel(citation)?.let { append(" — ").append(it) }
                        },
                        color = mut,
                        fontSize = 11.sp,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
            }
        }
    }
}

/** Mirrors MessageCitations.vue's locationLabel() - same precedence, same fields. */
private fun citationLocationLabel(citation: com.dorr.app.network.AiFileCitationDto): String? {
    val loc = citation.location ?: return null

    return when {
        loc.page != null -> "p.${loc.page}"
        loc.sheet != null && (loc.rowStart != null || loc.rowEnd != null) -> "${loc.sheet} (${loc.rowStart}-${loc.rowEnd})"
        loc.sheet != null -> loc.sheet
        loc.slide != null -> "slide ${loc.slide}"
        loc.timestampStart != null -> {
            val start = loc.timestampStart.toInt()
            "${start / 60}:${(start % 60).toString().padStart(2, '0')}"
        }
        loc.section != null -> loc.section
        else -> null
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

// ------------------------------------------------------------------------------- full-screen image viewer

/**
 * Full-screen zoomable viewer for any chat image (user-uploaded or
 * AI-generated), opened by tapping a bubble's image. Mirrors the existing
 * media viewer pattern already used by the regular chat module
 * (ui/screens/chat/ConversationPage.kt's MediaViewer: pinch-to-zoom, drag
 * down to dismiss) so the gesture language stays consistent across the
 * app, plus a download button - a real, previously missing capability
 * (there was no way to save a chat image to the device at all).
 */
@Composable
private fun AiImageViewer(attachment: AiAttachmentDto, onClose: () -> Unit) {
    val context = LocalContext.current
    val resolvedUrl = ApiClient.mediaUrl(attachment.url) ?: attachment.url
    val scope = rememberCoroutineScope()
    val drag = remember(attachment.id) { Animatable(0f) }
    var scale by remember(attachment.id) { mutableStateOf(1f) }
    var offset by remember(attachment.id) { mutableStateOf(androidx.compose.ui.geometry.Offset.Zero) }
    val fade = (1f - kotlin.math.abs(drag.value) / 900f).coerceIn(0.2f, 1f)

    Dialog(onDismissRequest = onClose, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Box(
            Modifier
                .fillMaxSize()
                .background(Color.Black.copy(alpha = fade))
                .pointerInput(attachment.id) {
                    // Only drag-to-dismiss while not zoomed in - otherwise a
                    // vertical pan while zoomed would fight detectTransformGestures
                    // below for the same gesture.
                    detectVerticalDragGestures(
                        onDragEnd = {
                            if (kotlin.math.abs(drag.value) > 260f) {
                                onClose()
                            } else {
                                scope.launch { drag.animateTo(0f, spring(dampingRatio = 0.6f)) }
                            }
                        },
                    ) { _, dy -> if (scale <= 1f) scope.launch { drag.snapTo(drag.value + dy) } }
                },
        ) {
            AsyncImage(
                model = resolvedUrl,
                imageLoader = chatImages(context),
                contentDescription = attachment.fileName,
                contentScale = androidx.compose.ui.layout.ContentScale.Fit,
                modifier = Modifier
                    .fillMaxSize()
                    .pointerInput(attachment.id) {
                        detectTransformGestures { _, pan, zoom, _ ->
                            scale = (scale * zoom).coerceIn(1f, 5f)
                            offset = if (scale > 1f) offset + pan else androidx.compose.ui.geometry.Offset.Zero
                        }
                    }
                    .graphicsLayer {
                        translationY = drag.value
                        val dragScale = 1f - kotlin.math.abs(drag.value) / 3000f
                        scaleX = scale * dragScale
                        scaleY = scale * dragScale
                        translationX = offset.x
                    },
            )

            Row(
                Modifier.fillMaxWidth().statusBarsPadding().padding(16.dp),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(
                    Modifier.size(42.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.15f)).clickable(onClick = onClose),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(Icons.Rounded.Close, contentDescription = stringResource(R.string.ai_image_viewer_close), tint = Color.White)
                }
                if (resolvedUrl != null) {
                    Box(
                        Modifier.size(42.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.15f))
                            .clickable { downloadChatImage(context, resolvedUrl, attachment.fileName) },
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(Icons.Rounded.DownloadForOffline, contentDescription = stringResource(R.string.ai_image_viewer_download), tint = Color.White)
                    }
                }
            }
        }
    }
}

/**
 * Saves a chat image to the device's public Pictures folder via the
 * system DownloadManager - no WRITE_EXTERNAL_STORAGE permission needed at
 * this app's targetSdk (29+ writes through MediaStore automatically).
 * The ngrok-skip-browser-warning header is required here for the exact
 * same reason DorrApp.newImageLoader()'s docblock explains for Coil:
 * DownloadManager makes its own independent HTTP request (it does not
 * reuse ApiClient.okHttpClient or its interceptors), so without this
 * header it would silently download ngrok's HTML interstitial page
 * instead of the actual image bytes.
 */
private fun downloadChatImage(context: android.content.Context, url: String, fileName: String?) {
    runCatching {
        val safeName = fileName?.takeIf { it.isNotBlank() && it.contains('.') } ?: "dorr_${System.currentTimeMillis()}.jpg"
        val request = DownloadManager.Request(Uri.parse(url))
            .addRequestHeader("ngrok-skip-browser-warning", "1")
            .setTitle(safeName)
            .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
            .setDestinationInExternalPublicDir(android.os.Environment.DIRECTORY_PICTURES, safeName)
            .setAllowedOverMetered(true)
            .setAllowedOverRoaming(true)
        val manager = context.getSystemService(android.content.Context.DOWNLOAD_SERVICE) as DownloadManager
        manager.enqueue(request)
        Toast.makeText(context, context.getString(R.string.ai_image_download_started), Toast.LENGTH_SHORT).show()
    }.onFailure {
        Toast.makeText(context, context.getString(R.string.ai_image_download_failed), Toast.LENGTH_SHORT).show()
    }
}
