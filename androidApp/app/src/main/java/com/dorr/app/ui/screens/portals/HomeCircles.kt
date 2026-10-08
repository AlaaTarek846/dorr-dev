package com.dorr.app.ui.screens.portals

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.spring
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.screens.chat.StoryRing
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.rememberPressScale

/**
 * The circles row on the home page, drawn like the chat's stories bar (docs/remaining_chat.md ج.1):
 * me, the merchant portals circle (not a story — it opens the portals page), Dorr's own stories,
 * then everyone's public stories. The stories open in the chat's own viewer (HomeStoriesLayer).
 */
@Composable
fun HomeCircles(stories: com.dorr.app.ui.screens.chat.HomeStoriesState, onOpenPortals: () -> Unit, modifier: Modifier = Modifier) {
    val reloadTick = com.dorr.app.network.collectReconnectTick()
    LaunchedEffect(reloadTick) { stories.refresh() }
    val feed = stories.feed
    val listState = androidx.compose.foundation.lazy.rememberLazyListState()

    // Ten people at a time: the next ten load as the row nears its end.
    LaunchedEffect(listState, feed?.people?.size, feed?.hasMore) {
        androidx.compose.runtime.snapshotFlow { listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index to listState.layoutInfo.totalItemsCount }
            .collect { (last, total) ->
                if (last != null && total > 0 && last >= total - 3) stories.loadMore()
            }
    }

    androidx.compose.foundation.layout.Column(modifier) {
        LazyRow(
            state = listState,
            contentPadding = PaddingValues(horizontal = 20.dp),
            horizontalArrangement = Arrangement.spacedBy(2.dp),
        ) {
        // 1. Me (my public story, or "+").  2. Merchant portals.  3. Dorr.  Then everyone — the
        // ones I haven't watched first, the most watched first; the ones I've seen at the back.
        if (feed?.enabled != false) item(key = "me") { com.dorr.app.ui.screens.chat.HomeMyCircle(stories) }
        item(key = "portals") { PortalsCircle(onOpenPortals) }
        feed?.dorr?.let { dorr -> item(key = "dorr") { com.dorr.app.ui.screens.chat.HomeDorrCircle(stories, dorr) } }
            feed?.people?.forEach { group ->
                item(key = "p-" + (group.owner?.key ?: group.lastAt.orEmpty())) { com.dorr.app.ui.screens.chat.HomePersonCircle(stories, group) }
            }
            if (stories.loadingMore) item(key = "more") {
                Box(Modifier.width(58.dp).height(56.dp), contentAlignment = Alignment.Center) {
                    androidx.compose.material3.CircularProgressIndicator(color = Wa.Red, strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
                }
            }
        }
    }
}

@Composable
private fun PortalsCircle(onClick: () -> Unit) {
    val pop = remember { Animatable(0.6f) }
    LaunchedEffect(Unit) { pop.animateTo(1f, spring(dampingRatio = 0.45f, stiffness = 300f)) }
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.92f)
    Column(
        Modifier.width(58.dp).scale(pop.value * press).clickable(interactionSource = source, indication = null, onClick = onClick),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        // Always "new": the ring keeps turning, like an unseen story.
        StoryRing(count = 1, seenCount = 0, size = 56.dp) {
            Box(Modifier.size(48.dp).clip(CircleShape).background(Wa.HeroBrush), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Storefront, null, tint = Color.White, modifier = Modifier.size(24.dp))
            }
        }
        Spacer(Modifier.height(4.dp))
        Text(
            stringResource(R.string.pt_circle), color = Wa.Ink, fontSize = 11.sp, fontWeight = FontWeight.Bold,
            maxLines = 1, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center,
        )
    }
}
