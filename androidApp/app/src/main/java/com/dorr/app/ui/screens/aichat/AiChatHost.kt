package com.dorr.app.ui.screens.aichat

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import com.dorr.app.network.AiConversationDto
import com.dorr.app.network.AiStatusDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.theme.LocalThemeState
import kotlinx.coroutines.launch

private enum class AiScreen { LIST, CONVERSATION, VOICE, SUBSCRIPTION, LANGUAGE, SITES, SETTINGS }

/**
 * Owns the AI Assistant flow end to end: checks whether there is anything to chat with,
 * loads the owner's AI conversations, and opens straight into the most recent one — a
 * "History" tap inside [AiConversationPage] comes back here to browse/switch/delete, a
 * "New chat" tap starts a fresh one. This is the destination the Home tab's central "+"
 * button (MainScreen's FAB) opens.
 */
@Composable
fun AiChatHost(onExit: () -> Unit) {
    val night = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    val scope = rememberCoroutineScope()

    var screen by rememberSaveable { mutableStateOf(AiScreen.LIST) }
    var conversations by remember { mutableStateOf<List<AiConversationDto>>(emptyList()) }
    var openConversationId by rememberSaveable { mutableStateOf<Int?>(null) }
    var status by remember { mutableStateOf<AiStatusDto?>(null) }
    var loading by remember { mutableStateOf(true) }
    var loadError by remember { mutableStateOf<String?>(null) }
    var bootstrapped by rememberSaveable { mutableStateOf(false) }

    suspend fun refreshConversations() {
        runCatching { ApiClient.aiChat.conversations(chatAuth()).data.orEmpty() }
            .onSuccess { conversations = it }
    }

    suspend fun startNewChat() {
        loading = true
        runCatching { ApiClient.aiChat.createConversation(chatAuth()).data }
            .onSuccess { conv ->
                if (conv != null) {
                    conversations = listOf(conv) + conversations
                    openConversationId = conv.id
                    screen = AiScreen.CONVERSATION
                }
            }
        loading = false
    }

    LaunchedEffect(Unit) {
        if (bootstrapped) return@LaunchedEffect
        loading = true
        loadError = null
        val statusResult = runCatching { ApiClient.aiChat.status(chatAuth()).data }
        statusResult.onFailure { loadError = it.apiFailure().message }
        val available = statusResult.getOrNull()?.available ?: false
        status = statusResult.getOrNull()

        if (available) {
            val list = runCatching { ApiClient.aiChat.conversations(chatAuth()).data.orEmpty() }
                .onFailure { loadError = it.apiFailure().message }
                .getOrDefault(emptyList())
            conversations = list

            when {
                list.isEmpty() -> {
                    val created = runCatching { ApiClient.aiChat.createConversation(chatAuth()).data }.getOrNull()
                    if (created != null) {
                        conversations = listOf(created)
                        openConversationId = created.id
                        screen = AiScreen.CONVERSATION
                    }
                }
                else -> {
                    openConversationId = list.first().id
                    screen = AiScreen.CONVERSATION
                }
            }
        }

        loading = false
        bootstrapped = true
    }

    BackHandler(enabled = screen == AiScreen.LIST) { onExit() }

    Box(Modifier.fillMaxSize()) {
        when (screen) {
            AiScreen.CONVERSATION -> {
                val id = openConversationId
                if (id != null) {
                    AiConversationPage(
                        conversationId = id,
                        night = night,
                        onExit = onExit,
                        onOpenHistory = {
                            scope.launch { refreshConversations() }
                            screen = AiScreen.LIST
                        },
                        onNewChat = { scope.launch { startNewChat() } },
                        onOpenVoice = { screen = AiScreen.VOICE },
                        onOpenSubscription = { screen = AiScreen.SUBSCRIPTION },
                        onOpenSettings = { screen = AiScreen.SETTINGS },
                    )
                }
            }
            AiScreen.VOICE -> {
                AiVoiceScreen(
                    night = night,
                    conversationId = openConversationId,
                    onExit = { screen = AiScreen.CONVERSATION },
                )
            }
            AiScreen.SUBSCRIPTION -> {
                AiSubscriptionScreen(
                    night = night,
                    onExit = { screen = AiScreen.CONVERSATION },
                )
            }
            AiScreen.LANGUAGE -> {
                AiLanguageSettingsScreen(
                    night = night,
                    onExit = { screen = AiScreen.LIST },
                )
            }
            AiScreen.SITES -> {
                AiSitesScreen(
                    night = night,
                    onExit = { screen = AiScreen.SETTINGS },
                )
            }
            AiScreen.SETTINGS -> {
                AiSettingsScreen(
                    night = night,
                    onExit = { screen = AiScreen.CONVERSATION },
                    onOpenSubscription = { screen = AiScreen.SUBSCRIPTION },
                    onOpenLanguage = { screen = AiScreen.LANGUAGE },
                    onOpenSites = { screen = AiScreen.SITES },
                )
            }
            AiScreen.LIST -> {
                AiConversationListPage(
                    night = night,
                    loading = loading,
                    unavailable = status != null && status?.available == false,
                    brandName = status?.brandName ?: "DORR AI",
                    errorMessage = loadError,
                    conversations = conversations,
                    onBack = {
                        if (openConversationId != null) screen = AiScreen.CONVERSATION else onExit()
                    },
                    onRetry = { scope.launch { refreshConversations() } },
                    onOpen = { id -> openConversationId = id; screen = AiScreen.CONVERSATION },
                    onNewChat = { scope.launch { startNewChat() } },
                    onOpenSettings = { screen = AiScreen.SETTINGS },
                    onDelete = { id ->
                        scope.launch {
                            runCatching { ApiClient.aiChat.deleteConversation(chatAuth(), id) }
                            conversations = conversations.filterNot { it.id == id }
                            if (openConversationId == id) openConversationId = conversations.firstOrNull()?.id
                        }
                    },
                )
            }
        }
    }
}
