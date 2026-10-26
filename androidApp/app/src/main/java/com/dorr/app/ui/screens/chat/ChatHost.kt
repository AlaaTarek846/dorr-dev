package com.dorr.app.ui.screens.chat

import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import com.dorr.app.chat.ChatEvent
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.LastMessageDto
import com.dorr.app.network.PresenceDto
import com.google.gson.Gson
import com.google.gson.JsonObject
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** The chat's own screen stack (like the wallet's). */
sealed interface ChRoute {
    data object List : ChRoute
    data class Conversation(val id: String, val preview: ConversationDto? = null) : ChRoute
    data class Info(val id: String) : ChRoute
    /** Everything shared in a chat: photos & videos, files, links, voice — [tab] opens on one of them. */
    data class Media(val id: String, val tab: String = "media") : ChRoute
    /** Wallpaper and bubble colours for one chat (only I see it). */
    data class ChatTheme(val id: String) : ChRoute
    /** Wallet balance and the money in this chat (transfers, requests, splits). */
    data class ChatMoney(val id: String) : ChRoute
    /** Everyone in a group or channel, with search. */
    data class GroupMembers(val id: String) : ChRoute
    data object NewChat : ChRoute
    /** Find and follow public channels. */
    data object Channels : ChRoute
    /** A new group — or, with `addTo`, more members for an existing one. */
    data class NewGroup(val addTo: String? = null) : ChRoute
    data object MyQr : ChRoute
    /** Emoji + a few words under my name in other people's chats (spec 90). */
    data object MyStatus : ChRoute
    /** Overflow → Settings: grouped chat tools (same chrome as Account settings). */
    data object ChatSettings : ChRoute
    data object Privacy : ChRoute
    /** Blocked contacts: search and pages of 15. */
    data object BlockedContacts : ChRoute
    /** My business tools: opening hours, welcome / away messages, quick replies. */
    data object Business : ChRoute
    data object Starred : ChRoute
    /** Messages I set aside to read later. */
    data object ReadLater : ChRoute
    data object Calls : ChRoute
    /** Communities (WhatsApp list 93–98): mine, and one of them. */
    data object Communities : ChRoute
    data class Community(val id: String) : ChRoute
    /** Write a text story, or caption a picked photo / video (`media`). */
    data class StoryComposer(val media: String? = null) : ChRoute
    data object StoryPrivacy : ChRoute
    /** A thread under one message (spec 122). */
    data class Thread(val conversationId: String, val rootId: String) : ChRoute
    /** A group's decisions log (spec 120). */
    data class Decisions(val conversationId: String, val isAdmin: Boolean) : ChRoute
    data object Broadcasts : ChRoute
    data class Broadcast(val id: String) : ChRoute
    /** My tasks (spec 38). */
    data object Tasks : ChRoute
    /** "What I missed" (spec 123). */
    data object CatchUp : ChRoute
    /** Every privacy choice in one place (spec 127). */
    data object PrivacyCenter : ChRoute
    /** A group decision's room (spec 153). */
    data class DecisionRoom(val id: String) : ChRoute
    /** My commitments and handoffs (spec 149, 152). */
    data object Commitments : ChRoute
    /** My communication rules: context, attention, always through, calls (spec 146, 151, 155). */
    data object CommRules : ChRoute
    /** What one context does (home / work / …) — one screen, title from the key. */
    data class ContextRules(val key: String) : ChRoute
    /** What needs something from me (spec 147). */
    data object LifeInbox : ChRoute
    /** Data saver and lite mode (spec 82, 87). */
    data object DataPerformance : ChRoute
    /** Usage stats (spec 83). */
    data object Usage : ChRoute
    /** Manage storage (backlog 46–48): all my chats, or one chat's files. */
    data class Storage(val conversationId: String? = null, val title: String? = null) : ChRoute
    /** A channel's numbers (backlog 107). */
    data class ChannelStats(val conversationId: String) : ChRoute
    /** A group's standing voice room (spec 91). */
    data class VoiceRoom(val conversationId: String, val title: String?) : ChRoute
    /** Watching a call broadcast live (spec 93). */
    data class LiveViewer(val token: String) : ChRoute
}

/** The full-screen story viewer: which people's stories it pages through, and where it starts. */
data class StoryViewerSession(val groups: kotlin.collections.List<com.dorr.app.network.StoryGroupDto>, val start: Int)

/** What someone is doing right now in a conversation. */
data class Activity(val participant: String, val state: String)

/** The answer to my request to join a group. */
data class JoinDecision(val conversationId: String, val groupName: String, val approved: Boolean)

/**
 * State shared by every chat page for one visit: the page stack, the chat list (kept live from
 * real-time events — new messages move a chat to the top with an animation), who is typing where,
 * who is online, and a toast.
 */
@Stable
class ChatHost(val scope: CoroutineScope, var onExit: () -> Unit, val openWalletQr: (String) -> Unit) {
    val stack = mutableStateListOf<ChRoute>(ChRoute.List)
    val current: ChRoute get() = stack.last()
    var forward by mutableStateOf(true)
        private set

    /**
     * A wide screen (tablet, unfolded phone, landscape — spec 89): the chat list stays on the side
     * and every other page opens beside it. Set by ChatScreen from the space it really has.
     */
    var twoPane by mutableStateOf(false)

    /** Which Chat-settings group is open, kept while QR / starred / privacy sit on top of it. */
    internal var chatSettingsSection by mutableStateOf(ChatSetSection.Menu)

    /** What opens beside the list: the page on top, unless that's the list itself. */
    val detail: ChRoute? get() = stack.last().takeIf { it !is ChRoute.List }

    /** The conversation open beside the list (marked in it). */
    val openConversationId: String? get() = (stack.lastOrNull { it is ChRoute.Conversation } as? ChRoute.Conversation)?.id

    val conversations = mutableStateListOf<ConversationDto>()
    var requestsCount by mutableIntStateOf(com.dorr.app.chat.ChatStore.requestsCount())
    var listLoaded by mutableStateOf(false)
    var listFailed by mutableStateOf(false)
    var filter by mutableStateOf("all")
    var listHasMore by mutableStateOf(false)
    var listLoadingMore by mutableStateOf(false)
    private var listPage = 1

    /** conversation id → people currently typing / recording there. */
    val activity = mutableStateMapOf<String, List<Activity>>()

    /** participant key → online / last seen (from `chat.presence`). */
    val presence = mutableStateMapOf<String, PresenceDto>()

    /** The stories bar (null until loaded; stays null when stories are off). */
    var stories by mutableStateOf<com.dorr.app.network.StoryFeedDto?>(null)
    var storiesLoadingMore by mutableStateOf(false)
    private var storiesPage = 1
    var storyViewer by mutableStateOf<StoryViewerSession?>(null)
    /** A story being uploaded — the "my status" ring spins meanwhile. */
    var storyUploading by mutableStateOf(false)

    /**
     * The home page's host (HomeStories): stories posted from it are public, and "refresh" reloads
     * the home page's circles instead of the chat's stories bar.
     */
    var publicMode = false
    var onRefreshStories: (suspend () -> Unit)? = null

    suspend fun refreshStories() {
        onRefreshStories?.let { it(); return }
        runCatching { ApiClient.chat.stories(chatAuth(), page = 1, perPage = PAGE).data }.getOrNull()?.let {
            stories = it
            storiesPage = 1
        }
    }

    suspend fun loadMoreStories() {
        if (publicMode) return
        val current = stories ?: return
        if (storiesLoadingMore || !current.hasMore) return
        storiesLoadingMore = true
        try {
            val next = runCatching { ApiClient.chat.stories(chatAuth(), page = storiesPage + 1, perPage = PAGE).data }.getOrNull() ?: return
            val known = (current.recent + current.muted).map { it.owner?.key }.toSet()
            stories = current.copy(
                recent = current.recent + next.recent.filter { it.owner?.key !in known },
                muted = current.muted + next.muted.filter { it.owner?.key !in known },
                hasMore = next.hasMore,
            )
            storiesPage += 1
        } finally {
            storiesLoadingMore = false
        }
    }

    fun openStories(groups: kotlin.collections.List<com.dorr.app.network.StoryGroupDto>, start: Int) {
        if (groups.isNotEmpty()) storyViewer = StoryViewerSession(groups, start.coerceIn(0, groups.lastIndex))
    }

    var toast by mutableStateOf<String?>(null)
    var toastError by mutableStateOf(false)
        private set
    private var toastJob: Job? = null
    private val typingJobs = mutableMapOf<String, Job>()
    private val gson = Gson()

    fun push(route: ChRoute) {
        // Side by side, a conversation takes the place of whatever was open beside the list (like
        // WhatsApp on a tablet) — or, already open there, it comes back to the front.
        if (twoPane && route is ChRoute.Conversation) {
            val open = stack.indexOfFirst { it is ChRoute.Conversation && it.id == route.id }
            if (open >= 0) {
                forward = false
                while (stack.lastIndex > open) stack.removeAt(stack.lastIndex)
                return
            }
            while (stack.size > 1) stack.removeAt(stack.lastIndex)
        }
        forward = true
        stack.add(route)
    }

    fun pop() {
        if (stack.size <= 1) {
            onExit()
            return
        }
        forward = false
        val leaving = stack.last()
        stack.removeAt(stack.lastIndex)
        if (leaving is ChRoute.ChatSettings) chatSettingsSection = ChatSetSection.Menu
    }

    /** Replace the top page (e.g. "New chat" → the conversation it opened). */
    fun replace(route: ChRoute) {
        if (twoPane && route is ChRoute.Conversation) {
            push(route)
            return
        }
        forward = true
        stack[stack.lastIndex] = route
    }

    /** A message the conversation page should scroll to and flash once it's open (conversation id → message id). */
    var focusRequest by mutableStateOf<Pair<String, String>?>(null)

    /**
     * "Show in chat" (media page, starred…): back to that conversation if it's already open under
     * this page — no second copy of it — or open it; then it scrolls to the message.
     */
    fun showInChat(conversationId: String, messageId: String) {
        focusRequest = conversationId to messageId
        val open = stack.indexOfLast { it is ChRoute.Conversation && it.id == conversationId }
        if (open >= 0) {
            forward = false
            while (stack.lastIndex > open) stack.removeAt(stack.lastIndex)
        } else {
            push(ChRoute.Conversation(conversationId))
        }
    }

    fun showToast(message: String, error: Boolean = false) {
        if (message.isBlank()) return
        toastError = error
        toast = message
        toastJob?.cancel()
        toastJob = scope.launch {
            delay(2300)
            toast = null
        }
    }

    // ------------------------------------------------------------------ list

    /** My folders (the extra chips after All / Unread / Groups / Archived). */
    val folders = mutableStateListOf<com.dorr.app.network.FolderDto>()

    /** My privacy circles (spec 98) — chips next to the folders; "circle:{id}" as the filter. */
    val circles = mutableStateListOf<com.dorr.app.network.CircleDto>()

    suspend fun refreshCircles() {
        runCatching { ApiClient.organize.circles(chatAuth()).data }.getOrNull()?.let {
            circles.clear()
            circles.addAll(it)
        }
    }

    /** The folder whose chats are being picked (FolderChatsSheet), if any. */
    var fillingFolder by mutableStateOf<com.dorr.app.network.FolderDto?>(null)

    suspend fun refreshFolders() {
        runCatching { ApiClient.chat.folders(chatAuth()).data }.getOrNull()?.let {
            folders.clear()
            folders.addAll(it)
        }
    }

    suspend fun refreshList() {
        // Offline first: the last list saved on the phone shows instantly (and without internet).
        if (!listLoaded && filter == "all") {
            com.dorr.app.chat.ChatStore.conversations()?.takeIf { it.isNotEmpty() }?.let {
                conversations.clear()
                conversations.addAll(it)
                requestsCount = com.dorr.app.chat.ChatStore.requestsCount()
                listLoaded = true
            }
        }
        try {
            // "folder:12" is one of my folders; everything else is a server filter.
            val folderId = filter.removePrefix("folder:").takeIf { filter.startsWith("folder:") }?.toIntOrNull()
            val circleId = filter.removePrefix("circle:").takeIf { filter.startsWith("circle:") }
            val result = ApiClient.chat.conversations(
                chatAuth(),
                filter = filter.takeIf { it != "all" && folderId == null && circleId == null },
                folder = folderId,
                circle = circleId,
                page = 1,
                perPage = PAGE,
            )
            conversations.clear()
            conversations.addAll(result.data.orEmpty())
            requestsCount = result.requestsCount
            listPage = 1
            listHasMore = result.pagination?.hasMorePages == true
            listFailed = false
            if (filter == "all") {
                com.dorr.app.chat.ChatStore.saveConversations(conversations.toList())
                com.dorr.app.chat.ChatStore.saveRequestsCount(requestsCount)
            }
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            if (!listLoaded) listFailed = true
        }
        listLoaded = true
    }

    suspend fun loadMoreList() {
        if (listLoadingMore || !listHasMore) return
        listLoadingMore = true
        try {
            val folderId = filter.removePrefix("folder:").takeIf { filter.startsWith("folder:") }?.toIntOrNull()
            val circleId = filter.removePrefix("circle:").takeIf { filter.startsWith("circle:") }
            val result = ApiClient.chat.conversations(
                chatAuth(),
                filter = filter.takeIf { it != "all" && folderId == null && circleId == null },
                folder = folderId,
                circle = circleId,
                page = listPage + 1,
                perPage = PAGE,
            )
            val known = conversations.map { it.id }.toSet()
            conversations.addAll(result.data.orEmpty().filter { it.id !in known })
            listPage += 1
            listHasMore = result.pagination?.hasMorePages == true
        } catch (e: CancellationException) {
            throw e
        } catch (_: Exception) {
        } finally {
            listLoadingMore = false
        }
    }

    /** Put one updated conversation back in the list, keeping pinned chats on top. */
    fun upsert(conversation: ConversationDto) {
        val index = conversations.indexOfFirst { it.id == conversation.id }
        if (index >= 0) conversations.removeAt(index)
        val belongsHere = when {
            filter == "archived" -> conversation.isArchived
            filter == "requests" -> conversation.isRequest
            filter == "locked" -> conversation.isLocked
            filter == "groups" -> conversation.isGroup && !conversation.isChannel && !conversation.isArchived && !conversation.isLocked
            filter == "channels" -> conversation.isChannel && !conversation.isArchived && !conversation.isLocked
            filter == "unread" -> (conversation.unreadCount > 0 || conversation.markedUnread) && !conversation.isArchived && !conversation.isLocked
            filter.startsWith("folder:") -> folders.firstOrNull { "folder:${it.id}" == filter }?.conversationIds?.contains(conversation.id) == true
            else -> !conversation.isArchived && !conversation.isRequest && !conversation.isLocked
        }
        if (!belongsHere) return
        val insertAt = if (conversation.isPinned) 0 else conversations.indexOfFirst { !it.isPinned }.let { if (it < 0) conversations.size else it }
        conversations.add(insertAt, conversation)
        if (filter == "all") com.dorr.app.chat.ChatStore.saveConversations(conversations.toList())
    }

    fun remove(id: String) {
        conversations.removeAll { it.id == id }
    }

    fun find(id: String): ConversationDto? = conversations.firstOrNull { it.id == id }

    /** The open conversation read everything: clear its badge right away. */
    fun markReadLocally(id: String) {
        val c = find(id) ?: return
        val index = conversations.indexOf(c)
        if (index >= 0) conversations[index] = c.copy(unreadCount = 0, markedUnread = false, hasUnreadMention = false)
    }

    // ------------------------------------------------------------------ real-time

    fun handle(event: ChatEvent, openConversationId: String?) {
        val data = event.data
        val conversationId = data.str("conversation_id")
        when (event.name) {
            "chat.message.sent" -> {
                val message = data.obj("message") ?: return
                val id = conversationId ?: return
                val senderKey = message.obj("sender")?.str("key")
                val mine = senderKey == myKey()
                if (!mine && message.get("system")?.isJsonObject != true) com.dorr.app.chat.ChatUsage.messageReceived()
                val existing = find(id)
                if (existing == null) {
                    scope.launch { reload(id) }
                } else {
                    val last = LastMessageDto(
                        id = message.str("id").orEmpty(),
                        type = message.str("type") ?: "text",
                        body = message.str("body"),
                        sender = runCatching { gson.fromJson(message.getAsJsonObject("sender"), com.dorr.app.network.ProfileDto::class.java) }.getOrNull(),
                        isMine = mine,
                        status = if (mine) "sent" else null,
                        isDeleted = false,
                        system = message.obj("system")?.str("event"),
                        createdAt = message.str("created_at"),
                    )
                    val countsAsUnread = !mine && id != openConversationId && senderKey != null
                    upsert(existing.copy(
                        lastMessage = last,
                        lastMessageAt = last.createdAt,
                        unreadCount = if (countsAsUnread) existing.unreadCount + 1 else existing.unreadCount,
                    ))
                }
                if (senderKey != null) clearActivity(id, senderKey)
            }
            "chat.message.deleted", "chat.message.updated", "chat.conversation.updated" -> conversationId?.let { id -> scope.launch { reload(id) } }
            "chat.reminder.due" -> showToast("⏰ " + (data.str("note") ?: find(conversationId.orEmpty())?.title.orEmpty()))
            "chat.receipt" -> conversationId?.let { id ->
                val c = find(id) ?: return
                val last = c.lastMessage ?: return
                if (!last.isMine) return
                val status = when (last.id) {
                    data.str("read_up_to") -> "read"
                    data.str("delivered_up_to") -> if (last.status == "read") "read" else "delivered"
                    else -> return
                }
                val index = conversations.indexOf(c)
                if (index >= 0) conversations[index] = c.copy(lastMessage = last.copy(status = status))
            }
            "chat.typing" -> {
                val id = conversationId ?: return
                val who = data.str("participant") ?: return
                val state = data.str("state") ?: "stopped"
                if (state == "stopped") clearActivity(id, who) else setActivity(id, Activity(who, state))
            }
            // Someone posted / removed a story, or my story got a view / reaction: refresh the bar
            // (the open viewer keeps its own snapshot so it never jumps under the finger).
            "chat.story.posted", "chat.story.deleted", "chat.story.viewed", "chat.story.reaction" -> scope.launch { refreshStories() }
            "chat.presence" -> {
                val who = data.str("participant") ?: return
                presence[who] = PresenceDto(online = data.bool("online") == true, lastSeenAt = data.str("last_seen_at"))
            }
            // Admins: the "asking to join" badge changed.
            "chat.group.join_requests" -> conversationId?.let { id -> scope.launch { reload(id) } }
            // My request was answered: welcome in (the chat appears) or a polite no.
            "chat.group.join_decided" -> {
                val id = conversationId ?: return
                val name = data.str("group_name").orEmpty()
                if (data.str("status") == "approved") {
                    scope.launch { reload(id) }
                    joinDecided = JoinDecision(id, name, approved = true)
                } else {
                    joinDecided = JoinDecision(id, name, approved = false)
                }
            }
        }
    }

    // ------------------------------------------------------------------ joining groups by link

    /** An invite link was tapped (dorr://chat/join/TOKEN): the join sheet shows over the chat. */
    var joinToken by mutableStateOf<String?>(null)
    /** A call link was opened (WhatsApp list 72): its sheet. */
    var callLinkToken by mutableStateOf<String?>(null)

    /** The admins answered my request — a small celebratory (or gentle) card. */
    var joinDecided by mutableStateOf<JoinDecision?>(null)

    private fun setActivity(conversationId: String, activity: Activity) {
        val list = activity(conversationId).filterNot { it.participant == activity.participant } + activity
        this.activity[conversationId] = list
        val key = "$conversationId|${activity.participant}"
        typingJobs[key]?.cancel()
        // The sender re-announces every few seconds; silence means they stopped.
        typingJobs[key] = scope.launch {
            delay(6000)
            clearActivity(conversationId, activity.participant)
        }
    }

    private fun clearActivity(conversationId: String, participant: String) {
        val list = activity(conversationId).filterNot { it.participant == participant }
        if (list.isEmpty()) this.activity.remove(conversationId) else this.activity[conversationId] = list
    }

    fun activity(conversationId: String): List<Activity> = activity[conversationId].orEmpty()

    suspend fun reload(id: String) {
        try {
            ApiClient.chat.conversation(chatAuth(), id).data?.let { fresh ->
                val wasListed = find(id) != null || fresh.lastMessage != null || fresh.isGroup
                if (wasListed) upsert(fresh)
            }
        } catch (e: kotlinx.coroutines.CancellationException) {
            throw e
        } catch (e: Exception) {
            // Gone (the owner deleted the group / channel for everyone): off the list.
            if (e.apiFailure().httpStatus == 404) remove(id)
        }
    }

    fun sendTyping(conversationId: String, state: String) {
        scope.launch { runCatching { ApiClient.chat.typing(chatAuth(), conversationId, mapOf("state" to state)) } }
    }

    init {
        scope.launch { ChatRealtime.events.collect { handle(it, (current as? ChRoute.Conversation)?.id) } }
    }

    companion object {
        const val PAGE = 15
    }
}

/**
 * The code in a group / channel invite, however it came: the web link (https://…/j/CODE), the
 * app's link (dorr://chat/join/CODE), or the code typed by itself. Null when it's none of these.
 */
internal fun inviteTokenOf(text: String): String? {
    val t = text.trim()
    Regex("""(?:dorr://chat/join/|https?://[^\s/]+/j/)([A-Za-z0-9]{8,64})""").find(t)?.let { return it.groupValues[1] }
    return t.takeIf { Regex("[A-Za-z0-9]{8,64}").matches(it) }
}

/** The code in a call link: https://…/call/CODE or dorr://chat/call/CODE. Null when it's not one. */
internal fun callLinkTokenOf(text: String): String? =
    Regex("""(?:dorr://chat/call/|https?://[^\s/]+/call/)([A-Za-z0-9]{8,64})""").find(text.trim())?.groupValues?.get(1)

internal fun JsonObject.str(name: String): String? = get(name)?.takeIf { !it.isJsonNull }?.let { runCatching { it.asString }.getOrNull() }

// Reading a message's `meta` never throws: a missing or null field (JSON null) is just null.
// `getAsJsonObject` / `.asBoolean` on a null field crashed the bubble — and with it the whole chat,
// every time it was opened.
internal fun JsonObject.obj(name: String): JsonObject? = get(name)?.takeIf { it.isJsonObject }?.asJsonObject
internal fun JsonObject.arr(name: String): com.google.gson.JsonArray? = get(name)?.takeIf { it.isJsonArray }?.asJsonArray
internal fun JsonObject.bool(name: String): Boolean? = get(name)?.takeIf { it.isJsonPrimitive }?.let { runCatching { it.asBoolean }.getOrNull() }
internal fun JsonObject.int(name: String): Int? = get(name)?.takeIf { it.isJsonPrimitive }?.let { runCatching { it.asInt }.getOrNull() }
internal fun JsonObject.long(name: String): Long? = get(name)?.takeIf { it.isJsonPrimitive }?.let { runCatching { it.asLong }.getOrNull() }
internal fun JsonObject.dbl(name: String): Double? = get(name)?.takeIf { it.isJsonPrimitive }?.let { runCatching { it.asDouble }.getOrNull() }

val LocalChat = staticCompositionLocalOf<ChatHost> { error("ChatHost missing — wrap chat pages in ChatScreen") }
