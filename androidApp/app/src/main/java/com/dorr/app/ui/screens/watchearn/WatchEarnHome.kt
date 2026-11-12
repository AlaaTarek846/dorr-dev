package com.dorr.app.ui.screens.watchearn

import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.AddCircle
import androidx.compose.material.icons.rounded.Campaign
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.EmojiEvents
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.PlayCircleFilled
import androidx.compose.material.icons.rounded.Videocam
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.WeCurrencyDto
import com.dorr.app.network.WeFeedDto
import com.dorr.app.network.WeRewardsDto
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.rememberPressScale
import com.dorr.app.ui.screens.wallet.waRise
import com.dorr.app.ui.screens.wallet.walletAuth
import kotlinx.coroutines.async
import kotlinx.coroutines.coroutineScope

/**
 * The landing page: my rewards balance (the wallet's rewards + what is still waiting), the big «start watching»
 * button with how many ads wait and what each earns, three tiles and «create an ad». Loads once when it opens.
 */
@Composable
fun WatchEarnHome() {
    val host = LocalWe.current
    var feed by remember { mutableStateOf<WeFeedDto?>(null) }
    var rewards by remember { mutableStateOf<WeRewardsDto?>(null) }
    var walletReward by remember { mutableStateOf<Long?>(null) }
    var failed by remember { mutableStateOf(false) }
    var reload by remember { mutableStateOf(0) }

    LaunchedEffect(reload) {
        failed = false
        coroutineScope {
            val f = async { runCatching { ApiClient.watchEarn.feed(weAuth()).data }.getOrNull() }
            val r = async { runCatching { ApiClient.watchEarn.rewards(weAuth()).data }.getOrNull() }
            val w = async { runCatching { ApiClient.wallet.balance(walletAuth()).data?.rewardMinor }.getOrNull() }
            feed = f.await()
            rewards = r.await()
            walletReward = w.await()
        }
        if (feed == null) failed = true
        feed?.let { WatchEarnLink.knownEnabled = it.enabled }
    }

    WaPage(title = stringResource(R.string.we_title), onBack = { host.pop() }) {
        val currency = rewards?.currency ?: feed?.ads?.firstOrNull()?.currency
        RewardsHero(
            walletReward = walletReward,
            waiting = rewards?.waitingMinor,
            currency = currency,
            modifier = Modifier.waRise(0),
            onClick = { host.push(WeRoute.Rewards) },
        )
        Spacer(Modifier.height(14.dp))

        val f = feed
        when {
            f == null && !failed -> WaSkeleton(Modifier.fillMaxWidth().height(132.dp), RoundedCornerShape(26.dp))
            f == null -> WaEmpty(
                Icons.Rounded.PlayCircleFilled, Tone.Red, stringResource(R.string.we_load_failed), stringResource(R.string.we_load_failed_hint),
                action = { WaButton(stringResource(R.string.we_retry), { reload++ }, modifier = Modifier.padding(horizontal = 40.dp)) },
            )
            !f.enabled -> WaEmpty(Icons.Rounded.Videocam, Tone.Gray, stringResource(R.string.we_disabled_title), stringResource(R.string.we_disabled_text))
            f.ads.isEmpty() -> WaEmpty(Icons.Rounded.HourglassTop, Tone.Amber, stringResource(R.string.we_no_ads_title), stringResource(R.string.we_no_ads_text))
            else -> StartWatchingButton(f, Modifier.waRise(1)) { host.push(WeRoute.Player(f)) }
        }

        Spacer(Modifier.height(14.dp))
        Row(Modifier.fillMaxWidth().waRise(2), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            WeTile(Icons.Rounded.EmojiEvents, stringResource(R.string.we_my_rewards), null, We.Reward, Modifier.weight(1f)) { host.push(WeRoute.Rewards) }
            WeTile(Icons.Rounded.Campaign, stringResource(R.string.we_my_ads), null, We.SpendOnly, Modifier.weight(1f)) { host.push(WeRoute.MyAds()) }
            WeTile(Icons.Rounded.AccountBalanceWallet, stringResource(R.string.we_ad_wallet), null, We.Withdrawable, Modifier.weight(1f)) { host.push(WeRoute.AdWallet()) }
        }

        Spacer(Modifier.height(16.dp))
        CreateAdCard(Modifier.waRise(3)) { host.push(WeRoute.Wizard(AdForm())) }
    }
}

/** The rewards balance on the brand gradient, counting up; what is still waiting under it. */
@Composable
private fun RewardsHero(walletReward: Long?, waiting: Long?, currency: WeCurrencyDto?, modifier: Modifier, onClick: () -> Unit) {
    val shape = RoundedCornerShape(24.dp)
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.98f)
    Box(
        modifier
            .fillMaxWidth()
            .scale(press)
            .shadow(14.dp, shape, ambientColor = Wa.Red.copy(alpha = 0.22f), spotColor = Wa.Red.copy(alpha = 0.32f))
            .clip(shape)
            .background(Wa.HeroBrush)
            .clickable(interactionSource = source, indication = null, onClick = onClick),
    ) {
        Box(Modifier.size(150.dp).offset(x = (-36).dp, y = 60.dp).align(Alignment.BottomStart).background(Color(0x12FFFFFF), CircleShape))
        Column(Modifier.padding(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(34.dp).clip(CircleShape).background(We.RewardBrush), contentAlignment = Alignment.Center) {
                    Icon(Icons.Rounded.EmojiEvents, null, tint = Color.White, modifier = Modifier.size(19.dp))
                }
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.we_title), color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.we_tagline), color = Color.White.copy(alpha = 0.78f), fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
            }
            Spacer(Modifier.height(14.dp))
            Text(stringResource(R.string.we_my_rewards_balance), color = Color.White.copy(alpha = 0.8f), fontSize = 12.sp)
            if (walletReward == null && waiting == null) {
                WaSkeleton(Modifier.padding(top = 6.dp).width(150.dp).height(30.dp))
            } else {
                WeCountUp(
                    (walletReward ?: 0L) + (waiting ?: 0L),
                    { weMoney(it, currency) },
                    color = Color.White, fontSize = 28.sp, modifier = Modifier.padding(top = 2.dp),
                )
            }
            if ((waiting ?: 0L) > 0L) {
                Row(
                    Modifier.padding(top = 8.dp).clip(RoundedCornerShape(99.dp)).background(Color.White.copy(alpha = 0.14f)).padding(horizontal = 10.dp, vertical = 4.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.HourglassTop, null, tint = Color.White, modifier = Modifier.size(13.dp))
                    Spacer(Modifier.width(5.dp))
                    Text(stringResource(R.string.we_waiting_of, weMoney(waiting ?: 0L, currency)), color = Color.White, fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

/** The big «start watching» button: a pulsing play mark, how many ads wait, and what each one can earn. */
@Composable
private fun StartWatchingButton(feed: WeFeedDto, modifier: Modifier, onClick: () -> Unit) {
    val pulse = rememberInfiniteTransition(label = "pulse")
    val ring by pulse.animateFloat(1f, 1.35f, infiniteRepeatable(tween(1300, easing = FastOutSlowInEasing), RepeatMode.Restart), label = "ring")
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.97f)
    val shape = RoundedCornerShape(26.dp)
    val best = feed.ads.maxByOrNull { it.earn.viewMinor + it.earn.interactionMinor }
    val count = feed.ads.size
    Row(
        modifier
            .fillMaxWidth()
            .scale(press)
            .shadow(16.dp, shape, ambientColor = Wa.Red.copy(alpha = 0.3f), spotColor = Wa.Red.copy(alpha = 0.45f))
            .clip(shape)
            .background(Wa.ButtonBrush)
            .clickable(interactionSource = source, indication = null, onClick = onClick)
            .padding(horizontal = 18.dp, vertical = 20.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(64.dp), contentAlignment = Alignment.Center) {
            Box(
                Modifier.size(56.dp).graphicsLayer { scaleX = ring; scaleY = ring; alpha = (1.35f - ring) / 0.35f }
                    .clip(CircleShape).background(Color.White.copy(alpha = 0.35f)),
            )
            Box(Modifier.size(56.dp).clip(CircleShape).background(Color.White), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.PlayArrow, null, tint = Wa.Red, modifier = Modifier.size(34.dp))
            }
        }
        Spacer(Modifier.width(16.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.we_start_watching), color = Color.White, fontSize = 19.sp, fontWeight = FontWeight.Black)
            Text(stringResource(R.string.we_ads_waiting, count), color = Color.White.copy(alpha = 0.88f), fontSize = 12.5.sp)
            if (best != null && best.earn.viewMinor > 0) {
                Row(
                    Modifier.padding(top = 8.dp).clip(RoundedCornerShape(99.dp)).background(We.RewardBrush).padding(horizontal = 10.dp, vertical = 4.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.EmojiEvents, null, tint = Color.White, modifier = Modifier.size(13.dp))
                    Spacer(Modifier.width(5.dp))
                    WeLtr {
                        Text(
                            stringResource(R.string.we_earn_per_ad, weMoney(best.earn.viewMinor, best.currency)),
                            color = Color.White, fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold,
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun CreateAdCard(modifier: Modifier, onClick: () -> Unit) {
    val shape = RoundedCornerShape(22.dp)
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.97f)
    Column(
        modifier
            .fillMaxWidth()
            .scale(press)
            .clip(shape)
            .background(Brush.linearGradient(listOf(Wa.Red.copy(alpha = 0.12f), Wa.Red.copy(alpha = 0.03f))))
            .border(1.dp, Wa.Red.copy(alpha = 0.25f), shape)
            .clickable(interactionSource = source, indication = null, onClick = onClick)
            .padding(16.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Campaign, null, tint = Wa.Red, modifier = Modifier.size(26.dp))
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.we_create_ad), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                Text(stringResource(R.string.we_create_ad_hint), color = Wa.Mut, fontSize = 12.sp)
            }
        }
        Spacer(Modifier.height(12.dp))
        WaButton(stringResource(R.string.we_create_ad), onClick, icon = Icons.Rounded.AddCircle, style = WaButtonStyle.Ghost, compact = true)
    }
}

// ------------------------------------------------------------------------------- entry points (D17)

/** Home: a banner card that opens the section. Hidden once the feed said the feature is off here. */
@Composable
fun WatchEarnBanner(modifier: Modifier = Modifier) {
    if (WatchEarnLink.knownEnabled == false) return
    val shape = RoundedCornerShape(22.dp)
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.98f)
    Row(
        modifier
            .fillMaxWidth()
            .scale(press)
            .shadow(10.dp, shape, ambientColor = Wa.Red.copy(alpha = 0.18f), spotColor = Wa.Red.copy(alpha = 0.26f))
            .clip(shape)
            .background(Wa.HeroBrush)
            .clickable(interactionSource = source, indication = null) { WatchEarnLink.show() }
            .padding(horizontal = 16.dp, vertical = 16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(46.dp).clip(CircleShape).background(We.RewardBrush), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.PlayArrow, null, tint = Color.White, modifier = Modifier.size(28.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.we_title), color = Color.White, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.we_banner_text), color = Color.White.copy(alpha = 0.88f), fontSize = 12.5.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
        }
        Spacer(Modifier.width(8.dp))
        Text(
            stringResource(R.string.we_banner_cta), color = Wa.Red, fontSize = 12.sp, fontWeight = FontWeight.ExtraBold,
            modifier = Modifier.clip(RoundedCornerShape(99.dp)).background(Color.White).padding(horizontal = 12.dp, vertical = 6.dp),
        )
    }
}

/** Chat list: a slim card at the top, which can be hidden (it comes back from the chat settings). */
@Composable
fun WatchEarnChatCard(modifier: Modifier = Modifier) {
    val context = LocalContext.current
    remember { WatchEarnPrefs.load(context) }
    if (WatchEarnPrefs.chatCardHidden || WatchEarnLink.knownEnabled == false) return
    val shape = RoundedCornerShape(16.dp)
    Row(
        modifier
            .fillMaxWidth()
            .clip(shape)
            .background(Brush.linearGradient(listOf(We.Gold.copy(alpha = 0.18f), Wa.Red.copy(alpha = 0.08f))))
            .border(1.dp, We.Gold.copy(alpha = 0.35f), shape)
            .clickable { WatchEarnLink.show() }
            .padding(start = 12.dp, end = 4.dp, top = 8.dp, bottom = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(30.dp).clip(CircleShape).background(We.RewardBrush), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.PlayArrow, null, tint = Color.White, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.we_title), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.we_chat_card_text), color = Wa.Mut, fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Box(
            Modifier.size(34.dp).clip(CircleShape).clickable { WatchEarnPrefs.setChatCardHidden(context, true) },
            contentAlignment = Alignment.Center,
        ) { Icon(Icons.Rounded.Close, stringResource(R.string.we_hide), tint = Wa.Mut, modifier = Modifier.size(17.dp)) }
    }
}

/** Wallet home: a card under the balance with my rewards balance, opening the section. */
@Composable
fun WatchEarnWalletCard(rewardMinor: Long, currencyCode: String?, modifier: Modifier = Modifier) {
    if (WatchEarnLink.knownEnabled == false && rewardMinor == 0L) return
    val shape = RoundedCornerShape(20.dp)
    val source = remember { MutableInteractionSource() }
    val press by rememberPressScale(source, 0.97f)
    Row(
        modifier
            .fillMaxWidth()
            .scale(press)
            .clip(shape)
            .background(Wa.Surface)
            .border(1.dp, We.Reward.copy(alpha = 0.35f), shape)
            .clickable(interactionSource = source, indication = null) { WatchEarnLink.show() }
            .padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(42.dp).clip(RoundedCornerShape(14.dp)).background(We.RewardBrush), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.PlayArrow, null, tint = Color.White, modifier = Modifier.size(24.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.we_title), color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.we_wallet_card_text), color = Wa.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Column(horizontalAlignment = Alignment.End) {
            Text(stringResource(R.string.we_bucket_reward), color = Wa.Mut, fontSize = 10.5.sp)
            WeLtr {
                Text(
                    weMoney(rewardMinor, WeCurrencyDto(currencyCode, 2)),
                    color = We.Reward, fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold,
                )
            }
        }
    }
}
