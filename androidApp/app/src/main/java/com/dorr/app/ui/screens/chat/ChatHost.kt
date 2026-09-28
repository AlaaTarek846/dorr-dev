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
    data object NewChat : ChRoute
    /** A new group — or, with `addTo`, more members for an existing one. */
    data class NewGroup(val addTo: String? = null) : ChRoute
    data object MyQr : ChRoute
    data object Privacy : ChRoute
    data object Starred : ChRoute
    data object Calls : ChRoute
    /** Write a text story, or caption a picked photo / video (`media`). */
    data class StoryComposer(val media: String? = null) : ChRoute
    data object StoryPrivacy : ChRoute
}

/** The full-screen story viewer: which people's stories it pages through, and where it starts. */
data class StoryViewerSession(val groups: kotlin.collections.List<com.dorr.app.network.StoryGroupDto>, val start: Int)

/** What someone is doing right now in a conversation. */
data class Activity(val participant: String, val state: String)

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

    val conversations = mutableStateListOf<ConversationDto>()
    var requestsCount by mutableIntStateOf(0)
    var listLoaded by mutableStateOf(false)
    var listFailed by mutableStateOf(false)
    var filter by mutableStateOf("all")

    /** conversation id → people currently typing / recording there. */
    val activity = mutableStateMapOf<String, List<Activity>>()

    /** participant key → online / last seen (from `chat.presence`). */
    val presence = mutableStateMapOf<String, PresenceDto>()

    /** The stories bar (null until loaded; stays null when stories are off). */
    var stories by mutableStateOf<com.dorr.app.network.StoryFeedDto?>(null)
    var storyViewer by mutableStateOf<StoryViewerSession?>(null)
    /** A story being uploaded — the "my status" ring spins meanwhile. */
    var storyUploading by mutableStateOf(false)

    suspend fun refreshStories() {
        runCatching { ApiClient.chat.stories(chatAuth()).data }.getOrNull()?.let { stories = it }
    }

    fun openStories(groups: kotlin.collections.List<com.dorr.app.network.StoryGroupDto>, start: Int) {
        if (groups.isNotEmpty()) storyViewer = StoryViewerSession(groups, start.coerceIn(0, groups.lastIndex))
    }

    var toast by mutableStateOf<String?>(null)
    private var toastJob: Job? = null
    private val typingJobs = mutableMapOf<String, Job>()
    private val gson = Gson()

    fun push(route: ChRoute) {
        forward = true
        stack.add(route)
    }

    fun pop() {
        if (stack.size <= 1) {
            onExit()
            return
        }
        forward = false
        stack.removeAt(stack.lastIndex)
    }

    /** Replace the top page (e.g. "New chat" → the conversation it opened). */
    fun replace(route: ChRoute) {
        forward = true
        stack[stack.lastIndex] = route
    }

    fun showToast(message: String) {
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
                listLoaded = true
            }
        }
        try {
            // "folder:12" is one of my folders; everything else is a server filter.
            val folderId = filter.removePrefix("folder:").takeIf { filter.startsWith("folder:") }?.toIntOrNull()
            val page = ApiClient.chat.conversations(
                chatAuth(),
                filter = filter.takeIf { it != "all" && folderId == null },
                folder = folderId,
                perPage = 50,
            )
            conversations.clear()
            conversations.addAll(page.data.orEmpty())
            requestsCount = page.requestsCount
            listFailed = false
            if (filter == "all") com.dorr.app.chat.ChatStore.saveConversations(conversations.toList())
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            if (!listLoaded) listFailed = true
        }
        listLoaded = true
    }

    /** Put one updated conversation back in the list, keeping pinned chats on top. */
    fun upsert(conversation: ConversationDto) {
        val index = conversations.indexOfFirst { it.id == conversation.id }
        if (index >= 0) conversations.removeAt(index)
        val belongsHere = when {
            filter == "archived" -> conversation.isArchived
            filter == "requests" -> conversation.isRequest
            filter == "locked" -> conversation.isLocked
            filter == "groups" -> conversation.isGroup && !conversation.isArchived && !conversation.isLocked
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
                val message = data.getAsJsonObject("message") ?: return
                val id = conversationId ?: return
                val senderKey = message.getAsJsonObject("sender")?.str("key")
                val mine = senderKey == myKey()
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
                        system = message.getAsJsonObject("system")?.str("event"),
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
                presence[who] = PresenceDto(online = data.get("online")?.asBoolean == true, lastSeenAt = data.str("last_seen_at"))
            }
        }
    }

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
        runCatching { ApiClient.chat.conversation(chatAuth(), id).data }.getOrNull()?.let { fresh ->
            val wasListed = find(id) != null || fresh.lastMessage != null || fresh.isGroup
            if (wasListed) upsert(fresh)
        }
    }

    fun sendTyping(conversationId: String, state: String) {
        scope.launch { runCatching { ApiClient.chat.typing(chatAuth(), conversationId, mapOf("state" to state)) } }
    }

    init {
        scope.launch { ChatRealtime.events.collect { handle(it, (current as? ChRoute.Conversation)?.id) } }
    }
}

internal fun JsonObject.str(name: String): String? = get(name)?.takeIf { !it.isJsonNull }?.let { runCatching { it.asString }.getOrNull() }

val LocalChat = staticCompositionLocalOf<ChatHost> { error("ChatHost missing — wrap chat pages in ChatScreen") }
