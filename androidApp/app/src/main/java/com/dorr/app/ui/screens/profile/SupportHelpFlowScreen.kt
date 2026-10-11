package com.dorr.app.ui.screens.profile

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.MutableTransitionState
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.scaleIn
import androidx.compose.animation.slideInVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.SupportAgent
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.SupportAutoReplyFeedbackRequest
import com.dorr.app.network.SupportHelpNodeDto
import kotlinx.coroutines.launch
import com.dorr.app.ui.screens.AccountDark

/** One line of the guided conversation: what the assistant says, or what the customer picked. */
private sealed interface HelpLine {
    val key: String
    data class Bot(override val key: String, val text: String) : HelpLine
    data class Me(override val key: String, val text: String) : HelpLine
}

/**
 * The guided help that comes before a ticket: the assistant greets, shows the topics as a list, and each pick is
 * answered and either opens the topic's own sub-list or ends the path. At the end the customer has to choose one
 * of two buttons — "that solved it" (done, no ticket) or "I need an agent" (on to the ticket form, with the topic
 * already as its title). With no menu configured (or no connection) it goes straight to the ticket form.
 */
@Composable
fun SupportHelpFlowScreen(
    onBack: () -> Unit,
    onSolved: () -> Unit,
    onNeedAgent: (topic: String?) -> Unit,
    /** The topics picked before (ids from the main menu down), so coming back from the ticket form lands where it left. */
    initialPath: List<Int> = emptyList(),
    onPathChange: (List<Int>) -> Unit = {},
) {
    val accent = settingsAccent()
    // The quick chat gets the whole screen: the main bottom bar steps aside while it is open (like a support chat).
    androidx.compose.runtime.DisposableEffect(Unit) {
        SupportChatState.open = true
        onDispose { SupportChatState.open = false }
    }
    var loading by remember { mutableStateOf(true) }
    var greeting by remember { mutableStateOf("") }
    var root by remember { mutableStateOf<List<SupportHelpNodeDto>>(emptyList()) }
    // The topics picked so far, from the main menu down.
    var steps by remember { mutableStateOf<List<SupportHelpNodeDto>>(emptyList()) }
    val listState = rememberLazyListState()
    val scope = kotlinx.coroutines.CoroutineScope(kotlinx.coroutines.Dispatchers.IO)

    // What the customer pressed is counted for the team (best effort: it never holds the flow up).
    fun report(topic: SupportHelpNodeDto?, solved: Boolean) {
        val id = topic?.id ?: return
        scope.launch {
            runCatching { ApiClient.support.helpFeedback("Bearer ${AuthSession.token.orEmpty()}", id, SupportAutoReplyFeedbackRequest(solved)) }
        }
    }

    LaunchedEffect(Unit) {
        val data = runCatching { ApiClient.support.help("Bearer ${AuthSession.token.orEmpty()}").data }.getOrNull()
        if (data == null || data.items.isEmpty()) {
            onNeedAgent(null)
        } else {
            greeting = data.greeting.orEmpty()
            root = data.items
            // Back to where the customer was: walk the saved path down the tree.
            var level = data.items
            val restored = mutableListOf<SupportHelpNodeDto>()
            for (id in initialPath) {
                val node = level.firstOrNull { it.id == id } ?: break
                restored += node
                level = node.children
            }
            steps = restored
            loading = false
        }
    }

    LaunchedEffect(steps, loading) { if (!loading) onPathChange(steps.map { it.id }) }

    // Back steps up the menu one topic at a time; at the top it leaves the flow.
    BackHandler { if (steps.isEmpty()) onBack() else steps = steps.dropLast(1) }

    val current = steps.lastOrNull()
    val options = if (current == null) root else current.children
    val finished = current != null && current.children.isEmpty()

    val lines = buildList<HelpLine> {
        if (greeting.isNotBlank()) add(HelpLine.Bot("greeting", greeting))
        steps.forEach { step ->
            add(HelpLine.Me("me-${step.id}", step.title.orEmpty()))
            step.answer?.takeIf { it.isNotBlank() }?.let { add(HelpLine.Bot("bot-${step.id}", it)) }
        }
    }

    LaunchedEffect(steps.size, loading) {
        // The newest line (and the options under it) stay in view.
        if (!loading) listState.animateScrollToItem(lines.size + 1)
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(R.string.support_help_title), onBack)
            if (loading) {
                Box(Modifier.weight(1f).fillMaxWidth(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = accent, strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
                }
            } else {
                LazyColumn(
                    state = listState,
                    modifier = Modifier.weight(1f).fillMaxWidth(),
                    contentPadding = PaddingValues(horizontal = 14.dp, vertical = 8.dp),
                    verticalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    items(lines.size, key = { lines[it].key }) { index ->
                        when (val line = lines[index]) {
                            is HelpLine.Bot -> HelpBubble(line.text, mine = false)
                            is HelpLine.Me -> HelpBubble(line.text, mine = true)
                        }
                    }
                    item("tail") {
                        if (finished) {
                            FinalChoice(
                                onSolved = { report(current, true); onSolved() },
                                onNeedAgent = { report(current, false); onNeedAgent(current?.title) },
                            )
                        } else {
                            OptionsCard(options) { picked -> steps = steps + picked }
                        }
                    }
                    item("space") { Spacer(Modifier.height(12.dp).navigationBarsPadding()) }
                }
            }
        }
    }
}

@Composable
private fun HelpBubble(text: String, mine: Boolean) {
    val shape = RoundedCornerShape(
        topStart = 20.dp, topEnd = 20.dp,
        bottomStart = if (mine) 20.dp else 5.dp,
        bottomEnd = if (mine) 5.dp else 20.dp,
    )
    val visible = remember(text) { MutableTransitionState(false).apply { targetState = true } }
    AnimatedVisibility(
        visibleState = visible,
        enter = fadeIn(tween(220)) + scaleIn(tween(220), initialScale = 0.94f) + slideInVertically(tween(220)) { it / 6 },
    ) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = if (mine) Arrangement.End else Arrangement.Start) {
            Column(
                Modifier
                    .widthIn(max = 300.dp)
                    .clip(shape)
                    .background(if (mine) settingsAccent() else if (settingsNight()) AccountDark.card else settingsCard())
                    .then(if (mine) Modifier else Modifier.border(1.dp, settingsMut().copy(alpha = 0.18f), shape))
                    .padding(horizontal = 14.dp, vertical = 10.dp),
            ) {
                Text(text, color = if (mine) androidx.compose.ui.graphics.Color.White else settingsInk(), fontSize = 14.5.sp, lineHeight = 21.sp, style = contentDirection())
            }
        }
    }
}

/** The topics as one rounded card of rows with a chevron, like the help menus of the delivery apps. */
@Composable
private fun OptionsCard(options: List<SupportHelpNodeDto>, onPick: (SupportHelpNodeDto) -> Unit) {
    val shape = RoundedCornerShape(22.dp)
    Column(
        Modifier
            .fillMaxWidth()
            .clip(shape)
            .background(if (settingsNight()) AccountDark.card else settingsCard())
            .border(1.dp, settingsMut().copy(alpha = 0.18f), shape),
    ) {
        options.forEachIndexed { index, option ->
            Row(
                Modifier
                    .fillMaxWidth()
                    .clickable { onPick(option) }
                    .padding(horizontal = 18.dp, vertical = 16.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(option.title.orEmpty(), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f), style = contentDirection())
                Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = settingsInk(), modifier = Modifier.size(22.dp))
            }
            if (index != options.lastIndex) {
                Box(Modifier.fillMaxWidth().height(1.dp).background(settingsMut().copy(alpha = 0.15f)))
            }
        }
    }
}

/** The end of the path: there is no way on but one of these two. */
@Composable
private fun FinalChoice(onSolved: () -> Unit, onNeedAgent: () -> Unit) {
    val accent = settingsAccent()
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(stringResource(R.string.support_help_final_question), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(horizontal = 4.dp), style = contentDirection())
        Box(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(accent).clickable(onClick = onSolved).padding(vertical = 15.dp),
            contentAlignment = Alignment.Center,
        ) { Text(stringResource(R.string.support_help_solved), color = androidx.compose.ui.graphics.Color.White, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold) }
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(accent.copy(alpha = 0.12f)).clickable(onClick = onNeedAgent).padding(vertical = 15.dp),
            horizontalArrangement = Arrangement.Center,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.SupportAgent, null, tint = accent, modifier = Modifier.size(20.dp))
            Spacer(Modifier.size(8.dp))
            Text(stringResource(R.string.support_help_need_agent), color = accent, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
        }
    }
}

/** Text lines up by its own language: Arabic sits on the right edge, English on the left, whatever the screen's direction. */
@Composable
private fun contentDirection() = androidx.compose.material3.LocalTextStyle.current.copy(
    textAlign = androidx.compose.ui.text.style.TextAlign.Start,
    textDirection = androidx.compose.ui.text.style.TextDirection.Content,
)
