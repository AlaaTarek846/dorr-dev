package com.dorr.app.ui.screens.chat

import android.content.Intent
import android.net.Uri
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.scaleIn
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Reply
import androidx.compose.material.icons.rounded.Block
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.CallMissed
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Alarm
import androidx.compose.material.icons.rounded.Bolt
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.Forward
import androidx.compose.material.icons.rounded.Map
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Timer
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.blur
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.chat.VoicePlayer
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AttachmentDto
import com.dorr.app.network.MessageDto
import com.dorr.app.ui.screens.wallet.formatMinor
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import kotlin.math.roundToInt

/** Callbacks a bubble can trigger. */
class BubbleActions(
    val onReply: (MessageDto) -> Unit,
    val onLongPress: (UiMessage) -> Unit,
    val onJumpTo: (String) -> Unit,
    val onRetry: (UiMessage) -> Unit,
    val onOpenMedia: (MessageDto, Int) -> Unit,
    val onPayQr: (String) -> Unit,
    val onMessageContact: (String) -> Unit,
    val onVote: (UiMessage, Int) -> Unit = { _, _ -> },
    val onShowVotes: (MessageDto) -> Unit = {},
    val onOpenViewOnce: (UiMessage) -> Unit = {},
    val onStopLive: (UiMessage) -> Unit = {},
    /** A Dorr group invite link (dorr://chat/join/…) was tapped. */
    val onJoinGroup: (String) -> Unit = {},
    /** Money request / bill split: pay (PIN), decline, or — the requester — call it off. */
    val onPay: (UiMessage) -> Unit = {},
    val onDeclineRequest: (UiMessage) -> Unit = {},
    val onCancelRequest: (UiMessage) -> Unit = {},
    /** AI: turn a voice message into text / hide a translation or transcript. */
    val onTranscribe: (UiMessage) -> Unit = {},
    val onHideAi: (UiMessage) -> Unit = {},
    /** Threads (spec 122): the "N replies" chip under a message. */
    val onOpenThread: (MessageDto) -> Unit = {},
    /** Sensitive messages / blurred media: shown yet? And unlock / uncover one. */
    val isRevealed: (String) -> Boolean = { true },
    val onReveal: (UiMessage) -> Unit = {},
    /** A surprise greeting card whose time came: fetch it again, opened. */
    val onOpenSealed: (UiMessage) -> Unit = {},
)

/**
 * One row of the conversation. Consecutive messages from the same person inside a few minutes
 * form a run: only the first shows the name (groups) and the tail, only the last the avatar.
 */
@Composable
fun MessageRow(
    m: UiMessage,
    firstInRun: Boolean,
    lastInRun: Boolean,
    isGroup: Boolean,
    actions: BubbleActions,
    highlighted: Boolean = false,
) {
    val dto = m.dto
    if (dto.type == "system" || dto.system != null && dto.sender == null) {
        Box(Modifier.fillMaxWidth().padding(vertical = 6.dp).chPopIn(m.fresh), contentAlignment = Alignment.Center) {
            ChChip(dto.system?.text?.takeIf { it.isNotBlank() } ?: "•")
        }
        return
    }

    val mine = m.isMine
    val haptic = LocalHapticFeedback.current
    val scope = rememberCoroutineScope()
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val drag = remember { Animatable(0f) }
    var armed by remember { mutableStateOf(false) }
    val threshold = 64f * LocalContext.current.resources.displayMetrics.density
    // Swiping "into" the chat (towards the reading start) replies — mirrored in Arabic.
    val sign = if (rtl) -1f else 1f

    val highlight by animateFloatAsState(if (highlighted) 1f else 0f, tween(600), label = "hl")

    Box(
        Modifier
            .fillMaxWidth()
            .background(Ch.Red.copy(alpha = 0.10f * highlight))
            .padding(top = if (firstInRun) 6.dp else 1.5.dp, bottom = 1.5.dp)
            .pointerInput(dto.id) {
                if (dto.isDeleted || m.local != null) return@pointerInput
                detectHorizontalDragGestures(
                    onDragEnd = {
                        if (armed) actions.onReply(dto)
                        armed = false
                        scope.launch { drag.animateTo(0f, spring(dampingRatio = 0.55f, stiffness = 500f)) }
                    },
                    onDragCancel = { armed = false; scope.launch { drag.animateTo(0f) } },
                ) { _, delta ->
                    val next = (drag.value + delta * sign).coerceIn(0f, threshold * 1.5f)
                    scope.launch { drag.snapTo(next) }
                    if (!armed && next >= threshold) {
                        armed = true
                        haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                    } else if (armed && next < threshold) armed = false
                }
            },
    ) {
        // The reply arrow that grows out from under the bubble.
        val p = (drag.value / threshold).coerceIn(0f, 1f)
        Box(
            Modifier
                .align(Alignment.CenterStart)
                .padding(start = 12.dp)
                .graphicsLayer { alpha = p; scaleX = 0.4f + 0.6f * p; scaleY = 0.4f + 0.6f * p }
                .size(34.dp)
                .background(if (armed) Ch.Red else Color.White, CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Icons.AutoMirrored.Rounded.Reply, null, tint = if (armed) Color.White else Ch.Red, modifier = Modifier.size(18.dp))
        }

        Row(
            Modifier
                .fillMaxWidth()
                // `offset` already mirrors in Arabic, so the drag distance goes in as-is (the sign was applied twice).
                .offset { androidx.compose.ui.unit.IntOffset(drag.value.roundToInt(), 0) }
                .padding(horizontal = 10.dp),
            horizontalArrangement = if (mine) Arrangement.End else Arrangement.Start,
            verticalAlignment = Alignment.Bottom,
        ) {
            if (!mine && isGroup) {
                if (lastInRun) ChAvatar(dto.sender?.avatar, dto.sender?.name, dto.sender?.key, size = 30.dp)
                else Spacer(Modifier.width(30.dp))
                Spacer(Modifier.width(6.dp))
            }
            Bubble(m, mine, firstInRun, isGroup, actions)
        }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun Bubble(m: UiMessage, mine: Boolean, firstInRun: Boolean, isGroup: Boolean, actions: BubbleActions) {
    val dto = m.dto
    val big = 20.dp
    val small = 6.dp
    // The tail: the top corner on the sender's side of the first bubble in a run is sharp.
    val shape = if (mine) RoundedCornerShape(topStart = big, topEnd = if (firstInRun) small else big, bottomEnd = big, bottomStart = big)
    else RoundedCornerShape(topStart = if (firstInRun) small else big, topEnd = big, bottomEnd = big, bottomStart = big)

    // Cards that bring their own background (wallet cards, the green money request).
    // A sticker floats on the wallpaper: no bubble, no shadow.
    val bare = dto.type == "sticker" && !dto.isDeleted
    val isCard = (dto.type in setOf("wallet_transfer", "wallet_qr", "money_request", "moment_card") || bare) && !dto.isDeleted
    val media = dto.type in setOf("image", "video") && !dto.isDeleted
    val haptic = LocalHapticFeedback.current

    Column(horizontalAlignment = if (mine) Alignment.End else Alignment.Start) {
        Box(
            Modifier
                .widthIn(max = 300.dp)
                .chPopIn(m.fresh, fromEnd = mine)
                .shadow(if (bare) 0.dp else if (mine) 6.dp else 2.dp, shape, spotColor = if (mine) Ch.Red.copy(alpha = 0.35f) else Color.Black.copy(alpha = 0.12f), ambientColor = Color.Transparent)
                .clip(shape)
                .then(if (mine && !isCard) Modifier.background(Ch.OutBubble) else Modifier.background(if (isCard) Color.Transparent else Ch.InBubble))
                .combinedClickable(
                    onClick = { if (m.local == "failed") actions.onRetry(m) },
                    onLongClick = {
                        if (!dto.isDeleted && m.local == null) {
                            haptic.performHapticFeedback(HapticFeedbackType.LongPress)
                            actions.onLongPress(m)
                        }
                    },
                )
                .padding(if (media || isCard) 3.dp else 0.dp),
        ) {
            Column(Modifier.width(IntrinsicSize.Max)) {
                if (isGroup && !mine && firstInRun && dto.sender != null) {
                    Text(
                        dto.sender.name.orEmpty(), color = Ch.colorFor(dto.sender.key), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold,
                        modifier = Modifier.padding(start = 12.dp, end = 12.dp, top = 8.dp), maxLines = 1,
                    )
                }
                if (dto.isForwarded && !dto.isDeleted) ForwardedLabel(dto.forwardedManyTimes, mine)
                if (dto.isUrgent && !dto.isDeleted) UrgentLabel()
                // A business's welcome / away message, sent by itself.
                if (!dto.isDeleted && dto.meta?.get("auto_reply")?.takeIf { it.isJsonPrimitive } != null) AutoReplyLabel(mine)
                dto.replyTo?.let { if (!dto.isDeleted) ReplyQuote(it, mine) { it.id?.let(actions.onJumpTo) } }
                BubbleContent(m, mine, actions)
                // AI, only for me and only when I asked: the translation / transcript, or the
                // "show text" button under a voice message.
                m.ai?.let { AiNotePanel(it, mine) { actions.onHideAi(m) } }
                    ?: run {
                        if (dto.type in setOf("voice", "audio") && !dto.viewOnce && !dto.isDeleted && m.local == null && ChatAi.transcribe) {
                            TranscribeChip(mine) { actions.onTranscribe(m) }
                        }
                    }
            }
        }
        Reactions(dto, mine)
        dto.thread?.takeIf { it.count > 0 }?.let { t -> ThreadChip(t.count, mine) { actions.onOpenThread(dto) } }
        AnimatedVisibility(m.local == "failed", enter = fadeIn() + scaleIn()) {
            Row(Modifier.padding(top = 3.dp, end = 4.dp).clickable { actions.onRetry(m) }, verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.ErrorOutline, null, tint = FailedRed, modifier = Modifier.size(14.dp))
                Spacer(Modifier.width(4.dp))
                Text(stringResource(R.string.ch_failed), color = FailedRed, fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

private val FailedRed get() = Ch.Danger

@Composable
private fun UrgentLabel() {
    Row(Modifier.padding(start = 12.dp, end = 12.dp, top = 7.dp).clip(RoundedCornerShape(8.dp)).background(Ch.Danger).padding(horizontal = 7.dp, vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
        Text("🚨 " + stringResource(R.string.ch_urgent), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold)
    }
}

@Composable
private fun AutoReplyLabel(mine: Boolean) {
    val tint = if (mine) Ch.OutText.copy(alpha = 0.75f) else Ch.Soft
    Row(Modifier.padding(start = 12.dp, end = 12.dp, top = 7.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Rounded.Bolt, null, tint = tint, modifier = Modifier.size(14.dp))
        Spacer(Modifier.width(4.dp))
        Text(stringResource(R.string.ch_auto_reply), color = tint, fontSize = 11.5.sp, fontStyle = FontStyle.Italic)
    }
}

@Composable
private fun ForwardedLabel(many: Boolean, mine: Boolean) {
    Row(Modifier.padding(start = 12.dp, end = 12.dp, top = 7.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Rounded.Forward, null, tint = if (mine) Ch.OutText.copy(alpha = 0.75f) else Ch.Soft, modifier = Modifier.size(14.dp))
        Spacer(Modifier.width(4.dp))
        Text(stringResource(if (many) R.string.ch_forwarded_many else R.string.ch_forwarded), color = if (mine) Ch.OutText.copy(alpha = 0.75f) else Ch.Soft, fontSize = 11.5.sp, fontStyle = FontStyle.Italic)
    }
}

@Composable
private fun ReplyQuote(reply: com.dorr.app.network.ReplyPreviewDto, mine: Boolean, onClick: () -> Unit) {
    val accent = if (mine) Ch.OutText else Ch.colorFor(reply.sender?.key)
    Row(
        Modifier
            .padding(start = 6.dp, end = 6.dp, top = 6.dp)
            .fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .background(if (mine) Ch.OutText.copy(alpha = 0.16f) else Ch.SurfaceMuted)
            .clickable(onClick = onClick)
            .height(IntrinsicSize.Min),
    ) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(accent))
        Column(Modifier.weight(1f).padding(horizontal = 9.dp, vertical = 6.dp)) {
            Text(
                if (reply.sender?.isMe == true) stringResource(R.string.ch_you) else reply.sender?.name.orEmpty(),
                color = accent, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1,
            )
            val (icon, label) = previewOf(reply.type ?: "text", reply.isDeleted)
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (icon != null) Icon(icon, null, tint = if (mine) Ch.OutText.copy(alpha = 0.8f) else Ch.Soft, modifier = Modifier.size(14.dp).padding(end = 2.dp))
                Text(
                    if (reply.isDeleted) stringResource(R.string.ch_deleted) else reply.body?.takeIf { it.isNotBlank() } ?: label,
                    color = if (mine) Ch.OutText.copy(alpha = 0.85f) else Ch.Mut, fontSize = 12.5.sp, maxLines = 2, overflow = TextOverflow.Ellipsis,
                )
            }
        }
        reply.thumbnail?.let { thumb ->
            AsyncImage(ApiClient.mediaUrl(thumb), null, imageLoader = chatImages(LocalContext.current), contentScale = ContentScale.Crop, modifier = Modifier.size(48.dp))
        }
    }
}

// ------------------------------------------------------------------------------- contents

@Composable
private fun BubbleContent(m: UiMessage, mine: Boolean, actions: BubbleActions) {
    val dto = m.dto
    val textColor = if (mine) Ch.OutText else Ch.InText
    if (dto.isDeleted) {
        Row(Modifier.padding(horizontal = 12.dp, vertical = 9.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Block, null, tint = textColor.copy(alpha = 0.6f), modifier = Modifier.size(15.dp))
            Spacer(Modifier.width(6.dp))
            Text(stringResource(R.string.ch_deleted), color = textColor.copy(alpha = 0.7f), fontSize = 14.sp, fontStyle = FontStyle.Italic)
            Spacer(Modifier.width(8.dp))
            Footer(m, mine, overlay = false)
        }
        return
    }
    if (dto.viewOnce) {
        ViewOnceCard(m, mine, onOpen = { actions.onOpenViewOnce(m) }) { Footer(m, mine, overlay = false) }
        return
    }
    // Someone's sensitive message: nothing until I unlock it (fingerprint / screen lock).
    if (dto.isSensitive && !mine && !actions.isRevealed(m.id)) {
        SensitiveCard(mine, onReveal = { actions.onReveal(m) }) { Footer(m, mine, overlay = false) }
        return
    }
    when (dto.type) {
        "image", "video" -> MediaGrid(m, mine, actions)
        "voice", "audio" -> VoiceNote(m, mine)
        "document" -> DocumentCard(m, mine)
        "sticker" -> StickerBubble(m, mine) { Footer(m, mine, overlay = true) }
        "gif" -> GifBubble(m, mine) { Footer(m, mine, overlay = true) }
        "money_request" -> MoneyRequestCard(m, mine, onPay = { actions.onPay(m) }, onDecline = { actions.onDeclineRequest(m) }, onCancel = { actions.onCancelRequest(m) }) {
            Footer(m, mine, overlay = true)
        }
        "bill_split" -> BillSplitCard(m, mine, onPay = { actions.onPay(m) }, onDecline = { actions.onDeclineRequest(m) }, onCancel = { actions.onCancelRequest(m) }) {
            Footer(m, mine, overlay = false)
        }
        "poll" -> PollCard(m, mine, onVote = { actions.onVote(m, it) }, onShowVotes = { actions.onShowVotes(dto) }) { Footer(m, mine, overlay = false) }
        // A greeting card (DORR Moments): the occasion's look, words, voices, photos, a gift.
        "moment_card" -> com.dorr.app.ui.screens.moments.MomentCardBubble(
            dto, mine, onOpenSealed = { actions.onOpenSealed(m) }, onOpenPhoto = { actions.onOpenMedia(dto, it) },
        ) { Footer(m, mine, overlay = true) }
        // An event from DORR Discover: a snapshot card that opens the live event.
        "event_card" -> com.dorr.app.ui.screens.events.EventCardBubble(dto, mine) { Footer(m, mine, overlay = false) }
        // A match from DORR Sports: live score from Pusher, opens the match.
        "match_card" -> com.dorr.app.ui.screens.sports.MatchCardBubble(dto) { Footer(m, mine, overlay = false) }
        "location" -> if (dto.liveLocation != null) {
            LiveLocationCard(dto.meta, dto.liveLocation, mine, onStop = { actions.onStopLive(m) }) { Footer(m, mine, overlay = true) }
        } else LocationCard(dto.meta, m, mine)
        "contact" -> ContactCard(dto.meta, m, mine, actions)
        "wallet_transfer" -> TransferReceiptCard(dto.meta, m, mine)
        "wallet_qr" -> WalletQrCard(dto.meta, m, mine, actions)
        "call" -> CallLine(dto.meta, m, mine)
        "story_reply" -> Column {
            StoryQuote(dto.meta, mine)
            TextBody(m, mine, actions)
        }
        else -> Column {
            // The link's card above the text, like WhatsApp (arrives a moment after sending).
            dto.linkPreview?.let { LinkPreviewCard(it, mine) }
            TextBody(m, mine, actions)
        }
    }
}

/** The story a reply answers: "Status" + a small copy of it (its gradient and text, or its photo). */
@Composable
private fun StoryQuote(meta: JsonObject?, mine: Boolean) {
    val context = LocalContext.current
    val style = meta?.get("style")?.takeIf { it.isJsonObject }?.asJsonObject // JSON null (an image story has no style) is not an object
    val text = meta?.str("text")
    val thumb = meta?.str("thumbnail")
    Row(
        Modifier.padding(start = 6.dp, end = 6.dp, top = 6.dp).fillMaxWidth()
            .clip(RoundedCornerShape(12.dp)).background(if (mine) Ch.OutText.copy(alpha = 0.16f) else Ch.SurfaceMuted).height(IntrinsicSize.Min),
    ) {
        Box(Modifier.width(4.dp).fillMaxHeight().background(if (mine) Color.White else Ch.Red))
        Column(Modifier.weight(1f).padding(horizontal = 9.dp, vertical = 7.dp)) {
            Text(stringResource(R.string.st_status_label), color = if (mine) Ch.OutText else Ch.Red, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold)
            val (icon, label) = previewOf(meta?.str("story_type") ?: "text", false)
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (icon != null) Icon(icon, null, tint = if (mine) Ch.OutText.copy(alpha = 0.8f) else Ch.Soft, modifier = Modifier.size(14.dp).padding(end = 2.dp))
                Text(text?.takeIf { it.isNotBlank() } ?: label, color = if (mine) Ch.OutText.copy(alpha = 0.85f) else Ch.Mut, fontSize = 12.5.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
        }
        Box(Modifier.width(44.dp).height(62.dp).background(StoryLook.brush(style?.str("background"))), contentAlignment = Alignment.Center) {
            if (thumb != null) {
                AsyncImage(ApiClient.mediaUrl(thumb), null, imageLoader = chatImages(context), contentScale = ContentScale.Crop, modifier = Modifier.matchParentSize())
            } else if (text != null) {
                Text(text.take(12), color = Color.White, fontSize = 7.sp, fontWeight = FontWeight.Bold, maxLines = 3, modifier = Modifier.padding(3.dp))
            }
        }
    }
}

/** Text with the time tucked into the last line (WhatsApp-style) and links underlined. */
@Composable
private fun TextBody(m: UiMessage, mine: Boolean, actions: BubbleActions? = null) {
    val dto = m.dto
    val color = if (mine) Ch.OutText else Ch.InText
    val body = dto.body.orEmpty()
    val onlyEmoji = body.length <= 8 && body.isNotEmpty() && body.all { !it.isLetterOrDigit() && !it.isWhitespace() }
    val context = LocalContext.current
    val linkColor = if (mine) Ch.OutText else Color(0xFF2563EB)
    val mentionNames = dto.mentions.mapNotNull { it.name }.filter { it.isNotBlank() }
    val mentionColor = if (mine) Ch.OutText else Ch.Red
    val codeBg = if (mine) Color.White.copy(alpha = 0.2f) else Ch.Ink.copy(alpha = 0.08f)
    val annotated = remember(body, mine, mentionNames, linkColor, mentionColor, codeBg) {
        buildAnnotatedString {
            fun pieces(text: String) = parseChatText(text).forEach { piece ->
                when {
                    piece.url != null -> {
                        pushStringAnnotation("url", piece.url)
                        withStyle(SpanStyle(color = linkColor, textDecoration = TextDecoration.Underline, fontWeight = FontWeight.SemiBold)) { append(piece.text) }
                        pop()
                    }
                    piece.code || piece.codeBlock -> withStyle(SpanStyle(fontFamily = FontFamily.Monospace, background = codeBg, fontSize = 14.sp)) {
                        append(if (piece.codeBlock) piece.text.trim('\n') else piece.text)
                    }
                    else -> withStyle(
                        SpanStyle(
                            fontWeight = if (piece.bold) FontWeight.Bold else null,
                            fontStyle = if (piece.italic) FontStyle.Italic else null,
                            textDecoration = if (piece.strike) TextDecoration.LineThrough else null,
                        ),
                    ) { append(piece.text) }
                }
            }
            // *bold* _italic_ ~strike~ `code` ```block``` — marks removed, links never formatted.
            // Lists and quotes are laid out line by line: a hanging indent under the bullet / number,
            // a quote dimmed behind a bar.
            val lines = chatLines(body)
            if (lines == null) {
                pieces(body)
            } else {
                lines.forEachIndexed { i, line ->
                    when (line.kind) {
                        LineKind.Plain -> pieces(line.text)
                        LineKind.Quote -> {
                            withStyle(SpanStyle(color = mentionColor.copy(alpha = 0.55f), fontWeight = FontWeight.ExtraBold)) { append("▍ ") }
                            withStyle(SpanStyle(fontStyle = FontStyle.Italic, color = Color.Unspecified)) { pieces(line.text) }
                        }
                        else -> {
                            withStyle(SpanStyle(fontWeight = FontWeight.Bold)) { append(line.marker + "  ") }
                            pieces(line.text)
                        }
                    }
                    if (i < lines.lastIndex) append('\n')
                }
            }
            // "@Sara" of a real mention: bold, in the brand colour (white on my own red bubble).
            val shown = toAnnotatedString().text
            mentionNames.forEach { name ->
                var from = shown.indexOf("@$name")
                while (from >= 0) {
                    addStyle(SpanStyle(color = mentionColor, fontWeight = FontWeight.ExtraBold), from, from + name.length + 1)
                    from = shown.indexOf("@$name", from + 1)
                }
            }
        }
    }
    Box(Modifier.padding(start = 12.dp, end = 12.dp, top = 7.dp, bottom = 7.dp)) {
        androidx.compose.foundation.text.ClickableText(
            text = annotated,
            // My text size (spec 6).
            style = androidx.compose.ui.text.TextStyle(color = color, fontSize = if (onlyEmoji) 38.sp else (15.5f * ChatPrefs.fontScale).sp, lineHeight = if (onlyEmoji) 44.sp else (21f * ChatPrefs.fontScale).sp, fontFamily = com.dorr.app.ui.theme.CairoFontFamily),
            modifier = Modifier.padding(end = 62.dp, bottom = 2.dp),
            onClick = { offset ->
                annotated.getStringAnnotations("url", offset, offset).firstOrNull()?.let { a ->
                    // A Dorr group invite opens the join sheet right here, not a browser.
                    if (a.item.startsWith("dorr://chat/join/")) {
                        actions?.onJoinGroup?.invoke(a.item.removePrefix("dorr://chat/join/").trimEnd('.', ',', ')'))
                        return@let
                    }
                    val url = if (a.item.startsWith("http")) a.item else "https://${a.item}"
                    runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }
                }
            },
        )
        Footer(m, mine, overlay = false, modifier = Modifier.align(Alignment.BottomEnd))
    }
}

/** 950 · 1.2K · 3.4M */
internal fun compactCount(n: Int): String = when {
    n >= 1_000_000 -> String.format(java.util.Locale.US, "%.1fM", n / 1_000_000f).replace(".0M", "M")
    n >= 1_000 -> String.format(java.util.Locale.US, "%.1fK", n / 1_000f).replace(".0K", "K")
    else -> n.toString()
}

/** Time · edited · star · ticks — at the bottom corner of every bubble. */
@Composable
private fun Footer(m: UiMessage, mine: Boolean, overlay: Boolean, modifier: Modifier = Modifier) {
    val dto = m.dto
    val color = when {
        overlay -> Color.White
        mine -> Ch.OutText.copy(alpha = 0.8f)
        else -> Ch.Soft
    }
    Row(
        modifier.then(if (overlay) Modifier.clip(RoundedCornerShape(10.dp)).background(Color.Black.copy(alpha = 0.38f)).padding(horizontal = 7.dp, vertical = 2.dp) else Modifier),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(3.dp),
    ) {
        if (dto.expiresAt != null) Icon(Icons.Rounded.Timer, null, tint = color, modifier = Modifier.size(11.dp))
        if (dto.isStarred) Icon(Icons.Rounded.Star, null, tint = color, modifier = Modifier.size(11.dp))
        if (dto.reminderAt != null) Icon(Icons.Rounded.Alarm, null, tint = color, modifier = Modifier.size(11.dp))
        if (dto.isFollowUp) Icon(Icons.Rounded.Flag, null, tint = color, modifier = Modifier.size(11.dp))
        // Channel posts: 👁 1.2K — how many followers saw it.
        dto.views?.let { views ->
            Icon(Icons.Rounded.Visibility, null, tint = color, modifier = Modifier.size(12.dp))
            Text(compactCount(views), color = color, fontSize = 10.5.sp, fontWeight = FontWeight.SemiBold)
        }
        if (dto.isEdited) Text(stringResource(R.string.ch_edited), color = color, fontSize = 10.5.sp, fontStyle = FontStyle.Italic)
        Text(clockTime(dto.createdAt), color = color, fontSize = 10.5.sp)
        if (mine) ChTicks(m.status, onBubble = mine || overlay, size = 15.dp)
    }
}

@Composable
private fun MediaGrid(m: UiMessage, mine: Boolean, actions: BubbleActions) {
    val context = LocalContext.current
    val dto = m.dto
    val remote = dto.attachments
    val local = m.localFiles
    val count = if (remote.isNotEmpty()) remote.size else local.size
    val isVideo = dto.type == "video"
    val shape = RoundedCornerShape(17.dp)
    // "Blur photos and videos": covered until I tap (others' media only).
    val covered = !mine && com.dorr.app.chat.ChatShield.blurMedia && !actions.isRevealed(m.id) && remote.isNotEmpty()

    Column {
        Box(Modifier.width(260.dp).clip(shape)) {
            val cells = (0 until count.coerceAtMost(4)).toList()
            Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                cells.chunked(if (count == 1) 1 else 2).forEach { row ->
                    Row(horizontalArrangement = Arrangement.spacedBy(2.dp)) {
                        row.forEach { i ->
                            val ratio = if (count == 1) aspectOf(remote.getOrNull(0)) else 1f
                            Box(
                                Modifier.weight(1f).aspectRatio(ratio).background(Ch.SurfaceMuted)
                                    .clickable(enabled = remote.isNotEmpty()) { if (covered) actions.onReveal(m) else actions.onOpenMedia(dto, i) },
                            ) {
                                // Photos: the photo. Videos: their poster (the server's copy, or the one made on this phone).
                                val model: Any? = if (isVideo) remote.getOrNull(i)?.thumbnail?.let { ApiClient.mediaUrl(it) } ?: m.localThumb
                                else remote.getOrNull(i)?.let { ApiClient.mediaUrl(it.url) } ?: local.getOrNull(i)?.file
                                if (model != null) {
                                    AsyncImage(model, null, imageLoader = chatImages(context), contentScale = ContentScale.Crop, modifier = Modifier.matchParentSize().then(if (covered) Modifier.blur(28.dp) else Modifier))
                                }
                                if (covered) {
                                    // Phones before Android 12 can't blur: a solid veil instead.
                                    Box(Modifier.matchParentSize().background(Color.Black.copy(alpha = if (android.os.Build.VERSION.SDK_INT >= 31) 0.25f else 0.9f)), contentAlignment = Alignment.Center) {
                                        Icon(Icons.Rounded.VisibilityOff, null, tint = Color.White, modifier = Modifier.size(28.dp))
                                    }
                                }
                                if (isVideo) {
                                    if (m.local != "pending") {
                                        Box(Modifier.align(Alignment.Center).size(52.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.45f)), contentAlignment = Alignment.Center) {
                                            Icon(Icons.Rounded.PlayArrow, null, tint = Color.White, modifier = Modifier.size(32.dp))
                                        }
                                    }
                                    // Length in the corner, like every messenger.
                                    val length = dto.meta?.get("duration_ms")?.let { runCatching { it.asLong }.getOrNull() }
                                    if (length != null && length > 0) {
                                        Text(
                                            durationText(length), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                                            modifier = Modifier.align(Alignment.BottomStart).padding(8.dp).clip(RoundedCornerShape(8.dp)).background(Color.Black.copy(alpha = 0.45f)).padding(horizontal = 6.dp, vertical = 2.dp),
                                        )
                                    }
                                }
                                if (i == 3 && count > 4) {
                                    Box(Modifier.matchParentSize().background(Color.Black.copy(alpha = 0.5f)), contentAlignment = Alignment.Center) {
                                        Text("+${count - 4}", color = Color.White, fontSize = 26.sp, fontWeight = FontWeight.ExtraBold)
                                    }
                                }
                                if (m.local == "pending") UploadRing(Modifier.align(Alignment.Center), m.progress)
                            }
                        }
                    }
                }
            }
            if (dto.body.isNullOrBlank()) Footer(m, mine, overlay = true, modifier = Modifier.align(Alignment.BottomEnd).padding(8.dp))
        }
        if (!dto.body.isNullOrBlank()) {
            Box(Modifier.width(260.dp)) { TextBody(m, mine) }
        }
    }
}

private fun aspectOf(a: AttachmentDto?): Float {
    val w = a?.width ?: return 1.1f
    val h = a.height ?: return 1.1f
    if (w <= 0 || h <= 0) return 1.1f
    return (w.toFloat() / h).coerceIn(0.62f, 1.8f)
}

/** A spinning arc while a file uploads. */
@Composable
private fun UploadRing(modifier: Modifier, progress: Float? = null) {
    val t = androidx.compose.animation.core.rememberInfiniteTransition(label = "upload")
    val angle by t.animateFloat(0f, 360f, androidx.compose.animation.core.infiniteRepeatable(tween(900, easing = androidx.compose.animation.core.LinearEasing)), label = "angle")
    // Real progress glides between the 2% steps it's reported in.
    val shown by animateFloatAsState(progress ?: 0f, tween(250), label = "uploadProgress")
    Box(modifier.size(46.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.45f)), contentAlignment = Alignment.Center) {
        Canvas(Modifier.size(34.dp)) {
            val stroke = androidx.compose.ui.graphics.drawscope.Stroke(width = 3.dp.toPx(), cap = androidx.compose.ui.graphics.StrokeCap.Round)
            drawArc(Color.White.copy(alpha = 0.25f), 0f, 360f, useCenter = false, style = stroke)
            if (progress == null) drawArc(Color.White, angle, 100f, useCenter = false, style = stroke)
            else drawArc(Color.White, -90f, 360f * shown, useCenter = false, style = stroke)
        }
        if (progress != null) Text("${(shown * 100).toInt()}", color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.ExtraBold)
    }
}

/**
 * Voice note: round play button, the waveform that fills in the brand colour as it plays (tap or
 * drag on it to seek), the duration, and a 1× / 1.5× / 2× speed pill while playing.
 */
@Composable
private fun VoiceNote(m: UiMessage, mine: Boolean) {
    val dto = m.dto
    val url = dto.attachments.firstOrNull()?.url?.let { ApiClient.mediaUrl(it) }
    val playing = VoicePlayer.isPlaying(dto.id)
    val current = VoicePlayer.playingId == dto.id
    val progress = if (current) VoicePlayer.progress else 0f
    val waveform = remember(dto.meta) {
        dto.meta?.getAsJsonArray("waveform")?.mapNotNull { runCatching { it.asInt }.getOrNull() }?.takeIf { it.isNotEmpty() }
            ?: List(36) { i -> (20 + ((i * 37) % 60)) }
    }
    val durationMs = dto.meta?.get("duration_ms")?.let { runCatching { it.asLong }.getOrNull() } ?: dto.attachments.firstOrNull()?.durationMs ?: 0L
    val fg = if (mine) Ch.OutText else Ch.Red
    val track = if (mine) Ch.OutText.copy(alpha = 0.38f) else Ch.Red.copy(alpha = 0.22f)
    val buttonScale by animateFloatAsState(if (playing) 1.08f else 1f, spring(dampingRatio = 0.4f), label = "play")

    Row(Modifier.width(250.dp).padding(start = 8.dp, end = 10.dp, top = 8.dp, bottom = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(
            Modifier.size(42.dp).scale(buttonScale).clip(CircleShape).background(if (mine) Color.White else Ch.Red)
                .clickable(enabled = url != null && m.local == null) { url?.let { VoicePlayer.toggle(dto.id, it) } },
            contentAlignment = Alignment.Center,
        ) {
            if (m.local == "pending") UploadRing(Modifier.size(42.dp), m.progress)
            else Icon(if (playing) Icons.Rounded.Pause else Icons.Rounded.PlayArrow, null, tint = if (mine) Ch.Red else Color.White, modifier = Modifier.size(26.dp))
        }
        Spacer(Modifier.width(8.dp))
        Column(Modifier.weight(1f)) {
            Canvas(
                Modifier.fillMaxWidth().height(30.dp).pointerInput(dto.id) {
                    detectTapGestures { pos -> VoicePlayer.seek(dto.id, (pos.x / size.width).coerceIn(0f, 1f)) }
                },
            ) {
                val bars = waveform.size
                val gap = 2.dp.toPx()
                val w = (size.width - gap * (bars - 1)) / bars
                waveform.forEachIndexed { i, v ->
                    val h = (size.height * (0.18f + 0.82f * v / 100f)).coerceAtLeast(3.dp.toPx())
                    val x = i * (w + gap)
                    val filled = (i + 0.5f) / bars <= progress
                    drawRoundRect(if (filled) fg else track, Offset(x, (size.height - h) / 2), Size(w, h), CornerRadius(w / 2))
                }
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(durationText(if (current) VoicePlayer.positionMs else durationMs), color = if (mine) Ch.OutText.copy(alpha = 0.85f) else Ch.Mut, fontSize = 11.sp)
                if (current) {
                    Spacer(Modifier.width(6.dp))
                    Text(
                        "${VoicePlayer.speed.toString().removeSuffix(".0")}×",
                        color = if (mine) Ch.Red else Color.White, fontSize = 10.5.sp, fontWeight = FontWeight.ExtraBold,
                        modifier = Modifier.clip(RoundedCornerShape(8.dp)).background(if (mine) Color.White else Ch.Red).clickable { VoicePlayer.cycleSpeed() }.padding(horizontal = 6.dp, vertical = 1.dp),
                    )
                }
                Spacer(Modifier.weight(1f))
                Footer(m, mine, overlay = false)
            }
        }
    }
}

@Composable
private fun DocumentCard(m: UiMessage, mine: Boolean) {
    val context = LocalContext.current
    val file = m.dto.attachments.firstOrNull()
    val name = file?.name ?: m.localFiles.firstOrNull()?.name ?: ""
    val ext = name.substringAfterLast('.', "").uppercase().take(4)
    val pdf = file != null && ChatViewers.isPdf(name, file.mimeType)
    // A PDF opens inside the chat; other files in the app that handles them.
    val open = {
        file?.let {
            if (pdf) ChatViewers.pdf = PdfTarget(it.url, name)
            else runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(ApiClient.mediaUrl(it.url)))) }
        }
        Unit
    }
    Column(Modifier.width(260.dp).padding(6.dp)) {
        // A small PDF shows its first page.
        if (pdf && file != null && m.local == null) PdfFirstPage(file.url, file.size, Modifier.padding(bottom = 6.dp).clickable { open() })
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(if (mine) Ch.OutText.copy(alpha = 0.16f) else Ch.SurfaceMuted)
                .clickable(enabled = file != null) { open() }
                .padding(10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(Modifier.size(42.dp).clip(RoundedCornerShape(10.dp)).background(if (mine) Color.White else Ch.Red), contentAlignment = Alignment.Center) {
                if (m.local == "pending") UploadRing(Modifier.size(42.dp), m.progress)
                else Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Icon(Icons.Rounded.Description, null, tint = if (mine) Ch.Red else Color.White, modifier = Modifier.size(20.dp))
                    if (ext.isNotEmpty()) Text(ext, color = if (mine) Ch.Red else Color.White, fontSize = 8.sp, fontWeight = FontWeight.ExtraBold)
                }
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(name, color = if (mine) Ch.OutText else Ch.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Text(fileSizeText(file?.size), color = if (mine) Ch.OutText.copy(alpha = 0.75f) else Ch.Mut, fontSize = 11.sp)
            }
        }
        if (!m.dto.body.isNullOrBlank()) TextBody(m, mine) else Footer(m, mine, overlay = false, modifier = Modifier.align(Alignment.End).padding(top = 4.dp, end = 4.dp))
    }
}

/** A stylised map tile (drawn — no map API key needed) with the pin bouncing in. */
@Composable
private fun LocationCard(meta: JsonObject?, m: UiMessage, mine: Boolean) {
    val context = LocalContext.current
    val lat = meta?.get("latitude")?.asDouble ?: 0.0
    val lng = meta?.get("longitude")?.asDouble ?: 0.0
    val name = meta?.str("name")
    val pin = remember { Animatable(0f) }
    androidx.compose.runtime.LaunchedEffect(Unit) { pin.animateTo(1f, spring(dampingRatio = 0.35f, stiffness = 300f)) }
    Column(Modifier.width(260.dp).padding(3.dp)) {
        Box(
            Modifier.fillMaxWidth().height(140.dp).clip(RoundedCornerShape(17.dp))
                .clickable { runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("geo:$lat,$lng?q=$lat,$lng"))) } },
        ) {
            Canvas(Modifier.matchParentSize().background(Color(0xFFE8F0E3))) {
                val road = Color.White
                val block = Color(0xFFD5E4CF)
                for (i in 0..6) drawRect(block, Offset(i * size.width / 5f - 20f, (i % 3) * size.height / 3f + 12f), Size(size.width / 7f, size.height / 4.5f))
                drawLine(road, Offset(0f, size.height * 0.62f), Offset(size.width, size.height * 0.38f), strokeWidth = 14.dp.toPx())
                drawLine(road, Offset(size.width * 0.3f, 0f), Offset(size.width * 0.44f, size.height), strokeWidth = 9.dp.toPx())
                drawLine(Color(0xFFBFDBFE), Offset(size.width * 0.75f, 0f), Offset(size.width, size.height * 0.3f), strokeWidth = 18.dp.toPx())
            }
            Icon(
                Icons.Rounded.Place, null, tint = Ch.Red,
                modifier = Modifier.align(Alignment.Center).size(46.dp).graphicsLayer { translationY = -(1f - pin.value) * 60.dp.toPx() - 14.dp.toPx(); alpha = pin.value.coerceIn(0f, 1f) },
            )
            Footer(m, mine, overlay = true, modifier = Modifier.align(Alignment.BottomEnd).padding(8.dp))
        }
        Row(Modifier.padding(horizontal = 9.dp, vertical = 7.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Map, null, tint = if (mine) Ch.OutText else Ch.Red, modifier = Modifier.size(16.dp))
            Spacer(Modifier.width(6.dp))
            Text(name ?: stringResource(R.string.ch_open_maps), color = if (mine) Ch.OutText else Ch.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

@Composable
private fun ContactCard(meta: JsonObject?, m: UiMessage, mine: Boolean, actions: BubbleActions) {
    val name = meta?.str("name").orEmpty()
    val phones = meta?.getAsJsonArray("phones")?.mapNotNull { runCatching { it.asString }.getOrNull() }.orEmpty()
    // The whole card opens: call · message or invite · save · copy.
    var sheet by remember { mutableStateOf(false) }
    if (sheet) ContactActionsSheet(name, phones) { sheet = false }
    Column(Modifier.width(250.dp).clip(RoundedCornerShape(16.dp)).clickable(enabled = phones.isNotEmpty()) { sheet = true }.padding(8.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            ChAvatar(null, name, phones.firstOrNull() ?: name, size = 44.dp)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(name, color = if (mine) Ch.OutText else Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 14.5.sp, maxLines = 1)
                phones.firstOrNull()?.let { Text(ltrNumber(it) + if (phones.size > 1) "  +${phones.size - 1}" else "", color = if (mine) Ch.OutText.copy(alpha = 0.8f) else Ch.Mut, fontSize = 12.sp) }
            }
        }
        Spacer(Modifier.height(8.dp))
        Box(Modifier.fillMaxWidth().height(1.dp).background(if (mine) Ch.OutText.copy(alpha = 0.25f) else Ch.Line))
        Row(Modifier.fillMaxWidth().padding(top = 6.dp), verticalAlignment = Alignment.CenterVertically) {
            Text(
                stringResource(R.string.ch_message_contact), color = if (mine) Ch.OutText else Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 13.5.sp,
                modifier = Modifier.weight(1f).clip(RoundedCornerShape(8.dp)).clickable(enabled = phones.isNotEmpty()) { sheet = true }.padding(vertical = 4.dp),
            )
            Footer(m, mine, overlay = false)
        }
    }
}

/**
 * The transfer receipt: a small red "success" card like the wallet's own success screen — amount
 * big, a check that pops, the masked recipient and the reference. Built by the server from the
 * sender's real transfer, so it can be trusted.
 */
@Composable
private fun TransferReceiptCard(meta: JsonObject?, m: UiMessage, mine: Boolean) {
    val amount = meta?.get("amount_minor")?.let { runCatching { it.asLong }.getOrNull() } ?: 0L
    val currency = meta?.str("currency_symbol") ?: meta?.str("currency")
    val reversed = meta?.get("is_reversed")?.asBoolean == true
    val check = remember { Animatable(0f) }
    androidx.compose.runtime.LaunchedEffect(Unit) { check.animateTo(1f, spring(dampingRatio = 0.35f, stiffness = 260f)) }
    // A money gift: the same receipt, drawn as a card for the occasion (it unwraps as it appears).
    val gift = giftOf(meta?.str("gift"))
    Column(
        Modifier.width(262.dp).clip(RoundedCornerShape(20.dp)).background(Ch.HeaderBrush).padding(16.dp),
    ) {
        if (gift != null) {
            Column(Modifier.fillMaxWidth().padding(bottom = 10.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Text(gift.first, fontSize = 46.sp, modifier = Modifier.scale(0.6f + 0.4f * check.value))
                Text(gift.second, color = Color.White, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(34.dp).scale(check.value).clip(CircleShape).background(Ch.Surface), contentAlignment = Alignment.Center) {
                Icon(if (reversed) Icons.Rounded.ErrorOutline else Icons.Rounded.CheckCircle, null, tint = Ch.Red, modifier = Modifier.size(24.dp))
            }
            Spacer(Modifier.width(10.dp))
            Column {
                Text(stringResource(R.string.ch_transfer_receipt), color = Color.White.copy(alpha = 0.85f), fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
                Text(stringResource(if (reversed) R.string.ch_transfer_reversed else R.string.ch_transfer_done), color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            }
        }
        Spacer(Modifier.height(14.dp))
        androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Text(formatMinor(amount, currency), color = Color.White, fontSize = 30.sp, fontWeight = FontWeight.ExtraBold, letterSpacing = (-1).sp)
        }
        Spacer(Modifier.height(6.dp))
        meta?.str("recipient_name")?.let { Text(stringResource(R.string.ch_transfer_to, it), color = Color.White.copy(alpha = 0.9f), fontSize = 13.sp, fontWeight = FontWeight.SemiBold) }
        meta?.str("recipient_wallet_number")?.let { Text(it, color = Color.White.copy(alpha = 0.7f), fontSize = 12.sp) }
        Spacer(Modifier.height(10.dp))
        Box(Modifier.fillMaxWidth().height(1.dp).background(Color.White.copy(alpha = 0.25f)))
        Row(Modifier.fillMaxWidth().padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            Text("#" + meta?.str("transaction_id").orEmpty().take(8).uppercase(), color = Color.White.copy(alpha = 0.7f), fontSize = 11.sp, modifier = Modifier.weight(1f))
            Footer(m, true, overlay = false)
        }
    }
}

/** Someone's wallet QR (drawn with zxing) + "Send money" — opens the wallet's confirmation. */
@Composable
private fun WalletQrCard(meta: JsonObject?, m: UiMessage, mine: Boolean, actions: BubbleActions) {
    val payload = meta?.str("qr_payload").orEmpty()
    val bitmap = remember(payload) { runCatching { qrImage(payload) }.getOrNull() }
    Column(
        Modifier.width(250.dp).shadow(4.dp, RoundedCornerShape(20.dp)).clip(RoundedCornerShape(20.dp)).background(Ch.Surface),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(Modifier.fillMaxWidth().background(Ch.HeaderBrush).padding(12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.QrCode2, null, tint = Color.White, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Column {
                    Text(stringResource(R.string.ch_wallet_qr_title), color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                    Text(meta?.str("owner_name").orEmpty(), color = Color.White.copy(alpha = 0.8f), fontSize = 12.sp)
                }
            }
        }
        if (bitmap != null) {
            Image(bitmap.asImageBitmap(), null, modifier = Modifier.padding(top = 14.dp).size(150.dp))
        }
        Text(meta?.str("wallet_number").orEmpty(), color = Ch.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 6.dp))
        Text(stringResource(R.string.ch_wallet_qr_sub), color = Ch.Mut, fontSize = 11.5.sp)
        if (!mine && payload.isNotEmpty()) {
            ChPrimaryButton(stringResource(R.string.ch_wallet_qr_pay), modifier = Modifier.padding(12.dp).fillMaxWidth(), icon = Icons.Rounded.QrCode2) { actions.onPayQr(payload) }
        }
        Footer(m, mine = false, overlay = false, modifier = Modifier.align(Alignment.End).padding(end = 12.dp, bottom = 8.dp, top = if (mine) 10.dp else 0.dp))
    }
}

internal fun qrImage(text: String, sizePx: Int = 600): android.graphics.Bitmap {
    val matrix = com.google.zxing.qrcode.QRCodeWriter().encode(text, com.google.zxing.BarcodeFormat.QR_CODE, 0, 0, mapOf(com.google.zxing.EncodeHintType.MARGIN to 1))
    val cell = sizePx / matrix.width
    val bmp = android.graphics.Bitmap.createBitmap(cell * matrix.width, cell * matrix.height, android.graphics.Bitmap.Config.ARGB_8888)
    bmp.eraseColor(android.graphics.Color.WHITE)
    val paint = android.graphics.Paint().apply { color = android.graphics.Color.parseColor("#111928") }
    val canvas = android.graphics.Canvas(bmp)
    for (x in 0 until matrix.width) for (y in 0 until matrix.height) {
        if (matrix[x, y]) canvas.drawRoundRect((x * cell).toFloat(), (y * cell).toFloat(), ((x + 1) * cell).toFloat(), ((y + 1) * cell).toFloat(), cell * 0.25f, cell * 0.25f, paint)
    }
    return bmp
}

@Composable
private fun CallLine(meta: JsonObject?, m: UiMessage, mine: Boolean) {
    val status = meta?.str("status")
    val video = meta?.str("call_type") == "video"
    val missed = status == "missed" || status == "cancelled" || status == "declined"
    val duration = meta?.get("duration_seconds")?.takeIf { !it.isJsonNull }?.asInt
    Row(Modifier.padding(horizontal = 12.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(38.dp).clip(CircleShape).background(if (mine) Ch.OutText.copy(alpha = 0.2f) else if (missed) Color(0xFFFEE2E2) else Color(0xFFDCFCE7)), contentAlignment = Alignment.Center) {
            Icon(
                if (missed) Icons.Rounded.CallMissed else if (video) Icons.Rounded.Videocam else Icons.Rounded.Call, null,
                tint = if (mine) Ch.OutText else if (missed) Ch.Danger else Ch.Success, modifier = Modifier.size(20.dp),
            )
        }
        Spacer(Modifier.width(10.dp))
        Column {
            Text(
                stringResource(
                    when {
                        status == "missed" -> R.string.ch_call_missed
                        status == "declined" -> R.string.ch_call_declined
                        status == "cancelled" -> R.string.ch_call_cancelled
                        video -> R.string.ch_call_video
                        else -> R.string.ch_call_voice
                    },
                ),
                color = if (mine) Ch.OutText else Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp,
            )
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (duration != null && duration > 0) Text(durationText(duration * 1000L) + "  ", color = if (mine) Ch.OutText.copy(alpha = 0.8f) else Ch.Mut, fontSize = 12.sp)
                Footer(m, mine, overlay = false)
            }
        }
    }
}

/** Reaction chips hanging under the bubble; they spring in when the first one lands. */
@Composable
private fun Reactions(dto: MessageDto, mine: Boolean) {
    val summary = dto.reactions.summary
    AnimatedVisibility(summary.isNotEmpty(), enter = scaleIn(spring(dampingRatio = 0.4f, stiffness = 500f)) + fadeIn()) {
        Row(
            Modifier
                .offset(y = (-6).dp)
                .padding(horizontal = 10.dp)
                .shadow(4.dp, RoundedCornerShape(14.dp))
                .clip(RoundedCornerShape(14.dp))
                .background(Ch.Surface)
                .border(1.5.dp, if (dto.reactions.mine != null) Ch.Red.copy(alpha = 0.35f) else Color.White, RoundedCornerShape(14.dp))
                .padding(horizontal = 7.dp, vertical = 2.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(2.dp),
        ) {
            summary.take(3).forEach { Text(it.emoji, fontSize = 14.sp) }
            if (dto.reactions.total > 1) Text(dto.reactions.total.toString(), color = Ch.Mut, fontSize = 11.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 2.dp))
        }
    }
}

/** "Ahmed is typing…" bubble at the bottom of the conversation. */
@Composable
fun TypingBubble(label: String?) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 4.dp).chPopIn(true), verticalAlignment = Alignment.CenterVertically) {
        Row(
            Modifier.shadow(2.dp, RoundedCornerShape(20.dp)).clip(RoundedCornerShape(topStart = 6.dp, topEnd = 20.dp, bottomEnd = 20.dp, bottomStart = 20.dp)).background(Ch.Surface).padding(horizontal = 16.dp, vertical = 13.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            TypingDots(color = Ch.Red.copy(alpha = 0.8f), dot = 7.dp)
            if (label != null) {
                Spacer(Modifier.width(8.dp))
                Text(label, color = Ch.Mut, fontSize = 12.sp)
            }
        }
    }
}

