package com.dorr.app.ui.screens.chat

import android.graphics.BitmapFactory
import android.util.Base64
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
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
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.ChatBubble
import androidx.compose.material.icons.rounded.CloudDownload
import androidx.compose.material.icons.rounded.DataUsage
import androidx.compose.material.icons.rounded.DeleteSweep
import androidx.compose.material.icons.rounded.Photo
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.SdCard
import androidx.compose.material.icons.rounded.SdStorage
import androidx.compose.material.icons.rounded.SignalCellularAlt
import androidx.compose.material.icons.rounded.Speed
import androidx.compose.material.icons.rounded.TrendingUp
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.blur
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.ConfirmDialog
import com.dorr.app.ui.screens.profile.PinkIcon
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.profile.settingsSurface
import com.dorr.app.chat.ChatDataSaver
import com.dorr.app.chat.ChatLite
import com.dorr.app.chat.ChatUsage
import com.dorr.app.network.AttachmentDto
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale

// ------------------------------------------------------------------ a photo held back by data saver (spec 82)

/** The server's tiny preview, softened, with the photo's size on a download button. */
@Composable
internal fun HeldPhoto(file: AttachmentDto?, modifier: Modifier) {
    val bitmap = remember(file?.preview) {
        file?.preview?.let { runCatching { Base64.decode(it, Base64.DEFAULT).let { b -> BitmapFactory.decodeByteArray(b, 0, b.size)?.asImageBitmap() } }.getOrNull() }
    }
    Box(modifier.background(Ch.SurfaceMuted), contentAlignment = Alignment.Center) {
        if (bitmap != null) {
            Image(
                bitmap, null, contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxSize().then(if (android.os.Build.VERSION.SDK_INT >= 31 && !ChatLite.active) Modifier.blur(10.dp) else Modifier),
            )
        }
        Row(
            Modifier.clip(CircleShape).background(Color.Black.copy(alpha = 0.55f)).padding(horizontal = 12.dp, vertical = 7.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.CloudDownload, null, tint = Color.White, modifier = Modifier.size(18.dp))
            file?.size?.let {
                Spacer(Modifier.width(6.dp))
                Text(usageBytes(it), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}

// ------------------------------------------------------------------ data and performance (spec 82, 87)

/** Data saver, lite mode, and the way to the usage stats. */
@Composable
fun DataPerformancePage() {
    val host = LocalChat.current
    val context = LocalContext.current
    var saverPicker by remember { mutableStateOf(false) }
    var litePicker by remember { mutableStateOf(false) }
    val saverChoices = listOf("off" to R.string.pf_off, "mobile" to R.string.pf_saver_mobile, "always" to R.string.pf_saver_always)
    val liteChoices = listOf("auto" to R.string.pf_auto, "on" to R.string.pf_on, "off" to R.string.pf_off)
    val saverStatus = stringResource(if (ChatDataSaver.metered) R.string.pf_net_mobile else R.string.pf_net_wifi) + " · " +
        stringResource(if (ChatDataSaver.active) R.string.pf_saving_now else R.string.pf_not_saving)
    val liteStatus = stringResource(R.string.pf_phone_memory, String.format(Locale.US, "%.1f", ChatLite.ramGb)) + " · " +
        stringResource(if (ChatLite.active) R.string.pf_lite_on_now else R.string.pf_lite_off_now)

    ChPage(stringResource(R.string.pf_title), onBack = { host.pop() }, settingsHeader = true) {
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(start = 14.dp, end = 14.dp, top = 6.dp, bottom = 16.dp)) {
            Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp), 8.dp).padding(horizontal = 8.dp, vertical = 4.dp)) {
                PrivacySettingsRow(
                    Icons.Rounded.DataUsage,
                    stringResource(R.string.pf_saver),
                    subtitle = stringResource(R.string.pf_saver_sub) + "\n" + saverStatus,
                    value = stringResource(when (ChatDataSaver.mode) {
                        "mobile" -> R.string.pf_saver_mobile
                        "always" -> R.string.pf_saver_always
                        else -> R.string.pf_off
                    }),
                ) { saverPicker = true }
                PrivacySettingsRow(
                    Icons.Rounded.Speed,
                    stringResource(R.string.pf_lite),
                    subtitle = stringResource(R.string.pf_lite_sub) + "\n" + liteStatus,
                    value = stringResource(when (ChatLite.mode) {
                        "on" -> R.string.pf_on
                        "off" -> R.string.pf_off
                        else -> R.string.pf_auto
                    }),
                ) { litePicker = true }
                PrivacySettingsRow(
                    Icons.Rounded.DeleteSweep,
                    stringResource(R.string.bk_storage),
                    subtitle = stringResource(R.string.bk_storage_sub),
                ) { host.push(ChRoute.Storage()) }
                PrivacySettingsRow(
                    Icons.Rounded.SdStorage,
                    stringResource(R.string.us_title),
                    subtitle = stringResource(R.string.us_link_sub),
                    last = true,
                ) { host.push(ChRoute.Usage) }
            }
        }
    }
    if (saverPicker) {
        ChoiceSheet(
            title = stringResource(R.string.pf_saver),
            subtitle = stringResource(R.string.pf_saver_sub),
            options = saverChoices.map { (key, label) -> stringResource(label) to { ChatDataSaver.setMode(context, key); Unit } },
            radios = true,
            selected = saverChoices.indexOfFirst { it.first == ChatDataSaver.mode }.takeIf { it >= 0 } ?: 0,
            onDismiss = { saverPicker = false },
        )
    }
    if (litePicker) {
        ChoiceSheet(
            title = stringResource(R.string.pf_lite),
            subtitle = stringResource(R.string.pf_lite_sub),
            options = liteChoices.map { (key, label) -> stringResource(label) to { ChatLite.setMode(context, key); Unit } },
            radios = true,
            selected = liteChoices.indexOfFirst { it.first == ChatLite.mode }.takeIf { it >= 0 } ?: 0,
            onDismiss = { litePicker = false },
        )
    }
}

// ------------------------------------------------------------------ usage stats (spec 83)

/** What chats used on this phone since a date: messages, data (mobile / Wi-Fi), calls, storage. */
@Composable
fun UsagePage() {
    val host = LocalChat.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var stats by remember { mutableStateOf(ChatUsage.snapshot()) }
    var outBytes by remember { mutableLongStateOf(0L) }
    var imageBytes by remember { mutableLongStateOf(0L) }
    var confirmReset by remember { mutableStateOf(false) }
    var confirmClear by remember { mutableStateOf(false) }
    var refresh by remember { mutableStateOf(0) }
    val cleared = stringResource(R.string.us_cleared)
    val resetDone = stringResource(R.string.us_reset_done)

    LaunchedEffect(Unit) {
        ChatUsage.seedDemoIfEmpty()
        stats = ChatUsage.snapshot()
    }
    LaunchedEffect(refresh) {
        withContext(Dispatchers.IO) {
            outBytes = dirSize(File(context.cacheDir, "chat-out"))
            imageBytes = runCatching { chatImages(context).diskCache?.size ?: 0L }.getOrDefault(0L)
        }
    }

    fun v(key: String) = stats[key] ?: 0L
    fun data(kind: String, mobile: Boolean) = v(ChatUsage.dataKey(kind, true, mobile)) + v(ChatUsage.dataKey(kind, false, mobile))

    if (confirmClear) {
        ConfirmDialog(
            icon = Icons.Rounded.DeleteSweep,
            title = stringResource(R.string.us_clear_title),
            message = stringResource(R.string.us_clear_sub),
            confirmLabel = stringResource(R.string.us_clear),
            onConfirm = {
                confirmClear = false
                scope.launch {
                    withContext(Dispatchers.IO) {
                        val cutoff = System.currentTimeMillis() - 10 * 60_000L
                        File(context.cacheDir, "chat-out").listFiles()?.filter { it.lastModified() < cutoff }?.forEach { it.deleteRecursively() }
                        runCatching { chatImages(context).diskCache?.clear() }
                        runCatching { chatImages(context).memoryCache?.clear() }
                    }
                    host.showToast(cleared)
                    refresh++
                }
            },
            onDismiss = { confirmClear = false },
        )
    }
    if (confirmReset) {
        ConfirmDialog(
            icon = Icons.Rounded.Refresh,
            title = stringResource(R.string.us_reset_title),
            message = stringResource(R.string.us_reset_sub),
            confirmLabel = stringResource(R.string.us_reset),
            onConfirm = {
                confirmReset = false
                ChatUsage.reset()
                stats = ChatUsage.snapshot()
                host.showToast(resetDone)
            },
            onDismiss = { confirmReset = false },
        )
    }

    ChPage(stringResource(R.string.us_title), onBack = { host.pop() }, settingsHeader = true) {
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
            // ------------------------------------------------ the total, mobile against Wi-Fi
            val mobile = data("msg", true) + data("media", true) + v(ChatUsage.callDataKey(true))
            val wifi = data("msg", false) + data("media", false) + v(ChatUsage.callDataKey(false))
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(24.dp)).background(Brush.linearGradient(listOf(Ch.Red, Ch.RedDeep))).padding(horizontal = 14.dp, vertical = 12.dp)) {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
                    Column(Modifier.weight(1f).padding(end = 10.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Box(Modifier.width(3.dp).height(14.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.9f)))
                            Spacer(Modifier.width(7.dp))
                            Text(stringResource(R.string.us_total), color = Color.White.copy(alpha = 0.88f), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
                        }
                        Text(usageBytes(mobile + wifi), color = Color.White, fontSize = 26.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 4.dp))
                        v(ChatUsage.SINCE).takeIf { it > 0 }?.let {
                            Row(Modifier.padding(top = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                                Icon(Icons.Rounded.CalendarMonth, null, tint = Color.White.copy(alpha = 0.8f), modifier = Modifier.size(13.dp))
                                Spacer(Modifier.width(4.dp))
                                Text(stringResource(R.string.us_since, sinceDate(it)), color = Color.White.copy(alpha = 0.8f), fontSize = 11.sp)
                            }
                        }
                    }
                    Box(
                        Modifier.size(48.dp).clip(RoundedCornerShape(16.dp)).background(Color.White.copy(alpha = 0.14f)).border(1.dp, Color.White.copy(alpha = 0.35f), RoundedCornerShape(16.dp)),
                        contentAlignment = Alignment.Center,
                    ) {
                        Icon(Icons.Rounded.SdCard, null, tint = Color.White, modifier = Modifier.size(24.dp))
                    }
                }
                Spacer(Modifier.height(10.dp))
                SplitBar(mobile, wifi)
                Spacer(Modifier.height(10.dp))
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    UsageNetPart(Icons.Rounded.SignalCellularAlt, stringResource(R.string.pf_net_mobile), usageBytes(mobile), Modifier.weight(1f), iconAtStart = true)
                    Box(Modifier.width(1.dp).height(22.dp).background(Color.White.copy(alpha = 0.28f)))
                    UsageNetPart(Icons.Rounded.TrendingUp, stringResource(R.string.pf_net_wifi), usageBytes(wifi), Modifier.weight(1f), iconAtStart = false)
                }
            }

            // ------------------------------------------------ messages
            UsageBlock(Icons.Rounded.ChatBubble, stringResource(R.string.us_messages)) {
                UsageStatRow(stringResource(R.string.us_sent), v(ChatUsage.SENT).toString())
                UsageStatRow(stringResource(R.string.us_received), v(ChatUsage.RECEIVED).toString())
                UsageStatRow(stringResource(R.string.us_bytes_sent), usageBytes(v(ChatUsage.dataKey("msg", true, true)) + v(ChatUsage.dataKey("msg", true, false))))
                UsageStatRow(stringResource(R.string.us_bytes_received), usageBytes(v(ChatUsage.dataKey("msg", false, true)) + v(ChatUsage.dataKey("msg", false, false))))
                UsageStatRow(stringResource(R.string.pf_net_mobile), usageBytes(data("msg", true)))
                UsageStatRow(stringResource(R.string.pf_net_wifi), usageBytes(data("msg", false)), last = true)
            }

            // ------------------------------------------------ media
            val up = v(ChatUsage.dataKey("media", true, true)) + v(ChatUsage.dataKey("media", true, false))
            val down = v(ChatUsage.dataKey("media", false, true)) + v(ChatUsage.dataKey("media", false, false))
            UsageBlock(Icons.Rounded.Photo, stringResource(R.string.us_media)) {
                UsageStatRow(stringResource(R.string.us_uploaded), usageBytes(up))
                UsageStatRow(stringResource(R.string.us_downloaded), usageBytes(down))
                UsageStatRow(stringResource(R.string.pf_net_mobile), usageBytes(data("media", true)))
                UsageStatRow(stringResource(R.string.pf_net_wifi), usageBytes(data("media", false)), last = true)
            }

            // ------------------------------------------------ calls
            val seconds = v(ChatUsage.CALL_SECONDS)
            UsageBlock(Icons.Rounded.Call, stringResource(R.string.us_calls)) {
                UsageStatRow(stringResource(R.string.us_calls_out), v(ChatUsage.CALLS_OUT).toString())
                UsageStatRow(stringResource(R.string.us_calls_in), v(ChatUsage.CALLS_IN).toString())
                UsageStatRow(stringResource(R.string.us_call_time), stringResource(R.string.us_hours_minutes, seconds / 3600, (seconds % 3600) / 60))
                UsageStatRow(stringResource(R.string.pf_net_mobile), usageBytes(v(ChatUsage.callDataKey(true))))
                UsageStatRow(stringResource(R.string.pf_net_wifi), usageBytes(v(ChatUsage.callDataKey(false))), last = true)
            }

            // ------------------------------------------------ storage on this phone
            UsageBlock(Icons.Rounded.SdStorage, stringResource(R.string.us_storage), stringResource(R.string.us_storage_sub)) {
                UsageStatRow(stringResource(R.string.us_storage_images), usageBytes(imageBytes))
                UsageStatRow(stringResource(R.string.us_storage_sent), usageBytes(outBytes), last = true)
            }
            ChPrimaryButton(
                stringResource(R.string.us_clear),
                Modifier.fillMaxWidth().padding(top = 14.dp),
                icon = Icons.Rounded.DeleteSweep,
            ) { confirmClear = true }
            ChPrimaryButton(
                stringResource(R.string.us_reset),
                Modifier.fillMaxWidth().padding(top = 10.dp),
                icon = Icons.Rounded.Refresh,
            ) { confirmReset = true }
            Text(stringResource(R.string.us_note), color = settingsMut(), fontSize = 11.5.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(top = 8.dp, bottom = 20.dp))
        }
    }
}

// ------------------------------------------------------------------ pieces

@Composable
private fun UsageBlock(icon: ImageVector, title: String, sub: String? = null, content: @Composable ColumnScope.() -> Unit) {
    Row(Modifier.padding(top = 18.dp, start = 4.dp, end = 4.dp, bottom = if (sub == null) 10.dp else 2.dp), verticalAlignment = Alignment.CenterVertically) {
        PinkIcon(icon)
        Spacer(Modifier.width(10.dp))
        Text(title, color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
    }
    if (sub != null) {
        Text(sub, color = settingsMut(), fontSize = 12.5.sp, modifier = Modifier.padding(start = 4.dp, end = 4.dp, top = 2.dp, bottom = 10.dp))
    }
    Column(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp), 8.dp).padding(horizontal = 8.dp, vertical = 4.dp), content = content)
}

@Composable
private fun UsageStatRow(label: String, value: String, last: Boolean = false) {
    val dark = settingsNight()
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 4.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = settingsInk(), modifier = Modifier.weight(1f), maxLines = 1)
        Text(value, fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold, color = settingsInk())
    }
    if (!last) {
        Box(Modifier.fillMaxWidth().height(1.dp).background(if (dark) AccountDark.line else Color(0xFFF6E4E8)))
    }
}

@Composable
private fun UsageNetIcon(icon: ImageVector) {
    Box(
        Modifier.size(26.dp).border(1.dp, Color.White.copy(alpha = 0.4f), CircleShape),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, null, tint = Color.White, modifier = Modifier.size(13.dp))
    }
}

/** One half of the usage hero: circular icon on the outer edge, name and amount in one line. */
@Composable
private fun UsageNetPart(icon: ImageVector, label: String, value: String, modifier: Modifier, iconAtStart: Boolean) {
    Row(
        modifier.padding(horizontal = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = if (iconAtStart) Arrangement.Start else Arrangement.End,
    ) {
        UsageNetIcon(icon)
        Spacer(Modifier.width(6.dp))
        if (iconAtStart) {
            Text(label, color = Color.White.copy(alpha = 0.88f), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
            Spacer(Modifier.width(5.dp))
            Text(value, color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
        } else {
            Text(value, color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
            Spacer(Modifier.width(5.dp))
            Text(label, color = Color.White.copy(alpha = 0.88f), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
        }
    }
}

/** Mobile data against Wi-Fi, as one bar. */
@Composable
private fun SplitBar(mobile: Long, wifi: Long) {
    val total = (mobile + wifi).coerceAtLeast(1)
    val share = (mobile.toFloat() / total).coerceIn(0f, 1f)
    Box(Modifier.fillMaxWidth().height(5.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.22f))) {
        if (share > 0f) Box(Modifier.fillMaxWidth(share).fillMaxHeight().clip(CircleShape).background(Color.White.copy(alpha = 0.92f)))
    }
}

internal fun usageBytes(bytes: Long): String = when {
    bytes >= 1_073_741_824L -> String.format(Locale.US, "%.2f GB", bytes / 1_073_741_824.0)
    bytes >= 1_048_576L -> String.format(Locale.US, "%.1f MB", bytes / 1_048_576.0)
    bytes >= 1024L -> String.format(Locale.US, "%d KB", bytes / 1024)
    else -> "$bytes B"
}

private fun sinceDate(millis: Long): String =
    DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(com.dorr.app.network.AppLocale.java()).format(Instant.ofEpochMilli(millis).atZone(ZoneId.systemDefault()))

private fun dirSize(dir: File): Long = dir.walkTopDown().filter { it.isFile }.sumOf { it.length() }
