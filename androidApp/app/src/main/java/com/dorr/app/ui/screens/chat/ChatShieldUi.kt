package com.dorr.app.ui.screens.chat

import android.app.Activity
import android.os.Build
import android.view.WindowManager
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccessTime
import androidx.compose.material.icons.rounded.Bedtime
import androidx.compose.material.icons.rounded.CheckBox
import androidx.compose.material.icons.rounded.CheckBoxOutlineBlank
import androidx.compose.material.icons.rounded.DateRange
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.chat.ChatShield
import com.dorr.app.network.ApiClient
import com.dorr.app.network.PrivacyDto
import com.dorr.app.network.PrivacySummaryDto
import com.dorr.app.network.apiFailure
import com.google.gson.JsonArray
import com.google.gson.JsonNull
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.DayOfWeek
import java.time.LocalTime
import java.time.ZoneId
import java.time.format.TextStyle

// =============================================================================== recent apps

/**
 * While the chat is on screen and "hide in recent apps" is on, the recent-apps screen shows
 * nothing of it (spec 106): Android 13+ just skips the snapshot; older phones need FLAG_SECURE,
 * which also blocks screenshots there.
 */
@Composable
internal fun HideFromRecents() {
    val activity = LocalContext.current as? Activity ?: return
    val on = ChatShield.hideInRecents
    DisposableEffect(on) {
        if (on) {
            if (Build.VERSION.SDK_INT >= 33) activity.setRecentsScreenshotEnabled(false)
            else activity.window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
        }
        onDispose {
            if (on) {
                if (Build.VERSION.SDK_INT >= 33) activity.setRecentsScreenshotEnabled(true)
                else activity.window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
            }
        }
    }
}

// =============================================================================== privacy mode

/**
 * In the privacy page: quick privacy mode (spec 111) with its duration, the daily schedule
 * (112), and this phone's own protections (Safe View 105, recent apps 106, blurred media 109,
 * hiding again 110).
 */
@Composable
internal fun PrivacyShieldCards(privacy: PrivacyDto, onChanged: (PrivacyDto) -> Unit, onSummary: (PrivacySummaryDto) -> Unit) {
    val context = LocalContext.current
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var durations by remember { mutableStateOf(false) }
    var schedule by remember { mutableStateOf(false) }
    var rehide by remember { mutableStateOf(false) }
    var quiet by remember { mutableStateOf(false) }
    val mode = privacy.privacyMode
    val networkError = stringResource(R.string.ch_error_network)
    val savedToast = stringResource(R.string.ch_saved)

    fun call(block: suspend () -> Unit) = scope.launch {
        try {
            block()
            host.showToast(savedToast)
        } catch (e: Exception) {
            host.showToast(e.apiFailure().message ?: networkError)
        }
    }

    PrivacySettingsRow(
        Icons.Rounded.Shield, stringResource(R.string.ch_privacy_mode),
        subtitle = when {
            mode?.until != null -> stringResource(R.string.ch_privacy_mode_until, scheduleLabel(mode.until))
            mode?.on == true -> stringResource(R.string.ch_privacy_mode_by_schedule)
            else -> stringResource(R.string.ch_privacy_mode_sub)
        },
        checked = mode?.on == true,
        onChecked = { on ->
            if (on) durations = true
            else call {
                val off = ApiClient.chat.privacyModeOff(chatAuth()).data ?: return@call
                ChatShield.setPrivacyStartedAt(context, 0)
                ChatShield.setSafeView(context, false)
                onChanged(off.settings)
                onSummary(off.summary)
            }
        },
    )
    PrivacySettingsRow(
        Icons.Rounded.Bedtime, stringResource(R.string.ch_privacy_schedule),
        value = mode?.schedule?.let { "${it.from} – ${it.to}" } ?: stringResource(R.string.ch_off),
    ) { schedule = true }
    PrivacySettingsRow(
        Icons.Rounded.Bedtime, stringResource(R.string.ch_quiet),
        value = privacy.quiet?.schedule?.let { "${it.from} – ${it.to}" } ?: stringResource(R.string.ch_off),
    ) { quiet = true }
    PrivacySettingsRow(Icons.Rounded.Lock, stringResource(R.string.ch_safe_view), subtitle = stringResource(R.string.ch_safe_view_sub), checked = ChatShield.safeView, onChecked = { ChatShield.setSafeView(context, it) })
    PrivacySettingsRow(Icons.Rounded.Lock, stringResource(R.string.ch_hide_recents), subtitle = stringResource(R.string.ch_hide_recents_sub), checked = ChatShield.hideInRecents, onChecked = { ChatShield.setHideInRecents(context, it) })
    PrivacySettingsRow(Icons.Rounded.Lock, stringResource(R.string.ch_blur_media), subtitle = stringResource(R.string.ch_blur_media_sub), checked = ChatShield.blurMedia, onChecked = { ChatShield.setBlurMedia(context, it) })
    PrivacySettingsRow(Icons.Rounded.Lock, stringResource(R.string.ch_rehide), value = rehideLabel(ChatShield.rehideSeconds), last = true) { rehide = true }

    if (durations) ChoiceSheet(
        stringResource(R.string.ch_privacy_mode),
        listOf(30, 60, 240, 480, 1440).map { minutes ->
            durationLabel(minutes) to {
                call {
                    ApiClient.chat.privacyModeOn(chatAuth(), mapOf("minutes" to minutes)).data?.let(onChanged)
                    // Safe View goes on with it; the summary is offered when it ends.
                    ChatShield.setSafeView(context, true)
                    if (ChatShield.privacyStartedAt(context) == 0L) ChatShield.setPrivacyStartedAt(context, System.currentTimeMillis())
                }
                Unit
            }
        },
        subtitle = stringResource(R.string.ch_privacy_mode_sub),
        onDismiss = { durations = false },
    )
    if (schedule) PrivacyScheduleSheet(privacy, onDismiss = { schedule = false }) { body -> call { ApiClient.chat.updatePrivacyJson(chatAuth(), body).data?.let(onChanged) } }
    if (quiet) PrivacyScheduleSheet(privacy, onDismiss = { quiet = false }, quiet = true) { body -> call { ApiClient.chat.updatePrivacyJson(chatAuth(), body).data?.let(onChanged) } }
    if (rehide) {
        val choices = listOf(0, 30, 60, 300)
        ChoiceSheet(
            stringResource(R.string.ch_rehide),
            choices.map { s -> rehideLabel(s) to { ChatShield.setRehideSeconds(context, s); Unit } },
            radios = true,
            selected = choices.indexOf(ChatShield.rehideSeconds).takeIf { it >= 0 } ?: 0,
            onDismiss = { rehide = false },
        )
    }
}

@Composable
private fun rehideLabel(seconds: Int): String = when (seconds) {
    0 -> stringResource(R.string.ch_rehide_leave)
    else -> slowModeLabel(seconds)
}

@Composable
private fun durationLabel(minutes: Int): String = if (minutes < 60) stringResource(R.string.ch_minutes_n, minutes) else stringResource(R.string.ch_hours_n, minutes / 60)

/** Privacy mode every day between two times, on the days I pick (spec 112). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PrivacyScheduleSheet(privacy: PrivacyDto, onDismiss: () -> Unit, quiet: Boolean = false, onSave: (JsonObject) -> Unit) {
    // The same sheet sets the privacy schedule (spec 112) and smart quiet (115).
    val current = if (quiet) privacy.quiet?.schedule else privacy.privacyMode?.schedule
    val key = if (quiet) "quiet_schedule" else "privacy_schedule"
    var groupsOnly by remember { mutableStateOf(privacy.quiet?.scope == "groups") }
    var from by remember { mutableStateOf(current?.from ?: "22:00") }
    var to by remember { mutableStateOf(current?.to ?: "07:00") }
    val days = remember { mutableStateListOf<Int>().apply { addAll(current?.days ?: (0..6).toList()) } }
    var picking by remember { mutableStateOf<Boolean?>(null) } // true = from

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(stringResource(if (quiet) R.string.ch_quiet else R.string.ch_privacy_schedule), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(if (quiet) R.string.ch_quiet_sub else R.string.ch_privacy_schedule_sub), color = Ch.Mut, fontSize = 13.sp)
            Spacer(Modifier.height(14.dp))
            SheetSection(Icons.Rounded.AccessTime, stringResource(R.string.ch_schedule_time))
            Row(verticalAlignment = Alignment.CenterVertically) {
                TimeBox(stringResource(R.string.ch_from), from, Modifier.weight(1f)) { picking = true }
                Spacer(Modifier.width(10.dp))
                TimeBox(stringResource(R.string.ch_to), to, Modifier.weight(1f)) { picking = false }
            }
            Spacer(Modifier.height(16.dp))
            SheetSection(Icons.Rounded.DateRange, stringResource(R.string.ch_weekdays))
            Text(stringResource(R.string.ch_weekdays_pick), color = Ch.Mut, fontSize = 12.sp, modifier = Modifier.padding(bottom = 8.dp))
            val locale = com.dorr.app.network.AppLocale.java()
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                (0..6).chunked(3).forEach { pair ->
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        pair.forEach { d ->
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
                        repeat(3 - pair.size) { Spacer(Modifier.weight(1f)) }
                    }
                }
            }
            if (quiet) {
                Spacer(Modifier.height(8.dp))
                PrivacySettingsRow(
                    Icons.Rounded.Bedtime,
                    stringResource(R.string.ch_quiet_groups_only),
                    last = true,
                    checked = groupsOnly,
                    onChecked = { groupsOnly = it },
                )
            }
            Spacer(Modifier.height(18.dp))
            ChPrimaryButton(stringResource(R.string.ch_save), modifier = Modifier.fillMaxWidth(), enabled = days.isNotEmpty()) {
                onSave(JsonObject().apply {
                    if (quiet) addProperty("quiet_scope", if (groupsOnly) "groups" else "all")
                    add(key, JsonObject().apply {
                        addProperty("from", from)
                        addProperty("to", to)
                        add("days", JsonArray().apply { days.sorted().forEach { add(it) } })
                        addProperty("timezone", ZoneId.systemDefault().id)
                    })
                })
                onDismiss()
            }
            if (current != null) {
                Spacer(Modifier.height(6.dp))
                Text(
                    stringResource(R.string.ch_privacy_schedule_off), color = Ch.Danger, fontWeight = FontWeight.Bold,
                    textAlign = androidx.compose.ui.text.style.TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable {
                        onSave(JsonObject().apply { add(key, JsonNull.INSTANCE) })
                        onDismiss()
                    }.padding(12.dp),
                )
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
private fun SheetSection(icon: ImageVector, title: String) {
    Row(Modifier.padding(bottom = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(8.dp))
        Text(title, color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp)
    }
}

@Composable
private fun TimeBox(label: String, time: String, modifier: Modifier, onClick: () -> Unit) {
    Row(
        modifier.clip(RoundedCornerShape(12.dp)).background(Ch.SurfaceMuted).clickable(onClick = onClick).padding(horizontal = 10.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, color = Ch.Mut, fontSize = 13.sp, fontWeight = FontWeight.Bold)
        Spacer(Modifier.width(6.dp))
        Icon(Icons.Rounded.AccessTime, null, tint = Ch.Red, modifier = Modifier.size(16.dp))
        Text(" : ", color = Ch.Mut, fontSize = 13.sp, fontWeight = FontWeight.Bold)
        Text(time, color = Ch.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
    }
}

/** "While you were private: 5 messages in 2 chats" (spec 113). */
@Composable
internal fun PrivacySummaryDialog(summary: PrivacySummaryDto, onDismiss: () -> Unit) {
    val locale = com.dorr.app.ui.locale.rememberAppLocaleFrame()
    AlertDialog(
        onDismissRequest = onDismiss,
        containerColor = Ch.Surface,
        icon = { locale.Provide { Icon(Icons.Rounded.Shield, null, tint = Ch.Red) } },
        title = { locale.Provide { Text(stringResource(R.string.ch_privacy_summary_title), color = Ch.Ink, fontWeight = FontWeight.ExtraBold) } },
        text = {
            locale.Provide {
                Text(
                    if (summary.messages == 0) stringResource(R.string.ch_privacy_summary_none)
                    else stringResource(R.string.ch_privacy_summary, summary.messages, summary.conversations),
                    color = Ch.Mut,
                )
            }
        },
        confirmButton = { locale.Provide { TextButton(onDismiss) { Text(stringResource(R.string.ch_ok), color = Ch.Red, fontWeight = FontWeight.Bold) } } },
    )
}

// =============================================================================== sensitive

/** A sensitive message before it's unlocked: no content, just "tap to view" (spec 107–108). */
@Composable
internal fun SensitiveCard(mine: Boolean, onReveal: () -> Unit, footer: @Composable () -> Unit) {
    val ink = if (mine) Ch.OutText else Ch.InText
    Column(Modifier.width(230.dp).clickable(onClick = onReveal).padding(horizontal = 12.dp, vertical = 10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(34.dp).clip(CircleShape).background(ink.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Lock, null, tint = ink, modifier = Modifier.size(18.dp))
            }
            Spacer(Modifier.width(10.dp))
            Column {
                Text(stringResource(R.string.ch_sensitive), color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp)
                Text(stringResource(R.string.ch_sensitive_tap), color = ink.copy(alpha = 0.7f), fontSize = 12.sp)
            }
        }
        Box(Modifier.fillMaxWidth().padding(top = 4.dp), contentAlignment = Alignment.CenterEnd) { footer() }
    }
}
