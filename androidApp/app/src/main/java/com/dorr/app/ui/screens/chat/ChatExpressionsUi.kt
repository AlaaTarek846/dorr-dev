package com.dorr.app.ui.screens.chat

import android.content.Context
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.staggeredgrid.LazyVerticalStaggeredGrid
import androidx.compose.foundation.lazy.staggeredgrid.StaggeredGridCells
import androidx.compose.foundation.lazy.staggeredgrid.rememberLazyStaggeredGridState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.EmojiEmotions
import androidx.compose.material.icons.rounded.Gif
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.StickyNote2
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
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
import com.dorr.app.network.GifDto
import com.dorr.app.network.StickerDto
import com.dorr.app.network.StickerLibraryDto
import com.dorr.app.ui.theme.CairoFontFamily
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.filter

// =============================================================================== bubbles

/** A sticker: no bubble, a little bounce when it arrives, the time in a pill underneath. */
@Composable
fun StickerBubble(m: UiMessage, mine: Boolean, footer: @Composable () -> Unit) {
    val context = LocalContext.current
    val meta = m.dto.meta
    val url = meta?.str("url")
    val pop = remember { Animatable(if (m.fresh) 0.4f else 1f) }
    LaunchedEffect(Unit) { if (pop.value < 1f) pop.animateTo(1f, spring(dampingRatio = 0.4f, stiffness = 320f)) }
    Column(horizontalAlignment = if (mine) Alignment.End else Alignment.Start) {
        AsyncImage(
            ApiClient.mediaUrl(url), meta?.str("title") ?: meta?.str("emoji"), imageLoader = chatImages(context),
            contentScale = ContentScale.Fit, modifier = Modifier.size(150.dp).scale(pop.value),
        )
        Box(Modifier.padding(top = 2.dp)) { footer() }
    }
}

/** A GIF: rounded, moving, with a small "GIF" badge and the time in the corner. */
@Composable
fun GifBubble(m: UiMessage, mine: Boolean, footer: @Composable () -> Unit) {
    val context = LocalContext.current
    val meta = m.dto.meta
    val w = meta?.get("width")?.asInt?.takeIf { it > 0 } ?: 1
    val h = meta?.get("height")?.asInt?.takeIf { it > 0 } ?: 1
    Box(Modifier.width(240.dp).aspectRatio((w.toFloat() / h).coerceIn(0.6f, 2.2f)).padding(3.dp).clip(RoundedCornerShape(17.dp)).background(Ch.SurfaceMuted)) {
        AsyncImage(ApiClient.mediaUrl(meta?.str("webp") ?: meta?.str("url")), meta?.str("title"), imageLoader = chatImages(context), contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
        Text(
            "GIF", color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.ExtraBold,
            modifier = Modifier.align(Alignment.TopStart).padding(8.dp).clip(RoundedCornerShape(6.dp)).background(Color.Black.copy(alpha = 0.45f)).padding(horizontal = 5.dp, vertical = 1.dp),
        )
        Box(Modifier.align(Alignment.BottomEnd).padding(8.dp)) { footer() }
    }
}

// =============================================================================== recents

/** What I sent lately (stickers and GIFs), newest first — kept on the phone. */
private data class RecentItem(val kind: String, val source: String, val id: String, val url: String, val width: Int = 0, val height: Int = 0)

private object Recents {
    private const val PREFS = "chat_expressions"
    private val gson = Gson()

    fun load(context: Context, kind: String): List<RecentItem> = runCatching {
        gson.fromJson<List<RecentItem>>(context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(kind, "[]"), object : TypeToken<List<RecentItem>>() {}.type)
    }.getOrNull().orEmpty()

    fun add(context: Context, item: RecentItem) {
        val list = (listOf(item) + load(context, item.kind).filterNot { it.id == item.id && it.source == item.source }).take(24)
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString(item.kind, gson.toJson(list)).apply()
    }
}

// =============================================================================== the panel

/** What was picked, ready to send: the message type and the fields (the server builds the rest). */
data class ExpressionPick(val type: String, val extra: Map<String, Any?>)

/**
 * Emoji · Stickers · GIF, in one sheet. Stickers: my recents, Dorr's packs, and the big
 * animated library (Giphy) with search. GIFs: trending, quick moods, search, endless scroll.
 * Everything pops when touched; a tap sends it at once.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ExpressionPanel(onDismiss: () -> Unit, onEmoji: (String) -> Unit, onPick: (ExpressionPick) -> Unit) {
    var tab by remember { mutableStateOf(1) }
    val sheet = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    var library by remember { mutableStateOf<StickerLibraryDto?>(null) }
    LaunchedEffect(Unit) { library = runCatching { ApiClient.chat.stickers(chatAuth()).data }.getOrNull() ?: StickerLibraryDto() }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheet, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 28.dp, topEnd = 28.dp)) {
        Column(Modifier.fillMaxWidth().height(460.dp)) {
            TabsRow(tab) { tab = it }
            AnimatedContent(tab, label = "exprTab", transitionSpec = {
                val dir = if (targetState > initialState) 1 else -1
                (slideInHorizontally(tween(260)) { dir * it / 5 } + fadeIn(tween(200))) togetherWith (slideOutHorizontally(tween(200)) { -dir * it / 5 } + fadeOut(tween(150)))
            }, modifier = Modifier.weight(1f)) { t ->
                when (t) {
                    0 -> EmojiTab(onEmoji)
                    1 -> StickersTab(library, onPick = { onPick(it); onDismiss() })
                    else -> GifTab(library, onPick = { onPick(it); onDismiss() })
                }
            }
        }
    }
}

@Composable
private fun TabsRow(selected: Int, onSelect: (Int) -> Unit) {
    val tabs = listOf(Icons.Rounded.EmojiEmotions to R.string.ch_expr_emoji, Icons.Rounded.StickyNote2 to R.string.ch_expr_stickers, Icons.Rounded.Gif to R.string.ch_expr_gif)
    Row(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp).clip(RoundedCornerShape(18.dp)).background(Ch.SurfaceMuted).padding(4.dp)) {
        tabs.forEachIndexed { i, (icon, label) ->
            val on = i == selected
            val bg by animateColorAsState(if (on) Ch.Surface else Color.Transparent, label = "tabBg")
            val tint by animateColorAsState(if (on) Ch.Red else Ch.Mut, label = "tabTint")
            Row(
                Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(bg).clickable { onSelect(i) }.padding(vertical = 9.dp),
                horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(icon, null, tint = tint, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(5.dp))
                Text(stringResource(label), color = tint, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp)
            }
        }
    }
}

// ------------------------------------------------------------------------------- emoji

private val EmojiGroups: List<Pair<String, List<String>>> = listOf(
    "😀" to "😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 😉 😍 🥰 😘 😗 😋 😛 😜 🤪 😝 🤗 🤭 🤫 🤔 😐 😏 😒 🙄 😬 😌 😔 😪 😴 😷 🤒 🤕 🥵 🥶 🥴 😵 🤯 🤠 🥳 😎 🤓 🧐 😕 😟 🙁 😮 😯 😲 😳 🥺 😦 😧 😨 😰 😥 😢 😭 😱 😖 😣 😞 😓 😩 😫 😤 😡 😠 🤬".split(" "),
    "👍" to "👍 👎 👌 🤌 🤏 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ ✋ 🤚 🖐️ 🖖 👋 👏 🙌 👐 🤲 🤝 🙏 💪 🦾 ✍️ 💅 🤳 👀 👁️ 👄 🫶".split(" "),
    "❤️" to "❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 💟 ☮️ ✨ ⭐ 🌟 💫 🔥 💯 ✅ ❌ ‼️ ❓ 💤".split(" "),
    "🐶" to "🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🙈 🙉 🙊 🐔 🐧 🐦 🦆 🦅 🦉 🐴 🦄 🐝 🦋 🐢 🐍 🐬 🐳 🐟 🌸 🌹 🌻 🌷 🌳 🌴 🌵 🍀 🌙 ☀️ ⛅ 🌈 ❄️ 🌊".split(" "),
    "🍕" to "🍏 🍎 🍐 🍊 🍋 🍌 🍉 🍇 🍓 🍒 🍑 🥭 🍍 🥥 🥑 🍅 🥕 🌽 🥔 🍞 🧀 🥚 🍳 🥞 🍗 🍖 🌭 🍔 🍟 🍕 🌮 🌯 🥙 🥗 🍝 🍜 🍣 🍤 🍩 🍪 🎂 🍰 🍫 🍬 🍭 ☕ 🍵 🧃 🥤 🍹".split(" "),
    "⚽" to "⚽ 🏀 🏈 ⚾ 🎾 🏐 🏓 🏸 🥊 🏆 🥇 🎮 🎲 🎯 🎳 🎤 🎧 🎸 🎹 🥁 🎬 🎨 🎁 🎉 🎊 🎈 🕌 🕋 🌙 🏠 🚗 🚕 🚌 ✈️ 🚀 ⛵ 🗺️ 📱 💻 ⌚ 📷 💡 📚 ✏️ 💰 💳 🛒".split(" "),
)

@Composable
private fun EmojiTab(onEmoji: (String) -> Unit) {
    var group by remember { mutableStateOf(0) }
    Column(Modifier.fillMaxSize()) {
        Row(Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 4.dp), horizontalArrangement = Arrangement.SpaceEvenly) {
            EmojiGroups.forEachIndexed { i, (icon, _) ->
                val size by animateDpAsState(if (i == group) 30.dp else 24.dp, spring(dampingRatio = 0.5f), label = "emojiGroup")
                Box(Modifier.size(42.dp).clip(CircleShape).background(if (i == group) Ch.Red.copy(alpha = 0.1f) else Color.Transparent).clickable { group = i }, contentAlignment = Alignment.Center) {
                    Text(icon, fontSize = (size.value * 0.75f).sp)
                }
            }
        }
        LazyVerticalGrid(GridCells.Fixed(8), contentPadding = PaddingValues(horizontal = 10.dp, vertical = 6.dp), modifier = Modifier.fillMaxSize()) {
            items(EmojiGroups[group].second) { emoji ->
                PopCell(Modifier.aspectRatio(1f), onClick = { onEmoji(emoji) }) { Text(emoji, fontSize = 26.sp) }
            }
        }
    }
}

// ------------------------------------------------------------------------------- stickers

@Composable
private fun StickersTab(library: StickerLibraryDto?, onPick: (ExpressionPick) -> Unit) {
    val context = LocalContext.current
    // -2 = recent, -1 = the Giphy library, else a pack id.
    var source by remember { mutableStateOf(-2) }
    val recents = remember { Recents.load(context, "sticker") }
    LaunchedEffect(library) {
        if (library != null && recents.isEmpty()) source = library.packs.firstOrNull()?.id ?: if (library.libraryEnabled) -1 else -2
    }

    fun packSticker(s: StickerDto) {
        Recents.add(context, RecentItem("sticker", "pack", s.id.toString(), s.url.orEmpty(), s.width ?: 0, s.height ?: 0))
        onPick(ExpressionPick("sticker", mapOf("sticker_id" to s.id, "source" to "pack", "url" to s.url, "emoji" to s.emoji)))
    }

    fun giphySticker(g: GifDto) {
        Recents.add(context, RecentItem("sticker", "giphy", g.id, g.webp ?: g.url, g.width, g.height))
        onPick(ExpressionPick("sticker", mapOf("giphy_id" to g.id, "source" to "giphy", "url" to (g.webp ?: g.url), "title" to g.title)))
    }

    Column(Modifier.fillMaxSize()) {
        // Sources: recent · library · each pack's cover.
        LazyRow(contentPadding = PaddingValues(horizontal = 12.dp, vertical = 4.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            item { SourceChip(source == -2, icon = Icons.Rounded.History) { source = -2 } }
            if (library?.libraryEnabled == true) item { SourceChip(source == -1, icon = Icons.Rounded.Search) { source = -1 } }
            itemsIndexed(library?.packs.orEmpty(), key = { _, p -> p.id }) { _, pack ->
                SourceChip(source == pack.id, image = pack.cover) { source = pack.id }
            }
        }
        when (source) {
            // Nothing at all to offer yet (no packs from the admin, no GIF library): say why, not just "nothing".
            -2 -> if (recents.isEmpty()) EmptyHint(stringResource(
                if (library != null && library.packs.isEmpty() && !library.libraryEnabled) R.string.ch_expr_no_stickers_yet else R.string.ch_expr_no_recent,
            )) else LazyVerticalGrid(GridCells.Fixed(4), contentPadding = PaddingValues(10.dp), modifier = Modifier.fillMaxSize()) {
                items(recents) { r ->
                    PopCell(Modifier.aspectRatio(1f).padding(4.dp), onClick = {
                        Recents.add(context, r)
                        onPick(ExpressionPick("sticker", if (r.source == "pack") mapOf("sticker_id" to r.id.toIntOrNull(), "source" to "pack", "url" to r.url) else mapOf("giphy_id" to r.id, "source" to "giphy", "url" to r.url)))
                    }) { AsyncImage(ApiClient.mediaUrl(r.url), null, imageLoader = chatImages(context), contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize()) }
                }
            }
            -1 -> GiphyGrid(kind = "stickers", columns = 4, onPick = ::giphySticker)
            else -> {
                val pack = library?.packs?.firstOrNull { it.id == source }
                if (pack == null) Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(Modifier.size(26.dp), color = Ch.Red, strokeWidth = 2.5.dp) }
                else LazyVerticalGrid(GridCells.Fixed(4), contentPadding = PaddingValues(10.dp), modifier = Modifier.fillMaxSize()) {
                    items(pack.stickers, key = { it.id }) { s ->
                        PopCell(Modifier.aspectRatio(1f).padding(4.dp), onClick = { packSticker(s) }) {
                            AsyncImage(ApiClient.mediaUrl(s.url), s.emoji, imageLoader = chatImages(context), contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize())
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun SourceChip(selected: Boolean, icon: ImageVector? = null, image: String? = null, onClick: () -> Unit) {
    val context = LocalContext.current
    val bg by animateColorAsState(if (selected) Ch.Red.copy(alpha = 0.12f) else Color.Transparent, label = "srcBg")
    Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(bg).clickable(onClick = onClick).padding(6.dp), contentAlignment = Alignment.Center) {
        when {
            image != null -> AsyncImage(ApiClient.mediaUrl(image), null, imageLoader = chatImages(context), contentScale = ContentScale.Fit, modifier = Modifier.fillMaxSize())
            icon != null -> Icon(icon, null, tint = if (selected) Ch.Red else Ch.Mut, modifier = Modifier.size(22.dp))
        }
    }
}

// ------------------------------------------------------------------------------- GIFs

@Composable
private fun GifTab(library: StickerLibraryDto?, onPick: (ExpressionPick) -> Unit) {
    val context = LocalContext.current
    // The GIF library is off until the server has a Giphy key — say so instead of "no results".
    if (library != null && !library.libraryEnabled) {
        EmptyHint(stringResource(R.string.ch_expr_gifs_off))
        return
    }
    GiphyGrid(kind = "gifs", columns = 2, moods = true) { g ->
        Recents.add(context, RecentItem("gif", "giphy", g.id, g.webp ?: g.url, g.width, g.height))
        onPick(ExpressionPick("gif", mapOf("giphy_id" to g.id, "source" to "giphy", "url" to g.url, "webp" to g.webp, "width" to g.width, "height" to g.height, "title" to g.title)))
    }
}

/**
 * Giphy results: a search field (+ mood chips for GIFs), then a staggered grid that loads more
 * as it nears the end. Trending when the search is empty.
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun GiphyGrid(kind: String, columns: Int, moods: Boolean = false, onPick: (GifDto) -> Unit) {
    val context = LocalContext.current
    var query by remember { mutableStateOf("") }
    val items = remember { mutableStateListOf<GifDto>() }
    var next by remember { mutableStateOf<Int?>(0) }
    var loading by remember { mutableStateOf(false) }
    val gridState = rememberLazyStaggeredGridState()

    suspend fun loadMore() {
        val offset = next ?: return
        if (loading) return
        loading = true
        val page = runCatching { ApiClient.chat.gifs(chatAuth(), kind, query.trim().ifEmpty { null }, offset).data }.getOrNull()
        items.addAll(page?.items.orEmpty().filter { n -> items.none { it.id == n.id } })
        next = page?.nextOffset
        loading = false
    }

    LaunchedEffect(query) {
        if (query.isNotEmpty()) delay(350)
        items.clear()
        next = 0
        loadMore()
    }
    // Near the end → the next page.
    LaunchedEffect(gridState) {
        snapshotFlow { gridState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0 }
            .distinctUntilChanged()
            .filter { it >= items.size - 6 }
            .collect { loadMore() }
    }

    Column(Modifier.fillMaxSize()) {
        ChField(
            query, { query = it.take(50) },
            stringResource(if (kind == "gifs") R.string.ch_expr_search_gifs else R.string.ch_expr_search_stickers),
            modifier = Modifier.padding(horizontal = 14.dp, vertical = 4.dp), icon = Icons.Rounded.Search, clearable = true,
        )
        if (moods) {
            val moodList = listOf("😂" to R.string.ch_mood_haha, "❤️" to R.string.ch_mood_love, "👏" to R.string.ch_mood_congrats, "👋" to R.string.ch_mood_hi, "🎉" to R.string.ch_mood_party, "😢" to R.string.ch_mood_sad, "😴" to R.string.ch_mood_sleepy, "🤔" to R.string.ch_mood_thinking)
            LazyRow(contentPadding = PaddingValues(horizontal = 14.dp, vertical = 4.dp), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                itemsIndexed(moodList) { i, (emoji, label) ->
                    val word = stringResource(label)
                    val on = query == word
                    Row(
                        Modifier.chStagger(i).clip(RoundedCornerShape(14.dp)).background(if (on) Ch.Red else Ch.SurfaceMuted).clickable { query = if (on) "" else word }.padding(horizontal = 12.dp, vertical = 7.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(emoji, fontSize = 14.sp)
                        Spacer(Modifier.width(4.dp))
                        Text(word, color = if (on) Color.White else Ch.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }
        }
        if (items.isEmpty() && !loading) {
            EmptyHint(stringResource(R.string.ch_expr_nothing))
        } else {
            LazyVerticalStaggeredGrid(
                columns = StaggeredGridCells.Fixed(columns), state = gridState,
                contentPadding = PaddingValues(10.dp), verticalItemSpacing = 6.dp, horizontalArrangement = Arrangement.spacedBy(6.dp),
                modifier = Modifier.fillMaxSize(),
            ) {
                items(items.size, key = { items[it].id }) { i ->
                    val g = items[i]
                    val ratio = if (g.width > 0 && g.height > 0) (g.width.toFloat() / g.height).coerceIn(0.5f, 2.5f) else 1f
                    PopCell(Modifier.fillMaxWidth().aspectRatio(if (kind == "stickers") 1f else ratio), shape = RoundedCornerShape(12.dp), onClick = { onPick(g) }) {
                        AsyncImage(
                            ApiClient.mediaUrl(g.preview ?: g.url), g.title, imageLoader = chatImages(context),
                            contentScale = if (kind == "stickers") ContentScale.Fit else ContentScale.Crop,
                            modifier = Modifier.fillMaxSize().background(if (kind == "stickers") Color.Transparent else Ch.SurfaceMuted),
                        )
                    }
                }
            }
        }
        // "Powered by GIPHY" — required by Giphy's terms.
        Text(stringResource(R.string.ch_expr_powered), color = Ch.Soft, fontSize = 10.sp, modifier = Modifier.align(Alignment.CenterHorizontally).padding(bottom = 4.dp))
    }
}

// ------------------------------------------------------------------------------- bits

/** A cell that shrinks under the finger and springs back (the press feels alive). */
@Composable
private fun PopCell(modifier: Modifier, shape: RoundedCornerShape = RoundedCornerShape(12.dp), onClick: () -> Unit, content: @Composable () -> Unit) {
    val press = remember { androidx.compose.foundation.interaction.MutableInteractionSource() }
    val scale by com.dorr.app.ui.screens.wallet.rememberPressScale(press, 0.82f)
    Box(modifier.scale(scale).clip(shape).clickable(interactionSource = press, indication = null, onClick = onClick), contentAlignment = Alignment.Center) { content() }
}

@Composable
private fun EmptyHint(text: String) {
    Box(Modifier.fillMaxWidth().padding(40.dp), contentAlignment = Alignment.Center) {
        Text(text, color = Ch.Mut, fontSize = 13.5.sp)
    }
}
