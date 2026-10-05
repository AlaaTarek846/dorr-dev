package com.dorr.app.ui.screens.chat

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.GraphicEq
import androidx.compose.material.icons.rounded.Translate
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AiCapabilitiesDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure

// =============================================================================== what's on offer

/**
 * Which AI tools the server offers right now (an AI provider is set up and the admin left them
 * on). Loaded when the chat opens; the buttons only show when their tool is available.
 */
object ChatAi {
    var caps by mutableStateOf<AiCapabilitiesDto?>(null)
        private set

    suspend fun load() {
        runCatching { ApiClient.chat.aiCapabilities(chatAuth()).data }.getOrNull()?.let { caps = it }
    }

    val translate get() = caps?.translate == true
    val transcribe get() = caps?.transcribe == true
    val summarize get() = caps?.summarize == true
    val smartReplies get() = caps?.smartReplies == true
}

/** A translation or a voice transcript shown under a message (only for me, only when I asked). */
data class AiNote(val kind: String, val text: String? = null, val loading: Boolean = false, val failed: String? = null)

/** The translation / transcript under a bubble, with a way to hide it. */
@Composable
internal fun AiNotePanel(note: AiNote, mine: Boolean, onHide: () -> Unit) {
    val ink = if (mine) Ch.OutText else Ch.Ink
    Column(
        Modifier.fillMaxWidth().padding(start = 8.dp, end = 8.dp, bottom = 6.dp)
            .clip(RoundedCornerShape(14.dp)).background(ink.copy(alpha = 0.07f)).padding(horizontal = 10.dp, vertical = 8.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(if (note.kind == "transcript") Icons.Rounded.GraphicEq else Icons.Rounded.Translate, null, tint = ink.copy(alpha = 0.7f), modifier = Modifier.size(14.dp))
            Spacer(Modifier.width(5.dp))
            Text(
                stringResource(if (note.kind == "transcript") R.string.ch_ai_transcript else R.string.ch_ai_translation),
                color = ink.copy(alpha = 0.7f), fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f),
            )
            Box(Modifier.size(22.dp).clip(CircleShape).clickable(onClick = onHide), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Close, null, tint = ink.copy(alpha = 0.6f), modifier = Modifier.size(14.dp))
            }
        }
        AnimatedContent(note, label = "aiNote", transitionSpec = { fadeIn(tween(200)) togetherWith fadeOut(tween(120)) }) { n ->
            when {
                n.loading -> AiShimmer(ink)
                n.failed != null -> Text(n.failed, color = Ch.Danger, fontSize = 12.5.sp)
                else -> Text(n.text.orEmpty(), color = ink, fontSize = 14.sp, lineHeight = 20.sp)
            }
        }
    }
}

/** Three soft lines that breathe while the AI works. */
@Composable
private fun AiShimmer(ink: Color) {
    val t = rememberInfiniteTransition(label = "aiShimmer")
    val a by t.animateFloat(0.25f, 0.6f, infiniteRepeatable(tween(700), RepeatMode.Reverse), label = "aiAlpha")
    Column(Modifier.padding(top = 6.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
        listOf(1f, 0.85f, 0.55f).forEach { w ->
            Box(Modifier.fillMaxWidth(w).height(9.dp).clip(CircleShape).background(ink.copy(alpha = a * 0.35f)))
        }
    }
}

/** Under a voice message: "Show text". */
@Composable
internal fun TranscribeChip(mine: Boolean, onClick: () -> Unit) {
    val ink = if (mine) Ch.OutText else Ch.Red
    Row(
        Modifier.padding(start = 10.dp, bottom = 6.dp).clip(CircleShape).background(ink.copy(alpha = 0.1f)).clickable(onClick = onClick).padding(horizontal = 10.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.GraphicEq, null, tint = ink, modifier = Modifier.size(14.dp))
        Spacer(Modifier.width(4.dp))
        Text(stringResource(R.string.ch_ai_show_text), color = ink, fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
    }
}

// =============================================================================== suggested replies

/**
 * Above the composer after tapping ✨: three replies the AI suggests. A tap puts one in the
 * field — it's sent only when I press send.
 */
@Composable
internal fun SmartRepliesRow(state: ConversationState, onPick: (String) -> Unit) {
    val visible = state.loadingReplies || state.smartReplies.isNotEmpty()
    AnimatedVisibility(visible, enter = expandVertically(spring(dampingRatio = 0.8f)) + fadeIn(), exit = shrinkVertically() + fadeOut()) {
        Row(Modifier.fillMaxWidth().padding(bottom = 6.dp).horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(34.dp).clip(CircleShape).background(Ch.Surface).clickable { state.clearSmartReplies() }, contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Close, null, tint = Ch.Mut, modifier = Modifier.size(16.dp))
            }
            if (state.loadingReplies) {
                repeat(3) { i -> SuggestionChip("…", Modifier.graphicsLayer { alpha = 0.5f }.chStagger(i)) {} }
            } else {
                state.smartReplies.forEachIndexed { i, reply -> SuggestionChip(reply, Modifier.chStagger(i)) { onPick(reply) } }
            }
        }
    }
}

@Composable
private fun SuggestionChip(text: String, modifier: Modifier, onClick: () -> Unit) {
    Row(
        modifier.shadow(4.dp, CircleShape).clip(CircleShape).background(Ch.Surface).clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.AutoAwesome, null, tint = Ch.Red, modifier = Modifier.size(14.dp))
        Spacer(Modifier.width(6.dp))
        Text(text, color = Ch.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
    }
}

// =============================================================================== summary

/** "✨ Summarise what's new" at the top of a chat opened with many unread messages. */
@Composable
internal fun SummarizePill(visible: Boolean, onClick: () -> Unit) {
    AnimatedVisibility(visible, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
        Row(Modifier.fillMaxWidth().padding(top = 8.dp), horizontalArrangement = Arrangement.Center) {
            Row(
                Modifier.shadow(6.dp, CircleShape).clip(CircleShape).background(Ch.HeaderBrush).clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.AutoAwesome, null, tint = Color.White, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(R.string.ch_ai_summarize_new), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp)
            }
        }
    }
}

/**
 * The AI's summary of this chat: what's new since I last read it, or the latest messages.
 * Says plainly that the messages go to the AI provider for this.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AiSummarySheet(conversationId: String, unreadFirst: Boolean, onDismiss: () -> Unit) {
    var unreadOnly by remember { mutableStateOf(unreadFirst) }
    var text by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var count by remember { mutableStateOf(0) }
    val networkError = stringResource(R.string.ch_error_network)

    LaunchedEffect(unreadOnly) {
        text = null
        error = null
        try {
            val result = ApiClient.chat.summarize(chatAuth(), conversationId, mapOf("unread_only" to unreadOnly)).data
            text = result?.text
            count = result?.messages ?: 0
        } catch (e: Exception) {
            error = e.apiFailure().message ?: networkError
        }
    }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(Ch.HeaderBrush), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.AutoAwesome, null, tint = Color.White, modifier = Modifier.size(20.dp))
                }
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.ch_ai_summary), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                    if (text != null) Text(stringResource(R.string.ch_ai_summary_from, count), color = Ch.Mut, fontSize = 12.sp)
                }
            }
            Spacer(Modifier.height(12.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                ScopeChip(stringResource(R.string.ch_ai_summary_unread), unreadOnly, Modifier.weight(1f)) { unreadOnly = true }
                ScopeChip(stringResource(R.string.ch_ai_summary_latest), !unreadOnly, Modifier.weight(1f)) { unreadOnly = false }
            }
            Spacer(Modifier.height(14.dp))
            Box(Modifier.fillMaxWidth().heightIn(min = 90.dp, max = 380.dp).clip(RoundedCornerShape(18.dp)).background(Ch.SurfaceMuted).padding(14.dp)) {
                when {
                    error != null -> Text(error.orEmpty(), color = Ch.Danger, fontSize = 13.5.sp)
                    text == null -> AiShimmer(Ch.Ink)
                    else -> Text(text.orEmpty(), color = Ch.Ink, fontSize = 14.5.sp, lineHeight = 22.sp, modifier = Modifier.verticalScroll(rememberScrollState()))
                }
            }
            Spacer(Modifier.height(10.dp))
            Text(stringResource(R.string.ch_ai_privacy_note), color = Ch.Soft, fontSize = 11.5.sp)
        }
    }
}

@Composable
private fun ScopeChip(label: String, selected: Boolean, modifier: Modifier, onClick: () -> Unit) {
    Text(
        label, color = if (selected) Color.White else Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 13.sp,
        textAlign = androidx.compose.ui.text.style.TextAlign.Center,
        modifier = modifier.clip(RoundedCornerShape(14.dp)).background(if (selected) Ch.Red else Ch.SurfaceMuted).clickable(onClick = onClick).padding(vertical = 9.dp),
    )
}
