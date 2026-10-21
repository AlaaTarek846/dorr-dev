package com.dorr.driver.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.DeleteOutline
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.NotificationsActive
import androidx.compose.material.icons.rounded.PhoneAndroid
import androidx.compose.material.icons.rounded.PhonelinkLock
import androidx.compose.material.icons.rounded.TextFields
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.AlertsDto
import com.dorr.driver.net.DeviceDto
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.LegalDocDto
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.BannerKind
import com.dorr.driver.ui.kit.DorrField
import com.dorr.driver.ui.kit.DorrToggle
import com.dorr.driver.ui.kit.PrimaryButton
import com.dorr.driver.ui.kit.ScreenScaffold
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.DriverPrefs
import com.dorr.driver.ui.theme.Pal
import com.google.gson.JsonObject
import kotlinx.coroutines.launch

/**
 * The captain's settings (docs/rides_tasks.md D-304, D-307, D-313, D-314, D-316..D-318): how a request rings,
 * the text size, the phones trusted with the wallet, a new number, the terms in force, and closing the account.
 */
@Composable
fun SettingsScreen(onBack: () -> Unit, onClosed: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var alerts by remember { mutableStateOf<AlertsDto?>(null) }
    var devices by remember { mutableStateOf<List<DeviceDto>>(emptyList()) }
    var legal by remember { mutableStateOf<List<LegalDocDto>>(emptyList()) }
    var phoneOpen by remember { mutableStateOf(false) }
    var closeOpen by remember { mutableStateOf(false) }
    var reading by remember { mutableStateOf<LegalDocDto?>(null) }
    LaunchedEffect(Unit) {
        alerts = (apiCall { DriverBackend.api.alerts() } as? ApiResult.Ok)?.data
        devices = (apiCall { DriverBackend.api.devices() } as? ApiResult.Ok)?.data.orEmpty()
        legal = (apiCall { DriverBackend.api.legal() } as? ApiResult.Ok)?.data.orEmpty()
    }
    fun saveAlerts(build: JsonObject.() -> Unit) = scope.launch {
        (apiCall { DriverBackend.api.saveAlerts(JsonObject().apply(build)) } as? ApiResult.Ok)?.data?.let { alerts = it; AlertPrefs.save(context, it) }
    }

    ScreenScaffold(stringResource(R.string.st_title), onBack) {
        Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding(), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            // D-304: how a request rings.
            Section(Icons.Rounded.NotificationsActive, stringResource(R.string.st_alerts)) {
                alerts?.let { a ->
                    ToggleLine(stringResource(R.string.st_sound), a.sound) { on -> saveAlerts { addProperty("sound", on) } }
                    ToggleLine(stringResource(R.string.st_vibration), a.vibration) { on -> saveAlerts { addProperty("vibration", on) } }
                    ToggleLine(stringResource(R.string.st_voice), a.voice) { on -> saveAlerts { addProperty("voice", on) } }
                }
            }
            // D-307: the text size.
            Section(Icons.Rounded.TextFields, stringResource(R.string.st_text_size)) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    listOf(0.9f to R.string.st_small, 1f to R.string.st_normal, 1.15f to R.string.st_large, 1.3f to R.string.st_xlarge).forEach { (scale, label) ->
                        val on = kotlin.math.abs(DriverPrefs.fontScale - scale) < 0.01f
                        Text(stringResource(label), color = if (on) Color.White else Pal.ink, fontSize = (13 * scale).sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.clip(CircleShape).background(if (on) Pal.accent else Pal.bg).clickable { DriverPrefs.chooseFontScale(scale) }.padding(horizontal = 12.dp, vertical = 8.dp))
                    }
                }
            }
            // D-313: phones trusted with the wallet.
            Section(Icons.Rounded.PhonelinkLock, stringResource(R.string.st_devices)) {
                if (devices.isEmpty()) Text(stringResource(R.string.st_no_devices), color = Pal.mut, fontSize = 13.sp)
                devices.forEach { d ->
                    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.PhoneAndroid, null, tint = if (d.current) Pal.success else Pal.mut, modifier = Modifier.size(20.dp))
                        Spacer(Modifier.width(8.dp))
                        Column(Modifier.weight(1f)) {
                            Text(d.device + if (d.current) " · " + stringResource(R.string.st_this_phone) else "", color = Pal.ink, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                            Text(d.lastSeenAt?.take(16)?.replace('T', ' ').orEmpty(), color = Pal.mut, fontSize = 11.sp)
                        }
                        if (!d.current) Icon(Icons.Rounded.DeleteOutline, null, tint = Pal.danger, modifier = Modifier.size(22.dp).clickable {
                            scope.launch { devices = (apiCall { DriverBackend.api.forgetDevice(d.id) } as? ApiResult.Ok)?.data ?: devices }
                        })
                    }
                }
            }
            // D-314: a new number.
            Section(Icons.Rounded.PhoneAndroid, stringResource(R.string.st_phone)) {
                Text(stringResource(R.string.st_phone_hint), color = Pal.mut, fontSize = 12.5.sp)
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.st_phone_change), color = Pal.accent, fontWeight = FontWeight.Bold, modifier = Modifier.clickable { phoneOpen = true }.padding(vertical = 4.dp))
            }
            // D-317: the terms in force, their version and my acceptance.
            Section(Icons.Rounded.Gavel, stringResource(R.string.st_terms)) {
                legal.forEach { doc ->
                    Row(Modifier.fillMaxWidth().clickable { reading = doc }.padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(doc.title.orEmpty(), color = Pal.ink, fontWeight = FontWeight.SemiBold)
                            Text(stringResource(R.string.st_version, doc.updatedAt?.take(10).orEmpty()), color = Pal.mut, fontSize = 11.sp)
                        }
                        Text(stringResource(if (doc.needsAcceptance) R.string.st_needs_ok else R.string.st_accepted), color = if (doc.needsAcceptance) Pal.warning else Pal.success, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }
            // D-316: closing.
            Text(stringResource(R.string.st_close), color = Pal.danger, fontWeight = FontWeight.Bold,
                modifier = Modifier.align(Alignment.CenterHorizontally).clip(CircleShape).clickable { closeOpen = true }.padding(horizontal = 16.dp, vertical = 10.dp))
        }
    }

    if (phoneOpen) PhoneChangeDialog(onClose = { phoneOpen = false })
    if (closeOpen) CloseAccountDialog(onClose = { closeOpen = false }, onClosed = onClosed)
    reading?.let { doc -> LegalDialog(doc, onClose = { reading = null }, onAccepted = { legal = it; reading = null }) }
}

@Composable
private fun Section(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().surface(RoundedCornerShape(20.dp)).padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(icon, null, tint = Pal.accent, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(8.dp))
            Text(title, color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 15.sp)
        }
        Spacer(Modifier.height(10.dp))
        content()
    }
}

@Composable
private fun ToggleLine(label: String, on: Boolean, onChange: (Boolean) -> Unit) {
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = Pal.ink, fontSize = 14.sp, modifier = Modifier.weight(1f))
        DorrToggle(on, onChange)
    }
}

@Composable
private fun PhoneChangeDialog(onClose: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var phone by remember { mutableStateOf("") }
    var pin by remember { mutableStateOf("") }
    var code by remember { mutableStateOf("") }
    var sent by remember { mutableStateOf(false) }
    var done by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    AlertDialog(
        onDismissRequest = { if (!busy) onClose() },
        title = { Text(stringResource(R.string.st_phone_change), fontWeight = FontWeight.Bold) },
        text = {
            Column {
                if (done) Text(stringResource(R.string.st_phone_done))
                else {
                    DorrField(phone, { phone = it.filter(Char::isDigit).take(15) }, placeholder = { Text(stringResource(R.string.st_new_phone)) }, enabled = !sent)
                    Spacer(Modifier.height(6.dp))
                    if (!sent) DorrField(pin, { pin = it.filter(Char::isDigit).take(4) }, placeholder = { Text(stringResource(R.string.wl_pin)) })
                    else DorrField(code, { code = it.filter(Char::isDigit).take(6) }, placeholder = { Text(stringResource(R.string.wx_freeze_code)) })
                }
                error?.let { Text(it, color = Pal.danger, fontSize = 12.sp) }
            }
        },
        confirmButton = {
            if (done) TextButton(onClick = onClose) { Text(stringResource(R.string.wx_ok)) }
            else TextButton(enabled = !busy && phone.length >= 8 && (!sent || code.length >= 4), onClick = {
                busy = true; error = null
                scope.launch {
                    val r = if (!sent) apiCall { DriverBackend.api.startPhoneChange(pin.takeIf { it.length == 4 }, JsonObject().apply { addProperty("phone", phone) }) }
                    else apiCall { DriverBackend.api.confirmPhoneChange(JsonObject().apply { addProperty("code", code) }) }
                    when (r) {
                        is ApiResult.Ok -> if (!sent) sent = true else done = true
                        is ApiResult.Failed -> error = r.error.displayMessage
                        is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                    }
                    busy = false
                }
            }) { Text(stringResource(if (sent) R.string.wx_freeze_confirm_generic else R.string.wx_freeze_send)) }
        },
        dismissButton = { if (!done) TextButton(onClick = onClose) { Text(stringResource(R.string.cancel)) } },
    )
}

/** D-316, D-226: what blocks closing; then closing, for good. */
@Composable
private fun CloseAccountDialog(onClose: () -> Unit, onClosed: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var check by remember { mutableStateOf<com.dorr.driver.net.CloseCheckDto?>(null) }
    var reason by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) { check = (apiCall { DriverBackend.api.closeCheck() } as? ApiResult.Ok)?.data }
    AlertDialog(
        onDismissRequest = onClose,
        icon = { Icon(Icons.Rounded.Warning, null, tint = Pal.danger) },
        title = { Text(stringResource(R.string.st_close), fontWeight = FontWeight.Bold) },
        text = {
            Column {
                val c = check
                when {
                    c == null -> Text("…")
                    !c.canClose -> {
                        Text(stringResource(R.string.st_close_blocked), fontSize = 13.sp)
                        c.blockers.forEach { b -> Text("• " + b.label, color = Pal.danger, fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp)) }
                    }
                    else -> {
                        Text(stringResource(R.string.st_close_warn), fontSize = 13.sp)
                        Spacer(Modifier.height(8.dp))
                        DorrField(reason, { reason = it.take(300) }, placeholder = { Text(stringResource(R.string.st_close_reason)) })
                    }
                }
                error?.let { Text(it, color = Pal.danger, fontSize = 12.sp) }
            }
        },
        confirmButton = {
            if (check?.canClose == true) TextButton(enabled = reason.trim().length >= 3, onClick = {
                scope.launch {
                    when (val r = apiCall { DriverBackend.api.closeAccount(JsonObject().apply { addProperty("reason", reason.trim()); addProperty("confirm", true) }) }) {
                        is ApiResult.Ok -> onClosed()
                        is ApiResult.Failed -> error = r.error.displayMessage
                        is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                    }
                }
            }) { Text(stringResource(R.string.st_close_now), color = Pal.danger) }
        },
        dismissButton = { TextButton(onClick = onClose) { Text(stringResource(R.string.cancel)) } },
    )
}

/** D-317, D-318: a page in force, and accepting this version. */
@Composable
internal fun LegalDialog(doc: LegalDocDto, onClose: () -> Unit, onAccepted: (List<LegalDocDto>) -> Unit) {
    val scope = rememberCoroutineScope()
    AlertDialog(
        onDismissRequest = onClose,
        title = { Text(doc.title.orEmpty(), fontWeight = FontWeight.Bold) },
        text = {
            Column(Modifier.heightIn(max = 420.dp).verticalScroll(rememberScrollState())) {
                Text(stringResource(R.string.st_version, doc.updatedAt?.take(10).orEmpty()), color = Pal.mut, fontSize = 11.sp)
                Spacer(Modifier.height(6.dp))
                Text(android.text.Html.fromHtml(doc.content.orEmpty(), android.text.Html.FROM_HTML_MODE_COMPACT).toString(), fontSize = 13.sp)
            }
        },
        confirmButton = {
            if (doc.needsAcceptance) TextButton(onClick = {
                scope.launch { (apiCall { DriverBackend.api.acceptLegal(JsonObject().apply { add("types", com.google.gson.JsonArray().apply { add(doc.type) }) }) } as? ApiResult.Ok)?.data?.let(onAccepted) }
            }) { Text(stringResource(R.string.st_accept)) } else TextButton(onClick = onClose) { Text(stringResource(R.string.wx_ok)) }
        },
    )
}

/** D-318: on opening, newer terms than the ones accepted are shown first. */
@Composable
fun TermsGate() {
    var pending by remember { mutableStateOf<List<LegalDocDto>>(emptyList()) }
    LaunchedEffect(Unit) { pending = (apiCall { DriverBackend.api.legal() } as? ApiResult.Ok)?.data.orEmpty().filter { it.needsAcceptance } }
    pending.firstOrNull()?.let { doc ->
        LegalDialog(doc, onClose = { pending = pending.drop(1) }, onAccepted = { all -> pending = all.filter { it.needsAcceptance } })
    }
}

/** D-304: how a request rings — kept on the phone so the ring follows it at once. */
internal object AlertPrefs {
    private const val PREFS = "dorr_alerts"

    fun save(context: android.content.Context, a: AlertsDto) {
        context.getSharedPreferences(PREFS, android.content.Context.MODE_PRIVATE).edit()
            .putBoolean("sound", a.sound).putBoolean("vibration", a.vibration).putBoolean("voice", a.voice).apply()
    }

    fun sound(context: android.content.Context) = context.getSharedPreferences(PREFS, android.content.Context.MODE_PRIVATE).getBoolean("sound", true)

    fun vibration(context: android.content.Context) = context.getSharedPreferences(PREFS, android.content.Context.MODE_PRIVATE).getBoolean("vibration", true)
}
