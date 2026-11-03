package com.dorr.app.ui.screens.watchearn

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddCircle
import androidx.compose.material.icons.rounded.Campaign
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.ApiClient
import com.dorr.app.network.WeAdDto
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.rememberPressScale
import com.dorr.app.ui.screens.wallet.waRise
import kotlinx.coroutines.launch

/** The status filters, in the order an advertiser cares about them; null = all. */
private val StatusFilters: List<String?> = listOf(null, "active", "pending_review", "paused", "exhausted", "rejected", "suspended", "ended", "stopped")

/**
 * «إعلاناتي»: filter chips by status; each ad as a card with its thumbnail, title, status, how much of the budget is
 * spent, completed views and the dates. A rejected ad shows why and «edit and resend». Reloads once when the server
 * says one of my ads changed (`watch_earn.ad.changed`) — never on a timer.
 */
@Composable
fun MyAds(initialStatus: String?) {
    val host = LocalWe.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.we_error_generic)
    var status by remember { mutableStateOf(initialStatus) }
    val ads = remember { mutableStateListOf<WeAdDto>() }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loaded by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    var reload by remember { mutableIntStateOf(0) }

    LaunchedEffect(status, reload) {
        runCatching { ApiClient.watchEarn.ads(weAuth(), status, 1) }
            .onSuccess { envelope ->
                ads.clear()
                ads.addAll(envelope.data.orEmpty())
                page = 1
                hasMore = envelope.pagination?.hasMorePages == true
            }
            .onFailure { if (!loaded) host.showToast(it.weMessage(generic), error = true) }
        loaded = true
    }

    // One reload per change the server announces.
    LaunchedEffect(Unit) {
        ChatRealtime.events.collect { event -> if (event.name == "watch_earn.ad.changed") reload++ }
    }

    WaPage(title = stringResource(R.string.we_my_ads), onBack = { host.pop() }) {
        Row(
            Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(bottom = 12.dp).waRise(0),
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            StatusFilters.forEach { s ->
                WeChip(
                    if (s == null) stringResource(R.string.we_all) else stringResource(weStatusLabel(s)),
                    status == s,
                    { if (status != s) { status = s; loaded = false } },
                )
            }
        }

        when {
            !loaded -> Column(verticalArrangement = Arrangement.spacedBy(12.dp)) { repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(128.dp), RoundedCornerShape(22.dp)) } }
            ads.isEmpty() -> WaEmpty(
                Icons.Rounded.Campaign, Tone.Blue, stringResource(R.string.we_no_ads_mine), stringResource(R.string.we_no_ads_mine_text),
                action = { WaButton(stringResource(R.string.we_create_ad), { host.push(WeRoute.Wizard(AdForm())) }, icon = Icons.Rounded.AddCircle, modifier = Modifier.padding(horizontal = 30.dp)) },
            )
            else -> Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                ads.forEachIndexed { i, ad ->
                    androidx.compose.runtime.key(ad.id) {
                        AdCard(
                            ad,
                            Modifier.waRise(i.coerceAtMost(6)),
                            onOpen = { host.push(WeRoute.Stats(ad.id)) },
                            onEdit = { host.push(WeRoute.Wizard(AdForm(edit = ad))) },
                        )
                    }
                }
                if (hasMore) {
                    WaButton(
                        stringResource(R.string.we_load_more),
                        onClick = {
                            loadingMore = true
                            scope.launch {
                                runCatching { ApiClient.watchEarn.ads(weAuth(), status, page + 1) }.getOrNull()?.let { envelope ->
                                    envelope.data.orEmpty().forEach { n -> if (ads.none { it.id == n.id }) ads.add(n) }
                                    page += 1
                                    hasMore = envelope.pagination?.hasMorePages == true
                                }
                                loadingMore = false
                            }
                        },
                        style = WaButtonStyle.Ghost,
                        loading = loadingMore,
                        compact = true,
                    )
                }
                Spacer(Modifier.height(4.dp))
                WaButton(stringResource(R.string.we_create_ad), { host.push(WeRoute.Wizard(AdForm())) }, icon = Icons.Rounded.AddCircle, compact = true)
            }
        }
    }
}

@Composable
private fun AdCard(ad: WeAdDto, modifier: Modifier, onOpen: () -> Unit, onEdit: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.98f)
    val budget = ad.budget
    val spent = if (budget.totalMinor > 0) budget.spentMinor.toFloat() / budget.totalMinor else 0f
    WaCard(modifier.fillMaxWidth().scale(press).clickable(interactionSource = source, indication = null, onClick = onOpen), padding = 14.dp) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            AdThumb(ad, 62)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(ad.title, color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Spacer(Modifier.height(5.dp))
                WeStatusBadge(ad.status, ad.scheduled)
            }
        }
        Spacer(Modifier.height(12.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.we_spent_of), color = Wa.Mut, fontSize = 11.5.sp, modifier = Modifier.weight(1f))
            WeLtr {
                Text(
                    weMoney(budget.spentMinor, ad.currency) + " / " + weAmount(budget.totalMinor, ad.currency.decimals),
                    color = Wa.Ink, fontSize = 11.5.sp, fontWeight = FontWeight.Bold,
                )
            }
        }
        Spacer(Modifier.height(6.dp))
        WeProgressBar(spent, color = weStatusColor(ad.status, ad.scheduled))
        Spacer(Modifier.height(10.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Visibility, null, tint = Wa.Mut, modifier = Modifier.size(15.dp))
            Spacer(Modifier.width(5.dp))
            Text(stringResource(R.string.we_completed_views, ad.counts.completed), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.weight(1f))
            Text(weDate(ad.startsAt) + " – " + weDate(ad.endsAt), color = Wa.Soft, fontSize = 11.sp, maxLines = 1)
        }
        AnimatedVisibility(ad.status == "rejected", enter = expandVertically() + fadeIn(), exit = shrinkVertically() + fadeOut()) {
            Column(Modifier.padding(top = 12.dp)) {
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Wa.Danger.copy(alpha = 0.08f)).padding(12.dp),
                    verticalAlignment = Alignment.Top,
                ) {
                    Icon(Icons.Rounded.ErrorOutline, null, tint = Wa.Danger, modifier = Modifier.size(17.dp))
                    Spacer(Modifier.width(8.dp))
                    Column {
                        Text(stringResource(R.string.we_reject_reason), color = Wa.Danger, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold)
                        Text(ad.rejectReason.orEmpty().ifBlank { "—" }, color = Wa.Ink, fontSize = 12.5.sp, lineHeight = 19.sp)
                    }
                }
                Spacer(Modifier.height(10.dp))
                WaButton(stringResource(R.string.we_edit_resend), onEdit, icon = Icons.Rounded.Edit, style = WaButtonStyle.Ghost, compact = true)
            }
        }
    }
}

/** The ad's picture, or a dark tile with ▶ for a video (no frame decoder in the app). */
@Composable
internal fun AdThumb(ad: WeAdDto, sizeDp: Int) {
    val shape = RoundedCornerShape((sizeDp * 0.28f).dp)
    Box(Modifier.size(sizeDp.dp).clip(shape).background(Color(0xFF111827)), contentAlignment = Alignment.Center) {
        if (ad.media.type == "image" && ad.media.url != null) {
            AsyncImage(ApiClient.mediaUrl(ad.media.url), null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
        } else {
            Icon(Icons.Rounded.PlayArrow, null, tint = Color.White, modifier = Modifier.size((sizeDp * 0.45f).dp))
        }
    }
}
