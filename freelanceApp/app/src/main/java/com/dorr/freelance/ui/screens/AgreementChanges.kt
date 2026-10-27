package com.dorr.freelance.ui.screens

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
private fun Link(text: String, color: Color = settingsAccent(), onClick: () -> Unit) {
    Text(text, color = color, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(onClick = onClick).padding(vertical = 6.dp))
}

@Composable
private fun Pick(text: String, selected: Boolean, onClick: () -> Unit) {
    Text(
        text, color = if (selected) Color.White else settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold,
        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(if (selected) settingsAccent() else settingsAccent().copy(alpha = 0.08f)).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 6.dp),
    )
}

/** Changes to my active agreement (FL-404 / FL-405): what was asked, the answers, and a short form to ask for one. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun AgreementChangesCard(changes: List<ChangeDto>, milestones: List<MilestoneDto>, currency: String, busy: Boolean, onAsk: (ChangeRequest) -> Unit, onAnswer: (String, String) -> Unit) {
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
                Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                    if (c.can.accept) Link(stringResource(R.string.ch_accept)) { onAnswer(c.uuid, "accept") }
                    if (c.can.reject) Link(stringResource(R.string.ch_reject), Color(0xFFDC2626)) { onAnswer(c.uuid, "reject") }
                    if (c.can.withdraw) Link(stringResource(R.string.ch_withdraw)) { onAnswer(c.uuid, "withdraw") }
                }
            }
        }
        if (!asking) Link(stringResource(R.string.ch_ask)) { asking = true }
        else {
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
                    DorrTextField(days, { days = it.filter(Char::isDigit).take(3) }, placeholder = stringResource(R.string.ch_days), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
                }
                "milestone_price" -> {
                    val unfunded = milestones.filter { it.status == "unfunded" }
                    if (unfunded.isEmpty()) Text(stringResource(R.string.ch_no_unfunded), color = settingsMut(), fontSize = 12.sp)
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) { unfunded.forEach { m -> Pick(m.title, part == m.uuid) { part = m.uuid } } }
                    DorrTextField(amount, { amount = it.take(12) }, placeholder = stringResource(R.string.ch_new_price, currency), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth())
                }
                "scope" -> {
                    Text(stringResource(R.string.ch_scope_hint), color = settingsMut(), fontSize = 11.sp)
                    DorrTextField(scope, { scope = it.take(8000) }, placeholder = stringResource(R.string.ag_scope), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                    DorrTextField(includes, { includes = it.take(3000) }, placeholder = stringResource(R.string.ag_includes), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                    DorrTextField(excludes, { excludes = it.take(3000) }, placeholder = stringResource(R.string.ag_excludes), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                }
                else -> {
                    DorrTextField(title, { title = it.take(120) }, placeholder = stringResource(R.string.ch_part_title), modifier = Modifier.fillMaxWidth())
                    DorrTextField(amount, { amount = it.take(12) }, placeholder = stringResource(R.string.ch_new_price, currency), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth())
                }
            }
            DorrTextField(reason, { reason = it.take(1000) }, placeholder = stringResource(R.string.ch_reason), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
            val minor = parseMinor(amount)
            val ready = when (type) {
                "extend_days" -> (days.toIntOrNull() ?: 0) > 0
                "milestone_price" -> part != null && minor != null
                "scope" -> scope.isNotBlank() || includes.isNotBlank() || excludes.isNotBlank()
                else -> title.isNotBlank() && minor != null
            }
            Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                Link(stringResource(R.string.ch_send)) {
                    if (ready && !busy) {
                        onAsk(ChangeRequest(
                            type = type, milestone = part, days = days.toIntOrNull(), amountMinor = minor, title = title.trim().ifBlank { null },
                            scope = if (type == "scope") scope.trim().ifBlank { null } else null, includes = if (type == "scope") includes.trim().ifBlank { null } else null,
                            excludes = if (type == "scope") excludes.trim().ifBlank { null } else null, reason = reason.trim().ifBlank { null },
                        ))
                        asking = false; days = ""; amount = ""; title = ""; reason = ""; scope = ""; includes = ""; excludes = ""
                    }
                }
                Link(stringResource(R.string.common_cancel), settingsMut()) { asking = false }
            }
        }
    }
}
