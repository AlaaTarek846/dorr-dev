package com.dorr.freelance.ui.screens.workroom

import android.content.Context
import android.content.Intent
import android.text.format.Formatter
import android.webkit.MimeTypeMap
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Cancel
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.FolderZip
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.InsertDriveFile
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.PictureAsPdf
import androidx.compose.material.icons.rounded.PlayCircle
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.SwapHoriz
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import com.dorr.core.network.ApiResult
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.MilestoneDto
import com.dorr.freelance.net.WorkPartDto
import com.dorr.freelance.ui.components.ChipRow
import com.dorr.freelance.ui.kit.Corners
import com.dorr.freelance.ui.kit.StatusPill
import com.dorr.freelance.ui.kit.Tints
import com.dorr.freelance.ui.kit.appCard
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsCard
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.locale.LocaleAwareBottomSheet
import java.io.File
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.time.LocalDate
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.util.Locale

/** The rows of a Workroom page (a full page means another one follows). */
internal const val WORK_PAGE = 30

internal val WorkRed = Color(0xFFDC2626)

/** A paged call's answer reduced to its rows (the screen keeps the rest — the numbers, what I can do — as a side effect of the fetch). */
internal fun <D, T> ApiResult<D>.rows(extract: (D) -> List<T>): ApiResult<List<T>> = when (this) {
    is ApiResult.Ok -> ApiResult.Ok(extract(data), message)
    is ApiResult.Failed -> this
    is ApiResult.Offline -> this
}

/** The parts of an agreement a Workroom item can be tied to (a cancelled part is not offered). */
internal fun List<MilestoneDto>.asParts(): List<WorkPartDto> =
    filter { it.status != "cancelled" }.map { WorkPartDto(uuid = it.uuid, title = it.title, position = it.position) }

// ---- time and size -------------------------------------------------------------------------------------------------------------

private fun parse(iso: String?): OffsetDateTime? = runCatching { OffsetDateTime.parse(iso) }.getOrNull()

/** A moment as a day: today as the time, this year as «12 Nov», older with the year. */
internal fun workWhen(iso: String?, locale: Locale): String? {
    val at = parse(iso)?.atZoneSameInstant(ZoneId.systemDefault()) ?: return null
    val today = LocalDate.now()
    return when {
        at.toLocalDate() == today -> at.format(DateTimeFormatter.ofPattern("HH:mm", locale))
        at.year == today.year -> at.format(DateTimeFormatter.ofPattern("d MMM", locale))
        else -> at.format(DateTimeFormatter.ofPattern("d MMM yyyy", locale))
    }
}

internal fun workDateTime(iso: String?, locale: Locale): String? =
    parse(iso)?.atZoneSameInstant(ZoneId.systemDefault())?.format(DateTimeFormatter.ofLocalizedDateTime(FormatStyle.MEDIUM, FormatStyle.SHORT).withLocale(locale))

/** A day without a time (`2026-11-20`): «20 Nov» in this year. */
internal fun workDay(day: String?, locale: Locale): String? {
    val d = runCatching { LocalDate.parse(day) }.getOrNull() ?: return null
    return d.format(DateTimeFormatter.ofPattern(if (d.year == LocalDate.now().year) "d MMM" else "d MMM yyyy", locale))
}

/** The heading of a day in the timeline: Today · Yesterday · a date. */
internal fun dayKey(iso: String?): LocalDate? = parse(iso)?.atZoneSameInstant(ZoneId.systemDefault())?.toLocalDate()

internal fun workClock(iso: String?, locale: Locale): String? =
    parse(iso)?.atZoneSameInstant(ZoneId.systemDefault())?.format(DateTimeFormatter.ofPattern("HH:mm", locale))

@Composable
internal fun fileSize(bytes: Long): String = Formatter.formatShortFileSize(LocalContext.current, bytes)

// ---- who ----------------------------------------------------------------------------------------------------------------------

/** My side in this app is the freelancer's; the other is the client. */
internal const val MY_SIDE = "freelancer"

@Composable
internal fun sideName(side: String): String = stringResource(if (side == MY_SIDE) R.string.wr_side_me else R.string.wr_side_client)

// ---- statuses -----------------------------------------------------------------------------------------------------------------

internal fun taskTint(status: String): Color = when (status) {
    "doing" -> Tints.blue
    "blocked" -> Tints.rose
    "done" -> Tints.green
    else -> Color(0xFF9CA3AF)
}

internal fun taskIcon(status: String): ImageVector = when (status) {
    "doing" -> Icons.Rounded.PlayCircle
    "blocked" -> Icons.Rounded.Block
    "done" -> Icons.Rounded.CheckCircle
    else -> Icons.Rounded.RadioButtonUnchecked
}

@Composable
internal fun taskLabel(status: String): String = stringResource(
    when (status) {
        "doing" -> R.string.wr_task_doing
        "blocked" -> R.string.wr_task_blocked
        "done" -> R.string.wr_task_done
        else -> R.string.wr_task_todo
    },
)

internal val TASK_STATUSES = listOf("todo", "doing", "blocked", "done")

internal fun decisionTint(status: String): Color = when (status) {
    "agreed" -> Tints.green
    "declined" -> Tints.rose
    "superseded" -> Color(0xFF9CA3AF)
    else -> Tints.amber
}

internal fun decisionIcon(status: String): ImageVector = when (status) {
    "agreed" -> Icons.Rounded.CheckCircle
    "declined" -> Icons.Rounded.Cancel
    "superseded" -> Icons.Rounded.SwapHoriz
    else -> Icons.Rounded.HourglassTop
}

@Composable
internal fun decisionLabel(status: String): String = stringResource(
    when (status) {
        "agreed" -> R.string.wr_dec_agreed
        "declined" -> R.string.wr_dec_declined
        "superseded" -> R.string.wr_dec_superseded
        else -> R.string.wr_dec_proposed
    },
)

/** The icon and colour of a kind of file (by its type). */
internal fun fileIcon(mime: String, name: String): Pair<ImageVector, Color> = when {
    mime.startsWith("image/") -> Icons.Rounded.Image to Tints.violet
    mime == "application/pdf" || name.endsWith(".pdf", true) -> Icons.Rounded.PictureAsPdf to Tints.rose
    mime.contains("zip") || mime.contains("compressed") || name.endsWith(".zip", true) || name.endsWith(".rar", true) -> Icons.Rounded.FolderZip to Tints.amber
    mime.contains("word") || mime.contains("text") || mime.contains("sheet") || mime.contains("presentation") || mime.contains("excel") || mime.contains("powerpoint") -> Icons.Rounded.Description to Tints.blue
    else -> Icons.Rounded.InsertDriveFile to Tints.teal
}

// ---- small parts --------------------------------------------------------------------------------------------------------------

/** A tonal card of the Workroom: tap it to open, content in a padded column. */
@Composable
internal fun WorkCard(modifier: Modifier = Modifier, accent: Color? = null, onClick: (() -> Unit)? = null, content: @Composable ColumnScope.() -> Unit) {
    val base = Modifier.fillMaxWidth().appCard(Corners.card)
    val framed = if (accent != null) base.then(Modifier.background(accent.copy(alpha = 0.06f), Corners.card)) else base
    Column(
        modifier.then(framed).then(if (onClick != null) Modifier.clip(Corners.card).clickable(onClick = onClick) else Modifier).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
        content = content,
    )
}

/** The tag of the part an item is tied to. */
@Composable
internal fun PartTag(part: WorkPartDto) {
    Text(
        stringResource(R.string.wr_part_tag, part.position, part.title), color = settingsMut(), fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 1,
        overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis,
        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(settingsMut().copy(alpha = 0.12f)).padding(horizontal = 9.dp, vertical = 3.dp),
    )
}

/** An inside item of a team (the client never sees it). */
@Composable
internal fun TeamOnlyPill() {
    StatusPill(stringResource(R.string.wr_team_only), Tints.violet, Icons.Rounded.Lock)
}

/** The server gave this version of the client's view as a small preview only (the work is not approved yet). */
@Composable
internal fun PreviewOnlyPill() {
    StatusPill(stringResource(R.string.wr_preview_only), Tints.amber, Icons.Rounded.Visibility)
}

/** The Workroom is over (the agreement is completed): everything is read only. */
@Composable
internal fun ReadOnlyBanner() {
    Row(
        Modifier.fillMaxWidth().clip(Corners.small).background(Tints.amber.copy(alpha = 0.12f)).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Icon(Icons.Rounded.Lock, contentDescription = null, tint = Tints.amber, modifier = Modifier.size(18.dp))
        Text(stringResource(R.string.wr_read_only), color = settingsInk(), fontSize = 12.5.sp, lineHeight = 18.sp, modifier = Modifier.weight(1f))
    }
}

/** Picks the part an item is tied to: «No part» or one of the agreement's parts. */
@Composable
internal fun PartPicker(parts: List<WorkPartDto>, selected: String?, onSelect: (String?) -> Unit) {
    if (parts.isEmpty()) return
    ChipRow(
        options = listOf("" to stringResource(R.string.wr_no_part)) + parts.map { it.uuid to stringResource(R.string.wr_part_tag, it.position, it.title) },
        selected = setOf(selected ?: ""),
        onToggle = { onSelect(it.ifEmpty { null }) },
    )
}

/** A bottom sheet of the Workroom: a title and a scrolling column for the content (the keyboard never covers the fields). */
@Composable
internal fun WorkSheet(title: String, onDismiss: () -> Unit, dismissible: Boolean = true, content: @Composable ColumnScope.() -> Unit) {
    LocaleAwareBottomSheet(onDismissRequest = { if (dismissible) onDismiss() }, containerColor = settingsCard(), shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp)) {
        Column(
            Modifier.fillMaxWidth().heightIn(max = 680.dp).imePadding().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            Text(title, color = settingsInk(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            content()
        }
    }
}

/** A line of a sheet: a small muted label over its value. */
@Composable
internal fun WorkFact(label: String, value: String) {
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text(label, color = settingsMut(), fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
        Text(value, color = settingsInk(), fontSize = 14.sp, lineHeight = 20.sp)
    }
}

/** A round number badge (a count on a tab or a card). */
@Composable
internal fun CountDot(count: Int, tint: Color = settingsAccent()) {
    if (count <= 0) return
    Box(Modifier.clip(RoundedCornerShape(999.dp)).background(tint).padding(horizontal = 7.dp, vertical = 2.dp), contentAlignment = Alignment.Center) {
        Text(if (count > 99) "99+" else count.toString(), color = Color.White, fontSize = 10.5.sp, fontWeight = FontWeight.Black)
    }
}

// ---- files on the phone -------------------------------------------------------------------------------------------------------

/**
 * Reads one version of a file with my own sign-in (never a public link), keeps it in the cache and opens it in a viewer. False when it
 * could not be read or no app on the phone opens it.
 */
internal suspend fun openVersion(context: Context, project: String, file: String, number: Int, name: String, mime: String): Boolean {
    val saved = withContext(Dispatchers.IO) {
        runCatching {
            val response = ApiClient.workroom.download(project, file, number)
            val body = response.body()
            if (!response.isSuccessful || body == null) return@runCatching null
            val dir = File(context.cacheDir, "workroom").apply { mkdirs() }
            val safe = name.filter { it.isLetterOrDigit() || it in "._- " }.ifBlank { "file" }.takeLast(80)
            File(dir, "${file.take(8)}_v${number}_$safe").also { out -> body.byteStream().use { input -> out.outputStream().use { input.copyTo(it) } } }
        }.getOrNull()
    } ?: return false
    val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", saved)
    val type = mime.ifBlank { MimeTypeMap.getSingleton().getMimeTypeFromExtension(saved.extension.lowercase()) ?: "*/*" }
    val view = Intent(Intent.ACTION_VIEW).setDataAndType(uri, type).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    return runCatching { context.startActivity(Intent.createChooser(view, name).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }.isSuccess
}
