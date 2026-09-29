package com.dorr.app.chat

import android.content.Context
import android.content.SharedPreferences
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.MessageDto
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File

/**
 * The chat's on-phone memory:
 *  - preferences (dark mode: system / light / dark, the OneSignal app id for the next cold start);
 *  - an offline cache — the last chat list and the latest messages of each conversation — so the
 *    chat opens instantly and still reads without a connection. The server stays the truth: the
 *    cache is always replaced by what the server answers.
 *
 * Wiped on logout (another person may sign in on this phone).
 */
object ChatStore {
    private const val PREFS = "dorr_chat"
    private const val MESSAGES_KEPT = 80

    private var prefs: SharedPreferences? = null
    private var dir: File? = null
    private val gson = Gson()
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val pendingWrites = mutableMapOf<String, Job>()

    /** The chat is on screen: the app-wide "no internet" page steps aside for the chat's own banner. */
    var screenOpen by mutableStateOf(false)

    /** "system" | "light" | "dark" */
    var themeMode by mutableStateOf("system")
        private set

    fun attach(context: Context) {
        prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        dir = File(context.filesDir, "chat-cache").apply { mkdirs() }
        themeMode = prefs?.getString("theme", "system") ?: "system"
    }

    fun setTheme(mode: String) {
        themeMode = mode
        prefs?.edit()?.putString("theme", mode)?.apply()
    }

    var oneSignalAppId: String?
        get() = prefs?.getString("onesignal_app_id", null)
        set(value) {
            prefs?.edit()?.putString("onesignal_app_id", value)?.apply()
        }

    // ------------------------------------------------------------------ cache

    suspend fun conversations(): List<ConversationDto>? = read("conversations.json", object : TypeToken<List<ConversationDto>>() {})

    fun saveConversations(list: List<ConversationDto>) = write("conversations.json", list.take(100))

    suspend fun messages(conversationId: String): List<MessageDto>? = read(fileFor(conversationId), object : TypeToken<List<MessageDto>>() {})

    fun saveMessages(conversationId: String, list: List<MessageDto>) = write(fileFor(conversationId), list.takeLast(MESSAGES_KEPT))

    fun clear() {
        pendingWrites.values.forEach { it.cancel() }
        pendingWrites.clear()
        dir?.listFiles()?.forEach { it.delete() }
        prefs?.edit()?.remove("theme")?.apply()
        themeMode = "system"
    }

    private fun fileFor(conversationId: String) = "m-" + conversationId.filter { it.isLetterOrDigit() || it == '-' } + ".json"

    private suspend fun <T> read(name: String, type: TypeToken<T>): T? = withContext(Dispatchers.IO) {
        val file = File(dir ?: return@withContext null, name)
        if (!file.exists()) return@withContext null
        runCatching { gson.fromJson<T>(file.readText(), type.type) }.getOrNull()
    }

    /** Debounced: a burst of updates (typing, receipts) writes the file once. */
    private fun write(name: String, value: Any) {
        val target = dir ?: return
        pendingWrites[name]?.cancel()
        pendingWrites[name] = scope.launch {
            delay(400)
            runCatching {
                val tmp = File(target, "$name.tmp")
                tmp.writeText(gson.toJson(value))
                tmp.renameTo(File(target, name))
            }
        }
    }
}
