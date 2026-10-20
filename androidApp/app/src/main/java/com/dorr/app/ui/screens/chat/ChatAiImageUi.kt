package com.dorr.app.ui.screens.chat

import androidx.compose.foundation.background
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.AiImageDraftDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MessageDto
import com.dorr.app.network.apiFailure
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.util.UUID

/**
 * Images with DORR AI (spec 50, 51): describe a new image ([source] null), or how to change an
 * image in the chat ([source]). The result is a preview only I see — "Send" puts it in the chat as
 * an image message labelled "Made with AI", "Again" makes another one (each one counts against my
 * AI plan's daily images; a plan refusal opens the subscription offer — see AiPlanGate).
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatAiImageSheet(state: ConversationState, source: MessageDto?, onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    var prompt by remember { mutableStateOf("") }
    var caption by remember { mutableStateOf("") }
    var draft by remember { mutableStateOf<AiImageDraftDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val networkError = stringResource(R.string.ch_error_network)
    val editing = source != null

    fun make() {
        val text = prompt.trim()
        if (text.isEmpty() || busy) return
        busy = true
        error = null
        scope.launch {
            val body = JsonObject().apply { addProperty("prompt", text) }
            runCatching {
                if (source != null) ApiClient.aiTools.editImage(chatAuth(), source.id, body).data
                else ApiClient.aiTools.generateImage(chatAuth(), state.id, body).data
            }.onSuccess { draft = it }.onFailure { error = it.apiFailure().message ?: networkError }
            busy = false
        }
    }

    fun send() {
        val current = draft ?: return
        if (busy) return
        busy = true
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
            busy = false
        }
    }

    ChSheet(onDismissRequest = { if (!busy) onDismiss() }, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
            AiHeader(stringResource(if (editing) R.string.ch_ai_image_edit_title else R.string.ch_ai_image_title), stringResource(R.string.ch_ai_image_sub))
            Spacer(Modifier.height(14.dp))

            val current = draft
            if (current != null) {
                Box(Modifier.fillMaxWidth().aspectRatio(1f).clip(RoundedCornerShape(18.dp)).background(Ch.SurfaceMuted)) {
                    AsyncImage(model = ApiClient.mediaUrl(current.url), contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                    AiMadeLabel(Modifier.align(Alignment.BottomStart).padding(10.dp))
                }
                Spacer(Modifier.height(10.dp))
                OutlinedTextField(
                    value = caption, onValueChange = { caption = it.take(4000) },
                    placeholder = { Text(stringResource(R.string.ch_ai_image_caption), color = Ch.Mut, fontSize = 13.sp) },
                    shape = RoundedCornerShape(14.dp), colors = imageFieldColors(), modifier = Modifier.fillMaxWidth(),
                )
            } else {
                OutlinedTextField(
                    value = prompt, onValueChange = { prompt = it.take(1000) },
                    placeholder = { Text(stringResource(if (editing) R.string.ch_ai_image_edit_hint else R.string.ch_ai_image_hint), color = Ch.Mut, fontSize = 13.sp) },
                    shape = RoundedCornerShape(14.dp), colors = imageFieldColors(), minLines = 2, modifier = Modifier.fillMaxWidth(),
                )
            }

            error?.let { Text(it, color = Ch.Danger, fontSize = 12.5.sp, modifier = Modifier.padding(top = 8.dp)) }
            Spacer(Modifier.height(14.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                if (current != null) {
                    SheetButton(stringResource(R.string.ch_ai_image_again), primary = false, busy = false, modifier = Modifier.weight(1f)) { draft = null }
                    SheetButton(stringResource(R.string.ch_ai_image_send), primary = true, busy = busy, modifier = Modifier.weight(1f)) { send() }
                } else {
                    SheetButton(stringResource(if (editing) R.string.ch_ai_image_edit_go else R.string.ch_ai_image_go), primary = true, busy = busy, enabled = prompt.isNotBlank(), modifier = Modifier.fillMaxWidth()) { make() }
                }
            }
            Text(stringResource(R.string.ch_ai_image_quota_note), color = Ch.Mut, fontSize = 11.5.sp, modifier = Modifier.padding(top = 10.dp))
        }
    }
}

/** "Made with AI", over an image that DORR AI made or changed (`meta.ai_generated`). */
@Composable
internal fun AiMadeLabel(modifier: Modifier = Modifier) {
    Row(
        modifier.clip(RoundedCornerShape(10.dp)).background(Color.Black.copy(alpha = 0.55f)).padding(horizontal = 8.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.AutoAwesome, contentDescription = null, tint = Color.White, modifier = Modifier.size(12.dp))
        Spacer(Modifier.size(4.dp))
        Text(stringResource(R.string.ch_ai_made), color = Color.White, fontSize = 10.5.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun SheetButton(text: String, primary: Boolean, busy: Boolean, modifier: Modifier, enabled: Boolean = true, onClick: () -> Unit) {
    Box(
        modifier.clip(RoundedCornerShape(14.dp))
            .background(if (primary) Ch.Red.copy(alpha = if (enabled) 1f else 0.4f) else Ch.Line)
            .clickable(enabled = enabled && !busy, onClick = onClick)
            .padding(vertical = 13.dp),
        contentAlignment = Alignment.Center,
    ) {
        if (busy) CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
        else Text(text, color = if (primary) Color.White else Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
    }
}

@Composable
private fun imageFieldColors() = OutlinedTextFieldDefaults.colors(
    focusedBorderColor = Ch.Red, unfocusedBorderColor = Ch.FieldLine, cursorColor = Ch.Red,
    focusedTextColor = Ch.Ink, unfocusedTextColor = Ch.Ink, focusedContainerColor = Ch.FieldFill, unfocusedContainerColor = Ch.FieldFill,
)
