package com.dorr.app.ui.screens.chat

import android.media.MediaMetadataRetriever
import android.net.Uri
import android.widget.VideoView
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.Crossfade
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.togetherWith
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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Palette
import androidx.compose.material.icons.rounded.Reply
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.theme.CairoFontFamily
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody

/** Maximum video length the app lets through before the server says no (the admin can raise it). */
private const val VIDEO_LIMIT_SECONDS = 60

@Composable
fun StoryComposerPage(mediaUri: String?) {
    if (mediaUri == null) TextStoryEditor() else MediaStoryEditor(Uri.parse(mediaUri))
}

// ------------------------------------------------------------------------------- text

@Composable
private fun TextStoryEditor() {
    val host = LocalChat.current
    var text by remember { mutableStateOf("") }
    var bg by remember { mutableIntStateOf(0) }
    var font by remember { mutableIntStateOf(0) }
    var allowReplies by remember { mutableStateOf(true) }
    val focus = remember { FocusRequester() }
    val backgrounds = StoryLook.backgrounds.keys.toList()
    val fontName = StoryLook.fonts[font]
    var fontBounce by remember { mutableStateOf(false) }
    val fontScale by animateFloatAsState(if (fontBounce) 1.3f else 1f, spring(dampingRatio = 0.3f, stiffness = 500f), finishedListener = { fontBounce = false }, label = "font")
    val size by animateFloatAsState(StoryLook.textSize(text.length), spring(stiffness = 300f), label = "size")

    LaunchedEffect(Unit) { focus.requestFocus() }
    BackHandler { host.pop() }

    Box(Modifier.fillMaxSize()) {
        // The background crossfades to the next gradient.
        Crossfade(targetState = backgrounds[bg], animationSpec = tween(450), label = "bg") { name ->
            Box(Modifier.fillMaxSize().background(StoryLook.brush(name)))
        }

        Box(Modifier.fillMaxSize().imePadding().padding(horizontal = 28.dp, vertical = 90.dp), contentAlignment = Alignment.Center) {
            if (text.isEmpty()) {
                Text(stringResource(R.string.st_type_status), color = Color.White.copy(alpha = 0.6f), fontSize = size.sp, fontFamily = StoryLook.font(fontName), fontWeight = StoryLook.weight(fontName), textAlign = TextAlign.Center)
            }
            BasicTextField(
                value = text,
                onValueChange = { text = it.take(700) },
                textStyle = TextStyle(color = Color.White, fontSize = size.sp, lineHeight = (size * 1.25f).sp, fontFamily = StoryLook.font(fontName), fontWeight = StoryLook.weight(fontName), textAlign = TextAlign.Center),
                cursorBrush = SolidColor(Color.White),
                modifier = Modifier.fillMaxWidth().focusRequester(focus),
            )
        }

        // Top tools.
        Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            GlassIcon(Icons.Rounded.Close) { host.pop() }
            Spacer(Modifier.weight(1f))
            ToolButton(null, "Aa", Modifier.scale(fontScale)) {
                font = (font + 1) % StoryLook.fonts.size
                fontBounce = true
            }
            Spacer(Modifier.width(10.dp))
            ToolButton(Icons.Rounded.Palette, null) { bg = (bg + 1) % backgrounds.size }
        }

        // Bottom: replies switch + privacy + post.
        Row(Modifier.align(Alignment.BottomCenter).fillMaxWidth().imePadding().navigationBarsPadding().padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
            PostOptions(allowReplies, onToggleReplies = { allowReplies = !allowReplies })
            Spacer(Modifier.weight(1f))
            val networkError = stringResource(R.string.ch_error_network)
            PostButton(enabled = text.isNotBlank()) {
                host.postStory(mapOf("type" to "text", "body" to text.trim(), "style[background]" to backgrounds[bg], "style[font]" to fontName, "allow_replies" to if (allowReplies) "1" else "0"), null, networkError)
            }
        }
    }
}

// ------------------------------------------------------------------------------- photo / video

@Composable
private fun MediaStoryEditor(uri: Uri) {
    val host = LocalChat.current
    val context = LocalContext.current
    var caption by remember { mutableStateOf("") }
    var allowReplies by remember { mutableStateOf(true) }
    val mime = remember(uri) { context.contentResolver.getType(uri).orEmpty() }
    val isVideo = mime.startsWith("video/")
    val durationMs = remember(uri) {
        if (!isVideo) null else runCatching {
            MediaMetadataRetriever().run {
                setDataSource(context, uri)
                extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)?.toLong().also { release() }
            }
        }.getOrNull()
    }
    val tooLong = isVideo && (durationMs ?: 0) > VIDEO_LIMIT_SECONDS * 1000 + 500
    val tooLongText = stringResource(R.string.st_video_too_long, VIDEO_LIMIT_SECONDS)

    BackHandler { host.pop() }

    Box(Modifier.fillMaxSize().background(Color.Black)) {
        if (isVideo) {
            AndroidView(
                factory = { ctx -> VideoView(ctx).apply { setVideoURI(uri); setOnPreparedListener { it.isLooping = true; start() } } },
                onRelease = { it.stopPlayback() },
                modifier = Modifier.fillMaxSize(),
            )
        } else {
            AsyncImage(uri, null, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize())
        }

        Row(Modifier.fillMaxWidth().padding(12.dp)) { GlassIcon(Icons.Rounded.Close) { host.pop() } }

        Column(Modifier.align(Alignment.BottomCenter).fillMaxWidth().imePadding().navigationBarsPadding().padding(14.dp)) {
            AnimatedVisibility(tooLong) {
                Text(tooLongText, color = Color.White, fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 10.dp).clip(RoundedCornerShape(12.dp)).background(Color(0xCCDC2626)).padding(horizontal = 12.dp, vertical = 6.dp))
            }
            Box(Modifier.fillMaxWidth().heightIn(min = 48.dp).clip(RoundedCornerShape(24.dp)).background(Color.Black.copy(alpha = 0.45f)).padding(horizontal = 18.dp, vertical = 13.dp)) {
                if (caption.isEmpty()) Text(stringResource(R.string.st_add_caption), color = Color.White.copy(alpha = 0.7f), fontSize = 15.sp)
                BasicTextField(caption, { caption = it.take(700) }, maxLines = 4, textStyle = TextStyle(color = Color.White, fontSize = 15.sp, fontFamily = CairoFontFamily), cursorBrush = SolidColor(Color.White), modifier = Modifier.fillMaxWidth())
            }
            Spacer(Modifier.size(12.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                PostOptions(allowReplies, onToggleReplies = { allowReplies = !allowReplies })
                Spacer(Modifier.weight(1f))
                val networkError = stringResource(R.string.ch_error_network)
                PostButton(enabled = !tooLong) {
                    val file = copyToCache(context, uri, if (isVideo) "story.mp4" else "story.jpg") ?: return@PostButton
                    host.postStory(
                        buildMap {
                            put("type", if (isVideo) "video" else "image")
                            if (caption.isNotBlank()) put("body", caption.trim())
                            durationMs?.let { put("duration_ms", it.toString()) }
                            put("allow_replies", if (allowReplies) "1" else "0")
                        },
                        file,
                        networkError,
                    )
                }
            }
        }
    }
}

// ------------------------------------------------------------------------------- shared

@Composable
private fun ToolButton(icon: ImageVector?, label: String?, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Box(
        modifier.size(46.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.25f)).border(1.dp, Color.White.copy(alpha = 0.35f), CircleShape).clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        if (icon != null) Icon(icon, null, tint = Color.White, modifier = Modifier.size(22.dp))
        if (label != null) Text(label, color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp)
    }
}

@Composable
private fun PostOptions(allowReplies: Boolean, onToggleReplies: () -> Unit) {
    val host = LocalChat.current
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        // From the home page it's public — everyone sees it; from the chat, my usual audience.
        if (host.publicMode) Pill(Icons.Rounded.Public, stringResource(R.string.st_public_everyone)) {}
        else Pill(Icons.Rounded.Shield, stringResource(R.string.st_privacy)) { host.push(ChRoute.StoryPrivacy) }
        Pill(Icons.Rounded.Reply, stringResource(if (allowReplies) R.string.st_allow_replies else R.string.st_replies_off), active = allowReplies, onClick = onToggleReplies)
    }
}

@Composable
private fun Pill(icon: ImageVector, text: String, active: Boolean = true, onClick: () -> Unit) {
    Row(
        Modifier.clip(RoundedCornerShape(20.dp)).background(Color.Black.copy(alpha = if (active) 0.35f else 0.6f)).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = Color.White.copy(alpha = if (active) 1f else 0.5f), modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(text, color = Color.White.copy(alpha = if (active) 1f else 0.6f), fontSize = 12.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun PostButton(enabled: Boolean, onClick: () -> Unit) {
    AnimatedContent(enabled, label = "post", transitionSpec = { scaleIn(spring(dampingRatio = 0.45f)) togetherWith scaleOut() }) { on ->
        Box(
            Modifier.size(58.dp).shadow(if (on) 16.dp else 0.dp, CircleShape, spotColor = Color.Black).clip(CircleShape)
                .background(if (on) Color.White else Color.White.copy(alpha = 0.35f)).clickable(enabled = on, onClick = onClick),
            contentAlignment = Alignment.Center,
        ) { Icon(Icons.AutoMirrored.Rounded.Send, null, tint = if (on) Ch.Red else Color.White, modifier = Modifier.size(26.dp)) }
    }
}

/**
 * Upload in the background: the page closes at once and "My status" spins until it's posted.
 */
internal fun ChatHost.postStory(fields: Map<String, String>, file: LocalFile?, networkError: String) {
    pop()
    storyUploading = true
    scope.launch {
        try {
            val all = if (publicMode) fields + ("public" to "1") else fields
            val parts = all.mapValues { (_, v) -> v.toRequestBody("text/plain".toMediaTypeOrNull()) as RequestBody }
            val filePart = file?.let { MultipartBody.Part.createFormData("file", it.name, it.file.asRequestBody(it.mime.toMediaTypeOrNull())) }
            ApiClient.chat.postStory(chatAuth(), parts, filePart)
            refreshStories()
        } catch (e: kotlinx.coroutines.CancellationException) {
            storyUploading = false
            throw e
        } catch (e: Exception) {
            android.util.Log.w("DorrChat", "story upload failed", e)
            // No HTTP answer at all (offline, tunnel down, timeout): say so instead of staying silent.
            showToast(e.apiFailure().message ?: networkError)
        }
        storyUploading = false
    }
}
