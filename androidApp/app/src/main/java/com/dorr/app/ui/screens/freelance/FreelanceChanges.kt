package com.dorr.app.ui.screens.freelance

import androidx.compose.material.icons.Icons
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.material3.Icon
import androidx.compose.ui.Alignment
import androidx.compose.foundation.clickable
import com.dorr.app.ui.screens.profile.settingsSurface
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material.icons.rounded.*
import androidx.compose.foundation.background
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
import com.dorr.app.R
import com.dorr.app.network.FreelanceChangeDto
import com.dorr.app.network.FreelanceChangeRequest
import com.dorr.app.network.FreelanceMilestoneDto
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut

private fun num(v: Any?): Long = (v as? Number)?.toLong() ?: 0L

/** What a change does, in one line. */
@Composable
private fun changeText(c: FreelanceChangeDto, currency: String): String {
    val p = c.payload
    return when (c.type) {
        "extend_days" -> stringResource(R.string.fl_ch_extend, num(p["days"]).toInt()) + (c.milestone?.let { " · ${it.title}" } ?: "")
        "milestone_price" -> stringResource(R.string.fl_ch_price, c.milestone?.title.orEmpty(), "‎${money(num(p["from"]))}", "‎${money(num(p["to"]))} $currency")
        "add_milestone" -> stringResource(R.string.fl_ch_add, p["title"]?.toString().orEmpty(), "‎${money(num(p["amount_minor"]))} $currency")
        else -> stringResource(R.string.fl_ch_scope)
    }
}

@Composable
private fun changeStatus(status: String): String = stringResource(
    when (status) {
        "accepted" -> R.string.fl_ch_s_accepted
        "rejected" -> R.string.fl_ch_s_rejected
        "withdrawn" -> R.string.fl_ch_s_withdrawn
        "expired" -> R.string.fl_ch_s_expired
        "cancelled" -> R.string.fl_ch_s_cancelled
        else -> R.string.fl_ch_s_pending
    },
)

/**
 * Changes to an active agreement (FL-404 / FL-405): what was asked and its answer, the buttons to answer or take one back, and a short
 * form to ask: more days, a new scope, a new price for a part not funded yet, or a new part for more work.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun ChangesCard(
    changes: List<FreelanceChangeDto>,
    milestones: List<FreelanceMilestoneDto>,
    currency: String,
    canAsk: Boolean,
    busy: Boolean,
    onAsk: (FreelanceChangeRequest) -> Unit,
    onAnswer: (String, String) -> Unit,
) {
    var asking by remember { mutableStateOf(false) }
    var type by remember { mutableStateOf("extend_days") }
    var part by remember { mutableStateOf<String?>(null) }
    var days by remember { mutableStateOf("") }
    var amount by remember { mutableStateOf("") }
    var title by remember { mutableStateOf("") }
    var scope by remember { mutableStateOf("") }
    var includes by remember { mutableStateOf("") }
    var excludes by remember { mutableStateOf("") }
    var reason by remember { mutableStateOf("") }

    Card(Icons.Rounded.SwapHoriz, stringResource(R.string.fl_ch_title)) {
        if (changes.isEmpty()) Text(stringResource(R.string.fl_ch_none), color = settingsMut(), fontSize = 12.sp)
        changes.forEach { c ->
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.07f)).padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(changeText(c, currency), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                Text(stringResource(if (c.mine) R.string.fl_ch_by_me else R.string.fl_ch_by_them) + "  ·  " + changeStatus(c.status), color = settingsMut(), fontSize = 11.sp)
                c.reason?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
                Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                    if (c.can.accept) TextAction(stringResource(R.string.fl_ch_accept)) { onAnswer(c.uuid, "accept") }
                    if (c.can.reject) TextAction(stringResource(R.string.fl_ch_reject), danger = true) { onAnswer(c.uuid, "reject") }
                    if (c.can.withdraw) TextAction(stringResource(R.string.fl_ch_withdraw)) { onAnswer(c.uuid, "withdraw") }
                }
            }
        }
        if (canAsk && !asking) TextAction(stringResource(R.string.fl_ch_ask)) { asking = true }
        if (canAsk && asking) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                listOf("extend_days" to R.string.fl_ch_t_extend, "scope" to R.string.fl_ch_t_scope, "milestone_price" to R.string.fl_ch_t_price, "add_milestone" to R.string.fl_ch_t_add).forEach { (t, label) ->
                    Chip(stringResource(label), selected = type == t) { type = t; part = null }
                }
            }
            val unfunded = milestones.filter { it.status == "unfunded" }
            val open = milestones.filter { it.status !in listOf("approved", "cancelled", "disputed") }
            when (type) {
                "extend_days" -> {
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        Chip(stringResource(R.string.fl_ch_whole), selected = part == null) { part = null }
                        open.forEach { m -> Chip(m.title, selected = part == m.uuid) { part = m.uuid } }
                    }
                    DorrTextField(days, { days = it.filter(Char::isDigit).take(3) }, label = stringResource(R.string.fl_ch_days), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.fillMaxWidth())
                }
                "milestone_price" -> {
                    if (unfunded.isEmpty()) Text(stringResource(R.string.fl_ch_no_unfunded), color = settingsMut(), fontSize = 12.sp)
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                        unfunded.forEach { m -> Chip(m.title, selected = part == m.uuid) { part = m.uuid } }
                    }
                    DorrTextField(amount, { amount = it.take(12) }, label = stringResource(R.string.fl_ch_new_price, currency), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth())
                }
                "add_milestone" -> {
                    DorrTextField(title, { title = it.take(120) }, label = stringResource(R.string.fl_ch_part_title), modifier = Modifier.fillMaxWidth())
                    DorrTextField(amount, { amount = it.take(12) }, label = stringResource(R.string.fl_ch_new_price, currency), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), modifier = Modifier.fillMaxWidth())
                }
                else -> {
                    // Only what is filled in changes.
                    Text(stringResource(R.string.fl_ch_scope_hint), color = settingsMut(), fontSize = 11.sp)
                    DorrTextField(scope, { scope = it.take(8000) }, label = stringResource(R.string.fl_ag_scope), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                    DorrTextField(includes, { includes = it.take(3000) }, label = stringResource(R.string.fl_ag_includes), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                    DorrTextField(excludes, { excludes = it.take(3000) }, label = stringResource(R.string.fl_ag_excludes), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
                }
            }
            DorrTextField(reason, { reason = it.take(1000) }, label = stringResource(R.string.fl_ch_reason), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
            val ready = when (type) {
                "extend_days" -> (days.toIntOrNull() ?: 0) > 0
                "milestone_price" -> part != null && parseMoney(amount) != null
                "add_milestone" -> title.isNotBlank() && parseMoney(amount) != null
                else -> scope.isNotBlank() || includes.isNotBlank() || excludes.isNotBlank()
            }
            Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                TextAction(stringResource(R.string.fl_ch_send)) {
                    if (ready && !busy) {
                        onAsk(FreelanceChangeRequest(
                            type = type, milestone = part, days = days.toIntOrNull(), amountMinor = parseMoney(amount), title = title.trim().ifBlank { null },
                            scope = if (type == "scope") scope.trim().ifBlank { null } else null,
                            includes = if (type == "scope") includes.trim().ifBlank { null } else null,
                            excludes = if (type == "scope") excludes.trim().ifBlank { null } else null,
                            reason = reason.trim().ifBlank { null },
                        ))
                        asking = false; days = ""; amount = ""; title = ""; scope = ""; includes = ""; excludes = ""; reason = ""
                    }
                }
                TextAction(stringResource(R.string.fl_not_now)) { asking = false }
            }
            if (!ready) Text(stringResource(R.string.fl_ch_fill), color = Color(0xFFD97706), fontSize = 11.sp)
        }
    }
}
