package com.dorr.driver.ui

import androidx.activity.compose.BackHandler
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.core.ui.DorrBrand
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.EarningsDto

/** Today / this week / this month, the last 7 days as bars that grow in, the pending money, points. */
@Composable
fun EarningsScreen(onBack: () -> Unit) {
    var data by remember { mutableStateOf<EarningsDto?>(null) }
    var tab by remember { mutableIntStateOf(0) }
    LaunchedEffect(Unit) { (apiCall { DriverBackend.api.earnings() } as? ApiResult.Ok)?.let { data = it.data } }
    BackHandler(onBack = onBack)
    val currency = data?.currency

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        Column(Modifier.fillMaxWidth().background(DorrBrand.navy).statusBarsPadding().padding(bottom = 18.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(8.dp)) {
                IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, null, tint = Color.White) }
                Text(stringResource(R.string.earn_title), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 18.sp)
            }
            Row(Modifier.padding(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf(R.string.earn_today, R.string.earn_week, R.string.earn_month).forEachIndexed { i, label ->
                    Text(
                        stringResource(label), color = if (tab == i) DorrBrand.navy else Color.White, fontWeight = FontWeight.SemiBold,
                        modifier = Modifier.clip(RoundedCornerShape(50)).background(if (tab == i) Color.White else Color.White.copy(alpha = 0.12f))
                            .clickable { tab = i }.padding(horizontal = 14.dp, vertical = 6.dp),
                    )
                }
            }
            val period = when (tab) { 1 -> data?.week; 2 -> data?.month; else -> data?.today }
            Text(money(period?.netMinor ?: 0, currency), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 34.sp, modifier = Modifier.padding(start = 16.dp, top = 14.dp))
            Row(Modifier.padding(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(18.dp)) {
                Text("${period?.trips ?: 0} ${stringResource(R.string.home_trips)}", color = Color.White.copy(alpha = 0.8f))
                Text(stringResource(R.string.earn_hours, ((period?.onlineS ?: 0) / 3600).toInt(), (((period?.onlineS ?: 0) % 3600) / 60).toInt()), color = Color.White.copy(alpha = 0.8f))
                period?.acceptanceBp?.let { Text("${stringResource(R.string.earn_acceptance)} ${it / 100}%", color = Color.White.copy(alpha = 0.8f)) }
            }
        }
        val d = data ?: return
        val period = when (tab) { 1 -> d.week; 2 -> d.month; else -> d.today }
        Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            // D-204: the period's tips on their own — all of them the captain's, no commission.
            TipsCard(period.tipsMinor, currency)
            Card {
                Text(stringResource(R.string.earn_last7), fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(12.dp))
                val max = (d.days.maxOfOrNull { it.netMinor } ?: 0).coerceAtLeast(1)
                Row(Modifier.fillMaxWidth().height(130.dp), horizontalArrangement = Arrangement.SpaceEvenly, verticalAlignment = Alignment.Bottom) {
                    d.days.forEach { day ->
                        val target = day.netMinor.toFloat() / max
                        val grow by animateFloatAsState(target, tween(700), label = "bar")
                        Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.fillMaxHeight(), verticalArrangement = Arrangement.Bottom) {
                            Box(Modifier.width(22.dp).fillMaxHeight(0.85f * grow.coerceAtLeast(0.03f)).clip(RoundedCornerShape(6.dp)).background(DorrBrand.orange))
                            Text(day.date.takeLast(2), fontSize = 10.sp)
                        }
                    }
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Card(Modifier.weight(1f)) {
                    Text(stringResource(R.string.earn_pending), fontSize = 12.sp)
                    Text(money(d.pendingMinor, currency), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
                }
                Card(Modifier.weight(1f)) {
                    Text(stringResource(R.string.earn_wallet), fontSize = 12.sp)
                    Text(money(d.walletMinor, currency), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, color = if (d.walletMinor < 0) DorrBrand.danger else MaterialTheme.colorScheme.onSurface)
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Card(Modifier.weight(1f)) {
                    Text(stringResource(R.string.earn_points), fontSize = 12.sp)
                    Text(d.profile.points.toString(), fontWeight = FontWeight.ExtraBold, fontSize = 22.sp, color = DorrBrand.orange)
                }
                Card(Modifier.weight(1f)) {
                    Text(stringResource(R.string.earn_rating), fontSize = 12.sp)
                    Text(d.profile.rating?.let { String.format("%.1f ★", it) } ?: "—", fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
                }
            }
            if (d.recentPoints.isNotEmpty()) {
                Card {
                    d.recentPoints.forEach { p ->
                        Row(Modifier.fillMaxWidth().padding(vertical = 4.dp)) {
                            Text(p.label ?: p.event, Modifier.weight(1f))
                            Text(if (p.points >= 0) "+${p.points}" else p.points.toString(), color = if (p.points >= 0) DorrBrand.success else DorrBrand.danger, fontWeight = FontWeight.Bold)
                        }
                    }
                }
            }
        }
    }
}
