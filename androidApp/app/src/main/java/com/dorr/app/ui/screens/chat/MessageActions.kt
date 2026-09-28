package com.dorr.app.ui.screens.chat

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Reply
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Forward
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.PushPin
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.StarBorder
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.MessageDto
import kotlinx.coroutines.delay

private val QuickReactions = listOf("❤️", "😂", "👍", "😮", "😢", "🙏")
private val MoreReactions = listOf(
    "❤️", "😂", "👍", "😮", "😢", "🙏", "🔥", "🎉", "😍", "🥰", "😎", "🤔", "👏", "💯", "😅", "😡",
    "🤣", "😊", "😘", "😭", "🥺", "🙌", "💪", "👌", "✅", "❌", "💔", "🤝", "🌹", "⭐", "💸", "🎁",
)

/**
 * Long-press on a message: the conversation blurs and dims behind, the message lifts forward with a
 * spring, the reaction bar opens (each emoji popping in one after another) and the action menu
 * slides up under it. Tap outside to put it all back.
 */
@Composable
fun MessageFocusOverlay(state: ConversationState, onOpenInfo: (MessageDto) -> Unit) {
    val focused = state.focused ?: return
    val dto = focused.dto
    val context = LocalContext.current
    val host = LocalChat.current
    val copied = stringResource(R.string.ch_copied)
    var confirmDelete by remember { mutableStateOf(false) }
    var pinPicker by remember { mutableStateOf(false) }
    var forward by remember { mutableStateOf(false) }
    var allEmoji by remember { mutableStateOf(false) }

    val appear = remember { Animatable(0f) }
    LaunchedEffect(focused.id) { appear.animateTo(1f, spring(dampingRatio = 0.7f, stiffness = 420f)) }

    fun close() {
        state.focused = null
    }

    Box(
        Modifier
            .fillMaxSize()
            .background(Color.Black.copy(alpha = 0.42f * appear.value.coerceIn(0f, 1f)))
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { close() },
        contentAlignment = Alignment.Center,
    ) {
        Column(
            Modifier.fillMaxWidth().padding(horizontal = 18.dp),
            horizontalAlignment = if (focused.isMine) Alignment.End else Alignment.Start,
        ) {
            // ------------------------------------------------------------ reactions
            if (dto.type !in setOf("call", "system")) {
                Row(
                    Modifier
                        .graphicsLayer { val p = appear.value; scaleX = 0.6f + 0.4f * p; scaleY = 0.6f + 0.4f * p; alpha = p.coerceIn(0f, 1f) }
                        .shadow(16.dp, RoundedCornerShape(30.dp))
                        .clip(RoundedCornerShape(30.dp))
                        .background(Ch.Surface)
                        .padding(horizontal = 8.dp, vertical = 6.dp),
                    horizontalArrangement = Arrangement.spacedBy(2.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    QuickReactions.forEachIndexed { i, emoji ->
                        ReactionEmoji(emoji, i, selected = dto.reactions.mine == emoji) {
                            state.react(dto, if (dto.reactions.mine == emoji) null else emoji)
                            close()
                        }
                    }
                    Box(Modifier.size(38.dp).clip(CircleShape).background(Ch.SurfaceMuted).clickable { allEmoji = true }, contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Add, null, tint = Ch.Mut, modifier = Modifier.size(22.dp))
                    }
                }
                Spacer(Modifier.height(10.dp))
            }

            // ------------------------------------------------------------ the message itself
            Box(Modifier.graphicsLayer { val p = appear.value; scaleX = 0.94f + 0.06f * p; scaleY = 0.94f + 0.06f * p }.widthIn(max = 320.dp)) {
                MessageRow(focused.copy(fresh = false), firstInRun = true, lastInRun = true, isGroup = false, actions = NoActions)
            }
            Spacer(Modifier.height(10.dp))

            // ------------------------------------------------------------ menu
            val canEdit = focused.isMine && dto.type in setOf("text", "image", "video", "document")
            val canPin = dto.type !in setOf("system", "call")
            val canForward = dto.type !in setOf("wallet_transfer", "call", "system", "story_reply")
            Column(
                Modifier
                    .width(240.dp)
                    .graphicsLayer { val p = appear.value; translationY = (1f - p) * 40.dp.toPx(); alpha = p.coerceIn(0f, 1f) }
                    .shadow(16.dp, RoundedCornerShape(22.dp))
                    .clip(RoundedCornerShape(22.dp))
                    .background(Ch.Surface),
            ) {
                ActionRow(Icons.AutoMirrored.Rounded.Reply, stringResource(R.string.ch_reply)) { state.replyTo = dto; close() }
                if (!dto.body.isNullOrBlank()) ActionRow(Icons.Rounded.ContentCopy, stringResource(R.string.ch_copy)) {
                    (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("message", dto.body))
                    host.showToast(copied)
                    close()
                }
                if (canForward) ActionRow(Icons.Rounded.Forward, stringResource(R.string.ch_forward)) { forward = true }
                ActionRow(if (dto.isStarred) Icons.Rounded.Star else Icons.Rounded.StarBorder, stringResource(if (dto.isStarred) R.string.ch_unstar else R.string.ch_star)) { state.star(dto); close() }
                if (canPin) {
                    val isPinned = state.pinned.any { it.id == dto.id }
                    ActionRow(Icons.Rounded.PushPin, stringResource(if (isPinned) R.string.ch_unpin else R.string.ch_pin)) {
                        if (isPinned) { state.unpin(dto); close() } else pinPicker = true
                    }
                }
                if (canEdit) ActionRow(Icons.Rounded.Edit, stringResource(R.string.ch_edit)) { state.editing = dto; state.replyTo = null; close() }
                if (focused.isMine) ActionRow(Icons.Rounded.Info, stringResource(R.string.ch_info)) { onOpenInfo(dto); close() }
                ActionRow(Icons.Rounded.Delete, stringResource(R.string.ch_delete), danger = true) { confirmDelete = true }
            }
        }
    }

    if (confirmDelete) ChoiceSheet(
        title = stringResource(R.string.ch_delete_title),
        options = buildList {
            if (focused.isMine || state.conversation?.isAdmin == true && state.conversation?.isGroup == true) add(stringResource(R.string.ch_delete_for_everyone) to { state.deleteForEveryone(dto); Unit })
            add(stringResource(R.string.ch_delete_for_me) to { state.deleteForMe(dto) })
        },
        danger = true,
        onDismiss = { confirmDelete = false; close() },
    )
    if (pinPicker) ChoiceSheet(
        title = stringResource(R.string.ch_pin_for),
        options = listOf(
            stringResource(R.string.ch_pin_24h) to { state.pin(dto, 86400); Unit },
            stringResource(R.string.ch_pin_7d) to { state.pin(dto, 604800); Unit },
            stringResource(R.string.ch_pin_30d) to { state.pin(dto, 2592000); Unit },
        ),
        onDismiss = { pinPicker = false; close() },
    )
    if (forward) ForwardSheet(exclude = state.id, onDismiss = { forward = false; close() }) { targets ->
        state.forward(dto, targets) { ok -> if (ok) host.showToast(context.getString(R.string.ch_forwarded_done)) }
    }
    if (allEmoji) EmojiSheet(onDismiss = { allEmoji = false }) { emoji ->
        allEmoji = false
        state.react(dto, emoji)
        close()
    }
}

private val NoActions = BubbleActions({}, {}, {}, {}, { _, _ -> }, {}, {})

@Composable
private fun ReactionEmoji(emoji: String, index: Int, selected: Boolean, onClick: () -> Unit) {
    val pop = remember { Animatable(0f) }
    LaunchedEffect(Unit) {
        delay(60L + index * 38L)
        pop.animateTo(1f, spring(dampingRatio = 0.35f, stiffness = 500f))
    }
    Box(
        Modifier
            .size(40.dp)
            .graphicsLayer { scaleX = pop.value; scaleY = pop.value; translationY = (1f - pop.value) * 14.dp.toPx() }
            .clip(CircleShape)
            .background(if (selected) Ch.Red.copy(alpha = 0.14f) else Color.Transparent)
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Text(emoji, fontSize = 25.sp) }
}

@Composable
private fun ActionRow(icon: ImageVector, text: String, danger: Boolean = false, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(text, color = if (danger) Color(0xFFDC2626) else Ch.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
        Icon(icon, null, tint = if (danger) Color(0xFFDC2626) else Ch.Mut, modifier = Modifier.size(20.dp))
    }
}

/** A small sheet of choices (delete for me / for everyone, pin duration, mute duration…). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ChoiceSheet(title: String, options: List<Pair<String, () -> Unit>>, danger: Boolean = false, subtitle: String? = null, onDismiss: () -> Unit) {
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 30.dp)) {
            Text(title, color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            subtitle?.let { Text(it, color = Ch.Mut, fontSize = 13.5.sp, modifier = Modifier.padding(top = 4.dp)) }
            Spacer(Modifier.height(14.dp))
            options.forEachIndexed { i, (label, action) ->
                Box(
                    Modifier.fillMaxWidth().padding(vertical = 4.dp).chStagger(i).clip(RoundedCornerShape(16.dp))
                        .background(if (danger && i == 0) Color(0xFFFEF2F2) else Ch.SurfaceMuted)
                        .clickable { action(); onDismiss() }
                        .padding(horizontal = 16.dp, vertical = 15.dp),
                ) {
                    Text(label, color = if (danger) Color(0xFFDC2626) else Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
                }
            }
            Box(Modifier.fillMaxWidth().padding(top = 6.dp).clip(RoundedCornerShape(16.dp)).clickable(onClick = onDismiss).padding(15.dp), contentAlignment = Alignment.Center) {
                Text(stringResource(R.string.ch_cancel), color = Ch.Mut, fontWeight = FontWeight.Bold)
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun EmojiSheet(onDismiss: () -> Unit, onPick: (String) -> Unit) {
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        LazyVerticalGrid(GridCells.Fixed(8), Modifier.fillMaxWidth().heightIn(max = 360.dp).padding(horizontal = 12.dp).padding(bottom = 24.dp)) {
            items(MoreReactions) { emoji ->
                Box(Modifier.size(44.dp).clip(CircleShape).clickable { onPick(emoji) }, contentAlignment = Alignment.Center) { Text(emoji, fontSize = 26.sp) }
            }
        }
    }
}

/** Pick up to 5 chats to forward to; the send button rises in once something is selected. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ForwardSheet(exclude: String, onDismiss: () -> Unit, onSend: (List<String>) -> Unit) {
    val host = LocalChat.current
    val selected = remember { mutableStateListOf<String>() }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 24.dp)) {
            Text(stringResource(R.string.ch_forward_to), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(10.dp))
            LazyColumn(Modifier.heightIn(max = 440.dp)) {
                items(host.conversations.filter { it.id != exclude && it.canSend }, key = { it.id }) { c ->
                    ForwardRow(c, c.id in selected) {
                        if (c.id in selected) selected.remove(c.id) else if (selected.size < 5) selected.add(c.id)
                    }
                }
            }
            androidx.compose.animation.AnimatedVisibility(selected.isNotEmpty()) {
                ChPrimaryButton(stringResource(R.string.ch_forward) + " (${selected.size})", modifier = Modifier.fillMaxWidth().padding(top = 12.dp), icon = Icons.Rounded.Forward) {
                    onSend(selected.toList())
                    onDismiss()
                }
            }
        }
    }
}

@Composable
private fun ForwardRow(c: ConversationDto, checked: Boolean, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).clickable(onClick = onClick).padding(vertical = 8.dp, horizontal = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        ChAvatar(c.avatar, c.title, c.peer?.key ?: c.id, size = 44.dp, isGroup = c.isGroup)
        Spacer(Modifier.width(12.dp))
        Text(c.title.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
        androidx.compose.animation.AnimatedContent(checked, label = "check") { on ->
            Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) Ch.Red else Ch.Soft, modifier = Modifier.size(26.dp))
        }
    }
}
