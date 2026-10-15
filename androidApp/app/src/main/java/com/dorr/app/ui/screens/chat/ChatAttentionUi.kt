package com.dorr.app.ui.screens.chat

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.ExpandLess
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material3.Icon
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AttentionDto
import com.dorr.app.network.ContextDto
import com.dorr.app.network.apiFailure
import com.google.gson.JsonArray
import com.google.gson.JsonNull
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.DayOfWeek
import java.time.LocalTime
import java.time.ZoneId
import java.time.format.TextStyle

/*
 * My communication rules (spec 146, 151, 155), one page: the context I'm in now; what interrupts
 * me, what waits for the summary and what waits silently, per source; how often the summary
 * comes; the people who always get through; when calls ring; and what each context does. Only
 * notifications change — every message still arrives.
 */

internal val ContextEmoji = mapOf("home" to "🏠", "work" to "💼", "meeting" to "🤝", "travel" to "✈️", "private" to "🔒")

@Composable
internal fun contextLabel(key: String): String = stringResource(
    when (key) {
        "home" -> R.string.at_ctx_home
        "work" -> R.string.at_ctx_work
        "meeting" -> R.string.at_ctx_meeting
        "travel" -> R.string.at_ctx_travel
        else -> R.string.at_ctx_private
    },
)

@Composable
private fun sourceLabel(key: String): Pair<String, String> = when (key) {
    "contacts" -> "👥" to stringResource(R.string.at_src_contacts)
    "strangers" -> "❔" to stringResource(R.string.at_src_strangers)
    "groups" -> "👨‍👩‍👧" to stringResource(R.string.at_src_groups)
    "mentions" -> "@" to stringResource(R.string.at_src_mentions)
    "channels" -> "📢" to stringResource(R.string.at_src_channels)
    "money" -> "💸" to stringResource(R.string.at_src_money)
    else -> "🚨" to stringResource(R.string.at_src_urgent)
}

@Composable
fun CommRulesPage() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var data by remember { mutableStateOf<AttentionDto?>(null) }
    var pickPeople by remember { mutableStateOf(false) }
    var picking by remember { mutableStateOf<Boolean?>(null) }
    var expanded by remember { mutableStateOf<String?>(null) }
    var askDuration by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) { data = runCatching { ApiClient.life.attention(chatAuth()).data }.getOrNull() }
    fun save(body: JsonObject) {
        scope.launch {
            runCatching { ApiClient.life.saveAttention(chatAuth(), body).data }.onSuccess { r -> r?.let { data = it } }.onFailure { host.showToast(it.apiFailure().message ?: "") }
        }
    }
    fun setContext(key: String?, minutes: Int?) {
        scope.launch {
            runCatching {
                ApiClient.life.setContext(chatAuth(), JsonObject().apply {
                    if (key == null) add("context", JsonNull.INSTANCE) else addProperty("context", key)
                    minutes?.let { addProperty("minutes", it) }
                }).data
            }.onSuccess { r -> r?.let { data = it } }.onFailure { host.showToast(it.apiFailure().message ?: "") }
        }
    }

    ChPage(stringResource(R.string.at_title), onBack = { host.pop() }) {
        val d = data
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
            if (d == null) {
                repeat(4) { Box(Modifier.fillMaxWidth().height(90.dp).padding(bottom = 10.dp).clip(RoundedCornerShape(22.dp)).background(Ch.SurfaceMuted)) }
                return@Column
            }
            // ------------------------------------------------ the context now (151)
            val active = d.context?.key
            Column(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(26.dp))
                    .background(Brush.linearGradient(listOf(Ch.Red, Ch.RedDeep))).padding(16.dp),
            ) {
                Text(stringResource(R.string.at_now), color = Color.White.copy(alpha = 0.8f), fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                Text(
                    active?.let { "${ContextEmoji[it]}  ${contextLabel(it)}" } ?: stringResource(R.string.at_ctx_none),
                    color = Color.White, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 2.dp),
                )
                d.context?.until?.let { lifeWhen(it) }?.let { Text(stringResource(R.string.at_until, it), color = Color.White.copy(alpha = 0.8f), fontSize = 12.sp) }
                Row(Modifier.padding(top = 12.dp).horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf<String?>(null, "home", "work", "meeting", "travel", "private").forEach { k ->
                        val on = k == active
                        Text(
                            if (k == null) stringResource(R.string.at_ctx_none) else "${ContextEmoji[k]} ${contextLabel(k)}",
                            color = if (on) Ch.Red else Color.White, fontSize = 13.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.clip(CircleShape).background(if (on) Color.White else Color.White.copy(alpha = 0.16f))
                                .clickable { if (k == null) setContext(null, null) else askDuration = k }.padding(horizontal = 13.dp, vertical = 8.dp),
                        )
                    }
                }
            }
            Text(stringResource(R.string.at_now_sub), color = Ch.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 6.dp, start = 4.dp))

            // ------------------------------------------------ attention per source (146)
            Section(stringResource(R.string.at_attention), stringResource(R.string.at_attention_sub)) {
                d.sources.ifEmpty { listOf("contacts", "strangers", "groups", "mentions", "channels", "money", "urgent") }.forEach { src ->
                    val (emoji, label) = sourceLabel(src)
                    ModeRow(emoji, label, d.attention[src] ?: "now") { mode -> save(JsonObject().apply { add("attention", JsonObject().apply { d.attention.forEach { (k, v) -> addProperty(k, v) }; addProperty(src, mode) }) }) }
                }
                Text(stringResource(R.string.at_digest_every), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 12.dp, bottom = 6.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf(15, 30, 60, 120, 240).forEach { m ->
                        Pill(if (m < 60) stringResource(R.string.ch_minutes_n, m) else stringResource(R.string.ch_hours_n, m / 60), d.digestMinutes == m) { save(JsonObject().apply { addProperty("digest_minutes", m) }) }
                    }
                }
            }

            // ------------------------------------------------ always get through (155)
            Section(stringResource(R.string.at_through), stringResource(R.string.at_through_sub)) {
                ThroughRow(d) { keys -> save(JsonObject().apply { add("always_through", JsonArray().apply { keys.forEach { add(it) } }) }) }
                Text("＋ " + stringResource(R.string.at_through_add), color = Ch.Red, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 8.dp).clip(CircleShape).clickable { pickPeople = true }.padding(horizontal = 6.dp, vertical = 6.dp))
            }

            // ------------------------------------------------ calls (155)
            Section(stringResource(R.string.at_calls), stringResource(R.string.at_calls_sub)) {
                val hours = d.callHours
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(stringResource(R.string.at_call_hours), color = Ch.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                    Switch(hours?.from != null, { on ->
                        save(JsonObject().apply {
                            if (on) add("call_hours", JsonObject().apply { addProperty("from", "09:00"); addProperty("to", "22:00"); addProperty("timezone", ZoneId.systemDefault().id) })
                            else add("call_hours", JsonNull.INSTANCE)
                        })
                    }, colors = SwitchDefaults.colors(checkedTrackColor = Ch.Red))
                }
                AnimatedVisibility(hours?.from != null) {
                    Column {
                        Row(Modifier.padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            TimeTile(stringResource(R.string.ch_from), hours?.from ?: "09:00", Modifier.weight(1f)) { picking = true }
                            TimeTile(stringResource(R.string.ch_to), hours?.to ?: "22:00", Modifier.weight(1f)) { picking = false }
                        }
                        Row(Modifier.padding(top = 8.dp).horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            val days = hours?.days?.ifEmpty { (0..6).toList() } ?: (0..6).toList()
                            (0..6).forEach { day ->
                                val on = day in days
                                val bg by animateColorAsState(if (on) Ch.Red else Ch.SurfaceMuted, label = "callday")
                                Box(Modifier.size(38.dp).clip(CircleShape).background(bg).clickable {
                                    val next = if (on) days - day else days + day
                                    if (next.isNotEmpty()) save(callHoursBody(hours?.from ?: "09:00", hours?.to ?: "22:00", next))
                                }, contentAlignment = Alignment.Center) {
                                    Text(DayOfWeek.of(if (day == 0) 7 else day).getDisplayName(TextStyle.NARROW, com.dorr.app.network.AppLocale.java()), color = if (on) Color.White else Ch.Ink, fontWeight = FontWeight.Bold)
                                }
                            }
                        }
                        Text(stringResource(R.string.at_call_hours_note), color = Ch.Soft, fontSize = 11.5.sp, modifier = Modifier.padding(top = 6.dp))
                    }
                }
                LinkRow(stringResource(R.string.at_who_can_call)) { host.push(ChRoute.Privacy) }
                LinkRow(stringResource(R.string.at_quiet_hours) + if (d.quiet?.on == true) " · " + stringResource(R.string.at_on_now) else "") { host.push(ChRoute.PrivacyCenter) }
            }

            // ------------------------------------------------ on my phone only (156)
            LocalIntelligenceSection()

            // ------------------------------------------------ what each context does (151)
            Section(stringResource(R.string.at_contexts), stringResource(R.string.at_contexts_sub)) {
                d.contexts.forEach { c ->
                    ContextBlock(c, d.sources, expanded == c.key, onToggle = { expanded = if (expanded == c.key) null else c.key }) { change ->
                        save(JsonObject().apply { add("contexts", JsonObject().apply { add(c.key, change) }) })
                    }
                }
            }
            Spacer(Modifier.height(24.dp))
        }
    }

    askDuration?.let { key ->
        ChoiceSheet(
            "${ContextEmoji[key]}  ${contextLabel(key)}",
            listOf(
                stringResource(R.string.ch_hours_n, 1) to { setContext(key, 60) },
                stringResource(R.string.ch_hours_n, 3) to { setContext(key, 180) },
                stringResource(R.string.at_today) to { setContext(key, minutesToEndOfDay()) },
                stringResource(R.string.at_until_change) to { setContext(key, null) },
            ),
            subtitle = stringResource(R.string.at_for_how_long),
            onDismiss = { askDuration = null },
        )
    }
    if (pickPeople) {
        PeoplePickerSheet(emptySet(), onDismiss = { pickPeople = false }) { ids ->
            pickPeople = false
            val keys = (data?.alwaysThrough.orEmpty().map { it.key } + ids.map { "user:$it" }).distinct()
            save(JsonObject().apply { add("always_through", JsonArray().apply { keys.forEach { add(it) } }) })
        }
    }
    picking?.let { isFrom ->
        val h = data?.callHours
        TimePickDialog(LocalTime.parse(if (isFrom) h?.from ?: "09:00" else h?.to ?: "22:00"), onDismiss = { picking = null }) { t ->
            val text = "%02d:%02d".format(t.hour, t.minute)
            save(callHoursBody(if (isFrom) text else h?.from ?: "09:00", if (isFrom) h?.to ?: "22:00" else text, h?.days ?: (0..6).toList()))
            picking = null
        }
    }
}

private fun minutesToEndOfDay(): Int {
    val now = java.time.LocalDateTime.now()
    return java.time.Duration.between(now, now.toLocalDate().plusDays(1).atStartOfDay()).toMinutes().toInt().coerceIn(15, 1440)
}

private fun callHoursBody(from: String, to: String, days: List<Int>) = JsonObject().apply {
    add("call_hours", JsonObject().apply {
        addProperty("from", from)
        addProperty("to", to)
        add("days", JsonArray().apply { days.sorted().forEach { add(it) } })
        addProperty("timezone", ZoneId.systemDefault().id)
    })
}

@Composable
private fun Section(title: String, sub: String, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().padding(top = 14.dp).clip(RoundedCornerShape(24.dp)).background(Ch.Surface).padding(16.dp)) {
        Text(title, color = Ch.Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
        Text(sub, color = Ch.Mut, fontSize = 12.5.sp, modifier = Modifier.padding(top = 2.dp, bottom = 10.dp))
        content()
    }
}

/** One source: now · in the summary · later — three segments. */
@Composable
private fun ModeRow(emoji: String, label: String, mode: String, onPick: (String) -> Unit) {
    Column(Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(emoji, fontSize = 15.sp, modifier = Modifier.width(26.dp))
            Text(label, color = Ch.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold)
        }
        Row(Modifier.fillMaxWidth().padding(top = 6.dp).clip(RoundedCornerShape(14.dp)).background(Ch.SurfaceMuted).padding(3.dp)) {
            listOf("now" to R.string.at_mode_now, "digest" to R.string.at_mode_digest, "later" to R.string.at_mode_later).forEach { (m, l) ->
                val on = m == mode
                val bg by animateColorAsState(if (on) Ch.Surface else Color.Transparent, label = "mode")
                Text(
                    stringResource(l), color = if (on) Ch.Red else Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, maxLines = 1,
                    modifier = Modifier.weight(1f).clip(RoundedCornerShape(11.dp)).background(bg).clickable { if (!on) onPick(m) }.padding(vertical = 8.dp),
                )
            }
        }
    }
}

@Composable
private fun Pill(text: String, on: Boolean, onClick: () -> Unit) {
    Text(
        text, color = if (on) Color.White else Ch.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1,
        modifier = Modifier.clip(CircleShape).background(if (on) Ch.Red else Ch.SurfaceMuted).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 7.dp),
    )
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ThroughRow(d: AttentionDto, onChange: (List<String>) -> Unit) {
    if (d.alwaysThrough.isEmpty()) {
        Text(stringResource(R.string.at_through_none), color = Ch.Soft, fontSize = 12.5.sp)
        return
    }
    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        d.alwaysThrough.forEach { p ->
            Row(Modifier.clip(CircleShape).background(Ch.SurfaceMuted).padding(start = 3.dp, end = 6.dp, top = 3.dp, bottom = 3.dp), verticalAlignment = Alignment.CenterVertically) {
                ChAvatar(p.avatar, p.name, p.key, size = 26.dp)
                Spacer(Modifier.width(6.dp))
                Text(p.name.orEmpty(), color = Ch.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Icon(Icons.Rounded.Close, null, tint = Ch.Soft, modifier = Modifier.padding(start = 4.dp).size(16.dp).clickable { onChange(d.alwaysThrough.map { it.key } - p.key) })
            }
        }
    }
}

@Composable
private fun TimeTile(label: String, time: String, modifier: Modifier, onClick: () -> Unit) {
    Column(modifier.clip(RoundedCornerShape(16.dp)).background(Ch.SurfaceMuted).clickable(onClick = onClick).padding(12.dp)) {
        Text(label, color = Ch.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        Text(time, color = Ch.Ink, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold)
    }
}

@Composable
private fun LinkRow(text: String, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().padding(top = 8.dp).clip(RoundedCornerShape(14.dp)).clickable(onClick = onClick).padding(vertical = 8.dp, horizontal = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(text, color = Ch.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
        Text("›", color = Ch.Soft, fontSize = 20.sp)
    }
}

/** One context: what it shows, who may call, and its own attention — folded until opened. */
@Composable
private fun ContextBlock(c: ContextDto, sources: List<String>, open: Boolean, onToggle: () -> Unit, onChange: (JsonObject) -> Unit) {
    Column(Modifier.fillMaxWidth().padding(bottom = 8.dp).clip(RoundedCornerShape(18.dp)).border(1.dp, Ch.Line, RoundedCornerShape(18.dp))) {
        Row(Modifier.fillMaxWidth().clickable(onClick = onToggle).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(ContextEmoji[c.key] ?: "•", fontSize = 18.sp, modifier = Modifier.width(30.dp))
            Column(Modifier.weight(1f)) {
                Text(contextLabel(c.key), color = Ch.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold)
                Text(contextSummary(c), color = Ch.Mut, fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            Icon(if (open) Icons.Rounded.ExpandLess else Icons.Rounded.ExpandMore, null, tint = Ch.Soft)
        }
        AnimatedVisibility(open) {
            Column(Modifier.padding(start = 12.dp, end = 12.dp, bottom = 12.dp)) {
                Text(stringResource(R.string.at_shows), color = Ch.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 6.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf<Pair<String?, Int>>(null to R.string.at_show_usual, "name" to R.string.at_show_name, "none" to R.string.at_show_nothing).forEach { (v, l) ->
                        Pill(stringResource(l), c.disclosure == v || (v == null && c.disclosure == "all")) { onChange(JsonObject().apply { if (v == null) add("disclosure", JsonNull.INSTANCE) else addProperty("disclosure", v) }) }
                    }
                }
                Text(stringResource(R.string.at_calls), color = Ch.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 10.dp, bottom = 6.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    listOf("everyone" to R.string.at_calls_everyone, "through" to R.string.at_calls_through, "nobody" to R.string.at_calls_nobody).forEach { (v, l) ->
                        Pill(stringResource(l), c.calls == v) { onChange(JsonObject().apply { addProperty("calls", v) }) }
                    }
                }
                sources.ifEmpty { listOf("contacts", "strangers", "groups", "mentions", "channels", "money", "urgent") }.forEach { src ->
                    val (emoji, label) = sourceLabel(src)
                    ModeRow(emoji, label, c.attention[src] ?: "now") { mode -> onChange(JsonObject().apply { add("attention", JsonObject().apply { c.attention.forEach { (k, v) -> addProperty(k, v) }; addProperty(src, mode) }) }) }
                }
            }
        }
    }
}

@Composable
private fun contextSummary(c: ContextDto): String {
    val parts = mutableListOf<String>()
    when (c.disclosure) {
        "name" -> parts += stringResource(R.string.at_show_name)
        "none" -> parts += stringResource(R.string.at_show_nothing)
    }
    when (c.calls) {
        "through" -> parts += stringResource(R.string.at_calls_through)
        "nobody" -> parts += stringResource(R.string.at_calls_nobody)
    }
    val waiting = c.attention.count { it.value != "now" }
    if (waiting > 0) parts += stringResource(R.string.at_n_wait, waiting)
    return parts.joinToString(" · ").ifEmpty { stringResource(R.string.at_all_through) }
}

/** Local private intelligence (spec 156): on this phone only — nothing of it is sent anywhere. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun LocalIntelligenceSection() {
    val context = androidx.compose.ui.platform.LocalContext.current
    var on by remember { mutableStateOf(com.dorr.app.chat.LocalIntelligence.enabled(context)) }
    var hide by remember { mutableStateOf(com.dorr.app.chat.LocalIntelligence.hideSensitive(context)) }
    var words by remember { mutableStateOf(com.dorr.app.chat.LocalIntelligence.keywords(context)) }
    var adding by remember { mutableStateOf("") }
    var trying by remember { mutableStateOf("") }
    Section(stringResource(R.string.lp_title), stringResource(R.string.lp_sub)) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.lp_on), color = Ch.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            Switch(on, { on = it; com.dorr.app.chat.LocalIntelligence.setEnabled(context, it) }, colors = SwitchDefaults.colors(checkedTrackColor = Ch.Red))
        }
        AnimatedVisibility(on) {
            Column {
                Row(Modifier.fillMaxWidth().padding(top = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text(stringResource(R.string.lp_hide), color = Ch.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold)
                        Text(stringResource(R.string.lp_hide_sub), color = Ch.Mut, fontSize = 12.sp)
                    }
                    Switch(hide, { hide = it; com.dorr.app.chat.LocalIntelligence.setHideSensitive(context, it) }, colors = SwitchDefaults.colors(checkedTrackColor = Ch.Red))
                }
                Text(stringResource(R.string.lp_words), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 12.dp, bottom = 6.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    words.forEach { w ->
                        Row(Modifier.clip(CircleShape).background(Ch.SurfaceMuted).padding(start = 12.dp, end = 6.dp, top = 6.dp, bottom = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                            Text("⭐ $w", color = Ch.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                            Icon(Icons.Rounded.Close, null, tint = Ch.Soft, modifier = Modifier.padding(start = 4.dp).size(16.dp).clickable {
                                words = words - w
                                com.dorr.app.chat.LocalIntelligence.setKeywords(context, words)
                            })
                        }
                    }
                }
                Row(Modifier.padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.weight(1f)) { ChField(adding, { adding = it.take(40) }, stringResource(R.string.lp_word_hint)) }
                    Spacer(Modifier.width(8.dp))
                    Text("＋", color = Ch.Red, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.clip(CircleShape).clickable(enabled = adding.trim().length >= 2) {
                        words = (words + adding.trim()).distinct()
                        com.dorr.app.chat.LocalIntelligence.setKeywords(context, words)
                        adding = ""
                    }.padding(10.dp))
                }
                // Try it: what this phone would make of a message.
                Text(stringResource(R.string.lp_try), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 12.dp, bottom = 6.dp))
                ChField(trying, { trying = it.take(200) }, stringResource(R.string.lp_try_hint))
                if (trying.isNotBlank()) {
                    val kind = com.dorr.app.chat.LocalIntelligence.kindOf(trying)
                    val word = words.firstOrNull { trying.contains(it, ignoreCase = true) }
                    Text(
                        listOfNotNull(word?.let { "⭐ " + stringResource(R.string.lp_kind_keyword, it) }, kind?.let { "${it.emoji} " + stringResource(it.label) }).joinToString(" · ")
                            .ifEmpty { stringResource(R.string.lp_kind_none) },
                        color = Ch.Red, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 6.dp),
                    )
                }
            }
        }
    }
}
