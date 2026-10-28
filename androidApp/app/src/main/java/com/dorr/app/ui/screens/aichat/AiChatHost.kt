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
import androidx.compose.ui.res.stringResource
import com.dorr.app.R
import com.dorr.app.network.AiConversationDto
import com.dorr.app.network.AiStatusDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.theme.LocalThemeState
import kotlinx.coroutines.launch

private enum class AiScreen { HOME, LIST, CONVERSATION, VOICE, SUBSCRIPTION, LANGUAGE, SITES, WORLD, AR, SETTINGS, SEARCH, MEMORY, OUTPUTS, PROJECTS }

/**
 * Owns the AI Assistant flow end to end. It opens on the DORR AI home ([AiHomeScreen], 2026-10-15):
 * every service the assistant offers, grouped in the order people reach for it, instead of dropping
 * the person straight into their last conversation. Each screen goes back to the one it was opened
 * from (a small back stack); back on the home leaves the assistant. This is the destination the
 * Home tab's central "+" button (MainScreen's FAB) opens.
 */
@Composable
fun AiChatHost(onExit: () -> Unit) {
    val night = LocalThemeState.current.isDark ?: isSystemInDarkTheme()
    val scope = rememberCoroutineScope()

    var stack by rememberSaveable { mutableStateOf(listOf(AiScreen.HOME)) }
    val screen = stack.last()
    var conversations by remember { mutableStateOf<List<AiConversationDto>>(emptyList()) }
    var openConversationId by rememberSaveable { mutableStateOf<Int?>(null) }
    var status by remember { mutableStateOf<AiStatusDto?>(null) }
    var loading by remember { mutableStateOf(true) }
    var loadError by remember { mutableStateOf<String?>(null) }
    var bootstrapped by rememberSaveable { mutableStateOf(false) }
    // A search or gallery result opens its conversation at that message (spec 138).
    var focusMessageId by rememberSaveable { mutableStateOf<Int?>(null) }
    // A home starter opens a new chat already saying what to do (and maybe the file picker).
    var starterText by rememberSaveable { mutableStateOf<String?>(null) }
    var starterAttach by rememberSaveable { mutableStateOf(false) }
    var continuing by remember { mutableStateOf(false) }

    fun go(next: AiScreen) {
        stack = stack + next
    }

    fun back() {
        if (stack.size > 1) stack = stack.dropLast(1) else onExit()
    }

    fun openAt(conversationId: Int, messageId: Int?) {
        openConversationId = conversationId
        focusMessageId = messageId
        starterText = null
        starterAttach = false
        // Replace a conversation already on top instead of stacking chat on chat.
        stack = (if (stack.last() == AiScreen.CONVERSATION) stack.dropLast(1) else stack) + AiScreen.CONVERSATION
    }

    suspend fun refreshConversations() {
        runCatching { ApiClient.aiChat.conversations(chatAuth()).data.orEmpty() }
            .onSuccess { conversations = it; loadError = null }
            .onFailure { loadError = it.apiFailure().message }
    }

    suspend fun load() {
        loading = true
        loadError = null
        val statusResult = runCatching { ApiClient.aiChat.status(chatAuth()).data }
        statusResult.onFailure { loadError = it.apiFailure().message }
        status = statusResult.getOrNull()
        if (status?.available == true) refreshConversations()
        loading = false
    }

    suspend fun startNewChat(text: String? = null, attach: Boolean = false) {
        loading = true
        runCatching { ApiClient.aiChat.createConversation(chatAuth()).data }
            .onSuccess { conv ->
                if (conv != null) {
                    conversations = listOf(conv) + conversations
                    openAt(conv.id, null)
                    starterText = text
                    starterAttach = attach
                }
            }
            .onFailure { loadError = it.apiFailure().message }
        loading = false
    }

    LaunchedEffect(Unit) {
        if (bootstrapped) return@LaunchedEffect
        load()
        bootstrapped = true
    }

    // "Subscribe" on the plan prompt (AiPlanGate): straight to the subscription page.
    LaunchedEffect(AiPlanGate.openSubscription, bootstrapped) {
        if (AiPlanGate.openSubscription && bootstrapped) {
            AiPlanGate.openSubscription = false
            go(AiScreen.SUBSCRIPTION)
        }
    }

    // The starters' opening words (each one a phrase the server recognises — see AiHomeScreen).
    val startImage = stringResource(R.string.ai_home_prompt_image)
    val startVideo = stringResource(R.string.ai_home_prompt_video)
    val startDocument = stringResource(R.string.ai_home_prompt_document)
    val startTranslate = stringResource(R.string.ai_home_prompt_translate)
    val startCode = stringResource(R.string.ai_home_prompt_code)
    val startAnalyze = stringResource(R.string.ai_home_prompt_analyze)

    fun onHomeAction(action: AiHomeAction) {
        when (action) {
            AiHomeAction.NEW_CHAT -> scope.launch { startNewChat() }
            AiHomeAction.IMAGE -> scope.launch { startNewChat(startImage) }
            AiHomeAction.VIDEO -> scope.launch { startNewChat(startVideo) }
            AiHomeAction.DOCUMENT -> scope.launch { startNewChat(startDocument) }
            AiHomeAction.TRANSLATE -> scope.launch { startNewChat(startTranslate) }
            AiHomeAction.CODE -> scope.launch { startNewChat(startCode) }
            AiHomeAction.ANALYZE -> scope.launch { startNewChat(startAnalyze, attach = true) }
            AiHomeAction.VOICE -> go(AiScreen.VOICE)
            AiHomeAction.SITES -> go(AiScreen.SITES)
            AiHomeAction.WORLD -> go(AiScreen.WORLD)
            AiHomeAction.AR -> go(AiScreen.AR)
            AiHomeAction.HISTORY -> { scope.launch { refreshConversations() }; go(AiScreen.LIST) }
            AiHomeAction.SEARCH -> go(AiScreen.SEARCH)
            AiHomeAction.PROJECTS -> { scope.launch { refreshConversations() }; go(AiScreen.PROJECTS) }
            AiHomeAction.OUTPUTS -> go(AiScreen.OUTPUTS)
            AiHomeAction.CONTINUE -> { scope.launch { refreshConversations() }; continuing = true }
            AiHomeAction.MEMORY -> go(AiScreen.MEMORY)
            AiHomeAction.LANGUAGE -> go(AiScreen.LANGUAGE)
            AiHomeAction.SUBSCRIPTION -> go(AiScreen.SUBSCRIPTION)
            AiHomeAction.SETTINGS -> go(AiScreen.SETTINGS)
        }
    }

    // The phone's back follows the same stack (screens with their own handler call back() too).
    BackHandler(enabled = stack.size > 1) { back() }

    Box(Modifier.fillMaxSize()) {
        when (screen) {
            AiScreen.HOME -> {
                AiHomeScreen(
                    night = night,
                    status = status,
                    loading = loading,
                    errorMessage = loadError,
                    conversations = conversations,
                    onExit = onExit,
                    onRetry = { scope.launch { load() } },
                    onAction = ::onHomeAction,
                    onOpenConversation = { id -> openAt(id, null) },
                )
            }
            AiScreen.CONVERSATION -> {
                val id = openConversationId
                if (id != null) {
                    AiConversationPage(
                        conversationId = id,
                        night = night,
                        onExit = { scope.launch { refreshConversations() }; back() },
                        onOpenHistory = {
                            scope.launch { refreshConversations() }
                            go(AiScreen.LIST)
                        },
                        onNewChat = { scope.launch { startNewChat() } },
                        onOpenVoice = { go(AiScreen.VOICE) },
                        onOpenSubscription = { go(AiScreen.SUBSCRIPTION) },
                        onOpenSettings = { go(AiScreen.SETTINGS) },
                        focusMessageId = focusMessageId,
                        initialInput = starterText,
                        openAttachments = starterAttach,
                    )
                }
            }
            AiScreen.SEARCH -> {
                AiSearchScreen(night = night, onExit = ::back, onOpen = { id, messageId -> openAt(id, messageId) })
            }
            AiScreen.MEMORY -> {
                AiMemoryScreen(night = night, onExit = ::back)
            }
            AiScreen.OUTPUTS -> {
                AiOutputsScreen(night = night, onExit = ::back, onOpenConversation = { id, messageId -> openAt(id, messageId) })
            }
            AiScreen.PROJECTS -> {
                AiProjectsScreen(
                    night = night,
                    conversations = conversations,
                    onExit = ::back,
                    onOpenConversation = { id -> openAt(id, null) },
                )
            }
            AiScreen.VOICE -> {
                AiVoiceScreen(
                    night = night,
                    conversationId = openConversationId,
                    onExit = ::back,
                )
            }
            AiScreen.SUBSCRIPTION -> {
                AiSubscriptionScreen(
                    night = night,
                    // Coming back, the plan chip and the AI buttons reflect the new plan.
                    onExit = { scope.launch { load() }; back() },
                )
            }
            AiScreen.LANGUAGE -> {
                AiLanguageSettingsScreen(
                    night = night,
                    onExit = ::back,
                )
            }
            AiScreen.SITES -> {
                AiSitesScreen(
                    night = night,
                    onExit = ::back,
                )
            }
            AiScreen.WORLD -> {
                AiWorldScreen(
                    night = night,
                    onExit = ::back,
                )
            }
            AiScreen.AR -> {
                AiArScreen(
                    night = night,
                    onExit = ::back,
                )
            }
            AiScreen.SETTINGS -> {
                AiSettingsScreen(
                    night = night,
                    onExit = ::back,
                    onOpenSubscription = { go(AiScreen.SUBSCRIPTION) },
                    onOpenLanguage = { go(AiScreen.LANGUAGE) },
                    onOpenSites = { go(AiScreen.SITES) },
                    onOpenWorld = { go(AiScreen.WORLD) },
                    onOpenAr = { go(AiScreen.AR) },
                    onOpenMemory = { go(AiScreen.MEMORY) },
                    onOpenProjects = {
                        scope.launch { refreshConversations() }
                        go(AiScreen.PROJECTS)
                    },
                    onOpenOutputs = { go(AiScreen.OUTPUTS) },
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
                    onBack = ::back,
                    onRetry = { scope.launch { refreshConversations() } },
                    onOpen = { id -> openAt(id, null) },
                    onNewChat = { scope.launch { startNewChat() } },
                    onOpenSettings = { go(AiScreen.SETTINGS) },
                    onOpenSearch = { go(AiScreen.SEARCH) },
                    onContinue = { continuing = true },
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

        if (continuing) {
            AiContinueDialog(
                night = night,
                conversations = conversations,
                onDismiss = { continuing = false },
                onStarted = { created ->
                    continuing = false
                    conversations = listOf(created) + conversations
                    openAt(created.id, null)
                },
            )
        }
    }
}
