package com.dorr.app.ui.screens.chat

import android.media.MediaMetadataRetriever
import android.net.Uri
import android.widget.VideoView
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.Crossfade
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.FormatAlignLeft
import androidx.compose.material.icons.automirrored.rounded.FormatAlignRight
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.AlternateEmail
import androidx.compose.material.icons.rounded.Brush
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.CommentsDisabled
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.DeleteOutline
import androidx.compose.material.icons.rounded.EmojiEmotions
import androidx.compose.material.icons.rounded.FormatAlignCenter
import androidx.compose.material.icons.rounded.Palette
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.Reply
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.TextFields
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.StoryLayerDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.theme.CairoFontFamily
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody

/** Maximum video length the app lets through before the server says no (the admin can raise it). */
internal const val VIDEO_LIMIT_SECONDS = 60

/** `voice` opens the voice recorder (backlog 78); any other value is one or more picked photos / videos (one per line). */
const val STORY_VOICE = "voice"

/** How many photos / videos can be picked at once — each becomes its own status (as in WhatsApp). */
internal const val STORY_MEDIA_MAX = 10

/** The status kinds, switched at the bottom of the editor. */
internal enum class StoryMode { Text, Media, Voice }

/** Several picked photos / videos travel in one route, one per line. */
internal fun storyMediaRoute(uris: List<Uri>): String = uris.joinToString("\n")

/** What an editor tells the page: it has something to lose, the kind to switch to, close. */
@Stable
internal class StoryEditorHost(val onDirty: (Boolean) -> Unit, val onSwitch: (StoryMode) -> Unit, val onClose: () -> Unit)

/**
 * The status editor: text, photo / video (one or several) or voice — switched at the bottom without leaving.
 * Closing or switching away from something made asks first («Discard this status?»).
 */
@Composable
fun StoryComposerPage(mediaUri: String?) {
    val host = LocalChat.current
    var mode by remember {
        mutableStateOf(when (mediaUri) { null -> StoryMode.Text; STORY_VOICE -> StoryMode.Voice; else -> StoryMode.Media })
    }
    var uris by remember { mutableStateOf(mediaUri?.takeIf { it != STORY_VOICE }?.split('\n')?.filter { it.isNotBlank() }?.map(Uri::parse).orEmpty()) }
    var dirty by remember { mutableStateOf(false) }
    var confirm by remember { mutableStateOf<(() -> Unit)?>(null) }
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.PickMultipleVisualMedia(STORY_MEDIA_MAX)) { picked ->
        if (picked.isNotEmpty()) {
            uris = picked
            dirty = false
            mode = StoryMode.Media
        }
    }
    fun guarded(action: () -> Unit) {
        if (dirty) confirm = action else action()
    }
    val editor = StoryEditorHost(
        onDirty = { dirty = it },
        onSwitch = { target ->
            when {
                target == StoryMode.Media -> guarded { picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageAndVideo)) }
                target != mode -> guarded { dirty = false; mode = target }
            }
        },
        onClose = { guarded { host.pop() } },
    )
    BackHandler { editor.onClose() }

    // A fresh editor for each kind (and each new pick).
    key(mode, uris) {
        when (mode) {
            StoryMode.Text -> TextStoryEditor(editor)
            StoryMode.Voice -> VoiceStoryEditor(editor)
            StoryMode.Media -> if (uris.isNotEmpty()) MediaStoryEditor(uris, editor) else LaunchedEffect(Unit) { mode = StoryMode.Text }
        }
    }

    confirm?.let { action ->
        com.dorr.app.ui.screens.ConfirmDialog(
            icon = Icons.Rounded.DeleteOutline,
            title = stringResource(R.string.st_discard_title),
            message = stringResource(R.string.st_discard_text),
            onConfirm = { confirm = null; dirty = false; action() },
            onDismiss = { confirm = null },
            confirmLabel = stringResource(R.string.st_discard),
        )
    }
}

// ------------------------------------------------------------------------------- text

private enum class TextPanel { None, Colors, Fonts }

@Composable
private fun TextStoryEditor(editor: StoryEditorHost) {
    val host = LocalChat.current
    var text by remember { mutableStateOf("") }
    var bg by remember { mutableIntStateOf(0) }
    var font by remember { mutableIntStateOf(0) }
    var align by remember { mutableStateOf("center") }
    var panel by remember { mutableStateOf(TextPanel.None) }
    var allowReplies by remember { mutableStateOf(true) }
    var mentions by remember { mutableStateOf<List<Int>>(emptyList()) }
    val focus = remember { FocusRequester() }
    val ed = rememberLayerEditor()
    val focusManager = LocalFocusManager.current
    val backgrounds = StoryLook.backgrounds.keys.toList()
    val fontName = StoryLook.fonts[font]
    var fontBounce by remember { mutableStateOf(false) }
    val fontScale by animateFloatAsState(if (fontBounce) 1.25f else 1f, spring(dampingRatio = 0.35f, stiffness = 500f), finishedListener = { fontBounce = false }, label = "font")
    val size by animateFloatAsState(StoryLook.textSize(text.length), spring(stiffness = 300f), label = "size")
    val made = text.isNotBlank() || ed.layers.isNotEmpty()

    LaunchedEffect(made) { editor.onDirty(made) }
    LaunchedEffect(Unit) { focus.requestFocus() }
    LaunchedEffect(ed.drawing) { if (ed.drawing) focusManager.clearFocus() }
    BackHandler(panel != TextPanel.None) { panel = TextPanel.None }
    BackHandler(ed.selected != null) { ed.selected = null }

    Box(Modifier.fillMaxSize()) {
        // The background crossfades to the one picked.
        Crossfade(targetState = backgrounds[bg], animationSpec = tween(450), label = "bg") { name ->
            Box(Modifier.fillMaxSize().background(StoryLook.brush(name)))
        }

        Box(Modifier.fillMaxSize().imePadding().padding(horizontal = 28.dp).padding(top = 80.dp, bottom = 250.dp), contentAlignment = Alignment.Center) {
            val textAlign = storyTextAlign(align)
            if (text.isEmpty()) {
                Text(
                    stringResource(R.string.st_type_status), color = Color.White.copy(alpha = 0.6f), fontSize = size.sp,
                    fontFamily = StoryLook.font(fontName), fontWeight = StoryLook.weight(fontName), textAlign = textAlign, modifier = Modifier.fillMaxWidth(),
                )
            }
            BasicTextField(
                value = text,
                onValueChange = { text = it.take(700) },
                textStyle = TextStyle(color = Color.White, fontSize = size.sp, lineHeight = (size * 1.25f).sp, fontFamily = StoryLook.font(fontName), fontWeight = StoryLook.weight(fontName), textAlign = textAlign),
                cursorBrush = SolidColor(Color.White),
                modifier = Modifier.fillMaxWidth().focusRequester(focus),
            )
        }

        // Stickers and drawing on top (backlog 79–80).
        StoryLayersEditable(ed, deselectOnTap = false)

        if (ed.drawing) DrawingBar(ed)
        // On top only what every kind shares (stickers · pen · mention) — the text's own tools sit at the bottom,
        // right under the colours / fonts they open, so the top bar never runs off a small screen.
        else StoryTopBar(editor.onClose) {
            LayerToolButtons(ed, withText = false)
            MentionTool(mentions) { mentions = it }
        }

        if (!ed.drawing) Column(
            Modifier.align(Alignment.BottomCenter).fillMaxWidth().imePadding().navigationBarsPadding().padding(horizontal = 14.dp, vertical = 12.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            AnimatedVisibility(panel == TextPanel.Colors) { BackgroundSwatches(bg, Modifier.padding(bottom = 12.dp)) { bg = it } }
            AnimatedVisibility(panel == TextPanel.Fonts) { FontChips(font, Modifier.padding(bottom = 12.dp)) { font = it; fontBounce = true } }
            // Near the limit: how much is left.
            if (text.length > 600) Text("${text.length}/700", color = Color.White.copy(alpha = 0.8f), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 8.dp))
            // The text's tools: colours · font · alignment — each right under what it opens.
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
                StoryTool(Icons.Rounded.Palette, stringResource(R.string.st_tool_colors), active = panel == TextPanel.Colors) {
                    panel = if (panel == TextPanel.Colors) TextPanel.None else TextPanel.Colors
                }
                StoryTool(null, stringResource(R.string.st_tool_font), Modifier.scale(fontScale), label = "Aa", active = panel == TextPanel.Fonts) {
                    panel = if (panel == TextPanel.Fonts) TextPanel.None else TextPanel.Fonts
                }
                StoryTool(alignIcon(align), stringResource(R.string.st_tool_align)) {
                    align = when (align) { "center" -> "start"; "start" -> "end"; else -> "center" }
                }
            }
            Spacer(Modifier.height(12.dp))
            StoryModeSwitch(StoryMode.Text, editor.onSwitch)
            Spacer(Modifier.height(12.dp))
            val networkError = stringResource(R.string.ch_error_network)
            StoryBottomBar(allowReplies, onToggleReplies = { allowReplies = !allowReplies }, sendEnabled = text.isNotBlank()) {
                host.postStory(
                    mapOf(
                        "type" to "text", "body" to text.trim(), "style[background]" to backgrounds[bg], "style[font]" to fontName,
                        "style[align]" to align, "allow_replies" to if (allowReplies) "1" else "0",
                    ) + mentionFields(mentions) + ed.fields(),
                    null,
                    networkError,
                )
            }
        }
        LayerStickerPicker(ed)
    }
}

/** `style.align` → how a text status lines up (the editor and the viewer). */
internal fun storyTextAlign(align: String?): TextAlign = when (align) {
    "start" -> TextAlign.Start
    "end" -> TextAlign.End
    else -> TextAlign.Center
}

/** The alignment's icon — auto-mirrored, so «start» points right in Arabic and left in English. */
private fun alignIcon(align: String): ImageVector = when (align) {
    "start" -> Icons.AutoMirrored.Rounded.FormatAlignLeft
    "end" -> Icons.AutoMirrored.Rounded.FormatAlignRight
    else -> Icons.Rounded.FormatAlignCenter
}

// ------------------------------------------------------------------------------- photo / video

/** One picked photo / video and what was made on it (its caption, its stickers and drawing). */
@Stable
private class MediaItem(val uri: Uri, val isVideo: Boolean, val durationMs: Long?, startAspect: Float?) {
    val ed = StoryLayerEditor()
    var caption by mutableStateOf("")
    var aspect by mutableStateOf(startAspect)
    val tooLong: Boolean get() = isVideo && (durationMs ?: 0) > VIDEO_LIMIT_SECONDS * 1000 + 500
}

@Composable
private fun MediaStoryEditor(uris: List<Uri>, editor: StoryEditorHost) {
    val host = LocalChat.current
    val context = LocalContext.current
    val items = remember(uris) {
        uris.map { uri ->
            val video = context.contentResolver.getType(uri).orEmpty().startsWith("video/")
            MediaItem(uri, video, if (video) videoDuration(context, uri) else null, if (video) videoAspect(context, uri) else null)
        }
    }
    var index by remember { mutableIntStateOf(0) }
    val item = items[index.coerceIn(0, items.lastIndex)]
    val ed = item.ed
    var allowReplies by remember { mutableStateOf(true) }
    var mentions by remember { mutableStateOf<List<Int>>(emptyList()) }
    val tooLongText = stringResource(R.string.st_video_too_long, VIDEO_LIMIT_SECONDS)
    val networkError = stringResource(R.string.ch_error_network)

    // A picked photo is already something to lose.
    LaunchedEffect(Unit) { editor.onDirty(true) }
    BackHandler(ed.selected != null) { ed.selected = null }

    Box(Modifier.fillMaxSize().background(Color.Black), contentAlignment = Alignment.Center) {
        var paused by remember(index) { mutableStateOf(false) }
        key(index) {
            if (item.isVideo) {
                var view by remember { mutableStateOf<VideoView?>(null) }
                AndroidView(
                    factory = { ctx -> VideoView(ctx).apply { setVideoURI(item.uri); setOnPreparedListener { it.isLooping = true; start() } }.also { view = it } },
                    onRelease = { it.stopPlayback() },
                    modifier = item.aspect?.let { Modifier.aspectRatio(it) } ?: Modifier.fillMaxSize(),
                )
                LaunchedEffect(paused, view) { view?.let { if (paused) it.pause() else it.start() } }
            } else {
                AsyncImage(
                    item.uri, null, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize(),
                    onSuccess = { st -> st.painter.intrinsicSize.let { if (it.width > 0 && it.height > 0) item.aspect = it.width / it.height } },
                )
            }
            item.aspect?.let { a -> Box(Modifier.aspectRatio(a)) { StoryLayersEditable(ed) } }
        }
        // Soft shade top and bottom: the tools read on any picture.
        StoryScrims()

        if (ed.drawing) DrawingBar(ed, Modifier.align(Alignment.TopCenter))
        else Column(Modifier.align(Alignment.TopCenter).fillMaxWidth()) {
            StoryTopBar(editor.onClose) {
                if (item.aspect != null) LayerToolButtons(ed, withText = true)
                MentionTool(mentions) { mentions = it }
            }
            // A video: pause / play, and how long it is.
            if (item.isVideo) Row(
                Modifier.padding(horizontal = 14.dp).clip(RoundedCornerShape(16.dp)).background(Color.Black.copy(alpha = 0.45f))
                    .clickable { paused = !paused }.padding(horizontal = 10.dp, vertical = 6.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(if (paused) Icons.Rounded.PlayArrow else Icons.Rounded.Pause, null, tint = Color.White, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(5.dp))
                Text(durationText(item.durationMs ?: 0), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
            }
        }

        if (!ed.busy) Column(
            Modifier.align(Alignment.BottomCenter).fillMaxWidth().imePadding().navigationBarsPadding().padding(horizontal = 14.dp, vertical = 12.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            AnimatedVisibility(item.tooLong) {
                Text(tooLongText, color = Color.White, fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 10.dp).clip(RoundedCornerShape(12.dp)).background(Color(0xCCDC2626)).padding(horizontal = 12.dp, vertical = 6.dp))
            }
            // Several picked: each one, to switch between (each keeps its own caption and drawing).
            if (items.size > 1) MediaThumbs(items, index) { index = it }
            StoryModeSwitch(StoryMode.Media, editor.onSwitch)
            Spacer(Modifier.height(10.dp))
            Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                AudienceChip()
                RepliesChip(allowReplies) { allowReplies = !allowReplies }
            }
            Spacer(Modifier.height(10.dp))
            // The caption and send, in one line (as in WhatsApp).
            Row(verticalAlignment = Alignment.Bottom) {
                Box(
                    Modifier.weight(1f).heightIn(min = 52.dp).clip(RoundedCornerShape(26.dp)).background(Color.Black.copy(alpha = 0.5f))
                        .border(1.dp, Color.White.copy(alpha = 0.2f), RoundedCornerShape(26.dp)).padding(horizontal = 18.dp, vertical = 15.dp),
                ) {
                    if (item.caption.isEmpty()) Text(stringResource(R.string.st_add_caption), color = Color.White.copy(alpha = 0.7f), fontSize = 15.sp)
                    BasicTextField(
                        item.caption, { item.caption = it.take(700) }, maxLines = 4,
                        textStyle = TextStyle(color = Color.White, fontSize = 15.sp, fontFamily = CairoFontFamily, textDirection = TextDirection.Content),
                        cursorBrush = SolidColor(Color.White), modifier = Modifier.fillMaxWidth(),
                    )
                }
                Spacer(Modifier.width(10.dp))
                PostButton(enabled = items.none { it.tooLong }) {
                    // Each one its own status, in order.
                    val batch = items.mapIndexedNotNull { i, it ->
                        val file = copyToCache(context, it.uri, if (it.isVideo) "story_$i.mp4" else "story_$i.jpg") ?: return@mapIndexedNotNull null
                        val fields = buildMap {
                            put("type", if (it.isVideo) "video" else "image")
                            if (it.caption.isNotBlank()) put("body", it.caption.trim())
                            it.durationMs?.let { d -> put("duration_ms", d.toString()) }
                            put("allow_replies", if (allowReplies) "1" else "0")
                            putAll(mentionFields(mentions))
                            it.aspect?.let { a -> put("style[aspect]", String.format(java.util.Locale.US, "%.4f", a.coerceIn(0.2f, 5f))) }
                            putAll(it.ed.fields())
                        }
                        Triple(fields, file, if (it.isVideo) videoPoster(context, it.uri, "story_poster_$i.jpg") else null)
                    }
                    if (batch.isNotEmpty()) host.postStories(batch, networkError)
                }
            }
        }
        LayerTextWriter(ed)
        LayerStickerPicker(ed)
    }
}

/** The picked photos / videos in a row: the one being edited is outlined. */
@Composable
private fun MediaThumbs(items: List<MediaItem>, index: Int, onPick: (Int) -> Unit) {
    Row(Modifier.horizontalScroll(rememberScrollState()).padding(bottom = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        items.forEachIndexed { i, it ->
            val on = i == index
            Box(
                Modifier.size(if (on) 56.dp else 50.dp).clip(RoundedCornerShape(12.dp))
                    .border(if (on) 2.5.dp else 1.dp, Color.White.copy(alpha = if (on) 1f else 0.35f), RoundedCornerShape(12.dp))
                    .clickable { onPick(i) },
                contentAlignment = Alignment.Center,
            ) {
                if (it.isVideo) VideoThumb(it.uri) else AsyncImage(it.uri, null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                if (it.isVideo) Icon(Icons.Rounded.PlayArrow, null, tint = Color.White, modifier = Modifier.size(20.dp))
            }
        }
    }
}

/** A video's first frame (no video decoder for images in the app — the phone's own retriever). */
@Composable
private fun VideoThumb(uri: Uri) {
    val context = LocalContext.current
    val frame by produceState<ImageBitmap?>(null, uri) {
        value = withContext(Dispatchers.IO) {
            runCatching {
                MediaMetadataRetriever().run {
                    try { setDataSource(context, uri); getFrameAtTime(0)?.asImageBitmap() } finally { release() }
                }
            }.getOrNull()
        }
    }
    frame?.let { Image(it, null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize()) }
        ?: Box(Modifier.fillMaxSize().background(Color(0xFF1F2937)))
}

/** A video's poster frame, small, as a JPEG in the cache — the picture in quotes of the status. */
private fun videoPoster(context: android.content.Context, uri: Uri, name: String): LocalFile? = runCatching {
    val frame = MediaMetadataRetriever().run {
        try { setDataSource(context, uri); getFrameAtTime(500_000) ?: getFrameAtTime(0) } finally { release() }
    } ?: return null
    val scale = 480f / maxOf(frame.width, frame.height)
    val small = if (scale < 1f) android.graphics.Bitmap.createScaledBitmap(frame, (frame.width * scale).toInt(), (frame.height * scale).toInt(), true) else frame
    val file = java.io.File(context.cacheDir, name)
    file.outputStream().use { small.compress(android.graphics.Bitmap.CompressFormat.JPEG, 80, it) }
    LocalFile(file, "image/jpeg", file.name)
}.getOrNull()

private fun videoDuration(context: android.content.Context, uri: Uri): Long? = runCatching {
    MediaMetadataRetriever().run {
        try { setDataSource(context, uri); extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)?.toLong() } finally { release() }
    }
}.getOrNull()

/** A video's width ÷ height as it plays (turned phones' videos swap the two). */
private fun videoAspect(context: android.content.Context, uri: Uri): Float? = runCatching {
    MediaMetadataRetriever().run {
        try {
            setDataSource(context, uri)
            val w = extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_WIDTH)?.toFloat() ?: return@run null
            val h = extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_HEIGHT)?.toFloat() ?: return@run null
            val turned = extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_ROTATION)?.toIntOrNull()?.let { it % 180 != 0 } == true
            if (w <= 0 || h <= 0) null else if (turned) h / w else w / h
        } finally {
            release()
        }
    }
}.getOrNull()

// ------------------------------------------------------------------------------- shared: the editor's parts

/**
 * A round tool over the status: the same size, glass look and order on every kind. [active] (its panel is open)
 * turns it white with the app's red; [badge] counts (people mentioned).
 */
@Composable
internal fun StoryTool(
    icon: ImageVector?, description: String, modifier: Modifier = Modifier, label: String? = null,
    active: Boolean = false, badge: Int = 0, onClick: () -> Unit,
) {
    val bg by animateColorAsState(if (active) Color.White else Color.Black.copy(alpha = 0.28f), label = "toolBg")
    Box(modifier) {
        Box(
            Modifier.size(44.dp).clip(CircleShape).background(bg).border(1.dp, Color.White.copy(alpha = if (active) 1f else 0.3f), CircleShape)
                .clickable(onClick = onClick).semantics { contentDescription = description },
            contentAlignment = Alignment.Center,
        ) {
            val tint = if (active) Ch.Red else Color.White
            if (icon != null) Icon(icon, null, tint = tint, modifier = Modifier.size(21.dp))
            if (label != null) Text(label, color = tint, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
        }
        if (badge > 0) Box(
            Modifier.align(Alignment.TopEnd).size(18.dp).clip(CircleShape).background(Ch.Red).border(1.5.dp, Color.White, CircleShape),
            contentAlignment = Alignment.Center,
        ) { Text("$badge", color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.Bold) }
    }
}

/** The top of every kind: ✕ at the start, then the tools — always in the same order. */
@Composable
internal fun StoryTopBar(onClose: () -> Unit, modifier: Modifier = Modifier, tools: @Composable RowScope.() -> Unit) {
    Row(modifier.fillMaxWidth().padding(horizontal = 12.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        StoryTool(Icons.Rounded.Close, stringResource(R.string.ch_close_sheet), onClick = onClose)
        Spacer(Modifier.weight(1f))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically, content = tools)
    }
}

/** The layer tools: 🗑 (the one selected) · stickers · text · pen. */
@Composable
internal fun LayerToolButtons(ed: StoryLayerEditor, withText: Boolean) {
    ed.selected?.let { id -> StoryTool(Icons.Rounded.Delete, stringResource(R.string.ch_delete)) { ed.remove(id) } }
    StoryTool(Icons.Rounded.EmojiEmotions, stringResource(R.string.ch_expr_stickers)) { ed.selected = null; ed.picking = true }
    if (withText) StoryTool(Icons.Rounded.TextFields, stringResource(R.string.st_text)) {
        ed.selected = null
        ed.writing = EditLayer(0, StoryLayerDto("t", y = 0.4f, c = "#FFFFFF", f = "bold"))
    }
    StoryTool(Icons.Rounded.Brush, stringResource(R.string.st_tool_draw)) { ed.selected = null; ed.drawing = true }
}

/** @ — pick saved contacts to mention (they're told in our chat); the count shows on it. */
@Composable
internal fun MentionTool(mentions: List<Int>, onMentions: (List<Int>) -> Unit) {
    var picking by remember { mutableStateOf(false) }
    StoryTool(Icons.Rounded.AlternateEmail, stringResource(R.string.bk_mention), badge = mentions.size) { picking = true }
    if (picking) PeoplePickerSheet(mentions.toSet(), onDismiss = { picking = false }) { ids ->
        onMentions(ids.take(10))
        picking = false
    }
}

/** Text · Photo or video · Voice — switch the kind without leaving. */
@Composable
internal fun StoryModeSwitch(mode: StoryMode, onSwitch: (StoryMode) -> Unit, modifier: Modifier = Modifier) {
    val kinds = listOf(StoryMode.Text to R.string.st_text, StoryMode.Media to R.string.st_photo_video, StoryMode.Voice to R.string.bk_voice_status)
    Row(modifier.clip(RoundedCornerShape(22.dp)).background(Color.Black.copy(alpha = 0.35f)).padding(4.dp)) {
        kinds.forEach { (kind, label) ->
            val on = kind == mode
            val bg by animateColorAsState(if (on) Color.White else Color.Transparent, label = "modeBg")
            Text(
                stringResource(label), color = if (on) Ch.Red else Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp, maxLines = 1,
                modifier = Modifier.clip(RoundedCornerShape(18.dp)).background(bg).clickable { onSwitch(kind) }.padding(horizontal = 14.dp, vertical = 7.dp),
            )
        }
    }
}

/** Who sees it · replies on/off · send. The chips scroll if they must — send is never pushed off. */
@Composable
internal fun StoryBottomBar(allowReplies: Boolean, onToggleReplies: () -> Unit, sendEnabled: Boolean, onSend: () -> Unit) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Row(Modifier.weight(1f).horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            AudienceChip()
            RepliesChip(allowReplies, onToggleReplies)
        }
        Spacer(Modifier.width(10.dp))
        PostButton(sendEnabled, onSend)
    }
}

/** From the home page: «Public» (a tap says what it means). From the chat: «Privacy» — who sees my statuses. */
@Composable
internal fun AudienceChip() {
    val host = LocalChat.current
    if (host.publicMode) {
        val hint = stringResource(R.string.st_public_hint)
        StoryChip(Icons.Rounded.Public, stringResource(R.string.st_public_short)) { host.showToast(hint) }
    } else {
        StoryChip(Icons.Rounded.Shield, stringResource(R.string.st_privacy_short)) { host.push(ChRoute.StoryPrivacy) }
    }
}

/** Replies: on shows ✓, off is dim with a crossed icon — and each tap says which it is now. */
@Composable
internal fun RepliesChip(allowReplies: Boolean, onToggle: () -> Unit) {
    val host = LocalChat.current
    val onText = stringResource(R.string.st_replies_on_toast)
    val offText = stringResource(R.string.st_replies_off_toast)
    if (allowReplies) StoryChip(Icons.Rounded.Reply, stringResource(R.string.st_replies_short), trailing = Icons.Rounded.Check) { onToggle(); host.showToast(offText) }
    else StoryChip(Icons.Rounded.CommentsDisabled, stringResource(R.string.st_replies_closed), dim = true) { onToggle(); host.showToast(onText) }
}

@Composable
private fun StoryChip(icon: ImageVector, text: String, dim: Boolean = false, trailing: ImageVector? = null, onClick: () -> Unit) {
    val shape = RoundedCornerShape(20.dp)
    Row(
        Modifier.clip(shape).background(Color.Black.copy(alpha = if (dim) 0.5f else 0.35f)).border(1.dp, Color.White.copy(alpha = if (dim) 0.1f else 0.25f), shape)
            .clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        val ink = Color.White.copy(alpha = if (dim) 0.55f else 1f)
        Icon(icon, null, tint = ink, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(text, color = ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
        if (trailing != null) {
            Spacer(Modifier.width(4.dp))
            Icon(trailing, null, tint = Color.White, modifier = Modifier.size(15.dp))
        }
    }
}

/** Send, in the app's red (as the chat's send button) — faded while there's nothing to post. */
@Composable
internal fun PostButton(enabled: Boolean, onClick: () -> Unit) {
    val bg by animateColorAsState(if (enabled) Ch.Red else Color.White.copy(alpha = 0.22f), label = "postBg")
    val scale by animateFloatAsState(if (enabled) 1f else 0.92f, spring(dampingRatio = 0.5f), label = "postScale")
    val send = stringResource(R.string.ch_send)
    Box(
        Modifier.size(56.dp).scale(scale).shadow(if (enabled) 12.dp else 0.dp, CircleShape).clip(CircleShape).background(bg)
            .clickable(enabled = enabled, onClick = onClick).semantics { contentDescription = send },
        contentAlignment = Alignment.Center,
    ) { Icon(Icons.AutoMirrored.Rounded.Send, null, tint = Color.White.copy(alpha = if (enabled) 1f else 0.6f), modifier = Modifier.size(24.dp)) }
}

/** The background colours to pick from (text and voice statuses): the one in use is ringed. */
@Composable
internal fun BackgroundSwatches(selected: Int, modifier: Modifier = Modifier, onPick: (Int) -> Unit) {
    Row(modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
        StoryLook.backgrounds.entries.forEachIndexed { i, (_, colors) ->
            val on = i == selected
            Box(
                Modifier.size(if (on) 38.dp else 32.dp).clip(CircleShape).background(Brush.linearGradient(colors))
                    .border(if (on) 3.dp else 1.5.dp, Color.White.copy(alpha = if (on) 1f else 0.6f), CircleShape).clickable { onPick(i) },
            )
        }
    }
}

/** The fonts to pick from, each «Aa» written in itself. */
@Composable
private fun FontChips(selected: Int, modifier: Modifier = Modifier, onPick: (Int) -> Unit) {
    Row(modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        StoryLook.fonts.forEachIndexed { i, name ->
            val on = i == selected
            val shape = RoundedCornerShape(14.dp)
            Text(
                "Aa", fontFamily = StoryLook.font(name), fontWeight = StoryLook.weight(name), fontSize = 17.sp, color = if (on) Ch.Red else Color.White,
                modifier = Modifier.clip(shape).background(if (on) Color.White else Color.Black.copy(alpha = 0.35f)).border(1.dp, Color.White.copy(alpha = 0.3f), shape)
                    .clickable { onPick(i) }.padding(horizontal = 16.dp, vertical = 8.dp),
            )
        }
    }
}

/** A soft shade at the top and bottom of a photo / video, so the tools read on any picture. */
@Composable
internal fun BoxScope.StoryScrims() {
    Box(Modifier.align(Alignment.TopCenter).fillMaxWidth().height(150.dp).background(Brush.verticalGradient(listOf(Color.Black.copy(alpha = 0.5f), Color.Transparent))))
    Box(Modifier.align(Alignment.BottomCenter).fillMaxWidth().height(260.dp).background(Brush.verticalGradient(listOf(Color.Transparent, Color.Black.copy(alpha = 0.65f)))))
}

/** The people mentioned (backlog 84), as form fields. */
private fun mentionFields(ids: List<Int>): Map<String, String> = ids.withIndex().associate { (i, id) -> "mentions[$i]" to id.toString() }

/** Upload in the background: the page closes at once and «My status» spins until it's posted. */
internal fun ChatHost.postStory(fields: Map<String, String>, file: LocalFile?, networkError: String) =
    postStories(listOf(Triple(fields, file, null)), networkError)

/**
 * Several statuses (photos / videos picked together), uploaded one after the other in that order. Each: its fields,
 * its file, and — a video — its poster frame (the picture in a reply's quote of it).
 */
internal fun ChatHost.postStories(batch: List<Triple<Map<String, String>, LocalFile?, LocalFile?>>, networkError: String) {
    pop()
    storyUploading = true
    scope.launch {
        var posted = 0
        for ((fields, file, poster) in batch) {
            try {
                val all = if (publicMode) fields + ("public" to "1") else fields
                val parts = all.mapValues { (_, v) -> v.toRequestBody("text/plain".toMediaTypeOrNull()) as RequestBody }
                val filePart = file?.let { MultipartBody.Part.createFormData("file", it.name, it.file.asRequestBody(it.mime.toMediaTypeOrNull())) }
                val posterPart = poster?.let { MultipartBody.Part.createFormData("thumbnail", it.name, it.file.asRequestBody(it.mime.toMediaTypeOrNull())) }
                ApiClient.chat.postStory(chatAuth(), parts, filePart, posterPart)
                posted++
            } catch (e: kotlinx.coroutines.CancellationException) {
                storyUploading = false
                throw e
            } catch (e: Exception) {
                android.util.Log.w("DorrChat", "story upload failed", e)
                // No HTTP answer at all (offline, tunnel down, timeout): say so instead of staying silent.
                showToast(e.apiFailure().message ?: networkError)
                break
            }
        }
        if (posted > 0) runCatching { refreshStories() }
        storyUploading = false
    }
}
