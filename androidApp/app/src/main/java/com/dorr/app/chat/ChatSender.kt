package com.dorr.app.chat

import android.content.Context
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ApiEnvelope
import com.dorr.app.network.MessageDto
import com.dorr.app.ui.screens.chat.Outgoing
import com.dorr.app.ui.screens.chat.chatAuth
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.File

/**
 * Sends one message — the same way from the open chat and from [ChatOutboxWorker] in the
 * background: JSON when it has no files, multipart (videos shrunk first) when it has. The
 * app-made [uuid] makes it idempotent: sending it twice returns the first message.
 */
object ChatSender {
    suspend fun send(
        context: Context,
        conversationId: String,
        uuid: String,
        out: Outgoing,
        replyId: String?,
        onProgress: ((Float) -> Unit)? = null,
    ): ApiEnvelope<MessageDto> {
        if (out.files.isEmpty()) {
            val body = mutableMapOf<String, Any?>("type" to out.type, "uuid" to uuid, "body" to out.body, "reply_to" to replyId)
            body.putAll(out.extra)
            return ApiClient.chat.send(chatAuth(), conversationId, body.filterValues { it != null })
        }

        val fields = mutableMapOf<String, RequestBody>()
        fun field(name: String, value: Any?) {
            // Laravel's "boolean" rule takes 1/0, not the text "true"/"false".
            val text = if (value is Boolean) (if (value) "1" else "0") else value?.toString()
            if (text != null) fields[name] = text.toRequestBody("text/plain".toMediaTypeOrNull())
        }
        field("type", out.type)
        field("uuid", uuid)
        field("body", out.body)
        field("reply_to", replyId)
        out.extra.forEach { (k, v) ->
            if (v is List<*>) v.forEachIndexed { i, item -> field("$k[$i]", item) } else field(k, v)
        }
        // Videos are shrunk first (the ring spins meanwhile), then uploaded.
        val files = if (out.type == "video") out.files.map { VideoTools.compress(context, it) } else out.files
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
                    onProgress?.invoke(percent / 100f)
                }
            })
        }
        return ApiClient.chat.sendWithFiles(chatAuth(), conversationId, fields, parts)
    }

    /** A message's stored extras read back from JSON: whole numbers are integers again (5000, not 5000.0). */
    fun normalize(value: Any?): Any? = when (value) {
        is Double -> if (value % 1.0 == 0.0 && kotlin.math.abs(value) < Long.MAX_VALUE) value.toLong() else value
        is Map<*, *> -> value.entries.associate { (k, v) -> k.toString() to normalize(v) }
        is List<*> -> value.map { normalize(it) }
        else -> value
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
                if (read == -1) break
                sink.write(buffer, 0, read)
                onBytes(read.toLong())
            }
        }
    }
}
