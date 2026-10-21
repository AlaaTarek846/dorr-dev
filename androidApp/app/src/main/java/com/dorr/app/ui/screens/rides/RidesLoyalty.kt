package com.dorr.app.ui.screens.rides

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.Bolt
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.DarkMode
import androidx.compose.material.icons.rounded.DirectionsBoat
import androidx.compose.material.icons.rounded.DirectionsBus
import androidx.compose.material.icons.rounded.DirectionsCar
import androidx.compose.material.icons.rounded.Eco
import androidx.compose.material.icons.rounded.EmojiEvents
import androidx.compose.material.icons.rounded.Favorite
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Map
import androidx.compose.material.icons.rounded.RocketLaunch
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.WorkspacePremium
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.LoyaltyBadgeDto
import com.dorr.app.network.LoyaltyChallengeDto
import com.dorr.app.network.LoyaltyDashboardDto
import com.dorr.app.network.LoyaltyEntryDto
import com.dorr.app.network.LoyaltyRedemptionDto
import com.dorr.app.network.LoyaltyRewardDto
import com.dorr.app.network.LoyaltyTierDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.Duration
import java.time.OffsetDateTime
import java.util.UUID

private val Gold = Color(0xFFE0A526)
private val GoldDeep = Color(0xFFB7791F)

private enum class LoyaltyTab(val label: Int) {
    Overview(R.string.loy_tab_overview), Rewards(R.string.loy_tab_rewards), Challenges(R.string.loy_tab_challenges), Badges(R.string.loy_tab_badges), History(R.string.loy_tab_history)
}

/**
 * DORR points (docs/rides_tasks.md R-146..R-155): the balance and tier with the way to the next one, the
 * tiers side by side, rewards to spend the points on, challenges, badges and the history. One catch-up
 * on open; each `loyalty.*` notification reloads it (no timer).
 */
@Composable
internal fun LoyaltyScreen(onClose: () -> Unit) {
    BackHandler(onBack = onClose)
    var data by remember { mutableStateOf<LoyaltyDashboardDto?>(null) }
    var rewards by remember { mutableStateOf<List<LoyaltyRewardDto>>(emptyList()) }
    var tab by remember { mutableStateOf(LoyaltyTab.Overview) }
    var error by remember { mutableStateOf<String?>(null) }
    suspend fun reload() {
        runCatching { ApiClient.loyalty.dashboard(ridesAuth()).data }.onSuccess { data = it }.onFailure { error = it.apiFailure().message }
        runCatching { ApiClient.loyalty.rewards(ridesAuth()).data.orEmpty() }.getOrNull()?.let { rewards = it }
    }
    LaunchedEffect(Unit) {
        reload()
        com.dorr.app.chat.ChatRealtime.events.collect { e -> if (e.name.startsWith("loyalty.")) reload() }
    }
    val scope = rememberCoroutineScope()

    Column(Modifier.fillMaxSize().background(settingsBackground())) {
        val d = data
        LoyaltyHero(d, onClose)
        if (d == null) {
            Box(Modifier.fillMaxWidth().padding(40.dp), contentAlignment = Alignment.Center) {
                if (error != null) Text(error.orEmpty(), color = AppColors.danger) else CircularProgressIndicator(color = settingsAccent())
            }
            return@Column
        }
        if (!d.enabled) {
            Text(stringResource(R.string.loy_off), color = settingsMut(), textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(32.dp))
            return@Column
        }
        // The tabs.
        Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()).padding(horizontal = 14.dp, vertical = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            LoyaltyTab.entries.forEach { t ->
                val on = t == tab
                Text(
                    stringResource(t.label), color = if (on) Color.White else settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp,
                    modifier = Modifier.clip(CircleShape).background(if (on) settingsAccent() else settingsCard()).clickable { tab = t }.padding(horizontal = 16.dp, vertical = 9.dp),
                )
            }
        }
        AnimatedContent(tab, transitionSpec = { fadeIn(tween(220)) togetherWith fadeOut(tween(150)) }, label = "loyalty-tab", modifier = Modifier.weight(1f)) { t ->
            Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 16.dp).padding(bottom = 24.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                when (t) {
                    LoyaltyTab.Overview -> Overview(d)
                    LoyaltyTab.Rewards -> Rewards(rewards, d.balance) { scope.launch { reload() } }
                    LoyaltyTab.Challenges -> if (d.challenges.isEmpty()) Empty(R.string.loy_no_challenges) else d.challenges.forEach { ChallengeCard(it) }
                    LoyaltyTab.Badges -> if (d.badges.isEmpty()) Empty(R.string.loy_no_badges) else BadgeGrid(d.badges)
                    LoyaltyTab.History -> History()
                }
                Spacer(Modifier.navigationBarsPadding())
            }
        }
    }
}

/** The balance (counting up), the tier and the bar to the next one, the committed rider's bonus. */
@Composable
private fun LoyaltyHero(d: LoyaltyDashboardDto?, onClose: () -> Unit) {
    val tierColor = d?.tier?.color?.let { parseColor(it) } ?: Gold
    val shine by rememberInfiniteTransition(label = "shine").animateFloat(0f, 1f, infiniteRepeatable(tween(3200)), label = "shine-x")
    Box(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(bottomStart = 34.dp, bottomEnd = 34.dp))
            .background(Brush.linearGradient(listOf(settingsAccent(), Color(0xFF2B4C9B), tierColor.copy(alpha = 0.95f)))),
    ) {
        // A soft light sweeping across.
        Canvas(Modifier.matchParentSize()) {
            val x = size.width * (shine * 1.6f - 0.3f)
            drawRect(Brush.linearGradient(listOf(Color.Transparent, Color.White.copy(alpha = 0.10f), Color.Transparent), start = Offset(x - 200f, 0f), end = Offset(x + 200f, size.height)))
        }
        Column(Modifier.statusBarsPadding().padding(horizontal = 18.dp).padding(top = 10.dp, bottom = 22.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(40.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.18f)).clickable(onClick = onClose), contentAlignment = Alignment.Center) {
                    Icon(Icons.AutoMirrored.Rounded.ArrowBack, null, tint = Color.White)
                }
                Spacer(Modifier.width(10.dp))
                Text(stringResource(R.string.loy_title), color = Color.White, fontWeight = FontWeight.Black, fontSize = 20.sp, modifier = Modifier.weight(1f))
                d?.tier?.let { tier ->
                    Row(Modifier.clip(CircleShape).background(Color.White.copy(alpha = 0.2f)).padding(horizontal = 12.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.WorkspacePremium, null, tint = tierColor, modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(4.dp))
                        Text(tier.name.orEmpty(), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                    }
                }
            }
            Spacer(Modifier.height(18.dp))
            val target = (d?.balance ?: 0L).toFloat()
            val counted = remember { Animatable(0f) }
            LaunchedEffect(target) { counted.animateTo(target, tween(1100, easing = FastOutSlowInEasing)) }
            Text(stringResource(R.string.loy_balance), color = Color.White.copy(alpha = 0.8f), fontSize = 13.sp)
            Row(verticalAlignment = Alignment.Bottom) {
                Text("%,d".format(counted.value.toLong()), color = Color.White, fontWeight = FontWeight.Black, fontSize = 40.sp)
                Spacer(Modifier.width(6.dp))
                Text(stringResource(R.string.loy_points), color = Color.White.copy(alpha = 0.85f), fontSize = 15.sp, modifier = Modifier.padding(bottom = 8.dp))
            }
            d?.takeIf { it.enabled }?.let { dd ->
                val next = dd.nextTier
                val bar by animateFloatAsState(dd.progress.toFloat(), tween(1200, easing = FastOutSlowInEasing), label = "tier-bar")
                Spacer(Modifier.height(10.dp))
                Box(Modifier.fillMaxWidth().height(10.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.22f))) {
                    Box(Modifier.fillMaxWidth(bar.coerceIn(0.02f, 1f)).height(10.dp).clip(CircleShape).background(Brush.horizontalGradient(listOf(Gold, Color.White))))
                }
                Spacer(Modifier.height(6.dp))
                Text(
                    if (next != null) stringResource(R.string.loy_to_next, next.pointsToGo ?: 0L, next.name.orEmpty()) else stringResource(R.string.loy_top_tier),
                    color = Color.White.copy(alpha = 0.9f), fontSize = 12.5.sp,
                )
                if (dd.committed.active && dd.committed.bonusBp > 0) {
                    Spacer(Modifier.height(10.dp))
                    Row(Modifier.clip(CircleShape).background(AppColors.success.copy(alpha = 0.9f)).padding(horizontal = 12.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.Verified, null, tint = Color.White, modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(6.dp))
                        Text(stringResource(R.string.loy_committed, dd.committed.bonusBp / 100), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 12.sp)
                    }
                }
            }
        }
    }
}

@Composable
private fun Overview(d: LoyaltyDashboardDto) {
    // How points come.
    Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsCard()).padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(42.dp).clip(CircleShape).background(Gold.copy(alpha = 0.16f)), contentAlignment = Alignment.Center) { Icon(Icons.Rounded.Star, null, tint = GoldDeep) }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.loy_how_title), color = settingsInk(), fontWeight = FontWeight.Bold)
            Text(stringResource(R.string.loy_how_body, d.pointsPerUnit), color = settingsMut(), fontSize = 12.5.sp)
        }
    }
    // R-154: the tiers side by side.
    if (d.tiers.isNotEmpty()) {
        Text(stringResource(R.string.loy_tiers), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 16.sp)
        Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            d.tiers.forEach { TierCard(it) }
        }
    }
    if (d.recent.isNotEmpty()) {
        Text(stringResource(R.string.loy_recent), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 16.sp)
        Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsCard())) { d.recent.forEach { EntryRow(it) } }
    }
}

@Composable
private fun TierCard(t: LoyaltyTierDto) {
    val color = t.color?.let { parseColor(it) } ?: Gold
    val glow by rememberInfiniteTransition(label = "tier").animateFloat(0.5f, 1f, infiniteRepeatable(tween(1400), RepeatMode.Reverse), label = "tier-glow")
    Column(
        Modifier.width(200.dp).clip(RoundedCornerShape(20.dp)).background(settingsCard())
            .border(if (t.current) 2.dp else 1.dp, if (t.current) color.copy(alpha = glow) else color.copy(alpha = 0.25f), RoundedCornerShape(20.dp)).padding(14.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.WorkspacePremium, null, tint = color, modifier = Modifier.size(26.dp))
            Spacer(Modifier.width(6.dp))
            Text(t.name.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 16.sp, modifier = Modifier.weight(1f))
            if (t.current) Text(stringResource(R.string.loy_you), color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(color).padding(horizontal = 8.dp, vertical = 2.dp))
        }
        Text(stringResource(R.string.loy_from_points, t.minPoints), color = settingsMut(), fontSize = 12.sp)
        Text("×" + "%.2f".format(t.multiplierBp / 10000.0).trimEnd('0').trimEnd('.'), color = color, fontWeight = FontWeight.Black, fontSize = 18.sp, modifier = Modifier.padding(vertical = 4.dp))
        t.benefits.forEach { b ->
            Row(Modifier.padding(top = 2.dp)) {
                Icon(Icons.Rounded.CheckCircle, null, tint = AppColors.success, modifier = Modifier.size(14.dp).padding(top = 2.dp))
                Spacer(Modifier.width(4.dp))
                Text(b, color = settingsInk(), fontSize = 12.sp)
            }
        }
    }
}

@Composable
private fun Rewards(list: List<LoyaltyRewardDto>, balance: Long, onDone: () -> Unit) {
    val scope = rememberCoroutineScope()
    var confirm by remember { mutableStateOf<LoyaltyRewardDto?>(null) }
    var result by remember { mutableStateOf<LoyaltyRedemptionDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    if (list.isEmpty()) Empty(R.string.loy_no_rewards)
    error?.let { Text(it, color = AppColors.danger, fontSize = 13.sp) }
    list.forEachIndexed { i, r -> RewardCard(r, i) { confirm = r } }

    confirm?.let { r ->
        AlertDialog(
            onDismissRequest = { if (!busy) confirm = null },
            title = { Text(r.title.orEmpty(), fontWeight = FontWeight.Bold) },
            text = { Text(stringResource(R.string.loy_confirm, r.costPoints, balance - r.costPoints)) },
            confirmButton = {
                TextButton(enabled = !busy, onClick = {
                    busy = true; error = null
                    scope.launch {
                        runCatching { ApiClient.loyalty.redeem(ridesAuth(), UUID.randomUUID().toString(), r.id).data }
                            .onSuccess { result = it; onDone() }.onFailure { error = it.apiFailure().message }
                        busy = false; confirm = null
                    }
                }) { Text(stringResource(R.string.loy_redeem)) }
            },
            dismissButton = { TextButton(onClick = { confirm = null }) { Text(stringResource(R.string.loy_back)) } },
        )
    }
    result?.let { res -> RedeemedDialog(res) { result = null } }
}

@Composable
private fun RewardCard(r: LoyaltyRewardDto, index: Int, onRedeem: () -> Unit) {
    val appear = remember { Animatable(0f) }
    LaunchedEffect(Unit) { delay(index * 60L); appear.animateTo(1f, tween(320)) }
    val coupon = r.kind == "coupon"
    Row(
        Modifier.fillMaxWidth().graphicsLayer { alpha = appear.value; translationY = (1f - appear.value) * 30f }
            .clip(RoundedCornerShape(20.dp)).background(settingsCard()).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(50.dp).clip(RoundedCornerShape(16.dp)).background(Brush.linearGradient(listOf(Gold, GoldDeep))), contentAlignment = Alignment.Center) {
            Icon(if (coupon) Icons.Rounded.LocalOffer else Icons.Rounded.AccountBalanceWallet, null, tint = Color.White)
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(r.title.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 15.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
            Text(
                when {
                    coupon && r.couponPercent != null -> stringResource(R.string.loy_coupon_percent, r.couponPercent)
                    coupon -> stringResource(R.string.loy_coupon_fixed, money(r.valueMinor, r.currency))
                    else -> stringResource(R.string.loy_credit, money(r.valueMinor, r.currency))
                },
                color = settingsMut(), fontSize = 12.sp,
            )
            r.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 11.5.sp, maxLines = 2) }
            Text(stringResource(R.string.loy_cost, r.costPoints), color = GoldDeep, fontWeight = FontWeight.Black, fontSize = 14.sp, modifier = Modifier.padding(top = 4.dp))
        }
        if (r.available) {
            Text(
                stringResource(R.string.loy_redeem), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                modifier = Modifier.clip(CircleShape).background(settingsAccent()).clickable(onClick = onRedeem).padding(horizontal = 14.dp, vertical = 9.dp),
            )
        } else {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Icon(Icons.Rounded.Lock, null, tint = settingsMut(), modifier = Modifier.size(18.dp))
                Text(
                    stringResource(
                        when (r.reason) { "out_of_stock" -> R.string.loy_why_stock; "limit" -> R.string.loy_why_limit; "tier" -> R.string.loy_why_tier; else -> R.string.loy_why_points },
                        r.minTier.orEmpty(),
                    ),
                    color = settingsMut(), fontSize = 10.5.sp, textAlign = TextAlign.Center, modifier = Modifier.width(80.dp),
                )
            }
        }
    }
}

@Composable
private fun RedeemedDialog(res: LoyaltyRedemptionDto, onClose: () -> Unit) {
    val context = LocalContext.current
    val pop = remember { Animatable(0.6f) }
    LaunchedEffect(Unit) { pop.animateTo(1f, androidx.compose.animation.core.spring(dampingRatio = 0.45f)) }
    AlertDialog(
        onDismissRequest = onClose,
        icon = { Icon(Icons.Rounded.EmojiEvents, null, tint = Gold, modifier = Modifier.size(48.dp).scale(pop.value)) },
        title = { Text(stringResource(R.string.loy_redeemed), fontWeight = FontWeight.Black, textAlign = TextAlign.Center) },
        text = {
            Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.fillMaxWidth()) {
                Text(res.title.orEmpty(), textAlign = TextAlign.Center)
                res.result?.couponCode?.let { code ->
                    Spacer(Modifier.height(10.dp))
                    Row(
                        Modifier.clip(RoundedCornerShape(12.dp)).border(1.5.dp, Gold, RoundedCornerShape(12.dp)).clickable {
                            (context.getSystemService(Context.CLIPBOARD_SERVICE) as? ClipboardManager)?.setPrimaryClip(ClipData.newPlainText("coupon", code))
                        }.padding(horizontal = 14.dp, vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(code, fontWeight = FontWeight.Black, fontSize = 18.sp, color = GoldDeep)
                        Spacer(Modifier.width(8.dp))
                        Icon(Icons.Rounded.ContentCopy, null, tint = GoldDeep, modifier = Modifier.size(18.dp))
                    }
                    Text(stringResource(R.string.loy_coupon_hint), fontSize = 12.sp, color = settingsMut(), textAlign = TextAlign.Center, modifier = Modifier.padding(top = 6.dp))
                }
                if (res.result?.creditMinor != null) Text(stringResource(R.string.loy_credit_added), fontSize = 12.sp, color = AppColors.success, modifier = Modifier.padding(top = 6.dp))
            }
        },
        confirmButton = { TextButton(onClick = onClose) { Text(stringResource(R.string.rd_done)) } },
    )
}

@Composable
private fun ChallengeCard(c: LoyaltyChallengeDto) {
    val done = c.completedAt != null
    val share = (c.progress.toFloat() / c.target.coerceAtLeast(1)).coerceIn(0f, 1f)
    val bar by animateFloatAsState(share, tween(900, easing = FastOutSlowInEasing), label = "challenge")
    // The time left, from the phone's clock.
    var now by remember { mutableStateOf(System.currentTimeMillis()) }
    LaunchedEffect(Unit) { while (true) { delay(60_000); now = System.currentTimeMillis() } }
    val left = c.endsAt?.let { runCatching { Duration.ofMillis(OffsetDateTime.parse(it).toInstant().toEpochMilli() - now) }.getOrNull() }
    val tint = when { done -> AppColors.success; c.failed -> AppColors.danger; else -> settingsAccent() }
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(settingsCard()).padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(42.dp).clip(CircleShape).background(tint.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(if (done) Icons.Rounded.EmojiEvents else Icons.Rounded.Bolt, null, tint = tint)
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(c.title.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold)
                c.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
            }
            Text("+${c.rewardPoints}", color = GoldDeep, fontWeight = FontWeight.Black)
        }
        Spacer(Modifier.height(10.dp))
        Box(Modifier.fillMaxWidth().height(8.dp).clip(CircleShape).background(tint.copy(alpha = 0.14f))) {
            Box(Modifier.fillMaxWidth(bar.coerceAtLeast(0.02f)).height(8.dp).clip(CircleShape).background(tint))
        }
        Spacer(Modifier.height(6.dp))
        Row {
            Text("${c.progress.coerceAtMost(c.target)} / ${c.target}", color = settingsMut(), fontSize = 12.sp, modifier = Modifier.weight(1f))
            Text(
                when {
                    done -> stringResource(R.string.loy_challenge_done)
                    c.failed -> stringResource(R.string.loy_challenge_failed)
                    left != null && !left.isNegative -> stringResource(R.string.loy_ends_in, left.toDays(), left.toHoursPart())
                    else -> stringResource(R.string.loy_challenge_over)
                },
                color = tint, fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
            )
        }
    }
}

@Composable
private fun BadgeGrid(badges: List<LoyaltyBadgeDto>) {
    badges.chunked(3).forEachIndexed { row, three ->
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            three.forEachIndexed { col, b -> Box(Modifier.weight(1f)) { BadgeTile(b, row * 3 + col) } }
            repeat(3 - three.size) { Spacer(Modifier.weight(1f)) }
        }
    }
}

@Composable
private fun BadgeTile(b: LoyaltyBadgeDto, index: Int) {
    val earned = b.earnedAt != null
    val appear = remember { Animatable(0f) }
    LaunchedEffect(Unit) { delay(index * 50L); appear.animateTo(1f, androidx.compose.animation.core.spring(dampingRatio = 0.55f)) }
    val share = (b.progress.toFloat() / b.threshold.coerceAtLeast(1)).coerceIn(0f, 1f)
    val ring by animateFloatAsState(share, tween(900), label = "badge-ring")
    val tint = if (earned) Gold else settingsMut().copy(alpha = 0.5f)
    Column(
        Modifier.fillMaxWidth().graphicsLayer { scaleX = appear.value; scaleY = appear.value; alpha = appear.value }
            .clip(RoundedCornerShape(18.dp)).background(settingsCard()).padding(vertical = 12.dp, horizontal = 6.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box(Modifier.size(62.dp), contentAlignment = Alignment.Center) {
            Canvas(Modifier.size(62.dp)) {
                drawCircle(tint.copy(alpha = 0.15f), radius = size.minDimension / 2 - 4f, style = Stroke(6f))
                drawArc(tint, -90f, 360f * ring, false, style = Stroke(6f, cap = StrokeCap.Round))
            }
            Box(Modifier.size(44.dp).clip(CircleShape).background(if (earned) Brush.linearGradient(listOf(Gold, GoldDeep)) else Brush.linearGradient(listOf(tint.copy(alpha = 0.15f), tint.copy(alpha = 0.15f)))), contentAlignment = Alignment.Center) {
                Icon(badgeIcon(b.icon), null, tint = if (earned) Color.White else tint, modifier = Modifier.size(24.dp))
            }
        }
        Text(b.name.orEmpty(), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 12.sp, textAlign = TextAlign.Center, maxLines = 2, modifier = Modifier.padding(top = 6.dp))
        Text(if (earned) stringResource(R.string.loy_badge_earned) else "${b.progress} / ${b.threshold}", color = if (earned) AppColors.success else settingsMut(), fontSize = 10.5.sp)
    }
}

@Composable
private fun History() {
    var filter by remember { mutableStateOf<String?>(null) }
    var list by remember { mutableStateOf<List<LoyaltyEntryDto>?>(null) }
    LaunchedEffect(filter) { list = null; list = runCatching { ApiClient.loyalty.history(ridesAuth(), filter).data.orEmpty() }.getOrDefault(emptyList()) }
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        listOf(null to R.string.loy_all, "earned" to R.string.loy_earned, "spent" to R.string.loy_spent).forEach { (k, label) ->
            val on = filter == k
            Text(
                stringResource(label), color = if (on) Color.White else settingsInk(), fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold,
                modifier = Modifier.clip(CircleShape).background(if (on) settingsAccent() else settingsCard()).clickable { filter = k }.padding(horizontal = 14.dp, vertical = 7.dp),
            )
        }
    }
    val rows = list
    when {
        rows == null -> Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = settingsAccent()) }
        rows.isEmpty() -> Empty(R.string.loy_no_history)
        else -> Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsCard())) { rows.forEach { EntryRow(it) } }
    }
}

@Composable
private fun EntryRow(e: LoyaltyEntryDto) {
    val plus = e.points > 0
    Row(Modifier.fillMaxWidth().padding(horizontal = 14.dp, vertical = 11.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(
            when (e.kind) { "badge" -> Icons.Rounded.EmojiEvents; "challenge" -> Icons.Rounded.Bolt; "redeem" -> Icons.Rounded.LocalOffer; "service" -> Icons.Rounded.DirectionsBus; else -> Icons.Rounded.DirectionsCar },
            null, tint = if (plus) AppColors.success else AppColors.danger, modifier = Modifier.size(20.dp),
        )
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(e.title.orEmpty(), color = settingsInk(), fontSize = 13.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(e.createdAt?.take(16)?.replace('T', ' ').orEmpty(), color = settingsMut(), fontSize = 11.sp)
        }
        Text((if (plus) "+" else "") + e.points, color = if (plus) AppColors.success else AppColors.danger, fontWeight = FontWeight.Black)
    }
}

@Composable
private fun Empty(text: Int) {
    Text(stringResource(text), color = settingsMut(), textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(28.dp))
}

private fun badgeIcon(key: String): ImageVector = when (key) {
    "rocket" -> Icons.Rounded.RocketLaunch
    "crown" -> Icons.Rounded.WorkspacePremium
    "heart" -> Icons.Rounded.Favorite
    "leaf" -> Icons.Rounded.Eco
    "moon" -> Icons.Rounded.DarkMode
    "bolt" -> Icons.Rounded.Bolt
    "trophy" -> Icons.Rounded.EmojiEvents
    "map" -> Icons.Rounded.Map
    "car" -> Icons.Rounded.DirectionsCar
    "bus" -> Icons.Rounded.DirectionsBus
    "boat" -> Icons.Rounded.DirectionsBoat
    else -> Icons.Rounded.Star
}

private fun parseColor(hex: String): Color? = runCatching { Color(android.graphics.Color.parseColor(hex)) }.getOrNull()
