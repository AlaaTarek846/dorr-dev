package com.dorr.app.ui.screens.chat

import android.app.Activity
import android.content.Intent
import android.net.Uri
import android.view.WindowManager
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
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
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Canvas
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
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.BarChart
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckBox
import androidx.compose.material.icons.rounded.CheckBoxOutlineBlank
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.HelpOutline
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.MyLocation
import androidx.compose.material.icons.rounded.NearMe
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.Poll
import androidx.compose.material.icons.rounded.RadioButtonChecked
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Send
import androidx.compose.material.icons.rounded.SentimentDissatisfied
import androidx.compose.material.icons.rounded.StopCircle
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AttachmentDto
import com.dorr.app.network.InvitePreviewDto
import com.dorr.app.network.JoinRequestDto
import com.dorr.app.network.LinkPreviewDto
import com.dorr.app.network.LiveLocationDto
import com.dorr.app.network.MessageDto
import com.dorr.app.network.PollOptionVotersDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.theme.CairoFontFamily
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

// =============================================================================== link card

/** The page behind a link: its picture on top, then site · title · a line of text. Tap → browser. */
@Composable
fun LinkPreviewCard(preview: LinkPreviewDto, mine: Boolean) {
    val context = LocalContext.current
    val ink = if (mine) Ch.OutText else Ch.InText
    val pop = remember { Animatable(0f) }
    LaunchedEffect(preview.url) { pop.animateTo(1f, spring(dampingRatio = 0.7f, stiffness = 300f)) }
    Column(
        Modifier.padding(start = 4.dp, end = 4.dp, top = 4.dp).width(260.dp)
            .graphicsLayer { alpha = pop.value; scaleY = 0.85f + 0.15f * pop.value; transformOrigin = androidx.compose.ui.graphics.TransformOrigin(0.5f, 0f) }
            .clip(RoundedCornerShape(14.dp))
            .background(if (mine) Color.Black.copy(alpha = 0.14f) else Ch.SurfaceMuted)
            .clickable { runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(preview.url))) } },
    ) {
        preview.image?.let { image ->
            AsyncImage(
                ApiClient.mediaUrl(image), null, imageLoader = chatImages(context), contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxWidth().height(136.dp),
            )
        }
        Column(Modifier.padding(horizontal = 10.dp, vertical = 8.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Rounded.Language, null, tint = ink.copy(alpha = 0.6f), modifier = Modifier.size(12.dp))
                Spacer(Modifier.width(4.dp))
                Text(preview.siteName.orEmpty().uppercase(), color = ink.copy(alpha = 0.6f), fontSize = 10.5.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            preview.title?.let { Text(it, color = ink, fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis, lineHeight = 18.sp) }
            preview.description?.let { Text(it, color = ink.copy(alpha = 0.75f), fontSize = 12.sp, maxLines = 2, overflow = TextOverflow.Ellipsis, lineHeight = 16.sp) }
        }
    }
}

/**
 * While typing a message with a link: its card slides up over the input (the server fetches it
 * once; the message then carries the same card). The ✕ hides it for this link.
 */
@Composable
fun ComposerLinkPreview(text: String) {
    val url = remember(text) { Regex("(https?://\\S+|www\\.\\S+)").find(text)?.value?.trimEnd('.', ',', ')', '!', '?') }
    var preview by remember { mutableStateOf<LinkPreviewDto?>(null) }
    var dismissed by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(url) {
        preview = null
        if (url == null) return@LaunchedEffect
        delay(500) // wait until the link is typed / pasted completely
        val json = runCatching { ApiClient.chat.linkPreview(chatAuth(), url).data }.getOrNull()
        preview = json?.takeIf { it.isJsonObject && it.asJsonObject.has("url") }?.let {
            com.google.gson.Gson().fromJson(it, LinkPreviewDto::class.java)
        }
    }
    val show = preview != null && dismissed != url
    AnimatedVisibility(show, enter = slideInVertically { it / 2 } + fadeIn() + expandVertically(), exit = fadeOut() + shrinkVertically()) {
        val p = preview ?: return@AnimatedVisibility
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 10.dp, vertical = 4.dp).clip(RoundedCornerShape(16.dp)).background(Ch.Surface).padding(8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            if (p.image != null) {
                AsyncImage(ApiClient.mediaUrl(p.image), null, imageLoader = chatImages(LocalContext.current), contentScale = ContentScale.Crop, modifier = Modifier.size(52.dp).clip(RoundedCornerShape(10.dp)))
            } else {
                Box(Modifier.size(52.dp).clip(RoundedCornerShape(10.dp)).background(Ch.Red.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Language, null, tint = Ch.Red, modifier = Modifier.size(24.dp))
                }
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(p.title ?: p.siteName.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(p.description ?: p.url, color = Ch.Mut, fontSize = 11.5.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
            Box(Modifier.size(30.dp).clip(CircleShape).clickable { dismissed = url }, contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Close, null, tint = Ch.Mut, modifier = Modifier.size(18.dp))
            }
        }
    }
}

// =============================================================================== polls

/**
 * A poll: the question, then each option as a row whose bar fills with its share of the votes
 * (springing as votes arrive live), a tick for mine, and "View votes" at the bottom.
 */
@Composable
fun PollCard(m: UiMessage, mine: Boolean, onVote: (Int) -> Unit, onShowVotes: () -> Unit, footer: @Composable () -> Unit) {
    val poll = m.dto.poll ?: return
    val ink = if (mine) Ch.OutText else Ch.InText
    val accent = if (mine) Ch.OutText else Ch.Red
    val total = poll.options.sumOf { it.votes }.coerceAtLeast(1)
    val top = poll.options.maxOfOrNull { it.votes } ?: 0
    Column(Modifier.width(270.dp).padding(horizontal = 12.dp, vertical = 10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Poll, null, tint = accent, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(6.dp))
            Text(
                stringResource(if (poll.multiple) R.string.ch_poll_pick_many else R.string.ch_poll_pick_one),
                color = ink.copy(alpha = 0.65f), fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold,
            )
        }
        Text(poll.question.orEmpty(), color = ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 4.dp, bottom = 8.dp))
        poll.options.forEachIndexed { i, option ->
            val chosen = option.id in poll.myVotes
            val share by animateFloatAsState(option.votes.toFloat() / total, spring(dampingRatio = 0.75f, stiffness = 220f), label = "pollShare")
            val tick by animateFloatAsState(if (chosen) 1f else 0f, spring(dampingRatio = 0.45f), label = "pollTick")
            Column(
                Modifier.fillMaxWidth().chStagger(i).padding(vertical = 5.dp).clip(RoundedCornerShape(12.dp))
                    .clickable(enabled = m.local == null) { onVote(option.id) }.padding(vertical = 3.dp),
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(22.dp), contentAlignment = Alignment.Center) {
                        Icon(
                            if (poll.multiple) Icons.Rounded.CheckBoxOutlineBlank else Icons.Rounded.RadioButtonUnchecked, null,
                            tint = ink.copy(alpha = 0.45f), modifier = Modifier.size(21.dp).graphicsLayer { alpha = 1f - tick },
                        )
                        Icon(
                            if (poll.multiple) Icons.Rounded.CheckBox else Icons.Rounded.CheckCircle, null,
                            tint = accent, modifier = Modifier.size(21.dp).scale(tick).graphicsLayer { alpha = tick },
                        )
                    }
                    Spacer(Modifier.width(8.dp))
                    Text(option.text, color = ink, fontSize = 14.sp, fontWeight = if (chosen) FontWeight.ExtraBold else FontWeight.SemiBold, modifier = Modifier.weight(1f))
                    AnimatedContent(option.votes, label = "pollCount", transitionSpec = { (slideInVertically { -it } + fadeIn()) togetherWith fadeOut() }) { n ->
                        Text("$n", color = ink.copy(alpha = 0.75f), fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                    }
                }
                Box(Modifier.padding(start = 30.dp, top = 5.dp).fillMaxWidth().height(6.dp).clip(RoundedCornerShape(3.dp)).background(ink.copy(alpha = 0.12f))) {
                    Box(
                        Modifier.fillMaxWidth(share).height(6.dp).clip(RoundedCornerShape(3.dp))
                            .background(if (option.votes == top && top > 0) accent else accent.copy(alpha = 0.55f)),
                    )
                }
            }
        }
        Box(Modifier.fillMaxWidth().padding(top = 6.dp)) {
            Row(
                Modifier.clip(RoundedCornerShape(10.dp)).clickable(enabled = poll.voters > 0, onClick = onShowVotes).padding(horizontal = 6.dp, vertical = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.BarChart, null, tint = accent, modifier = Modifier.size(15.dp))
                Spacer(Modifier.width(4.dp))
                Text(
                    if (poll.voters == 0) stringResource(R.string.ch_poll_no_votes) else stringResource(R.string.ch_poll_view_votes, poll.voters),
                    color = accent, fontSize = 12.5.sp, fontWeight = FontWeight.ExtraBold,
                )
            }
            Box(Modifier.align(Alignment.CenterEnd)) { footer() }
        }
    }
}

/** Who voted for what — one section per option, the most voted first. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PollVotesSheet(message: MessageDto, onDismiss: () -> Unit) {
    var rows by remember { mutableStateOf<List<PollOptionVotersDto>?>(null) }
    LaunchedEffect(message.id) { rows = runCatching { ApiClient.chat.votes(chatAuth(), message.id).data }.getOrNull().orEmpty() }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconBadge(Icons.Rounded.Poll, Ch.Red)
                Spacer(Modifier.width(12.dp))
                Text(message.poll?.question.orEmpty(), color = Ch.Ink, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
            Spacer(Modifier.height(12.dp))
            val list = rows
            if (list == null) {
                Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
            } else {
                LazyColumn(Modifier.heightIn(max = 460.dp)) {
                    list.sortedByDescending { it.voters.size }.forEachIndexed { i, option ->
                        item(key = "o${option.id}") {
                            Row(Modifier.fillMaxWidth().chStagger(i).padding(top = 12.dp, bottom = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                                Text(option.text, color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 14.5.sp, modifier = Modifier.weight(1f))
                                Text(stringResource(R.string.ch_poll_votes_count, option.voters.size), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                            }
                        }
                        itemsIndexed(option.voters, key = { _, p -> "o${option.id}-${p.key}" }) { j, person ->
                            Row(Modifier.fillMaxWidth().chStagger(i + j).padding(vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
                                ChAvatar(person.avatar, person.name, person.key, size = 36.dp)
                                Spacer(Modifier.width(10.dp))
                                Text(if (person.isMe) stringResource(R.string.ch_you) else person.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                            }
                        }
                    }
                }
            }
        }
    }
}

/** Ask a question with 2–12 options; "Allow several answers" is a switch. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PollComposerSheet(onDismiss: () -> Unit, onSend: (question: String, options: List<String>, multiple: Boolean) -> Unit) {
    var question by remember { mutableStateOf("") }
    val options = remember { mutableStateListOf("", "") }
    var multiple by remember { mutableStateOf(false) }
    val filled = options.map { it.trim() }.filter { it.isNotEmpty() }.distinct()
    val ready = question.isNotBlank() && filled.size >= 2
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().heightIn(max = 620.dp).verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconBadge(Icons.Rounded.Poll, Color(0xFFF59E0B))
                Spacer(Modifier.width(12.dp))
                Text(stringResource(R.string.ch_poll_new), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            }
            Spacer(Modifier.height(14.dp))
            SheetField(question, stringResource(R.string.ch_poll_question_hint), bold = true, icon = Icons.Rounded.HelpOutline) { question = it.take(300) }
            Spacer(Modifier.height(12.dp))
            Text(stringResource(R.string.ch_poll_options), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 4.dp, bottom = 6.dp))
            options.forEachIndexed { i, value ->
                Row(Modifier.padding(vertical = 4.dp).chStagger(i), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.weight(1f)) { SheetField(value, stringResource(R.string.ch_poll_option_hint, i + 1), icon = Icons.Rounded.RadioButtonUnchecked) { options[i] = it.take(100) } }
                    AnimatedVisibility(options.size > 2, enter = scaleIn() + fadeIn(), exit = scaleOut() + fadeOut()) {
                        Box(Modifier.padding(start = 6.dp).size(34.dp).clip(CircleShape).clickable { if (options.size > 2) options.removeAt(i) }, contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.Close, null, tint = Ch.Mut, modifier = Modifier.size(18.dp))
                        }
                    }
                }
            }
            AnimatedVisibility(options.size < 12) {
                Row(
                    Modifier.padding(top = 4.dp).clip(RoundedCornerShape(14.dp)).clickable { options.add("") }.padding(horizontal = 8.dp, vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.Add, null, tint = Ch.Red, modifier = Modifier.size(20.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(stringResource(R.string.ch_poll_add_option), color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp)
                }
            }
            Row(
                Modifier.fillMaxWidth().padding(top = 6.dp).clip(RoundedCornerShape(14.dp)).clickable { multiple = !multiple }.padding(vertical = 10.dp, horizontal = 4.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(stringResource(R.string.ch_poll_allow_many), color = Ch.Ink, fontWeight = FontWeight.SemiBold, fontSize = 14.sp, modifier = Modifier.weight(1f))
                ChSwitch(multiple)
            }
            Spacer(Modifier.height(12.dp))
            ChPrimaryButton(stringResource(R.string.ch_send), icon = Icons.Rounded.Send, modifier = Modifier.fillMaxWidth(), enabled = ready) {
                onSend(question.trim(), filled, multiple)
                onDismiss()
            }
        }
    }
}

// =============================================================================== view once

/**
 * A view-once photo / video / voice note: never a preview, just a round "1" badge and what it
 * is. Theirs: tap to open (once). Opened: greyed "Opened". Mine: "Photo" → "Opened" when seen.
 */
@Composable
fun ViewOnceCard(m: UiMessage, mine: Boolean, onOpen: () -> Unit, footer: @Composable () -> Unit) {
    val dto = m.dto
    val opened = dto.viewOnceOpened == true
    val ink = if (mine) Ch.OutText else Ch.InText
    val canOpen = !mine && !opened && m.local == null
    val pulse = rememberInfiniteTransition(label = "viewOnce")
    val glow by pulse.animateFloat(0.85f, 1.08f, infiniteRepeatable(tween(1100, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "voGlow")
    val (icon, label) = when (dto.type) {
        "video" -> Icons.Rounded.Videocam to R.string.ch_view_once_video
        "voice" -> Icons.Rounded.Mic to R.string.ch_view_once_voice
        else -> Icons.Rounded.Image to R.string.ch_view_once_photo
    }
    Row(
        Modifier.width(230.dp).clickable(enabled = canOpen, onClick = onOpen).padding(horizontal = 12.dp, vertical = 11.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            Modifier.size(42.dp).scale(if (canOpen) glow else 1f).clip(CircleShape)
                .then(if (opened) Modifier.border(2.dp, ink.copy(alpha = 0.35f), CircleShape) else Modifier.background(if (mine) Ch.OutText.copy(alpha = 0.2f) else Ch.Red)),
            contentAlignment = Alignment.Center,
        ) {
            if (opened) Icon(Icons.Rounded.Visibility, null, tint = ink.copy(alpha = 0.5f), modifier = Modifier.size(20.dp))
            else Text("1", color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp)
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(icon, null, tint = ink.copy(alpha = if (opened) 0.5f else 0.9f), modifier = Modifier.size(15.dp))
                Spacer(Modifier.width(4.dp))
                Text(stringResource(if (opened) R.string.ch_view_once_opened else label), color = ink.copy(alpha = if (opened) 0.55f else 1f), fontWeight = FontWeight.ExtraBold, fontSize = 14.sp)
            }
            if (canOpen) Text(stringResource(R.string.ch_view_once_tap), color = ink.copy(alpha = 0.6f), fontSize = 11.5.sp)
        }
        footer()
    }
}

/**
 * The view-once file, full screen, this one time: screenshots blocked while it's up; closing it
 * is final. Images show; videos and voice notes play once.
 */
@Composable
fun ViewOnceViewer(files: List<AttachmentDto>, onClose: () -> Unit) {
    val context = LocalContext.current
    val file = files.firstOrNull() ?: return
    DisposableEffect(Unit) {
        val window = (context as? Activity)?.window
        window?.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
        onDispose { window?.clearFlags(WindowManager.LayoutParams.FLAG_SECURE) }
    }
    BackHandler(onBack = onClose)
    val appear = remember { Animatable(0.9f) }
    LaunchedEffect(Unit) { appear.animateTo(1f, spring(dampingRatio = 0.7f)) }
    Box(
        Modifier.fillMaxSize().background(Color.Black)
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { },
        contentAlignment = Alignment.Center,
    ) {
        val url = ApiClient.mediaUrl(file.url)
        val mime = file.mimeType.orEmpty()
        when {
            mime.startsWith("image/") -> AsyncImage(url, null, imageLoader = chatImages(context), contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize().scale(appear.value))
            else -> AndroidView(
                factory = { ctx ->
                    android.widget.VideoView(ctx).apply {
                        setVideoURI(Uri.parse(url))
                        setOnPreparedListener { it.start() }
                        setOnCompletionListener { onClose() }
                    }
                },
                onRelease = { it.stopPlayback() },
                modifier = Modifier.fillMaxWidth().then(if (mime.startsWith("audio/")) Modifier.height(1.dp) else Modifier),
            )
        }
        if (mime.startsWith("audio/")) {
            val wave = rememberInfiniteTransition(label = "voWave")
            val lift by wave.animateFloat(0.4f, 1f, infiniteRepeatable(tween(600, easing = LinearEasing), RepeatMode.Reverse), label = "voLift")
            Box(Modifier.size(140.dp).scale(0.8f + 0.2f * lift).clip(CircleShape).background(Ch.HeaderBrush), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Mic, null, tint = Color.White, modifier = Modifier.size(56.dp))
            }
        }
        Row(Modifier.align(Alignment.TopCenter).padding(top = 18.dp).clip(RoundedCornerShape(16.dp)).background(Color.White.copy(alpha = 0.14f)).padding(horizontal = 14.dp, vertical = 7.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(20.dp).clip(CircleShape).background(Ch.Red), contentAlignment = Alignment.Center) { Text("1", color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold) }
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.ch_view_once_title), color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.Bold)
        }
        Box(Modifier.align(Alignment.TopEnd).padding(14.dp).size(42.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.16f)).clickable(onClick = onClose), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Close, null, tint = Color.White, modifier = Modifier.size(24.dp))
        }
    }
}

// =============================================================================== live location

/**
 * A live location: the drawn map with a pin that glides to each new position, a pulsing ring
 * while it's live, "Live until 3:45 PM" (a countdown in the last hour), and — mine — "Stop sharing".
 */
@Composable
fun LiveLocationCard(meta: JsonObject?, live: LiveLocationDto, mine: Boolean, onStop: () -> Unit, footer: @Composable () -> Unit) {
    val context = LocalContext.current
    val lat = meta?.get("latitude")?.asDouble ?: 0.0
    val lng = meta?.get("longitude")?.asDouble ?: 0.0
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(Unit) { while (true) { delay(15_000); now = System.currentTimeMillis() } }
    val until = parseInstant(live.liveUntil)?.toEpochMilli() ?: 0L
    val active = live.active && until > now
    val ink = if (mine) Ch.OutText else Ch.InText
    val pulse = rememberInfiniteTransition(label = "live")
    val ring by pulse.animateFloat(0f, 1f, infiniteRepeatable(tween(1800, easing = LinearEasing)), label = "liveRing")
    // Each new position nudges the pin a little: it looks like it's walking.
    val nudge = remember { Animatable(0f) }
    LaunchedEffect(lat, lng) {
        nudge.snapTo(-8f)
        nudge.animateTo(0f, spring(dampingRatio = 0.4f, stiffness = 300f))
    }
    val activeColor by animateColorAsState(if (active) Ch.Success else Ch.Mut, label = "liveColor")

    Column(Modifier.width(260.dp).padding(3.dp)) {
        Box(
            Modifier.fillMaxWidth().height(150.dp).clip(RoundedCornerShape(17.dp))
                .clickable { runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("geo:$lat,$lng?q=$lat,$lng"))) } },
        ) {
            Canvas(Modifier.fillMaxSize().background(Color(0xFFE8F0E3))) {
                val block = Color(0xFFD5E4CF)
                for (i in 0..6) drawRect(block, Offset(i * size.width / 5f - 20f, (i % 3) * size.height / 3f + 12f), Size(size.width / 7f, size.height / 4.5f))
                drawLine(Color.White, Offset(0f, size.height * 0.62f), Offset(size.width, size.height * 0.38f), strokeWidth = 14.dp.toPx())
                drawLine(Color.White, Offset(size.width * 0.3f, 0f), Offset(size.width * 0.44f, size.height), strokeWidth = 9.dp.toPx())
                drawLine(Color(0xFFBFDBFE), Offset(size.width * 0.75f, 0f), Offset(size.width, size.height * 0.3f), strokeWidth = 18.dp.toPx())
                if (active) {
                    val c = Offset(size.width / 2, size.height / 2)
                    drawCircle(Ch.Success.copy(alpha = 0.35f * (1f - ring)), radius = 16.dp.toPx() + 34.dp.toPx() * ring, center = c)
                    drawCircle(Ch.Success.copy(alpha = 0.18f), radius = 16.dp.toPx(), center = c)
                }
            }
            Box(Modifier.align(Alignment.Center).graphicsLayer { translationY = nudge.value.dp.toPx() - 14.dp.toPx() }) {
                Icon(Icons.Rounded.Place, null, tint = if (active) Ch.Success else Ch.Red, modifier = Modifier.size(44.dp))
            }
            Row(
                Modifier.align(Alignment.TopStart).padding(8.dp).clip(RoundedCornerShape(10.dp)).background(Color.Black.copy(alpha = 0.45f)).padding(horizontal = 8.dp, vertical = 3.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(Modifier.size(7.dp).clip(CircleShape).background(activeColor))
                Spacer(Modifier.width(5.dp))
                Text(stringResource(if (active) R.string.ch_live_badge else R.string.ch_live_ended), color = Color.White, fontSize = 11.sp, fontWeight = FontWeight.ExtraBold)
            }
            Box(Modifier.align(Alignment.BottomEnd).padding(8.dp)) { footer() }
        }
        Row(Modifier.padding(horizontal = 9.dp, vertical = 7.dp), verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.NearMe, null, tint = if (active) activeColor else ink.copy(alpha = 0.6f), modifier = Modifier.size(16.dp))
            Spacer(Modifier.width(6.dp))
            Text(
                if (active) liveLabel(until, now) else stringResource(R.string.ch_live_ended_long),
                color = ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f), maxLines = 1,
            )
        }
        if (mine && active) {
            Row(
                Modifier.fillMaxWidth().padding(horizontal = 6.dp).padding(bottom = 6.dp).clip(RoundedCornerShape(12.dp))
                    .background(Ch.OutText.copy(alpha = 0.16f)).clickable(onClick = onStop).padding(vertical = 9.dp),
                horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.StopCircle, null, tint = ink, modifier = Modifier.size(17.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(R.string.ch_live_stop), color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 13.5.sp)
            }
        }
    }
}

@Composable
private fun liveLabel(until: Long, now: Long): String {
    val minutes = ((until - now) / 60_000L).coerceAtLeast(1)
    return if (minutes < 60) stringResource(R.string.ch_live_minutes_left, minutes.toInt())
    else stringResource(R.string.ch_live_until, java.time.Instant.ofEpochMilli(until).atZone(java.time.ZoneId.systemDefault()).toLocalTime().format(java.time.format.DateTimeFormatter.ofPattern("h:mm a")))
}

/** Send my location now, or share it live for 15 min / 1 h / 8 h. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LocationShareSheet(onDismiss: () -> Unit, onCurrent: () -> Unit, onLive: (Int) -> Unit) {
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 30.dp)) {
            Text(stringResource(R.string.ch_attach_location), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            Spacer(Modifier.height(14.dp))
            ShareRow(Icons.Rounded.MyLocation, stringResource(R.string.ch_location_current), stringResource(R.string.ch_location_current_sub), Color(0xFF2563EB), 0) { onCurrent() }
            Text(stringResource(R.string.ch_live_title), color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 4.dp, top = 14.dp, bottom = 6.dp))
            listOf(900 to R.string.ch_live_15m, 3600 to R.string.ch_live_1h, 28800 to R.string.ch_live_8h).forEachIndexed { i, (seconds, label) ->
                ShareRow(Icons.Rounded.NearMe, stringResource(label), stringResource(R.string.ch_live_sub), Ch.Success, i + 1) { onLive(seconds) }
            }
        }
    }
}

@Composable
private fun ShareRow(icon: ImageVector, title: String, sub: String, color: Color, index: Int, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(vertical = 4.dp).chStagger(index).clip(RoundedCornerShape(18.dp)).background(Ch.SurfaceMuted).clickable(onClick = onClick).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconBadge(icon, color)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 14.5.sp)
            Text(sub, color = Ch.Mut, fontSize = 12.sp)
        }
    }
}

// =============================================================================== joining a group by link

/**
 * An invite link was opened: the group's photo, name and size, then "Join" — or "Ask to join"
 * when its admins approve new members (then "Request sent · Cancel").
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun JoinGroupSheet(token: String, onDismiss: () -> Unit) {
    val host = LocalChat.current
    var preview by remember { mutableStateOf<InvitePreviewDto?>(null) }
    var failed by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    val sent = stringResource(R.string.ch_join_request_sent)
    LaunchedEffect(token) {
        try {
            preview = ApiClient.chat.previewInvite(chatAuth(), token).data
        } catch (e: Exception) {
            failed = e.apiFailure().message ?: ""
        }
    }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 24.dp).padding(bottom = 30.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            val p = preview
            when {
                failed != null -> {
                    IconBadge(Icons.Rounded.SentimentDissatisfied, Ch.Mut, size = 64)
                    Spacer(Modifier.height(12.dp))
                    Text(failed.orEmpty().ifBlank { stringResource(R.string.ch_error_network) }, color = Ch.Ink, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
                }
                p == null -> Box(Modifier.height(180.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(28.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
                else -> {
                    val pop = remember { Animatable(0.6f) }
                    LaunchedEffect(Unit) { pop.animateTo(1f, spring(dampingRatio = 0.45f, stiffness = 300f)) }
                    Box(Modifier.scale(pop.value)) { ChAvatar(p.avatar, p.name, p.conversationId, size = 92.dp) }
                    Spacer(Modifier.height(12.dp))
                    Text(p.name, color = Ch.Ink, fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
                    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(top = 4.dp)) {
                        Icon(Icons.Rounded.Groups, null, tint = Ch.Mut, modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(4.dp))
                        Text(stringResource(R.string.ch_members, p.membersCount), color = Ch.Mut, fontSize = 13.sp)
                    }
                    p.description?.takeIf { it.isNotBlank() }?.let {
                        Text(it, color = Ch.Mut, fontSize = 13.5.sp, textAlign = TextAlign.Center, maxLines = 4, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 10.dp))
                    }
                    if (p.approveJoins && !p.isMember) {
                        Row(
                            Modifier.padding(top = 14.dp).clip(RoundedCornerShape(12.dp)).background(Color(0xFFF59E0B).copy(alpha = 0.12f)).padding(horizontal = 12.dp, vertical = 8.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(Icons.Rounded.HourglassTop, null, tint = Color(0xFFD97706), modifier = Modifier.size(16.dp))
                            Spacer(Modifier.width(6.dp))
                            Text(stringResource(R.string.ch_join_needs_approval), color = Color(0xFFB45309), fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                        }
                    }
                    Spacer(Modifier.height(20.dp))
                    AnimatedContent(p.requestStatus to p.isMember, label = "joinState") { (status, member) ->
                        when {
                            member -> ChPrimaryButton(stringResource(R.string.ch_open_group), icon = Icons.Rounded.Groups, modifier = Modifier.fillMaxWidth()) {
                                onDismiss()
                                host.push(ChRoute.Conversation(p.conversationId))
                            }
                            status == "pending" -> Column(horizontalAlignment = Alignment.CenterHorizontally) {
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Icon(Icons.Rounded.CheckCircle, null, tint = Ch.Success, modifier = Modifier.size(20.dp))
                                    Spacer(Modifier.width(6.dp))
                                    Text(sent, color = Ch.Ink, fontWeight = FontWeight.ExtraBold)
                                }
                                Text(
                                    stringResource(R.string.ch_join_cancel), color = Ch.Red, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp,
                                    modifier = Modifier.padding(top = 10.dp).clip(RoundedCornerShape(12.dp)).clickable {
                                        host.scope.launch {
                                            runCatching { ApiClient.chat.cancelJoin(chatAuth(), token) }
                                            preview = p.copy(requestStatus = null)
                                        }
                                    }.padding(horizontal = 14.dp, vertical = 8.dp),
                                )
                            }
                            else -> ChPrimaryButton(
                                stringResource(if (p.approveJoins) R.string.ch_join_ask else R.string.ch_join_group),
                                icon = if (p.approveJoins) Icons.Rounded.Send else Icons.Rounded.Groups,
                                modifier = Modifier.fillMaxWidth(), enabled = !busy,
                            ) {
                                busy = true
                                host.scope.launch {
                                    try {
                                        val result = ApiClient.chat.joinByInvite(chatAuth(), token).data?.takeIf { it.isJsonObject }?.asJsonObject
                                        if (result?.get("status")?.asString == "pending") {
                                            preview = p.copy(requestStatus = "pending")
                                        } else {
                                            host.refreshList()
                                            onDismiss()
                                            host.push(ChRoute.Conversation(result?.get("id")?.asString ?: p.conversationId))
                                        }
                                    } catch (e: Exception) {
                                        e.apiFailure().message?.let { host.showToast(it) }
                                    }
                                    busy = false
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

/** "You're in!" / "Not this time" — after the admins answered my request. */
@Composable
fun JoinDecisionBanner(decision: JoinDecision, onOpen: () -> Unit, onDismiss: () -> Unit) {
    LaunchedEffect(decision) { delay(6000); onDismiss() }
    val color = if (decision.approved) Ch.Success else Ch.Mut
    Row(
        Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 10.dp).shadow(14.dp, RoundedCornerShape(20.dp)).clip(RoundedCornerShape(20.dp)).background(Ch.Surface)
            .clickable(enabled = decision.approved) { onOpen() }.padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconBadge(if (decision.approved) Icons.Rounded.CheckCircle else Icons.Rounded.SentimentDissatisfied, color)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(
                stringResource(if (decision.approved) R.string.ch_join_approved else R.string.ch_join_rejected),
                color = Ch.Ink, fontWeight = FontWeight.ExtraBold, fontSize = 14.5.sp,
            )
            Text(decision.groupName, color = Ch.Mut, fontSize = 12.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Box(Modifier.size(30.dp).clip(CircleShape).clickable(onClick = onDismiss), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Close, null, tint = Ch.Mut, modifier = Modifier.size(18.dp))
        }
    }
}

/** Admins: who's asking to join, with ✓ and ✕ that slide the row away. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun JoinRequestsSheet(conversationId: String, onDismiss: () -> Unit, onChanged: () -> Unit) {
    val host = LocalChat.current
    var rows by remember { mutableStateOf<List<JoinRequestDto>?>(null) }
    LaunchedEffect(conversationId) { rows = runCatching { ApiClient.chat.joinRequests(chatAuth(), conversationId).data }.getOrNull().orEmpty() }
    fun decide(request: JoinRequestDto, approve: Boolean) {
        rows = rows?.filterNot { it.id == request.id }
        host.scope.launch {
            try {
                rows = if (approve) ApiClient.chat.approveJoin(chatAuth(), conversationId, request.id).data
                else ApiClient.chat.rejectJoin(chatAuth(), conversationId, request.id).data
                onChanged()
            } catch (e: Exception) {
                e.apiFailure().message?.let { host.showToast(it) }
            }
        }
    }
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 26.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconBadge(Icons.Rounded.HourglassTop, Color(0xFFF59E0B))
                Spacer(Modifier.width(12.dp))
                Text(stringResource(R.string.ch_join_requests), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
            }
            Spacer(Modifier.height(10.dp))
            val list = rows
            when {
                list == null -> Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
                list.isEmpty() -> Text(stringResource(R.string.ch_join_requests_empty), color = Ch.Mut, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(vertical = 30.dp))
                else -> LazyColumn(Modifier.heightIn(max = 460.dp)) {
                    itemsIndexed(list, key = { _, r -> r.id }) { i, r ->
                        Row(
                            Modifier.fillMaxWidth().animateItem().chStagger(i).padding(vertical = 4.dp).clip(RoundedCornerShape(18.dp)).background(Ch.SurfaceMuted).padding(10.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            ChAvatar(r.profile?.avatar, r.profile?.name, r.profile?.key, size = 44.dp)
                            Spacer(Modifier.width(10.dp))
                            Column(Modifier.weight(1f)) {
                                Text(r.profile?.name.orEmpty(), color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.5.sp, maxLines = 1)
                                Text(listTime(r.requestedAt), color = Ch.Mut, fontSize = 12.sp)
                            }
                            RoundIcon(Icons.Rounded.Close, Ch.Danger.copy(alpha = 0.12f), Ch.Danger) { decide(r, false) }
                            Spacer(Modifier.width(8.dp))
                            RoundIcon(Icons.Rounded.Check, Ch.Success, Color.White) { decide(r, true) }
                        }
                    }
                }
            }
        }
    }
}

// =============================================================================== small shared bits

@Composable
private fun IconBadge(icon: ImageVector, color: Color, size: Int = 42) {
    Box(Modifier.size(size.dp).clip(CircleShape).background(color.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = color, modifier = Modifier.size((size * 0.52f).dp))
    }
}

@Composable
private fun RoundIcon(icon: ImageVector, background: Color, tint: Color, onClick: () -> Unit) {
    val press = remember { MutableInteractionSource() }
    val scale by com.dorr.app.ui.screens.wallet.rememberPressScale(press, 0.85f)
    Box(
        Modifier.size(38.dp).scale(scale).clip(CircleShape).background(background).clickable(interactionSource = press, indication = null, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = tint, modifier = Modifier.size(20.dp)) }
}

@Composable
private fun SheetField(value: String, hint: String, bold: Boolean = false, icon: ImageVector? = null, onChange: (String) -> Unit) {
    ChField(value, onChange, hint, icon = icon, bold = bold, singleLine = !bold, minLines = 1)
}

/** A small on/off pill, springing its knob across. */
@Composable
fun ChSwitch(on: Boolean) {
    val x by animateFloatAsState(if (on) 1f else 0f, spring(dampingRatio = 0.6f, stiffness = 500f), label = "switch")
    val track by animateColorAsState(if (on) Ch.Red else Ch.Line, label = "switchTrack")
    Box(Modifier.size(width = 46.dp, height = 28.dp).clip(RoundedCornerShape(14.dp)).background(track).padding(3.dp)) {
        Box(Modifier.graphicsLayer { translationX = x * 18.dp.toPx() }.size(22.dp).shadow(3.dp, CircleShape).clip(CircleShape).background(Color.White))
    }
}
