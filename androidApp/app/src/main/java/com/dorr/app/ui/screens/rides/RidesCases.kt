package com.dorr.app.ui.screens.rides

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
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
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.SupportAgent
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.RideCaseDto
import com.dorr.app.network.RideCaseOptionsDto
import com.dorr.app.network.RideHistoryItemDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.google.gson.JsonObject
import kotlinx.coroutines.launch

/**
 * My complaints and disputes (R-133..R-140): a complaint about the captain, the car, the price, the payment,
 * safety or privacy; or an objection to a waiting, cancellation, no-show fee or the fare — each a case of its
 * own, apart from general support. The page shows what the system recorded for the ride (R-137), the team's
 * decision with its reason and any refund (R-139), and one request for another look where the country allows
 * it (R-140). The team's replies reach the rider as notifications; nothing here asks the server on a timer.
 */
@Composable
internal fun CasesPage(state: RidesState, onClose: () -> Unit) {
    var options by remember { mutableStateOf<RideCaseOptionsDto?>(null) }
    var cases by remember { mutableStateOf<List<RideCaseDto>?>(null) }
    var openUuid by remember { mutableStateOf<String?>(null) }
    var creating by remember { mutableStateOf(state.caseStartNew) }
    var error by remember { mutableStateOf<String?>(null) }
    val failed = stringResource(R.string.rd_cases_failed)

    // Back from a case (or the form) shows the list as the server has it now.
    LaunchedEffect(openUuid, creating) {
        if (openUuid == null && !creating) {
            runCatching { ApiClient.rides.caseOptions(ridesAuth()).data }.getOrNull()?.let { options = it }
            cases = runCatching { ApiClient.rides.cases(ridesAuth()).data.orEmpty() }.onFailure { error = it.apiFailure().message ?: failed }.getOrNull() ?: cases
        } else if (options == null) {
            options = runCatching { ApiClient.rides.caseOptions(ridesAuth()).data }.getOrNull()
        }
    }
    fun back() {
        when {
            openUuid != null -> openUuid = null
            creating -> { creating = false; state.caseStartNew = false; state.caseRideUuid = null }
            else -> onClose()
        }
    }

    Column(Modifier.fillMaxSize().background(settingsBackground()).statusBarsPadding()) {
        Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            RoundButton(Icons.AutoMirrored.Rounded.ArrowBack, onClick = { back() })
            Spacer(Modifier.width(10.dp))
            Text(stringResource(R.string.rd_cases_title), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 20.sp, modifier = Modifier.weight(1f))
            if (openUuid == null && !creating && options?.available == true) RoundButton(Icons.Rounded.Add, onClick = { creating = true })
        }
        when {
            openUuid != null -> CaseDetail(openUuid.orEmpty())
            creating -> NewCase(state, options, onCreated = { uuid -> creating = false; state.caseStartNew = false; state.caseRideUuid = null; openUuid = uuid })
            else -> {
                val list = cases
                when {
                    list == null && error == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = settingsAccent()) }
                    options?.available == false -> CasesHint(stringResource(R.string.rd_cases_unavailable))
                    list.isNullOrEmpty() -> CasesHint(error ?: stringResource(R.string.rd_cases_empty))
                    else -> Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        list.forEach { CaseRow(it) { openUuid = it.uuid } }
                    }
                }
            }
        }
    }
}

@Composable
private fun CaseCard(content: @Composable androidx.compose.foundation.layout.ColumnScope.() -> Unit) {
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(settingsCard()).padding(16.dp), content = content)
}

@Composable
private fun CasesHint(text: String) {
    Column(Modifier.fillMaxSize().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
        Box(Modifier.size(72.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.SupportAgent, null, tint = settingsAccent(), modifier = Modifier.size(34.dp))
        }
        Spacer(Modifier.height(10.dp))
        Text(text, color = settingsMut(), fontSize = 14.sp)
    }
}

@Composable
private fun caseColor(status: String): Color = when (status) {
    "confirmed", "partially_confirmed" -> com.dorr.app.ui.theme.AppColors.success
    "rejected" -> com.dorr.app.ui.theme.AppColors.danger
    "needs_info" -> com.dorr.app.ui.theme.AppColors.warning
    else -> settingsAccent()
}

@Composable
private fun CaseRow(c: RideCaseDto, onClick: () -> Unit) {
    val color = caseColor(c.status)
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(settingsCard()).clickable(onClick = onClick).padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(c.kindLabel + if (c.categoryLabel.isNotBlank() && c.categoryLabel != c.kindLabel) " · ${c.categoryLabel}" else "", color = settingsInk(), fontWeight = FontWeight.Bold)
                Text(listOfNotNull(c.number, c.ride?.number).joinToString(" · "), color = settingsMut(), fontSize = 12.sp)
            }
            // The state in words (a colour only helps).
            Text(c.statusLabel, color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp))
        }
        c.disputedMinor?.let { Text(money(it, c.currency), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, modifier = Modifier.padding(top = 6.dp)) }
    }
}

@Composable
private fun CasePill(label: String, selected: Boolean, onClick: () -> Unit) {
    Text(
        label, color = if (selected) Color.White else settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 13.sp,
        modifier = Modifier.clip(CircleShape).background(if (selected) settingsAccent() else settingsCard()).clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 8.dp),
    )
}

/** Open a complaint or a dispute — about the ride it came from, or (a complaint) about nothing in particular. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun NewCase(state: RidesState, options: RideCaseOptionsDto?, onCreated: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    var rides by remember { mutableStateOf<List<RideHistoryItemDto>?>(null) }
    var kind by remember { mutableStateOf(if (state.caseRideUuid != null) "dispute" else options?.kinds?.firstOrNull()?.code) }
    var category by remember { mutableStateOf<String?>(null) }
    var rideUuid by remember { mutableStateOf(state.caseRideUuid) }
    var summary by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) { rides = runCatching { ApiClient.rides.history(ridesAuth(), "past").data?.items.orEmpty() }.getOrDefault(emptyList()) }

    Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        CaseCard {
            Text(stringResource(R.string.rd_cases_what), color = settingsInk(), fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(8.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                options?.kinds.orEmpty().forEach { k -> CasePill(k.label, kind == k.code) { kind = k.code; category = null } }
            }
            Spacer(Modifier.height(10.dp))
            Text(stringResource(if (kind == "dispute") R.string.rd_cases_which_charge else R.string.rd_cases_about), color = settingsMut(), fontSize = 13.sp)
            Spacer(Modifier.height(6.dp))
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                (if (kind == "dispute") options?.disputeReasons else options?.complaintCategories).orEmpty().forEach { o -> CasePill(o.label, category == o.code) { category = o.code } }
            }
        }
        CaseCard {
            Text(stringResource(R.string.rd_cases_which_ride) + if (kind == "complaint") " " + stringResource(R.string.rd_cases_optional) else "", color = settingsInk(), fontWeight = FontWeight.Bold)
            Spacer(Modifier.height(8.dp))
            val list = rides
            when {
                list == null -> CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp, color = settingsAccent())
                list.isEmpty() -> Text(stringResource(R.string.rd_cases_no_rides), color = settingsMut(), fontSize = 13.sp)
                else -> list.take(15).forEach { r ->
                    val on = rideUuid == r.uuid
                    Row(
                        Modifier.fillMaxWidth().padding(vertical = 3.dp).clip(RoundedCornerShape(12.dp))
                            .background(if (on) settingsAccent().copy(alpha = 0.12f) else Color.Transparent)
                            .clickable { rideUuid = if (on && kind == "complaint") null else r.uuid }.padding(10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Column(Modifier.weight(1f)) {
                            Text(listOfNotNull(r.number, r.category).joinToString(" · "), color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
                            Text(listOfNotNull(r.originAddress, r.destinationAddress).joinToString(" → "), color = settingsMut(), fontSize = 11.sp, maxLines = 1)
                        }
                        r.totalMinor?.let { Text(money(it, com.dorr.app.network.RideCurrencyDto(symbol = r.currency)), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold) }
                    }
                }
            }
        }
        CaseCard {
            OutlinedTextField(value = summary, onValueChange = { summary = it }, label = { Text(stringResource(R.string.rd_cases_summary)) }, modifier = Modifier.fillMaxWidth(), minLines = 3)
            error?.let { Text(it, color = com.dorr.app.ui.theme.AppColors.danger, fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp)) }
        }
        AccentButton(
            stringResource(R.string.rd_cases_send), busy = busy,
            enabled = kind != null && category != null && summary.trim().length >= 3 && (kind != "dispute" || rideUuid != null),
        ) {
            busy = true
            error = null
            scope.launch {
                val body = JsonObject().apply {
                    addProperty("kind", kind)
                    addProperty("category", category)
                    addProperty("summary", summary.trim())
                    rideUuid?.let { addProperty("ride_uuid", it) }
                }
                runCatching { ApiClient.rides.openCase(ridesAuth(), body).data }
                    .onSuccess { c -> if (c != null) onCreated(c.uuid) }
                    .onFailure { error = it.apiFailure().message }
                busy = false
            }
        }
    }
}

@Composable
private fun CaseDetail(uuid: String) {
    val scope = rememberCoroutineScope()
    var c by remember { mutableStateOf<RideCaseDto?>(null) }
    var text by remember { mutableStateOf("") }
    var appealing by remember { mutableStateOf(false) }
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(uuid) { runCatching { ApiClient.rides.caseOf(ridesAuth(), uuid).data }.getOrNull()?.let { c = it } }
    val d = c ?: return Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = settingsAccent()) }

    fun act(call: suspend () -> RideCaseDto?) {
        busy = true
        error = null
        scope.launch {
            runCatching { call() }
                .onSuccess { updated -> if (updated != null) { c = updated; text = ""; note = ""; appealing = false } }
                .onFailure { error = it.apiFailure().message }
            busy = false
        }
    }

    val color = caseColor(d.status)
    Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp).navigationBarsPadding(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        CaseCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(d.kindLabel + if (d.categoryLabel.isNotBlank() && d.categoryLabel != d.kindLabel) " · ${d.categoryLabel}" else "", color = settingsInk(), fontWeight = FontWeight.Bold)
                    Text(listOfNotNull(d.number, d.ride?.number).joinToString(" · "), color = settingsMut(), fontSize = 12.sp)
                }
                Text(d.statusLabel, color = color, fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).padding(horizontal = 12.dp, vertical = 4.dp))
            }
            d.disputedMinor?.let { Text(money(it, d.currency), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 22.sp, modifier = Modifier.padding(top = 8.dp)) }
            d.summary?.let { Text(it, color = settingsInk(), fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp)) }
        }

        d.decision?.let { dec ->
            CaseCard {
                Text(stringResource(R.string.rd_cases_decision), color = settingsInk(), fontWeight = FontWeight.Bold)
                Text(d.statusLabel, color = color, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 4.dp))
                dec.reason?.let { Text(it, color = settingsInk(), fontSize = 14.sp, modifier = Modifier.padding(top = 6.dp)) }
                // R-139: the money effect, plainly.
                if ((dec.refundMinor ?: 0) > 0) {
                    Text(
                        stringResource(if (dec.refundApplied == true) R.string.rd_cases_refund_paid else R.string.rd_cases_refund_decided, money(dec.refundMinor, d.currency)),
                        color = com.dorr.app.ui.theme.AppColors.success, fontWeight = FontWeight.SemiBold, fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp),
                    )
                }
            }
        }
        if (d.appealedAt != null && d.decision == null) Text(stringResource(R.string.rd_cases_appealed), color = com.dorr.app.ui.theme.AppColors.warning, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)

        d.evidence?.let { e ->
            CaseCard {
                Text(stringResource(R.string.rd_cases_evidence), color = settingsInk(), fontWeight = FontWeight.Bold)
                Spacer(Modifier.height(6.dp))
                e.timeline.forEach { m ->
                    Row(Modifier.padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(milestoneWord(m.event), color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 13.sp, modifier = Modifier.weight(1f))
                        m.distanceToPickupM?.let { Text(stringResource(R.string.rd_cases_from_pickup, it), color = settingsMut(), fontSize = 11.sp) }
                        Spacer(Modifier.width(8.dp))
                        Text(parseTime(m.at)?.let { z -> String.format("%02d:%02d", z.hour, z.minute) }.orEmpty(), color = settingsInk(), fontSize = 12.sp)
                    }
                }
                e.waiting?.let { Text(stringResource(R.string.rd_cases_waiting, (it.pickupS / 60).toInt(), (it.freeS / 60).toInt()), color = settingsInk(), fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp)) }
                e.route?.let { r -> Text(stringResource(R.string.rd_cases_route, km(r.drivenM), km(r.plannedM)), color = settingsInk(), fontSize = 13.sp) }
                e.lines.forEach { l -> Row(Modifier.fillMaxWidth().padding(top = 2.dp)) { Text(l.code, color = settingsMut(), modifier = Modifier.weight(1f), fontSize = 12.sp); Text(money(l.amountMinor, d.currency), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold) } }
            }
        }

        CaseCard {
            Text(stringResource(R.string.rd_cases_conversation), color = settingsInk(), fontWeight = FontWeight.Bold)
            if (d.messages.isEmpty()) Text(stringResource(R.string.rd_cases_no_messages), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(top = 4.dp))
            d.messages.forEach { m ->
                val mine = m.from == "me"
                Column(Modifier.fillMaxWidth().padding(top = 8.dp), horizontalAlignment = if (mine) Alignment.End else Alignment.Start) {
                    Text(
                        m.body, color = settingsInk(), fontSize = 13.sp,
                        modifier = Modifier.clip(RoundedCornerShape(14.dp)).background(if (mine) settingsAccent().copy(alpha = 0.12f) else settingsBackground()).padding(horizontal = 12.dp, vertical = 8.dp),
                    )
                    if (!mine) Text(stringResource(R.string.rd_cases_team), color = settingsMut(), fontSize = 10.sp)
                }
            }
            if (d.canMessage) {
                Spacer(Modifier.height(10.dp))
                OutlinedTextField(value = text, onValueChange = { text = it }, label = { Text(stringResource(R.string.rd_cases_write)) }, modifier = Modifier.fillMaxWidth(), minLines = 2)
                Spacer(Modifier.height(8.dp))
                AccentButton(stringResource(R.string.rd_cases_reply), enabled = text.isNotBlank(), busy = busy) {
                    act { ApiClient.rides.caseMessage(ridesAuth(), uuid, JsonObject().apply { addProperty("body", text.trim()) }).data }
                }
            }
        }

        // R-140: another look — only where the country allows it, once.
        if (d.canAppeal) {
            CaseCard {
                if (!appealing) {
                    Text(stringResource(R.string.rd_cases_appeal_hint), color = settingsInk(), fontSize = 13.sp)
                    Spacer(Modifier.height(8.dp))
                    AccentButton(stringResource(R.string.rd_cases_appeal)) { appealing = true }
                } else {
                    OutlinedTextField(value = note, onValueChange = { note = it }, label = { Text(stringResource(R.string.rd_cases_appeal_why)) }, modifier = Modifier.fillMaxWidth(), minLines = 3)
                    Spacer(Modifier.height(8.dp))
                    AccentButton(stringResource(R.string.rd_cases_appeal_send), enabled = note.trim().length >= 3, busy = busy) {
                        act { ApiClient.rides.appealCase(ridesAuth(), uuid, JsonObject().apply { addProperty("note", note.trim()) }).data }
                    }
                }
            }
        }
        error?.let { Text(it, color = com.dorr.app.ui.theme.AppColors.danger, fontSize = 13.sp) }
    }
}

@Composable
private fun milestoneWord(event: String): String = stringResource(
    when (event) {
        "accepted" -> R.string.rd_cases_ev_accepted
        "arrived" -> R.string.rd_cases_ev_arrived
        "started" -> R.string.rd_cases_ev_started
        "completed" -> R.string.rd_cases_ev_completed
        "canceled" -> R.string.rd_cases_ev_canceled
        else -> R.string.rd_cases_ev_no_show
    },
)

private fun km(m: Long?): String = m?.let { String.format("%.1f", it / 1000.0) } ?: "—"
