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
import kotlinx.coroutines.flow.drop
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
    /** A translation / voice transcript I asked the AI for, shown under the bubble. */
    val ai: AiNote? = null,
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

    /** Slow mode: when I may send again (epoch ms; 0 = now). Admins never wait. */
    var slowUntil by mutableStateOf(0L)

    /** Messages I unlocked (sensitive) or uncovered (blurred media) — until they hide again. */
    val revealed = androidx.compose.runtime.mutableStateMapOf<String, Long>()

    fun reveal(id: String) {
        val at = System.currentTimeMillis()
        revealed[id] = at
        val seconds = com.dorr.app.chat.ChatShield.rehideSeconds
        // 0 = until I leave the chat (this state goes with it).
        if (seconds > 0) scope.launch {
            kotlinx.coroutines.delay(seconds * 1000L)
            if (revealed[id] == at) revealed.remove(id)
        }
    }

    /** My scheduled messages in this chat (waiting or failed), soonest first. */
    var scheduled by mutableStateOf<List<com.dorr.app.network.ScheduledMessageDto>>(emptyList())
        private set

    /** Group members, for @mention suggestions (loaded on first "@"). */
    var members by mutableStateOf<List<com.dorr.app.network.MemberDto>>(emptyList())
        private set

    private val gson = Gson()
    private var typingJob: Job? = null
    private var lastTypingSent = 0L

    /** Messages that failed for lack of a connection → automatic tries so far (never a server "no"). */
    private val autoRetries = mutableMapOf<String, Int>()

    init {
        // While this chat is open it sends its own messages; the background outbox leaves it alone.
        com.dorr.app.chat.ChatOutbox.opened(id)
        scope.launch { load() }
        scope.launch { ChatRealtime.events.collect { onEvent(it) } }
        // Back online: everything that failed for lack of a connection goes again, by itself.
        scope.launch {
            com.dorr.app.network.NetworkMonitor.current()?.reconnectTick?.drop(1)?.collect {
                autoRetries.keys.toList().forEach { uuid ->
                    autoRetries[uuid] = 0
                    messages.firstOrNull { it.id == uuid && it.local == "failed" }?.let { retry(it, quiet = true) }
                }
            }
        }
    }

    /** The page left: anything still unsent goes on in the background (even if the app closes). */
    fun close() = com.dorr.app.chat.ChatOutbox.closed(context, id)

    /**
     * A send that never reached the server (offline, tunnel down, timeout) is tried again after
     * 5 s, 15 s and 45 s — always with the same uuid, so it can't arrive twice.
     */
    private fun scheduleAutoRetry(uuid: String) {
        val tries = autoRetries[uuid] ?: 0
        if (tries >= AutoRetryDelays.size) return
        autoRetries[uuid] = tries + 1
        scope.launch {
            kotlinx.coroutines.delay(AutoRetryDelays[tries])
            messages.firstOrNull { it.id == uuid && it.local == "failed" }?.let { retry(it, quiet = true) }
        }
    }

    suspend fun load() {
        // Offline first: what this phone saved last time shows at once — and without internet.
        if (messages.isEmpty()) {
            com.dorr.app.chat.ChatStore.messages(id)?.takeIf { it.isNotEmpty() }?.let { cached ->
                messages.addAll(cached.map { UiMessage(it) })
            }
        }
        // What I sent here that the server doesn't have yet (sent offline, app closed since): back
        // in place, and sent again below.
        val restored = mutableListOf<UiMessage>()
        com.dorr.app.chat.ChatOutbox.pendingFor(id).forEach { item ->
            if (messages.none { it.id == item.uuid }) {
                val out = com.dorr.app.chat.ChatOutbox.outgoingOf(item)
                val ui = UiMessage(item.message, local = "failed", localFiles = out.files, localThumb = out.thumbnail)
                messages.add(ui)
                if (!item.rejected) restored += ui
            }
        }
        loading = messages.isEmpty()
        try {
            val fresh = ApiClient.chat.conversation(chatAuth(), id).data
            if (fresh != null) conversation = fresh
            val page = ApiClient.chat.messages(chatAuth(), id).data
            if (page != null) {
                // Keep bubbles still on their way up; replace the rest with the server's truth.
                // One the server already has (the background sender got there first) isn't kept twice.
                val unsent = messages.filter { it.local != null && page.messages.none { p -> p.id == it.id } }
                page.messages.forEach { p -> if (p.isMine == true) com.dorr.app.chat.ChatOutbox.sent(p.id) }
                messages.clear()
                messages.addAll(page.messages.map { UiMessage(it) })
                messages.addAll(unsent)
                hasMoreBefore = page.hasMoreBefore
                hasMoreAfter = false
                persist()
            }
            failed = false
            refreshPinned()
            refreshScheduled()
            markRead()
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            failed = messages.isEmpty()
        }
        loading = false
        restored.forEach { ui -> messages.firstOrNull { it.id == ui.id && it.local != null }?.let { retry(it, quiet = true) } }
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

    suspend fun refreshScheduled() {
        runCatching { ApiClient.chat.scheduled(chatAuth(), id).data }.getOrNull()?.let { scheduled = it }
    }

    /** Write now, send at [sendAt] (the server sends it, even with this phone off). */
    suspend fun schedule(body: String, sendAt: java.time.ZonedDateTime, silent: Boolean = false): Boolean = try {
        val row = ApiClient.chat.schedule(chatAuth(), id, buildMap {
            put("body", body)
            put("send_at", sendAt.format(java.time.format.DateTimeFormatter.ISO_OFFSET_DATE_TIME))
            if (silent) put("silent", true)
        }).data
        if (row != null) scheduled = (scheduled + row).sortedBy { it.sendAt }
        true
    } catch (e: CancellationException) {
        throw e
    } catch (e: Exception) {
        host.showToast(e.apiFailure().message ?: context.getString(com.dorr.app.R.string.ch_error_network))
        false
    }

    // ------------------------------------------------------------------ AI (each one tap)

    /** Replies the AI suggested (shown as chips above the composer). */
    var smartReplies by mutableStateOf<List<String>>(emptyList())
        private set
    var loadingReplies by mutableStateOf(false)
        private set

    fun translate(message: UiMessage) = askAi(message, "translation") {
        ApiClient.chat.translate(chatAuth(), message.id, mapOf("to" to com.dorr.app.network.AppLocale.current)).data?.text
    }

    fun transcribe(message: UiMessage) = askAi(message, "transcript") {
        ApiClient.chat.transcribe(chatAuth(), message.id).data?.text
    }

    fun hideAi(message: UiMessage) = setAi(message.id, null)

    private fun askAi(message: UiMessage, kind: String, call: suspend () -> String?) {
        setAi(message.id, AiNote(kind, loading = true))
        scope.launch {
            try {
                setAi(message.id, AiNote(kind, text = call()))
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                setAi(message.id, AiNote(kind, failed = e.apiFailure().message ?: context.getString(com.dorr.app.R.string.ch_error_network)))
            }
        }
    }

    private fun setAi(id: String, note: AiNote?) {
        val index = messages.indexOfFirst { it.id == id }
        if (index >= 0) messages[index] = messages[index].copy(ai = note, fresh = false)
    }

    fun loadSmartReplies() {
        if (loadingReplies) return
        loadingReplies = true
        scope.launch {
            try {
                smartReplies = ApiClient.chat.smartReplies(chatAuth(), id).data?.replies.orEmpty()
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                host.showToast(e.apiFailure().message ?: context.getString(com.dorr.app.R.string.ch_error_network))
            }
            loadingReplies = false
        }
    }

    fun clearSmartReplies() {
        smartReplies = emptyList()
    }

    /** Slow mode applies to me here: a group member (not an admin) in a group that has it on. */
    val slowModeSeconds: Int
        get() = conversation?.takeIf { it.isGroup && !it.isChannel && !it.isAdmin }?.group?.slowModeSeconds ?: 0

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
            // Drawn right away while it uploads: the poll's options, the "1" of a view-once.
            viewOnce = out.extra["view_once"] != null,
            poll = if (out.type == "poll") com.dorr.app.network.PollDto(
                question = out.body,
                multiple = out.extra["poll_multiple"] == true,
                options = (out.extra["poll_options"] as? List<*>).orEmpty().mapIndexed { i, text -> com.dorr.app.network.PollOptionDto(i + 1, text.toString()) },
            ) else null,
            payment = when (out.type) {
                "money_request" -> com.dorr.app.network.PaymentDto(kind = "request", status = "pending", amountMinor = (out.extra["amount_minor"] as? Number)?.toLong() ?: 0, isRequester = true)
                "bill_split" -> com.dorr.app.network.PaymentDto(kind = "split", status = "open", totalMinor = (out.extra["amount_minor"] as? Number)?.toLong() ?: 0, isRequester = true)
                else -> null
            },
        )
        val replyId = replyTo?.id
        replyTo = null
        // Saved on the phone first: it survives no connection and the app being closed.
        val stored = com.dorr.app.chat.ChatOutbox.add(uuid, id, out, replyId, optimistic)
        messages.add(UiMessage(optimistic, local = "pending", localFiles = stored.files, fresh = true, localThumb = stored.thumbnail))
        sendTyping(stop = true)
        // Slow mode: the next one waits (the server counts from this message too).
        slowModeSeconds.takeIf { it > 0 }?.let { slowUntil = System.currentTimeMillis() + it * 1000L }
        upload(uuid, stored, replyId)
    }

    /** [quiet]: an automatic try — no toast if it fails again. */
    fun retry(message: UiMessage, quiet: Boolean = false) {
        val index = messages.indexOfFirst { it.id == message.id }
        if (index < 0) return
        messages[index] = message.copy(local = "pending")
        val dto = message.dto
        com.dorr.app.chat.ChatOutbox.retrying(dto.id)
        // From the outbox when it's there (its files, its reply); else rebuilt from the bubble.
        val kept = com.dorr.app.chat.ChatOutbox.pendingFor(id).firstOrNull { it.uuid == dto.id }
        @Suppress("UNCHECKED_CAST")
        val out = kept?.let { com.dorr.app.chat.ChatOutbox.outgoingOf(it) } ?: Outgoing(
            dto.type, dto.body, message.localFiles,
            dto.meta?.let { com.dorr.app.chat.ChatSender.normalize(gson.fromJson(it, Map::class.java)) as? Map<String, Any?> }.orEmpty(),
            message.localThumb,
        )
        upload(dto.id, out, kept?.replyTo ?: dto.replyTo?.id, quiet)
    }

    // ------------------------------------------------------------------ polls, view once, live location

    /**
     * Tap an option: ticks it (single choice replaces, multiple toggles), shows the new totals at
     * once, then keeps the server's numbers.
     */
    fun vote(message: UiMessage, optionId: Int) {
        val poll = message.dto.poll ?: return
        val picked = when {
            optionId in poll.myVotes -> poll.myVotes - optionId
            poll.multiple -> poll.myVotes + optionId
            else -> listOf(optionId)
        }
        val before = poll.myVotes.toSet()
        val optimistic = poll.copy(
            options = poll.options.map { o ->
                val delta = (if (o.id in picked) 1 else 0) - (if (o.id in before) 1 else 0)
                o.copy(votes = (o.votes + delta).coerceAtLeast(0))
            },
            myVotes = picked,
            voters = poll.voters + (if (before.isEmpty() && picked.isNotEmpty()) 1 else 0) - (if (before.isNotEmpty() && picked.isEmpty()) 1 else 0),
        )
        replaceMessage(message.id, message.copy(dto = message.dto.copy(poll = optimistic)))
        scope.launch {
            try {
                ApiClient.chat.vote(chatAuth(), message.id, mapOf("options" to picked)).data?.let { fresh ->
                    val current = messages.firstOrNull { it.id == message.id } ?: return@let
                    replaceMessage(message.id, current.copy(dto = current.dto.copy(poll = fresh.poll)))
                }
            } catch (e: Exception) {
                replaceMessage(message.id, message)
                e.apiFailure().message?.let { host.showToast(it) }
            }
        }
    }

    /** The files of a view-once message, this one time — null when it can't be opened. */
    suspend fun openViewOnce(message: UiMessage): List<com.dorr.app.network.AttachmentDto>? {
        return try {
            val files = ApiClient.chat.openViewOnce(chatAuth(), message.id).data?.attachments
            replaceMessage(message.id, message.copy(dto = message.dto.copy(viewOnceOpened = true)))
            files
        } catch (e: Exception) {
            if (e.apiFailure().httpStatus == 410) replaceMessage(message.id, message.copy(dto = message.dto.copy(viewOnceOpened = true)))
            e.apiFailure().message?.let { host.showToast(it) }
            null
        }
    }

    /** A money request / split answered on the server (paid, declined, cancelled): keep its new state. */
    fun paymentUpdated(fresh: MessageDto) {
        val current = messages.firstOrNull { it.id == fresh.id } ?: return
        replaceMessage(fresh.id, current.copy(dto = current.dto.copy(payment = fresh.payment, meta = fresh.meta)))
    }

    fun declineRequest(message: UiMessage) {
        scope.launch {
            try {
                ApiClient.chat.declineRequest(chatAuth(), message.id).data?.let(::paymentUpdated)
            } catch (e: Exception) {
                e.apiFailure().message?.let { host.showToast(it) }
            }
        }
    }

    fun cancelRequest(message: UiMessage) {
        scope.launch {
            try {
                ApiClient.chat.cancelRequest(chatAuth(), message.id).data?.let(::paymentUpdated)
            } catch (e: Exception) {
                e.apiFailure().message?.let { host.showToast(it) }
            }
        }
    }

    fun stopLive(message: UiMessage) {
        scope.launch {
            runCatching { ApiClient.chat.stopLive(chatAuth(), message.id).data }.getOrNull()?.let { fresh ->
                replaceMessage(message.id, message.copy(dto = fresh.copy(isMine = true, status = message.dto.status)))
            }
            com.dorr.app.chat.LiveLocationSharing.stop(context, message.id)
        }
    }

    private fun upload(uuid: String, out: Outgoing, replyId: String?, quiet: Boolean = false) {
        scope.launch {
            try {
                val sent = com.dorr.app.chat.ChatSender.send(context, id, uuid, out, replyId) { progress ->
                    scope.launch(kotlinx.coroutines.Dispatchers.Main) {
                        val index = messages.indexOfFirst { it.id == uuid }
                        if (index >= 0 && messages[index].local == "pending") messages[index] = messages[index].copy(progress = progress)
                    }
                }
                com.dorr.app.chat.ChatOutbox.sent(uuid)
                autoRetries.remove(uuid)
                sent.data?.let {
                    replaceMessage(uuid, UiMessage(it))
                    // A live location starts its position updates as soon as the server has it.
                    it.liveLocation?.takeIf { live -> live.active }?.liveUntil?.let { until ->
                        com.dorr.app.chat.LiveLocationSharing.start(context, it.id, until)
                    }
                }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                val failure = e.apiFailure()
                val index = messages.indexOfFirst { it.id == uuid }
                if (index >= 0) messages[index] = messages[index].copy(local = "failed")
                android.util.Log.w("DorrChat", "send ${out.type} failed", e)
                // Slow mode (the app didn't know yet — e.g. just switched on): it goes by itself
                // once the wait is over, and the send button counts down meanwhile.
                if (failure.errorCode == "chat_slow_mode") {
                    val wait = (failure.retryAfter ?: slowModeSeconds).coerceAtLeast(1)
                    slowUntil = System.currentTimeMillis() + wait * 1000L
                    scope.launch {
                        kotlinx.coroutines.delay(wait * 1000L + 500)
                        messages.firstOrNull { it.id == uuid && it.local == "failed" }?.let { retry(it, quiet = true) }
                    }
                    if (!quiet) failure.message?.let { host.showToast(it) }
                    return@launch
                }
                // No HTTP answer at all (offline, tunnel down, timeout): it goes again by itself.
                // A real answer from the server (validation, blocked…) is final — tap to retry.
                if (e !is retrofit2.HttpException) {
                    scheduleAutoRetry(uuid)
                } else {
                    autoRetries.remove(uuid)
                    com.dorr.app.chat.ChatOutbox.rejected(uuid)
                }
                if (!quiet) (failure.message ?: context.getString(com.dorr.app.R.string.ch_error_network)).let { host.showToast(it) }
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
        // An unsent one is simply dropped from the outbox (never sent).
        com.dorr.app.chat.ChatOutbox.sent(message.id)
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

    fun readLater(message: MessageDto) {
        val on = !message.isReadLater
        replaceMessage(message.id, UiMessage(message.copy(isReadLater = on)))
        scope.launch { runCatching { ApiClient.chat.readLater(chatAuth(), message.id, mapOf("on" to on)) } }
    }

    fun followUp(message: MessageDto) {
        val on = !message.isFollowUp
        replaceMessage(message.id, UiMessage(message.copy(isFollowUp = on)))
        scope.launch { runCatching { ApiClient.chat.followUp(chatAuth(), message.id, mapOf("on" to on)) } }
    }

    /** Remind me about this message at [at] (null: take the reminder off). */
    fun remind(message: MessageDto, at: java.time.ZonedDateTime?) = scope.launch {
        try {
            if (at == null) {
                ApiClient.chat.clearReminder(chatAuth(), message.id)
                replaceMessage(message.id, UiMessage(message.copy(reminderAt = null)))
            } else {
                val iso = at.format(java.time.format.DateTimeFormatter.ISO_OFFSET_DATE_TIME)
                ApiClient.chat.setReminder(chatAuth(), message.id, mapOf("remind_at" to iso))
                replaceMessage(message.id, UiMessage(message.copy(reminderAt = iso)))
                host.showToast(context.getString(com.dorr.app.R.string.ch_reminder_set, scheduleLabel(at)))
            }
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            host.showToast(e.apiFailure().message ?: context.getString(com.dorr.app.R.string.ch_error_network))
        }
    }

    /** "Save to my notes": a copy in my note-to-self chat — a private place of my own, apart from stars. */
    fun saveToNotes(message: MessageDto, done: String) = scope.launch {
        try {
            val notes = ApiClient.chat.openSelf(chatAuth()).data ?: return@launch
            ApiClient.chat.forward(chatAuth(), mapOf("messages" to listOf(message.id), "conversations" to listOf(notes.id)))
            host.showToast(done)
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            host.showToast(e.apiFailure().message ?: context.getString(com.dorr.app.R.string.ch_error_network))
        }
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
        // Nobody to tell in a request I haven't accepted, or in my own notes.
        if (c.isRequest || c.isSelf) return
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
            // One of my scheduled messages went out, failed, or changed on another device.
            "chat.scheduled.changed" -> scope.launch { refreshScheduled() }
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
                // A money card is drawn per viewer (can I pay? my share): the broadcast is the
                // requester's view, so read my own version of it.
                if (dto.payment != null && event.name == "chat.message.updated") {
                    scope.launch {
                        runCatching { ApiClient.chat.messages(chatAuth(), id, around = dto.id, limit = 1).data?.messages }.getOrNull()
                            ?.firstOrNull { it.id == dto.id }?.let(::paymentUpdated)
                    }
                    return
                }
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
            // Someone voted: new totals (and my own ticks when it was me, from another device).
            "chat.poll.updated" -> {
                val index = messages.indexOfFirst { it.id == event.data.str("message_id") }
                if (index < 0) return
                val old = messages[index].dto
                val poll = old.poll ?: return
                val counts = event.data.getAsJsonObject("counts")
                val mineNow = if (event.data.str("participant") == myKey()) {
                    event.data.getAsJsonArray("option_ids")?.mapNotNull { runCatching { it.asInt }.getOrNull() } ?: poll.myVotes
                } else poll.myVotes
                messages[index] = messages[index].copy(dto = old.copy(poll = poll.copy(
                    options = poll.options.map { o -> o.copy(votes = counts?.get(o.id.toString())?.asInt ?: 0) },
                    myVotes = mineNow,
                    voters = event.data.get("voters")?.asInt ?: poll.voters,
                )))
            }
            // My view-once was opened (or I opened it on another device): it can't be opened again.
            "chat.view_once.opened" -> {
                val index = messages.indexOfFirst { it.id == event.data.str("message_id") }
                if (index < 0) return
                val m = messages[index]
                if (m.isMine || event.data.str("participant") == myKey()) messages[index] = m.copy(dto = m.dto.copy(viewOnceOpened = true))
            }
            // A live location moved: the pin walks to the new spot.
            "chat.location.moved" -> {
                val index = messages.indexOfFirst { it.id == event.data.str("message_id") }
                if (index < 0) return
                val old = messages[index].dto
                val meta = (old.meta?.deepCopy() ?: com.google.gson.JsonObject()).apply {
                    event.data.get("latitude")?.let { add("latitude", it) }
                    event.data.get("longitude")?.let { add("longitude", it) }
                }
                messages[index] = messages[index].copy(dto = old.copy(meta = meta, liveLocation = old.liveLocation?.copy(updatedAt = event.data.str("updated_at"))))
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

/** Gaps before each automatic resend of a message that never reached the server. */
private val AutoRetryDelays = listOf(5_000L, 15_000L, 45_000L)
