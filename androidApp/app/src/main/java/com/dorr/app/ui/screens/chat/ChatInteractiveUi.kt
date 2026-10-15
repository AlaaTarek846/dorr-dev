package com.dorr.app.ui.screens.chat

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
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
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.List
import androidx.compose.material.icons.automirrored.rounded.Reply
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.BarChart
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.ShortText
import androidx.compose.material.icons.rounded.SmartButton
import androidx.compose.material.icons.rounded.Subject
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.InteractiveDto

/*
 * Reply buttons and lists (WhatsApp list 123–125): a message with up to 3 buttons, or a button
 * that opens a list of up to 10 choices. A tap answers in the chat as a reply; the sender sees
 * how many answered and who picked what.
 */

/** The bubble: the text, a quiet footer, then the buttons (or the list's button). */
@Composable
fun InteractiveCard(m: UiMessage, mine: Boolean, onChoose: (Int) -> Unit, onShowAnswers: () -> Unit, footer: @Composable () -> Unit) {
    val ui = m.dto.interactive ?: return
    val ink = if (mine) Ch.OutText else Ch.InText
    val accent = if (mine) Ch.OutText else Ch.Red
    // My own message can't be answered by me; one still being sent can't be tapped yet.
    val canTap = !mine && m.local == null
    var listOpen by remember { mutableStateOf(false) }

    Column(Modifier.width(270.dp).padding(top = 10.dp)) {
        Text(m.dto.body.orEmpty(), color = ink, fontSize = 15.sp, modifier = Modifier.padding(horizontal = 12.dp))
        ui.footer?.let { Text(it, color = ink.copy(alpha = 0.6f), fontSize = 12.sp, modifier = Modifier.padding(horizontal = 12.dp, vertical = 4.dp)) }
        Box(Modifier.fillMaxWidth().padding(horizontal = 12.dp)) { Box(Modifier.align(Alignment.CenterEnd)) { footer() } }
        if (ui.kind == "list") {
            HorizontalDivider(color = ink.copy(alpha = 0.12f), modifier = Modifier.padding(top = 6.dp))
            ChoiceButton(Icons.AutoMirrored.Rounded.List, ui.button ?: stringResource(R.string.ia_choose), accent, chosen = false) { listOpen = true }
        } else {
            ui.options.forEach { o ->
                HorizontalDivider(color = ink.copy(alpha = 0.12f), modifier = Modifier.padding(top = 2.dp))
                ChoiceButton(Icons.AutoMirrored.Rounded.Reply, o.title, accent, chosen = o.id == ui.myChoice, enabled = canTap) { onChoose(o.id) }
            }
        }
        if (mine) {
            Row(
                Modifier.padding(horizontal = 8.dp, vertical = 6.dp).clip(RoundedCornerShape(10.dp)).clickable(enabled = ui.answers > 0, onClick = onShowAnswers).padding(horizontal = 6.dp, vertical = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.BarChart, null, tint = accent, modifier = Modifier.size(15.dp))
                Spacer(Modifier.width(4.dp))
                Text(
                    if (ui.answers == 0) stringResource(R.string.ia_no_answers) else stringResource(R.string.ia_answers, ui.answers),
                    color = accent, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold,
                )
            }
        } else {
            Spacer(Modifier.height(4.dp))
        }
    }

    if (listOpen) InteractiveListSheet(m.dto.body.orEmpty(), ui, canTap, onDismiss = { listOpen = false }) { onChoose(it) }
}

@Composable
private fun ChoiceButton(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, accent: Color, chosen: Boolean, enabled: Boolean = true, onClick: () -> Unit) {
    val pop by animateFloatAsState(if (chosen) 1f else 0f, spring(dampingRatio = 0.45f), label = "choice")
    Row(
        Modifier.fillMaxWidth().clickable(enabled = enabled, onClick = onClick).padding(horizontal = 12.dp, vertical = 11.dp),
        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(if (chosen) Icons.Rounded.CheckCircle else icon, null, tint = accent, modifier = Modifier.size(18.dp).scale(1f + pop * 0.15f))
        Spacer(Modifier.width(6.dp))
        Text(title, color = accent, fontSize = 14.5.sp, fontWeight = if (chosen) FontWeight.ExtraBold else FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/** A list message opened: one choice, each with its line, then "Send". */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun InteractiveListSheet(title: String, ui: InteractiveDto, canTap: Boolean, onDismiss: () -> Unit, onSend: (Int) -> Unit) {
    var picked by remember { mutableStateOf(ui.myChoice) }
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().heightIn(max = 620.dp).verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Text(ui.button ?: title, color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
            Spacer(Modifier.height(10.dp))
            ui.options.forEachIndexed { i, o ->
                val on = picked == o.id
                Row(
                    Modifier.fillMaxWidth().chStagger(i).clip(RoundedCornerShape(14.dp)).clickable(enabled = canTap) { picked = o.id }.padding(vertical = 10.dp, horizontal = 4.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f)) {
                        Text(o.title, color = Ch.Ink, fontWeight = if (on) FontWeight.ExtraBold else FontWeight.SemiBold, fontSize = 15.sp)
                        o.description?.let { Text(it, color = Ch.Mut, fontSize = 12.5.sp) }
                    }
                    Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) Ch.Red else Ch.Mut, modifier = Modifier.size(22.dp))
                }
            }
            if (canTap) {
                Spacer(Modifier.height(12.dp))
                ChPrimaryButton(stringResource(R.string.ch_send), icon = Icons.AutoMirrored.Rounded.Send, modifier = Modifier.fillMaxWidth(), enabled = picked != null && picked != ui.myChoice) {
                    picked?.let(onSend)
                    onDismiss()
                }
            }
        }
    }
}

/**
 * Write one: the message, then up to 3 buttons — or a list of up to 10 choices (each with an
 * optional line) opened from a button I name — and an optional footer.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun InteractiveComposerSheet(onDismiss: () -> Unit, onSend: (body: String, extra: Map<String, Any?>) -> Unit) {
    var body by remember { mutableStateOf("") }
    var list by remember { mutableStateOf(false) }
    val options = remember { mutableStateListOf("", "") }
    val descriptions = remember { mutableStateListOf("", "") }
    var button by remember { mutableStateOf("") }
    var footer by remember { mutableStateOf("") }
    val max = if (list) 10 else 3
    val titleMax = if (list) 24 else 20
    val filled = options.map { it.trim() }.filter { it.isNotEmpty() }
    val ready = body.isNotBlank() && filled.isNotEmpty() && filled.size <= max && filled.map { it.lowercase() }.distinct().size == filled.size

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().heightIn(max = 640.dp).verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconBadge(Icons.Rounded.SmartButton, Color(0xFF0EA5E9))
                Spacer(Modifier.width(12.dp))
                Column {
                    Text(stringResource(R.string.ia_new), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.ia_new_sub), color = Ch.Mut, fontSize = 12.5.sp)
                }
            }
            Spacer(Modifier.height(14.dp))
            // Buttons or a list.
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Ch.SurfaceMuted).padding(4.dp)) {
                listOf(false to R.string.ia_kind_buttons, true to R.string.ia_kind_list).forEach { (isList, label) ->
                    val on = list == isList
                    Text(
                        stringResource(label), color = if (on) Color.White else Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp, textAlign = TextAlign.Center,
                        modifier = Modifier.weight(1f).clip(RoundedCornerShape(11.dp)).background(if (on) Ch.Red else Color.Transparent)
                            .clickable {
                                list = isList
                                // Down to 3 when going back to buttons.
                                while (!isList && options.size > 3) { options.removeAt(options.lastIndex); descriptions.removeAt(descriptions.lastIndex) }
                            }.padding(vertical = 9.dp),
                    )
                }
            }
            Spacer(Modifier.height(12.dp))
            SheetField(body, stringResource(R.string.ia_body_hint), bold = true, icon = Icons.Rounded.Subject) { body = it.take(1000) }
            if (list) {
                Spacer(Modifier.height(8.dp))
                SheetField(button, stringResource(R.string.ia_button_hint), icon = Icons.AutoMirrored.Rounded.List) { button = it.take(20) }
            }
            Spacer(Modifier.height(12.dp))
            Text(stringResource(if (list) R.string.ia_choices else R.string.ia_buttons), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 4.dp, bottom = 6.dp))
            options.forEachIndexed { i, value ->
                Column(Modifier.padding(vertical = 4.dp).chStagger(i)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.weight(1f)) {
                            SheetField(value, stringResource(R.string.ia_option_hint, i + 1), icon = if (list) Icons.Rounded.RadioButtonUnchecked else Icons.AutoMirrored.Rounded.Reply) { options[i] = it.take(titleMax) }
                        }
                        AnimatedVisibility(options.size > 1) {
                            Box(
                                Modifier.padding(start = 6.dp).size(34.dp).clip(CircleShape).clickable { if (options.size > 1) { options.removeAt(i); descriptions.removeAt(i) } },
                                contentAlignment = Alignment.Center,
                            ) { Icon(Icons.Rounded.Close, null, tint = Ch.Mut, modifier = Modifier.size(18.dp)) }
                        }
                    }
                    if (list) Box(Modifier.padding(start = 24.dp, end = 40.dp, top = 4.dp)) {
                        SheetField(descriptions[i], stringResource(R.string.ia_description_hint), icon = Icons.Rounded.ShortText) { descriptions[i] = it.take(72) }
                    }
                }
            }
            AnimatedVisibility(options.size < max) {
                Row(
                    Modifier.padding(top = 4.dp).clip(RoundedCornerShape(14.dp)).clickable { options.add(""); descriptions.add("") }.padding(horizontal = 8.dp, vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.Add, null, tint = Ch.Red, modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(stringResource(if (list) R.string.ia_add_choice else R.string.ia_add_button), color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp)
                }
            }
            Spacer(Modifier.height(8.dp))
            SheetField(footer, stringResource(R.string.ia_footer_hint), icon = Icons.Rounded.ShortText) { footer = it.take(60) }
            Spacer(Modifier.height(14.dp))
            ChPrimaryButton(stringResource(R.string.ch_send), icon = Icons.AutoMirrored.Rounded.Send, modifier = Modifier.fillMaxWidth(), enabled = ready) {
                val kept = options.indices.filter { options[it].isNotBlank() }
                onSend(body.trim(), buildMap {
                    put("interactive_kind", if (list) "list" else "buttons")
                    put("interactive_options", kept.map { options[it].trim() })
                    if (list) {
                        put("interactive_descriptions", kept.map { descriptions[it].trim() })
                        button.trim().takeIf { it.isNotEmpty() }?.let { put("interactive_button", it) }
                    }
                    footer.trim().takeIf { it.isNotEmpty() }?.let { put("interactive_footer", it) }
                })
                onDismiss()
            }
        }
    }
}
