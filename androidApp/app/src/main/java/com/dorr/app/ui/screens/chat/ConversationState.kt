package com.dorr.app.ui.screens.chat

import android.content.Context
import android.net.Uri
import android.provider.OpenableColumns
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.dorr.app.chat.ChatEvent
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AttachmentDto
import com.dorr.app.network.AuthSession
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.MessageDto
import com.dorr.app.network.ProfileDto
import com.dorr.app.network.ReactionSummaryDto
import com.dorr.app.network.ReactionsDto
import com.dorr.app.network.apiFailure
import com.google.gson.Gson
import com.google.gson.JsonObject
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.File
import java.util.UUID

/** One row in the conversation: the server's message plus what only this phone knows. */
data class UiMessage(
    val dto: MessageDto,
    /** "pending" while uploading, "failed" when it didn't go; null once the server has it. */
    val local: String? = null,
    /** Files picked on this phone, shown straight away before the upload finishes. */
    val localFiles: List<LocalFile> = emptyList(),
    /** Plays the arrival animation once. */
    val fresh: Boolean = false,
    /** Upload progress 0..1 while a file message is going up (null = unknown / not uploading). */
    val progress: Float? = null,
    /** A video's poster made on this phone — shown until the server's copy arrives. */
    val localThumb: File? = null,
) {
    val id: String get() = dto.id
    val isMine: Boolean get() = dto.isMine ?: (dto.sender?.key == myKey())
    val status: String? get() = local ?: dto.status
}

data class LocalFile(val file: File, val mime: String, val name: String)

/** What the composer is sending. */
data class Outgoing(
    val type: String,
    val body: String? = null,
    val files: List<LocalFile> = emptyList(),
    val extra: Map<String, Any?> = emptyMap(),
    /** Video only: its poster frame. */
    val thumbnail: File? = null,
)

/**
 * Everything one open conversation knows and does: its messages (newest last), paging back through
 * history, sending (optimistic — the bubble appears at once with a ⏱ and turns into ✓ when the
 * server answers; a failed one offers retry with the *same* uuid so it can never duplicate), and
 * the live updates for this chat (new messages, ticks, reactions, edits, deletions, pins).
 */
@Stable
class ConversationState(val id: String, private val scope: CoroutineScope, private val host: ChatHost, private val context: Context) {
    var conversation by mutableStateOf<ConversationDto?>(host.find(id))
    val messages = mutableStateListOf<UiMessage>()
    var loading by mutableStateOf(true)
    var loadingOlder by mutableStateOf(false)
    var hasMoreBefore by mutableStateOf(false)
    var failed by mutableStateOf(false)

    var replyTo by mutableStateOf<MessageDto?>(null)
    var editing by mutableStateOf<MessageDto?>(null)
    var focused by mutableStateOf<UiMessage?>(null)
    var pinned by mutableStateOf<List<MessageDto>>(emptyList())

    /** How many arrived while the list was scrolled up (shown on the ↓ button). */
    var unseenBelow by mutableStateOf(0)
    var atBottom = true

    /** After jumping to an old message (search, pin), newer ones are loaded as you scroll down. */
    var hasMoreAfter by mutableStateOf(false)
    private var loadingNewer = false

    /** Group members, for @mention suggestions (loaded on first "@"). */
    var members by mutableStateOf<List<com.dorr.app.network.MemberDto>>(emptyList())
        private set

    private val gson = Gson()
    private var typingJob: Job? = null
    private var lastTypingSent = 0L

    init {
        scope.launch { load() }
        scope.launch { ChatRealtime.events.collect { onEvent(it) } }
    }

    suspend fun load() {
        // Offline first: what this phone saved last time shows at once — and without internet.
        if (messages.isEmpty()) {
            com.dorr.app.chat.ChatStore.messages(id)?.takeIf { it.isNotEmpty() }?.let { cached ->
                messages.addAll(cached.map { UiMessage(it) })
            }
        }
        loading = messages.isEmpty()
        try {
            val fresh = ApiClient.chat.conversation(chatAuth(), id).data
            if (fresh != null) conversation = fresh
            val page = ApiClient.chat.messages(chatAuth(), id).data
            if (page != null) {
                // Keep bubbles still on their way up; replace the rest with the server's truth.
                val unsent = messages.filter { it.local != null }
                messages.clear()
                messages.addAll(page.messages.map { UiMessage(it) })
                messages.addAll(unsent)
                hasMoreBefore = page.hasMoreBefore
                hasMoreAfter = false
                persist()
            }
            failed = false
            refreshPinned()
            markRead()
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            failed = messages.isEmpty()
        }
        loading = false
    }

    /** Open the page around one message (a search result / pinned message not loaded yet). */
    suspend fun loadAround(messageId: String): Boolean {
        val page = runCatching { ApiClient.chat.messages(chatAuth(), id, around = messageId).data }.getOrNull() ?: return false
        messages.clear()
        messages.addAll(page.messages.map { UiMessage(it) })
        hasMoreBefore = page.hasMoreBefore
        hasMoreAfter = page.hasMoreAfter
        return true
    }

    suspend fun loadNewer() {
        if (loadingNewer || !hasMoreAfter) return
        val last = messages.lastOrNull { it.local == null } ?: return
        loadingNewer = true
        runCatching { ApiClient.chat.messages(chatAuth(), id, after = last.id).data }.getOrNull()?.let { page ->
            messages.addAll(page.messages.map { UiMessage(it) })
            hasMoreAfter = page.hasMoreAfter
        }
        loadingNewer = false
    }

    suspend fun loadMembers() {
        if (members.isNotEmpty() || conversation?.isGroup != true) return
        members = runCatching { ApiClient.chat.members(chatAuth(), id).data }.getOrNull().orEmpty()
    }

    /** Save the latest messages for offline reading (debounced by ChatStore). */
    private fun persist() {
        if (!hasMoreAfter) com.dorr.app.chat.ChatStore.saveMessages(id, messages.filter { it.local == null }.map { it.dto })
    }

    suspend fun loadOlder() {
        if (loadingOlder || !hasMoreBefore) return
        val first = messages.firstOrNull { it.local == null } ?: return
        loadingOlder = true
        runCatching { ApiClient.chat.messages(chatAuth(), id, before = first.id).data }.getOrNull()?.let { page ->
            messages.addAll(0, page.messages.map { UiMessage(it) })
            hasMoreBefore = page.hasMoreBefore
        }
        loadingOlder = false
    }

    fun markRead() {
        host.markReadLocally(id)
        val c = conversation ?: return
        if (c.isRequest) return
        scope.launch { runCatching { ApiClient.chat.markRead(chatAuth(), id) } }
    }

    suspend fun refreshPinned() {
        pinned = runCatching { ApiClient.chat.pinned(chatAuth(), id).data }.getOrNull().orEmpty()
    }

    // ------------------------------------------------------------------ sending

    fun send(out: Outgoing) {
        val uuid = UUID.randomUUID().toString()
        val me = AuthSession.user
        val optimistic = MessageDto(
            id = uuid, conversationId = id, type = out.type, body = out.body,
            meta = out.extra.takeIf { it.isNotEmpty() }?.let { gson.toJsonTree(it).asJsonObject },
            attachments = emptyList(),
            sender = ProfileDto(type = "user", id = me?.id ?: 0, key = myKey(), name = me?.name, isMe = true),
            isMine = true, status = null,
            replyTo = replyTo?.let { com.dorr.app.network.ReplyPreviewDto(it.id, it.type, it.body, it.sender, null, false) },
            expiresAt = null, system = null, createdAt = java.time.OffsetDateTime.now().toString(),
        )
        val replyId = replyTo?.id
        replyTo = null
        messages.add(UiMessage(optimistic, local = "pending", localFiles = out.files, fresh = true, localThumb = out.thumbnail))
        sendTyping(stop = true)
        upload(uuid, out, replyId)
    }

    fun retry(message: UiMessage) {
        val index = messages.indexOfFirst { it.id == message.id }
        if (index < 0) return
        messages[index] = message.copy(local = "pending")
        val dto = message.dto
        val extra: Map<String, Any?> = dto.meta?.let { gson.fromJson(it, Map::class.java) as Map<String, Any?> } ?: emptyMap()
        upload(dto.id, Outgoing(dto.type, dto.body, message.localFiles, extra, message.localThumb), dto.replyTo?.id)
    }

    private fun upload(uuid: String, out: Outgoing, replyId: String?) {
        scope.launch {
            try {
                val sent = if (out.files.isEmpty()) {
                    val body = mutableMapOf<String, Any?>("type" to out.type, "uuid" to uuid, "body" to out.body, "reply_to" to replyId)
                    body.putAll(out.extra)
                    ApiClient.chat.send(chatAuth(), id, body.filterValues { it != null })
                } else {
                    val fields = mutableMapOf<String, RequestBody>()
                    fun field(name: String, value: Any?) {
                        if (value != null) fields[name] = value.toString().toRequestBody("text/plain".toMediaTypeOrNull())
                    }
                    field("type", out.type)
                    field("uuid", uuid)
                    field("body", out.body)
                    field("reply_to", replyId)
                    out.extra.forEach { (k, v) ->
                        if (v is List<*>) v.forEachIndexed { i, item -> field("$k[$i]", item) } else field(k, v)
                    }
                    // Videos are shrunk first (the ring spins meanwhile), then uploaded.
                    val files = if (out.type == "video") out.files.map { com.dorr.app.chat.VideoTools.compress(context, it) } else out.files
                    // Progress across all the files together, reported in 2% steps.
                    val total = files.sumOf { it.file.length() }.coerceAtLeast(1)
                    var sentBytes = 0L
                    var lastShown = -1
                    val poster = out.thumbnail?.takeIf { it.exists() }?.let {
                        MultipartBody.Part.createFormData("thumbnail", "poster.jpg", it.asRequestBody("image/jpeg".toMediaTypeOrNull()))
                    }
                    val parts = listOfNotNull(poster) + files.map { f ->
                        MultipartBody.Part.createFormData("files[]", f.name, ProgressBody(f.file, f.mime) { delta ->
                            sentBytes += delta
                            val percent = (sentBytes * 100 / total).toInt()
                            if (percent - lastShown >= 2) {
                                lastShown = percent
                                scope.launch(kotlinx.coroutines.Dispatchers.Main) {
                                    val index = messages.indexOfFirst { it.id == uuid }
                                    if (index >= 0 && messages[index].local == "pending") messages[index] = messages[index].copy(progress = percent / 100f)
                                }
                            }
                        })
                    }
                    ApiClient.chat.sendWithFiles(chatAuth(), id, fields, parts)
                }
                sent.data?.let { replaceMessage(uuid, UiMessage(it)) }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                val failure = e.apiFailure()
                val index = messages.indexOfFirst { it.id == uuid }
                if (index >= 0) messages[index] = messages[index].copy(local = "failed")
                failure.message?.let { host.showToast(it) }
            }
        }
    }

    fun edit(message: MessageDto, body: String) {
        editing = null
        scope.launch {
            try {
                ApiClient.chat.edit(chatAuth(), message.id, mapOf("body" to body)).data?.let { replaceMessage(it.id, UiMessage(it)) }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                e.apiFailure().message?.let { host.showToast(it) }
            }
        }
    }

    fun deleteForEveryone(message: MessageDto) = scope.launch {
        try {
            ApiClient.chat.deleteForEveryone(chatAuth(), message.id).data?.let { replaceMessage(it.id, UiMessage(it)) }
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            e.apiFailure().message?.let { host.showToast(it) }
        }
    }

    fun deleteForMe(message: MessageDto) {
        messages.removeAll { it.id == message.id }
        scope.launch { runCatching { ApiClient.chat.deleteForMe(chatAuth(), id, mapOf("messages" to listOf(message.id))) } }
    }

    fun react(message: MessageDto, emoji: String?) {
        // Instant feedback; the server's answer (and everyone else's reactions) follow.
        val index = messages.indexOfFirst { it.id == message.id }
        if (index >= 0) {
            val current = messages[index].dto.reactions
            messages[index] = messages[index].copy(dto = messages[index].dto.copy(reactions = localReact(current, emoji)))
        }
        scope.launch {
            runCatching { ApiClient.chat.react(chatAuth(), message.id, mapOf("emoji" to emoji)).data }.getOrNull()?.let { replaceMessage(it.id, UiMessage(it)) }
        }
    }

    private fun localReact(r: ReactionsDto, emoji: String?): ReactionsDto {
        val counts = r.summary.associate { it.emoji to it.count }.toMutableMap()
        r.mine?.let { old -> counts[old] = (counts[old] ?: 1) - 1 }
        val newEmoji = if (emoji == r.mine) null else emoji
        newEmoji?.let { counts[it] = (counts[it] ?: 0) + 1 }
        val summary = counts.filterValues { it > 0 }.map { ReactionSummaryDto(it.key, it.value) }.sortedByDescending { it.count }
        return ReactionsDto(summary, newEmoji, summary.sumOf { it.count })
    }

    fun star(message: MessageDto) {
        val starred = !message.isStarred
        replaceMessage(message.id, UiMessage(message.copy(isStarred = starred)))
        scope.launch { runCatching { ApiClient.chat.star(chatAuth(), message.id, mapOf("starred" to starred)) } }
    }

    fun pin(message: MessageDto, seconds: Int) = scope.launch {
        try {
            pinned = ApiClient.chat.pin(chatAuth(), message.id, mapOf("duration_seconds" to seconds)).data.orEmpty()
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            e.apiFailure().message?.let { host.showToast(it) }
        }
    }

    fun unpin(message: MessageDto) = scope.launch {
        pinned = runCatching { ApiClient.chat.unpin(chatAuth(), message.id).data }.getOrNull() ?: pinned.filterNot { it.id == message.id }
    }

    fun forward(message: MessageDto, targets: List<String>, done: (Boolean) -> Unit) = scope.launch {
        try {
            ApiClient.chat.forward(chatAuth(), mapOf("messages" to listOf(message.id), "conversations" to targets))
            done(true)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            e.apiFailure().message?.let { host.showToast(it) }
            done(false)
        }
    }

    /** Tell the others we're typing — at most every 3s, and "stopped" after a pause. */
    fun sendTyping(stop: Boolean = false, state: String = "typing") {
        val c = conversation ?: return
        if (c.isRequest) return
        val now = System.currentTimeMillis()
        typingJob?.cancel()
        if (stop) {
            if (lastTypingSent > 0) host.sendTyping(id, "stopped")
            lastTypingSent = 0
            return
        }
        if (now - lastTypingSent > 3000) {
            lastTypingSent = now
            host.sendTyping(id, state)
        }
        typingJob = scope.launch {
            delay(4000)
            host.sendTyping(id, "stopped")
            lastTypingSent = 0
        }
    }

    // ------------------------------------------------------------------ real-time

    private fun onEvent(event: ChatEvent) {
        if (event.data.str("conversation_id") != id) return
        when (event.name) {
            "chat.message.sent" -> {
                val dto = parseMessage(event.data.getAsJsonObject("message")) ?: return
                val mine = dto.sender?.key == myKey()
                val fixed = dto.copy(isMine = mine, status = if (mine) "sent" else null)
                if (messages.any { it.id == dto.id }) {
                    // Our own optimistic bubble: keep the server's version (with its files).
                    if (mine) replaceMessage(dto.id, UiMessage(fixed))
                } else if (hasMoreAfter) {
                    // Reading an older stretch (after a search jump): don't glue it on out of order.
                    if (!mine) unseenBelow++
                } else {
                    messages.add(UiMessage(fixed, fresh = true))
                    persist()
                    if (!mine) {
                        if (atBottom) markRead() else unseenBelow++
                    }
                }
            }
            "chat.message.updated", "chat.message.deleted" -> {
                val dto = parseMessage(event.data.getAsJsonObject("message")) ?: return
                val old = messages.firstOrNull { it.id == dto.id } ?: return
                replaceMessage(dto.id, UiMessage(dto.copy(isMine = old.isMine, status = old.dto.status, isStarred = old.dto.isStarred, reactions = if (event.name == "chat.message.deleted") ReactionsDto() else old.dto.reactions)))
            }
            "chat.receipt" -> {
                if (event.data.str("participant") == myKey()) return
                applyReceipt(event.data.str("delivered_up_to"), "delivered")
                applyReceipt(event.data.str("read_up_to"), "read")
            }
            "chat.reaction" -> {
                val messageId = event.data.str("message_id") ?: return
                val index = messages.indexOfFirst { it.id == messageId }
                if (index < 0) return
                val summary = runCatching {
                    event.data.getAsJsonArray("summary").map { gson.fromJson(it, ReactionSummaryDto::class.java) }
                }.getOrDefault(emptyList())
                val old = messages[index].dto
                val mine = if (event.data.str("participant") == myKey()) event.data.str("emoji") else old.reactions.mine
                messages[index] = messages[index].copy(dto = old.copy(reactions = ReactionsDto(summary, mine, summary.sumOf { it.count })))
            }
            "chat.pins.updated" -> scope.launch { refreshPinned() }
            "chat.conversation.updated" -> scope.launch {
                runCatching { ApiClient.chat.conversation(chatAuth(), id).data }.getOrNull()?.let { conversation = it }
            }
        }
    }

    /** Everything I sent up to `uuid` gets `status` (never downgrading read → delivered). */
    private fun applyReceipt(uuid: String?, status: String) {
        uuid ?: return
        val upTo = messages.indexOfFirst { it.id == uuid }
        if (upTo < 0) return
        for (i in 0..upTo) {
            val m = messages[i]
            if (!m.isMine || m.local != null || m.dto.system != null) continue
            if (m.dto.status == "read" || m.dto.status == status) continue
            messages[i] = m.copy(dto = m.dto.copy(status = status))
        }
    }

    private fun parseMessage(json: JsonObject?): MessageDto? = json?.let { runCatching { gson.fromJson(it, MessageDto::class.java) }.getOrNull() }

    fun replaceMessage(id: String, message: UiMessage) {
        val index = messages.indexOfFirst { it.id == id }
        if (index >= 0) messages[index] = message.copy(fresh = false) else messages.add(message)
        persist()
    }
}

/** A file upload body that reports how many bytes went out. */
private class ProgressBody(private val file: File, private val mime: String, private val onBytes: (Long) -> Unit) : RequestBody() {
    override fun contentType() = mime.toMediaTypeOrNull()

    override fun contentLength() = file.length()

    override fun writeTo(sink: okio.BufferedSink) {
        file.inputStream().use { input ->
            val buffer = ByteArray(64 * 1024)
            while (true) {
                val read = input.read(buffer)
                if (read < 0) break
                sink.write(buffer, 0, read)
                onBytes(read.toLong())
            }
        }
    }
}

/**
 * Photos are shrunk before upload (longest side 1600px, JPEG 82%, right way up) — a 6 MB camera
 * shot becomes ~300 KB and sends in a blink. GIFs and anything that fails keep the original.
 */
internal fun compressImage(context: Context, file: LocalFile): LocalFile {
    if (!file.mime.startsWith("image/") || file.mime == "image/gif") return file
    return runCatching {
        val bounds = android.graphics.BitmapFactory.Options().apply { inJustDecodeBounds = true }
        android.graphics.BitmapFactory.decodeFile(file.file.absolutePath, bounds)
        val longest = maxOf(bounds.outWidth, bounds.outHeight)
        if (longest <= 0) return file
        var sample = 1
        while (longest / (sample * 2) >= 1600) sample *= 2
        val decoded = android.graphics.BitmapFactory.decodeFile(file.file.absolutePath, android.graphics.BitmapFactory.Options().apply { inSampleSize = sample }) ?: return file
        val scale = (1600f / maxOf(decoded.width, decoded.height)).coerceAtMost(1f)
        var bitmap = if (scale < 1f) android.graphics.Bitmap.createScaledBitmap(decoded, (decoded.width * scale).toInt(), (decoded.height * scale).toInt(), true) else decoded
        val rotation = when (android.media.ExifInterface(file.file.absolutePath).getAttributeInt(android.media.ExifInterface.TAG_ORIENTATION, android.media.ExifInterface.ORIENTATION_NORMAL)) {
            android.media.ExifInterface.ORIENTATION_ROTATE_90 -> 90f
            android.media.ExifInterface.ORIENTATION_ROTATE_180 -> 180f
            android.media.ExifInterface.ORIENTATION_ROTATE_270 -> 270f
            else -> 0f
        }
        if (rotation != 0f) {
            bitmap = android.graphics.Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, android.graphics.Matrix().apply { postRotate(rotation) }, true)
        }
        val out = File(context.cacheDir.resolve("chat-out").apply { mkdirs() }, UUID.randomUUID().toString() + ".jpg")
        out.outputStream().use { bitmap.compress(android.graphics.Bitmap.CompressFormat.JPEG, 82, it) }
        if (out.length() in 1 until file.file.length()) LocalFile(out, "image/jpeg", file.name.substringBeforeLast('.') + ".jpg") else file
    }.getOrDefault(file)
}

// ------------------------------------------------------------------------------- files

/** Copy a picked content:// file into the cache so it can be uploaded (and shown) as a real file. */
internal fun copyToCache(context: Context, uri: Uri, fallbackName: String): LocalFile? = runCatching {
    val resolver = context.contentResolver
    val mime = resolver.getType(uri) ?: "application/octet-stream"
    var name = fallbackName
    resolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { c ->
        if (c.moveToFirst()) c.getString(0)?.let { name = it }
    }
    val dir = File(context.cacheDir, "chat-out").apply { mkdirs() }
    val target = File(dir, "${UUID.randomUUID()}-${name.replace(Regex("[^A-Za-z0-9._-]"), "_")}")
    resolver.openInputStream(uri)?.use { input -> target.outputStream().use { input.copyTo(it) } }
    LocalFile(target, mime, name)
}.getOrNull()

internal fun typeForMime(mime: String): String = when {
    mime.startsWith("image/") -> "image"
    mime.startsWith("video/") -> "video"
    mime.startsWith("audio/") -> "audio"
    else -> "document"
}

internal fun AttachmentDto.isImage(): Boolean = mimeType?.startsWith("image/") == true
