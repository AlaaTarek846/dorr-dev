package com.dorr.app.chat

import android.content.Context
import android.content.SharedPreferences
import android.net.TrafficStats
import android.os.Handler
import android.os.Looper
import android.os.Process
import okhttp3.Interceptor
import okhttp3.MediaType
import okhttp3.Response
import okhttp3.ResponseBody
import okio.Buffer
import okio.BufferedSource
import okio.ForwardingSource
import okio.buffer
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.atomic.AtomicLong

/**
 * Usage stats (spec 83), kept on this phone like WhatsApp's "Network usage": messages sent and
 * received, the data chats used — messages and media, up and down, on mobile data and on Wi-Fi —
 * and calls (how many, how long, how much data). Counted since a date; reset by hand.
 *
 * Chat requests and media downloads are measured on the wire (an OkHttp network interceptor, so
 * gzip counts as sent). A call's data is the app's own traffic while it lasts (TrafficStats).
 */
object ChatUsage {
    private const val PREFS = "chat_usage"
    const val SINCE = "since"
    const val SENT = "sent"
    const val RECEIVED = "received"
    const val CALLS_OUT = "calls_out"
    const val CALLS_IN = "calls_in"
    const val CALL_SECONDS = "call_seconds"

    /** Data counters: `msg_up_m`, `media_down_w`… (kind _ direction _ m = mobile / w = Wi-Fi). */
    fun dataKey(kind: String, up: Boolean, mobile: Boolean) = "${kind}_${if (up) "up" else "down"}_${if (mobile) "m" else "w"}"
    fun callDataKey(mobile: Boolean) = "call_${if (mobile) "m" else "w"}"

    private var prefs: SharedPreferences? = null
    private val pending = ConcurrentHashMap<String, AtomicLong>()
    private val main = Handler(Looper.getMainLooper())
    @Volatile private var flushQueued = false

    fun attach(context: Context) {
        if (prefs != null) return
        prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).also {
            if (!it.contains(SINCE)) it.edit().putLong(SINCE, System.currentTimeMillis()).apply()
        }
    }

    /** Everything counted so far (saved + not yet written). */
    fun snapshot(): Map<String, Long> {
        val p = prefs ?: return emptyMap()
        val out = p.all.mapNotNull { (k, v) -> (v as? Long)?.let { k to it } }.toMap().toMutableMap()
        pending.forEach { (k, v) -> out[k] = (out[k] ?: 0L) + v.get() }
        return out
    }

    fun reset() {
        pending.clear()
        prefs?.edit()?.clear()?.putLong(SINCE, System.currentTimeMillis())?.putBoolean("cleared", true)?.apply()
    }

    /** Sample numbers so the usage page isn't empty the first time. Skip after I reset. */
    fun seedDemoIfEmpty() {
        val p = prefs ?: return
        if (p.getBoolean("cleared", false)) return
        val snap = snapshot()
        if ((snap[SENT] ?: 0L) + (snap[RECEIVED] ?: 0L) + (snap[CALLS_OUT] ?: 0L) > 0L) return
        p.edit()
            .putLong(SINCE, System.currentTimeMillis() - 5L * 24 * 60 * 60 * 1000)
            .putLong(SENT, 186)
            .putLong(RECEIVED, 540)
            .putLong(dataKey("msg", true, true), 1_150_000)
            .putLong(dataKey("msg", false, true), 4_820_000)
            .putLong(dataKey("msg", true, false), 3_400_000)
            .putLong(dataKey("msg", false, false), 12_600_000)
            .putLong(dataKey("media", true, true), 18_500_000)
            .putLong(dataKey("media", false, true), 96_000_000)
            .putLong(dataKey("media", true, false), 42_000_000)
            .putLong(dataKey("media", false, false), 210_000_000)
            .putLong(CALLS_OUT, 9)
            .putLong(CALLS_IN, 6)
            .putLong(CALL_SECONDS, 4_860)
            .putLong(callDataKey(true), 28_000_000)
            .putLong(callDataKey(false), 74_000_000)
            .apply()
    }

    fun add(key: String, amount: Long) {
        if (amount <= 0 || prefs == null) return
        pending.getOrPut(key) { AtomicLong() }.addAndGet(amount)
        // Written in one go a little later — not once per network read.
        if (!flushQueued) {
            flushQueued = true
            main.postDelayed(::flush, 3_000)
        }
    }

    private fun flush() {
        flushQueued = false
        val p = prefs ?: return
        val edit = p.edit()
        pending.keys.toList().forEach { k ->
            val v = pending.remove(k)?.get() ?: return@forEach
            edit.putLong(k, p.getLong(k, 0L) + v)
        }
        edit.apply()
    }

    fun messageSent() = add(SENT, 1)
    fun messageReceived() = add(RECEIVED, 1)

    // ------------------------------------------------------------------ calls

    private var callStart = 0L
    private var callBytes = 0L
    private var callMobile = false

    private fun appBytes(): Long {
        val uid = Process.myUid()
        val rx = TrafficStats.getUidRxBytes(uid)
        val tx = TrafficStats.getUidTxBytes(uid)
        return if (rx < 0 || tx < 0) -1 else rx + tx
    }

    /** A call got through (both sides talking). */
    fun callStarted(incoming: Boolean) {
        if (callStart != 0L) return
        callStart = System.currentTimeMillis()
        callBytes = appBytes()
        callMobile = ChatDataSaver.metered
        add(if (incoming) CALLS_IN else CALLS_OUT, 1)
    }

    fun callEnded() {
        if (callStart == 0L) return
        add(CALL_SECONDS, (System.currentTimeMillis() - callStart) / 1000)
        val now = appBytes()
        if (callBytes >= 0 && now >= callBytes) add(callDataKey(callMobile), now - callBytes)
        callStart = 0L
    }

    // ------------------------------------------------------------------ chat requests and media

    /** Chat API calls and media files, measured as they cross the network. */
    val interceptor = Interceptor { chain ->
        val request = chain.request()
        val path = request.url.encodedPath
        val chatApi = path.contains("/chat/")
        val upload = request.body?.contentType()?.type == "multipart"
        val media = path.contains("/storage/") || path.contains("/media/")
        if (!chatApi && !media) return@Interceptor chain.proceed(request)

        val mobile = ChatDataSaver.metered
        request.body?.contentLength()?.takeIf { it > 0 }?.let { add(dataKey(if (upload) "media" else "msg", true, mobile), it) }
        val response = chain.proceed(request)
        val type = response.body?.contentType()
        val isMedia = media || type?.type in setOf("image", "video", "audio") || type?.subtype == "octet-stream"
        val key = dataKey(if (isMedia) "media" else "msg", false, mobile)
        val body = response.body ?: return@Interceptor response
        response.newBuilder().body(CountingBody(body, key)).build()
    }

    private class CountingBody(private val inner: ResponseBody, private val key: String) : ResponseBody() {
        override fun contentType(): MediaType? = inner.contentType()
        override fun contentLength(): Long = inner.contentLength()
        private val counted: BufferedSource by lazy {
            object : ForwardingSource(inner.source()) {
                override fun read(sink: Buffer, byteCount: Long): Long {
                    val read = super.read(sink, byteCount)
                    if (read > 0) add(key, read)
                    return read
                }
            }.buffer()
        }
        override fun source(): BufferedSource = counted
        override fun close() = inner.close()
    }
}
