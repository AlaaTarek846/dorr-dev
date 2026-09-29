package com.dorr.app.ui.screens.chat

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.expandVertically
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.togetherWith
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
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckBox
import androidx.compose.material.icons.rounded.CheckBoxOutlineBlank
import androidx.compose.material.icons.rounded.EditNote
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.RadioButtonChecked
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
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
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ChatThemeDto
import com.dorr.app.network.ConversationDto
import com.dorr.app.network.ReportTypeDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.theme.CairoFontFamily
import kotlinx.coroutines.launch

// =============================================================================== wallpaper

/**
 * The conversation background for a theme: its image (cropped, slightly dimmed so bubbles stay
 * readable), or its colour with the doodle pattern, or — no theme — Dorr's own wallpaper.
 * Crossfades when the theme changes.
 */
@Composable
fun ThemedWallpaper(theme: ChatThemeDto?, modifier: Modifier = Modifier) {
    val context = LocalContext.current
    AnimatedContent(theme, transitionSpec = { fadeIn(tween(420)) togetherWith fadeOut(tween(420)) }, label = "wallpaper", modifier = modifier.fillMaxSize()) { t ->
        when {
            t == null -> ChWallpaper()
            t.wallpaper != null -> Box(Modifier.fillMaxSize().background(hexColor(t.backgroundColor) ?: Ch.Bg)) {
                AsyncImage(ApiClient.mediaUrl(t.wallpaper), null, imageLoader = chatImages(context), contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                Box(Modifier.fillMaxSize().background(Color.Black.copy(alpha = if (t.isDark || Ch.dark) 0.28f else 0.06f)))
            }
            else -> Box(Modifier.fillMaxSize().background(hexColor(t.backgroundColor) ?: Ch.Bg)) {
                ChDoodles(tint = hexColor(t.senderColor)?.let { if (t.isDark) Color.White else it.shade(0.6f) } ?: Ch.Red)
            }
        }
    }
}

/** Just the doodle pattern of [ChWallpaper], over whatever is behind it. */
@Composable
private fun ChDoodles(tint: Color) {
    // ChWallpaper paints its own wash first; a transparent wash here keeps the theme colour.
    Box(Modifier.fillMaxSize()) { ChWallpaper(tint = tint, wash = false) }
}

// =============================================================================== theme picker

/**
 * Pick a look for this chat: a live preview on top (wallpaper + two bubbles) that changes as you
 * tap the swatches below, then "Apply". "Dorr" = the app's own look.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ThemePickerSheet(conversation: ConversationDto, onDismiss: () -> Unit, onApplied: (ConversationDto) -> Unit) {
    val host = LocalChat.current
    var themes by remember { mutableStateOf<List<ChatThemeDto>?>(null) }
    var picked by remember { mutableStateOf(conversation.theme?.themeId) }
    var saving by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { themes = runCatching { ApiClient.chat.themes(chatAuth()).data }.getOrNull().orEmpty() }

    val list = themes
    // What "no pick" shows: the admin's default when there is one, else Dorr's look.
    val fallback = list?.firstOrNull { it.isDefault }
    val previewTheme = picked?.let { id -> list?.firstOrNull { it.id == id } } ?: fallback

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(bottom = 26.dp)) {
            Text(stringResource(R.string.ch_chat_theme), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(horizontal = 20.dp))
            Text(stringResource(R.string.ch_chat_theme_sub), color = Ch.Mut, fontSize = 13.sp, modifier = Modifier.padding(horizontal = 20.dp, vertical = 2.dp))
            Spacer(Modifier.height(14.dp))

            ThemePreview(previewTheme, Modifier.padding(horizontal = 20.dp).fillMaxWidth().height(210.dp))
            Spacer(Modifier.height(16.dp))

            if (list == null) {
                Box(Modifier.fillMaxWidth().height(96.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
            } else {
                LazyRow(contentPadding = PaddingValues(horizontal = 20.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    item(key = "dorr") { Swatch(null, stringResource(R.string.ch_theme_dorr), selected = picked == null && fallback == null, index = 0) { picked = null } }
                    itemsIndexed(list, key = { _, t -> t.id }) { i, t ->
                        Swatch(t, t.name.orEmpty(), selected = (picked ?: fallback?.id) == t.id, index = i + 1) { picked = t.id }
                    }
                }
            }

            Spacer(Modifier.height(18.dp))
            ChPrimaryButton(
                stringResource(R.string.ch_apply), icon = Icons.Rounded.Check,
                modifier = Modifier.padding(horizontal = 20.dp).fillMaxWidth(),
                enabled = !saving && list != null && picked != conversation.theme?.themeId,
            ) {
                saving = true
                host.scope.launch {
                    try {
                        ApiClient.chat.updateSettings(chatAuth(), conversation.id, mapOf("theme_id" to picked)).data?.let(onApplied)
                        onDismiss()
                    } catch (e: Exception) {
                        e.apiFailure().message?.let { host.showToast(it) }
                    }
                    saving = false
                }
            }
        }
    }
}

/** A mini chat: the wallpaper with one bubble each way — what the conversation will look like. */
@Composable
private fun ThemePreview(theme: ChatThemeDto?, modifier: Modifier) {
    val outColor by animateColorAsState(hexColor(theme?.senderColor) ?: Ch.Red, tween(380), label = "pOut")
    val inColor by animateColorAsState(hexColor(theme?.receiverColor) ?: Ch.Surface, tween(380), label = "pIn")
    Box(modifier.shadow(10.dp, RoundedCornerShape(24.dp)).clip(RoundedCornerShape(24.dp))) {
        ThemedWallpaper(theme)
        Column(Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp, Alignment.Bottom)) {
            ChChip(stringResource(R.string.ch_today), Modifier.align(Alignment.CenterHorizontally))
            PreviewBubble(stringResource(R.string.ch_theme_sample_in), inColor, mine = false, Modifier.align(Alignment.Start))
            PreviewBubble(stringResource(R.string.ch_theme_sample_out), outColor, mine = true, Modifier.align(Alignment.End))
        }
    }
}

@Composable
private fun PreviewBubble(text: String, color: Color, mine: Boolean, modifier: Modifier) {
    val shape = if (mine) RoundedCornerShape(18.dp, 18.dp, 4.dp, 18.dp) else RoundedCornerShape(18.dp, 18.dp, 18.dp, 4.dp)
    Text(
        text, color = if (color.isLight()) Color(0xFF111928) else Color.White, fontSize = 13.5.sp, fontWeight = FontWeight.SemiBold,
        modifier = modifier.shadow(3.dp, shape).clip(shape).background(color).padding(horizontal = 14.dp, vertical = 9.dp),
    )
}

@Composable
private fun Swatch(theme: ChatThemeDto?, label: String, selected: Boolean, index: Int, onClick: () -> Unit) {
    val ring by animateDpAsState(if (selected) 3.dp else 0.dp, spring(dampingRatio = 0.5f), label = "ring")
    val context = LocalContext.current
    Column(Modifier.width(74.dp).chStagger(index), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(
            Modifier.size(width = 66.dp, height = 88.dp)
                .border(ring, Ch.Red, RoundedCornerShape(18.dp))
                .padding(if (selected) 5.dp else 0.dp)
                .clip(RoundedCornerShape(14.dp))
                .clickable(onClick = onClick),
        ) {
            when {
                theme == null -> ChWallpaper()
                theme.wallpaper != null -> AsyncImage(ApiClient.mediaUrl(theme.wallpaper), null, imageLoader = chatImages(context), contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                else -> Box(Modifier.fillMaxSize().background(hexColor(theme.backgroundColor) ?: Ch.SurfaceMuted))
            }
            Column(Modifier.fillMaxSize().padding(7.dp), verticalArrangement = Arrangement.spacedBy(5.dp, Alignment.Bottom)) {
                Box(Modifier.size(30.dp, 9.dp).clip(RoundedCornerShape(5.dp)).background(hexColor(theme?.receiverColor) ?: Ch.Surface))
                Box(Modifier.align(Alignment.End).size(30.dp, 9.dp).clip(RoundedCornerShape(5.dp)).background(hexColor(theme?.senderColor) ?: Ch.Red))
            }
            androidx.compose.animation.AnimatedVisibility(selected, enter = scaleIn(spring(dampingRatio = 0.45f)), exit = scaleOut(), modifier = Modifier.align(Alignment.TopEnd).padding(5.dp)) {
                Box(Modifier.size(20.dp).clip(CircleShape).background(Ch.Red), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(13.dp))
                }
            }
        }
        Text(label, color = if (selected) Ch.Ink else Ch.Mut, fontSize = 12.sp, fontWeight = if (selected) FontWeight.ExtraBold else FontWeight.Medium, maxLines = 1, modifier = Modifier.padding(top = 6.dp))
    }
}

// =============================================================================== report

/**
 * Report a chat: pick a reason, add details if you like, and optionally block the person / leave
 * the group in the same step. The last messages go with it as evidence — the sheet says so.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ReportSheet(conversation: ConversationDto, onDismiss: () -> Unit, onReported: (blockedOrLeft: Boolean) -> Unit) {
    val host = LocalChat.current
    var types by remember { mutableStateOf<List<ReportTypeDto>?>(null) }
    var picked by remember { mutableStateOf<Int?>(null) }
    var details by remember { mutableStateOf("") }
    var alsoBlock by remember { mutableStateOf(true) }
    var sending by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { types = runCatching { ApiClient.chat.reportTypes(chatAuth()).data }.getOrNull().orEmpty() }
    val sent = stringResource(R.string.ch_report_sent)
    val canBlock = !conversation.isGroup && conversation.peer != null && conversation.iBlocked != true
    val canLeave = conversation.isGroup && conversation.isMember

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().imePadding().heightIn(max = 640.dp).verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(40.dp).clip(CircleShape).background(Ch.Danger.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Flag, null, tint = Ch.Danger, modifier = Modifier.size(22.dp))
                }
                Spacer(Modifier.width(12.dp))
                Column {
                    Text(stringResource(R.string.ch_report_title, conversation.title.orEmpty()), color = Ch.Ink, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
                    Text(stringResource(R.string.ch_report_sub), color = Ch.Mut, fontSize = 12.5.sp)
                }
            }
            Spacer(Modifier.height(14.dp))

            val list = types
            if (list == null) {
                Box(Modifier.fillMaxWidth().height(120.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
            } else {
                list.forEachIndexed { i, type ->
                    val on = picked == type.id
                    val bg by animateColorAsState(if (on) Ch.Red.copy(alpha = 0.1f) else Ch.SurfaceMuted, tween(200), label = "reason")
                    Row(
                        Modifier.fillMaxWidth().padding(vertical = 4.dp).chStagger(i).clip(RoundedCornerShape(16.dp)).background(bg)
                            .clickable { picked = type.id }.padding(horizontal = 14.dp, vertical = 13.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(type.name.orEmpty(), color = Ch.Ink, fontWeight = if (on) FontWeight.ExtraBold else FontWeight.SemiBold, fontSize = 14.5.sp, modifier = Modifier.weight(1f))
                        AnimatedContent(on, label = "reasonTick", transitionSpec = { scaleIn(spring(dampingRatio = 0.4f)) togetherWith scaleOut() }) { v ->
                            Icon(if (v) Icons.Rounded.RadioButtonChecked else Icons.Rounded.RadioButtonUnchecked, null, tint = if (v) Ch.Red else Ch.Soft, modifier = Modifier.size(22.dp))
                        }
                    }
                }
            }

            AnimatedVisibility(picked != null, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                Column {
                    Spacer(Modifier.height(8.dp))
                    ChField(details, { details = it.take(1000) }, stringResource(R.string.ch_report_details_hint), icon = Icons.Rounded.EditNote, singleLine = false, minLines = 3)
                    if (canBlock || canLeave) {
                        Row(
                            Modifier.fillMaxWidth().padding(top = 10.dp).clip(RoundedCornerShape(14.dp)).clickable { alsoBlock = !alsoBlock }.padding(vertical = 8.dp, horizontal = 4.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(if (alsoBlock) Icons.Rounded.CheckBox else Icons.Rounded.CheckBoxOutlineBlank, null, tint = if (alsoBlock) Ch.Red else Ch.Soft, modifier = Modifier.size(22.dp))
                            Spacer(Modifier.width(10.dp))
                            Text(stringResource(if (canBlock) R.string.ch_report_also_block else R.string.ch_report_also_leave), color = Ch.Ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold)
                        }
                    }
                    Text(stringResource(R.string.ch_report_evidence_note), color = Ch.Mut, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
                    Spacer(Modifier.height(14.dp))
                    ChPrimaryButton(stringResource(R.string.ch_report), icon = Icons.Rounded.Flag, modifier = Modifier.fillMaxWidth(), enabled = !sending) {
                        val reason = picked ?: return@ChPrimaryButton
                        sending = true
                        host.scope.launch {
                            try {
                                val andAlso = alsoBlock && (canBlock || canLeave)
                                ApiClient.chat.report(chatAuth(), conversation.id, mapOf(
                                    "report_type_id" to reason,
                                    "details" to details.trim().ifEmpty { null },
                                    "block" to (andAlso && canBlock),
                                    "leave" to (andAlso && canLeave),
                                ))
                                host.showToast(sent)
                                onReported(andAlso)
                                onDismiss()
                            } catch (e: Exception) {
                                e.apiFailure().message?.let { host.showToast(it) }
                            }
                            sending = false
                        }
                    }
                }
            }
        }
    }
}
