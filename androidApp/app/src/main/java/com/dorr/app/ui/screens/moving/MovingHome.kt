package com.dorr.app.ui.screens.moving

import android.widget.Toast
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.LocalShipping
import androidx.compose.material.icons.rounded.LocationOff
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MovingRequestDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.network.movingRefusal
import com.dorr.app.ui.components.PullToRefreshHost
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.rides.AccentButton
import com.dorr.app.ui.screens.rides.PanelCard
import com.dorr.app.ui.screens.rides.TripLine
import com.dorr.app.ui.screens.rides.parseTime
import com.dorr.app.ui.screens.rides.rememberWhenLabels
import com.dorr.app.ui.screens.rides.staggerIn
import com.dorr.app.ui.screens.rides.whenText
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.launch

/**
 * The moving home: «طلباتي» and the button for a new request. The tabs are the "My rides" pill; each row is its history
 * card with the status chip. Where the service is off in this country (`options.available`) the rides' unavailable card
 * takes the place of the button, before any wizard opens; requests already made can still be followed.
 */
@Composable
internal fun MovingHomePage(nav: MovingNav, onExit: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val tabs = listOf("active" to stringResource(R.string.mv_tab_active), "all" to stringResource(R.string.mv_tab_all))
    var tab by remember { mutableStateOf("active") }
    var rows by remember { mutableStateOf<List<MovingRequestDto>?>(null) }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_error)
    val tick = collectReconnectTick()
    var checked by remember { mutableStateOf(false) }
    var reloadKey by remember { mutableIntStateOf(0) }
    var deleting by remember { mutableStateOf<MovingRequestDto?>(null) }

    // Is the service open here? Asked on opening, so the button for a new request shows only when it is.
    LaunchedEffect(tick, nav.serviceOff) {
        if (!nav.serviceOff) {
            val options = runCatching { ApiClient.moving.options(mvAuth()).data }.getOrNull()
            if (options?.available == false) nav.serviceOff = true
        }
        checked = true
    }

    LaunchedEffect(tab, tick, reloadKey) {
        error = null
        runCatching { ApiClient.moving.requests(mvAuth(), tab.takeIf { it == "active" }, 1) }
            .onSuccess { envelope ->
                rows = envelope.data.orEmpty()
                page = 1
                hasMore = envelope.pagination?.hasMorePages == true
            }
            .onFailure {
                val refusal = it.movingRefusal()
                if (refusal.code == "moving_service_off") nav.serviceOff = true
                else {
                    error = refusal.message ?: failed
                    if (rows == null) rows = emptyList()
                }
            }
    }

    fun loadMore() {
        if (loadingMore || !hasMore) return
        loadingMore = true
        scope.launch {
            runCatching { ApiClient.moving.requests(mvAuth(), tab.takeIf { it == "active" }, page + 1) }
                .onSuccess { envelope ->
                    val known = rows.orEmpty().map { it.uuid }.toSet()
                    rows = rows.orEmpty() + envelope.data.orEmpty().filter { it.uuid !in known }
                    page += 1
                    hasMore = envelope.pagination?.hasMorePages == true
                }
                .onFailure { hasMore = false }
            loadingMore = false
        }
    }

    MvPage(
        title = stringResource(R.string.mv_title),
        onBack = onExit,
        bottom = if (nav.serviceOff || !checked) null else ({
            AccentButton(stringResource(R.string.mv_new_request), icon = Icons.Rounded.Add) { nav.push(MvRoute.Wizard(null)) }
        }),
    ) {
        Column(Modifier.fillMaxSize()) {
            // The tabs: a pill that fills the chosen one.
            Row(Modifier.padding(horizontal = 16.dp).fillMaxWidth().clip(CircleShape).background(settingsCard()).padding(4.dp)) {
                tabs.forEach { (key, label) ->
                    val on = key == tab
                    val bg by animateColorAsState(if (on) settingsAccent() else Color.Transparent, tween(240), label = "mv-tab")
                    Box(Modifier.weight(1f).clip(CircleShape).background(bg).clickable { if (!on) { tab = key; rows = null } }.padding(vertical = 10.dp), contentAlignment = Alignment.Center) {
                        Text(label, color = if (on) Color.White else settingsInk(), fontWeight = if (on) FontWeight.Bold else FontWeight.SemiBold, fontSize = 14.sp)
                    }
                }
            }
            Spacer(Modifier.height(10.dp))
            PullToRefreshHost(Modifier.weight(1f).fillMaxWidth()) {
                val list = rows
                when {
                    list == null -> MvLoading()
                    list.isEmpty() -> MvEmpty(Icons.Rounded.LocalShipping, error ?: stringResource(if (nav.serviceOff) R.string.mv_empty_off else R.string.mv_empty))
                    else -> LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        itemsIndexedStagger(list) { row ->
                            RequestCard(row, onDelete = if (row.status == "draft") ({ deleting = row }) else null) {
                                nav.push(if (row.status == "draft") MvRoute.Wizard(row.uuid) else MvRoute.Request(row.uuid))
                            }
                        }
                        if (hasMore) item(key = "more") {
                            LaunchedEffect(page) { loadMore() }
                            Box(Modifier.fillMaxWidth().padding(vertical = 8.dp), contentAlignment = Alignment.Center) {
                                CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(24.dp))
                            }
                        }
                        item { Spacer(Modifier.height(16.dp)) }
                    }
                }
            }
            if (nav.serviceOff) {
                MovingUnavailablePanel(
                    onRetry = {
                        checked = false
                        nav.serviceOff = false
                    },
                )
            }
        }
    }

    // A draft is taken back with `withdraw`: it leaves the running list, and «كل طلباتي» still shows it as withdrawn.
    deleting?.let { draft ->
        MovingWithdrawSheet(
            uuid = draft.uuid,
            title = stringResource(R.string.mv_draft_delete_title),
            body = stringResource(R.string.mv_draft_delete_body),
            action = stringResource(R.string.mv_draft_delete),
            onClose = { deleting = null },
            onDone = { message ->
                deleting = null
                message?.let { Toast.makeText(context, it, Toast.LENGTH_SHORT).show() }
                reloadKey += 1
            },
        )
    }
}

private fun androidx.compose.foundation.lazy.LazyListScope.itemsIndexedStagger(list: List<MovingRequestDto>, row: @Composable (MovingRequestDto) -> Unit) {
    items(list, key = { it.uuid }) { item -> Box(Modifier.staggerIn(list.indexOf(item))) { row(item) } }
}

@Composable
private fun RequestCard(item: MovingRequestDto, onDelete: (() -> Unit)? = null, onClick: () -> Unit) {
    val (label, color) = movingStatusLook(item.status)
    val (today, tomorrow) = rememberWhenLabels()
    val time = parseTime(item.scheduledAt)
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsCard()).clickable(onClick = onClick).padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(40.dp).clip(RoundedCornerShape(12.dp)).background(color.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.LocalShipping, null, tint = color, modifier = Modifier.size(22.dp))
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(loadTypeLabel(item.loadType), color = settingsInk(), fontWeight = FontWeight.Bold)
                time?.let { Text(whenText(it, today, tomorrow), color = settingsMut(), fontSize = 12.sp) }
            }
            MvChip(label, color)
        }
        val from = item.pickup?.area ?: item.pickup?.address
        val to = item.dropoff?.area ?: item.dropoff?.address
        if (from != null || to != null) {
            Spacer(Modifier.height(8.dp))
            TripLine(AppColors.brandNavy, from)
            TripLine(AppColors.brandOrange, to)
        }
        val total = item.money?.totalMinor
        if (total != null || item.establishment?.name != null) {
            Row(Modifier.fillMaxWidth().padding(top = 6.dp)) {
                Text(item.establishment?.name.orEmpty(), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.weight(1f))
                total?.let { Text(mvMoney(it, item.currencyCode, item.currencyDecimals), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 13.sp) }
            }
        }
        onDelete?.let {
            Text(
                stringResource(R.string.mv_draft_delete), color = AppColors.danger, fontWeight = FontWeight.SemiBold, fontSize = 12.sp,
                modifier = Modifier.padding(top = 6.dp).clip(CircleShape).clickable(onClick = it).padding(vertical = 4.dp),
            )
        }
    }
}

/** "Moving isn't available here": the rides' unavailable card, with its own words. */
@Composable
internal fun MovingUnavailablePanel(onRetry: () -> Unit) {
    val pulse = rememberInfiniteTransition(label = "mv-unavailable").animateFloat(
        initialValue = 1f, targetValue = 1.08f,
        animationSpec = infiniteRepeatable(tween(1100), RepeatMode.Reverse), label = "scale",
    )
    PanelCard {
        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Box(Modifier.size(72.dp).scale(pulse.value).clip(CircleShape).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.LocationOff, null, tint = settingsAccent(), modifier = Modifier.size(34.dp))
            }
            Text(stringResource(R.string.mv_na_title), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 18.sp, textAlign = TextAlign.Center)
            Text(stringResource(R.string.mv_na_body), color = settingsInk().copy(alpha = 0.65f), fontSize = 14.sp, textAlign = TextAlign.Center)
            Spacer(Modifier.height(6.dp))
            AccentButton(stringResource(R.string.rd_retry), onClick = onRetry)
        }
    }
}
