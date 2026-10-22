package com.dorr.app.ui.screens.chat

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringArrayResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.AiImageDraftDto
import com.dorr.app.network.AiImageQuotaDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MessageDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.aichat.AiPlanGate
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.util.UUID

/** A look the person can pick, with its own colours for the swatch. */
private data class ImageStyle(val key: String, val label: Int, val colors: List<Color>)

private val STYLES = listOf(
    ImageStyle("realistic", R.string.ch_ai_style_realistic, listOf(Color(0xFF94A3B8), Color(0xFF334155))),
    ImageStyle("illustration", R.string.ch_ai_style_illustration, listOf(Color(0xFFFBBF24), Color(0xFFEC4899))),
    ImageStyle("anime", R.string.ch_ai_style_anime, listOf(Color(0xFFF9A8D4), Color(0xFF8B5CF6))),
    ImageStyle("3d", R.string.ch_ai_style_3d, listOf(Color(0xFF67E8F9), Color(0xFF2563EB))),
    ImageStyle("oil", R.string.ch_ai_style_oil, listOf(Color(0xFFFDBA74), Color(0xFFB45309))),
    ImageStyle("minimal", R.string.ch_ai_style_minimal, listOf(Color(0xFFE5E7EB), Color(0xFF9CA3AF))),
)

private val Indigo = listOf(Color(0xFF818CF8), Color(0xFF4F46E5))

/**
 * Images with DORR AI in the chat (spec 50, 51) — describe a new image ([source] null), or how to
 * change an image in the chat ([source]).
 *
 * 1. Write: today's images on my DORR AI plan up front (or a lock that offers the plans), the
 *    description with one-tap ideas, and — for a new image — a look (realistic, anime, 3D…).
 * 2. Making it: a soft animated canvas while DORR AI draws.
 * 3. The result, only for me: send it with a caption (an image message labelled "Made with AI"),
 *    try again, or go back and change the words. Every image counts against the plan's daily images.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatAiImageSheet(state: ConversationState, source: MessageDto?, onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    val editing = source != null
    var prompt by remember { mutableStateOf("") }
    var style by remember { mutableStateOf<String?>(null) }
    var caption by remember { mutableStateOf("") }
    var draft by remember { mutableStateOf<AiImageDraftDto?>(null) }
    var making by remember { mutableStateOf(false) }
    var sending by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var quota by remember { mutableStateOf<AiImageQuotaDto?>(null) }
    val networkError = stringResource(R.string.ch_error_network)
    val lockedText = quotaText(quota)

    suspend fun refreshQuota() {
        runCatching { ApiClient.aiTools.imageQuota(chatAuth()).data }.onSuccess { quota = it }
    }
    LaunchedEffect(Unit) { refreshQuota() }

    fun make() {
        val text = prompt.trim()
        if (text.isEmpty() || making) return
        making = true
        error = null
        scope.launch {
            val body = JsonObject().apply {
                addProperty("prompt", text)
                if (!editing) style?.let { addProperty("style", it) }
            }
            runCatching {
                if (source != null) ApiClient.aiTools.editImage(chatAuth(), source.id, body).data
                else ApiClient.aiTools.generateImage(chatAuth(), state.id, body).data
            }.onSuccess { draft = it }.onFailure { error = it.apiFailure().message ?: networkError }
            making = false
            refreshQuota()
        }
    }

    fun send() {
        val current = draft ?: return
        if (sending) return
        sending = true
        error = null
        scope.launch {
            val body = JsonObject().apply {
                addProperty("draft", current.draft)
                addProperty("uuid", UUID.randomUUID().toString())
                caption.trim().takeIf { it.isNotEmpty() }?.let { addProperty("body", it) }
            }
            runCatching { ApiClient.aiTools.sendImage(chatAuth(), state.id, body).data }
                .onSuccess { sent ->
                    sent?.let { state.replaceMessage(it.id, UiMessage(it.copy(isMine = true, status = "sent"), fresh = true)) }
                    onDismiss()
                }
                .onFailure { error = it.apiFailure().message ?: networkError }
            sending = false
        }
    }

    ChSheet(onDismissRequest = { if (!making && !sending) onDismiss() }, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
            // ---------------------------------------------------------------- header
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(42.dp).clip(RoundedCornerShape(14.dp)).background(Brush.linearGradient(Indigo)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.AutoAwesome, null, tint = Color.White, modifier = Modifier.size(21.dp))
                }
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(if (editing) R.string.ch_ai_image_edit_title else R.string.ch_ai_image_title), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.ch_ai_image_sub), color = Ch.Mut, fontSize = 12.sp)
                }
                QuotaChip(quota)
            }
            Spacer(Modifier.height(16.dp))

            val stage = when {
                quota?.allowed == false && draft == null && !making -> "locked"
                making -> "making"
                draft != null -> "result"
                else -> "write"
            }
            AnimatedContent(stage, transitionSpec = { fadeIn(tween(220)) togetherWith fadeOut(tween(160)) }, label = "aiImageStage") { now ->
                when (now) {
                    "locked" -> LockedCard(lockedText)
                    "making" -> MakingCanvas()
                    "result" -> draft?.let { ResultPreview(it) }
                    else -> Column {
                        if (source != null) SourceRow(source)
                        OutlinedTextField(
                            value = prompt, onValueChange = { prompt = it.take(1000) },
                            placeholder = { Text(stringResource(if (editing) R.string.ch_ai_image_edit_hint else R.string.ch_ai_image_hint), color = Ch.Mut, fontSize = 13.5.sp) },
                            shape = RoundedCornerShape(18.dp), colors = imageFieldColors(), minLines = 3, modifier = Modifier.fillMaxWidth(),
                        )
                        Spacer(Modifier.height(10.dp))
                        SectionLabel(stringResource(R.string.ch_ai_image_ideas))
                        val ideas = stringArrayResource(if (editing) R.array.ch_ai_image_edit_ideas else R.array.ch_ai_image_ideas)
                        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            items(ideas.toList()) { idea -> IdeaChip(idea) { prompt = idea } }
                        }
                        if (!editing) {
                            Spacer(Modifier.height(14.dp))
                            SectionLabel(stringResource(R.string.ch_ai_image_style))
                            LazyRow(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                items(STYLES, key = { it.key }) { s -> StyleChip(s, selected = style == s.key) { style = if (style == s.key) null else s.key } }
                            }
                        }
                    }
                }
            }

            error?.let { Text(it, color = Ch.Danger, fontSize = 12.5.sp, modifier = Modifier.padding(top = 10.dp)) }

            // ---------------------------------------------------------------- actions
            Spacer(Modifier.height(16.dp))
            when {
                quota?.allowed == false && draft == null && !making ->
                    SheetButton(stringResource(R.string.ai_plan_required_subscribe), Icons.Rounded.Lock, primary = true, busy = false, modifier = Modifier.fillMaxWidth()) { AiPlanGate.offer(lockedText) }
                draft != null -> {
                    OutlinedTextField(
                        value = caption, onValueChange = { caption = it.take(4000) },
                        placeholder = { Text(stringResource(R.string.ch_ai_image_caption), color = Ch.Mut, fontSize = 13.sp) },
                        shape = RoundedCornerShape(16.dp), colors = imageFieldColors(), singleLine = true, modifier = Modifier.fillMaxWidth(),
                    )
                    Spacer(Modifier.height(12.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        SheetButton(stringResource(R.string.ch_ai_image_change), Icons.Rounded.Edit, primary = false, busy = false, modifier = Modifier.weight(1f)) { draft = null }
                        SheetButton(stringResource(R.string.ch_ai_image_again), Icons.Rounded.Refresh, primary = false, busy = false, enabled = quota?.remaining != 0, modifier = Modifier.weight(1f)) { draft = null; make() }
                    }
                    Spacer(Modifier.height(8.dp))
                    SheetButton(stringResource(R.string.ch_ai_image_send), Icons.Rounded.Send, primary = true, busy = sending, modifier = Modifier.fillMaxWidth()) { send() }
                }
                !making -> SheetButton(
                    stringResource(if (editing) R.string.ch_ai_image_edit_go else R.string.ch_ai_image_go), Icons.Rounded.AutoAwesome,
                    primary = true, busy = false, enabled = prompt.isNotBlank(), modifier = Modifier.fillMaxWidth(),
                ) { make() }
            }
            Text(stringResource(R.string.ch_ai_image_quota_note), color = Ch.Mut, fontSize = 11.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(top = 10.dp))
        }
    }
}

// =============================================================================== pieces

/** "3 left today" / "Unlimited" / a lock — read-only, from the plan. */
@Composable
private fun QuotaChip(quota: AiImageQuotaDto?) {
    val q = quota ?: return
    val (text, locked) = when {
        !q.allowed -> stringResource(R.string.ch_ai_image_locked_chip) to true
        q.limit == null -> stringResource(R.string.ch_ai_image_unlimited) to false
        else -> stringResource(R.string.ch_ai_image_left, q.remaining ?: 0) to false
    }
    Row(
        Modifier.clip(RoundedCornerShape(50)).background(if (locked) Ch.Danger.copy(alpha = 0.12f) else Ch.Red.copy(alpha = 0.10f)).padding(horizontal = 10.dp, vertical = 5.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(if (locked) Icons.Rounded.Lock else Icons.Rounded.AutoAwesome, null, tint = if (locked) Ch.Danger else Ch.Red, modifier = Modifier.size(12.dp))
        Spacer(Modifier.width(4.dp))
        Text(text, color = if (locked) Ch.Danger else Ch.Red, fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
    }
}

/** Why images are locked now, in the person's words. */
@Composable
private fun quotaText(quota: AiImageQuotaDto?): String = when (quota?.reason) {
    "not_in_plan" -> stringResource(R.string.ch_ai_image_not_in_plan)
    "daily_limit_reached" -> stringResource(R.string.ch_ai_image_limit_reached, quota.limit ?: 0)
    "trial_ended" -> stringResource(R.string.ch_ai_image_trial_ended)
    "blocked" -> stringResource(R.string.ch_ai_image_blocked)
    else -> stringResource(R.string.ch_ai_image_no_plan)
}

@Composable
private fun LockedCard(text: String) {
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp)).background(Brush.linearGradient(listOf(Color(0xFF001B53), Color(0xFF4F46E5)))).padding(22.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(Modifier.size(52.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.15f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Lock, null, tint = Color.White, modifier = Modifier.size(24.dp))
        }
        Spacer(Modifier.height(12.dp))
        Text(text, color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center)
    }
}

/** A soft, moving canvas with rotating hints while the image is being drawn. */
@Composable
private fun MakingCanvas() {
    val hints = stringArrayResource(R.array.ch_ai_image_making)
    var hint by remember { mutableIntStateOf(0) }
    LaunchedEffect(Unit) {
        while (true) {
            delay(2200)
            hint = (hint + 1) % hints.size
        }
    }
    val t = rememberInfiniteTransition(label = "making")
    val shift by t.animateFloat(0f, 1f, infiniteRepeatable(tween(2400, easing = LinearEasing), RepeatMode.Reverse), label = "shift")
    val spin by t.animateFloat(0f, 360f, infiniteRepeatable(tween(3000, easing = LinearEasing)), label = "spin")
    Box(
        Modifier.fillMaxWidth().aspectRatio(1f).clip(RoundedCornerShape(24.dp))
            .background(Brush.linearGradient(listOf(Color(0xFFC7D2FE), Color(0xFFF5D0FE), Color(0xFFBAE6FD)), start = Offset(0f, 1200f * shift), end = Offset(1200f, 1200f * (1 - shift)))),
        contentAlignment = Alignment.Center,
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(64.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.55f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.AutoAwesome, null, tint = Color(0xFF4F46E5), modifier = Modifier.size(30.dp).rotate(spin))
            }
            Spacer(Modifier.height(14.dp))
            AnimatedContent(hints[hint], transitionSpec = { fadeIn(tween(300)) togetherWith fadeOut(tween(200)) }, label = "hint") { text ->
                Text(text, color = Color(0xFF312E81), fontSize = 14.sp, fontWeight = FontWeight.Bold)
            }
        }
    }
}

@Composable
private fun ResultPreview(draft: AiImageDraftDto) {
    Box(Modifier.fillMaxWidth().aspectRatio(1f).clip(RoundedCornerShape(24.dp)).background(Ch.SurfaceMuted)) {
        AsyncImage(model = ApiClient.mediaUrl(draft.url), contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
    }
}

/** The image being changed, small, above the description. */
@Composable
private fun SourceRow(source: MessageDto) {
    val url = source.attachments.firstOrNull()?.url ?: return
    Row(Modifier.fillMaxWidth().padding(bottom = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        AsyncImage(ApiClient.mediaUrl(url), null, imageLoader = chatImages(LocalContext.current), contentScale = ContentScale.Crop, modifier = Modifier.size(54.dp).clip(RoundedCornerShape(14.dp)))
        Spacer(Modifier.width(10.dp))
        Text(stringResource(R.string.ch_ai_image_source), color = Ch.Mut, fontSize = 12.5.sp)
    }
}

@Composable
private fun SectionLabel(text: String) {
    Text(text, color = Ch.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 8.dp))
}

@Composable
private fun IdeaChip(text: String, onClick: () -> Unit) {
    Text(
        text, color = Ch.Ink, fontSize = 12.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
        modifier = Modifier.clip(RoundedCornerShape(50)).background(Ch.SurfaceMuted).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 7.dp),
    )
}

@Composable
private fun StyleChip(style: ImageStyle, selected: Boolean, onClick: () -> Unit) {
    Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.clip(RoundedCornerShape(14.dp)).clickable(onClick = onClick).padding(2.dp)) {
        Box(
            Modifier.size(54.dp).clip(RoundedCornerShape(16.dp)).background(Brush.linearGradient(style.colors))
                .border(if (selected) 2.5.dp else 0.dp, if (selected) Ch.Red else Color.Transparent, RoundedCornerShape(16.dp)),
            contentAlignment = Alignment.Center,
        ) { if (selected) Icon(Icons.Rounded.AutoAwesome, null, tint = Color.White, modifier = Modifier.size(20.dp)) }
        Spacer(Modifier.height(4.dp))
        Text(stringResource(style.label), color = if (selected) Ch.Red else Ch.Mut, fontSize = 11.5.sp, fontWeight = if (selected) FontWeight.Bold else FontWeight.Medium)
    }
}

@Composable
private fun SheetButton(text: String, icon: ImageVector, primary: Boolean, busy: Boolean, modifier: Modifier, enabled: Boolean = true, onClick: () -> Unit) {
    Row(
        modifier.clip(RoundedCornerShape(16.dp))
            .background(
                if (primary) Brush.linearGradient(listOf(Ch.Red, Ch.Red.copy(alpha = 0.85f)).map { if (enabled) it else it.copy(alpha = 0.4f) })
                else Brush.linearGradient(listOf(Ch.SurfaceMuted, Ch.SurfaceMuted)),
            )
            .clickable(enabled = enabled && !busy, onClick = onClick)
            .padding(vertical = 14.dp),
        horizontalArrangement = Arrangement.Center,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (busy) {
            CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
        } else {
            Icon(icon, null, tint = if (primary) Color.White else Ch.Ink, modifier = Modifier.size(17.dp))
            Spacer(Modifier.width(7.dp))
            Text(text, color = if (primary) Color.White else Ch.Ink.copy(alpha = if (enabled) 1f else 0.4f), fontWeight = FontWeight.Bold, fontSize = 14.sp)
        }
    }
}

@Composable
private fun imageFieldColors() = OutlinedTextFieldDefaults.colors(
    focusedBorderColor = Ch.Red, unfocusedBorderColor = Ch.FieldLine, cursorColor = Ch.Red,
    focusedTextColor = Ch.Ink, unfocusedTextColor = Ch.Ink, focusedContainerColor = Ch.FieldFill, unfocusedContainerColor = Ch.FieldFill,
)
