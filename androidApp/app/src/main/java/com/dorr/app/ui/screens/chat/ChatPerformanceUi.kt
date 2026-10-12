package com.dorr.app.ui.screens.chat

import android.graphics.BitmapFactory
import android.util.Base64
import androidx.compose.animation.animateColorAsState
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
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
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.ChatBubble
import androidx.compose.material.icons.rounded.CloudDownload
import androidx.compose.material.icons.rounded.DataUsage
import androidx.compose.material.icons.rounded.DeleteSweep
import androidx.compose.material.icons.rounded.Photo
import androidx.compose.material.icons.rounded.SdStorage
import androidx.compose.material.icons.rounded.Speed
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import com.dorr.app.chat.ChatDataSaver
import com.dorr.app.chat.ChatLite
import com.dorr.app.chat.ChatUsage
import com.dorr.app.network.AttachmentDto
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
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
    ChPage(stringResource(R.string.pf_title), onBack = { host.pop() }) {
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
            // ------------------------------------------------ data saver (82)
            PerfSection(Icons.Rounded.DataUsage, stringResource(R.string.pf_saver), stringResource(R.string.pf_saver_sub)) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    listOf("off" to R.string.pf_off, "mobile" to R.string.pf_saver_mobile, "always" to R.string.pf_saver_always).forEach { (key, label) ->
                        PerfPill(stringResource(label), ChatDataSaver.mode == key, Modifier.weight(1f)) { ChatDataSaver.setMode(context, key) }
                    }
                }
                StatusLine(
                    stringResource(if (ChatDataSaver.metered) R.string.pf_net_mobile else R.string.pf_net_wifi) + " · " +
                        stringResource(if (ChatDataSaver.active) R.string.pf_saving_now else R.string.pf_not_saving),
                    ChatDataSaver.active,
                )
            }

            // ------------------------------------------------ lite mode (87)
            PerfSection(Icons.Rounded.Speed, stringResource(R.string.pf_lite), stringResource(R.string.pf_lite_sub)) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    listOf("auto" to R.string.pf_auto, "on" to R.string.pf_on, "off" to R.string.pf_off).forEach { (key, label) ->
                        PerfPill(stringResource(label), ChatLite.mode == key, Modifier.weight(1f)) { ChatLite.setMode(context, key) }
                    }
                }
                StatusLine(
                    stringResource(R.string.pf_phone_memory, String.format(Locale.US, "%.1f", ChatLite.ramGb)) + " · " +
                        stringResource(if (ChatLite.active) R.string.pf_lite_on_now else R.string.pf_lite_off_now),
                    ChatLite.active,
                )
            }

            // ------------------------------------------------ manage storage (backlog 46–48)
            Row(
                Modifier.fillMaxWidth().padding(top = 14.dp).clip(RoundedCornerShape(22.dp)).background(Ch.Surface)
                    .clickable { host.push(ChRoute.Storage()) }.padding(16.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                IconBadge(Icons.Rounded.DeleteSweep)
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.bk_storage), color = Ch.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.bk_storage_sub), color = Ch.Mut, fontSize = 12.5.sp)
                }
                Text("›", color = Ch.Soft, fontSize = 22.sp)
            }

            // ------------------------------------------------ usage stats (83)
            Row(
                Modifier.fillMaxWidth().padding(top = 14.dp).clip(RoundedCornerShape(22.dp)).background(Ch.Surface)
                    .clickable { host.push(ChRoute.Usage) }.padding(16.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                IconBadge(Icons.Rounded.SdStorage)
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.us_title), color = Ch.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.us_link_sub), color = Ch.Mut, fontSize = 12.5.sp)
                }
                Text("›", color = Ch.Soft, fontSize = 22.sp)
            }
        }
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
    var refresh by remember { mutableStateOf(0) }
    val cleared = stringResource(R.string.us_cleared)

    LaunchedEffect(Unit) {
        while (true) {
            stats = ChatUsage.snapshot()
            delay(2_000)
        }
    }
    LaunchedEffect(refresh) {
        withContext(Dispatchers.IO) {
            outBytes = dirSize(File(context.cacheDir, "chat-out"))
            imageBytes = runCatching { chatImages(context).diskCache?.size ?: 0L }.getOrDefault(0L)
        }
    }

    fun v(key: String) = stats[key] ?: 0L
    fun data(kind: String, mobile: Boolean) = v(ChatUsage.dataKey(kind, true, mobile)) + v(ChatUsage.dataKey(kind, false, mobile))

    if (confirmReset) {
        AlertDialog(
            onDismissRequest = { confirmReset = false },
            containerColor = Ch.Surface,
            title = { Text(stringResource(R.string.us_reset_title), color = Ch.Ink, fontWeight = FontWeight.ExtraBold) },
            text = { Text(stringResource(R.string.us_reset_sub), color = Ch.Mut) },
            confirmButton = {
                TextButton(onClick = { ChatUsage.reset(); stats = ChatUsage.snapshot(); confirmReset = false }) { Text(stringResource(R.string.us_reset), color = Ch.Red, fontWeight = FontWeight.Bold) }
            },
            dismissButton = { TextButton(onClick = { confirmReset = false }) { Text(stringResource(R.string.us_cancel), color = Ch.Mut) } },
        )
    }

    ChPage(stringResource(R.string.us_title), onBack = { host.pop() }) {
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp)) {
            // ------------------------------------------------ the total, mobile against Wi-Fi
            val mobile = data("msg", true) + data("media", true) + v(ChatUsage.callDataKey(true))
            val wifi = data("msg", false) + data("media", false) + v(ChatUsage.callDataKey(false))
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(26.dp)).background(Brush.linearGradient(listOf(Ch.Red, Ch.RedDeep))).padding(16.dp)) {
                Text(stringResource(R.string.us_total), color = Color.White.copy(alpha = 0.8f), fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                Text(usageBytes(mobile + wifi), color = Color.White, fontSize = 28.sp, fontWeight = FontWeight.ExtraBold)
                v(ChatUsage.SINCE).takeIf { it > 0 }?.let {
                    Text(stringResource(R.string.us_since, sinceDate(it)), color = Color.White.copy(alpha = 0.8f), fontSize = 12.sp)
                }
                Spacer(Modifier.height(12.dp))
                SplitBar(mobile, wifi)
                Row(Modifier.padding(top = 6.dp)) {
                    Text("● " + stringResource(R.string.pf_net_mobile) + " " + usageBytes(mobile), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                    Text("● " + stringResource(R.string.pf_net_wifi) + " " + usageBytes(wifi), color = Color.White.copy(alpha = 0.7f), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                }
            }

            // ------------------------------------------------ messages
            PerfSection(Icons.Rounded.ChatBubble, stringResource(R.string.us_messages), null) {
                StatRow(stringResource(R.string.us_sent), v(ChatUsage.SENT).toString())
                StatRow(stringResource(R.string.us_received), v(ChatUsage.RECEIVED).toString())
                StatRow(stringResource(R.string.us_data), usageBytes(data("msg", true) + data("msg", false)), splitOf(data("msg", true), data("msg", false)))
            }

            // ------------------------------------------------ media
            PerfSection(Icons.Rounded.Photo, stringResource(R.string.us_media), null) {
                val up = v(ChatUsage.dataKey("media", true, true)) + v(ChatUsage.dataKey("media", true, false))
                val down = v(ChatUsage.dataKey("media", false, true)) + v(ChatUsage.dataKey("media", false, false))
                StatRow(stringResource(R.string.us_uploaded), usageBytes(up))
                StatRow(stringResource(R.string.us_downloaded), usageBytes(down))
                StatRow(stringResource(R.string.us_data), usageBytes(up + down), splitOf(data("media", true), data("media", false)))
            }

            // ------------------------------------------------ calls
            PerfSection(Icons.Rounded.Call, stringResource(R.string.us_calls), null) {
                StatRow(stringResource(R.string.us_calls_out), v(ChatUsage.CALLS_OUT).toString())
                StatRow(stringResource(R.string.us_calls_in), v(ChatUsage.CALLS_IN).toString())
                val seconds = v(ChatUsage.CALL_SECONDS)
                StatRow(stringResource(R.string.us_call_time), stringResource(R.string.us_hours_minutes, seconds / 3600, (seconds % 3600) / 60))
                val cm = v(ChatUsage.callDataKey(true))
                val cw = v(ChatUsage.callDataKey(false))
                StatRow(stringResource(R.string.us_data), usageBytes(cm + cw), splitOf(cm, cw))
            }

            // ------------------------------------------------ storage on this phone
            PerfSection(Icons.Rounded.SdStorage, stringResource(R.string.us_storage), stringResource(R.string.us_storage_sub)) {
                StatRow(stringResource(R.string.us_storage_images), usageBytes(imageBytes))
                StatRow(stringResource(R.string.us_storage_sent), usageBytes(outBytes))
                Row(
                    Modifier.fillMaxWidth().padding(top = 10.dp).clip(RoundedCornerShape(14.dp)).background(Ch.SurfaceMuted)
                        .clickable {
                            scope.launch {
                                withContext(Dispatchers.IO) {
                                    // Files being sent right now are a few minutes old at most: kept.
                                    val cutoff = System.currentTimeMillis() - 10 * 60_000L
                                    File(context.cacheDir, "chat-out").listFiles()?.filter { it.lastModified() < cutoff }?.forEach { it.deleteRecursively() }
                                    runCatching { chatImages(context).diskCache?.clear() }
                                    runCatching { chatImages(context).memoryCache?.clear() }
                                }
                                host.showToast(cleared)
                                refresh++
                            }
                        }
                        .padding(12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.Center,
                ) {
                    Icon(Icons.Rounded.DeleteSweep, null, tint = Ch.Red, modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(R.string.us_clear), color = Ch.Red, fontSize = 14.sp, fontWeight = FontWeight.Bold)
                }
            }

            Text(
                stringResource(R.string.us_reset), color = Ch.Mut, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth().padding(top = 18.dp).clip(RoundedCornerShape(14.dp)).clickable { confirmReset = true }.padding(12.dp),
            )
            Text(stringResource(R.string.us_note), color = Ch.Soft, fontSize = 11.5.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(top = 4.dp, bottom = 20.dp))
        }
    }
}

// ------------------------------------------------------------------ pieces

@Composable
private fun PerfSection(icon: ImageVector, title: String, sub: String?, content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth().padding(top = 14.dp).clip(RoundedCornerShape(24.dp)).background(Ch.Surface).padding(16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconBadge(icon)
            Spacer(Modifier.width(10.dp))
            Text(title, color = Ch.Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
        }
        if (sub != null) Text(sub, color = Ch.Mut, fontSize = 12.5.sp, modifier = Modifier.padding(top = 6.dp, bottom = 10.dp))
        else Spacer(Modifier.height(8.dp))
        content()
    }
}

@Composable
private fun IconBadge(icon: ImageVector) {
    Box(Modifier.size(36.dp).clip(RoundedCornerShape(11.dp)).background(Ch.Red.copy(alpha = 0.08f)), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(19.dp))
    }
}

@Composable
private fun PerfPill(label: String, on: Boolean, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val bg by animateColorAsState(if (on) Ch.Red else Ch.SurfaceMuted, label = "perfPill")
    Text(
        label, color = if (on) Color.White else Ch.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, maxLines = 1,
        modifier = modifier.clip(RoundedCornerShape(14.dp)).background(bg).clickable(onClick = onClick).padding(vertical = 10.dp, horizontal = 4.dp),
    )
}

@Composable
private fun StatusLine(text: String, on: Boolean) {
    Row(Modifier.padding(top = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(8.dp).clip(CircleShape).background(if (on) Color(0xFF16A34A) else Ch.Soft))
        Spacer(Modifier.width(6.dp))
        Text(text, color = Ch.Mut, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun StatRow(label: String, value: String, sub: String? = null) {
    Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(label, color = Ch.Ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
            if (sub != null) Text(sub, color = Ch.Soft, fontSize = 11.5.sp)
        }
        Text(value, color = Ch.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
    }
}

/** "Mobile 12 MB · Wi-Fi 80 MB" under a data row. */
@Composable
private fun splitOf(mobile: Long, wifi: Long): String =
    stringResource(R.string.pf_net_mobile) + " " + usageBytes(mobile) + " · " + stringResource(R.string.pf_net_wifi) + " " + usageBytes(wifi)

/** Mobile data against Wi-Fi, as one bar. */
@Composable
private fun SplitBar(mobile: Long, wifi: Long) {
    val total = (mobile + wifi).coerceAtLeast(1)
    val share = (mobile.toFloat() / total).coerceIn(0f, 1f)
    Box(Modifier.fillMaxWidth().height(8.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.3f))) {
        if (share > 0f) Box(Modifier.fillMaxWidth(share).fillMaxHeight().clip(CircleShape).background(Color.White))
    }
}

internal fun usageBytes(bytes: Long): String = when {
    bytes >= 1_073_741_824L -> String.format(Locale.US, "%.2f GB", bytes / 1_073_741_824.0)
    bytes >= 1_048_576L -> String.format(Locale.US, "%.1f MB", bytes / 1_048_576.0)
    bytes >= 1024L -> String.format(Locale.US, "%d KB", bytes / 1024)
    else -> "$bytes B"
}

private fun sinceDate(millis: Long): String =
    DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM).withLocale(Locale.getDefault()).format(Instant.ofEpochMilli(millis).atZone(ZoneId.systemDefault()))

private fun dirSize(dir: File): Long = dir.walkTopDown().filter { it.isFile }.sumOf { it.length() }
