package com.dorr.app.ui.screens.sports

import android.content.Context
import android.media.AudioAttributes
import android.media.AudioManager
import android.media.SoundPool
import android.os.Build
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.graphics.Color
import com.dorr.app.R
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.SpMatchDto
import com.dorr.app.network.SpPrefsDto
import com.google.gson.Gson
import com.google.gson.JsonObject
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.OffsetDateTime
import java.time.ZoneId

/*
 * DORR Sports on the phone (spec 183–200, docs/sports-plan.md §6): live scores arrive on Pusher —
 * the app never asks the provider — the minute keeps ticking between updates, and a goal for my
 * team brings the goal moment (sound, vibration, confetti) unless I turned it off or hid spoilers.
 */

internal fun spAuth() = "Bearer ${AuthSession.token.orEmpty()}"

internal fun spZone(): String = ZoneId.systemDefault().id

/** Opening Sports from anywhere: the home card, a notification, the calendar. */
@Stable
object SportsLink {
    var open by mutableStateOf(false)
    var matchId by mutableStateOf<String?>(null)

    fun show(match: String? = null) {
        matchId = match
        open = true
    }

    fun close() {
        open = false
        matchId = null
    }
}

/** One alert for a match I follow, as it arrived on my private channel. */
data class SportsAlert(
    val matchId: String,
    val type: String,
    val hidden: Boolean,
    val homeScore: Int?,
    val awayScore: Int?,
    val side: String?,
    val scorer: String?,
    val minute: Int?,
    /** off / calm / normal / festive — null: no celebration (not my team, or spoilers hidden). */
    val celebrate: String?,
    val at: Long = System.currentTimeMillis(),
)

@Stable
object SportsLive {
    private val gson = Gson()
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private var started = false

    /** The newest version of each match we've heard about live (compact fields). */
    val latest = mutableStateMapOf<String, SpMatchDto>()

    /** When each match last changed live — rows flash, scores flip. */
    val changedAt = mutableStateMapOf<String, Long>()

    var prefs by mutableStateOf(SpPrefsDto())

    /** The goal moment / win on screen now. */
    var celebration by mutableStateOf<SportsAlert?>(null)

    /** A quiet banner (the other team scored, a red card, full time…). */
    var banner by mutableStateOf<SportsAlert?>(null)

    /** Live minute keeps ticking: the clock the rows read. */
    var now by mutableLongStateOf(System.currentTimeMillis())
        private set

    fun start(context: Context) {
        if (started) return
        started = true
        SportsSounds.init(context.applicationContext)
        com.dorr.app.widget.SportsWidgets.refreshNow(context.applicationContext)
        ChatRealtime.watchPublic("sports.live", listOf("sports.match.updated"))
        scope.launch {
            runCatching { ApiClient.sports.preferences(spAuth()).data }.getOrNull()?.let { prefs = it }
        }
        scope.launch {
            ChatRealtime.events.collect { e ->
                when (e.name) {
                    "sports.match.updated" -> onUpdate(e.data)
                    "sports.alert" -> onAlert(context.applicationContext, e.data)
                    // I won a contest: the win celebration, with the prize.
                    "sports.prize" -> {
                        celebration = SportsAlert(
                            matchId = "", type = "prize", hidden = false, homeScore = null, awayScore = null, side = null,
                            scorer = e.data.get("title")?.takeIf { it.isJsonPrimitive }?.asString, minute = null, celebrate = prefs.celebration.takeIf { it != "off" } ?: "calm",
                        )
                        SportsSounds.play(SportsSounds.Kind.Win, prefs)
                    }
                }
            }
        }
        scope.launch {
            while (true) {
                delay(15_000)
                now = System.currentTimeMillis()
            }
        }
    }

    /** The freshest copy of a match: what the server sent live, if it's newer than the list's. */
    fun fresh(m: SpMatchDto): SpMatchDto {
        val live = latest[m.id] ?: return m
        return if (live.version >= m.version) m.copy(
            status = live.status, statusCode = live.statusCode, minute = live.minute, minuteExtra = live.minuteExtra,
            homeScore = live.homeScore, awayScore = live.awayScore, periods = live.periods, winner = live.winner, version = live.version, syncedAt = live.syncedAt,
            race = live.race ?: m.race, fight = live.fight ?: m.fight, leader = live.leader ?: m.leader,
        ) else m
    }

    private fun onUpdate(data: JsonObject) {
        val m = runCatching { gson.fromJson(data, SpMatchDto::class.java) }.getOrNull() ?: return
        val old = latest[m.id]
        if (old != null && old.version >= m.version) return
        latest[m.id] = m
        changedAt[m.id] = System.currentTimeMillis()
    }

    private fun onAlert(context: Context, d: JsonObject) {
        fun s(k: String) = d.get(k)?.takeIf { it.isJsonPrimitive }?.asString
        fun i(k: String) = d.get(k)?.takeIf { it.isJsonPrimitive }?.asInt
        val alert = SportsAlert(
            matchId = s("match_id") ?: return, type = s("type") ?: "", hidden = d.get("hidden")?.asBoolean == true,
            homeScore = i("home_score"), awayScore = i("away_score"), side = s("side"), scorer = s("scorer"), minute = i("minute"), celebrate = s("celebrate"),
        )
        if (alert.celebrate != null && alert.celebrate != "off") {
            celebration = alert
            val win = alert.type == "finished"
            SportsSounds.play(if (win) SportsSounds.Kind.Win else SportsSounds.Kind.Goal, prefs)
            if (prefs.vibrate) vibrate(context, if (win) longArrayOf(0, 120, 80, 120, 80, 260) else longArrayOf(0, 90, 60, 90, 60, 400))
        } else if (!alert.hidden || alert.type == "kickoff") {
            banner = alert
            when (alert.type) {
                "kickoff", "finished", "half_time" -> SportsSounds.play(SportsSounds.Kind.Whistle, prefs)
                "goal", "goal_detail" -> SportsSounds.play(SportsSounds.Kind.Tick, prefs)
            }
        }
    }

    private fun vibrate(context: Context, pattern: LongArray) {
        runCatching {
            val v = if (Build.VERSION.SDK_INT >= 31) (context.getSystemService(Context.VIBRATOR_MANAGER_SERVICE) as VibratorManager).defaultVibrator
            else @Suppress("DEPRECATION") (context.getSystemService(Context.VIBRATOR_SERVICE) as Vibrator)
            v.vibrate(VibrationEffect.createWaveform(pattern, -1))
        }
    }
}

/** Short sounds (made for Dorr, in res/raw), played at once with SoundPool — never in silent mode. */
object SportsSounds {
    enum class Kind { Whistle, Goal, Tick, Win }

    private var pool: SoundPool? = null
    private val ids = mutableMapOf<Kind, Int>()
    private val cheer = intArrayOf(0)
    private var audio: AudioManager? = null

    fun init(context: Context) {
        if (pool != null) return
        audio = context.getSystemService(Context.AUDIO_SERVICE) as? AudioManager
        val p = SoundPool.Builder().setMaxStreams(3)
            .setAudioAttributes(AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_GAME).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build())
            .build()
        ids[Kind.Whistle] = p.load(context, R.raw.sp_whistle, 1)
        ids[Kind.Goal] = p.load(context, R.raw.sp_horn, 1)
        ids[Kind.Tick] = p.load(context, R.raw.sp_tick, 1)
        ids[Kind.Win] = p.load(context, R.raw.sp_win, 1)
        cheer[0] = p.load(context, R.raw.sp_cheer, 1)
        pool = p
    }

    fun play(kind: Kind, prefs: SpPrefsDto, force: Boolean = false) {
        if (!force && !prefs.sounds) return
        if (audio?.ringerMode != AudioManager.RINGER_MODE_NORMAL && !force) return
        val p = pool ?: return
        ids[kind]?.let { p.play(it, 0.9f, 0.9f, 1, 0, 1f) }
        // The crowd joins a goal or a win.
        if (kind == Kind.Goal || kind == Kind.Win) p.play(cheer[0], 0.55f, 0.55f, 0, 0, 1f)
    }
}

// =============================================================================== the minute

private fun instant(iso: String?): Instant? = runCatching { OffsetDateTime.parse(iso).toInstant() }.getOrNull()

/**
 * What the clock shows: football's minute keeps ticking from the last update ("67'", "45+2'",
 * "HT"), other sports show their period (Q3 · 7'), a coming match its local time.
 */
internal fun clockText(m: SpMatchDto, now: Long, ht: String, ft: String): String = when (m.status) {
    "break" -> if (m.statusCode == "HT") ht else m.statusCode ?: ht
    "finished" -> when (m.statusCode) { "AET", "PEN" -> m.statusCode; else -> ft }
    "live" -> if (m.sport == "formula1") {
        listOfNotNull(m.minute?.let { "L$it" + (m.race?.laps?.let { t -> "/$t" } ?: "") }).joinToString().ifEmpty { "LIVE" }
    } else if (m.sport == "mma") {
        m.statusCode ?: "LIVE"
    } else if (m.sport == "football" || m.sport == null) {
        val base = m.minute ?: 0
        val synced = instant(m.syncedAt)?.toEpochMilli() ?: now
        val ran = ((now - synced) / 60_000L).toInt().coerceIn(0, 10)
        val cap = when (m.statusCode) { "1H" -> 45; "2H" -> 90; "ET" -> 120; else -> 200 }
        val minute = base + ran
        when {
            m.statusCode == "P" -> "PEN"
            (m.minuteExtra ?: 0) > 0 -> "$cap+${m.minuteExtra}'"
            minute > cap -> "$cap+${minute - cap}'"
            else -> "$minute'"
        }
    } else listOfNotNull(m.statusCode, m.minute?.let { "$it'" }).joinToString(" · ")
    else -> m.localTime.orEmpty()
}

/** 0..1 of the match played (football), for the ring around the minute. */
internal fun progressOf(m: SpMatchDto, now: Long): Float {
    if (m.status == "finished") return 1f
    if (m.status !in setOf("live", "break")) return 0f
    val synced = instant(m.syncedAt)?.toEpochMilli() ?: now
    val minute = (m.minute ?: 0) + ((now - synced) / 60_000L).toInt().coerceIn(0, 10)
    return (minute / 90f).coerceIn(0f, 1f)
}

/** A team's colour: its kit (from line-ups) or a steady colour from its id. */
internal fun teamColor(hex: String?, id: Int): Color {
    hex?.let { runCatching { return Color(android.graphics.Color.parseColor(it)) } }
    val palette = listOf(0xFF1D4ED8, 0xFFDC2626, 0xFF059669, 0xFF7C3AED, 0xFFEA580C, 0xFF0891B2, 0xFFDB2777, 0xFF4D7C0F, 0xFF0F172A, 0xFFCA8A04)
    return Color(palette[Math.floorMod(id, palette.size)])
}

internal val SpGreen = Color(0xFF0E9F6E)
internal val SpLive = Color(0xFFE11D48)
internal val SpPitch = Color(0xFF15803D)

/** Re-reads the clock every few seconds so live minutes move on screen. */
@Composable
internal fun rememberTicker(): Long {
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(Unit) {
        while (true) {
            delay(10_000)
            now = System.currentTimeMillis()
        }
    }
    return maxOf(now, SportsLive.now)
}
