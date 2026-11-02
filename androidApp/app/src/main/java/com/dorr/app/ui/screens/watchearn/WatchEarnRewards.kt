package com.dorr.app.ui.screens.watchearn

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.EmojiEvents
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.ThumbUp
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.WeCurrencyDto
import com.dorr.app.network.WeRewardItemDto
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaDivider
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.waRise
import com.dorr.app.ui.screens.wallet.walletAuth
import kotlinx.coroutines.launch

/**
 * «مكافآتي»: what is waiting and what was paid (two cards that count up), then every reward — for a view or for an
 * interaction — with its ad, amount and «available on …» / «added». Rewards are spent anywhere in the app; where the
 * country allows it they can be sent to others too.
 */
@Composable
fun WatchEarnRewards() {
    val host = LocalWe.current
    val scope = rememberCoroutineScope()
    val generic = stringResource(R.string.we_error_generic)
    val items = remember { mutableStateListOf<WeRewardItemDto>() }
    var waiting by remember { mutableStateOf<Long?>(null) }
    var paid by remember { mutableStateOf<Long?>(null) }
    var currency by remember { mutableStateOf<WeCurrencyDto?>(null) }
    var nextCursor by remember { mutableStateOf<String?>(null) }
    var loaded by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    var transferable by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        runCatching { ApiClient.watchEarn.rewards(weAuth()).data }
            .onSuccess { page ->
                if (page != null) {
                    waiting = page.waitingMinor
                    paid = page.paidMinor
                    currency = page.currency
                    items.clear()
                    items.addAll(page.items)
                    nextCursor = page.nextCursor
                }
            }
            .onFailure { host.showToast(it.weMessage(generic), error = true) }
        loaded = true
        transferable = runCatching { ApiClient.wallet.balance(walletAuth()).data?.rewardsTransferable }.getOrNull() == true
    }

    WaPage(title = stringResource(R.string.we_my_rewards), onBack = { host.pop() }) {
        Row(Modifier.fillMaxWidth().waRise(0), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            SumCard(Icons.Rounded.HourglassTop, stringResource(R.string.we_rewards_waiting), waiting, currency, We.Gold, Modifier.weight(1f))
            SumCard(Icons.Rounded.CheckCircle, stringResource(R.string.we_rewards_paid), paid, currency, We.Withdrawable, Modifier.weight(1f))
        }
        Spacer(Modifier.height(12.dp))
        WaNote(
            stringResource(if (transferable) R.string.we_rewards_note_transferable else R.string.we_rewards_note),
            Modifier.waRise(1),
            icon = Icons.Rounded.Info,
        )

        WaSectionTitle(stringResource(R.string.we_rewards_list), Modifier.waRise(2), topPadding = 16.dp)
        WaCard(Modifier.fillMaxWidth().waRise(3)) {
            when {
                !loaded -> Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(40.dp)) }
                }
                items.isEmpty() -> WaEmpty(
                    Icons.Rounded.EmojiEvents, Tone.Amber, stringResource(R.string.we_no_rewards), stringResource(R.string.we_no_rewards_text),
                    action = { WaButton(stringResource(R.string.we_start_watching), { host.pop() }, modifier = Modifier.padding(horizontal = 40.dp)) },
                )
                else -> Column(Modifier.padding(horizontal = 14.dp, vertical = 4.dp)) {
                    items.forEachIndexed { i, item ->
                        RewardRow(item, currency)
                        if (i < items.lastIndex) WaDivider()
                    }
                }
            }
        }
        val cursor = nextCursor
        if (cursor != null) {
            Spacer(Modifier.height(12.dp))
            WaButton(
                stringResource(R.string.we_load_more),
                onClick = {
                    loadingMore = true
                    scope.launch {
                        runCatching { ApiClient.watchEarn.rewards(weAuth(), cursor).data }.getOrNull()?.let { page ->
                            items.addAll(page.items.filter { n -> items.none { it.id == n.id } })
                            nextCursor = page.nextCursor
                        }
                        loadingMore = false
                    }
                },
                style = WaButtonStyle.Ghost,
                loading = loadingMore,
                compact = true,
            )
        }
    }
}

@Composable
private fun SumCard(icon: ImageVector, label: String, value: Long?, currency: WeCurrencyDto?, tint: Color, modifier: Modifier) {
    WaCard(modifier, padding = 14.dp) {
        Box(Modifier.size(34.dp).clip(RoundedCornerShape(11.dp)).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = tint, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.height(10.dp))
        Text(label, color = Wa.Mut, fontSize = 12.sp)
        if (value == null) WaSkeleton(Modifier.padding(top = 4.dp).width(90.dp).height(22.dp))
        else WeCountUp(value, { weMoney(it, currency) }, color = tint, fontSize = 18.sp)
    }
}

@Composable
private fun RewardRow(item: WeRewardItemDto, currency: WeCurrencyDto?) {
    val interaction = item.kind == "interaction"
    val released = item.releasedAt != null
    Row(Modifier.fillMaxWidth().padding(vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        val tint = if (interaction) We.Interaction else We.Reward
        Box(Modifier.size(40.dp).clip(RoundedCornerShape(13.dp)).background(tint.copy(alpha = 0.13f)), contentAlignment = Alignment.Center) {
            Icon(if (interaction) Icons.Rounded.ThumbUp else Icons.Rounded.Visibility, null, tint = tint, modifier = Modifier.size(19.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(
                stringResource(if (interaction) R.string.we_reward_interaction else R.string.we_reward_view),
                color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold,
            )
            item.ad?.title?.let { Text(it, color = Wa.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis) }
        }
        Column(horizontalAlignment = Alignment.End) {
            WeLtr { Text("+ " + weMoney(item.amountMinor, currency), color = if (released) We.Withdrawable else We.Gold, fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold) }
            Text(
                if (released) stringResource(R.string.we_reward_added) else stringResource(R.string.we_reward_available_on, weDate(item.availableAt)),
                color = Wa.Soft, fontSize = 11.sp, modifier = Modifier.padding(top = 2.dp),
            )
        }
    }
}
