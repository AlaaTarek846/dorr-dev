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
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.HideImage
import androidx.compose.material.icons.rounded.FormatColorReset
import androidx.compose.material.icons.rounded.Brightness6
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
                // My own picture: darkened as much as I chose; an admin theme: a light standard veil.
                val veil = if (t.isCustom) t.dim / 100f else if (t.isDark || Ch.dark) 0.28f else 0.06f
                Box(Modifier.fillMaxSize().background(Color.Black.copy(alpha = veil.coerceIn(0f, 0.8f))))
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

/** Bubble colours to pick from: strong ones for mine, soft ones for theirs (null = the theme's). */
private val MyBubbleColors = listOf("#E50914", "#F97316", "#F59E0B", "#10B981", "#0D9488", "#0EA5E9", "#2563EB", "#6366F1", "#7C3AED", "#DB2777", "#111827")
private val TheirBubbleColors = listOf("#FFFFFF", "#F3F4F6", "#FEF3C7", "#DCFCE7", "#E0F2FE", "#EDE9FE", "#FCE7F3", "#FFE4E6", "#1F2937", "#334155")

/**
 * Pick a look for this chat: a live preview on top (wallpaper + two bubbles) that changes as you
 * go, the admin's themes, and "make it yours" — a photo from the gallery (dimmed as you like) and
 * your own bubble colours. Only you see it. "Dorr" = the app's own look.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ThemePickerSheet(conversation: ConversationDto, onDismiss: () -> Unit, onApplied: (ConversationDto) -> Unit) {
    val host = LocalChat.current
    val context = LocalContext.current
    var themes by remember { mutableStateOf<List<ChatThemeDto>?>(null) }
    var picked by remember { mutableStateOf(conversation.theme?.themeId) }
    // My own look as it stands on the server (the photo is uploaded right away) …
    var custom by remember { mutableStateOf(conversation.theme?.custom) }
    // … and the colour / dim changes not applied yet.
    var mine by remember { mutableStateOf(custom?.senderColor) }
    var theirs by remember { mutableStateOf(custom?.receiverColor) }
    var dim by remember { mutableStateOf((custom?.dim ?: 25).toFloat()) }
    var saving by remember { mutableStateOf(false) }
    var uploading by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { themes = runCatching { ApiClient.chat.themes(chatAuth()).data }.getOrNull().orEmpty() }

    fun applyServer(updated: ConversationDto) {
        custom = updated.theme?.custom
        onApplied(updated)
    }

    val gallery = androidx.activity.compose.rememberLauncherForActivityResult(androidx.activity.result.contract.ActivityResultContracts.PickVisualMedia()) { uri ->
        uri ?: return@rememberLauncherForActivityResult
        uploading = true
        host.scope.launch {
            try {
                val file = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
                    copyToCache(context, uri, "wallpaper.jpg")?.let { compressImage(context, it) }
                }
                if (file != null) {
                    val part = okhttp3.MultipartBody.Part.createFormData("image", file.name, file.file.asRequestBody(file.mime.toMediaTypeOrNull()))
                    ApiClient.chat.uploadWallpaper(chatAuth(), conversation.id, part).data?.let(::applyServer)
                    dim = (custom?.dim ?: 25).toFloat()
                }
            } catch (e: Exception) {
                host.showToast(e.apiFailure().message ?: context.getString(R.string.ch_error_network))
            }
            uploading = false
        }
    }

    val list = themes
    // What "no pick" shows: the admin's default when there is one, else Dorr's look.
    val fallback = list?.firstOrNull { it.isDefault }
    val base = picked?.let { id -> list?.firstOrNull { it.id == id } } ?: fallback
    val hasCustom = custom?.wallpaper != null || mine != null || theirs != null || custom?.backgroundColor != null
    // The preview: my look over the theme, exactly as the server will draw it.
    val previewTheme = if (!hasCustom) base else ChatThemeDto(
        id = 0, name = null,
        wallpaper = custom?.wallpaper ?: base?.wallpaper,
        backgroundColor = custom?.backgroundColor ?: base?.backgroundColor,
        senderColor = mine ?: base?.senderColor, receiverColor = theirs ?: base?.receiverColor,
        isDark = base?.isDark ?: false, isDefault = false, isCustom = true,
        dim = if (custom?.wallpaper != null) dim.toInt() else 0,
    )
    val savedCustom = conversation.theme?.custom
    val changed = picked != conversation.theme?.themeId || mine != savedCustom?.senderColor || theirs != savedCustom?.receiverColor ||
        (custom?.wallpaper != null && dim.toInt() != (savedCustom?.dim ?: 25))

    fun save(body: Map<String, Any?>, close: Boolean = true) {
        saving = true
        host.scope.launch {
            try {
                ApiClient.chat.updateSettingsJson(chatAuth(), conversation.id, com.dorr.app.network.jsonKeepingNulls(body)).data?.let(::applyServer)
                if (close) onDismiss()
            } catch (e: Exception) {
                e.apiFailure().message?.let { host.showToast(it) }
            }
            saving = false
        }
    }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(bottom = 26.dp)) {
            Text(stringResource(R.string.ch_chat_theme), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(horizontal = 20.dp))
            Text(stringResource(R.string.ch_chat_theme_sub), color = Ch.Mut, fontSize = 13.sp, modifier = Modifier.padding(horizontal = 20.dp, vertical = 2.dp))
            Spacer(Modifier.height(14.dp))

            Box(Modifier.padding(horizontal = 20.dp)) {
                ThemePreview(previewTheme, Modifier.fillMaxWidth().height(210.dp))
                androidx.compose.animation.AnimatedVisibility(uploading, enter = fadeIn(), exit = fadeOut(), modifier = Modifier.matchParentSize()) {
                    Box(Modifier.fillMaxSize().clip(RoundedCornerShape(24.dp)).background(Color.Black.copy(alpha = 0.35f)), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(Modifier.size(30.dp), color = Color.White, strokeWidth = 3.dp)
                    }
                }
            }
            Spacer(Modifier.height(16.dp))

            // ------------------------------------------------------------ the admin's themes
            if (list == null) {
                Box(Modifier.fillMaxWidth().height(96.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
            } else {
                LazyRow(contentPadding = PaddingValues(horizontal = 20.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    item(key = "dorr") {
                        Swatch(null, stringResource(R.string.ch_theme_dorr), selected = !hasCustom && picked == null && fallback == null, index = 0) { picked = null; mine = null; theirs = null }
                    }
                    itemsIndexed(list, key = { _, t -> t.id }) { i, t ->
                        Swatch(t, t.name.orEmpty(), selected = !hasCustom && (picked ?: fallback?.id) == t.id, index = i + 1) { picked = t.id; mine = null; theirs = null }
                    }
                }
            }

            // ------------------------------------------------------------ make it yours
            Spacer(Modifier.height(20.dp))
            Text(stringResource(R.string.ch_theme_yours), color = Ch.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(horizontal = 20.dp))
            Text(stringResource(R.string.ch_theme_yours_sub), color = Ch.Mut, fontSize = 12.sp, modifier = Modifier.padding(horizontal = 20.dp))
            Spacer(Modifier.height(10.dp))
            Row(Modifier.padding(horizontal = 20.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                ThemeAction(
                    Icons.Rounded.Image, stringResource(if (custom?.wallpaper != null) R.string.ch_theme_change_photo else R.string.ch_theme_photo),
                    Modifier.weight(1f), enabled = !uploading,
                ) {
                    gallery.launch(androidx.activity.result.PickVisualMediaRequest(androidx.activity.result.contract.ActivityResultContracts.PickVisualMedia.ImageOnly))
                }
                if (custom?.wallpaper != null) {
                    ThemeAction(Icons.Rounded.HideImage, stringResource(R.string.ch_theme_remove_photo), Modifier.weight(1f), enabled = !saving) {
                        save(mapOf("custom_theme" to mapOf("wallpaper" to null)), close = false)
                    }
                }
            }
            // How dark the photo is under the bubbles.
            AnimatedVisibility(custom?.wallpaper != null, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                Row(Modifier.padding(horizontal = 20.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.Brightness6, null, tint = Ch.Mut, modifier = Modifier.size(20.dp))
                    androidx.compose.material3.Slider(
                        value = dim, onValueChange = { dim = it }, valueRange = 0f..80f, modifier = Modifier.weight(1f).padding(horizontal = 8.dp),
                        colors = androidx.compose.material3.SliderDefaults.colors(thumbColor = Ch.Red, activeTrackColor = Ch.Red, inactiveTrackColor = Ch.Red.copy(alpha = 0.2f)),
                    )
                    Text("${dim.toInt()}%", color = Ch.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.width(38.dp))
                }
            }
            ColorRow(stringResource(R.string.ch_theme_my_bubbles), MyBubbleColors, mine) { mine = it }
            ColorRow(stringResource(R.string.ch_theme_their_bubbles), TheirBubbleColors, theirs) { theirs = it }

            Spacer(Modifier.height(18.dp))
            ChPrimaryButton(
                stringResource(R.string.ch_apply), icon = Icons.Rounded.Check,
                modifier = Modifier.padding(horizontal = 20.dp).fillMaxWidth(),
                enabled = !saving && !uploading && list != null && changed,
            ) {
                // My colours (and the dim over my photo) — or none: just the picked theme.
                val keepCustom = custom?.wallpaper != null || mine != null || theirs != null
                save(
                    mapOf(
                        "theme_id" to picked,
                        "custom_theme" to if (!keepCustom) null else mapOf("sender_color" to mine, "receiver_color" to theirs, "dim" to dim.toInt()),
                    ),
                )
            }
            // Everything back to Dorr's look (my photo is deleted too).
            if (conversation.theme?.themeId != null || savedCustom != null || custom != null) {
                Text(
                    stringResource(R.string.ch_theme_reset), color = Ch.Red, fontWeight = FontWeight.Bold, fontSize = 13.5.sp,
                    modifier = Modifier.align(Alignment.CenterHorizontally).padding(top = 12.dp).clip(RoundedCornerShape(10.dp))
                        .clickable(enabled = !saving) { save(mapOf("theme_id" to null, "custom_theme" to null)) }.padding(horizontal = 12.dp, vertical = 6.dp),
                )
            }
        }
    }
}

@Composable
private fun ThemeAction(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, modifier: Modifier, enabled: Boolean = true, onClick: () -> Unit) {
    Row(
        modifier.clip(RoundedCornerShape(16.dp)).background(Ch.Red.copy(alpha = if (enabled) 0.1f else 0.05f))
            .clickable(enabled = enabled, onClick = onClick).padding(horizontal = 12.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center,
    ) {
        Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(8.dp))
        Text(label, color = Ch.Red, fontWeight = FontWeight.Bold, fontSize = 13.5.sp, maxLines = 1)
    }
}

/** A row of colour dots; the first one means: use the theme's colour. */
@Composable
private fun ColorRow(title: String, colors: List<String>, selected: String?, onPick: (String?) -> Unit) {
    Text(title, color = Ch.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(start = 20.dp, end = 20.dp, top = 12.dp, bottom = 6.dp))
    LazyRow(contentPadding = PaddingValues(horizontal = 20.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        item(key = "theme") { ColorDot(null, selected == null) { onPick(null) } }
        itemsIndexed(colors, key = { _, c -> c }) { i, hex ->
            Box(Modifier.chStagger(i)) { ColorDot(hex, selected.equals(hex, ignoreCase = true)) { onPick(hex) } }
        }
    }
}

@Composable
private fun ColorDot(hex: String?, selected: Boolean, onClick: () -> Unit) {
    val ring by animateDpAsState(if (selected) 3.dp else 0.dp, spring(dampingRatio = 0.5f), label = "dotRing")
    val color = hexColor(hex)
    Box(
        Modifier.size(42.dp).border(ring, Ch.Red, CircleShape).padding(if (selected) 5.dp else 2.dp).clip(CircleShape)
            .background(color ?: Ch.SurfaceMuted).border(1.dp, Ch.Line, CircleShape).clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        when {
            color == null -> Icon(Icons.Rounded.FormatColorReset, null, tint = Ch.Mut, modifier = Modifier.size(18.dp))
            selected -> Icon(Icons.Rounded.Check, null, tint = if (color.isLight()) Color(0xFF111928) else Color.White, modifier = Modifier.size(18.dp))
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
