package com.dorr.freelance.ui.screens

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Title
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.EditNote
import androidx.compose.material.icons.rounded.AttachFile
import androidx.compose.material.icons.rounded.Close
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material3.Icon
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.platform.LocalContext
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.effectOf
import kotlinx.coroutines.launch
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.R
import com.dorr.freelance.net.ChangeDto
import com.dorr.freelance.net.ChangeRequest
import com.dorr.freelance.net.MilestoneDto
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.FieldLabel
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut

private fun num(v: Any?): Long = (v as? Number)?.toLong() ?: 0L

@Composable
private fun Pick(text: String, selected: Boolean, onClick: () -> Unit) {
    Text(
        text, color = if (selected) Color.White else settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(if (selected) settingsAccent() else settingsAccent().copy(alpha = 0.08f)).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 6.dp),
    )
}

/** FS-101: at most 3 files of up to 10 MB each go with a change. */
private const val MAX_CHANGE_FILES = 3
private const val MAX_CHANGE_FILE_BYTES = 10L * 1024 * 1024

/** "12/10 14:05" in the phone's own time zone. */
private fun changeWhen(iso: String): String =
    runCatching { OffsetDateTime.parse(iso).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("d/M HH:mm")) }.getOrDefault(iso.take(10))

/**
 * Changes to my active agreement (FL-404 / FL-405): what was asked, the answers, and a short form to ask for one. FS-101: an accepted
 * change reads as the agreement's addendum N with the version it reached and what it moved; a change carries up to 3 files.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun AgreementChangesCard(
    projectUuid: String, changes: List<ChangeDto>, milestones: List<MilestoneDto>, currency: String, busy: Boolean,
    onAsk: (ChangeRequest, List<PickedFile>) -> Unit, onAnswer: (String, String) -> Unit,
) {
    val context = LocalContext.current
    val scope0 = rememberCoroutineScope()
    val fileError = stringResource(R.string.ag_dl_open_failed)
    val tooBig = stringResource(R.string.fs_ch_file_too_big)
    var openError by remember { mutableStateOf<String?>(null) }
    val picks = remember { mutableStateListOf<PickedFile>() }
    var pickError by remember { mutableStateOf<String?>(null) }
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null && picks.size < MAX_CHANGE_FILES) readFile(context, uri)?.let { f ->
            if (f.bytes.size > MAX_CHANGE_FILE_BYTES) pickError = tooBig else { pickError = null; picks.add(f) }
        }
    }
    var asking by remember { mutableStateOf(false) }
    var type by remember { mutableStateOf("extend_days") }
    var part by remember { mutableStateOf<String?>(null) }
    var days by remember { mutableStateOf("") }
    var amount by remember { mutableStateOf("") }
    var title by remember { mutableStateOf("") }
    var reason by remember { mutableStateOf("") }
    var scope by remember { mutableStateOf("") }
    var includes by remember { mutableStateOf("") }
    var excludes by remember { mutableStateOf("") }

    Card {
        FieldLabel(stringResource(R.string.ch_title))
        if (changes.isEmpty()) Text(stringResource(R.string.ch_none), color = settingsMut(), fontSize = 12.sp)
        changes.forEach { c ->
            val p = c.payload
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.05f)).padding(10.dp)) {
                // FS-101: an accepted change is addendum N of the agreement.
                c.addendumNo?.let {
                    Text(
                        stringResource(R.string.fs_ch_addendum, it), color = Color(0xFF16A34A), fontSize = 11.sp, fontWeight = FontWeight.ExtraBold,
                        modifier = Modifier.padding(bottom = 4.dp).clip(RoundedCornerShape(999.dp)).background(Color(0xFF16A34A).copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 3.dp),
                    )
                }
                Text(
                    when (c.type) {
                        "extend_days" -> stringResource(R.string.ch_extend, num(p["days"]).toInt()) + (c.milestone?.let { " · ${it.title}" } ?: "")
                        "milestone_price" -> stringResource(R.string.ch_price, c.milestone?.title.orEmpty(), "‎${formatMinor(num(p["from"]), null)}", "‎${formatMinor(num(p["to"]), null)} $currency")
                        "add_milestone" -> stringResource(R.string.ch_add, p["title"]?.toString().orEmpty(), "‎${formatMinor(num(p["amount_minor"]), null)} $currency")
                        else -> stringResource(R.string.ch_scope)
                    },
                    color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                )
                Text(stringResource(if (c.mine) R.string.ch_by_me else R.string.ch_by_them) + "  ·  " + stringResource(when (c.status) {
                    "accepted" -> R.string.ch_s_accepted; "rejected" -> R.string.ch_s_rejected; "withdrawn" -> R.string.ch_s_withdrawn
                    "expired" -> R.string.ch_s_expired; "cancelled" -> R.string.ch_s_cancelled; else -> R.string.ch_s_pending
                }), color = settingsMut(), fontSize = 11.sp)
                c.reason?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
                // FS-101: the deadline while it waits (it expires by itself after that).
                if (c.status == "pending") c.respondBy?.let {
                    Text(stringResource(R.string.fs_ch_respond_by, "‎" + changeWhen(it)), color = Color(0xFFD97706), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 2.dp))
                }
                // FS-101: the version the agreement reached and what moved, before → after.
                if (c.status == "accepted") {
                    c.appliedVersion?.let { Text(stringResource(R.string.fs_ch_version, it), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 2.dp)) }
                    c.effectOf("price_minor")?.let { (b, a) ->
                        Text(stringResource(R.string.fs_ch_effect_price, "‎${formatMinor(b, null)} $currency", "‎${formatMinor(a, null)} $currency"), color = settingsInk(), fontSize = 12.sp)
                    }
                    c.effectOf("duration_days")?.let { (b, a) -> Text(stringResource(R.string.fs_ch_effect_days, b.toInt(), a.toInt()), color = settingsInk(), fontSize = 12.sp) }
                    c.effectOf("milestones")?.let { (b, a) -> Text(stringResource(R.string.fs_ch_effect_parts, b.toInt(), a.toInt()), color = settingsInk(), fontSize = 12.sp) }
                }
                // FS-101: the files it carries - downloaded with my sign-in and opened.
                if (c.files.isNotEmpty()) FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp), modifier = Modifier.padding(top = 6.dp)) {
                    c.files.forEach { f ->
                        Row(
                            Modifier.clip(RoundedCornerShape(999.dp)).background(settingsAccent().copy(alpha = 0.08f))
                                .clickable { scope0.launch { openError = if (openDeliveryFile(context, f, ApiClient.FREELANCE + "projects/$projectUuid/")) null else fileError } }
                                .padding(horizontal = 10.dp, vertical = 6.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(Icons.Rounded.AttachFile, null, tint = settingsAccent(), modifier = Modifier.size(14.dp))
                            Spacer(Modifier.width(4.dp))
                            Text("‎${f.name}", color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
                        }
                    }
                }
                // Real buttons, side by side: the answer is the important move on this card (a small link was easy to miss).
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    if (c.can.accept) ActionButton(stringResource(R.string.ch_accept), null, Tone.Primary, modifier = Modifier.weight(1f)) { onAnswer(c.uuid, "accept") }
                    if (c.can.reject) ActionButton(stringResource(R.string.ch_reject), null, Tone.Danger, modifier = Modifier.weight(1f)) { onAnswer(c.uuid, "reject") }
                    if (c.can.withdraw) ActionButton(stringResource(R.string.ch_withdraw), null, Tone.Soft, modifier = Modifier.weight(1f)) { onAnswer(c.uuid, "withdraw") }
                }
            }
        }
        openError?.let { Text(it, color = Color(0xFFDC2626), fontSize = 12.sp) }
        ActionButton(stringResource(R.string.ch_ask), Icons.Rounded.EditNote, Tone.Primary) { asking = true }
        val minor = parseMinor(amount)
        val ready = when (type) {
            "extend_days" -> (days.toIntOrNull() ?: 0) > 0
            "milestone_price" -> part != null && minor != null
            "scope" -> scope.isNotBlank() || includes.isNotBlank() || excludes.isNotBlank()
            else -> title.isNotBlank() && minor != null
        }
        if (asking) FormSheet(
            Icons.Rounded.EditNote, stringResource(R.string.ch_ask), stringResource(R.string.ch_form_intro), onDismiss = { asking = false },
            // The send button stays above the keyboard.
            footer = {
                ActionButton(stringResource(R.string.ch_send), Icons.Rounded.Send, Tone.Primary, enabled = ready && !busy) {
                    if (ready && !busy) {
                        onAsk(ChangeRequest(
                            type = type, milestone = part, days = days.toIntOrNull(), amountMinor = minor, title = title.trim().ifBlank { null },
                            scope = if (type == "scope") scope.trim().ifBlank { null } else null, includes = if (type == "scope") includes.trim().ifBlank { null } else null,
                            excludes = if (type == "scope") excludes.trim().ifBlank { null } else null, reason = reason.trim().ifBlank { null },
                        ), picks.toList())
                        asking = false; days = ""; amount = ""; title = ""; reason = ""; scope = ""; includes = ""; excludes = ""
                        picks.clear(); pickError = null
                    }
                }
            },
        ) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                listOf("extend_days" to R.string.ch_t_extend, "scope" to R.string.ch_t_scope, "milestone_price" to R.string.ch_t_price, "add_milestone" to R.string.ch_t_add).forEach { (t, label) ->
                    Pick(stringResource(label), type == t) { type = t; part = null }
                }
            }
            when (type) {
                "extend_days" -> {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Pick(stringResource(R.string.ch_whole), part == null) { part = null }
                        milestones.filter { it.status !in listOf("approved", "cancelled", "disputed") }.forEach { m -> Pick(m.title, part == m.uuid) { part = m.uuid } }
                    }
                    DorrTextField(days, { days = it.filter(Char::isDigit).take(3) }, label = stringResource(R.string.ch_days), icon = Icons.Rounded.Schedule, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
                }
                "milestone_price" -> {
                    val unfunded = milestones.filter { it.status == "unfunded" }
                    if (unfunded.isEmpty()) Text(stringResource(R.string.ch_no_unfunded), color = settingsMut(), fontSize = 12.sp)
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) { unfunded.forEach { m -> Pick(m.title, part == m.uuid) { part = m.uuid } } }
                    DorrTextField(amount, { amount = it.take(12) }, label = stringResource(R.string.ch_new_price, currency), icon = Icons.Rounded.Payments, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth())
                }
                "scope" -> {
                    Text(stringResource(R.string.ch_scope_hint), color = settingsMut(), fontSize = 11.sp)
                    DorrTextField(scope, { scope = it.take(8000) }, label = stringResource(R.string.ag_scope), icon = Icons.Rounded.Notes, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                    DorrTextField(includes, { includes = it.take(3000) }, label = stringResource(R.string.ag_includes), icon = Icons.Rounded.Notes, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                    DorrTextField(excludes, { excludes = it.take(3000) }, label = stringResource(R.string.ag_excludes), icon = Icons.Rounded.Notes, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                }
                else -> {
                    DorrTextField(title, { title = it.take(120) }, label = stringResource(R.string.ch_part_title), icon = Icons.Rounded.Title, modifier = Modifier.fillMaxWidth())
                    DorrTextField(amount, { amount = it.take(12) }, label = stringResource(R.string.ch_new_price, currency), icon = Icons.Rounded.Payments, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth())
                }
            }
            DorrTextField(reason, { reason = it.take(1000) }, label = stringResource(R.string.ch_reason), icon = Icons.Rounded.Notes, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
            // FS-101: up to 3 files (a design, a sketch, a document) go with the request.
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                picks.forEachIndexed { i, f ->
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.AttachFile, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                        Spacer(Modifier.width(8.dp))
                        Text(f.name, color = settingsInk(), fontSize = 13.sp, maxLines = 1, modifier = Modifier.weight(1f))
                        Icon(Icons.Rounded.Close, stringResource(R.string.ag_dl_remove), tint = settingsMut(), modifier = Modifier.size(28.dp).clip(RoundedCornerShape(50)).clickable(enabled = !busy) { picks.removeAt(i) }.padding(5.dp))
                    }
                }
                if (picks.size < MAX_CHANGE_FILES) ActionButton(stringResource(R.string.fs_ch_attach, picks.size, MAX_CHANGE_FILES), Icons.Rounded.AttachFile, Tone.Soft, enabled = !busy) { picker.launch("*/*") }
                Text(stringResource(R.string.fs_ch_attach_hint), color = settingsMut(), fontSize = 11.sp)
                pickError?.let { Text(it, color = Color(0xFFDC2626), fontSize = 12.sp, fontWeight = FontWeight.Bold) }
            }
        }
    }
}
