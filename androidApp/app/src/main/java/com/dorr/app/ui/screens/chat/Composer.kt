package com.dorr.app.ui.screens.chat

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.location.LocationManager
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.CallSplit
import androidx.compose.material.icons.rounded.EmojiEmotions
import androidx.compose.material.icons.rounded.LooksOne
import androidx.compose.material.icons.rounded.NotificationsOff
import androidx.compose.material.icons.rounded.Poll
import androidx.compose.material.icons.rounded.PriorityHigh
import androidx.compose.material.icons.rounded.ReceiptLong
import androidx.compose.material.icons.rounded.RequestQuote
import androidx.compose.material.icons.rounded.Schedule
import android.os.Build
import android.provider.ContactsContract
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.gestures.waitForUpOrCancellation
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.KeyboardArrowUp
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Photo
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.Stop
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.input.pointer.positionChange
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import com.dorr.app.R
import com.dorr.app.chat.VoiceRecorder
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MessageDto
import com.dorr.app.network.WalletTransactionDto
import com.dorr.app.ui.screens.wallet.formatMinor
import com.dorr.app.ui.theme.CairoFontFamily
import kotlinx.coroutines.launch
import kotlin.math.roundToInt

@Composable
fun Composer(state: ConversationState) {
    val context = LocalContext.current
    val haptic = LocalHapticFeedback.current
    val scope = rememberCoroutineScope()
    val host = LocalChat.current
    // What I typed last time and didn't send comes back (and is kept as I type — see the effect below).
    var text by remember(state.id) { mutableStateOf(com.dorr.app.chat.ChatStore.draft(state.id)) }
    // Long-press on Send: "Send without sound" / "Schedule message".
    var silentMenu by remember { mutableStateOf(false) }
    var scheduling by remember { mutableStateOf(false) }
    var scheduledOpen by remember { mutableStateOf(false) }
    var attachOpen by remember { mutableStateOf(false) }
    var exprOpen by remember { mutableStateOf(false) }
    var transferPicker by remember { mutableStateOf(false) }
    val recorder = remember { VoiceRecorder(context) }
    var locked by remember { mutableStateOf(false) }
    var dragX by remember { mutableFloatStateOf(0f) }
    var dragY by remember { mutableFloatStateOf(0f) }
    val micDenied = stringResource(R.string.ch_permission_mic)

    // Editing puts the old text in the field.
    LaunchedEffect(state.editing) { state.editing?.let { text = it.body.orEmpty() } }
    // The draft: saved a moment after typing stops, and right away when I leave the chat.
    LaunchedEffect(state.id, text) {
        if (state.editing != null) return@LaunchedEffect
        kotlinx.coroutines.delay(500)
        com.dorr.app.chat.ChatStore.saveDraft(state.id, text)
    }
    val latestText by rememberUpdatedState(text)
    DisposableEffect(state.id) {
        onDispose { if (state.editing == null) com.dorr.app.chat.ChatStore.saveDraft(state.id, latestText) }
    }
    DisposableEffect(Unit) { onDispose { recorder.cancel() } }

    val micPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (!granted) host.showToast(micDenied)
    }

    // @mentions: participant id → the name inserted for them.
    val mentioned = remember(state.id) { mutableStateMapOf<Int, String>() }
    val isGroup = state.conversation?.isGroup == true
    // The word being typed right now, when it starts with "@" (null otherwise).
    val mentionQuery = if (isGroup) Regex("(?:^|\\s)@([^\\s@]{0,30})$").find(text)?.groupValues?.get(1) else null
    LaunchedEffect(mentionQuery != null) { if (mentionQuery != null) state.loadMembers() }
    // "/word" alone in the field: my quick replies that start with it.
    val quickQuery = if (state.editing == null) Regex("^/([^\\s/]{0,32})$").find(text)?.groupValues?.get(1) else null

    // Slow mode: seconds left before I may send again (ticks down on the send button).
    var clock by remember { androidx.compose.runtime.mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(state.slowUntil) {
        while (System.currentTimeMillis() < state.slowUntil) {
            clock = System.currentTimeMillis()
            kotlinx.coroutines.delay(250)
        }
        clock = System.currentTimeMillis()
    }
    val slowLeft = if (state.slowUntil > clock) ((state.slowUntil - clock + 999) / 1000).toInt() else 0
    val slowWait = if (slowLeft > 0) stringResource(R.string.ch_slow_mode_wait, slowLeft) else ""
    val scheduledToast = stringResource(R.string.ch_scheduled_toast)

    fun send(silent: Boolean = false, urgent: Boolean = false, sensitive: Boolean = false) {
        val body = text.trim()
        if (body.isEmpty()) return
        if (slowLeft > 0 && state.editing == null) {
            host.showToast(slowWait)
            return
        }
        haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
        val editing = state.editing
        val mentions = mentioned.filter { (_, name) -> body.contains("@$name") }.keys.toList()
        if (editing != null) {
            state.edit(editing, body)
        } else {
            val extra = buildMap<String, Any?> {
                if (mentions.isNotEmpty()) put("mentions", mentions)
                // Delivered without a notification sound or vibration.
                if (silent) put("silent", true)
                // Gets through their mute, when they allow urgent messages from me.
                if (urgent) put("urgent", true)
                // Hidden until they unlock it, and never in a notification.
                if (sensitive) put("sensitive", true)
            }
            state.send(Outgoing("text", body, extra = extra))
        }
        text = ""
        mentioned.clear()
        com.dorr.app.chat.ChatStore.saveDraft(state.id, "")
    }

    fun finishVoice() {
        val clip = recorder.finish() ?: return
        haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
        state.sendTyping(stop = true)
        state.send(Outgoing("voice", files = listOf(LocalFile(clip.file, "audio/mp4", clip.file.name)), extra = mapOf("duration_ms" to clip.durationMs, "waveform" to clip.waveform)))
    }

    Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 8.dp, vertical = 6.dp)) {
        // --------------------------------------------------------------- my scheduled messages here
        ScheduledPill(state) { scheduledOpen = true }

        // --------------------------------------------------------------- the card of a link being typed
        ComposerLinkPreview(text)

        // --------------------------------------------------------------- "/" quick replies
        QuickReplySuggestions(quickQuery) { reply ->
            text = reply.body
            haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
        }

        // --------------------------------------------------------------- ✨ suggested replies (on a tap)
        SmartRepliesRow(state) { reply ->
            text = reply
            state.clearSmartReplies()
            haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
        }

        // --------------------------------------------------------------- @mention suggestions
        val suggestions = if (mentionQuery == null) emptyList() else state.members
            .filter { it.profile?.isMe != true && (mentionQuery.isEmpty() || it.profile?.name.orEmpty().contains(mentionQuery, ignoreCase = true)) }
            .take(6)
        AnimatedVisibility(suggestions.isNotEmpty(), enter = expandVertically(spring(dampingRatio = 0.8f)) + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            Column(
                Modifier.fillMaxWidth().padding(bottom = 6.dp).shadow(10.dp, RoundedCornerShape(20.dp)).clip(RoundedCornerShape(20.dp)).background(Ch.Surface).padding(vertical = 6.dp),
            ) {
                suggestions.forEachIndexed { i, member ->
                    val name = member.profile?.name.orEmpty()
                    Row(
                        Modifier.fillMaxWidth().chStagger(i).clickable {
                            // Replace "@partial" with "@Full Name ".
                            text = text.replace(Regex("@([^\\s@]{0,30})$"), "@$name ")
                            mentioned[member.participantId] = name
                            haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                        }.padding(horizontal = 14.dp, vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        ChAvatar(member.profile?.avatar, name, member.profile?.key, size = 34.dp)
                        Spacer(Modifier.width(10.dp))
                        Text(name, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp, modifier = Modifier.weight(1f))
                        if (member.role != "member") Text(stringResource(if (member.role == "owner") R.string.ch_owner else R.string.ch_admin), color = Ch.Red, fontSize = 11.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }
        }

        // --------------------------------------------------------------- reply / edit preview
        AnimatedVisibility(state.replyTo != null || state.editing != null, enter = expandVertically(spring(dampingRatio = 0.8f)) + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            val target = state.editing ?: state.replyTo
            if (target != null) ContextBar(target, isEdit = state.editing != null) {
                if (state.editing != null) text = ""
                state.replyTo = null
                state.editing = null
            }
        }

        Row(verticalAlignment = Alignment.Bottom) {
            // --------------------------------------------------------------- field or recording bar
            Box(Modifier.weight(1f)) {
                AnimatedContent(targetState = recorder.recording, label = "composer", transitionSpec = {
                    (fadeIn(tween(200)) + slideInHorizontally { it / 4 }) togetherWith (fadeOut(tween(150)) + slideOutHorizontally { -it / 4 })
                }) { recording ->
                    if (recording) {
                        RecordingBar(recorder, dragX, locked, onCancel = { recorder.cancel(); locked = false; state.sendTyping(stop = true) })
                    } else {
                        Row(
                            Modifier.fillMaxWidth().heightIn(min = 50.dp).shadow(6.dp, RoundedCornerShape(25.dp), spotColor = Color.Black.copy(alpha = 0.15f))
                                // Slightly see-through: the wallpaper shows around and under the message box.
                                .clip(RoundedCornerShape(25.dp)).background(Ch.Surface.copy(alpha = 0.9f)).padding(horizontal = 6.dp),
                            verticalAlignment = Alignment.Bottom,
                        ) {
                            val rotation by animateFloatAsState(if (attachOpen) 45f else 0f, spring(dampingRatio = 0.45f), label = "attach")
                            Box(Modifier.padding(bottom = 5.dp).size(40.dp).clip(CircleShape).clickable { attachOpen = true }, contentAlignment = Alignment.Center) {
                                Icon(Icons.Rounded.Add, null, tint = Ch.Red, modifier = Modifier.size(26.dp).rotate(rotation))
                            }
                            Box(Modifier.weight(1f).padding(vertical = 14.dp, horizontal = 4.dp)) {
                                if (text.isEmpty()) Text(
                                    // Slow mode on: say so where I type.
                                    if (state.slowModeSeconds > 0) stringResource(R.string.ch_slow_mode_hint, slowModeLabel(state.slowModeSeconds)) else stringResource(R.string.ch_message_hint),
                                    color = Ch.Soft, fontSize = 15.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
                                )
                                BasicTextField(
                                    value = text,
                                    onValueChange = {
                                        text = it
                                        if (it.isNotBlank()) state.sendTyping() else state.sendTyping(stop = true)
                                    },
                                    textStyle = TextStyle(color = Ch.Ink, fontSize = 15.5.sp, fontFamily = CairoFontFamily, lineHeight = 21.sp),
                                    cursorBrush = SolidColor(Ch.Red),
                                    maxLines = 6,
                                    keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences),
                                    modifier = Modifier.fillMaxWidth(),
                                )
                            }
                            // ✨ Suggest replies — only on an empty field, only when the AI is available.
                            if (text.isEmpty() && ChatAi.smartReplies && state.editing == null) {
                                Box(Modifier.padding(bottom = 5.dp).size(40.dp).clip(CircleShape).clickable { state.loadSmartReplies() }, contentAlignment = Alignment.Center) {
                                    Icon(Icons.Rounded.AutoAwesome, null, tint = if (state.loadingReplies) Ch.Red else Ch.Mut, modifier = Modifier.size(22.dp))
                                }
                            }
                            // Emoji · stickers · GIFs — the face winks when the panel opens.
                            val wink by animateFloatAsState(if (exprOpen) 1f else 0f, spring(dampingRatio = 0.4f), label = "exprWink")
                            Box(Modifier.padding(bottom = 5.dp).size(40.dp).clip(CircleShape).clickable { exprOpen = true }, contentAlignment = Alignment.Center) {
                                Icon(
                                    Icons.Rounded.EmojiEmotions, null, tint = if (exprOpen) Ch.Red else Ch.Mut,
                                    modifier = Modifier.size(24.dp).graphicsLayer { rotationZ = wink * 18f; scaleX = 1f + wink * 0.15f; scaleY = 1f + wink * 0.15f },
                                )
                            }
                        }
                    }
                }
            }
            Spacer(Modifier.width(8.dp))

            // --------------------------------------------------------------- mic ⇄ send
            val showSend = text.isNotBlank() || locked
            val pulse = rememberInfiniteTransition(label = "rec")
            val halo by pulse.animateFloat(1f, 1.35f, infiniteRepeatable(tween(700, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "halo")
            val grow by animateFloatAsState(if (recorder.recording && !locked) 1.45f else 1f, spring(dampingRatio = 0.5f, stiffness = 400f), label = "grow")
            val density = LocalDensity.current
            val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
            val cancelAt = with(density) { 110.dp.toPx() }
            val lockAt = with(density) { 80.dp.toPx() }

            Box(contentAlignment = Alignment.BottomCenter) {
                // Long-press on Send: "Send without sound" pops up above the button.
                if (silentMenu) {
                    val popIn = remember { androidx.compose.animation.core.Animatable(0f) }
                    LaunchedEffect(Unit) { popIn.animateTo(1f, spring(dampingRatio = 0.55f, stiffness = 500f)) }
                    androidx.compose.ui.window.Popup(
                        alignment = Alignment.BottomEnd,
                        offset = with(LocalDensity.current) { androidx.compose.ui.unit.IntOffset(0, -64.dp.roundToPx()) },
                        onDismissRequest = { silentMenu = false },
                        properties = androidx.compose.ui.window.PopupProperties(focusable = true),
                    ) {
                        Column(
                            Modifier
                                .graphicsLayer { scaleX = popIn.value; scaleY = popIn.value; alpha = popIn.value; transformOrigin = androidx.compose.ui.graphics.TransformOrigin(1f, 1f) }
                                .shadow(14.dp, RoundedCornerShape(18.dp), spotColor = Color.Black.copy(alpha = 0.25f))
                                .clip(RoundedCornerShape(18.dp)).background(Ch.Surface)
                                .padding(vertical = 4.dp),
                        ) {
                            SendMenuRow(Icons.Rounded.NotificationsOff, stringResource(R.string.ch_send_silent)) { silentMenu = false; send(silent = true) }
                            SendMenuRow(Icons.Rounded.Schedule, stringResource(R.string.ch_schedule_message)) { silentMenu = false; scheduling = true }
                            SendMenuRow(Icons.Rounded.Lock, stringResource(R.string.ch_send_sensitive)) { silentMenu = false; send(sensitive = true) }
                            if (state.conversation?.let { !it.isGroup && !it.isSelf } == true) {
                                SendMenuRow(Icons.Rounded.PriorityHigh, stringResource(R.string.ch_send_urgent)) { silentMenu = false; send(urgent = true) }
                            }
                        }
                    }
                }
                // The lock that rises above the mic while recording.
                androidx.compose.animation.AnimatedVisibility(recorder.recording && !locked, enter = fadeIn() + scaleIn(), exit = fadeOut() + scaleOut(), modifier = Modifier.offset(y = (-70).dp)) {
                    val lift = (-dragY / lockAt).coerceIn(0f, 1f)
                    Column(
                        Modifier.offset(y = (-lift * 24).dp).shadow(6.dp, RoundedCornerShape(20.dp)).clip(RoundedCornerShape(20.dp)).background(Ch.Surface).padding(horizontal = 8.dp, vertical = 10.dp),
                        horizontalAlignment = Alignment.CenterHorizontally,
                    ) {
                        Icon(Icons.Rounded.Lock, null, tint = if (lift > 0.9f) Ch.Red else Ch.Mut, modifier = Modifier.size(20.dp))
                        Icon(Icons.Rounded.KeyboardArrowUp, null, tint = Ch.Soft, modifier = Modifier.size(18.dp))
                    }
                }
                if (recorder.recording && !locked) {
                    Box(Modifier.size(52.dp).scale(grow * halo).background(Ch.Red.copy(alpha = 0.18f), CircleShape))
                }
                Box(
                    Modifier
                        .size(52.dp)
                        .scale(grow)
                        .shadow(12.dp, CircleShape, spotColor = Ch.Red.copy(alpha = 0.5f))
                        .clip(CircleShape)
                        .background(Ch.HeaderBrush)
                        .pointerInput(showSend) {
                            if (showSend) {
                                awaitEachGesture {
                                    awaitFirstDown()
                                    // A long press on a written message offers "send without sound".
                                    val longPress = !locked && state.editing == null
                                    // Wrapped in a list: null then means "held past the timeout", not "cancelled".
                                    val inTime = if (longPress) {
                                        withTimeoutOrNull(viewConfiguration.longPressTimeoutMillis) { listOf(waitForUpOrCancellation()) }
                                    } else {
                                        listOf(waitForUpOrCancellation())
                                    }
                                    val up = if (inTime != null) {
                                        inTime.first()
                                    } else {
                                        haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                                        silentMenu = true
                                        waitForUpOrCancellation()
                                        null
                                    }
                                    if (up != null) {
                                        if (locked) { locked = false; finishVoice() } else send()
                                    }
                                }
                                return@pointerInput
                            }
                            // Hold to record: slide towards the start to cancel, up to lock hands-free.
                            awaitEachGesture {
                                awaitFirstDown()
                                if (ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
                                    micPermission.launch(Manifest.permission.RECORD_AUDIO)
                                    return@awaitEachGesture
                                }
                                if (!recorder.start()) return@awaitEachGesture
                                haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                                state.sendTyping(state = "recording")
                                dragX = 0f
                                dragY = 0f
                                var cancelled = false
                                while (true) {
                                    val event = awaitPointerEvent()
                                    val change = event.changes.firstOrNull() ?: break
                                    val delta = change.positionChange()
                                    dragX += if (rtl) delta.x else -delta.x
                                    dragY += delta.y
                                    change.consume()
                                    if (dragX > cancelAt) {
                                        cancelled = true
                                        haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                                        break
                                    }
                                    if (dragY < -lockAt) {
                                        locked = true
                                        haptic.performHapticFeedback(HapticFeedbackType.TextHandleMove)
                                        break
                                    }
                                    if (!change.pressed) break
                                }
                                dragX = 0f
                                dragY = 0f
                                when {
                                    cancelled -> { recorder.cancel(); state.sendTyping(stop = true) }
                                    locked -> Unit // keeps recording; the send button finishes it
                                    else -> finishVoice()
                                }
                            }
                        },
                    contentAlignment = Alignment.Center,
                ) {
                    AnimatedContent(targetState = showSend, label = "micSend", transitionSpec = {
                        (scaleIn(spring(dampingRatio = 0.45f), initialScale = 0.3f) + fadeIn()) togetherWith (scaleOut(targetScale = 0.3f) + fadeOut())
                    }) { send ->
                        if (send && slowLeft > 0 && state.editing == null) {
                            Text(slowLeft.toString(), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
                        } else {
                            Icon(
                                if (send) (if (state.editing != null) Icons.Rounded.Edit else Icons.AutoMirrored.Rounded.Send) else Icons.Rounded.Mic,
                                null, tint = Color.White, modifier = Modifier.size(24.dp),
                            )
                        }
                    }
                }
            }
        }
    }

    if (scheduling) ScheduleSheet(onDismiss = { scheduling = false }) { at ->
        val body = text.trim()
        scope.launch {
            if (body.isNotEmpty() && state.schedule(body, at)) {
                host.showToast(scheduledToast.format(scheduleLabel(at)))
                text = ""
                com.dorr.app.chat.ChatStore.saveDraft(state.id, "")
            }
        }
    }
    if (scheduledOpen) ScheduledListSheet(state) { scheduledOpen = false }
    if (attachOpen) AttachSheet(state, onDismiss = { attachOpen = false }, onPickTransfer = { attachOpen = false; transferPicker = true })
    if (exprOpen) ExpressionPanel(
        onDismiss = { exprOpen = false },
        onEmoji = { text += it },
        onPick = { pick -> state.send(Outgoing(pick.type, extra = pick.extra.filterValues { it != null })) },
    )
    if (transferPicker) TransferPicker(onDismiss = { transferPicker = false }) { tx ->
        transferPicker = false
        state.send(Outgoing("wallet_transfer", extra = mapOf("wallet_transaction_id" to tx.uuid)))
    }
}

/** One action in the send button's long-press menu. */
@Composable
private fun SendMenuRow(icon: ImageVector, label: String, onClick: () -> Unit) {
    Row(Modifier.clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(32.dp).clip(CircleShape).background(Ch.Red.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(10.dp))
        Text(label, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp)
    }
}

/** Above the field: what I'm replying to, or the message I'm editing. */
@Composable
private fun ContextBar(target: MessageDto, isEdit: Boolean, onClose: () -> Unit) {
    val accent = if (isEdit) Ch.Red else Ch.colorFor(target.sender?.key)
    Row(
        Modifier.fillMaxWidth().padding(bottom = 6.dp).shadow(4.dp, RoundedCornerShape(18.dp)).clip(RoundedCornerShape(18.dp)).background(Ch.Surface).height(IntrinsicSize.Min),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(5.dp).fillMaxHeight().background(accent))
        Column(Modifier.weight(1f).padding(horizontal = 12.dp, vertical = 8.dp)) {
            Text(
                if (isEdit) stringResource(R.string.ch_editing) else if (target.sender?.key == myKey()) stringResource(R.string.ch_you) else target.sender?.name.orEmpty(),
                color = accent, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold,
            )
            val (_, label) = previewOf(target.type, false)
            Text(target.body?.takeIf { it.isNotBlank() } ?: label, color = Ch.Mut, fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Icon(Icons.Rounded.Close, null, tint = Ch.Soft, modifier = Modifier.padding(10.dp).size(20.dp).clickable(onClick = onClose))
    }
}

/**
 * While holding the mic: blinking dot + timer, a live waveform, and "‹ slide to cancel" drifting
 * with your finger. Locked: a bin to discard.
 */
@Composable
private fun RecordingBar(recorder: VoiceRecorder, dragX: Float, locked: Boolean, onCancel: () -> Unit) {
    val blink = rememberInfiniteTransition(label = "blink")
    val dot by blink.animateFloat(1f, 0.2f, infiniteRepeatable(tween(600), RepeatMode.Reverse), label = "dot")
    val shimmer by blink.animateFloat(0f, 1f, infiniteRepeatable(tween(1300, easing = FastOutSlowInEasing)), label = "shimmer")
    val shake = remember { Animatable(0f) }
    val density = LocalDensity.current

    Row(
        Modifier.fillMaxWidth().height(50.dp).shadow(6.dp, RoundedCornerShape(25.dp)).clip(RoundedCornerShape(25.dp)).background(Ch.Surface.copy(alpha = 0.9f)).padding(horizontal = 14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (locked) {
            Icon(Icons.Rounded.Delete, null, tint = Ch.Mut, modifier = Modifier.size(24.dp).clickable(onClick = onCancel))
            Spacer(Modifier.width(10.dp))
        }
        Box(Modifier.size(10.dp).graphicsLayer { alpha = dot }.background(Ch.Red, CircleShape))
        Spacer(Modifier.width(8.dp))
        Text(durationText(recorder.elapsedMs), color = Ch.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold)
        Spacer(Modifier.width(10.dp))
        Canvas(Modifier.weight(1f).height(26.dp)) {
            val bars = 40
            val gap = 2.dp.toPx()
            val w = (size.width - gap * (bars - 1)) / bars
            val levels = recorder.levels
            for (i in 0 until bars) {
                val v = levels.getOrNull(levels.size - bars + i) ?: 4
                val h = (size.height * v / 100f).coerceAtLeast(3.dp.toPx())
                drawRoundRect(Ch.Red.copy(alpha = 0.35f + 0.65f * (i / bars.toFloat())), Offset(i * (w + gap), (size.height - h) / 2), Size(w, h), CornerRadius(w / 2))
            }
        }
        if (!locked) {
            Spacer(Modifier.width(8.dp))
            Text(
                "‹ " + stringResource(R.string.ch_slide_cancel),
                color = Ch.Mut.copy(alpha = 0.5f + 0.5f * shimmer), fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold,
                modifier = Modifier.offset { androidx.compose.ui.unit.IntOffset(-(dragX * 0.6f).roundToInt() + shake.value.roundToInt(), 0) },
            )
        } else {
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.ch_recording_locked), color = Ch.Red, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
        }
    }
}

// ------------------------------------------------------------------------------- attachments

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun AttachSheet(state: ConversationState, onDismiss: () -> Unit, onPickTransfer: () -> Unit) {
    val context = LocalContext.current
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    val sheet = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    val locating = stringResource(R.string.ch_sending_location)
    val locationFailed = stringResource(R.string.ch_location_failed)

    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.PickMultipleVisualMedia(10)) { uris ->
        onDismiss()
        if (uris.isEmpty()) return@rememberLauncherForActivityResult
        // Copy + shrink off the main thread (on the chat's scope — this sheet is already closing).
        host.scope.launch {
            val files = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
                uris.mapNotNull { copyToCache(context, it, "media") }.map { compressImage(context, it) }
            }
            // Photos travel together as one album; each video is its own message.
            val (videos, images) = files.partition { it.mime.startsWith("video/") }
            if (images.isNotEmpty()) state.send(Outgoing("image", files = images))
            videos.forEach { video ->
                // Poster + length now, so the bubble shows a picture and a duration at once.
                val (poster, info) = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
                    com.dorr.app.chat.VideoTools.poster(context, video.file) to com.dorr.app.chat.VideoTools.info(context, video.file)
                }
                state.send(Outgoing("video", files = listOf(video), extra = info?.durationMs?.let { mapOf("duration_ms" to it) } ?: emptyMap(), thumbnail = poster))
            }
        }
    }
    val document = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        onDismiss()
        uri?.let { copyToCache(context, it, "document") }?.let { f -> state.send(Outgoing(typeForMime(f.mime).let { if (it == "image" || it == "video") "document" else it }, files = listOf(f))) }
    }
    val contact = rememberLauncherForActivityResult(ActivityResultContracts.PickContact()) { uri ->
        onDismiss()
        uri ?: return@rememberLauncherForActivityResult
        readContact(context, uri)?.let { (name, phones) ->
            state.send(Outgoing("contact", extra = mapOf("contact_name" to name, "contact_phones" to phones)))
        }
    }
    // Location: pick "now" or live (15 min / 1 h / 8 h) first, then ask for the permission.
    var locationChoice by remember { mutableStateOf(false) }
    var liveSeconds by remember { mutableStateOf<Int?>(null) }
    var askingLocation by remember { mutableStateOf(false) }
    var pollOpen by remember { mutableStateOf(false) }
    // Money: "request" to the other person in a direct chat, "split the bill" in a group.
    var moneyOpen by remember { mutableStateOf(false) }
    var sendMoneyOpen by remember { mutableStateOf(false) }
    val isGroup = state.conversation?.isGroup == true
    // Sending money goes to one other person: not in a group, a channel, or my own notes.
    val canSendMoney = !isGroup && state.conversation?.isSelf != true && state.conversation != null
    val isChannel = state.conversation?.isChannel == true
    // Why the location couldn't be sent yet: "off" (location turned off on the phone) or
    // "denied" (no permission) — a sheet explains and opens the right settings.
    var locationNotice by remember { mutableStateOf<String?>(null) }
    fun shareLocation() {
        val manager = context.getSystemService(Context.LOCATION_SERVICE) as LocationManager
        if (!androidx.core.location.LocationManagerCompat.isLocationEnabled(manager)) {
            locationNotice = "off"
            return
        }
        locationNotice = null
        host.showToast(locating)
        val live = liveSeconds
        currentLocation(context) { loc ->
            if (loc == null) host.showToast(locationFailed)
            else state.send(Outgoing("location", extra = buildMap {
                put("latitude", loc.latitude)
                put("longitude", loc.longitude)
                if (live != null) put("live_seconds", live)
            }))
        }
        onDismiss()
    }
    val locationPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted ->
        askingLocation = false
        if (granted.values.any { it }) shareLocation() else locationNotice = "denied"
    }
    // View once: one photo or video, never kept in the chat's gallery.
    val viewOnce = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri ->
        onDismiss()
        uri ?: return@rememberLauncherForActivityResult
        host.scope.launch {
            val file = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) { copyToCache(context, uri, "media")?.let { compressImage(context, it) } } ?: return@launch
            state.send(Outgoing(if (file.mime.startsWith("video/")) "video" else "image", files = listOf(file), extra = mapOf("view_once" to 1)))
        }
    }
    val contactsPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) contact.launch(null) else onDismiss()
    }

    val items = listOfNotNull(
        AttachItem(Icons.Rounded.Photo, R.string.ch_attach_gallery, listOf(Color(0xFFA78BFA), Color(0xFF7C3AED))) { gallery.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo)) },
        AttachItem(Icons.Rounded.Description, R.string.ch_attach_document, listOf(Color(0xFF60A5FA), Color(0xFF2563EB))) { document.launch(arrayOf("*/*")) },
        AttachItem(Icons.Rounded.Place, R.string.ch_attach_location, listOf(Color(0xFF34D399), Color(0xFF059669))) { locationChoice = true },
        AttachItem(Icons.Rounded.Poll, R.string.ch_attach_poll, listOf(Color(0xFFFCD34D), Color(0xFFF59E0B))) { pollOpen = true },
        AttachItem(Icons.Rounded.LooksOne, R.string.ch_attach_view_once, listOf(Color(0xFFF472B6), Color(0xFFDB2777))) {
            viewOnce.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo))
        },
        AttachItem(Icons.Rounded.Person, R.string.ch_attach_contact, listOf(Color(0xFF22D3EE), Color(0xFF0891B2))) {
            if (ContextCompat.checkSelfPermission(context, Manifest.permission.READ_CONTACTS) == PackageManager.PERMISSION_GRANTED) contact.launch(null)
            else contactsPermission.launch(Manifest.permission.READ_CONTACTS)
        },
        // Money requests / splits are between people — not in a channel.
        if (isChannel) null else AttachItem(
            if (isGroup) Icons.Rounded.CallSplit else Icons.Rounded.RequestQuote,
            if (isGroup) R.string.ch_attach_split else R.string.ch_attach_request,
            listOf(Color(0xFF34D399), Color(0xFF047857)),
        ) { moneyOpen = true },
        if (canSendMoney) AttachItem(Icons.Rounded.Payments, R.string.ch_attach_send_money, listOf(Color(0xFF10B981), Color(0xFF047857))) { sendMoneyOpen = true } else null,
        AttachItem(Icons.Rounded.ReceiptLong, R.string.ch_attach_transfer, listOf(Ch.Red, Ch.RedDeep)) { onPickTransfer() },
        AttachItem(Icons.Rounded.QrCode2, R.string.ch_attach_wallet_qr, listOf(Color(0xFFFBBF24), Color(0xFFD97706))) {
            onDismiss()
            state.send(Outgoing("wallet_qr"))
        },
    )

    // While Android asks for the location permission this sheet stays composed (its launcher must
    // be alive to get the answer) but draws nothing; the answer closes it.
    if (askingLocation) return
    locationNotice?.let { notice ->
        val off = notice == "off"
        ChoiceSheet(
            title = stringResource(if (off) R.string.ch_location_off_title else R.string.ch_location_denied_title),
            subtitle = stringResource(if (off) R.string.ch_location_off_sub else R.string.ch_location_denied_sub),
            options = buildList {
                add(stringResource(if (off) R.string.ch_location_open_settings else R.string.ch_open_app_settings) to {
                    val intent = if (off) android.content.Intent(android.provider.Settings.ACTION_LOCATION_SOURCE_SETTINGS)
                    else android.content.Intent(android.provider.Settings.ACTION_APPLICATION_DETAILS_SETTINGS, android.net.Uri.fromParts("package", context.packageName, null))
                    runCatching { context.startActivity(intent) }
                    Unit
                })
                // Back from the settings: send it now.
                add(stringResource(R.string.ch_location_try_again) to {
                    if (off) shareLocation() else {
                        locationNotice = null
                        askingLocation = true
                        locationPermission.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
                    }
                    Unit
                })
            },
            onDismiss = { locationNotice = null; onDismiss() },
        )
        return
    }
    if (locationChoice) {
        fun ask(seconds: Int?) {
            liveSeconds = seconds
            locationChoice = false
            askingLocation = true
            locationPermission.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
        }
        LocationShareSheet(
            onDismiss = { locationChoice = false; onDismiss() },
            onCurrent = { ask(null) },
            onLive = { ask(it) },
        )
        return
    }
    if (moneyOpen) {
        if (isGroup) {
            SplitBillSheet(state.members, onDismiss = { moneyOpen = false; onDismiss() }) { total, note, equal, shares ->
                state.send(Outgoing("bill_split", body = note, extra = buildMap {
                    put("amount_minor", total)
                    put("split_mode", if (equal) "equal" else "custom")
                    if (equal) put("split_participants", shares.keys.toList())
                    else put("split_shares", shares.map { (id, minor) -> mapOf("participant_id" to id, "amount_minor" to minor) })
                }))
            }
        } else {
            RequestMoneySheet(state.conversation?.title.orEmpty(), onDismiss = { moneyOpen = false; onDismiss() }) { amount, note ->
                state.send(Outgoing("money_request", body = note, extra = mapOf("amount_minor" to amount)))
            }
        }
        return
    }
    if (sendMoneyOpen) {
        SendMoneySheet(state.id, state.conversation?.title.orEmpty(), onDismiss = { sendMoneyOpen = false; onDismiss() }) { sent ->
            state.replaceMessage(sent.id, UiMessage(sent))
        }
        return
    }
    if (pollOpen) {
        PollComposerSheet(onDismiss = { pollOpen = false; onDismiss() }) { question, options, multiple ->
            state.send(Outgoing("poll", body = question, extra = mapOf("poll_options" to options, "poll_multiple" to multiple)))
        }
        return
    }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheet, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(start = 20.dp, end = 20.dp, bottom = 34.dp)) {
            items.chunked(3).forEachIndexed { row, chunk ->
                Row(Modifier.fillMaxWidth().padding(vertical = 10.dp), horizontalArrangement = Arrangement.SpaceEvenly) {
                    chunk.forEachIndexed { col, item -> AttachButton(item, row * 3 + col) }
                }
            }
        }
    }
}

private class AttachItem(val icon: ImageVector, val label: Int, val colors: List<Color>, val onClick: () -> Unit)

@Composable
private fun AttachButton(item: AttachItem, index: Int) {
    val pop = remember { Animatable(0f) }
    LaunchedEffect(Unit) {
        kotlinx.coroutines.delay(40L + index * 45L)
        pop.animateTo(1f, spring(dampingRatio = 0.45f, stiffness = 380f))
    }
    Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.width(92.dp).graphicsLayer { scaleX = pop.value; scaleY = pop.value; alpha = pop.value.coerceIn(0f, 1f) }) {
        Box(
            Modifier.size(62.dp).shadow(12.dp, CircleShape, spotColor = item.colors.last().copy(alpha = 0.5f)).clip(CircleShape).background(Brush.linearGradient(item.colors)).clickable(onClick = item.onClick),
            contentAlignment = Alignment.Center,
        ) { Icon(item.icon, null, tint = Color.White, modifier = Modifier.size(28.dp)) }
        Spacer(Modifier.height(8.dp))
        Text(stringResource(item.label), color = Ch.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, maxLines = 1)
    }
}

/** My recent outgoing transfers, to share one as a receipt card. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun TransferPicker(onDismiss: () -> Unit, onPick: (WalletTransactionDto) -> Unit) {
    var list by remember { mutableStateOf<List<WalletTransactionDto>?>(null) }
    LaunchedEffect(Unit) {
        list = runCatching { ApiClient.wallet.transactions(chatAuth(), page = 1, perPage = 30, direction = "debit").data }.getOrNull()
            .orEmpty().filter { it.type == "transfer_out" }
    }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 30.dp)) {
            Text(stringResource(R.string.ch_pick_transfer), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(12.dp))
            val items = list
            when {
                items == null -> repeat(3) { com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().height(62.dp).padding(vertical = 4.dp)) }
                items.isEmpty() -> Text(stringResource(R.string.ch_no_transfers), color = Ch.Mut, modifier = Modifier.padding(vertical = 24.dp))
                else -> LazyColumn(Modifier.heightIn(max = 420.dp)) {
                    items(items, key = { it.uuid }) { tx ->
                        Row(
                            Modifier.fillMaxWidth().padding(vertical = 4.dp).clip(RoundedCornerShape(16.dp)).background(Ch.SurfaceMuted).clickable { onPick(tx) }.padding(12.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Box(Modifier.size(40.dp).clip(CircleShape).background(Ch.HeaderBrush), contentAlignment = Alignment.Center) {
                                Icon(Icons.Rounded.Payments, null, tint = Color.White, modifier = Modifier.size(20.dp))
                            }
                            Spacer(Modifier.width(10.dp))
                            Column(Modifier.weight(1f)) {
                                Text(tx.counterparty?.name ?: tx.typeLabel, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                                Text(listTime(tx.createdAt), color = Ch.Mut, fontSize = 12.sp)
                            }
                            androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                                Text(formatMinor(tx.amountMinor, null), color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
                            }
                        }
                    }
                }
            }
        }
    }
}

private fun readContact(context: Context, uri: android.net.Uri): Pair<String, List<String>>? = runCatching {
    val resolver = context.contentResolver
    var name = ""
    var id = ""
    resolver.query(uri, arrayOf(ContactsContract.Contacts._ID, ContactsContract.Contacts.DISPLAY_NAME), null, null, null)?.use { c ->
        if (c.moveToFirst()) {
            id = c.getString(0)
            name = c.getString(1).orEmpty()
        }
    }
    val phones = mutableListOf<String>()
    resolver.query(
        ContactsContract.CommonDataKinds.Phone.CONTENT_URI, arrayOf(ContactsContract.CommonDataKinds.Phone.NUMBER),
        "${ContactsContract.CommonDataKinds.Phone.CONTACT_ID} = ?", arrayOf(id), null,
    )?.use { c -> while (c.moveToNext()) c.getString(0)?.let { phones += it } }
    if (name.isBlank()) null else name to phones.distinct().take(10)
}.getOrNull()

/**
 * Where the phone is, as fast as it can be told: a fix from the last 2 minutes right away, else
 * every enabled provider at once (GPS, network, fused) and the first answer wins — GPS alone can
 * take very long indoors. After 12 s the last known position is used; null only when there's none.
 */
@SuppressLint("MissingPermission")
private fun currentLocation(context: Context, onResult: (Location?) -> Unit) {
    val manager = context.getSystemService(Context.LOCATION_SERVICE) as LocationManager
    val candidates = buildList {
        add(LocationManager.GPS_PROVIDER)
        add(LocationManager.NETWORK_PROVIDER)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) add(LocationManager.FUSED_PROVIDER)
    }
    val providers = candidates.filter { runCatching { manager.isProviderEnabled(it) }.getOrDefault(false) }
    val last = candidates.mapNotNull { runCatching { manager.getLastKnownLocation(it) }.getOrNull() }.maxByOrNull { it.time }
    if (last != null && System.currentTimeMillis() - last.time < 2 * 60_000) {
        onResult(last)
        return
    }
    if (providers.isEmpty()) {
        onResult(last)
        return
    }

    val main = android.os.Handler(android.os.Looper.getMainLooper())
    val cancel = android.os.CancellationSignal()
    val listeners = mutableListOf<android.location.LocationListener>()
    var done = false
    fun finish(loc: Location?) {
        if (done) return
        done = true
        main.removeCallbacksAndMessages(null)
        runCatching { cancel.cancel() }
        listeners.forEach { runCatching { manager.removeUpdates(it) } }
        onResult(loc)
    }
    main.postDelayed({ finish(last) }, 12_000)
    providers.forEach { provider ->
        runCatching {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                manager.getCurrentLocation(provider, cancel, ContextCompat.getMainExecutor(context)) { loc -> if (loc != null) finish(loc) }
            } else {
                val listener = android.location.LocationListener { loc -> finish(loc) }
                listeners += listener
                @Suppress("DEPRECATION")
                manager.requestSingleUpdate(provider, listener, android.os.Looper.getMainLooper())
            }
        }
    }
}
