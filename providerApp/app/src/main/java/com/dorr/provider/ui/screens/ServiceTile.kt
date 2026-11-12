package com.dorr.provider.ui.screens

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Category
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material3.Icon
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
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.provider.net.ApiClient
import com.dorr.provider.ui.kit.AccountDark
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsCard
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsNight
import kotlinx.coroutines.delay

/**
 * One service as a tile — the same tile in the registration (to pick the services) and on the home (the services the
 * establishment works in): the admin's image on a soft disc, the name in full under it (two lines at most), and, once picked,
 * the app colour around it with a tick that pops in. [onClick] null = a tile to look at (the home); [dimmed] = a service
 * switched off for this establishment.
 */
@Composable
internal fun ServiceTile(
    name: String?,
    image: String?,
    selected: Boolean,
    modifier: Modifier = Modifier,
    dimmed: Boolean = false,
    onClick: (() -> Unit)? = null,
) {
    val night = settingsNight()
    val accent = settingsAccent()
    val shape = RoundedCornerShape(20.dp)
    val fill by animateColorAsState(if (selected) accent.copy(alpha = if (night) 0.22f else 0.08f) else settingsCard(), tween(220), label = "tileFill")
    val edge by animateColorAsState(if (selected) accent else if (night) AccountDark.line else Color(0xFFE9EBEF), tween(220), label = "tileEdge")
    val lift by animateFloatAsState(if (selected) 1.03f else 1f, spring(dampingRatio = 0.55f), label = "tileLift")
    Box(modifier.graphicsLayer { scaleX = lift; scaleY = lift; alpha = if (dimmed) 0.5f else 1f }) {
        Column(
            Modifier
                .fillMaxWidth()
                .clip(shape)
                .background(fill)
                .border(if (selected) 2.dp else 1.dp, edge, shape)
                .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier)
                .padding(horizontal = 8.dp, vertical = 14.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            // The image on a soft disc in the app colour, so a light picture still stands out on the white card.
            Box(
                Modifier.size(68.dp).clip(CircleShape)
                    .background(Brush.linearGradient(listOf(accent.copy(alpha = if (night) 0.20f else 0.10f), accent.copy(alpha = if (night) 0.08f else 0.03f)))),
                contentAlignment = Alignment.Center,
            ) {
                val url = ApiClient.mediaUrl(image)
                if (url != null) {
                    AsyncImage(model = url, contentDescription = null, contentScale = ContentScale.Fit, modifier = Modifier.size(50.dp))
                } else {
                    Icon(Icons.Rounded.Category, contentDescription = null, tint = accent, modifier = Modifier.size(28.dp))
                }
            }
            // The name always shows in full width under the image; two lines kept for every tile so the rows line up.
            Text(
                name.orEmpty(), color = if (selected) accent else settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold,
                lineHeight = 17.sp, textAlign = TextAlign.Center, minLines = 2, maxLines = 2, overflow = TextOverflow.Ellipsis,
                modifier = Modifier.fillMaxWidth(),
            )
        }
        AnimatedVisibility(
            visible = selected,
            enter = scaleIn(spring(dampingRatio = 0.5f)) + fadeIn(),
            exit = scaleOut(tween(150)) + fadeOut(tween(150)),
            modifier = Modifier.align(Alignment.TopEnd).padding(7.dp),
        ) {
            Box(Modifier.size(22.dp).clip(CircleShape).background(accent), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Check, contentDescription = null, tint = Color.White, modifier = Modifier.size(15.dp))
            }
        }
    }
}

/**
 * Tiles three to a row, in the order given (the admin's order), each sliding up into place a moment after the one before it.
 * [tile] draws one item with the modifier it must take.
 */
@Composable
internal fun <T> ServiceTileGrid(items: List<T>, tile: @Composable (item: T, modifier: Modifier) -> Unit) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        items.chunked(3).forEachIndexed { rowIndex, row ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                row.forEachIndexed { index, item ->
                    val order = rowIndex * 3 + index
                    val appear = remember { Animatable(0f) }
                    LaunchedEffect(Unit) {
                        delay(40L * order.coerceAtMost(12))
                        appear.animateTo(1f, tween(320))
                    }
                    tile(item, Modifier.weight(1f).graphicsLayer { alpha = appear.value; translationY = (1f - appear.value) * 40f })
                }
                repeat(3 - row.size) { Spacer(Modifier.weight(1f)) }
            }
        }
    }
}
