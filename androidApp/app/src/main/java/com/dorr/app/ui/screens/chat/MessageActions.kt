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
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Reply
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.MoreVert
import androidx.compose.material3.DropdownMenu
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.unit.LayoutDirection
import com.dorr.app.ui.components.DarkHeroButton
import com.dorr.app.ui.components.DarkHeroPanel
import com.dorr.app.ui.locale.rememberAppLocaleFrame
import androidx.compose.material.icons.rounded.Psychology
import androidx.compose.material.icons.rounded.ShortText
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.AddTask
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Redeem
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material.icons.rounded.Forum
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Forward
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.PushPin
import androidx.compose.material.icons.rounded.Bolt
import androidx.compose.material.icons.rounded.RadioButtonChecked
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Alarm
import androidx.compose.material.icons.rounded.BookmarkAdd
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.NoteAdd
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Translate
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
import androidx.compose.ui.AbsoluteAlignment
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
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
 * Long-press on a message: the conversation blurs and dims behind, a bar opens at the top (back, reply, forward,
 * star, reminder, pin, delete, and ⋮ for everything else), the message lifts forward with a spring and the
 * reaction bar opens under it. Tap outside to put it all back.
 */
@Composable
fun MessageFocusOverlay(state: ConversationState, onOpenInfo: (MessageDto) -> Unit) {
    val focused = state.focused ?: return
    val dto = focused.dto
    val context = LocalContext.current
    val host = LocalChat.current
    val locale = rememberAppLocaleFrame()
    val copied = stringResource(R.string.ch_copied)
    val decisionMade = stringResource(R.string.ch_decision_made)
    val savedToNotes = stringResource(R.string.ch_saved_to_notes)
    var reminding by remember { mutableStateOf(false) }
    var toAction by remember { mutableStateOf(false) }
    val noteSaved = stringResource(R.string.ct_note_saved)
    var confirmDelete by remember { mutableStateOf(false) }
    var pinPicker by remember { mutableStateOf(false) }
    // All pin places taken: ask before the new one replaces the oldest (as WhatsApp does).
    var replaceOldestPin by remember { mutableStateOf(false) }
    var forward by remember { mutableStateOf(false) }
    var capsule by remember { mutableStateOf(false) }
    var starInto by remember { mutableStateOf(false) }
    var allEmoji by remember { mutableStateOf(false) }
    var moreOpen by remember { mutableStateOf(false) }

    val appear = remember { Animatable(0f) }
    LaunchedEffect(focused.id) { appear.animateTo(1f, spring(dampingRatio = 0.7f, stiffness = 420f)) }

    fun close() {
        state.focused = null
    }

    // Only what the server will still accept (the admin sets both time limits).
    val canEdit = focused.isMine && dto.type in setOf("text", "image", "video", "document") && stillOpen(dto.editUntil)
    val canPin = dto.type !in setOf("system", "call")
    val canForward = dto.type !in setOf("wallet_transfer", "call", "system", "story_reply")
    val isPinned = state.pinned.any { it.id == dto.id }

    androidx.compose.foundation.layout.BoxWithConstraints(
        Modifier
            .fillMaxSize()
            .background(Color.Black.copy(alpha = 0.42f * appear.value.coerceIn(0f, 1f)))
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { close() },
        contentAlignment = Alignment.Center,
    ) {
        val screen = maxHeight
        Column(
            Modifier.fillMaxWidth().heightIn(max = screen).padding(start = 18.dp, end = 18.dp, top = 96.dp, bottom = 14.dp),
            horizontalAlignment = if (focused.isMine) Alignment.End else Alignment.Start,
            verticalArrangement = Arrangement.Center,
        ) {
            // ------------------------------------------------------------ the message itself
            // A long message scrolls in at most 45% of the screen.
            Box(
                Modifier.graphicsLayer { val p = appear.value; scaleX = 0.94f + 0.06f * p; scaleY = 0.94f + 0.06f * p }.widthIn(max = 320.dp)
                    .weight(1f, fill = false).heightIn(max = screen * 0.45f).verticalScroll(rememberScrollState()),
            ) {
                MessageRow(focused.copy(fresh = false), firstInRun = true, lastInRun = true, isGroup = false, actions = NoActions)
            }
            Spacer(Modifier.height(10.dp))

            // ------------------------------------------------------------ reactions, under the message
            if (dto.type !in setOf("call", "system") && state.conversation?.takeIf { it.isChannel }?.group?.allowedReactions?.isEmpty() != true) {
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
                    val allowed = state.conversation?.takeIf { it.isChannel }?.group?.allowedReactions
                    (allowed?.take(8) ?: QuickReactions).forEachIndexed { i, emoji ->
                        ReactionEmoji(emoji, i, selected = dto.reactions.mine == emoji) {
                            state.react(dto, if (dto.reactions.mine == emoji) null else emoji)
                            close()
                        }
                    }
                    if (allowed == null) Box(Modifier.size(38.dp).clip(CircleShape).background(Ch.SurfaceMuted).clickable { allEmoji = true }, contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Add, null, tint = Ch.Mut, modifier = Modifier.size(22.dp))
                    }
                }
            }
        }

        // ---------------------------------------------------------------- the bar at the top
        Box(
            Modifier
                .align(Alignment.TopCenter)
                .fillMaxWidth()
                .padding(horizontal = 16.dp)
                .graphicsLayer { translationY = (1f - appear.value.coerceIn(0f, 1f)) * -40.dp.toPx(); alpha = appear.value.coerceIn(0f, 1f) }
                .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) {},
        ) {
            DarkHeroPanel {
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
                        DarkHeroButton(Icons.AutoMirrored.Rounded.ArrowBack, { close() }, stringResource(R.string.common_back))
                        DarkHeroButton(Icons.AutoMirrored.Rounded.Reply, { state.replyTo = dto; close() }, stringResource(R.string.ch_reply))
                        if (canForward) DarkHeroButton(Icons.Rounded.Forward, { forward = true }, stringResource(R.string.ch_forward))
                        DarkHeroButton(
                            if (dto.isStarred) Icons.Rounded.Star else Icons.Rounded.StarBorder,
                            { if (dto.isStarred) { state.star(dto); close() } else starInto = true },
                            stringResource(if (dto.isStarred) R.string.ch_unstar else R.string.ch_star),
                        )
                        if (dto.type != "system") DarkHeroButton(
                            Icons.Rounded.Alarm,
                            { if (dto.reminderAt != null) { state.remind(dto, null); close() } else reminding = true },
                            stringResource(if (dto.reminderAt != null) R.string.ch_reminder_remove else R.string.ch_reminder),
                        )
                        if (canPin) DarkHeroButton(
                            Icons.Rounded.PushPin,
                            {
                                when {
                                    isPinned -> { state.unpin(dto); close() }
                                    state.pinned.size >= (state.conversation?.maxPinnedMessages ?: Int.MAX_VALUE) -> replaceOldestPin = true
                                    else -> pinPicker = true
                                }
                            },
                            stringResource(if (isPinned) R.string.ch_unpin else R.string.ch_pin),
                        )
                        DarkHeroButton(Icons.Rounded.Delete, { confirmDelete = true }, stringResource(R.string.ch_delete))
                        DarkHeroButton(Icons.Rounded.MoreVert, { moreOpen = true }, stringResource(R.string.ch_share_more))
                    }
                }
            }
            // The ⋮ menu opens from the bar's bottom edge, like the conversation header's menus.
            Box(Modifier.align(AbsoluteAlignment.BottomRight)) {
                DropdownMenu(
                    expanded = moreOpen,
                    onDismissRequest = { moreOpen = false },
                    shape = RoundedCornerShape(18.dp),
                    containerColor = Ch.Surface,
                ) {
                    locale.Provide {
                        val aiText = !dto.body.isNullOrBlank() && !dto.viewOnce && !dto.sealed && dto.type != "system"
                        // A side discussion under this message (spec 122).
                        if (dto.type !in setOf("system", "call") && !dto.isDeleted && dto.threadRoot == null) MenuItem(Icons.Rounded.Forum, stringResource(R.string.ch_thread_reply)) {
                            moreOpen = false
                            close()
                            host.push(ChRoute.Thread(dto.conversationId, dto.id))
                        }
                        // Into something to do (spec 148): commitment, handoff, agreement, task, appointment, reminder, decision.
                        if (dto.type !in setOf("system", "call", "commitment", "handoff", "agreement") && !dto.isDeleted && state.conversation?.isChannel != true) MenuItem(Icons.Rounded.Bolt, stringResource(R.string.lf_turn_into)) { moreOpen = false; toAction = true }
                        // In a group: put it to a vote, for the decisions log (spec 119–120).
                        if (state.conversation?.type == "group" && dto.type !in setOf("system", "call", "poll") && !dto.isDeleted) MenuItem(Icons.Rounded.Gavel, stringResource(R.string.ch_make_decision)) {
                            moreOpen = false
                            close()
                            host.makeDecision(dto, decisionMade)
                        }
                        if (!dto.body.isNullOrBlank()) MenuItem(Icons.Rounded.ContentCopy, stringResource(R.string.ch_copy)) {
                            (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).setPrimaryClip(ClipData.newPlainText("message", dto.body))
                            host.showToast(copied)
                            moreOpen = false
                            close()
                        }
                        // AI, on a tap: a question about this message (spec 125).
                        if (ChatAi.ask && !dto.body.isNullOrBlank() && !dto.viewOnce && dto.type != "system") {
                            MenuItem(Icons.Rounded.AutoAwesome, stringResource(R.string.ch_ai_ask_this)) { moreOpen = false; state.askAbout = listOf(dto.id); close() }
                        }
                        // DORR AI tools on this message (spec 37–39, 42, 46) — each one tap, suggestions only.
                        if (aiText && !focused.isMine && ChatAi.understand) MenuItem(Icons.Rounded.Psychology, stringResource(R.string.ct_understand)) { moreOpen = false; state.aiTool = "understand" to dto; close() }
                        if (aiText && ChatAi.simplify && dto.body.orEmpty().length > 220) MenuItem(Icons.Rounded.ShortText, stringResource(R.string.ct_simplify)) { moreOpen = false; state.aiTool = "simplify" to dto; close() }
                        if (aiText && ChatAi.tasks) MenuItem(Icons.Rounded.TaskAlt, stringResource(R.string.ct_make_tasks)) { moreOpen = false; state.aiTool = "tasks" to dto; close() }
                        // A work group (or one of its projects): make this message a team task (GR-038).
                        val g = state.conversation?.group
                        if (aiText && !dto.isDeleted && (g?.kind == "work" || g?.space?.type == "project")) {
                            MenuItem(Icons.Rounded.AddTask, stringResource(R.string.gt_make_task)) { moreOpen = false; state.groupTaskFrom = dto; close() }
                        }
                        // An image in the chat, changed as I describe (spec 51) — a preview first, sent only if I want.
                        if (ChatAi.imageEdit && dto.type == "image" && !dto.viewOnce && !dto.sealed && !dto.isDeleted) MenuItem(Icons.Rounded.AutoAwesome, stringResource(R.string.ch_ai_image_edit_title)) { moreOpen = false; state.aiTool = "edit_image" to dto; close() }
                        if (aiText && ChatAi.notes) MenuItem(Icons.Rounded.Description, stringResource(R.string.ct_make_note)) { moreOpen = false; state.aiNote(listOf(dto.id), noteSaved); close() }
                        // AI, on a tap: translate the text (never a view-once message).
                        if (ChatAi.translate && !dto.body.isNullOrBlank() && !dto.viewOnce && dto.type != "system") {
                            MenuItem(Icons.Rounded.Translate, stringResource(R.string.ch_ai_translate)) { moreOpen = false; state.translate(focused); close() }
                        }
                        // Set it aside to come back to, or keep a copy in my own notes.
                        if (dto.type != "system") MenuItem(Icons.Rounded.BookmarkAdd, stringResource(if (dto.isReadLater) R.string.ch_read_later_done else R.string.ch_read_later)) { moreOpen = false; state.readLater(dto); close() }
                        if (canForward && !dto.viewOnce && state.conversation?.isSelf != true) MenuItem(Icons.Rounded.NoteAdd, stringResource(R.string.ch_save_to_notes)) { moreOpen = false; state.saveToNotes(dto, savedToNotes); close() }
                        // DORR Moments capsules: keep a copy of it in an album for an occasion.
                        if (!dto.viewOnce && !dto.sealed && dto.type !in setOf("system", "call")) MenuItem(Icons.Rounded.Inventory2, stringResource(R.string.mo_capsule_keep)) { moreOpen = false; capsule = true }
                        // Someone else's message I owe an answer to.
                        if (!focused.isMine && dto.type != "system") MenuItem(Icons.Rounded.Flag, stringResource(if (dto.isFollowUp) R.string.ch_needs_reply_done else R.string.ch_needs_reply)) { moreOpen = false; state.followUp(dto); close() }
                        if (canEdit) MenuItem(Icons.Rounded.Edit, stringResource(R.string.ch_edit)) { moreOpen = false; state.editing = dto; state.replyTo = null; close() }
                        if (focused.isMine) MenuItem(Icons.Rounded.Info, stringResource(R.string.ch_info)) { moreOpen = false; onOpenInfo(dto); close() }
                    }
                }
            }
        }
    }

    if (reminding) ScheduleSheet(onDismiss = { reminding = false; close() }) { at -> state.remind(dto, at) }
    if (toAction) ToActionSheet(state, dto, onDismiss = { toAction = false; close() }, onReminder = { reminding = true })
    if (confirmDelete) ChoiceSheet(
        title = stringResource(R.string.ch_delete_title),
        options = buildList {
            // Mine within the time limit, or anyone's when I'm a group admin.
            val forEveryone = if (focused.isMine) stillOpen(dto.deleteUntil) else state.conversation?.isAdmin == true && state.conversation?.isGroup == true
            if (forEveryone) add(stringResource(R.string.ch_delete_for_everyone) to { state.deleteForEveryone(dto); Unit })
            add(stringResource(R.string.ch_delete_for_me) to { state.deleteForMe(dto) })
        },
        danger = true,
        onDismiss = { confirmDelete = false; close() },
    )
    if (replaceOldestPin) com.dorr.app.ui.screens.ConfirmDialog(
        icon = Icons.Rounded.PushPin,
        title = stringResource(R.string.ch_pin_replace_title),
        message = stringResource(R.string.ch_pin_replace_text),
        confirmLabel = stringResource(R.string.ch_continue),
        onConfirm = { replaceOldestPin = false; pinPicker = true },
        onDismiss = { replaceOldestPin = false; close() },
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
    if (capsule) com.dorr.app.ui.screens.moments.CapsulePickerSheet(dto.id) { capsule = false; close() }
    if (starInto) StarFolderSheet(onDismiss = { starInto = false; close() }) { folder -> state.star(dto, folder) }
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

/** A small sheet of choices (delete for me / for everyone, pin duration, mute duration…). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ChoiceSheet(
    title: String,
    options: List<Pair<String, () -> Unit>>,
    danger: Boolean = false,
    subtitle: String? = null,
    radios: Boolean = false,
    selected: Int? = null,
    values: List<String?>? = null,
    fixedHeight: Dp? = null,
    onDismiss: () -> Unit,
) {
    var picked by remember { mutableStateOf(selected) }
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        @Composable
        fun optionRow(i: Int, label: String, action: () -> Unit) {
            Row(
                Modifier.fillMaxWidth().padding(vertical = 4.dp).chStagger(i).clip(RoundedCornerShape(16.dp))
                    .background(if (danger && i == 0) Ch.Danger.copy(alpha = 0.1f) else Ch.SurfaceMuted)
                    .clickable { picked = i; action(); onDismiss() }
                    .padding(horizontal = 16.dp, vertical = 15.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                val hint = values?.getOrNull(i)
                Text(
                    buildAnnotatedString {
                        append(label)
                        if (!hint.isNullOrBlank()) {
                            withStyle(SpanStyle(fontSize = 12.sp, fontWeight = FontWeight.Normal, color = Ch.Mut)) {
                                append(" ($hint)")
                            }
                        }
                    },
                    color = if (danger) Ch.Danger else Ch.Ink,
                    fontWeight = FontWeight.Bold,
                    fontSize = 15.sp,
                    modifier = Modifier.weight(1f),
                )
                if (radios) {
                    val on = picked == i
                    Icon(
                        if (on) Icons.Rounded.RadioButtonChecked else Icons.Rounded.RadioButtonUnchecked,
                        null,
                        tint = if (on) Ch.Red else Ch.Soft,
                        modifier = Modifier.size(22.dp),
                    )
                }
            }
        }
        Column(
            Modifier
                .fillMaxWidth()
                .then(if (fixedHeight != null) Modifier.height(fixedHeight) else Modifier)
                .navigationBarsPadding()
                .padding(horizontal = 20.dp)
                .padding(bottom = 24.dp),
        ) {
            Text(title, color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            subtitle?.let { Text(it, color = Ch.Mut, fontSize = 13.5.sp, modifier = Modifier.padding(top = 4.dp)) }
            Spacer(Modifier.height(14.dp))
            if (fixedHeight != null) {
                LazyColumn(Modifier.weight(1f).fillMaxWidth()) {
                    itemsIndexed(options) { i, (label, action) -> optionRow(i, label, action) }
                }
            } else {
                options.forEachIndexed { i, (label, action) -> optionRow(i, label, action) }
            }
            Box(
                Modifier
                    .fillMaxWidth()
                    .padding(top = 10.dp)
                    .clip(RoundedCornerShape(16.dp))
                    .background(Ch.SurfaceMuted)
                    .clickable(onClick = onDismiss)
                    .padding(vertical = 14.dp),
                contentAlignment = Alignment.Center,
            ) {
                Text(stringResource(R.string.ch_cancel), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun EmojiSheet(onDismiss: () -> Unit, onPick: (String) -> Unit) {
    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
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
internal fun ForwardSheet(exclude: String, onDismiss: () -> Unit, onSend: (List<String>) -> Unit) {
    val selected = remember { mutableStateListOf<String>() }
    var query by remember { mutableStateOf("") }
    // Chats I can send to, 15 at a time from the server (search included), the next 15 near the end.
    val chats = remember { mutableStateListOf<ConversationDto>() }
    var page by remember { mutableStateOf(0) }
    var hasMore by remember { mutableStateOf(true) }
    var loading by remember { mutableStateOf(false) }
    val listState = androidx.compose.foundation.lazy.rememberLazyListState()

    /** False when nothing was loaded (another load running, a newer search, or the server failed). */
    suspend fun loadPage(next: Int, q: String): Boolean {
        if (loading) return false
        loading = true
        val res = runCatching {
            com.dorr.app.network.ApiClient.chat.conversations(chatAuth(), search = q.trim().ifEmpty { null }, page = next, perPage = 15)
        }.getOrNull()
        loading = false
        // A newer search started meanwhile: drop this answer.
        if (q != query) return false
        if (res == null) {
            hasMore = false
            return false
        }
        if (next == 1) chats.clear()
        chats.addAll(res.data.orEmpty().filter { c -> c.id != exclude && c.canSend && chats.none { it.id == c.id } })
        page = next
        hasMore = res.pagination?.hasMorePages == true
        return true
    }

    LaunchedEffect(query) {
        if (query.isNotEmpty()) delay(300)
        loading = false
        hasMore = true
        if (loadPage(1, query)) {
            // Chats I can't send to are left out: keep going until the first screen is full.
            while (hasMore && chats.size < 15) if (!loadPage(page + 1, query)) break
        }
    }
    LaunchedEffect(listState) {
        androidx.compose.runtime.snapshotFlow {
            val info = listState.layoutInfo
            info.totalItemsCount > 0 && (info.visibleItemsInfo.lastOrNull()?.index ?: 0) >= info.totalItemsCount - 3
        }.collect { nearEnd -> if (nearEnd && hasMore && !loading) loadPage(page + 1, query) }
    }

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 24.dp)) {
            Text(stringResource(R.string.ch_forward_to), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(10.dp))
            ChField(query, { query = it }, stringResource(R.string.ch_forward_search), icon = Icons.Rounded.Search, clearable = true)
            Spacer(Modifier.height(8.dp))
            Box(Modifier.fillMaxWidth().heightIn(min = 120.dp, max = 440.dp)) {
                when {
                    chats.isEmpty() && (loading || hasMore) -> androidx.compose.material3.CircularProgressIndicator(Modifier.align(Alignment.Center).size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp)
                    chats.isEmpty() -> Text(stringResource(R.string.ch_forward_none), color = Ch.Soft, modifier = Modifier.align(Alignment.Center))
                    else -> LazyColumn(state = listState) {
                        items(chats, key = { it.id }) { c ->
                            ForwardRow(c, c.id in selected) {
                                if (c.id in selected) selected.remove(c.id) else if (selected.size < 5) selected.add(c.id)
                            }
                        }
                        if (hasMore) item(key = "more") {
                            Box(Modifier.fillMaxWidth().padding(10.dp), contentAlignment = Alignment.Center) {
                                androidx.compose.material3.CircularProgressIndicator(Modifier.size(20.dp), color = Ch.Red, strokeWidth = 2.dp)
                            }
                        }
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
        Text(ltrTitle(c.title), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
        androidx.compose.animation.AnimatedContent(checked, label = "check") { on ->
            Icon(if (on) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (on) Ch.Red else Ch.Soft, modifier = Modifier.size(26.dp))
        }
    }
}

/** A deadline from the server that hasn't passed yet. */
private fun stillOpen(until: String?): Boolean = parseInstant(until)?.isAfter(java.time.Instant.now()) == true
