package com.dorr.app.ui.screens.chat

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.material.icons.rounded.AccessTime
import androidx.compose.material.icons.rounded.AlternateEmail
import androidx.compose.material.icons.rounded.Bedtime
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Campaign
import androidx.compose.material.icons.rounded.CheckBox
import androidx.compose.material.icons.rounded.CheckBoxOutlineBlank
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.DateRange
import androidx.compose.material.icons.rounded.Flight
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.People
import androidx.compose.material.icons.rounded.PersonOff
import androidx.compose.material.icons.rounded.PhoneAndroid
import androidx.compose.material.icons.rounded.PriorityHigh
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AttentionDto
import com.dorr.app.network.CallHoursDto
import com.dorr.app.network.ContextDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface
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

private fun contextIcon(key: String): ImageVector = when (key) {
    "home" -> Icons.Rounded.Home
    "work" -> Icons.Rounded.Work
    "meeting" -> Icons.Rounded.Groups
    "travel" -> Icons.Rounded.Flight
    "private" -> Icons.Rounded.Lock
    else -> Icons.Rounded.Tune
}

private fun sourceIcon(key: String): ImageVector = when (key) {
    "contacts" -> Icons.Rounded.People
    "strangers" -> Icons.Rounded.PersonOff
    "groups" -> Icons.Rounded.Groups
    "mentions" -> Icons.Rounded.AlternateEmail
    "channels" -> Icons.Rounded.Campaign
    "money" -> Icons.Rounded.Payments
    else -> Icons.Rounded.PriorityHigh
}

private fun modeLabel(mode: String): Int = when (mode) {
    "digest" -> R.string.at_mode_digest
    "later" -> R.string.at_mode_later
    else -> R.string.at_mode_now
}

@Composable
private fun digestMinutesLabel(minutes: Int?): String {
    val m = minutes ?: 60
    return if (m < 60) stringResource(R.string.ch_minutes_n, m) else stringResource(R.string.ch_hours_n, m / 60)
}

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
    var editCallHours by remember { mutableStateOf(false) }
    var pickNow by remember { mutableStateOf(false) }
    var askDuration by remember { mutableStateOf<String?>(null) }
    var pickSource by remember { mutableStateOf<Triple<String, String, String>?>(null) }
    var pickDigest by remember { mutableStateOf(false) }
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

    ChPage(stringResource(R.string.at_title), onBack = { host.pop() }, settingsHeader = true) {
        val d = data
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
            if (d == null) {
                repeat(4) { Box(Modifier.fillMaxWidth().height(90.dp).padding(bottom = 10.dp).clip(RoundedCornerShape(22.dp)).background(Ch.SurfaceMuted)) }
                return@Column
            }
            // ------------------------------------------------ the context now (151)
            Text(
                stringResource(R.string.at_now),
                color = settingsInk(),
                fontSize = 16.sp,
                fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.padding(start = 4.dp),
            )
            Text(
                stringResource(R.string.at_now_sub),
                color = settingsMut(),
                fontSize = 12.5.sp,
                modifier = Modifier.padding(top = 2.dp, start = 4.dp, end = 4.dp, bottom = 10.dp),
            )
            val active = d.context?.key
            Column(
                Modifier
                    .fillMaxWidth()
                    .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
                    .padding(horizontal = 8.dp, vertical = 4.dp),
            ) {
                PrivacySettingsRow(
                    contextIcon(active ?: "none"),
                    active?.let { contextLabel(it) } ?: stringResource(R.string.at_ctx_none),
                    value = d.context?.until?.let { lifeWhen(it) }?.let { stringResource(R.string.at_until, it) },
                    last = true,
                ) { pickNow = true }
            }

            // ------------------------------------------------ attention per source (146)
            Text(
                stringResource(R.string.at_attention),
                color = settingsInk(),
                fontSize = 16.sp,
                fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.padding(top = 18.dp, start = 4.dp),
            )
            Text(
                stringResource(R.string.at_attention_sub),
                color = settingsMut(),
                fontSize = 12.5.sp,
                modifier = Modifier.padding(top = 2.dp, start = 4.dp, end = 4.dp, bottom = 10.dp),
            )
            val sources = d.sources.ifEmpty { listOf("contacts", "strangers", "groups", "mentions", "channels", "money", "urgent") }
            Column(
                Modifier
                    .fillMaxWidth()
                    .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
                    .padding(horizontal = 8.dp, vertical = 4.dp),
            ) {
                sources.forEach { src ->
                    val label = sourceLabel(src).second
                    val mode = d.attention[src] ?: "now"
                    PrivacySettingsRow(
                        sourceIcon(src),
                        label,
                        value = stringResource(modeLabel(mode)),
                    ) { pickSource = Triple(src, label, mode) }
                }
                PrivacySettingsRow(
                    Icons.Rounded.Schedule,
                    stringResource(R.string.at_digest_every),
                    value = digestMinutesLabel(d.digestMinutes),
                    last = true,
                ) { pickDigest = true }
            }

            // ------------------------------------------------ always get through (155)
            Text(
                stringResource(R.string.at_through),
                color = settingsInk(),
                fontSize = 16.sp,
                fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.padding(top = 18.dp, start = 4.dp),
            )
            Text(
                stringResource(R.string.at_through_sub),
                color = settingsMut(),
                fontSize = 12.5.sp,
                modifier = Modifier.padding(top = 2.dp, start = 4.dp, end = 4.dp, bottom = 10.dp),
            )
            val throughCount = d.alwaysThrough.size
            Column(
                Modifier
                    .fillMaxWidth()
                    .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
                    .padding(horizontal = 8.dp, vertical = 4.dp),
            ) {
                PrivacySettingsRow(
                    Icons.Rounded.People,
                    stringResource(R.string.at_through),
                    value = if (throughCount == 0) stringResource(R.string.at_through_none) else throughCount.toString(),
                    last = true,
                ) { pickPeople = true }
            }

            // ------------------------------------------------ calls (155)
            Text(
                stringResource(R.string.at_calls),
                color = settingsInk(),
                fontSize = 16.sp,
                fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.padding(top = 18.dp, start = 4.dp),
            )
            Text(
                stringResource(R.string.at_calls_sub),
                color = settingsMut(),
                fontSize = 12.5.sp,
                modifier = Modifier.padding(top = 2.dp, start = 4.dp, end = 4.dp, bottom = 10.dp),
            )
            val hours = d.callHours
            val hoursOn = hours?.from != null
            Column(
                Modifier
                    .fillMaxWidth()
                    .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
                    .padding(horizontal = 8.dp, vertical = 4.dp),
            ) {
                PrivacySettingsRow(
                    Icons.Rounded.Call,
                    stringResource(R.string.at_call_hours),
                    subtitle = stringResource(R.string.at_call_hours_note),
                    value = if (hoursOn) "${hours?.from} – ${hours?.to}" else stringResource(R.string.ch_off),
                    checked = hoursOn,
                    onChecked = { on ->
                        save(JsonObject().apply {
                            if (on) add("call_hours", JsonObject().apply {
                                addProperty("from", "09:00")
                                addProperty("to", "22:00")
                                add("days", JsonArray().apply { (0..6).forEach { add(it) } })
                                addProperty("timezone", ZoneId.systemDefault().id)
                            })
                            else add("call_hours", JsonNull.INSTANCE)
                        })
                    },
                )
                if (hoursOn) {
                    PrivacySettingsRow(
                        Icons.Rounded.AccessTime,
                        stringResource(R.string.ch_schedule_time),
                        value = "${hours?.from} – ${hours?.to}",
                    ) { editCallHours = true }
                    PrivacySettingsRow(
                        Icons.Rounded.DateRange,
                        stringResource(R.string.ch_weekdays),
                        value = callDaysLabel(hours?.days.orEmpty()),
                    ) { editCallHours = true }
                }
                PrivacySettingsRow(Icons.Rounded.Call, stringResource(R.string.at_who_can_call)) { host.push(ChRoute.Privacy) }
                PrivacySettingsRow(
                    Icons.Rounded.Bedtime,
                    stringResource(R.string.at_quiet_hours),
                    value = if (d.quiet?.on == true) stringResource(R.string.at_on_now) else stringResource(R.string.ch_off),
                    last = true,
                ) { host.push(ChRoute.PrivacyCenter) }
            }

            // ------------------------------------------------ on my phone only (156)
            LocalIntelligenceSection()

            // ------------------------------------------------ what each context does (151)
            Text(
                stringResource(R.string.at_contexts),
                color = settingsInk(),
                fontSize = 16.sp,
                fontWeight = FontWeight.ExtraBold,
                modifier = Modifier.padding(top = 18.dp, start = 4.dp),
            )
            Text(
                stringResource(R.string.at_contexts_sub),
                color = settingsMut(),
                fontSize = 12.5.sp,
                modifier = Modifier.padding(top = 2.dp, start = 4.dp, end = 4.dp, bottom = 10.dp),
            )
            Column(
                Modifier
                    .fillMaxWidth()
                    .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
                    .padding(horizontal = 8.dp, vertical = 4.dp),
            ) {
                val contexts = d.contexts.ifEmpty { listOf("home", "work", "meeting", "travel", "private").map { ContextDto(key = it) } }
                contexts.forEachIndexed { i, c ->
                    PrivacySettingsRow(
                        contextIcon(c.key),
                        contextLabel(c.key),
                        value = contextSummary(c),
                        last = i == contexts.lastIndex,
                    ) { host.push(ChRoute.ContextRules(c.key)) }
                }
            }
            Spacer(Modifier.height(24.dp))
        }
    }

    if (pickDigest) {
        val choices = listOf(15, 30, 60, 120, 240)
        val current = data?.digestMinutes ?: 60
        ChoiceSheet(
            title = stringResource(R.string.at_digest_every),
            options = choices.map { m ->
                digestMinutesLabel(m) to { save(JsonObject().apply { addProperty("digest_minutes", m) }) }
            },
            radios = true,
            selected = choices.indexOf(current).let { if (it < 0) 0 else it },
            onDismiss = { pickDigest = false },
        )
    }
    pickSource?.let { (src, title, mode) ->
        val modes = listOf("now", "digest", "later")
        ChoiceSheet(
            title = title,
            options = modes.map { m ->
                stringResource(modeLabel(m)) to {
                    save(JsonObject().apply {
                        add("attention", JsonObject().apply {
                            data?.attention?.forEach { (k, v) -> addProperty(k, v) }
                            addProperty(src, m)
                        })
                    })
                }
            },
            radios = true,
            selected = modes.indexOf(mode).let { if (it < 0) 0 else it },
            onDismiss = { pickSource = null },
        )
    }
    if (pickNow) {
        val choices = listOf<String?>(null, "home", "work", "meeting", "travel", "private")
        ChoiceSheet(
            title = stringResource(R.string.at_now),
            options = choices.map { k ->
                (k?.let { contextLabel(it) } ?: stringResource(R.string.at_ctx_none)) to {
                    if (k == null) setContext(null, null) else askDuration = k
                }
            },
            radios = true,
            selected = choices.indexOf(data?.context?.key).let { if (it < 0) 0 else it },
            onDismiss = { pickNow = false },
        )
    }
    askDuration?.let { key ->
        ChoiceSheet(
            contextLabel(key),
            listOf(
                stringResource(R.string.ch_hours_n, 1) to { setContext(key, 60) },
                stringResource(R.string.ch_hours_n, 3) to { setContext(key, 180) },
                stringResource(R.string.at_today) to { setContext(key, minutesToEndOfDay()) },
                stringResource(R.string.at_until_change) to { setContext(key, null) },
            ),
            radios = true,
            subtitle = stringResource(R.string.at_for_how_long),
            onDismiss = { askDuration = null },
        )
    }
    if (pickPeople) {
        PeoplePickerSheet(
            data?.alwaysThrough.orEmpty().mapNotNull { throughUserId(it.key) }.toSet(),
            onDismiss = { pickPeople = false },
            title = R.string.at_through,
            allowEmpty = true,
        ) { ids ->
            pickPeople = false
            save(JsonObject().apply { add("always_through", JsonArray().apply { ids.forEach { add("user:$it") } }) })
        }
    }
    if (editCallHours) {
        CallHoursSheet(data?.callHours, onDismiss = { editCallHours = false }) { from, to, days ->
            save(callHoursBody(from, to, days))
            editCallHours = false
        }
    }
}

@Composable
fun ContextRulesPage(key: String) {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var data by remember { mutableStateOf<AttentionDto?>(null) }
    var pickShows by remember { mutableStateOf(false) }
    var pickCalls by remember { mutableStateOf(false) }
    var pickSource by remember { mutableStateOf<Triple<String, String, String>?>(null) }
    LaunchedEffect(key) { data = runCatching { ApiClient.life.attention(chatAuth()).data }.getOrNull() }
    fun saveContext(change: JsonObject) {
        scope.launch {
            runCatching {
                ApiClient.life.saveAttention(chatAuth(), JsonObject().apply {
                    add("contexts", JsonObject().apply { add(key, change) })
                }).data
            }.onSuccess { r -> r?.let { data = it } }.onFailure { host.showToast(it.apiFailure().message ?: "") }
        }
    }
    val c = data?.contexts?.firstOrNull { it.key == key } ?: ContextDto(key = key)
    val sources = data?.sources.orEmpty().ifEmpty { listOf("contacts", "strangers", "groups", "mentions", "channels", "money", "urgent") }
    val showChoices = listOf<String?>(null, "name", "none")
    val callChoices = listOf("everyone", "through", "nobody")
    ChPage(contextLabel(key), onBack = { host.pop() }, settingsHeader = true) {
        if (data == null) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                androidx.compose.material3.CircularProgressIndicator(color = Ch.Red)
            }
            return@ChPage
        }
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
            Column(
                Modifier
                    .fillMaxWidth()
                    .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
                    .padding(horizontal = 8.dp, vertical = 4.dp),
            ) {
                PrivacySettingsRow(
                    Icons.Rounded.Visibility,
                    stringResource(R.string.at_shows),
                    value = stringResource(disclosureLabel(c.disclosure)),
                ) { pickShows = true }
                PrivacySettingsRow(
                    Icons.Rounded.Call,
                    stringResource(R.string.at_calls),
                    value = stringResource(callsLabel(c.calls)),
                ) { pickCalls = true }
                sources.forEachIndexed { i, src ->
                    val label = sourceLabel(src).second
                    val mode = c.attention[src] ?: "now"
                    PrivacySettingsRow(
                        sourceIcon(src),
                        label,
                        value = stringResource(modeLabel(mode)),
                        last = i == sources.lastIndex,
                    ) { pickSource = Triple(src, label, mode) }
                }
            }
        }
    }
    if (pickShows) {
        ChoiceSheet(
            title = stringResource(R.string.at_shows),
            options = showChoices.map { v ->
                stringResource(disclosureLabel(v)) to {
                    saveContext(JsonObject().apply {
                        if (v == null) add("disclosure", JsonNull.INSTANCE) else addProperty("disclosure", v)
                    })
                }
            },
            radios = true,
            selected = showChoices.indexOf(c.disclosure.takeIf { it != "all" }).let { if (it < 0) 0 else it },
            onDismiss = { pickShows = false },
        )
    }
    if (pickCalls) {
        ChoiceSheet(
            title = stringResource(R.string.at_calls),
            options = callChoices.map { v ->
                stringResource(callsLabel(v)) to { saveContext(JsonObject().apply { addProperty("calls", v) }) }
            },
            radios = true,
            selected = callChoices.indexOf(c.calls).let { if (it < 0) 0 else it },
            onDismiss = { pickCalls = false },
        )
    }
    pickSource?.let { (src, title, mode) ->
        val modes = listOf("now", "digest", "later")
        ChoiceSheet(
            title = title,
            options = modes.map { m ->
                stringResource(modeLabel(m)) to {
                    saveContext(JsonObject().apply {
                        add("attention", JsonObject().apply {
                            c.attention.forEach { (k, v) -> addProperty(k, v) }
                            addProperty(src, m)
                        })
                    })
                }
            },
            radios = true,
            selected = modes.indexOf(mode).let { if (it < 0) 0 else it },
            onDismiss = { pickSource = null },
        )
    }
}

private fun disclosureLabel(value: String?): Int = when (value) {
    "name" -> R.string.at_show_name
    "none" -> R.string.at_show_nothing
    else -> R.string.at_show_usual
}

private fun callsLabel(value: String): Int = when (value) {
    "through" -> R.string.at_calls_through
    "nobody" -> R.string.at_calls_nobody
    else -> R.string.at_calls_everyone
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

private fun throughUserId(key: String): Int? =
    if (key.startsWith("user:")) key.removePrefix("user:").toIntOrNull() else null

@Composable
private fun callDaysLabel(days: List<Int>): String {
    val selected = days.ifEmpty { (0..6).toList() }.sorted()
    val locale = com.dorr.app.network.AppLocale.java()
    return selected.joinToString(" · ") { DayOfWeek.of(if (it == 0) 7 else it).getDisplayName(TextStyle.SHORT, locale) }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CallHoursSheet(current: CallHoursDto?, onDismiss: () -> Unit, onSave: (String, String, List<Int>) -> Unit) {
    var from by remember { mutableStateOf(current?.from ?: "09:00") }
    var to by remember { mutableStateOf(current?.to ?: "22:00") }
    val days = remember { mutableStateListOf<Int>().apply { addAll(current?.days?.ifEmpty { (0..6).toList() } ?: (0..6).toList()) } }
    var picking by remember { mutableStateOf<Boolean?>(null) }
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.at_call_hours), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(14.dp))
            PrivacySettingsRow(Icons.Rounded.AccessTime, stringResource(R.string.ch_from), value = from) { picking = true }
            PrivacySettingsRow(Icons.Rounded.AccessTime, stringResource(R.string.ch_to), value = to, last = true) { picking = false }
            Text(stringResource(R.string.ch_weekdays), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp, modifier = Modifier.padding(top = 10.dp, bottom = 8.dp))
            val locale = com.dorr.app.network.AppLocale.java()
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                (0..6).chunked(3).forEach { row ->
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        row.forEach { d ->
                            val on = d in days
                            val name = DayOfWeek.of(if (d == 0) 7 else d).getDisplayName(TextStyle.FULL, locale)
                            Row(
                                Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(Ch.SurfaceMuted).clickable {
                                    if (on) { if (days.size > 1) days.remove(d) } else days.add(d)
                                }.padding(horizontal = 12.dp, vertical = 11.dp),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Text(name, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.5.sp, maxLines = 1, modifier = Modifier.weight(1f))
                                Icon(
                                    if (on) Icons.Rounded.CheckBox else Icons.Rounded.CheckBoxOutlineBlank,
                                    null,
                                    tint = if (on) Ch.Red else Ch.Soft,
                                    modifier = Modifier.size(22.dp),
                                )
                            }
                        }
                        repeat(3 - row.size) { Spacer(Modifier.weight(1f)) }
                    }
                }
            }
            Spacer(Modifier.height(18.dp))
            ChPrimaryButton(stringResource(R.string.ch_save), modifier = Modifier.fillMaxWidth(), enabled = days.isNotEmpty()) {
                onSave(from, to, days.toList())
            }
        }
    }
    picking?.let { isFrom ->
        TimePickDialog(LocalTime.parse(if (isFrom) from else to), onDismiss = { picking = null }) { t ->
            val text = "%02d:%02d".format(t.hour, t.minute)
            if (isFrom) from = text else to = text
            picking = null
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
@OptIn(ExperimentalLayoutApi::class, ExperimentalMaterial3Api::class)
@Composable
private fun LocalIntelligenceSection() {
    val context = androidx.compose.ui.platform.LocalContext.current
    var on by remember { mutableStateOf(com.dorr.app.chat.LocalIntelligence.enabled(context)) }
    var hide by remember { mutableStateOf(com.dorr.app.chat.LocalIntelligence.hideSensitive(context)) }
    var words by remember { mutableStateOf(com.dorr.app.chat.LocalIntelligence.keywords(context)) }
    var adding by remember { mutableStateOf("") }
    var trying by remember { mutableStateOf("") }
    var editWords by remember { mutableStateOf(false) }
    Text(
        stringResource(R.string.lp_title),
        color = settingsInk(),
        fontSize = 16.sp,
        fontWeight = FontWeight.ExtraBold,
        modifier = Modifier.padding(top = 18.dp, start = 4.dp),
    )
    Text(
        stringResource(R.string.lp_sub),
        color = settingsMut(),
        fontSize = 12.5.sp,
        modifier = Modifier.padding(top = 2.dp, start = 4.dp, end = 4.dp, bottom = 10.dp),
    )
    Column(
        Modifier
            .fillMaxWidth()
            .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
            .padding(horizontal = 8.dp, vertical = 4.dp),
    ) {
        PrivacySettingsRow(
            Icons.Rounded.PhoneAndroid,
            stringResource(R.string.lp_on),
            last = !on,
            checked = on,
            onChecked = { on = it; com.dorr.app.chat.LocalIntelligence.setEnabled(context, it) },
        )
        if (on) {
            PrivacySettingsRow(
                Icons.Rounded.VisibilityOff,
                stringResource(R.string.lp_hide),
                subtitle = stringResource(R.string.lp_hide_sub),
                checked = hide,
                onChecked = { hide = it; com.dorr.app.chat.LocalIntelligence.setHideSensitive(context, it) },
            )
            PrivacySettingsRow(
                Icons.Rounded.Star,
                stringResource(R.string.lp_words),
                value = if (words.isEmpty()) "0" else words.size.toString(),
                last = true,
            ) { editWords = true }
            Text(stringResource(R.string.lp_try), color = settingsMut(), fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 4.dp, top = 6.dp, bottom = 6.dp))
            ChField(trying, { trying = it.take(200) }, stringResource(R.string.lp_try_hint), modifier = Modifier.padding(start = 4.dp, end = 4.dp, bottom = 10.dp))
            if (trying.isNotBlank()) {
                val kind = com.dorr.app.chat.LocalIntelligence.kindOf(trying)
                val word = words.firstOrNull { trying.contains(it, ignoreCase = true) }
                Text(
                    listOfNotNull(word?.let { "⭐ " + stringResource(R.string.lp_kind_keyword, it) }, kind?.let { "${it.emoji} " + stringResource(it.label) }).joinToString(" · ")
                        .ifEmpty { stringResource(R.string.lp_kind_none) },
                    color = Ch.Red, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 4.dp, bottom = 10.dp),
                )
            }
        }
    }
    if (editWords) {
        ChSheet(onDismissRequest = { editWords = false }, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
            Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
                Text(stringResource(R.string.lp_words), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                Spacer(Modifier.height(10.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.weight(1f)) { ChField(adding, { adding = it.take(40) }, stringResource(R.string.lp_word_hint)) }
                    Spacer(Modifier.width(8.dp))
                    Text("＋", color = Ch.Red, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.clip(CircleShape).clickable(enabled = adding.trim().length >= 2) {
                        words = (words + adding.trim()).distinct()
                        com.dorr.app.chat.LocalIntelligence.setKeywords(context, words)
                        adding = ""
                    }.padding(10.dp))
                }
                Spacer(Modifier.height(10.dp))
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    words.forEach { w ->
                        val onWord = true
                        Row(
                            Modifier.clip(CircleShape).background(if (onWord) Ch.Red.copy(alpha = 0.12f) else Ch.SurfaceMuted)
                                .clickable {
                                    words = words - w
                                    com.dorr.app.chat.LocalIntelligence.setKeywords(context, words)
                                }.padding(start = 12.dp, end = 8.dp, top = 8.dp, bottom = 8.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Text(w, color = Ch.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                            Icon(Icons.Rounded.CheckBox, null, tint = Ch.Red, modifier = Modifier.padding(start = 6.dp).size(18.dp))
                        }
                    }
                }
                Spacer(Modifier.height(16.dp))
                ChPrimaryButton(stringResource(R.string.ch_save), modifier = Modifier.fillMaxWidth()) { editWords = false }
            }
        }
    }
}
