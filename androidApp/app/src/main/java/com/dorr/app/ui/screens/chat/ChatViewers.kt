package com.dorr.app.ui.screens.chat

import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.pdf.PdfRenderer
import android.net.Uri
import android.os.ParcelFileDescriptor
import android.widget.MediaController
import android.widget.VideoView
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTransformGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.OpenInNew
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import okhttp3.Request
import java.io.File
import java.security.MessageDigest

/** A PDF to show: where it is and what it's called. */
data class PdfTarget(val url: String, val name: String)

/**
 * The full-screen viewers any chat page can open: a video played inside the app (instead of
 * handing it to another player), and a PDF read page by page without leaving the chat.
 * Drawn once on top of everything by [ChatViewersHost].
 */
object ChatViewers {
    var video by mutableStateOf<String?>(null)
    var pdf by mutableStateOf<PdfTarget?>(null)

    fun isPdf(name: String?, mime: String?): Boolean =
        mime == "application/pdf" || name?.endsWith(".pdf", ignoreCase = true) == true
}

@Composable
fun ChatViewersHost() {
    ChatViewers.video?.let { url -> VideoPlayerDialog(url) { ChatViewers.video = null } }
    ChatViewers.pdf?.let { target -> PdfViewerDialog(target) { ChatViewers.pdf = null } }
}

// =============================================================================== video

/** The video in the app, with the system's play / pause / seek bar (no extra library). */
@Composable
private fun VideoPlayerDialog(url: String, onClose: () -> Unit) {
    var ready by remember { mutableStateOf(false) }
    var failed by remember { mutableStateOf(false) }
    Dialog(onDismissRequest = onClose, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        BackHandler(onBack = onClose)
        Box(Modifier.fillMaxSize().background(Color.Black), contentAlignment = Alignment.Center) {
            AndroidView(
                factory = { ctx ->
                    VideoView(ctx).apply {
                        val controls = MediaController(ctx)
                        controls.setAnchorView(this)
                        setMediaController(controls)
                        setVideoURI(Uri.parse(ApiClient.mediaUrl(url)))
                        setOnPreparedListener { ready = true; start(); controls.show(2500) }
                        setOnErrorListener { _, _, _ -> failed = true; true }
                    }
                },
                onRelease = { it.stopPlayback() },
                modifier = Modifier.fillMaxWidth(),
            )
            if (!ready && !failed) CircularProgressIndicator(color = Color.White, strokeWidth = 3.dp)
            if (failed) Text(stringResource(R.string.ch_video_failed), color = Color.White, fontSize = 14.sp)
            Box(
                Modifier.align(Alignment.TopEnd).statusBarsPadding().padding(14.dp).size(42.dp).clip(CircleShape)
                    .background(Color.White.copy(alpha = 0.16f)).clickable(onClick = onClose),
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.Close, null, tint = Color.White, modifier = Modifier.size(24.dp)) }
        }
    }
}

// =============================================================================== PDF

/**
 * The PDF inside the chat: downloaded once to the phone's cache, then drawn page by page with
 * Android's own PdfRenderer. Pinch to zoom; "open with" hands it to another app.
 */
@Composable
private fun PdfViewerDialog(target: PdfTarget, onClose: () -> Unit) {
    val context = LocalContext.current
    val doc by produceState<PdfDocument?>(null, target.url) {
        value = withContext(Dispatchers.IO) { cachedPdf(context, target.url)?.let { PdfDocument.open(it) } }
        if (value == null) value = PdfDocument.Failed
    }
    var scale by remember { mutableFloatStateOf(1f) }
    var offset by remember { mutableStateOf(Offset.Zero) }
    val list = rememberLazyListState()
    val page by remember { derivedStateOf { list.firstVisibleItemIndex + 1 } }

    Dialog(onDismissRequest = onClose, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        BackHandler(onBack = onClose)
        Column(Modifier.fillMaxSize().background(Color(0xFF1F2430))) {
            Row(Modifier.fillMaxWidth().statusBarsPadding().padding(10.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(40.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.12f)).clickable(onClick = onClose), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.Close, null, tint = Color.White)
                }
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(target.name, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    (doc as? PdfDocument.Ready)?.let { Text(stringResource(R.string.ch_pdf_page, page, it.pages), color = Color.White.copy(alpha = 0.6f), fontSize = 12.sp) }
                }
                Box(
                    Modifier.size(40.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.12f)).clickable {
                        runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(ApiClient.mediaUrl(target.url)))) }
                    },
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.AutoMirrored.Rounded.OpenInNew, null, tint = Color.White, modifier = Modifier.size(20.dp)) }
            }
            when (val d = doc) {
                null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Color.White, strokeWidth = 3.dp) }
                PdfDocument.Failed -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Text(stringResource(R.string.ch_pdf_failed), color = Color.White, fontSize = 14.sp)
                }
                is PdfDocument.Ready -> LazyColumn(
                    state = list,
                    contentPadding = PaddingValues(12.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                    modifier = Modifier.fillMaxSize().navigationBarsPadding()
                        .pointerInput(Unit) {
                            detectTransformGestures { _, pan, zoom, _ ->
                                scale = (scale * zoom).coerceIn(1f, 4f)
                                offset = if (scale == 1f) Offset.Zero else offset + pan
                            }
                        }
                        .graphicsLayer { scaleX = scale; scaleY = scale; translationX = offset.x; translationY = offset.y },
                ) {
                    items(d.pages) { index -> PdfPageImage(d, index) }
                }
            }
        }
    }
}

@Composable
private fun PdfPageImage(doc: PdfDocument.Ready, index: Int) {
    val bitmap by produceState<Bitmap?>(null, doc, index) { value = doc.render(index, 1400) }
    Box(Modifier.fillMaxWidth().aspectRatio(doc.ratio(index)).clip(RoundedCornerShape(6.dp)).background(Color.White), contentAlignment = Alignment.Center) {
        bitmap?.let { Image(it.asImageBitmap(), null, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize()) }
            ?: CircularProgressIndicator(Modifier.size(24.dp), color = Ch.Red, strokeWidth = 2.dp)
    }
}

/**
 * Under a PDF in the chat: its first page (only for files up to 5 MB, so a big PDF is never
 * downloaded just to be shown in the list).
 */
@Composable
internal fun PdfFirstPage(url: String, size: Long?, modifier: Modifier = Modifier) {
    if (size == null || size > 5L * 1024 * 1024) return
    val context = LocalContext.current
    val bitmap by produceState<Bitmap?>(null, url) {
        value = withContext(Dispatchers.IO) { cachedPdf(context, url)?.let { PdfDocument.open(it) as? PdfDocument.Ready }?.render(0, 700) }
    }
    bitmap?.let {
        Image(
            it.asImageBitmap(), null, contentScale = ContentScale.Crop, alignment = Alignment.TopCenter,
            modifier = modifier.fillMaxWidth().height(140.dp).clip(RoundedCornerShape(12.dp)).background(Color.White),
        )
    }
}

/** An open PDF; rendering one page at a time (PdfRenderer can't draw two at once). */
private sealed class PdfDocument {
    data object Failed : PdfDocument()

    class Ready(private val renderer: PdfRenderer) : PdfDocument() {
        private val lock = Mutex()
        val pages: Int = renderer.pageCount
        private val ratios = FloatArray(pages) { i -> renderer.openPage(i).use { it.width.toFloat() / it.height.coerceAtLeast(1) } }

        fun ratio(index: Int): Float = ratios.getOrElse(index) { 0.7f }.coerceAtLeast(0.1f)

        suspend fun render(index: Int, width: Int): Bitmap? = withContext(Dispatchers.IO) {
            lock.withLock {
                runCatching {
                    renderer.openPage(index).use { page ->
                        val height = (width * page.height.toFloat() / page.width.coerceAtLeast(1)).toInt().coerceAtLeast(1)
                        Bitmap.createBitmap(width, height, Bitmap.Config.ARGB_8888).also { bmp ->
                            bmp.eraseColor(android.graphics.Color.WHITE)
                            page.render(bmp, null, null, PdfRenderer.Page.RENDER_MODE_FOR_DISPLAY)
                        }
                    }
                }.getOrNull()
            }
        }
    }

    companion object {
        fun open(file: File): PdfDocument = runCatching {
            Ready(PdfRenderer(ParcelFileDescriptor.open(file, ParcelFileDescriptor.MODE_READ_ONLY)))
        }.getOrDefault(Failed)
    }
}

/** The PDF on this phone, downloaded once (kept in the cache folder, which Android may clear). */
private fun cachedPdf(context: Context, url: String): File? = runCatching {
    val name = MessageDigest.getInstance("SHA-1").digest(url.toByteArray()).joinToString("") { "%02x".format(it) }
    val file = File(File(context.cacheDir, "chat-docs").apply { mkdirs() }, "$name.pdf")
    if (file.length() > 0) return@runCatching file
    val request = Request.Builder().url(ApiClient.mediaUrl(url) ?: return@runCatching null).build()
    ApiClient.okHttpClient.newCall(request).execute().use { response ->
        if (!response.isSuccessful) return@runCatching null
        val part = File(file.parentFile, "$name.part")
        response.body?.byteStream()?.use { input -> part.outputStream().use { input.copyTo(it) } }
        part.renameTo(file)
    }
    file.takeIf { it.length() > 0 }
}.getOrNull()
