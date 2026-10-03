package com.dorr.app.chat

import android.content.Context
import android.util.Log
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.dorr.app.network.AuthSession
import com.dorr.app.network.MessageDto
import com.dorr.app.ui.screens.chat.LocalFile
import com.dorr.app.ui.screens.chat.Outgoing
import com.google.gson.Gson
import com.google.gson.JsonObject
import com.google.gson.reflect.TypeToken
import kotlinx.coroutines.CancellationException
import java.io.File
import java.util.concurrent.TimeUnit

/** A file waiting to go, kept in the app's own storage (not the cache the system may clear). */
data class OutboxFile(val path: String, val mime: String, val name: String)

/** One message I sent that the server doesn't have yet. */
data class OutboxItem(
    val uuid: String,
    val conversationId: String,
    val type: String,
    val body: String?,
    val extra: JsonObject?,
    val files: List<OutboxFile> = emptyList(),
    val thumbnail: String? = null,
    val replyTo: String? = null,
    /** The server said no (validation, blocked…): kept as "failed" until I retry or delete it. */
    val rejected: Boolean = false,
    /** The bubble as drawn while it waits. */
    val message: MessageDto,
)

/**
 * Messages that haven't reached the server yet, saved on the phone — like WhatsApp's clock icon.
 * Something sent offline survives closing the app; [ChatOutboxWorker] sends it as soon as there's
 * a connection, even with the app closed, and the chat shows it in place until then. Always the
 * same uuid, so nothing arrives twice.
 *
 * The open chat sends its own messages (with the upload ring); the worker leaves those alone and
 * takes over whatever is left when the chat closes.
 */
object ChatOutbox {
    private const val WORK = "dorr-chat-outbox"
    private val gson = Gson()
    private val lock = Any()
    private var dir: File? = null
    private var index: File? = null
    private var items = mutableListOf<OutboxItem>()

    /** Conversations open on screen right now (their own page is sending). */
    private val open = mutableSetOf<String>()

    fun attach(context: Context) = synchronized(lock) {
        if (dir != null) return@synchronized
        dir = File(context.filesDir, "chat-outbox").apply { mkdirs() }
        index = File(dir, "outbox.json")
        items = runCatching {
            gson.fromJson<List<OutboxItem>>(index!!.readText(), object : TypeToken<List<OutboxItem>>() {}.type)
        }.getOrNull().orEmpty().toMutableList()
    }

    /** Anything still to send? Then make sure the background sender is waiting for a connection. */
    fun resume(context: Context) {
        if (synchronized(lock) { items.any { !it.rejected } }) schedule(context)
    }

    /**
     * Keep a new message until the server has it. Its files move into the outbox folder (the
     * picker's copies live in the cache), and the message to upload points at them.
     */
    fun add(uuid: String, conversationId: String, out: Outgoing, replyId: String?, message: MessageDto): Outgoing {
        val home = dir?.let { File(it, uuid).apply { mkdirs() } } ?: return out
        fun keep(file: File): File {
            val target = File(home, file.name)
            if (file.absolutePath == target.absolutePath) return file
            return if (file.renameTo(target)) target else runCatching { file.copyTo(target, overwrite = true) }.getOrDefault(file)
        }
        val files = out.files.map { LocalFile(keep(it.file), it.mime, it.name) }
        val thumb = out.thumbnail?.takeIf { it.exists() }?.let { keep(it) }
        val stored = out.copy(files = files, thumbnail = thumb)
        synchronized(lock) {
            items.removeAll { it.uuid == uuid }
            items += OutboxItem(
                uuid = uuid, conversationId = conversationId, type = out.type, body = out.body,
                extra = out.extra.takeIf { it.isNotEmpty() }?.let { gson.toJsonTree(it).asJsonObject },
                files = files.map { OutboxFile(it.file.absolutePath, it.mime, it.name) },
                thumbnail = thumb?.absolutePath, replyTo = replyId, message = message,
            )
            save()
        }
        return stored
    }

    /** The server has it: forget it and its files. */
    fun sent(uuid: String) = synchronized(lock) {
        if (items.removeAll { it.uuid == uuid }) save()
        dir?.let { File(it, uuid).deleteRecursively() }
    }

    fun rejected(uuid: String) = update(uuid) { it.copy(rejected = true) }

    fun retrying(uuid: String) = update(uuid) { it.copy(rejected = false) }

    fun pendingFor(conversationId: String): List<OutboxItem> = synchronized(lock) { items.filter { it.conversationId == conversationId } }

    /** The message to send for an item (its extras' whole numbers back as integers). */
    fun outgoingOf(item: OutboxItem): Outgoing {
        @Suppress("UNCHECKED_CAST")
        val extra = item.extra?.let { ChatSender.normalize(gson.fromJson(it, Map::class.java)) as? Map<String, Any?> }.orEmpty()
        return Outgoing(
            type = item.type, body = item.body,
            files = item.files.map { LocalFile(File(it.path), it.mime, it.name) }.filter { it.file.exists() },
            extra = extra,
            thumbnail = item.thumbnail?.let { File(it) }?.takeIf { it.exists() },
        )
    }

    fun opened(conversationId: String) = synchronized(lock) { open += conversationId }

    /** The chat closed: whatever it didn't get to send goes in the background. */
    fun closed(context: Context, conversationId: String) {
        synchronized(lock) { open -= conversationId }
        resume(context)
    }

    /** Sign-out: another person may use this phone. */
    fun clear(context: Context) {
        WorkManager.getInstance(context).cancelUniqueWork(WORK)
        synchronized(lock) {
            items.clear()
            save()
            dir?.listFiles()?.filter { it.isDirectory }?.forEach { it.deleteRecursively() }
        }
    }

    /** Wait for any connection, then [flush]; retried with a growing delay while it can't get through. */
    fun schedule(context: Context) {
        val request = OneTimeWorkRequestBuilder<ChatOutboxWorker>()
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 15, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork(WORK, ExistingWorkPolicy.REPLACE, request)
    }

    /**
     * Send everything waiting (except chats open on screen — they send their own).
     * @return false when something still couldn't get through (try again later).
     */
    suspend fun flush(context: Context): Boolean {
        val waiting = synchronized(lock) { items.filter { !it.rejected && it.conversationId !in open } }
        for (item in waiting) {
            try {
                val delivered = ChatSender.send(context, item.conversationId, item.uuid, outgoingOf(item), item.replyTo).data
                sent(item.uuid)
                // Opening that chat offline later shows it as sent.
                if (delivered != null) {
                    val cached = ChatStore.messages(item.conversationId).orEmpty()
                    ChatStore.saveMessages(item.conversationId, cached.filterNot { it.id == delivered.id } + delivered)
                }
            } catch (e: CancellationException) {
                throw e
            } catch (e: retrofit2.HttpException) {
                // The server answered "no": that's final, it waits for me in the chat as failed.
                Log.w("DorrChat", "outbox ${item.type} rejected (${e.code()})", e)
                if (e.code() == 401) return true
                rejected(item.uuid)
            } catch (e: Exception) {
                Log.w("DorrChat", "outbox ${item.type} still offline", e)
                return false
            }
        }
        return true
    }

    private fun update(uuid: String, change: (OutboxItem) -> OutboxItem) = synchronized(lock) {
        val i = items.indexOfFirst { it.uuid == uuid }
        if (i >= 0) {
            items[i] = change(items[i])
            save()
        }
    }

    private fun save() {
        val file = index ?: return
        runCatching {
            val tmp = File(file.parentFile, "outbox.json.tmp")
            tmp.writeText(gson.toJson(items))
            tmp.renameTo(file)
        }
    }
}

/** Sends the outbox in the background once there's a connection — even with the app closed. */
class ChatOutboxWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        ChatOutbox.attach(applicationContext)
        if (AuthSession.token.isNullOrBlank()) return Result.success()
        return if (ChatOutbox.flush(applicationContext)) Result.success() else Result.retry()
    }
}
