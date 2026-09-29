package com.dorr.app.ui.screens.aichat

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.ArrowBackIosNew
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.DeleteOutline
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AiConversationDto
import com.dorr.app.ui.screens.chat.timeAgo

@Composable
internal fun AiConversationListPage(
    night: Boolean,
    loading: Boolean,
    unavailable: Boolean,
    brandName: String,
    errorMessage: String?,
    conversations: List<AiConversationDto>,
    onBack: () -> Unit,
    onRetry: () -> Unit,
    onOpen: (Int) -> Unit,
    onNewChat: () -> Unit,
    onDelete: (Int) -> Unit,
) {
    val bg = if (night) Ai.bgDark else Ai.bgLight
    val ink = if (night) Ai.inkDark else Ai.inkLight
    val mut = if (night) Ai.mutDark else Ai.mutLight
    Column(Modifier.fillMaxSize().background(bg)) {
        Row(
            Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 12.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                Modifier.size(38.dp).clip(CircleShape).clickable(onClick = onBack),
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.ArrowBackIosNew, contentDescription = null, tint = ink, modifier = Modifier.size(18.dp)) }
            Spacer(Modifier.width(4.dp))
            Text(stringResource(R.string.ai_history_title), color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, modifier = Modifier.weight(1f))
            if (!unavailable) {
                Box(
                    Modifier.size(38.dp).clip(CircleShape).background(Ai.Red.copy(alpha = 0.12f)).clickable(onClick = onNewChat),
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.Rounded.Add, contentDescription = stringResource(R.string.ai_new_chat), tint = Ai.Red, modifier = Modifier.size(20.dp)) }
            }
        }

        when {
            unavailable -> AiCenterNotice(mut, stringResource(R.string.ai_unavailable, brandName))
            loading && conversations.isEmpty() -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                CircularProgressIndicator(color = Ai.Red, strokeWidth = 2.5.dp, modifier = Modifier.size(28.dp))
            }
            errorMessage != null && conversations.isEmpty() -> AiCenterNotice(mut, errorMessage, retryLabel = stringResource(R.string.ai_retry), onRetry = onRetry)
            conversations.isEmpty() -> AiCenterNotice(mut, stringResource(R.string.ai_empty_history))
            else -> LazyColumn(Modifier.fillMaxSize().padding(top = 4.dp)) {
                items(conversations, key = { it.id }) { conversation ->
                    var confirmDelete by remember(conversation.id) { mutableStateOf(false) }
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .clickable { onOpen(conversation.id) }
                            .padding(horizontal = 16.dp, vertical = 12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Box(
                            Modifier.size(44.dp).clip(CircleShape).background(Brush.linearGradient(listOf(Color(0xFFFF8A65), Ai.Red))),
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.Rounded.AutoAwesome, contentDescription = null, tint = Color.White, modifier = Modifier.size(20.dp)) }
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(
                                conversation.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.ai_new_chat),
                                color = ink, fontWeight = FontWeight.Bold, fontSize = 15.sp,
                                maxLines = 1, overflow = TextOverflow.Ellipsis,
                            )
                            conversation.lastMessagePreview?.takeIf { it.isNotBlank() }?.let {
                                Text(it, color = mut, fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 2.dp))
                            }
                        }
                        Spacer(Modifier.width(8.dp))
                        Text(timeAgo(conversation.updatedAt ?: conversation.createdAt), color = mut, fontSize = 11.sp)
                        Box(
                            Modifier.size(32.dp).clip(CircleShape).clickable { confirmDelete = true },
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.Rounded.DeleteOutline, contentDescription = stringResource(R.string.ai_delete_chat), tint = mut, modifier = Modifier.size(18.dp)) }
                    }
                    if (confirmDelete) {
                        AiConfirmDialog(
                            night = night,
                            title = stringResource(R.string.ai_delete_chat),
                            message = stringResource(R.string.ai_delete_chat_confirm),
                            onConfirm = { confirmDelete = false; onDelete(conversation.id) },
                            onDismiss = { confirmDelete = false },
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun AiCenterNotice(mut: Color, text: String, retryLabel: String? = null, onRetry: (() -> Unit)? = null) {
    Column(
        Modifier.fillMaxSize().padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Text(text, color = mut, fontSize = 14.sp, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
        if (retryLabel != null && onRetry != null) {
            Spacer(Modifier.height(12.dp))
            Box(
                Modifier.clip(RoundedCornerShape(12.dp)).background(Ai.Red).clickable(onClick = onRetry).padding(horizontal = 20.dp, vertical = 10.dp),
            ) { Text(retryLabel, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp) }
        }
    }
}
