package com.dorr.app.ui.screens.rides

import android.content.Intent
import android.net.Uri
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberModalBottomSheetState
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
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.RideDto
import com.dorr.app.network.SafetyCenterDto
import com.dorr.app.network.SafetyCheckDto
import com.dorr.app.network.TrustedContactDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.util.UUID

/*
 * The Safety Center (R-219..230, wireflow 16 and ج1): SOS, trusted contacts (with when each gets the
 * ride's link), the ride code always / at night, a check-in after arriving, the seatbelt reminder,
 * Smart Ride Check, favourite and blocked captains — plus the in-ride pieces: the "is this your
 * car?" card, the seatbelt banner, sharing with contacts, reporting and "Are you OK?".
 */

private val SosRed = Color(0xFFA32D2D)

@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun SafetyCenterPage(state: RidesState, onClose: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var data by remember { mutableStateOf<SafetyCenterDto?>(null) }
    var editing by remember { mutableStateOf<TrustedContactDto?>(null) }
    var adding by remember { mutableStateOf(false) }
    var sos by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    suspend fun load() { data = runCatching { ApiClient.rides.safety(ridesAuth()).data }.getOrNull() }
    LaunchedEffect(Unit) { load() }
    fun savePrefs(body: JsonObject) {
        scope.launch {
            runCatching { ApiClient.rides.savePreferences(ridesAuth(), body).data }
                .onSuccess { p -> if (p != null) data = data?.copy(preferences = p) }
                .onFailure { error = it.apiFailure().message }
        }
    }

    Column(Modifier.fillMaxSize().background(settingsBackground()).statusBarsPadding()) {
        Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            RoundButton(Icons.AutoMirrored.Rounded.ArrowBack, onClick = onClose)
            Spacer(Modifier.width(10.dp))
            Text(stringResource(R.string.rd_safety_center), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 20.sp)
        }
        val d = data
        if (d == null) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { androidx.compose.material3.CircularProgressIndicator(color = settingsAccent()) }
            return@Column
        }
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 16.dp).padding(bottom = 24.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            // The hero: "Your safety comes first" and the SOS button.
            Column(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp)).background(Brush.linearGradient(listOf(AppColors.brandNavy, AppColors.brandNavy.copy(alpha = 0.82f)))).padding(18.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Text(stringResource(R.string.rd_safety_first), color = Color.White.copy(alpha = 0.85f), fontSize = 13.sp)
                Spacer(Modifier.height(10.dp))
                val ring by animateFloatAsState(1f, spring(dampingRatio = Spring.DampingRatioMediumBouncy), label = "sos")
                Box(
                    Modifier.size(84.dp).scale(ring).clip(CircleShape).background(SosRed).border(6.dp, SosRed.copy(alpha = 0.3f), CircleShape)
                        .clickable {
                            if (state.ride != null) sos = true
                            else context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:${d.emergencyNumber}")))
                        },
                    contentAlignment = Alignment.Center,
                ) { Text("SOS", color = Color.White, fontWeight = FontWeight.Black, fontSize = 22.sp) }
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.rd_safety_sos_hint, d.emergencyNumber), color = Color.White, fontSize = 12.sp, textAlign = TextAlign.Center)
            }

            // 👥 Trusted contacts (R-220, R-221).
            SafetyCard("👥", stringResource(R.string.rd_safety_contacts), stringResource(R.string.rd_safety_contacts_hint)) {
                d.contacts.forEach { c ->
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { editing = c }.padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(36.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                            Text(c.name.take(1), color = settingsAccent(), fontWeight = FontWeight.Bold)
                        }
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(c.name, color = settingsInk(), fontWeight = FontWeight.SemiBold)
                            Text(c.phone, color = settingsMut(), fontSize = 12.sp)
                        }
                        Pill(shareModeLabel(c.shareMode))
                    }
                }
                if (d.contacts.size < d.maxContacts) {
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { adding = true }.padding(vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.Add, null, tint = settingsAccent())
                        Spacer(Modifier.width(8.dp))
                        Text(stringResource(R.string.rd_safety_add_contact), color = settingsAccent(), fontWeight = FontWeight.SemiBold)
                    }
                }
            }

            // 🔐 The ride code (R-229).
            SafetyCard("🔐", stringResource(R.string.rd_safety_pin), stringResource(R.string.rd_safety_pin_hint)) {
                ChoiceRow(listOf("policy" to stringResource(R.string.rd_safety_pin_policy), "always" to stringResource(R.string.rd_safety_always), "night" to stringResource(R.string.rd_safety_night)), d.preferences.pinMode) {
                    data = d.copy(preferences = d.preferences.copy(pinMode = it))
                    savePrefs(JsonObject().apply { addProperty("pin_mode", it) })
                }
            }

            // ⏰ Check-in after arriving (R-223).
            SafetyCard("⏰", stringResource(R.string.rd_safety_checkin), stringResource(R.string.rd_safety_checkin_hint)) {
                val off = stringResource(R.string.rd_safety_off)
                ChoiceRow(listOf("0" to off, "10" to "10", "20" to "20", "30" to "30").map { (k, v) -> k to if (k == "0") v else stringResource(R.string.rd_safety_minutes, v) }, (d.preferences.checkinAfterMin ?: 0).toString()) {
                    val minutes = it.toInt()
                    data = d.copy(preferences = d.preferences.copy(checkinAfterMin = minutes.takeIf { m -> m > 0 }))
                    savePrefs(JsonObject().apply { if (minutes > 0) addProperty("checkin_after_min", minutes) else add("checkin_after_min", com.google.gson.JsonNull.INSTANCE) })
                }
            }

            // 🪢 Seatbelt (R-230) and 🛡️ Smart Ride Check (R-222 — always on).
            SafetyCard("🪢", stringResource(R.string.rd_safety_seatbelt), stringResource(R.string.rd_safety_seatbelt_hint), trailing = {
                Switch(
                    checked = d.preferences.seatbeltReminder,
                    onCheckedChange = { on -> data = d.copy(preferences = d.preferences.copy(seatbeltReminder = on)); savePrefs(JsonObject().apply { addProperty("seatbelt_reminder", on) }) },
                    colors = SwitchDefaults.colors(checkedTrackColor = settingsAccent()),
                )
            })
            SafetyCard("🛡️", stringResource(R.string.rd_safety_ride_check), stringResource(R.string.rd_safety_ride_check_hint))

            // ⭐ Favourite captains (R-226) and 🚫 blocked ones (R-225).
            if (d.favorites.isNotEmpty()) SafetyCard("⭐", stringResource(R.string.rd_safety_favorites), null) {
                d.favorites.forEach { f ->
                    PersonRow(f.name.orEmpty(), f.rating?.let { String.format("%.1f ★", it) }) {
                        scope.launch { runCatching { ApiClient.rides.unfavorite(ridesAuth(), f.driverId) }; load() }
                    }
                }
            }
            if (d.blocked.isNotEmpty()) SafetyCard("🚫", stringResource(R.string.rd_safety_blocked), stringResource(R.string.rd_safety_blocked_hint)) {
                d.blocked.forEach { b ->
                    PersonRow(b.name.orEmpty(), null) { scope.launch { runCatching { ApiClient.rides.unblock(ridesAuth(), b.id) }; load() } }
                }
            }
            error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp) }
        }
    }

    if (adding || editing != null) ContactSheet(editing, onClose = { adding = false; editing = null }, onSaved = { scope.launch { load() } })
    if (sos) state.ride?.let { SosSheet(it, onClose = { sos = false }) }
}

@Composable
private fun SafetyCard(emoji: String, title: String, hint: String?, trailing: (@Composable () -> Unit)? = null, content: (@Composable () -> Unit)? = null) {
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsCard()).padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(settingsBackground()), contentAlignment = Alignment.Center) { Text(emoji, fontSize = 18.sp) }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(title, color = settingsInk(), fontWeight = FontWeight.Bold)
                hint?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
            }
            trailing?.invoke()
        }
        if (content != null) {
            Spacer(Modifier.height(8.dp))
            content()
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ChoiceRow(options: List<Pair<String, String>>, selected: String, onPick: (String) -> Unit) {
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        options.forEach { (key, label) ->
            val on = key == selected
            val bg by animateColorAsState(if (on) settingsAccent() else settingsBackground(), tween(200), label = "choice")
            Text(
                label, color = if (on) Color.White else settingsInk(), fontSize = 13.sp, fontWeight = if (on) FontWeight.Bold else FontWeight.Normal,
                modifier = Modifier.clip(CircleShape).background(bg).clickable { onPick(key) }.padding(horizontal = 14.dp, vertical = 8.dp),
            )
        }
    }
}

@Composable
private fun Pill(text: String) {
    Text(text, color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)).padding(horizontal = 10.dp, vertical = 4.dp))
}

@Composable
private fun PersonRow(name: String, sub: String?, onRemove: () -> Unit) {
    Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(34.dp).clip(CircleShape).background(settingsBackground()), contentAlignment = Alignment.Center) { Text(name.take(1), color = settingsInk(), fontWeight = FontWeight.Bold) }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(name, color = settingsInk(), fontWeight = FontWeight.SemiBold)
            sub?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
        }
        Icon(Icons.Rounded.Close, null, tint = settingsMut(), modifier = Modifier.size(20.dp).clip(CircleShape).clickable(onClick = onRemove))
    }
}

@Composable
private fun shareModeLabel(mode: String): String = when (mode) {
    "all" -> stringResource(R.string.rd_safety_every_ride)
    "night" -> stringResource(R.string.rd_safety_night)
    else -> stringResource(R.string.rd_safety_manual)
}

/** Add or edit a trusted contact: name, phone, when they get the link, "arrived safely". */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ContactSheet(contact: TrustedContactDto?, onClose: () -> Unit, onSaved: () -> Unit) {
    val scope = rememberCoroutineScope()
    var name by remember { mutableStateOf(contact?.name.orEmpty()) }
    var phone by remember { mutableStateOf(contact?.phone.orEmpty()) }
    var mode by remember { mutableStateOf(contact?.shareMode ?: "all") }
    var arrival by remember { mutableStateOf(contact?.notifyArrival ?: true) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun save() {
        busy = true
        error = null
        scope.launch {
            val body = JsonObject().apply {
                addProperty("name", name.trim())
                addProperty("phone", phone.trim())
                addProperty("share_mode", mode)
                addProperty("notify_arrival", arrival)
            }
            runCatching { if (contact == null) ApiClient.rides.addContact(ridesAuth(), body) else ApiClient.rides.updateContact(ridesAuth(), contact.id, body) }
                .onSuccess { onSaved(); onClose() }
                .onFailure { error = it.apiFailure().message }
            busy = false
        }
    }

    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = settingsCard()) {
        Column(Modifier.padding(horizontal = 20.dp).padding(bottom = 28.dp).imePadding(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(stringResource(if (contact == null) R.string.rd_safety_add_contact else R.string.rd_safety_edit_contact), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
            Field(stringResource(R.string.rd_safety_name), name) { name = it.take(80) }
            Field(stringResource(R.string.rd_safety_phone), phone, KeyboardType.Phone) { phone = it.take(18) }
            Text(stringResource(R.string.rd_safety_when_share), color = settingsMut(), fontSize = 12.sp)
            ChoiceRow(listOf("all" to stringResource(R.string.rd_safety_every_ride), "night" to stringResource(R.string.rd_safety_night), "manual" to stringResource(R.string.rd_safety_manual)), mode) { mode = it }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(stringResource(R.string.rd_safety_notify_arrival), color = settingsInk(), modifier = Modifier.weight(1f))
                Switch(checked = arrival, onCheckedChange = { arrival = it }, colors = SwitchDefaults.colors(checkedTrackColor = settingsAccent()))
            }
            error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp) }
            AccentButton(stringResource(R.string.rd_safety_save), enabled = name.isNotBlank() && phone.length >= 8, busy = busy) { save() }
            if (contact != null) {
                Text(
                    stringResource(R.string.rd_safety_remove), color = AppColors.danger, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable {
                        scope.launch { runCatching { ApiClient.rides.deleteContact(ridesAuth(), contact.id) }; onSaved(); onClose() }
                    }.padding(10.dp),
                )
            }
        }
    }
}

@Composable
private fun Field(label: String, value: String, keyboard: KeyboardType = KeyboardType.Text, onChange: (String) -> Unit) {
    Column {
        Text(label, color = settingsMut(), fontSize = 12.sp)
        Spacer(Modifier.height(4.dp))
        Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsBackground()).padding(horizontal = 14.dp, vertical = 12.dp)) {
            BasicTextField(value, onChange, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = keyboard), textStyle = TextStyle(color = settingsInk(), fontSize = 16.sp), cursorBrush = SolidColor(settingsAccent()), modifier = Modifier.fillMaxWidth())
        }
    }
}

/** 📤 Share: to my trusted contacts (by name), or the link through any app (R-111, R-220). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ShareRideSheet(ride: RideDto, onClose: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var contacts by remember { mutableStateOf<List<TrustedContactDto>?>(null) }
    val picked = remember { mutableStateOf(setOf<Int>()) }
    var message by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        contacts = runCatching { ApiClient.rides.safety(ridesAuth()).data?.contacts.orEmpty() }.getOrDefault(emptyList())
        picked.value = contacts.orEmpty().map { it.id }.toSet()
    }
    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = settingsCard()) {
        Column(Modifier.padding(horizontal = 20.dp).padding(bottom = 28.dp)) {
            Text(stringResource(R.string.rd_share_trip), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
            Spacer(Modifier.height(10.dp))
            val list = contacts
            if (!list.isNullOrEmpty()) {
                Text(stringResource(R.string.rd_safety_contacts), color = settingsMut(), fontSize = 12.sp)
                list.forEach { c ->
                    val on = c.id in picked.value
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { picked.value = if (on) picked.value - c.id else picked.value + c.id }.padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(22.dp).clip(RoundedCornerShape(6.dp)).background(if (on) settingsAccent() else Color.Transparent).border(2.dp, if (on) settingsAccent() else settingsMut(), RoundedCornerShape(6.dp)), contentAlignment = Alignment.Center) {
                            if (on) Text("✓", color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                        }
                        Spacer(Modifier.width(10.dp))
                        Text(c.name, color = settingsInk(), modifier = Modifier.weight(1f))
                        Text(c.phone, color = settingsMut(), fontSize = 12.sp)
                    }
                }
                Spacer(Modifier.height(8.dp))
                AccentButton(stringResource(R.string.rd_safety_send_contacts), enabled = picked.value.isNotEmpty(), busy = busy) {
                    busy = true
                    scope.launch {
                        val body = JsonObject().apply { add("ids", JsonArray().apply { picked.value.forEach { add(it) } }) }
                        runCatching { ApiClient.rides.shareWithContacts(ridesAuth(), ride.uuid, body) }
                            .onSuccess { message = it.message }
                            .onFailure { message = it.apiFailure().message }
                        busy = false
                    }
                }
                Spacer(Modifier.height(10.dp))
            } else if (list != null) {
                Text(stringResource(R.string.rd_safety_no_contacts), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(bottom = 10.dp))
            }
            Row(
                Modifier.fillMaxWidth().height(50.dp).clip(RoundedCornerShape(16.dp)).border(BorderStroke(1.dp, settingsInk().copy(alpha = 0.15f)), RoundedCornerShape(16.dp))
                    .clickable { scope.launch { message = shareRide(context, ride) } },
                horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
            ) { Text(stringResource(R.string.rd_safety_share_link), color = settingsInk(), fontWeight = FontWeight.SemiBold) }
            message?.let { Text(it, color = AppColors.success, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp)) }
        }
    }
}

/** 🚩 Report a problem on the ride (R-117). */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
internal fun ReportSheet(ride: RideDto, onClose: () -> Unit) {
    val scope = rememberCoroutineScope()
    var type by remember { mutableStateOf<String?>(null) }
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var done by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val types = listOf(
        "unsafe_driving" to stringResource(R.string.rd_report_unsafe_driving),
        "abuse" to stringResource(R.string.rd_report_abuse),
        "harassment" to stringResource(R.string.rd_report_harassment),
        "vehicle_issue" to stringResource(R.string.rd_report_vehicle),
        "other" to stringResource(R.string.rd_report_other),
    )
    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = settingsCard()) {
        Column(Modifier.padding(horizontal = 20.dp).padding(bottom = 28.dp).imePadding(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(stringResource(R.string.rd_report_title), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
            if (done != null) {
                Text(done.orEmpty(), color = AppColors.success, fontWeight = FontWeight.SemiBold)
                AccentButton(stringResource(R.string.rd_done), onClick = onClose)
                return@Column
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                types.forEach { (key, label) ->
                    val on = key == type
                    Text(label, color = if (on) Color.White else settingsInk(), fontSize = 13.sp, modifier = Modifier.clip(CircleShape).background(if (on) SosRed else settingsBackground()).clickable { type = key }.padding(horizontal = 14.dp, vertical = 8.dp))
                }
            }
            Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsBackground()).padding(12.dp)) {
                if (note.isEmpty()) Text(stringResource(R.string.rd_report_note), color = settingsMut(), fontSize = 13.sp)
                BasicTextField(note, { note = it.take(500) }, textStyle = TextStyle(color = settingsInk(), fontSize = 14.sp), cursorBrush = SolidColor(settingsAccent()), modifier = Modifier.fillMaxWidth().height(70.dp))
            }
            error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp) }
            AccentButton(stringResource(R.string.rd_report_send), enabled = type != null, busy = busy, color = SosRed) {
                busy = true
                error = null
                scope.launch {
                    val body = JsonObject().apply { addProperty("type", type); if (note.isNotBlank()) addProperty("note", note.trim()) }
                    runCatching { ApiClient.rides.report(ridesAuth(), ride.uuid, body) }
                        .onSuccess { done = it.message }
                        .onFailure { error = it.apiFailure().message }
                    busy = false
                }
            }
        }
    }
}

/** "Are you OK?" — a long stop, a detour, or the check-in after arriving (R-222, R-223). */
@Composable
internal fun SafetyCheckDialog(rideUuid: String, check: SafetyCheckDto, onDone: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    fun answer(value: String) {
        busy = true
        scope.launch {
            val here = if (value == "help") currentLocation(context) else null
            val body = JsonObject().apply { addProperty("answer", value); here?.let { addProperty("lat", it.latitude); addProperty("lng", it.longitude) } }
            val number = runCatching { ApiClient.rides.answerCheck(ridesAuth(), rideUuid, check.uuid, body).data?.emergencyNumber }.getOrNull()
            busy = false
            onDone()
            if (value == "help" && number != null) context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$number")))
        }
    }
    AlertDialog(
        onDismissRequest = {},
        icon = { Icon(Icons.Rounded.Shield, null, tint = SosRed, modifier = Modifier.size(36.dp)) },
        title = { Text(stringResource(if (check.kind == "checkin") R.string.rd_check_arrived else R.string.rd_check_ok), fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center) },
        text = {
            Text(
                stringResource(when (check.reason) { "long_stop" -> R.string.rd_check_long_stop; "deviation" -> R.string.rd_check_deviation; else -> R.string.rd_check_checkin }),
                textAlign = TextAlign.Center,
            )
        },
        confirmButton = {
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                AccentButton(stringResource(R.string.rd_check_im_ok), busy = busy, color = AppColors.success) { answer("ok") }
                AccentButton(stringResource(R.string.rd_check_help), color = SosRed) { answer("help") }
            }
        },
    )
}

/** Before getting in (R-224): the photo, the car, its colour and plate — and "this isn't the car". */
@Composable
internal fun VehicleCheckCard(ride: RideDto, onMismatch: () -> Unit) {
    var confirm by remember { mutableStateOf(false) }
    Row(
        Modifier.fillMaxWidth().padding(top = 10.dp).clip(RoundedCornerShape(14.dp)).background(settingsBackground()).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text("🔍", fontSize = 18.sp)
        Spacer(Modifier.width(8.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.rd_vehicle_check), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp)
            Text(stringResource(R.string.rd_vehicle_check_hint), color = settingsMut(), fontSize = 11.sp)
        }
        Text(stringResource(R.string.rd_vehicle_not_it), color = AppColors.danger, fontWeight = FontWeight.Bold, fontSize = 12.sp, modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable { confirm = true }.padding(6.dp))
    }
    if (confirm) AlertDialog(
        onDismissRequest = { confirm = false },
        title = { Text(stringResource(R.string.rd_vehicle_not_it)) },
        text = { Text(stringResource(R.string.rd_vehicle_not_it_body)) },
        confirmButton = { TextButton(onClick = { confirm = false; onMismatch() }) { Text(stringResource(R.string.rd_vehicle_cancel_free), color = AppColors.danger) } },
        dismissButton = { TextButton(onClick = { confirm = false }) { Text(stringResource(R.string.rd_keep)) } },
    )
}

internal suspend fun reportVehicleMismatch(state: RidesState) {
    val ride = state.ride ?: return
    runCatching { ApiClient.rides.vehicleMismatch(ridesAuth(), UUID.randomUUID().toString(), ride.uuid, JsonObject()) }
    runCatching { ApiClient.rides.ride(ridesAuth(), ride.uuid).data }.getOrNull()?.let { state.ride = it }
}

/** 🪢 Fasten your seatbelt — once, as the ride starts (R-230). */
@Composable
internal fun SeatbeltBanner(rideUuid: String) {
    var shown by remember(rideUuid) { mutableStateOf(true) }
    AnimatedVisibility(visible = shown, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
        Row(
            Modifier.fillMaxWidth().padding(top = 10.dp).clip(RoundedCornerShape(14.dp)).background(AppColors.brandOrange.copy(alpha = 0.12f)).clickable { shown = false }.padding(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text("🪢", fontSize = 18.sp)
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.rd_seatbelt), color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 13.sp, modifier = Modifier.weight(1f))
            Icon(Icons.Rounded.Close, null, tint = settingsMut(), modifier = Modifier.size(18.dp))
        }
    }
}

/** After the ride: keep this captain as a favourite (R-226), or never be matched again (R-225). */
@Composable
internal fun AfterRideChoices(ride: RideDto) {
    val scope = rememberCoroutineScope()
    var favorite by remember { mutableStateOf(ride.favoriteDriver == true) }
    var blocked by remember { mutableStateOf(false) }
    Row(Modifier.fillMaxWidth().padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Row(
            Modifier.weight(1f).clip(RoundedCornerShape(12.dp)).background(if (favorite) AppColors.warning.copy(alpha = 0.15f) else settingsBackground())
                .clickable(enabled = !blocked) {
                    favorite = !favorite
                    scope.launch { runCatching { ApiClient.rides.favorite(ridesAuth(), ride.uuid, JsonObject().apply { addProperty("on", favorite) }) } }
                }.padding(10.dp),
            horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.Star, null, tint = if (favorite) AppColors.warning else settingsMut(), modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(6.dp))
            Text(stringResource(if (favorite) R.string.rd_favorite_on else R.string.rd_favorite), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        }
        Row(
            Modifier.weight(1f).clip(RoundedCornerShape(12.dp)).background(if (blocked) AppColors.danger.copy(alpha = 0.12f) else settingsBackground())
                .clickable(enabled = !blocked) {
                    blocked = true
                    favorite = false
                    scope.launch { runCatching { ApiClient.rides.blockDriver(ridesAuth(), ride.uuid, JsonObject()) } }
                }.padding(10.dp),
            horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
        ) {
            Text("🚫", fontSize = 14.sp)
            Spacer(Modifier.width(6.dp))
            Text(stringResource(if (blocked) R.string.rd_blocked_done else R.string.rd_block_driver), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        }
    }
}
