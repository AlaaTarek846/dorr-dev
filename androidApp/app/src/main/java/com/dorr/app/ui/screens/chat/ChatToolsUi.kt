package com.dorr.app.ui.screens.chat

import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Alarm
import androidx.compose.material.icons.rounded.BookmarkAdd
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.Mood
import androidx.compose.material.icons.rounded.PersonOff
import androidx.compose.material.icons.rounded.RadioButtonChecked
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MessageDto
import com.dorr.app.network.PrivacyDto
import com.dorr.app.network.ReminderDto
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.launch
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter

// =============================================================================== personal status

/** Ready-made statuses: emoji + a string resource. */
private val StatusPresets = listOf(
    "🏖️" to R.string.ch_status_holiday,
    "💼" to R.string.ch_status_work,
    "🚗" to R.string.ch_status_driving,
    "📵" to R.string.ch_status_busy,
    "🤒" to R.string.ch_status_sick,
    "🌙" to R.string.ch_status_sleeping,
    "✈️" to R.string.ch_status_travelling,
    "🕌" to R.string.ch_status_prayer,
)

/**
 * My status (spec 90): an emoji and a few words, how long it lasts, and who sees it. Shown under
 * my name in other people's chats.
 */
@Composable
fun MyStatusPage() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var loaded by remember { mutableStateOf<PrivacyDto?>(null) }
    var emoji by remember { mutableStateOf("") }
    var text by remember { mutableStateOf("") }
    var hours by remember { mutableStateOf<Int?>(null) } // null = until I clear it
    var audience by remember { mutableStateOf("contacts") }
    // Hidden from these people (WhatsApp list 54).
    var except by remember { mutableStateOf<List<String>>(emptyList()) }
    var pickExcept by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    val networkError = stringResource(R.string.ch_error_network)
    val savedMsg = stringResource(R.string.ch_status_saved)
    val removedMsg = stringResource(R.string.ch_status_removed)
    LaunchedEffect(Unit) {
        runCatching { ApiClient.chat.privacy(chatAuth()).data }.getOrNull()?.let { p ->
            loaded = p
            p.status?.takeIf { it.active }?.let { emoji = it.emoji.orEmpty(); text = it.text.orEmpty() }
            audience = p.status?.audience ?: "contacts"
            except = p.status?.except.orEmpty()
        }
    }

    fun save(ok: String, body: suspend () -> Unit) {
        busy = true
        scope.launch {
            try {
                body()
                host.showToast(ok)
                host.pop()
            } catch (e: Exception) {
                host.showToast(e.apiFailure().message ?: networkError)
            }
            busy = false
        }
    }

    if (pickExcept) {
        ExceptPicker(except, onDismiss = { pickExcept = false }) { except = it; pickExcept = false }
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(R.string.ch_status_title)) { host.pop() }
            Column(Modifier.weight(1f).fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 12.dp)) {
            Text(stringResource(R.string.ch_status_sub), color = Ch.Mut, fontSize = 13.sp)
            Spacer(Modifier.height(12.dp))
            val sampleName = stringResource(R.string.ch_status_sample_name)
            val preview = listOfNotNull(emoji.trim().ifBlank { null }, text.trim().ifBlank { null }).joinToString(" ")
            Row(
                Modifier
                    .fillMaxWidth()
                    .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
                    .padding(horizontal = 14.dp, vertical = 12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                ChAvatar(null, sampleName, "status-sample", size = 46.dp)
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(sampleName, color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(
                        preview.ifBlank { stringResource(R.string.ch_status_hint) },
                        color = if (preview.isBlank()) settingsMut() else settingsAccent(),
                        fontWeight = if (preview.isBlank()) FontWeight.Normal else FontWeight.Bold,
                        fontSize = 13.sp,
                        maxLines = 2,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
            }
            Spacer(Modifier.height(14.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                ChField(emoji, { emoji = it.take(4) }, "🙂", modifier = Modifier.width(80.dp), center = true)
                Spacer(Modifier.width(8.dp))
                ChField(text, { text = it.take(100) }, stringResource(R.string.ch_status_hint), modifier = Modifier.weight(1f), icon = Icons.Rounded.Mood)
            }
            Spacer(Modifier.height(12.dp))
            StatusPresetSelect(emoji = emoji, text = text) { e, words ->
                emoji = e
                text = words
            }
            Spacer(Modifier.height(16.dp))
            Text(stringResource(R.string.ch_status_for), color = settingsMut(), fontWeight = FontWeight.Bold, fontSize = 13.sp)
            Spacer(Modifier.height(8.dp))
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf(1 to R.string.ch_status_1h, 4 to R.string.ch_status_4h, 24 to R.string.ch_status_today, 168 to R.string.ch_status_week, null to R.string.ch_status_forever)
                    .forEach { (h, label) -> StatusChoiceCard(stringResource(label), selected = hours == h) { hours = h } }
            }
            Spacer(Modifier.height(16.dp))
            Text(stringResource(R.string.ch_status_who), color = settingsMut(), fontWeight = FontWeight.Bold, fontSize = 13.sp)
            Spacer(Modifier.height(8.dp))
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("everyone" to R.string.ch_everyone, "contacts" to R.string.ch_my_contacts, "nobody" to R.string.ch_nobody)
                    .forEach { (value, label) -> StatusChoiceCard(stringResource(label), selected = audience == value) { audience = value } }
            }
            if (audience != "nobody") {
                Spacer(Modifier.height(12.dp))
                Row(
                    Modifier
                        .fillMaxWidth()
                        .clip(RoundedCornerShape(18.dp))
                        .background(Ch.SurfaceMuted)
                        .border(1.dp, Ch.Line, RoundedCornerShape(18.dp))
                        .clickable { pickExcept = true }
                        .padding(horizontal = 16.dp, vertical = 14.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.PersonOff, null, tint = Ch.Ink, modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(
                        stringResource(R.string.ch_status_except_people),
                        color = Ch.Ink,
                        fontWeight = FontWeight.Bold,
                        fontSize = 14.sp,
                        modifier = Modifier.weight(1f),
                    )
                    Text(
                        except.size.toString(),
                        color = Ch.Red,
                        fontWeight = FontWeight.ExtraBold,
                        fontSize = 14.sp,
                    )
                    Spacer(Modifier.width(6.dp))
                    Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = Ch.Soft, modifier = Modifier.size(20.dp))
                }
            }
            }
            val canSave = !busy && (emoji.isNotBlank() || text.isNotBlank())
            val hasStatus = loaded?.status?.active == true
            val persistStatus: () -> Unit = {
                save(savedMsg) {
                    ApiClient.chat.setStatus(chatAuth(), buildMap<String, Any> {
                        if (emoji.isNotBlank()) put("emoji", emoji.trim())
                        if (text.isNotBlank()) put("text", text.trim())
                        hours?.let { put("until", ZonedDateTime.now().plusHours(it.toLong()).format(DateTimeFormatter.ISO_OFFSET_DATE_TIME)) }
                        put("audience", audience)
                        put("except", except)
                    })
                }
            }
            Row(
                Modifier
                    .fillMaxWidth()
                    .imePadding()
                    .padding(horizontal = 20.dp, vertical = 12.dp),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                ChPrimaryButton(stringResource(R.string.ch_save), modifier = Modifier.weight(1f), enabled = canSave) { persistStatus() }
                Box(
                    Modifier
                        .weight(1f)
                        .clip(RoundedCornerShape(18.dp))
                        .background(Ch.Danger.copy(alpha = 0.12f))
                        .clickable(enabled = !busy && hasStatus) { save(removedMsg) { ApiClient.chat.clearStatus(chatAuth()) } }
                        .padding(vertical = 14.dp),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(
                        stringResource(R.string.ch_status_clear),
                        color = Ch.Danger.copy(alpha = if (hasStatus) 1f else 0.45f),
                        fontWeight = FontWeight.ExtraBold,
                        fontSize = 15.sp,
                    )
                }
            }
        }
    }
}

@Composable
private fun StatusPresetSelect(emoji: String, text: String, onPick: (String, String) -> Unit) {
    val pickLabel = stringResource(R.string.ch_status_pick)
    val presets = StatusPresets.map { (e, label) -> e to stringResource(label) }
    var fromList by remember { mutableStateOf(false) }
    val matched = if (fromList) presets.firstOrNull { (e, words) -> emoji == e && text == words } else null
    val shown = matched?.let { "${it.first} ${it.second}" } ?: pickLabel
    var open by remember { mutableStateOf(false) }
    val density = LocalDensity.current
    var menuWidth by remember { mutableStateOf(0.dp) }
    Box(Modifier.fillMaxWidth().onGloballyPositioned { menuWidth = with(density) { it.size.width.toDp() } }) {
        Row(
            Modifier
                .fillMaxWidth()
                .clip(RoundedCornerShape(18.dp))
                .background(Ch.FieldFill)
                .border(1.dp, Ch.FieldLine, RoundedCornerShape(18.dp))
                .clickable { open = true }
                .padding(horizontal = 16.dp, vertical = 14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                shown,
                color = if (matched == null) Ch.Soft else Ch.Ink,
                fontWeight = FontWeight.Bold,
                fontSize = 14.sp,
                modifier = Modifier.weight(1f),
            )
            Icon(Icons.Rounded.KeyboardArrowDown, null, tint = Ch.Mut, modifier = Modifier.size(22.dp))
        }
        DropdownMenu(
            expanded = open,
            onDismissRequest = { open = false },
            containerColor = Ch.Surface,
            shape = RoundedCornerShape(16.dp),
            modifier = Modifier.width(menuWidth).heightIn(max = 320.dp),
        ) {
            DropdownMenuItem(
                text = {
                    Text(pickLabel, color = if (matched == null) Ch.Red else Ch.Ink, fontWeight = if (matched == null) FontWeight.ExtraBold else FontWeight.SemiBold, fontSize = 14.sp)
                },
                onClick = { open = false; fromList = false; onPick("", "") },
            )
            presets.forEach { (e, words) ->
                val on = matched?.first == e && matched?.second == words
                DropdownMenuItem(
                    text = {
                        Text("$e $words", color = if (on) Ch.Red else Ch.Ink, fontWeight = if (on) FontWeight.ExtraBold else FontWeight.SemiBold, fontSize = 14.sp)
                    },
                    onClick = { open = false; fromList = true; onPick(e, words) },
                )
            }
        }
    }
}

@Composable
private fun StatusChoiceCard(label: String, selected: Boolean, onClick: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
            .clickable(onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            label,
            fontSize = 13.5.sp,
            fontWeight = FontWeight.Bold,
            color = settingsInk(),
            modifier = Modifier.weight(1f),
        )
        Icon(
            if (selected) Icons.Rounded.RadioButtonChecked else Icons.Rounded.RadioButtonUnchecked,
            null,
            tint = if (selected) settingsAccent() else settingsMut(),
            modifier = Modifier.size(22.dp),
        )
    }
}

@Composable
private fun ExceptPicker(keys: List<String>, onDismiss: () -> Unit, onDone: (List<String>) -> Unit) {
    PeoplePickerSheet(
        keys.mapNotNull { it.removePrefix("user:").toIntOrNull() }.toSet(),
        onDismiss = onDismiss,
        title = R.string.ch_status_pick_contact,
    ) { ids ->
        onDone(ids.map { "user:$it" })
    }
}

@Composable
private fun Chip(label: String, selected: Boolean, onClick: () -> Unit) {
    val bg by animateColorAsState(if (selected) Ch.Red else Ch.SurfaceMuted, label = "chip")
    Text(
        label, color = if (selected) Color.White else Ch.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.clip(CircleShape).background(bg).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 8.dp),
    )
}

/** "🏖️ On holiday" for a profile's status, or null. */
internal fun statusLine(status: com.dorr.app.network.UserStatusDto?): String? =
    status?.let { listOfNotNull(it.emoji, it.text).joinToString(" ").trim().ifEmpty { null } }

// =============================================================================== my follow-ups

/**
 * Everything I set aside, in three tabs: read later, waiting for my reply, and reminders. Tap a
 * message to see it in its chat; "Done" takes it off.
 */
@Composable
fun FollowUpsPage() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var tab by remember { mutableStateOf(0) }
    var readLater by remember { mutableStateOf<List<MessageDto>?>(null) }
    var replies by remember { mutableStateOf<List<MessageDto>?>(null) }
    var reminders by remember { mutableStateOf<List<ReminderDto>?>(null) }
    LaunchedEffect(Unit) {
        readLater = runCatching { ApiClient.chat.readLaterList(chatAuth()).data }.getOrNull().orEmpty()
        replies = runCatching { ApiClient.chat.followUpList(chatAuth()).data }.getOrNull().orEmpty()
        reminders = runCatching { ApiClient.chat.reminders(chatAuth()).data }.getOrNull().orEmpty()
    }

    ChPage(stringResource(R.string.ch_follow_ups_title), onBack = { host.pop() }) {
        Column(Modifier.fillMaxSize()) {
            Row(Modifier.fillMaxWidth().padding(14.dp).clip(RoundedCornerShape(18.dp)).background(Ch.SurfaceMuted).padding(4.dp)) {
                listOf(
                    R.string.ch_read_later_title to readLater?.size,
                    R.string.ch_needs_reply_title to replies?.size,
                    R.string.ch_reminders_title to reminders?.size,
                ).forEachIndexed { i, (label, count) ->
                    val on = tab == i
                    val bg by animateColorAsState(if (on) Ch.Surface else Color.Transparent, label = "fuTab")
                    Text(
                        stringResource(label) + (count?.takeIf { it > 0 }?.let { " ($it)" } ?: ""),
                        color = if (on) Ch.Red else Ch.Mut, fontWeight = FontWeight.ExtraBold, fontSize = 12.5.sp, textAlign = TextAlign.Center, maxLines = 1,
                        modifier = Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(bg).clickable { tab = i }.padding(vertical = 9.dp),
                    )
                }
            }
            when (tab) {
                0 -> FollowList(readLater, Icons.Rounded.BookmarkAdd, R.string.ch_read_later_empty, label = { null }) { m ->
                    readLater = readLater.orEmpty() - m
                    scope.launch { runCatching { ApiClient.chat.readLater(chatAuth(), m.id, mapOf("on" to false)) } }
                }
                1 -> FollowList(replies, Icons.Rounded.Flag, R.string.ch_needs_reply_empty, label = { null }) { m ->
                    replies = replies.orEmpty() - m
                    scope.launch { runCatching { ApiClient.chat.followUp(chatAuth(), m.id, mapOf("on" to false)) } }
                }
                else -> {
                    val byMessage = reminders.orEmpty().associateBy { it.message.id }
                    FollowList(reminders?.map { it.message }, Icons.Rounded.Alarm, R.string.ch_reminders_empty, label = { m ->
                        byMessage[m.id]?.let { r -> "⏰ " + scheduleLabel(r.remindAt) + (r.note?.let { " · $it" } ?: "") }
                    }) { m ->
                        reminders = reminders.orEmpty().filterNot { it.message.id == m.id }
                        scope.launch { runCatching { ApiClient.chat.clearReminder(chatAuth(), m.id) } }
                    }
                }
            }
        }
    }
}

@Composable
private fun FollowList(items: List<MessageDto>?, icon: androidx.compose.ui.graphics.vector.ImageVector, empty: Int, label: (MessageDto) -> String?, onDone: (MessageDto) -> Unit) {
    val host = LocalChat.current
    when {
        items == null -> Box(Modifier.fillMaxSize()) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().padding(16.dp).height(200.dp)) }
        items.isEmpty() -> ChEmptyState(icon, "", stringResource(empty), animated = true)
        else -> LazyColumn(contentPadding = PaddingValues(bottom = 12.dp)) {
            itemsIndexed(items, key = { _, m -> m.id }) { i, m ->
                Column(Modifier.fillMaxWidth().chStagger(i).clickable { host.showInChat(m.conversationId, m.id) }.padding(vertical = 6.dp)) {
                    Row(Modifier.padding(horizontal = 18.dp), verticalAlignment = Alignment.CenterVertically) {
                        ChAvatar(m.sender?.avatar, m.sender?.name, m.sender?.key, size = 26.dp)
                        Spacer(Modifier.width(8.dp))
                        Column(Modifier.weight(1f)) {
                            Text(if (m.sender?.isMe == true) stringResource(R.string.ch_you) else m.sender?.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                            label(m)?.let { Text(it, color = Ch.Red, fontSize = 11.5.sp, fontWeight = FontWeight.Bold) }
                        }
                        Text(
                            stringResource(R.string.ch_read_later_done_short), color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 12.5.sp,
                            modifier = Modifier.clip(RoundedCornerShape(12.dp)).background(Ch.Red.copy(alpha = 0.08f)).clickable { onDone(m) }.padding(horizontal = 10.dp, vertical = 5.dp),
                        )
                    }
                    MessageRow(UiMessage(m), firstInRun = true, lastInRun = true, isGroup = false, actions = BubbleActions({}, {}, {}, {}, { _, _ -> }, { host.openWalletQr(it) }, {}))
                }
            }
        }
    }
}

// =============================================================================== money gifts

/** Gift cards for a money gift (the server's MessageService::GIFT_CARDS): emoji + title. */
internal val GiftCards = listOf(
    "general" to ("🎁" to R.string.ch_gift_general),
    "birthday" to ("🎂" to R.string.ch_gift_birthday),
    "eid" to ("🌙" to R.string.ch_gift_eid),
    "ramadan" to ("🏮" to R.string.ch_gift_ramadan),
    "wedding" to ("💍" to R.string.ch_gift_wedding),
    "newborn" to ("👶" to R.string.ch_gift_newborn),
    "graduation" to ("🎓" to R.string.ch_gift_graduation),
    "congrats" to ("🎉" to R.string.ch_gift_congrats),
    "thanks" to ("💐" to R.string.ch_gift_thanks),
)

/** Pick an occasion to send the money as a gift card (or none: a plain transfer). */
@Composable
internal fun GiftPicker(selected: String?, onPick: (String?) -> Unit) {
    Column(Modifier.fillMaxWidth()) {
        Text(stringResource(R.string.ch_gift_as), color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 13.sp)
        Spacer(Modifier.height(6.dp))
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            Chip(stringResource(R.string.ch_gift_none), selected = selected == null) { onPick(null) }
            GiftCards.forEach { (key, card) -> Chip(card.first + " " + stringResource(card.second), selected = selected == key) { onPick(key) } }
        }
    }
}

/** The occasion's emoji and title, for the gift card in the chat. */
@Composable
internal fun giftOf(key: String?): Pair<String, String>? =
    GiftCards.firstOrNull { it.first == key }?.second?.let { (emoji, title) -> emoji to stringResource(title) }
