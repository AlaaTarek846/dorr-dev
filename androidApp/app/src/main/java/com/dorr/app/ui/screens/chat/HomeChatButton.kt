package com.dorr.app.ui.screens.chat

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.spring
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ChatBubble
import androidx.compose.material3.Icon
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.ApiClient
import com.dorr.app.ui.theme.AppColors

/**
 * The chat entry on the Home header: same round white button as its neighbours, with the number
 * of chats that have unread messages popping in (and updating live as messages arrive).
 */
@Composable
fun HomeChatButton(onClick: () -> Unit) {
    var unread by remember { mutableIntStateOf(0) }

    suspend fun refresh() {
        unread = runCatching {
            ApiClient.chat.conversations(chatAuth(), filter = "unread", perPage = 50).data?.size ?: 0
        }.getOrDefault(unread)
    }

    LaunchedEffect(Unit) {
        refresh()
        ChatRealtime.events.collect { event ->
            if (event.name == "chat.message.sent" || event.name == "chat.receipt") refresh()
        }
    }

    Box {
        Box(
            Modifier
                .size(34.dp)
                .shadow(6.dp, CircleShape, spotColor = AppColors.waRed.copy(alpha = 0.08f))
                .clip(CircleShape)
                .background(Color.White)
                .clickable(onClick = onClick),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Icons.Rounded.ChatBubble, contentDescription = stringResource(R.string.ch_open_chats), tint = AppColors.waRed, modifier = Modifier.size(17.dp))
        }
        AnimatedVisibility(
            visible = unread > 0,
            enter = scaleIn(spring(dampingRatio = 0.4f)) + fadeIn(),
            exit = scaleOut() + fadeOut(),
            modifier = Modifier.align(Alignment.TopEnd).offset(x = 6.dp, y = (-6).dp),
        ) {
            ChBadge(unread)
        }
    }
}
