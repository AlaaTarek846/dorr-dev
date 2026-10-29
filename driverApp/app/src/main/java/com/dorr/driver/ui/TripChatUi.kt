package com.dorr.driver.ui

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.spring
import androidx.compose.animation.fadeIn
import androidx.compose.animation.scaleIn
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Call
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
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.dorr.driver.R
import com.dorr.driver.net.MessageDto
import com.dorr.driver.net.QuickDto
import com.dorr.driver.ui.theme.DorrBrand
import com.dorr.driver.ui.theme.Pal
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/** The chat's roles in the captain app's colours (the DORR app's chat look, O-61). */
private object Ch {
    val Red get() = DorrBrand.navy
    val Ink get() = Pal.ink
    val Mut get() = Pal.mut
    val Soft get() = Pal.faint
    val Surface get() = Pal.card
    val SurfaceMuted get() = Pal.well
    val FieldFill get() = Pal.bg
    val FieldLine get() = Pal.line
    val InBubble get() = Pal.card
    val InText get() = Pal.ink
    val OutText = Color.White
    val OutBubble get() = androidx.compose.ui.graphics.Brush.linearGradient(listOf(DorrBrand.navy, androidx.compose.ui.graphics.lerp(DorrBrand.navy, Color.Black, 0.25f)))
    val HeaderBrush get() = androidx.compose.ui.graphics.Brush.linearGradient(listOf(DorrBrand.navy, androidx.compose.ui.graphics.lerp(DorrBrand.navy, Color.Black, 0.45f)))
    val Wallpaper get() = androidx.compose.ui.graphics.Brush.verticalGradient(listOf(androidx.compose.ui.graphics.lerp(Pal.bg, DorrBrand.navy, 0.05f), Pal.bg))
    val BubbleRadius = 20.dp
    val TailRadius = 6.dp
}

/*
 * The ride's chat with the rider, in the look of the chat between people (docs/rides_tasks.md O-61): the
 * same header gradient, wallpaper, bubbles (mine in the app colour's gradient, theirs as soft cards, the
 * small «tail» corner), times under them, the ready-made replies as chips and the pill composer with its
 * round send button. Full screen, like a conversation.
 */
@Composable
internal fun TripChatScreen(
    title: String,
    /** The person on the other side is a verified Dorr account: the tick after their name. */
    titleVerified: Boolean = false,
    subtitle: String,
    messages: List<MessageDto>,
    quick: List<QuickDto>,
    open: Boolean,
    onSend: (body: String?, quickKey: String?) -> Unit,
    onCall: (() -> Unit)?,
    onClose: () -> Unit,
) {
    var text by remember { mutableStateOf("") }
    val list = rememberLazyListState()
    LaunchedEffect(messages.size) { if (messages.isNotEmpty()) list.animateScrollToItem(messages.size - 1) }

    Dialog(onDismissRequest = onClose, properties = DialogProperties(usePlatformDefaultWidth = false, decorFitsSystemWindows = false)) {
        Column(Modifier.fillMaxSize().background(Ch.Wallpaper).imePadding()) {
            // The header: the app colour deepening, like a conversation's.
            Row(
                Modifier.fillMaxWidth().background(Ch.HeaderBrush).statusBarsPadding().padding(horizontal = 10.dp, vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(Modifier.size(40.dp).clip(CircleShape).clickable(onClick = onClose), contentAlignment = Alignment.Center) {
                    Icon(Icons.AutoMirrored.Rounded.ArrowBack, null, tint = Color.White)
                }
                Box(Modifier.size(42.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.2f)), contentAlignment = Alignment.Center) {
                    Text(title.take(1).ifEmpty { "?" }, color = Color.White, fontWeight = FontWeight.Black, fontSize = 18.sp)
                }
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(title, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 16.sp, maxLines = 1, modifier = Modifier.weight(1f, fill = false))
                        RiderTick(titleVerified, 15.dp, tint = Color.White)
                    }
                    Text(subtitle, color = Color.White.copy(alpha = 0.8f), fontSize = 12.sp, maxLines = 1)
                }
                onCall?.let {
                    Box(Modifier.size(42.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.18f)).clickable(onClick = it), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Call, null, tint = Color.White)
                    }
                }
            }

            LazyColumn(Modifier.weight(1f).fillMaxWidth(), state = list, contentPadding = androidx.compose.foundation.layout.PaddingValues(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                if (messages.isEmpty()) item {
                    Text(
                        stringResource(R.string.trip_chat_empty), color = Ch.Mut, fontSize = 13.sp, textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth().padding(top = 40.dp),
                    )
                }
                items(messages, key = { it.id }) { m -> Bubble(m) }
            }

            if (!open) {
                Text(
                    stringResource(R.string.trip_chat_closed), color = Ch.Mut, fontSize = 13.sp, textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().background(Ch.Surface).navigationBarsPadding().padding(16.dp),
                )
                return@Column
            }
            Column(Modifier.fillMaxWidth().background(Ch.Surface).navigationBarsPadding().padding(bottom = 8.dp)) {
                // The ready-made replies, one tap each.
                if (quick.isNotEmpty()) {
                    LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp), contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 12.dp, vertical = 8.dp)) {
                        items(quick) { q ->
                            Text(
                                q.text, fontSize = 13.sp, color = Ch.Red, fontWeight = FontWeight.SemiBold,
                                modifier = Modifier.clip(CircleShape).background(Ch.Red.copy(alpha = 0.08f)).border(1.dp, Ch.Red.copy(alpha = 0.2f), CircleShape)
                                    .clickable { onSend(null, q.key) }.padding(horizontal = 14.dp, vertical = 8.dp),
                            )
                        }
                    }
                }
                // The composer: a pill field and the round send button.
                Row(Modifier.fillMaxWidth().padding(horizontal = 10.dp), verticalAlignment = Alignment.Bottom) {
                    Box(
                        Modifier.weight(1f).heightIn(min = 46.dp).clip(RoundedCornerShape(24.dp)).background(Ch.FieldFill)
                            .border(1.dp, Ch.FieldLine, RoundedCornerShape(24.dp)).padding(horizontal = 16.dp, vertical = 12.dp),
                        contentAlignment = Alignment.CenterStart,
                    ) {
                        if (text.isEmpty()) Text(stringResource(R.string.trip_chat_hint), color = Ch.Soft, fontSize = 15.sp)
                        BasicTextField(
                            value = text, onValueChange = { text = it.take(1000) }, textStyle = TextStyle(color = Ch.Ink, fontSize = 15.sp),
                            cursorBrush = SolidColor(Ch.Red), maxLines = 5, modifier = Modifier.fillMaxWidth(),
                        )
                    }
                    Spacer(Modifier.width(8.dp))
                    AnimatedVisibility(true, enter = scaleIn(spring(dampingRatio = 0.5f)) + fadeIn()) {
                        Box(
                            Modifier.size(46.dp).shadow(if (text.isBlank()) 0.dp else 6.dp, CircleShape).clip(CircleShape)
                                .background(if (text.isBlank()) Ch.SurfaceMuted else Ch.Red)
                                .clickable(enabled = text.isNotBlank()) { onSend(text.trim(), null); text = "" },
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.AutoMirrored.Rounded.Send, null, tint = if (text.isBlank()) Ch.Soft else Color.White) }
                    }
                }
            }
        }
    }
}

@Composable
private fun Bubble(m: MessageDto) {
    val mine = m.mine ?: (m.from == "driver")
    val shape = RoundedCornerShape(
        topStart = Ch.BubbleRadius, topEnd = Ch.BubbleRadius,
        bottomStart = if (mine) Ch.BubbleRadius else Ch.TailRadius, bottomEnd = if (mine) Ch.TailRadius else Ch.BubbleRadius,
    )
    Box(Modifier.fillMaxWidth(), contentAlignment = if (mine) Alignment.CenterEnd else Alignment.CenterStart) {
        Column(
            Modifier.widthIn(max = 300.dp).shadow(if (mine) 0.dp else 1.dp, shape).clip(shape)
                .then(if (mine) Modifier.background(Ch.OutBubble) else Modifier.background(Ch.InBubble))
                .padding(horizontal = 12.dp, vertical = 8.dp),
            horizontalAlignment = Alignment.End,
        ) {
            Text(m.body, color = if (mine) Ch.OutText else Ch.InText, fontSize = 15.sp, lineHeight = 21.sp)
            Text(timeOf(m.createdAt), color = (if (mine) Ch.OutText else Ch.Mut).copy(alpha = 0.75f), fontSize = 10.sp, modifier = Modifier.padding(top = 2.dp))
        }
    }
}

private fun timeOf(iso: String?): String = iso?.let {
    runCatching { OffsetDateTime.parse(it).atZoneSameInstant(ZoneId.systemDefault()).format(DateTimeFormatter.ofPattern("h:mm a")) }.getOrNull()
}.orEmpty()
