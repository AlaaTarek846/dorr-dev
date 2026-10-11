package com.dorr.app.ui.screens.sports

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.SpMinuteDto
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise

/*
 * The in-app sports widgets (docs/sports-plan.md §10.3.7): small native pieces in the spirit of
 * the provider's own widgets (games, game, team, player, standings, league, h2h), drawn from
 * our data — tabs that scroll, a "why it's empty" note, photos, form, charts and comparisons.
 */

/** Tabs that scroll sideways (pages with many of them); the picked one is a filled pill. */
@Composable
internal fun ScrollTabs(labels: List<String>, selected: Int, onPick: (Int) -> Unit) {
    val list = rememberLazyListState()
    LaunchedEffect(selected) { if (selected in labels.indices) list.animateScrollToItem(maxOf(0, selected - 1)) }
    LazyRow(state = list, horizontalArrangement = Arrangement.spacedBy(6.dp), modifier = Modifier.fillMaxWidth()) {
        itemsIndexed(labels) { i, label ->
            val on = i == selected
            val bg by animateColorAsState(if (on) Wa.Red else Wa.Surface, label = "stab")
            Text(
                label, color = if (on) Color.White else Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1,
                modifier = Modifier.clip(CircleShape).background(bg).clickable { onPick(i) }.padding(horizontal = 16.dp, vertical = 9.dp),
            )
        }
    }
}

/** Why a part is empty: still on its way, or the data provider has none for it. */
@Composable
internal fun StateNote(state: String?, modifier: Modifier = Modifier, empty: Int = R.string.spx_empty) {
    val text = when (state) {
        "unavailable" -> R.string.spx_unavailable
        "pending" -> R.string.spx_pending
        else -> empty
    }
    Row(modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Wa.Surface).padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(if (state == "unavailable") "🔒" else if (state == "pending") "⏳" else "ℹ️", fontSize = 18.sp)
        Spacer(Modifier.width(10.dp))
        Text(stringResource(text), color = Wa.Mut, fontSize = 13.sp, lineHeight = 19.sp)
    }
}

/** Loading rows. */
@Composable
internal fun SkeletonRows(count: Int = 4, height: Dp = 56.dp) {
    repeat(count) { WaSkeleton(Modifier.fillMaxWidth().height(height).padding(bottom = 8.dp), RoundedCornerShape(16.dp)) }
}

/** A round photo (player, coach) with initials while it loads or when there's none. */
@Composable
internal fun PhotoAvatar(url: String?, name: String?, size: Dp, modifier: Modifier = Modifier, ring: Color? = null) {
    Box(
        modifier.size(size).clip(CircleShape).background(Wa.Field).then(if (ring != null) Modifier.border(2.dp, ring, CircleShape) else Modifier),
        contentAlignment = Alignment.Center,
    ) {
        Text(initials(name), color = Wa.Mut, fontSize = (size.value * 0.34f).sp, fontWeight = FontWeight.ExtraBold)
        if (url != null) AsyncImage(model = ApiClient.mediaUrl(url), contentDescription = name, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
    }
}

internal fun initials(name: String?): String =
    name.orEmpty().split(' ', '.').filter { it.isNotBlank() }.take(2).joinToString("") { it.take(1) }.uppercase().ifEmpty { "?" }

/** W · D · L squares from a form string ("WWDLW"), newest last, as the provider writes it. */
@Composable
internal fun FormStrip(form: String?, modifier: Modifier = Modifier, size: Dp = 22.dp, last: Int = 5) {
    val letters = form.orEmpty().filter { it in "WDL" }.takeLast(last)
    if (letters.isEmpty()) return
    Row(modifier, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        letters.forEachIndexed { i, c ->
            Box(Modifier.size(size).waRise(i).clip(RoundedCornerShape(size * 0.3f)).background(formColor(c)), contentAlignment = Alignment.Center) {
                Text(formLetter(c), color = Color.White, fontSize = (size.value * 0.5f).sp, fontWeight = FontWeight.Black)
            }
        }
    }
}

internal fun formColor(c: Char): Color = when (c) { 'W' -> SpGreen; 'L' -> SpLive; else -> Color(0xFF9CA3AF) }

@Composable
internal fun formLetter(c: Char): String = when (c) {
    'W' -> stringResource(R.string.spx_w)
    'L' -> stringResource(R.string.spx_l)
    else -> stringResource(R.string.spx_d)
}

/** Win · draw · win as one bar in three colours, growing in. */
@Composable
internal fun ProbBar(home: Double, draw: Double, away: Double, homeColor: Color, awayColor: Color, modifier: Modifier = Modifier) {
    val total = (home + draw + away).takeIf { it > 0 } ?: return
    val grow = remember { Animatable(0f) }
    LaunchedEffect(home, draw, away) { grow.animateTo(1f, tween(900, easing = FastOutSlowInEasing)) }
    Column(modifier.fillMaxWidth()) {
        Row(Modifier.fillMaxWidth().height(14.dp).clip(CircleShape).background(Wa.Field)) {
            listOf(home to homeColor, draw to Color(0xFF9CA3AF), away to awayColor).forEach { (v, c) ->
                if (v > 0) Box(Modifier.weight((v / total).toFloat() * grow.value + 0.0001f).fillMaxHeight().background(c))
            }
            if (grow.value < 1f) Spacer(Modifier.weight(1f - grow.value + 0.0001f))
        }
        Row(Modifier.fillMaxWidth().padding(top = 6.dp)) {
            Text("${home.toInt()}%", color = homeColor, fontSize = 13.sp, fontWeight = FontWeight.Black, modifier = Modifier.weight(1f))
            Text("${draw.toInt()}%", color = Wa.Mut, fontSize = 13.sp, fontWeight = FontWeight.Black, textAlign = TextAlign.Center, modifier = Modifier.weight(1f))
            Text("${away.toInt()}%", color = awayColor, fontSize = 13.sp, fontWeight = FontWeight.Black, textAlign = TextAlign.End, modifier = Modifier.weight(1f))
        }
    }
}

/** Two sides of one number, each bar growing from the middle out. */
@Composable
internal fun CompareRow(label: String, home: Double, away: Double, homeText: String, awayText: String, homeColor: Color, awayColor: Color, index: Int = 0) {
    val total = (home + away).takeIf { it > 0 } ?: 1.0
    val grow = remember { Animatable(0f) }
    LaunchedEffect(home, away) { grow.animateTo(1f, tween(700, delayMillis = index * 60, easing = FastOutSlowInEasing)) }
    Column(Modifier.fillMaxWidth().padding(vertical = 6.dp)) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text(homeText, color = if (home >= away) Wa.Ink else Wa.Mut, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.width(52.dp))
            Text(label, color = Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Center, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            Text(awayText, color = if (away >= home) Wa.Ink else Wa.Mut, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.End, modifier = Modifier.width(52.dp))
        }
        Row(Modifier.fillMaxWidth().padding(top = 4.dp).height(7.dp), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
            Box(Modifier.weight(1f).fillMaxHeight().clip(CircleShape).background(Wa.Field), contentAlignment = Alignment.CenterEnd) {
                Box(Modifier.fillMaxWidth((home / total).toFloat() * grow.value).fillMaxHeight().clip(CircleShape).background(homeColor))
            }
            Box(Modifier.weight(1f).fillMaxHeight().clip(CircleShape).background(Wa.Field), contentAlignment = Alignment.CenterStart) {
                Box(Modifier.fillMaxWidth((away / total).toFloat() * grow.value).fillMaxHeight().clip(CircleShape).background(awayColor))
            }
        }
    }
}

/** Columns by 15-minute slice (goals, cards…), rising in one after another. */
@Composable
internal fun MinuteChart(items: List<SpMinuteDto>, color: Color, modifier: Modifier = Modifier) {
    val shown = items.filter { !it.range.startsWith("106") || it.total > 0 }.filter { !it.range.startsWith("91") || it.total > 0 }
    if (shown.isEmpty()) return
    val top = (shown.maxOf { it.total }).coerceAtLeast(1)
    Row(modifier.fillMaxWidth().height(130.dp), horizontalArrangement = Arrangement.spacedBy(6.dp), verticalAlignment = Alignment.Bottom) {
        shown.forEachIndexed { i, m ->
            val grow = remember { Animatable(0f) }
            LaunchedEffect(m.total) { grow.animateTo(1f, tween(650, delayMillis = i * 70, easing = FastOutSlowInEasing)) }
            Column(Modifier.weight(1f).fillMaxHeight(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Bottom) {
                Text("${m.total}", color = Wa.Ink, fontSize = 11.sp, fontWeight = FontWeight.Black)
                Box(
                    Modifier.padding(top = 3.dp).fillMaxWidth().height((86f * m.total / top * grow.value).dp.coerceAtLeast(3.dp))
                        .clip(RoundedCornerShape(topStart = 8.dp, topEnd = 8.dp, bottomStart = 3.dp, bottomEnd = 3.dp))
                        .background(Brush.verticalGradient(listOf(color, color.copy(alpha = 0.55f)))),
                )
                Text(m.range.replace("-", "–"), color = Wa.Soft, fontSize = 9.sp, maxLines = 1, modifier = Modifier.padding(top = 4.dp))
            }
        }
    }
}

/** A big number with its label, on a soft tile. */
@Composable
internal fun StatTile(value: String, label: String, modifier: Modifier = Modifier, accent: Color = Wa.Ink, emoji: String? = null) {
    Column(modifier.clip(RoundedCornerShape(18.dp)).background(Wa.Surface).padding(horizontal = 12.dp, vertical = 12.dp)) {
        emoji?.let { Text(it, fontSize = 16.sp) }
        Text(value, color = accent, fontSize = 22.sp, fontWeight = FontWeight.Black, maxLines = 1)
        Text(label, color = Wa.Mut, fontSize = 11.5.sp, maxLines = 2, lineHeight = 15.sp)
    }
}

/** Tiles two (or three) to a row. */
@Composable
internal fun StatGrid(tiles: List<Triple<String, String, String?>>, columns: Int = 3, accent: Color = Wa.Ink) {
    tiles.chunked(columns).forEachIndexed { r, row ->
        Row(Modifier.fillMaxWidth().padding(bottom = 8.dp).waRise(r), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            row.forEach { (v, l, e) -> StatTile(v, l, Modifier.weight(1f), accent, e) }
            repeat(columns - row.size) { Spacer(Modifier.weight(1f)) }
        }
    }
}

/** A white card with a title. */
@Composable
internal fun SectionCard(title: String?, modifier: Modifier = Modifier, trailing: @Composable (() -> Unit)? = null, content: @Composable ColumnScope.() -> Unit) {
    Column(modifier.fillMaxWidth().padding(top = 12.dp).clip(RoundedCornerShape(22.dp)).background(Wa.Surface).padding(14.dp)) {
        if (title != null) {
            Row(Modifier.fillMaxWidth().padding(bottom = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(title, color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                trailing?.invoke()
            }
        }
        content()
    }
}

/** A label and its value on one line. */
@Composable
internal fun InfoLine(emoji: String, label: String, value: String?) {
    if (value.isNullOrBlank()) return
    Row(Modifier.fillMaxWidth().padding(vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(emoji, fontSize = 15.sp, modifier = Modifier.width(28.dp))
        Text(label, color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.weight(1f))
        Text(value, color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.End, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1.3f))
    }
}

/** A chip row to pick one of a few (a season, a competition, a kind of leader). */
@Composable
internal fun ChoiceChips(options: List<Pair<String, String>>, selected: String?, onPick: (String) -> Unit) {
    LazyRow(horizontalArrangement = Arrangement.spacedBy(6.dp), modifier = Modifier.fillMaxWidth()) {
        itemsIndexed(options) { _, (key, label) ->
            val on = key == selected
            Text(
                label, color = if (on) Wa.Red else Wa.Mut, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1,
                modifier = Modifier.clip(CircleShape).border(1.5.dp, if (on) Wa.Red else Wa.Line, CircleShape).background(if (on) Wa.Red.copy(alpha = 0.08f) else Color.Transparent)
                    .clickable { onPick(key) }.padding(horizontal = 13.dp, vertical = 7.dp),
            )
        }
    }
}
