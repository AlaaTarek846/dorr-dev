package com.dorr.freelance.ui.screens

import com.dorr.freelance.ui.components.SkeletonList
import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Computer
import androidx.compose.material.icons.rounded.Devices
import androidx.compose.material.icons.rounded.Logout
import androidx.compose.material.icons.rounded.PhoneAndroid
import androidx.compose.material.icons.rounded.PhoneIphone
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.VerifiedUser
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.R
import com.dorr.freelance.net.SecurityDeviceDto
import com.dorr.freelance.net.SecurityEventDto
import com.dorr.freelance.net.SupportClient
import com.dorr.freelance.net.apiFailure
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.ConfirmDialog
import com.dorr.freelance.ui.kit.PinkBackdrop
import com.dorr.freelance.ui.kit.PinkIcon
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.SubHeader
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsCard
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface
import kotlinx.coroutines.launch
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

private val WarningRed = Color(0xFFD93A3A)
private val SafeGreen = Color(0xFF1F9D55)

/**
 * Settings → Security (spec items 28–29): the devices this account is signed in on — sign any other one out, or all of
 * them at once — and the account's security log (sign-ins, wrong codes, devices signed out, wallet PIN locks), warnings
 * marked in red.
 */
@Composable
fun SecurityScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    val devices = remember { mutableStateListOf<SecurityDeviceDto>() }
    val events = remember { mutableStateListOf<SecurityEventDto>() }
    var loadingDevices by remember { mutableStateOf(true) }
    var loadingEvents by remember { mutableStateOf(true) }
    var failed by remember { mutableStateOf(false) }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var warningsOnly by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var confirmDevice by remember { mutableStateOf<SecurityDeviceDto?>(null) }
    var confirmOthers by remember { mutableStateOf(false) }

    val failedText = stringResource(R.string.security_load_failed)
    val signedOutText = stringResource(R.string.security_signed_out)

    suspend fun loadDevices() {
        loadingDevices = true
        runCatching { SupportClient.securityDevices().data.orEmpty() }
            .onSuccess { devices.clear(); devices.addAll(it); failed = false }
            .onFailure { failed = true }
        loadingDevices = false
    }

    suspend fun loadEvents(next: Int) {
        loadingEvents = true
        runCatching { SupportClient.securityEvents(next, warningsOnly) }
            .onSuccess { res ->
                if (next == 1) events.clear()
                events.addAll(res.data.orEmpty())
                page = next
                hasMore = res.pagination?.hasMorePages == true
            }
            .onFailure { if (next == 1) failed = true }
        loadingEvents = false
    }

    val tick = collectReconnectTick()
    LaunchedEffect(tick) { loadDevices() }
    LaunchedEffect(tick, warningsOnly) { loadEvents(1) }

    fun signOut(device: SecurityDeviceDto) {
        busy = true
        scope.launch {
            runCatching { SupportClient.signOutDevice(device.id) }
                .onSuccess { res ->
                    devices.clear(); devices.addAll(res.data?.devices.orEmpty())
                    Toast.makeText(context, res.message.ifBlank { signedOutText }, Toast.LENGTH_SHORT).show()
                    loadEvents(1)
                }
                .onFailure { Toast.makeText(context, it.apiFailure().message ?: failedText, Toast.LENGTH_LONG).show() }
            busy = false
            confirmDevice = null
        }
    }

    fun signOutOthers() {
        busy = true
        scope.launch {
            runCatching { SupportClient.signOutOtherDevices() }
                .onSuccess { res ->
                    devices.clear(); devices.addAll(res.data?.devices.orEmpty())
                    Toast.makeText(context, res.message, Toast.LENGTH_SHORT).show()
                    loadEvents(1)
                }
                .onFailure { Toast.makeText(context, it.apiFailure().message ?: failedText, Toast.LENGTH_LONG).show() }
            busy = false
            confirmOthers = false
        }
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(R.string.security_title), onBack)

            LazyColumn(
                modifier = Modifier.fillMaxSize(),
                contentPadding = PaddingValues(start = 14.dp, end = 14.dp, top = 6.dp, bottom = 24.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                // ---------------------------------------------------------------- devices
                item { SectionTitle(stringResource(R.string.security_devices), Icons.Rounded.Devices) }

                if (loadingDevices && devices.isEmpty()) {
                    item { Loading() }
                } else if (failed && devices.isEmpty()) {
                    item { Hint(failedText) }
                } else {
                    item {
                        Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp), 6.dp)) {
                            devices.forEachIndexed { index, device ->
                                DeviceRow(device, enabled = !busy) { confirmDevice = device }
                                if (index != devices.lastIndex) Divider()
                            }
                        }
                    }
                    if (devices.count { !it.current } > 0) {
                        item {
                            SettingsPrimaryButton(
                                text = stringResource(R.string.security_sign_out_others),
                                loading = busy && confirmOthers,
                                enabled = !busy,
                                onClick = { confirmOthers = true },
                            )
                        }
                    }
                }

                // ---------------------------------------------------------------- security log
                item {
                    Row(Modifier.fillMaxWidth().padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.weight(1f)) { SectionTitle(stringResource(R.string.security_log), Icons.Rounded.Shield) }
                        FilterChip(stringResource(R.string.security_all), selected = !warningsOnly) { warningsOnly = false }
                        Spacer(Modifier.width(6.dp))
                        FilterChip(stringResource(R.string.security_warnings_only), selected = warningsOnly) { warningsOnly = true }
                    }
                }

                if (loadingEvents && events.isEmpty()) {
                    item { Loading() }
                } else if (events.isEmpty()) {
                    item { Hint(stringResource(R.string.security_log_empty)) }
                } else {
                    items(events, key = { it.id }) { event -> EventRow(event) }
                    if (hasMore) {
                        item {
                            Box(Modifier.fillMaxWidth().padding(top = 4.dp), contentAlignment = Alignment.Center) {
                                if (loadingEvents) {
                                    CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
                                } else {
                                    Text(
                                        stringResource(R.string.security_load_more),
                                        color = settingsAccent(),
                                        fontWeight = FontWeight.Bold,
                                        fontSize = 13.sp,
                                        modifier = Modifier
                                            .clip(RoundedCornerShape(12.dp))
                                            .clickable { scope.launch { loadEvents(page + 1) } }
                                            .padding(horizontal = 16.dp, vertical = 8.dp),
                                    )
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    confirmDevice?.let { device ->
        ConfirmDialog(
            icon = Icons.Rounded.Logout,
            title = stringResource(R.string.security_sign_out),
            confirmLabel = stringResource(R.string.security_sign_out),
            message = stringResource(R.string.security_sign_out_confirm, device.deviceName.orEmpty()),
            loading = busy,
            onConfirm = { signOut(device) },
            onDismiss = { if (!busy) confirmDevice = null },
        )
    }
    if (confirmOthers) {
        ConfirmDialog(
            icon = Icons.Rounded.Logout,
            title = stringResource(R.string.security_sign_out_others),
            confirmLabel = stringResource(R.string.security_sign_out),
            message = stringResource(R.string.security_sign_out_others_confirm),
            loading = busy,
            onConfirm = { signOutOthers() },
            onDismiss = { if (!busy) confirmOthers = false },
        )
    }
}

@Composable
private fun DeviceRow(device: SecurityDeviceDto, enabled: Boolean, onSignOut: () -> Unit) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        PinkIcon(platformIcon(device.platform))
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    device.deviceName.orEmpty(),
                    fontSize = 13.5.sp,
                    fontWeight = FontWeight.Bold,
                    color = settingsInk(),
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f, fill = false),
                )
                if (device.current) {
                    Spacer(Modifier.width(6.dp))
                    Text(
                        stringResource(R.string.security_this_device),
                        fontSize = 10.sp,
                        fontWeight = FontWeight.Bold,
                        color = SafeGreen,
                        modifier = Modifier
                            .clip(RoundedCornerShape(8.dp))
                            .background(SafeGreen.copy(alpha = 0.12f))
                            .padding(horizontal = 6.dp, vertical = 2.dp),
                    )
                }
            }
            Text(
                listOfNotNull(device.platformLabel, device.appVersion?.let { "v$it" }, device.countryCode).joinToString(" · "),
                fontSize = 11.sp,
                color = settingsMut(),
                modifier = Modifier.padding(top = 2.dp),
            )
            Text(
                stringResource(R.string.security_last_active, formatWhen(device.lastActiveAt)),
                fontSize = 11.sp,
                color = settingsMut(),
            )
        }
        if (!device.current) {
            Text(
                stringResource(R.string.security_sign_out),
                fontSize = 12.sp,
                fontWeight = FontWeight.Bold,
                color = WarningRed,
                modifier = Modifier
                    .clip(RoundedCornerShape(10.dp))
                    .clickable(enabled = enabled, onClick = onSignOut)
                    .border(1.dp, WarningRed.copy(alpha = 0.35f), RoundedCornerShape(10.dp))
                    .padding(horizontal = 10.dp, vertical = 6.dp),
            )
        }
    }
}

@Composable
private fun EventRow(event: SecurityEventDto) {
    val tint = if (event.warning) WarningRed else SafeGreen
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .settingsSurface(RoundedCornerShape(14.dp), 3.dp)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier.size(34.dp).clip(RoundedCornerShape(10.dp)).background(tint.copy(alpha = 0.12f)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                if (event.warning) Icons.Rounded.Warning else Icons.Rounded.VerifiedUser,
                contentDescription = null,
                tint = tint,
                modifier = Modifier.size(18.dp),
            )
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(event.label, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = if (event.warning) tint else settingsInk())
            event.description?.let { Text(it, fontSize = 11.sp, color = settingsMut()) }
            Text(
                listOfNotNull(event.deviceName, event.ip, event.countryCode).joinToString(" · "),
                fontSize = 10.5.sp,
                color = settingsMut(),
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }
        Spacer(Modifier.width(8.dp))
        Text(formatWhen(event.createdAt), fontSize = 10.5.sp, color = settingsMut())
    }
}

@Composable
private fun SectionTitle(text: String, icon: ImageVector) {
    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(horizontal = 4.dp)) {
        Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(6.dp))
        Text(text, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, color = settingsInk())
    }
}

@Composable
private fun FilterChip(text: String, selected: Boolean, onClick: () -> Unit) {
    Text(
        text,
        fontSize = 11.5.sp,
        fontWeight = FontWeight.Bold,
        color = if (selected) Color.White else settingsMut(),
        modifier = Modifier
            .clip(RoundedCornerShape(12.dp))
            .background(if (selected) settingsAccent() else settingsCard())
            .clickable(onClick = onClick)
            .padding(horizontal = 10.dp, vertical = 5.dp),
    )
}

@Composable
private fun Divider() {
    Box(Modifier.fillMaxWidth().height(1.dp).background(if (settingsNight()) AccountDark.line else Color(0xFFF6E4E8)))
}

@Composable
private fun Loading() {
    SkeletonList(count = 2)
}

@Composable
private fun Hint(text: String) {
    Text(text, fontSize = 12.sp, color = settingsMut(), modifier = Modifier.fillMaxWidth().padding(vertical = 18.dp, horizontal = 8.dp))
}

private fun platformIcon(platform: String?): ImageVector = when (platform) {
    "android" -> Icons.Rounded.PhoneAndroid
    "ios" -> Icons.Rounded.PhoneIphone
    "web" -> Icons.Rounded.Computer
    else -> Icons.Rounded.Devices
}

/** "12/10 14:05" in the phone's own time zone. */
private fun formatWhen(iso: String?): String = iso?.let {
    runCatching {
        OffsetDateTime.parse(it).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("d/M HH:mm"))
    }.getOrDefault(it)
} ?: "—"
