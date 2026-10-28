package com.dorr.app.ui.screens.chat

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Forward
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MessageDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.aichat.AiMemoryScreen
import com.dorr.app.ui.theme.LocalThemeState
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.util.UUID

/*
 * DORR AI's continue-in-another-chat, memory and AI-images gallery, inside the ordinary chat. Each
 * is one explicit tap, on the person's DORR AI plan (a refusal opens the plan offer — AiPlanGate).
 */

// =============================================================================== continue in another chat

/**
 * A summary of this chat — goal, decisions, facts, files, what's left and what's done — written by
 * DORR AI from the latest messages, shown first and editable; sent only to the chats I pick.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatCapsuleSheet(conversationId: String, onDismiss: () -> Unit) {
    val scope = rememberCoroutineScope()
    val host = LocalChat.current
    val context = LocalContext.current
    var text by remember { mutableStateOf<String?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var picking by remember { mutableStateOf(false) }
    var sending by remember { mutableStateOf(false) }
    val networkError = stringResource(R.string.ch_error_network)

    LaunchedEffect(conversationId) {
        runCatching { ApiClient.aiTools.capsule(chatAuth(), conversationId, JsonObject()).data?.text }
            .onSuccess { text = it.orEmpty() }
            .onFailure { error = it.apiFailure().message ?: networkError }
    }

    ChSheet(onDismissRequest = { if (!sending) onDismiss() }, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
            AiHeader(stringResource(R.string.ch_ai_capsule_title), stringResource(R.string.ch_ai_capsule_sub))
            Spacer(Modifier.height(12.dp))
            val current = text
            when {
                error != null -> Text(error.orEmpty(), color = Ch.Danger, fontSize = 13.sp)
                current == null -> Box(Modifier.fillMaxWidth().padding(28.dp), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = Ch.Red, strokeWidth = 2.5.dp, modifier = Modifier.padding(4.dp))
                }
                else -> {
                    OutlinedTextField(
                        value = current, onValueChange = { text = it.take(4000) },
                        shape = RoundedCornerShape(16.dp), minLines = 6,
                        colors = OutlinedTextFieldDefaults.colors(
                            focusedBorderColor = Ch.Red, unfocusedBorderColor = Ch.FieldLine, cursorColor = Ch.Red,
                            focusedTextColor = Ch.Ink, unfocusedTextColor = Ch.Ink, focusedContainerColor = Ch.FieldFill, unfocusedContainerColor = Ch.FieldFill,
                        ),
                        modifier = Modifier.fillMaxWidth().heightIn(min = 160.dp),
                    )
                    Text(stringResource(R.string.ch_ai_capsule_note), color = Ch.Mut, fontSize = 11.5.sp, modifier = Modifier.padding(top = 8.dp))
                    ChPrimaryButton(stringResource(R.string.ch_ai_capsule_send), modifier = Modifier.fillMaxWidth().padding(top = 14.dp), icon = Icons.Rounded.Forward, enabled = current.isNotBlank() && !sending) { picking = true }
                }
            }
        }
    }

    if (picking) {
        ForwardSheet(exclude = conversationId, onDismiss = { picking = false }) { targets ->
            val body = text?.trim().orEmpty()
            sending = true
            scope.launch {
                val sent = targets.count { target ->
                    runCatching { ApiClient.chat.send(chatAuth(), target, mapOf("type" to "text", "body" to body, "uuid" to UUID.randomUUID().toString())) }.isSuccess
                }
                sending = false
                host.showToast(context.getString(if (sent > 0) R.string.ch_ai_capsule_sent else R.string.ch_error_network))
                if (sent > 0) onDismiss()
            }
        }
    }
}

// =============================================================================== memory

/** My DORR AI memory — the same one the DORR AI chat uses — over the chat, full screen. */
@Composable
fun ChatMemoryDialog(onDismiss: () -> Unit) {
    val night = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    Dialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false, decorFitsSystemWindows = false)) {
        Box(Modifier.fillMaxSize()) { AiMemoryScreen(night = night, onExit = onDismiss) }
    }
}

// =============================================================================== AI images

/** Every image made or changed with AI in my chats; a tap opens it in its conversation. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChatAiImagesSheet(onDismiss: () -> Unit, onOpen: (conversationId: String, messageId: String) -> Unit) {
    var images by remember { mutableStateOf<List<MessageDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    val networkError = stringResource(R.string.ch_error_network)

    LaunchedEffect(Unit) {
        runCatching { ApiClient.aiTools.aiImages(chatAuth()).data.orEmpty() }
            .onSuccess { images = it }
            .onFailure { error = it.apiFailure().message ?: networkError }
    }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp).padding(bottom = 20.dp)) {
            AiHeader(stringResource(R.string.ch_ai_images_title), stringResource(R.string.ch_ai_images_sub))
            Spacer(Modifier.height(12.dp))
            val list = images
            when {
                error != null -> Text(error.orEmpty(), color = Ch.Danger, fontSize = 13.sp)
                list == null -> Box(Modifier.fillMaxWidth().padding(28.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ch.Red, strokeWidth = 2.5.dp) }
                list.isEmpty() -> Text(stringResource(R.string.ch_ai_images_empty), color = Ch.Mut, fontSize = 13.5.sp, modifier = Modifier.padding(vertical = 24.dp))
                else -> LazyVerticalGrid(
                    columns = GridCells.Fixed(3),
                    modifier = Modifier.fillMaxWidth().heightIn(max = 520.dp),
                    horizontalArrangement = Arrangement.spacedBy(6.dp),
                    verticalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    items(list, key = { it.id }) { m ->
                        val url = m.attachments.firstOrNull()?.url
                        Box(Modifier.aspectRatio(1f).clip(RoundedCornerShape(14.dp)).background(Ch.SurfaceMuted).clickable { onOpen(m.conversationId, m.id) }) {
                            if (url != null) AsyncImage(ApiClient.mediaUrl(url), null, imageLoader = chatImages(LocalContext.current), contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                        }
                    }
                }
            }
        }
    }
}

/** The words a search by meaning also looked for («الكورة» → «الماتش», «football»). */
@Composable
internal fun MeaningTerms(terms: List<String>) {
    if (terms.isEmpty()) return
    Text(
        stringResource(R.string.ch_ai_meaning_terms, terms.joinToString(" · ")),
        color = Ch.Mut, fontSize = 12.sp, fontWeight = FontWeight.Medium,
        modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp),
    )
}
