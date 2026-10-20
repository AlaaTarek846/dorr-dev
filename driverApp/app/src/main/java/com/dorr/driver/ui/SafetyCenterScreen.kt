package com.dorr.driver.ui

import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
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
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Shield
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Switch
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.foundation.text.KeyboardOptions
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.ui.kit.DorrToggle
import com.dorr.driver.ui.kit.ScreenScaffold
import com.dorr.driver.ui.kit.bounceClick
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.DorrBrand
import com.dorr.driver.ui.kit.DorrField
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.DriverSafetyDto
import com.dorr.driver.net.SafetyCheckDto
import com.dorr.driver.net.TrustedContactDto
import com.dorr.driver.net.TripDto
import com.google.gson.JsonArray
import com.google.gson.JsonObject
import kotlinx.coroutines.launch

/*
 * The captain's safety (D-140..160, D-381, D-396, D-410): the Safety Center (SOS, trusted contacts
 * and when they get the trip's link, riders they won't be matched with), reporting a problem — an
 * accident comes back with what to do now (D-155) — "Are you OK?" during the trip, the seatbelt
 * banner, and "don't match me with this rider again".
 */

private val SosRed = Color(0xFFA32D2D)

@Composable
fun SafetyCenterScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var data by remember { mutableStateOf<DriverSafetyDto?>(null) }
    var editing by remember { mutableStateOf<TrustedContactDto?>(null) }
    var adding by remember { mutableStateOf(false) }
    BackHandler(onBack = onBack)
    suspend fun load() { (apiCall { DriverBackend.api.safety() } as? ApiResult.Ok)?.let { data = it.data } }
    LaunchedEffect(Unit) { load() }

    ScreenScaffold(stringResource(R.string.sc_title), onBack) {
        // The SOS button first: one tap to the emergency number.
        Column(
            Modifier.padding(horizontal = 16.dp).fillMaxWidth().surface(RoundedCornerShape(24.dp), elevation = 8.dp).padding(18.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(stringResource(R.string.sc_first), color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.7f), fontSize = 13.sp)
            Spacer(Modifier.height(10.dp))
            val number = data?.emergencyNumber ?: "911"
            Box(
                Modifier.size(84.dp).bounceClick { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$number"))) }
                    .clip(CircleShape).background(SosRed).border(6.dp, SosRed.copy(alpha = 0.35f), CircleShape),
                contentAlignment = Alignment.Center,
            ) { Text("SOS", color = Color.White, fontWeight = FontWeight.Black, fontSize = 22.sp) }
            Spacer(Modifier.height(6.dp))
            Text(stringResource(R.string.trip_sos_call, number), color = MaterialTheme.colorScheme.onSurface, fontSize = 12.sp)
        }
        val d = data ?: run {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = DorrBrand.orange) }
            return@ScreenScaffold
        }
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Card {
                Text("👥  " + stringResource(R.string.sc_contacts), fontWeight = FontWeight.Bold, fontSize = 16.sp)
                Text(stringResource(R.string.sc_contacts_hint), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                Spacer(Modifier.height(8.dp))
                d.contacts.forEach { c ->
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { editing = c }.padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(38.dp).clip(CircleShape).background(DorrBrand.orange.copy(alpha = 0.15f)), contentAlignment = Alignment.Center) {
                            Text(c.name.take(1), color = DorrBrand.orange, fontWeight = FontWeight.Bold)
                        }
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(c.name, fontWeight = FontWeight.SemiBold)
                            Text(c.phone, fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
                        }
                        Text(modeLabel(c.shareMode), fontSize = 11.sp, color = DorrBrand.navy, modifier = Modifier.clip(CircleShape).background(DorrBrand.navy.copy(alpha = 0.08f)).padding(horizontal = 10.dp, vertical = 4.dp))
                    }
                }
                if (d.contacts.size < d.maxContacts) {
                    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { adding = true }.padding(vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Filled.Add, null, tint = DorrBrand.orange)
                        Spacer(Modifier.width(8.dp))
                        Text(stringResource(R.string.sc_add_contact), color = DorrBrand.orange, fontWeight = FontWeight.SemiBold)
                    }
                }
            }
            Card {
                Text("🛡️  " + stringResource(R.string.sc_ride_check), fontWeight = FontWeight.Bold, fontSize = 16.sp)
                Text(stringResource(R.string.sc_ride_check_hint), fontSize = 12.sp, color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.6f))
            }
            if (d.blocked.isNotEmpty()) Card {
                Text("🚫  " + stringResource(R.string.sc_blocked), fontWeight = FontWeight.Bold, fontSize = 16.sp)
                d.blocked.forEach { b ->
                    Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(b.name.orEmpty(), fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                        Icon(Icons.Filled.Close, null, modifier = Modifier.size(20.dp).clip(CircleShape).clickable { scope.launch { apiCall { DriverBackend.api.unblock(b.id) }; load() } })
                    }
                }
            }
        }
    }
    if (adding || editing != null) ContactSheet(editing, onClose = { adding = false; editing = null }, onSaved = { scope.launch { load() } })
}

@Composable
private fun modeLabel(mode: String): String = stringResource(
    when (mode) { "all" -> R.string.sc_every_trip; "night" -> R.string.sc_night; else -> R.string.sc_manual },
)

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
private fun ContactSheet(contact: TrustedContactDto?, onClose: () -> Unit, onSaved: () -> Unit) {
    val scope = rememberCoroutineScope()
    var name by remember { mutableStateOf(contact?.name.orEmpty()) }
    var phone by remember { mutableStateOf(contact?.phone.orEmpty()) }
    var mode by remember { mutableStateOf(contact?.shareMode ?: "all") }
    var arrival by remember { mutableStateOf(contact?.notifyArrival ?: true) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(Modifier.padding(horizontal = 20.dp).padding(bottom = 28.dp).imePadding(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(stringResource(if (contact == null) R.string.sc_add_contact else R.string.sc_edit_contact), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
            DorrField(name, { name = it.take(80) }, label = { Text(stringResource(R.string.sc_name)) }, singleLine = true, modifier = Modifier.fillMaxWidth())
            DorrField(phone, { phone = it.take(18) }, label = { Text(stringResource(R.string.sc_phone)) }, singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone), modifier = Modifier.fillMaxWidth())
            Text(stringResource(R.string.sc_when), fontSize = 12.sp)
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("all", "night", "manual").forEach { key ->
                    val on = key == mode
                    val bg by animateColorAsState(if (on) DorrBrand.navy else DorrBrand.navy.copy(alpha = 0.06f), tween(200), label = "mode")
                    Text(modeLabel(key), color = if (on) Color.White else DorrBrand.navy, modifier = Modifier.clip(CircleShape).background(bg).clickable { mode = key }.padding(horizontal = 14.dp, vertical = 8.dp))
                }
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(stringResource(R.string.sc_arrival), modifier = Modifier.weight(1f))
                DorrToggle(arrival) { arrival = it }
            }
            error?.let { Text(it, color = MaterialTheme.colorScheme.error, fontSize = 13.sp) }
            BigButton(stringResource(R.string.ob_save), color = DorrBrand.navy, enabled = name.isNotBlank() && phone.length >= 8, busy = busy) {
                busy = true
                scope.launch {
                    val body = JsonObject().apply { addProperty("name", name.trim()); addProperty("phone", phone.trim()); addProperty("share_mode", mode); addProperty("notify_arrival", arrival) }
                    when (val r = apiCall { if (contact == null) DriverBackend.api.addContact(body) else DriverBackend.api.updateContact(contact.id, body) }) {
                        is ApiResult.Ok -> { onSaved(); onClose() }
                        is ApiResult.Failed -> error = r.error.displayMessage
                        is ApiResult.Offline -> error = null
                    }
                    busy = false
                }
            }
            if (contact != null) {
                Text(
                    stringResource(R.string.sc_remove), color = DorrBrand.danger, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(10.dp)).clickable { scope.launch { apiCall { DriverBackend.api.deleteContact(contact.id) }; onSaved(); onClose() } }.padding(10.dp),
                )
            }
        }
    }
}

/** 🚩 Report a problem on the trip (D-124, D-130, D-152..154); an accident shows what to do now (D-155). */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
internal fun DriverReportSheet(t: TripDto, onClose: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var type by remember { mutableStateOf<String?>(null) }
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var sent by remember { mutableStateOf(false) }
    var guidance by remember { mutableStateOf<List<String>>(emptyList()) }
    val types = listOf(
        "rider_mismatch" to R.string.rp_rider_mismatch, "abuse" to R.string.rp_abuse, "threat" to R.string.rp_threat,
        "accident" to R.string.rp_accident, "road_hazard" to R.string.rp_hazard, "other" to R.string.rp_other,
    )
    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(Modifier.padding(horizontal = 20.dp).padding(bottom = 28.dp).imePadding(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(stringResource(R.string.rp_title), fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
            if (sent) {
                Text(stringResource(R.string.rp_sent), color = DorrBrand.success, fontWeight = FontWeight.SemiBold)
                if (guidance.isNotEmpty()) {
                    Text(stringResource(R.string.rp_after_accident), fontWeight = FontWeight.Bold)
                    guidance.forEachIndexed { i, step ->
                        Row(verticalAlignment = Alignment.Top) {
                            Box(Modifier.size(24.dp).clip(CircleShape).background(DorrBrand.orange), contentAlignment = Alignment.Center) { Text("${i + 1}", color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold) }
                            Spacer(Modifier.width(10.dp))
                            Text(step, fontSize = 14.sp)
                        }
                    }
                }
                BigButton(stringResource(R.string.sc_done), color = DorrBrand.navy, onClick = onClose)
                return@Column
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                types.forEach { (key, label) ->
                    val on = key == type
                    Text(stringResource(label), color = if (on) Color.White else MaterialTheme.colorScheme.onSurface, fontSize = 14.sp,
                        modifier = Modifier.clip(CircleShape).background(if (on) SosRed else MaterialTheme.colorScheme.onSurface.copy(alpha = 0.07f)).clickable { type = key }.padding(horizontal = 14.dp, vertical = 9.dp))
                }
            }
            DorrField(note, { note = it.take(500) }, label = { Text(stringResource(R.string.rp_note)) }, modifier = Modifier.fillMaxWidth())
            error?.let { Text(it, color = MaterialTheme.colorScheme.error, fontSize = 13.sp) }
            BigButton(stringResource(R.string.rp_send), color = SosRed, enabled = type != null, busy = busy) {
                busy = true
                scope.launch {
                    val here = lastPosition(context)
                    val body = JsonObject().apply {
                        addProperty("type", type)
                        if (note.isNotBlank()) addProperty("note", note.trim())
                        here?.let { addProperty("lat", it.first); addProperty("lng", it.second) }
                    }
                    when (val r = apiCall { DriverBackend.api.report(t.uuid, body) }) {
                        is ApiResult.Ok -> {
                            sent = true
                            guidance = r.data.takeIf { it.isJsonObject }?.asJsonObject?.get("guidance")?.takeIf { it.isJsonArray }?.asJsonArray?.map { it.asString }.orEmpty()
                        }
                        is ApiResult.Failed -> error = r.error.displayMessage
                        is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                    }
                    busy = false
                }
            }
        }
    }
}

/** "Are you OK?" during the trip (D-144, D-396): few big buttons, safe while driving. */
@Composable
internal fun DriverSafetyCheckDialog(t: TripDto, check: SafetyCheckDto, onDone: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    fun answer(value: String) {
        busy = true
        scope.launch {
            val here = if (value == "help") lastPosition(context) else null
            val body = JsonObject().apply { addProperty("answer", value); here?.let { addProperty("lat", it.first); addProperty("lng", it.second) } }
            val r = apiCall { DriverBackend.api.answerCheck(t.uuid, check.uuid, body) }
            busy = false
            onDone()
            val number = (r as? ApiResult.Ok)?.data?.emergencyNumber
            if (value == "help" && number != null) context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$number")))
        }
    }
    AlertDialog(
        onDismissRequest = {},
        icon = { Icon(Icons.Filled.Shield, null, tint = SosRed, modifier = Modifier.size(40.dp)) },
        title = { Text(stringResource(R.string.ck_title), fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center, fontSize = 22.sp) },
        text = { Text(stringResource(if (check.reason == "deviation") R.string.ck_deviation else R.string.ck_long_stop), textAlign = TextAlign.Center) },
        confirmButton = {
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                BigButton(stringResource(R.string.ck_ok), color = DorrBrand.success, busy = busy) { answer("ok") }
                BigButton(stringResource(R.string.ck_help), color = SosRed) { answer("help") }
            }
        },
    )
}

/** 🪢 Fasten your seatbelt — as the trip starts (D-157). */
@Composable
internal fun DriverSeatbeltBanner(uuid: String) {
    var shown by remember(uuid) { mutableStateOf(true) }
    AnimatedVisibility(visible = shown, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(DorrBrand.orange.copy(alpha = 0.14f)).clickable { shown = false }.padding(14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text("🪢", fontSize = 20.sp)
            Spacer(Modifier.width(10.dp))
            Text(stringResource(R.string.sb_text), fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
            Icon(Icons.Filled.Close, null, modifier = Modifier.size(18.dp))
        }
    }
}

/** 🚫 After the trip: don't match me with this rider again (D-151, D-187, D-410). */
@Composable
internal fun BlockRiderButton(t: TripDto) {
    val scope = rememberCoroutineScope()
    var confirm by remember { mutableStateOf(false) }
    var done by remember { mutableStateOf(false) }
    Text(
        stringResource(if (done) R.string.bl_done else R.string.bl_button), color = if (done) DorrBrand.success else DorrBrand.danger,
        fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center,
        modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable(enabled = !done) { confirm = true }.padding(10.dp),
    )
    if (confirm) AlertDialog(
        onDismissRequest = { confirm = false },
        title = { Text(stringResource(R.string.bl_button)) },
        text = { Text(stringResource(R.string.bl_body)) },
        confirmButton = { TextButton(onClick = { confirm = false; scope.launch { if (apiCall { DriverBackend.api.blockRider(t.uuid, JsonObject()) } is ApiResult.Ok) done = true } }) { Text(stringResource(R.string.bl_confirm), color = DorrBrand.danger) } },
        dismissButton = { TextButton(onClick = { confirm = false }) { Text(stringResource(R.string.sch_keep)) } },
    )
}

/** "Send my trip to my trusted contacts" from the SOS sheet. */
internal suspend fun shareTripWithContacts(uuid: String): String? =
    when (val r = apiCall { DriverBackend.api.shareWithContacts(uuid, JsonObject().apply { add("ids", JsonArray()) }) }) {
        is ApiResult.Ok -> null
        is ApiResult.Failed -> r.error.displayMessage
        is ApiResult.Offline -> null
    }
