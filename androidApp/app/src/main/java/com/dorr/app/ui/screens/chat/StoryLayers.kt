package com.dorr.app.ui.screens.chat

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectDragGestures
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.gestures.detectTransformGestures
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
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
import androidx.compose.material.icons.rounded.Brush
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.EmojiEmotions
import androidx.compose.material.icons.rounded.FormatColorFill
import androidx.compose.material.icons.rounded.TextFields
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.Shadow
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Constraints
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.network.ApiClient
import com.dorr.app.network.StoryLayerDto
import com.google.gson.Gson
import kotlin.math.hypot
import kotlin.math.roundToInt

/*
 * Stickers, GIFs, text and drawing on a status (backlog 79–80). They stay layers — sent as
 * `style[layers]` — so a GIF keeps moving for whoever watches. Positions are fractions of the
 * frame (the photo / video's own shape, or the whole screen for a text status), so every phone
 * puts them in the same spot.
 */

/** Pen and text colours. */
private val LayerColors = listOf("#FFFFFF", "#000000", "#EF4444", "#F97316", "#FACC15", "#22C55E", "#3B82F6", "#A855F7", "#EC4899")

private const val PEN_WIDTH = 0.012f

private fun layerColor(hex: String?): Color = runCatching { Color(android.graphics.Color.parseColor(hex ?: "#FFFFFF")) }.getOrDefault(Color.White)

private fun isLight(hex: String?) = hex.equals("#FFFFFF", true) || hex.equals("#FACC15", true)

// ------------------------------------------------------------------------------- showing them

/** What sits on top of a status, as it's watched. Fills its frame. */
@Composable
internal fun StoryLayersView(layers: List<StoryLayerDto>?, modifier: Modifier = Modifier) {
    if (layers.isNullOrEmpty()) return
    BoxWithConstraints(modifier.fillMaxSize()) {
        val frameW = constraints.maxWidth.toFloat()
        Strokes(layers.filter { it.k == "d" }, emptyList(), "#FFFFFF")
        val placed = layers.filter { it.k == "t" || it.k == "s" }
        PlacedLayers(placed) { placed.forEach { LayerBody(it, frameW) } }
    }
}

/** Each child is centred on its layer's spot. */
@Composable
private fun PlacedLayers(items: List<StoryLayerDto>, content: @Composable () -> Unit) {
    Layout(content, Modifier.fillMaxSize()) { measurables, c ->
        val w = c.maxWidth
        val h = c.maxHeight
        val placeables = measurables.map { it.measure(Constraints(maxWidth = (w * 0.9f).roundToInt().coerceAtLeast(1))) }
        layout(w, h) {
            placeables.forEachIndexed { i, p ->
                val l = items.getOrNull(i) ?: return@forEachIndexed
                p.place((l.x * w - p.width / 2f).roundToInt(), (l.y * h - p.height / 2f).roundToInt())
            }
        }
    }
}

/** A text or a sticker, sized from the frame's width so it looks the same on every phone. */
@Composable
private fun LayerBody(l: StoryLayerDto, frameW: Float, modifier: Modifier = Modifier, selected: Boolean = false) {
    val density = LocalDensity.current
    // Touch first (unscaled), then the turn and the size, then the picked outline.
    val base = modifier
        .graphicsLayer { scaleX = l.s; scaleY = l.s; rotationZ = l.r }
        .then(if (selected) Modifier.border(1.5.dp, Color.White.copy(alpha = 0.85f), RoundedCornerShape(8.dp)) else Modifier)
    if (l.k == "s") {
        val context = LocalContext.current
        val w = with(density) { (frameW * 0.32f).toDp() }
        AsyncImage(ApiClient.mediaUrl(l.url), null, imageLoader = chatImages(context), contentScale = ContentScale.Fit, modifier = base.width(w).aspectRatio(l.a ?: 1f))
        return
    }
    val size = with(density) { (frameW * 0.07f).toSp() }
    val pill = l.bg == 1
    Text(
        l.text.orEmpty(),
        color = if (pill) (if (isLight(l.c)) Color.Black else Color.White) else layerColor(l.c),
        fontSize = size, lineHeight = size * 1.2f, fontFamily = StoryLook.font(l.f), fontWeight = StoryLook.weight(l.f), textAlign = TextAlign.Center,
        style = if (pill) TextStyle.Default else TextStyle(shadow = Shadow(Color.Black.copy(alpha = 0.45f), Offset(0f, 2f), 6f)),
        modifier = base.then(if (pill) Modifier.clip(RoundedCornerShape(10.dp)).background(layerColor(l.c)).padding(horizontal = 10.dp, vertical = 4.dp) else Modifier),
    )
}

/** The pen lines, plus the one being drawn right now. */
@Composable
private fun Strokes(strokes: List<StoryLayerDto>, live: List<Offset>, liveColor: String) {
    Canvas(Modifier.fillMaxSize()) {
        strokes.forEach { d ->
            val pts = d.p.orEmpty().chunked(2).filter { it.size == 2 }.map { Offset(it[0] * size.width, it[1] * size.height) }
            drawStroke(pts, layerColor(d.c), (d.w ?: PEN_WIDTH) * size.width)
        }
        drawStroke(live.map { Offset(it.x * size.width, it.y * size.height) }, layerColor(liveColor), PEN_WIDTH * size.width)
    }
}

private fun DrawScope.drawStroke(pts: List<Offset>, color: Color, width: Float) {
    if (pts.isEmpty()) return
    if (pts.size == 1) {
        drawCircle(color, width / 2, pts[0])
        return
    }
    val path = Path().apply {
        moveTo(pts[0].x, pts[0].y)
        for (i in 1 until pts.size) {
            val a = pts[i - 1]
            val b = pts[i]
            quadraticBezierTo(a.x, a.y, (a.x + b.x) / 2, (a.y + b.y) / 2)
        }
        lineTo(pts.last().x, pts.last().y)
    }
    drawPath(path, color, style = Stroke(width, cap = StrokeCap.Round, join = StrokeJoin.Round))
}

// ------------------------------------------------------------------------------- editing them

internal data class EditLayer(val id: Long, val dto: StoryLayerDto)

/** The layers being put on a status, and what the editor is busy with. */
internal class StoryLayerEditor {
    val layers = mutableStateListOf<EditLayer>()
    var drawing by mutableStateOf(false)
    var pen by mutableStateOf("#FFFFFF")
    var selected by mutableStateOf<Long?>(null)
    /** The text being written: id 0 = a new one. */
    var writing by mutableStateOf<EditLayer?>(null)
    var picking by mutableStateOf(false)
    private var nextId = 1L

    val busy: Boolean get() = drawing || writing != null

    fun add(dto: StoryLayerDto) {
        val id = nextId++
        layers.add(EditLayer(id, dto))
        selected = id
    }

    fun update(id: Long, change: (StoryLayerDto) -> StoryLayerDto) {
        val i = layers.indexOfFirst { it.id == id }
        if (i >= 0) layers[i] = layers[i].copy(dto = change(layers[i].dto))
    }

    fun remove(id: Long) {
        layers.removeAll { it.id == id }
        if (selected == id) selected = null
    }

    fun undoStroke() {
        val i = layers.indexOfLast { it.dto.k == "d" }
        if (i >= 0) layers.removeAt(i)
    }

    /** The form field the server keeps (nothing when the status is plain). */
    fun fields(): Map<String, String> = if (layers.isEmpty()) emptyMap() else mapOf("style[layers]" to Gson().toJson(layers.map { it.dto.rounded() }))
}

private fun Float.r3(): Float = (this * 1000).roundToInt() / 1000f

private fun StoryLayerDto.rounded() = copy(x = x.r3(), y = y.r3(), s = s.r3(), r = r.r3(), p = p?.map { it.r3() })

@Composable
internal fun rememberLayerEditor(): StoryLayerEditor = remember { StoryLayerEditor() }

/**
 * The layers, live: drag / pinch / turn a sticker or text, tap it to pick it (tap a picked
 * text to rewrite it), and in pen mode draw with a finger.
 */
@Composable
internal fun StoryLayersEditable(ed: StoryLayerEditor, modifier: Modifier = Modifier, deselectOnTap: Boolean = true) {
    BoxWithConstraints(modifier.fillMaxSize()) {
        val w = constraints.maxWidth.toFloat()
        val h = constraints.maxHeight.toFloat()
        val live = remember { mutableStateListOf<Offset>() }

        if (deselectOnTap && !ed.drawing) Box(Modifier.fillMaxSize().pointerInput(Unit) { detectTapGestures { ed.selected = null } })
        Strokes(ed.layers.map { it.dto }.filter { it.k == "d" }, live, ed.pen)

        val placed = ed.layers.filter { it.dto.k != "d" }
        PlacedLayers(placed.map { it.dto }) {
            placed.forEach { item ->
                key(item.id) {
                    LayerBody(
                        item.dto, w, selected = ed.selected == item.id,
                        modifier = Modifier
                            .pointerInput(item.id) {
                                detectTapGestures {
                                    if (ed.selected == item.id && item.dto.k == "t") ed.writing = ed.layers.firstOrNull { it.id == item.id }
                                    else ed.selected = item.id
                                }
                            }
                            .pointerInput(item.id, w, h) {
                                detectTransformGestures { _, pan, zoom, rotation ->
                                    ed.selected = item.id
                                    ed.update(item.id) { d ->
                                        d.copy(x = (d.x + pan.x / w).coerceIn(0f, 1f), y = (d.y + pan.y / h).coerceIn(0f, 1f), s = (d.s * zoom).coerceIn(0.2f, 6f), r = d.r + rotation)
                                    }
                                }
                            },
                    )
                }
            }
        }

        // Pen mode: the whole frame takes the finger.
        if (ed.drawing) Box(
            Modifier.fillMaxSize().pointerInput(w, h) {
                fun norm(o: Offset) = Offset((o.x / w).coerceIn(0f, 1f), (o.y / h).coerceIn(0f, 1f))
                fun commit() {
                    if (live.isNotEmpty()) ed.layers.add(EditLayer(System.nanoTime(), StoryLayerDto("d", c = ed.pen, w = PEN_WIDTH, p = live.flatMap { listOf(it.x, it.y) })))
                    live.clear()
                }
                detectDragGestures(
                    onDragStart = { live.clear(); live.add(norm(it)) },
                    onDragEnd = { commit() },
                    onDragCancel = { commit() },
                ) { change, _ ->
                    val p = norm(change.position)
                    val last = live.lastOrNull()
                    if (last == null || hypot(p.x - last.x, p.y - last.y) > 0.003f) live.add(p)
                }
            },
        )
    }
}

/** Top tools: delete the picked one, stickers / GIF, text, pen. */
@Composable
internal fun LayerTools(ed: StoryLayerEditor, withText: Boolean = true) {
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
        ed.selected?.let { id -> LayerTool(Icons.Rounded.Delete) { ed.remove(id) } }
        LayerTool(Icons.Rounded.EmojiEmotions) { ed.selected = null; ed.picking = true }
        if (withText) LayerTool(Icons.Rounded.TextFields) { ed.selected = null; ed.writing = EditLayer(0, StoryLayerDto("t", y = 0.4f, c = "#FFFFFF", f = "bold")) }
        LayerTool(Icons.Rounded.Brush) { ed.selected = null; ed.drawing = true }
    }
}

/** Pen mode's bar: undo · colours · done. */
@Composable
internal fun DrawingBar(ed: StoryLayerEditor, modifier: Modifier = Modifier) {
    BackHandler { ed.drawing = false }
    Row(modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
        LayerTool(Icons.Rounded.Undo) { ed.undoStroke() }
        ColorDots(ed.pen, Modifier.weight(1f)) { ed.pen = it }
        LayerTool(Icons.Rounded.Check) { ed.drawing = false }
    }
}

@Composable
private fun ColorDots(current: String, modifier: Modifier = Modifier, onPick: (String) -> Unit) {
    Row(modifier.horizontalScroll(rememberScrollState()).padding(horizontal = 8.dp), horizontalArrangement = Arrangement.spacedBy(6.dp, Alignment.CenterHorizontally), verticalAlignment = Alignment.CenterVertically) {
        LayerColors.forEach { hex ->
            val on = hex == current
            Box(
                Modifier.size(if (on) 28.dp else 22.dp).clip(CircleShape).background(layerColor(hex))
                    .border(if (on) 3.dp else 1.5.dp, Color.White, CircleShape).clickable { onPick(hex) },
            )
        }
    }
}

@Composable
private fun LayerTool(icon: ImageVector?, label: String? = null, onClick: () -> Unit) {
    Box(
        Modifier.size(42.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.3f)).border(1.dp, Color.White.copy(alpha = 0.35f), CircleShape).clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        if (icon != null) Icon(icon, null, tint = Color.White, modifier = Modifier.size(21.dp))
        if (label != null) Text(label, color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
    }
}

/** Writing (or rewriting) a text: font, a coloured pill behind it, colour. A tap outside = done. */
@Composable
internal fun LayerTextWriter(ed: StoryLayerEditor) {
    val start = ed.writing ?: return
    var text by remember(start.id) { mutableStateOf(start.dto.text.orEmpty()) }
    var color by remember(start.id) { mutableStateOf(start.dto.c ?: "#FFFFFF") }
    var font by remember(start.id) { mutableStateOf(start.dto.f ?: "bold") }
    var pill by remember(start.id) { mutableStateOf(start.dto.bg == 1) }
    val focus = remember { FocusRequester() }
    LaunchedEffect(start.id) { focus.requestFocus() }

    fun done() {
        val t = text.trim()
        val dto = start.dto.copy(text = t, c = color, f = font, bg = if (pill) 1 else 0)
        when {
            start.id == 0L -> if (t.isNotEmpty()) ed.add(dto)
            t.isEmpty() -> ed.remove(start.id)
            else -> ed.update(start.id) { dto }
        }
        ed.writing = null
    }
    BackHandler { done() }

    Box(Modifier.fillMaxSize().background(Color.Black.copy(alpha = 0.55f)).clickable(remember { MutableInteractionSource() }, null) { done() }) {
        Row(Modifier.fillMaxWidth().padding(12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            LayerTool(null, "Aa") { font = StoryLook.fonts[(StoryLook.fonts.indexOf(font) + 1) % StoryLook.fonts.size] }
            LayerTool(Icons.Rounded.FormatColorFill) { pill = !pill }
            Spacer(Modifier.weight(1f))
            LayerTool(Icons.Rounded.Check) { done() }
        }
        Box(Modifier.align(Alignment.Center).imePadding().padding(horizontal = 24.dp)) {
            BasicTextField(
                text, { text = it.take(200) },
                textStyle = TextStyle(
                    color = if (pill) (if (isLight(color)) Color.Black else Color.White) else layerColor(color),
                    fontSize = 30.sp, fontFamily = StoryLook.font(font), fontWeight = StoryLook.weight(font), textAlign = TextAlign.Center,
                ),
                cursorBrush = SolidColor(if (pill && isLight(color)) Color.Black else Color.White),
                modifier = Modifier.focusRequester(focus)
                    .then(if (pill) Modifier.clip(RoundedCornerShape(12.dp)).background(layerColor(color)).padding(horizontal = 12.dp, vertical = 6.dp) else Modifier),
            )
        }
        ColorDots(color, Modifier.align(Alignment.BottomCenter).fillMaxWidth().imePadding().navigationBarsPadding().padding(bottom = 16.dp)) { color = it }
    }
}

/** The emoji / sticker / GIF sheet: an emoji lands as big text, a sticker or GIF as a picture. */
@Composable
internal fun LayerStickerPicker(ed: StoryLayerEditor) {
    if (!ed.picking) return
    ExpressionPanel(
        onDismiss = { ed.picking = false },
        onEmoji = { emoji ->
            ed.add(StoryLayerDto("t", y = 0.4f, s = 1.8f, text = emoji, c = "#FFFFFF"))
            ed.picking = false
        },
        onPick = { pick ->
            val url = (pick.extra["webp"] ?: pick.extra["url"])?.toString()
            if (url != null) {
                val w = (pick.extra["width"] as? Number)?.toFloat() ?: 0f
                val h = (pick.extra["height"] as? Number)?.toFloat() ?: 0f
                ed.add(StoryLayerDto("s", y = 0.4f, url = url, a = if (w > 0 && h > 0) (w / h).coerceIn(0.2f, 5f) else 1f))
            }
        },
    )
}
