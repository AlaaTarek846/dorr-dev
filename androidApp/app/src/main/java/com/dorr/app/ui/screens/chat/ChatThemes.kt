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
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Check
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.ChangeCircle
import androidx.compose.material.icons.rounded.Delete
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
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.SubHeader
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
@Composable
fun ChatThemePage(id: String) {
    val host = LocalChat.current
    var conversation by remember { mutableStateOf(host.find(id)) }
    LaunchedEffect(id) {
        conversation = runCatching { ApiClient.chat.conversation(chatAuth(), id).data }.getOrNull() ?: conversation
    }
    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(R.string.ch_chat_theme)) { host.pop() }
            Box(Modifier.weight(1f).fillMaxWidth()) {
                val conv = conversation
                if (conv == null) {
                    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(Modifier.size(28.dp), color = Ch.Red, strokeWidth = 2.5.dp)
                    }
                } else {
                    ThemePickerBody(conv, onApplied = { conversation = it; host.upsert(it) }, onClose = { host.pop() })
                }
            }
        }
    }
}

@Composable
private fun ThemePickerBody(conversation: ConversationDto, onApplied: (ConversationDto) -> Unit, onClose: () -> Unit) {
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
        picked = updated.theme?.themeId
        mine = custom?.senderColor
        theirs = custom?.receiverColor
        dim = (custom?.dim ?: 25).toFloat()
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
    val swatchList = rememberLazyListState()
    val swatchScope = rememberCoroutineScope()
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
                if (close) onClose()
            } catch (e: Exception) {
                e.apiFailure().message?.let { host.showToast(it) }
            }
            saving = false
        }
    }

    Column(Modifier.fillMaxSize().imePadding().navigationBarsPadding()) {
        Column(Modifier.weight(1f).fillMaxWidth().verticalScroll(rememberScrollState()).padding(bottom = 8.dp)) {
            Text(
                stringResource(R.string.ch_chat_theme_sub),
                color = Ch.Mut,
                fontSize = 12.5.sp,
                lineHeight = 18.sp,
                modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp),
            )

            Box(Modifier.padding(horizontal = 16.dp)) {
                ThemePreview(previewTheme, Modifier.fillMaxWidth().height(168.dp))
                androidx.compose.animation.AnimatedVisibility(uploading, enter = fadeIn(), exit = fadeOut(), modifier = Modifier.matchParentSize()) {
                    Box(Modifier.fillMaxSize().clip(RoundedCornerShape(18.dp)).background(Color.Black.copy(alpha = 0.35f)), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(Modifier.size(24.dp), color = Color.White, strokeWidth = 2.5.dp)
                    }
                }
            }
            Spacer(Modifier.height(4.dp))
            ThemeSectionTitle(stringResource(R.string.ch_theme_wallpaper)) {
                Box(
                    Modifier.size(28.dp).clip(CircleShape).clickable {
                        val last = (swatchList.layoutInfo.totalItemsCount - 1).coerceAtLeast(0)
                        val next = (swatchList.firstVisibleItemIndex + 3).coerceAtMost(last)
                        swatchScope.launch { swatchList.animateScrollToItem(next) }
                    },
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = Ch.Mut, modifier = Modifier.size(20.dp))
                }
            }

            LazyRow(state = swatchList, contentPadding = PaddingValues(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                item(key = "add-photo") {
                    AddPhotoSwatch(
                        wallpaper = custom?.wallpaper,
                        selected = custom?.wallpaper != null,
                        uploading = uploading,
                        index = 0,
                    ) {
                        gallery.launch(androidx.activity.result.PickVisualMediaRequest(androidx.activity.result.contract.ActivityResultContracts.PickVisualMedia.ImageOnly))
                    }
                }
                if (list == null) {
                    item(key = "themes-loading") {
                        Box(Modifier.size(width = 62.dp, height = 82.dp), contentAlignment = Alignment.Center) {
                            CircularProgressIndicator(Modifier.size(22.dp), color = Ch.Red, strokeWidth = 2.5.dp)
                        }
                    }
                } else {
                    item(key = "dorr") {
                        Swatch(null, stringResource(R.string.ch_theme_dorr), selected = !hasCustom && picked == null && fallback == null, index = 1) { picked = null; mine = null; theirs = null }
                    }
                    itemsIndexed(list, key = { _, t -> t.id }) { i, t ->
                        Swatch(t, t.name.orEmpty(), selected = !hasCustom && (picked ?: fallback?.id) == t.id, index = i + 2) { picked = t.id; mine = null; theirs = null }
                    }
                }
            }

            AnimatedVisibility(custom?.wallpaper != null, enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
                Column(Modifier.padding(start = 16.dp, end = 16.dp, top = 16.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        Text(stringResource(R.string.ch_theme_dim), color = Ch.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f), maxLines = 1)
                        ThemeIconBtn(Icons.Rounded.ChangeCircle, stringResource(R.string.ch_theme_change_photo), enabled = !uploading) {
                            gallery.launch(androidx.activity.result.PickVisualMediaRequest(androidx.activity.result.contract.ActivityResultContracts.PickVisualMedia.ImageOnly))
                        }
                        ThemeIconBtn(Icons.Rounded.Delete, stringResource(R.string.ch_theme_remove_photo), enabled = !saving) {
                            save(mapOf("custom_theme" to mapOf("wallpaper" to null)), close = false)
                        }
                    }
                    Row(Modifier.padding(top = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.Brightness6, null, tint = Ch.Mut, modifier = Modifier.size(20.dp))
                        androidx.compose.material3.Slider(
                            value = dim, onValueChange = { dim = it }, valueRange = 0f..80f, modifier = Modifier.weight(1f).padding(horizontal = 6.dp),
                            colors = androidx.compose.material3.SliderDefaults.colors(thumbColor = Ch.Red, activeTrackColor = Ch.Red, inactiveTrackColor = Ch.Red.copy(alpha = 0.2f)),
                        )
                        Text("${dim.toInt()}%", color = Ch.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.width(34.dp))
                    }
                }
            }
            ThemeSectionTitle(stringResource(R.string.ch_theme_message_colors))
            ColorRow(stringResource(R.string.ch_theme_my_bubbles), MyBubbleColors, mine) { mine = it }
            ColorRow(stringResource(R.string.ch_theme_their_bubbles), TheirBubbleColors, theirs) { theirs = it }
            Spacer(Modifier.height(12.dp))
        }

        val canReset = conversation.theme?.themeId != null || savedCustom != null || custom != null
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 12.dp),
            horizontalArrangement = Arrangement.spacedBy(10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            ChPrimaryButton(
                stringResource(R.string.ch_apply), icon = Icons.Rounded.Check,
                modifier = Modifier.weight(1f),
                enabled = !saving && !uploading && list != null && changed,
            ) {
                val keepCustom = custom?.wallpaper != null || mine != null || theirs != null
                save(
                    mapOf(
                        "theme_id" to picked,
                        "custom_theme" to if (!keepCustom) null else mapOf("sender_color" to mine, "receiver_color" to theirs, "dim" to dim.toInt()),
                    ),
                    close = false,
                )
            }
            Row(
                Modifier.weight(1f).heightIn(min = 48.dp)
                    .graphicsLayer { alpha = if (!saving && canReset) 1f else 0.45f }
                    .clip(RoundedCornerShape(18.dp))
                    .border(1.5.dp, Ch.Red, RoundedCornerShape(18.dp))
                    .background(Ch.Red.copy(alpha = 0.08f))
                    .clickable(enabled = !saving && canReset) { save(mapOf("theme_id" to null, "custom_theme" to null), close = false) }
                    .padding(horizontal = 10.dp, vertical = 12.dp),
                horizontalArrangement = Arrangement.spacedBy(6.dp, Alignment.CenterHorizontally),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.FormatColorReset, null, tint = Ch.Red, modifier = Modifier.size(18.dp))
                Text(
                    stringResource(R.string.ch_theme_reset),
                    color = Ch.Red,
                    fontWeight = FontWeight.ExtraBold,
                    fontSize = 12.5.sp,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
        }
    }
}

@Composable
private fun ThemeSectionTitle(title: String, trailing: (@Composable RowScope.() -> Unit)? = null) {
    Row(
        Modifier.fillMaxWidth().padding(start = 16.dp, end = 16.dp, top = 16.dp, bottom = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            title,
            color = Ch.Ink,
            fontFamily = CairoFontFamily,
            fontWeight = FontWeight.ExtraBold,
            fontSize = 15.sp,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier.weight(1f),
        )
        trailing?.invoke(this)
    }
}

@Composable
private fun ThemeIconBtn(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, enabled: Boolean = true, onClick: () -> Unit) {
    Row(
        Modifier.clip(RoundedCornerShape(12.dp))
            .background(Ch.Red.copy(alpha = if (enabled) 0.12f else 0.05f))
            .clickable(enabled = enabled, onClick = onClick)
            .padding(horizontal = 8.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(16.dp))
        Text(label, color = Ch.Red, fontWeight = FontWeight.Bold, fontSize = 11.sp, maxLines = 1)
    }
}

/** A row of colour dots; the first one means: use the theme's colour. */
@Composable
private fun ColorRow(title: String, colors: List<String>, selected: String?, onPick: (String?) -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(start = 16.dp, top = 6.dp, bottom = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            title,
            color = Ch.Ink,
            fontSize = 13.sp,
            fontWeight = FontWeight.ExtraBold,
            maxLines = 1,
            modifier = Modifier.width(72.dp).padding(end = 8.dp),
        )
        LazyRow(
            modifier = Modifier.weight(1f),
            contentPadding = PaddingValues(end = 16.dp),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            item(key = "theme") { ColorDot(null, selected == null) { onPick(null) } }
            itemsIndexed(colors, key = { _, c -> c }) { i, hex ->
                Box(Modifier.chStagger(i)) { ColorDot(hex, selected.equals(hex, ignoreCase = true)) { onPick(hex) } }
            }
        }
    }
}

@Composable
private fun ColorDot(hex: String?, selected: Boolean, onClick: () -> Unit) {
    val ring by animateDpAsState(if (selected) 3.dp else 0.dp, spring(dampingRatio = 0.5f), label = "dotRing")
    val color = hexColor(hex)
    Box(
        Modifier.size(36.dp).border(ring, Ch.Red, CircleShape).padding(if (selected) 4.dp else 2.dp).clip(CircleShape)
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
    Box(
        modifier.shadow(6.dp, RoundedCornerShape(18.dp))
            .clip(RoundedCornerShape(18.dp))
            .border(1.dp, Ch.Ink.copy(alpha = 0.12f), RoundedCornerShape(18.dp)),
    ) {
        ThemedWallpaper(theme)
        Column(Modifier.fillMaxSize().padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp, Alignment.Bottom)) {
            ChChip(stringResource(R.string.ch_today), Modifier.align(Alignment.CenterHorizontally))
            PreviewBubble(stringResource(R.string.ch_theme_sample_in), inColor, mine = false, Modifier.align(Alignment.Start))
            PreviewBubble(stringResource(R.string.ch_theme_sample_out), outColor, mine = true, Modifier.align(Alignment.End))
        }
    }
}

@Composable
private fun PreviewBubble(text: String, color: Color, mine: Boolean, modifier: Modifier) {
    val shape = if (mine) RoundedCornerShape(14.dp, 14.dp, 4.dp, 14.dp) else RoundedCornerShape(14.dp, 14.dp, 14.dp, 4.dp)
    Text(
        text, color = if (color.isLight()) Color(0xFF111928) else Color.White, fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
        modifier = modifier.shadow(2.dp, shape).clip(shape).background(color).padding(horizontal = 11.dp, vertical = 7.dp),
    )
}

@Composable
private fun AddPhotoSwatch(wallpaper: String?, selected: Boolean, uploading: Boolean, index: Int, onClick: () -> Unit) {
    val ring by animateDpAsState(if (selected) 3.dp else 0.dp, spring(dampingRatio = 0.5f), label = "addRing")
    val context = LocalContext.current
    val dash = Ch.Red
    Column(Modifier.width(68.dp).chStagger(index), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(
            Modifier.size(width = 62.dp, height = 82.dp)
                .border(ring, Ch.Red, RoundedCornerShape(14.dp))
                .padding(if (selected) 4.dp else 0.dp)
                .then(
                    if (wallpaper == null) Modifier.drawBehind {
                        drawRoundRect(
                            color = dash,
                            cornerRadius = CornerRadius(12.dp.toPx()),
                            style = Stroke(width = 1.6.dp.toPx(), pathEffect = PathEffect.dashPathEffect(floatArrayOf(10.dp.toPx(), 6.dp.toPx()))),
                        )
                    } else Modifier.clip(RoundedCornerShape(12.dp)),
                )
                .clickable(enabled = !uploading, onClick = onClick),
            contentAlignment = Alignment.Center,
        ) {
            when {
                wallpaper != null -> {
                    AsyncImage(ApiClient.mediaUrl(wallpaper), null, imageLoader = chatImages(context), contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                    androidx.compose.animation.AnimatedVisibility(selected, enter = scaleIn(spring(dampingRatio = 0.45f)), exit = scaleOut(), modifier = Modifier.align(Alignment.TopEnd).padding(4.dp)) {
                        Box(Modifier.size(16.dp).clip(CircleShape).background(Ch.Red), contentAlignment = Alignment.Center) {
                            Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(11.dp))
                        }
                    }
                }
                uploading -> CircularProgressIndicator(Modifier.size(18.dp), color = Ch.Red, strokeWidth = 2.dp)
                else -> Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Box(Modifier.size(22.dp).clip(CircleShape).background(Ch.Red), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Add, null, tint = Color.White, modifier = Modifier.size(16.dp))
                    }
                    Text(
                        stringResource(R.string.ch_theme_add_photo),
                        color = Ch.Red,
                        fontSize = 10.sp,
                        fontWeight = FontWeight.ExtraBold,
                        textAlign = TextAlign.Center,
                        lineHeight = 12.sp,
                        maxLines = 2,
                        modifier = Modifier.padding(horizontal = 4.dp),
                    )
                }
            }
        }
    }
}

@Composable
private fun Swatch(theme: ChatThemeDto?, label: String, selected: Boolean, index: Int, onClick: () -> Unit) {
    val ring by animateDpAsState(if (selected) 3.dp else 0.dp, spring(dampingRatio = 0.5f), label = "ring")
    val context = LocalContext.current
    Column(Modifier.width(68.dp).chStagger(index), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(
            Modifier.size(width = 62.dp, height = 82.dp)
                .border(ring, Ch.Red, RoundedCornerShape(14.dp))
                .padding(if (selected) 4.dp else 0.dp)
                .clip(RoundedCornerShape(12.dp))
                .clickable(onClick = onClick),
        ) {
            when {
                theme == null -> ChWallpaper()
                theme.wallpaper != null -> AsyncImage(ApiClient.mediaUrl(theme.wallpaper), null, imageLoader = chatImages(context), contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                else -> Box(Modifier.fillMaxSize().background(hexColor(theme.backgroundColor) ?: Ch.SurfaceMuted))
            }
            Column(Modifier.fillMaxSize().padding(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp, Alignment.Bottom)) {
                Box(Modifier.size(24.dp, 7.dp).clip(RoundedCornerShape(4.dp)).background(hexColor(theme?.receiverColor) ?: Ch.Surface))
                Box(Modifier.align(Alignment.End).size(24.dp, 7.dp).clip(RoundedCornerShape(4.dp)).background(hexColor(theme?.senderColor) ?: Ch.Red))
            }
            androidx.compose.animation.AnimatedVisibility(selected, enter = scaleIn(spring(dampingRatio = 0.45f)), exit = scaleOut(), modifier = Modifier.align(Alignment.TopEnd).padding(4.dp)) {
                Box(Modifier.size(16.dp).clip(CircleShape).background(Ch.Red), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(11.dp))
                }
            }
        }
        Text(label, color = if (selected) Ch.Ink else Ch.Mut, fontSize = 11.5.sp, fontWeight = if (selected) FontWeight.ExtraBold else FontWeight.Medium, maxLines = 1, modifier = Modifier.padding(top = 6.dp))
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

    ChSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
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
