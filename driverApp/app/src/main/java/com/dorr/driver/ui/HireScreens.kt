package com.dorr.driver.ui

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.PersonSearch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.HireJobDto
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.Chip
import com.dorr.driver.ui.kit.OutlineButton
import com.dorr.driver.ui.kit.PrimaryButton
import com.dorr.driver.ui.kit.ScreenHeader
import com.dorr.driver.ui.kit.TileIcon
import com.dorr.driver.ui.kit.staggerIn
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.Pal
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.OffsetDateTime

/*
 * Find a driver, the captain's side (docs/rides_tasks.md O-26c): requests near me to drive a rider's own car
 * (where, when, how long, automatic or manual, the pay), the first to accept takes it; my jobs: call the
 * rider, arrived → started → ended (the time left counts down on the phone's clock), or step back before
 * starting. New requests arrive as notifications (`hire.new`) — the page reloads once on them; nothing polls.
 */

private val Mint = Color(0xFF00897B)

@Composable
fun HireJobsScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var tab by remember { mutableStateOf("open") }
    var open by remember { mutableStateOf<List<HireJobDto>?>(null) }
    var mine by remember { mutableStateOf<List<HireJobDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf<String?>(null) }
    var tick by remember { mutableStateOf(0) }

    LaunchedEffect(tick) {
        when (val r = apiCall { DriverBackend.hire.open() }) {
            is ApiResult.Ok -> open = r.data
            is ApiResult.Failed -> error = r.error.displayMessage
            else -> {}
        }
        (apiCall { DriverBackend.hire.mine() } as? ApiResult.Ok)?.let { mine = it.data }
    }
    LaunchedEffect(Unit) { com.dorr.driver.live.DriverLive.events.collect { e -> if (e.name.startsWith("hire.")) tick++ } }
    // The time left on a job going, from the phone's clock.
    var nowMs by remember { mutableLongStateOf(System.currentTimeMillis()) }
    val started = mine.orEmpty().any { it.status == "started" }
    LaunchedEffect(started) { while (started) { delay(1000); nowMs = System.currentTimeMillis() } }

    fun act(uuid: String, call: suspend () -> retrofit2.Response<com.dorr.core.network.ApiEnvelope<HireJobDto>>, then: () -> Unit = {}) {
        busy = uuid
        error = null
        scope.launch {
            when (val r = apiCall { call() }) {
                is ApiResult.Ok -> { then(); tick++ }
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = null
        }
    }
    fun move(uuid: String, to: String) = act(uuid, { DriverBackend.hire.move(uuid, JsonObject().apply { addProperty("to", to) }) })

    Column(Modifier.fillMaxSize().background(Pal.bg)) {
        ScreenHeader(stringResource(R.string.hj_title), onBack = onBack, modifier = Modifier.statusBarsPadding())
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                Chip(stringResource(R.string.hj_open_tab, open?.size ?: 0), selected = tab == "open", tint = Mint) { tab = "open" }
                Chip(stringResource(R.string.hj_mine_tab), selected = tab == "mine", tint = Mint) { tab = "mine" }
            }
            Banner(error)
            val list = if (tab == "open") open else mine
            when {
                list == null -> {}
                list.isEmpty() -> Column(Modifier.fillMaxWidth().padding(28.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    TileIcon(Icons.Rounded.PersonSearch, Mint, 56.dp)
                    Text(stringResource(if (tab == "open") R.string.hj_no_open else R.string.hj_no_mine), color = Pal.mut, modifier = Modifier.padding(top = 10.dp))
                }
                else -> list.forEachIndexed { i, j ->
                    Column(Modifier.fillMaxWidth().surface().padding(14.dp).staggerIn(i)) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(money(j.amountMinor + j.extraMinor, j.currency), fontWeight = FontWeight.Black, fontSize = 20.sp, color = Mint, modifier = Modifier.weight(1f))
                            Text(
                                "${j.quantity} " + stringResource(if (j.unit == "day") R.string.hj_days else R.string.hj_hours) + " · " + stringResource(if (j.transmission == "manual") R.string.hj_manual else R.string.hj_automatic),
                                color = Pal.ink, fontSize = 12.sp, fontWeight = FontWeight.Bold,
                                modifier = Modifier.clip(CircleShape).background(Mint.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
                            )
                        }
                        Text(j.pickup.address.orEmpty(), color = Pal.ink, fontSize = 14.sp, fontWeight = FontWeight.SemiBold, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 6.dp))
                        Text(busDay(j.startsAt) + " · " + busTime(j.startsAt), color = Pal.mut, fontSize = 12.sp)
                        j.car?.let { Text("🚗 $it", color = Pal.mut, fontSize = 12.sp) }
                        j.note?.let { Text("📝 $it", color = Pal.mut, fontSize = 12.sp) }
                        if (j.status == "started") {
                            val end = j.endsAt?.let { runCatching { OffsetDateTime.parse(it).toInstant().toEpochMilli() }.getOrNull() }
                            end?.let {
                                val left = (it - nowMs) / 1000
                                val s = kotlin.math.abs(left)
                                Text(
                                    (if (left < 0) "+" else "") + String.format("%d:%02d:%02d", s / 3600, s % 3600 / 60, s % 60),
                                    color = if (left < 0) Pal.warning else Pal.success, fontWeight = FontWeight.Black, fontSize = 26.sp, modifier = Modifier.padding(top = 6.dp),
                                )
                            }
                        }
                        j.rider?.phone?.let { phone ->
                            Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                Chip("📞 " + (j.rider?.name ?: stringResource(R.string.hj_rider)), tint = Pal.success) { runCatching { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone"))) } }
                                Chip("🧭 " + stringResource(R.string.hj_navigate), tint = Mint) {
                                    openNavigation(context, j.pickup.lat, j.pickup.lng)
                                }
                            }
                        }
                        Spacer(Modifier.padding(top = 8.dp))
                        val working = busy == j.uuid
                        when (j.status) {
                            "open" -> PrimaryButton(stringResource(R.string.hj_accept), busy = working, color = Mint) { act(j.uuid, { DriverBackend.hire.accept(j.uuid) }) { tab = "mine" } }
                            "accepted" -> Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                Box(Modifier.weight(1f)) { PrimaryButton(stringResource(R.string.hj_arrived), busy = working, color = Mint) { move(j.uuid, "arrived") } }
                                Box(Modifier.weight(1f)) { OutlineButton(stringResource(R.string.hj_release), color = Pal.danger) { act(j.uuid, { DriverBackend.hire.release(j.uuid) }) } }
                            }
                            "arrived" -> Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                Box(Modifier.weight(1f)) { PrimaryButton(stringResource(R.string.hj_start), busy = working, color = Pal.success) { move(j.uuid, "started") } }
                                Box(Modifier.weight(1f)) { OutlineButton(stringResource(R.string.hj_release), color = Pal.danger) { act(j.uuid, { DriverBackend.hire.release(j.uuid) }) } }
                            }
                            "started" -> PrimaryButton(stringResource(R.string.hj_finish), busy = working) { move(j.uuid, "completed") }
                            else -> Text(stringResource(if (j.status == "completed") R.string.hj_done else R.string.hj_closed), color = Pal.mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                        }
                    }
                }
            }
            Spacer(Modifier.navigationBarsPadding())
        }
    }
}
