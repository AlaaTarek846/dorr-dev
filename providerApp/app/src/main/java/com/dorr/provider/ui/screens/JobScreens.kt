package com.dorr.provider.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Cancel
import androidx.compose.material.icons.rounded.EventRepeat
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.LocalShipping
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Payments
import androidx.compose.material.icons.rounded.PersonPin
import androidx.compose.material.icons.rounded.RadioButtonChecked
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.Receipt
import androidx.compose.material.icons.rounded.Repeat
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.AssignBody
import com.dorr.provider.net.CancelBody
import com.dorr.provider.net.ChangeBody
import com.dorr.provider.net.ChangeOrderDto
import com.dorr.provider.net.DriverOptionDto
import com.dorr.provider.net.InvoiceDto
import com.dorr.provider.net.MovingRequestDto
import com.dorr.provider.net.PagedRows
import com.dorr.provider.net.Realtime
import com.dorr.provider.net.RescheduleBody
import com.dorr.provider.net.RespondBody
import com.dorr.provider.net.Session
import com.dorr.provider.net.VehicleDto
import com.dorr.provider.net.apiDone
import com.dorr.provider.net.can
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.net.plate
import com.dorr.provider.ui.components.AvatarImage
import com.dorr.provider.ui.components.Card
import com.dorr.provider.ui.components.ChipRow
import com.dorr.provider.ui.components.DateField
import com.dorr.provider.ui.components.DorrTextField
import com.dorr.provider.ui.components.EmptyState
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.components.FieldLabel
import com.dorr.provider.ui.components.FormGroup
import com.dorr.provider.ui.components.FormIntro
import com.dorr.provider.ui.components.Page
import com.dorr.provider.ui.components.PagedFooter
import com.dorr.provider.ui.components.TimeField
import com.dorr.provider.ui.components.isoAt
import com.dorr.provider.ui.kit.ConfirmDialog
import com.dorr.provider.ui.kit.SettingsPrimaryButton
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import kotlinx.coroutines.launch

/**
 * The establishment's jobs (C-04, `jobs.track`): the running ones, or those still waiting for a driver, soonest first. Read on open,
 * when the connection returns and on every notice — never on a timer.
 */
@Composable
fun JobsScreen(startUnassigned: Boolean, onBack: () -> Unit, onOpenJob: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    var filter by rememberSaveable { mutableStateOf(if (startUnassigned) "confirmed" else "") }
    val paged = remember(filter) {
        PagedRows<MovingRequestDto>(20, { it.uuid }) { page ->
            apiCall { ApiClient.moving.jobs(filter.ifEmpty { null }, page) }
        }
    }
    LaunchedEffect(reconnect, filter) { paged.reload() }
    LaunchedEffect(filter) { Realtime.changed.collect { paged.reload() } }

    Page(title = stringResource(R.string.mv_jobs_title), onBack = onBack, onNearEnd = { scope.launch { paged.loadMore() } }) {
        ChipRow(
            options = listOf("" to stringResource(R.string.mv_jobs_running), "confirmed" to stringResource(R.string.mv_jobs_unassigned)),
            selected = setOf(filter),
            onToggle = { filter = it },
            modifier = Modifier.fillMaxWidth(),
        )
        when {
            !paged.loaded -> CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 40.dp).size(28.dp))
            paged.rows.isEmpty() -> EmptyState(Icons.Rounded.Work, stringResource(R.string.mv_jobs_empty_title), stringResource(R.string.mv_jobs_empty_body), Modifier.padding(top = 30.dp))
            else -> paged.rows.forEach { job -> JobCard(job) { onOpenJob(job.uuid) } }
        }
        PagedFooter(paged, onRetry = { paged.retry(); scope.launch { paged.loadMore() } })
        paged.failure?.let { ErrorBanner(it.errorText(offlineText), Modifier.fillMaxWidth()) }
    }
}

/** One job in a list: the route, when, its status and who drives it. */
@Composable
private fun JobCard(job: MovingRequestDto, footer: @Composable () -> Unit = {}, onClick: () -> Unit) {
    Card {
        Column(Modifier.fillMaxWidth().clickable(onClick = onClick), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(routeText(job), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                StatusPill(moveStatusLabel(job.status), moveStatusColor(job.status))
            }
            Text(dateTimeText(job.scheduledAt), color = settingsMut(), fontSize = 12.sp)
            val who = listOfNotNull(
                job.driver?.name?.let { stringResource(R.string.mv_driver_line, it) } ?: stringResource(R.string.mv_no_driver).takeIf { job.status == "confirmed" },
                job.vehicle?.let { "‎" + listOfNotNull(it.plateLetters, it.plateNumber).joinToString(" ") },
            )
            if (who.isNotEmpty()) Text(who.joinToString(" · "), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        }
        footer()
    }
}

/**
 * «مهامي»: the jobs handed to me as a driver, with accepting or declining a new one; the moving day itself runs on the job's page
 * ([DriverDayPanel]). Read on open, when the connection returns and on every notice.
 */
@Composable
fun MyJobsScreen(onBack: () -> Unit, onOpenJob: (String) -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var jobs by remember { mutableStateOf<List<MovingRequestDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var declining by remember { mutableStateOf<MovingRequestDto?>(null) }

    suspend fun reload() {
        when (val result = apiCall { ApiClient.moving.myJobs() }) {
            is ApiResult.Ok -> {
                jobs = result.data
                error = null
            }
            else -> error = result.errorText(offlineText) ?: genericError
        }
    }
    LaunchedEffect(reconnect) { reload() }
    LaunchedEffect(Unit) { Realtime.changed.collect { reload() } }

    fun accept(job: MovingRequestDto) {
        busy = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.moving.acceptJob(job.uuid) }
            busy = false
            if (result is ApiResult.Ok) reload() else error = result.errorText(offlineText) ?: genericError
        }
    }

    fun decline(job: MovingRequestDto) {
        busy = true
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.moving.declineJob(job.uuid) }
            busy = false
            declining = null
            if (failed != null) error = failed else reload()
        }
    }

    val list = jobs
    Page(title = stringResource(R.string.mv_my_jobs_title), onBack = onBack) {
        when {
            list == null -> if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 40.dp).size(28.dp))
            list.isEmpty() -> EmptyState(Icons.Rounded.LocalShipping, stringResource(R.string.mv_my_jobs_empty_title), stringResource(R.string.mv_my_jobs_empty_body), Modifier.padding(top = 30.dp))
            else -> list.forEach { job ->
                JobCard(
                    job,
                    footer = {
                        // A new job waits for my answer.
                        if (job.status == "assigned" && !job.driverAccepted) {
                            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                Box(
                                    Modifier.weight(1f).height(44.dp).clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = if (busy) 0.6f else 1f))
                                        .clickable(enabled = !busy) { accept(job) },
                                    contentAlignment = Alignment.Center,
                                ) { Text(stringResource(R.string.mv_accept_job), color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.Bold) }
                                Box(
                                    Modifier.weight(1f).height(44.dp).clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.10f))
                                        .clickable(enabled = !busy) { declining = job },
                                    contentAlignment = Alignment.Center,
                                ) { Text(stringResource(R.string.mv_decline_job), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold) }
                            }
                        }
                    },
                ) { onOpenJob(job.uuid) }
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    declining?.let { job ->
        ConfirmDialog(
            icon = Icons.Rounded.Cancel,
            title = stringResource(R.string.mv_decline_job),
            message = stringResource(R.string.mv_decline_job_message),
            confirmLabel = stringResource(R.string.mv_decline_job),
            loading = busy,
            onConfirm = { decline(job) },
            onDismiss = { if (!busy) declining = null },
        )
    }
}

/**
 * A job's page: for its driver the moving day on top ([DriverDayPanel]); for everyone the request with everything now that it is ours,
 * its driver and vehicle, the truck's last position from the live channel and its changes (price differences, waiting fees, new times).
 * By permission: assign a driver and a new time (`jobs.assign`), a price difference (`jobs.change_price`, or the job's own driver),
 * the answer to the customer's new time (`jobs.assign`), cancelling (`jobs.cancel`). The prices and the invoice are for whoever follows
 * the jobs (`jobs.track`): a driver alone doesn't see them (§5). The move's own channel reloads the page once on each change; nothing
 * is asked on a timer.
 */
@Composable
fun JobScreen(uuid: String, onBack: () -> Unit) {
    val reconnect = collectReconnectTick()
    val connected by Realtime.connected.collectAsState()
    val seat by Session.membership.collectAsState()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val answeredText = stringResource(R.string.mv_change_answered)
    var move by remember { mutableStateOf<MovingRequestDto?>(null) }
    var changes by remember { mutableStateOf<List<ChangeOrderDto>>(emptyList()) }
    var error by remember { mutableStateOf<String?>(null) }
    var info by remember { mutableStateOf<String?>(null) }
    var lastPosition by remember { mutableStateOf<String?>(null) }
    // "" = the page; assign, change, reschedule, cancel, invoice, inventory, deliver = a page of its own.
    var page by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }

    suspend fun reload() {
        when (val result = apiCall { ApiClient.moving.request(uuid) }) {
            is ApiResult.Ok -> {
                move = result.data
                error = null
            }
            else -> error = result.errorText(offlineText) ?: genericError
        }
        (apiCall { ApiClient.moving.changes(uuid) } as? ApiResult.Ok)?.data?.let { changes = it }
    }
    LaunchedEffect(reconnect) { reload() }
    LaunchedEffect(Unit) { Realtime.changed.collect { reload() } }
    LaunchedEffect(uuid, connected) {
        Realtime.watchMove(uuid).collect { event ->
            when (event.name) {
                "moving.changed" -> reload()
                "moving.driver_location" -> event.data?.let { d ->
                    val lat = d.get("lat")?.takeIf { it.isJsonPrimitive }?.asDouble
                    val lng = d.get("lng")?.takeIf { it.isJsonPrimitive }?.asDouble
                    val at = d.get("at")?.takeIf { it.isJsonPrimitive }?.asString
                    if (lat != null && lng != null) lastPosition = "‎%.5f, %.5f".format(java.util.Locale.US, lat, lng) + (dateTimeText(at).takeIf { it.isNotBlank() }?.let { " · $it" } ?: "")
                }
            }
        }
    }

    val current = move
    val mySeat = seat?.uuid
    val isDriver = mySeat != null && current?.driver?.uuid == mySeat
    val back = { page = "" }
    if (page.isNotEmpty() && current != null) {
        BackHandler { page = "" }
        when (page) {
            "assign" -> AssignPage(current, onBack = back, onDone = { message -> info = message; page = ""; scope.launch { reload() } })
            "change" -> ChangePage(current, onBack = back, onDone = { message -> info = message; page = ""; scope.launch { reload() } })
            "reschedule" -> ReschedulePage(current, onBack = back, onDone = { message -> info = message; page = ""; scope.launch { reload() } })
            "cancel" -> CancelPage(current, onBack = back, onDone = { message -> info = message; page = ""; scope.launch { reload() } })
            "inventory" -> InventoryPage(current, isDriver, onBack = back)
            "deliver" -> DeliverPage(current, onBack = back, onDone = { message -> info = message; page = ""; scope.launch { reload() } })
            else -> InvoicePage(current, onBack = back)
        }
        return
    }

    fun respond(change: ChangeOrderDto, approve: Boolean) {
        busy = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.moving.respond(change.uuid, RespondBody(approve)) }
            busy = false
            if (result is ApiResult.Ok) {
                info = answeredText
                reload()
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    Page(title = stringResource(R.string.mv_job_title), onBack = onBack) {
        if (current == null) {
            if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 40.dp).size(28.dp))
        } else {
            info?.let { Text(it, color = Green, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.fillMaxWidth()) }

            // The driver's moving day comes first: it is why they opened the page.
            if (isDriver) {
                DriverDayPanel(
                    current,
                    onReload = { reload() },
                    onOpenInventory = { page = "inventory" },
                    onOpenDeliver = { page = "deliver" },
                    onDeclined = onBack,
                )
            }

            // Price differences, waiting fees and new times: the open ones first.
            changes.forEach { change ->
                ChangeCard(change, current.currencyCode, canAnswer = seat.can("jobs.assign"), busy = busy, onAnswer = { approve -> respond(change, approve) })
            }

            RequestDetails(current)

            // Who drives it and with which vehicle, and the truck's last position while it moves.
            Card {
                Text(stringResource(R.string.mv_execution), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
                val driver = current.driver
                if (driver != null) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        AvatarImage(url = driver.avatar, size = 34.dp)
                        Spacer(Modifier.width(8.dp))
                        Text(driver.name.orEmpty(), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                        StatusPill(stringResource(if (current.driverAccepted) R.string.mv_driver_accepted else R.string.mv_driver_waiting), if (current.driverAccepted) Green else Amber)
                    }
                } else {
                    Text(stringResource(R.string.mv_no_driver), color = settingsMut(), fontSize = 13.sp)
                }
                current.vehicle?.let { v -> InfoLine(stringResource(R.string.mv_offer_vehicle), "‎" + listOfNotNull(v.plateLetters, v.plateNumber).joinToString(" ") + (v.make?.let { " · $it" } ?: "")) }
                lastPosition?.let { InfoLine(stringResource(R.string.mv_last_position), it) }
            }

            val seesMoney = seat.can("jobs.track")
            if (seesMoney) {
                current.money?.let { money ->
                    Card {
                        InfoLine(stringResource(R.string.mv_price), money.priceMinor?.let { formatMinor(it, current.currencyCode) })
                        InfoLine(stringResource(R.string.mv_tax), money.taxMinor?.takeIf { it > 0 }?.let { formatMinor(it, current.currencyCode) })
                        InfoLine(stringResource(R.string.mv_total), money.totalMinor?.let { formatMinor(it, current.currencyCode) })
                    }
                }
            }

            val status = current.status
            val beforeMoving = status == "confirmed" || status == "assigned"
            val running = status in setOf("confirmed", "assigned", "driver_on_way", "arrived", "loading", "in_transit", "unloading")
            // The pieces for whoever follows the job; the driver has them in their panel while they work on them.
            if (seesMoney && !isDriver && status in setOf("loading", "in_transit", "unloading", "delivered", "completed", "disputed")) {
                RowCard(Icons.Rounded.Inventory2, stringResource(R.string.day_inventory), null, onClick = { page = "inventory" })
            }
            if (seat.can("jobs.assign") && beforeMoving) RowCard(Icons.Rounded.PersonPin, stringResource(R.string.mv_assign), stringResource(R.string.mv_assign_sub), onClick = { page = "assign" })
            if ((seat.can("jobs.change_price") || isDriver) && running) RowCard(Icons.Rounded.Payments, stringResource(R.string.mv_change), stringResource(R.string.mv_change_sub), onClick = { page = "change" })
            if (seat.can("jobs.assign") && beforeMoving) RowCard(Icons.Rounded.Schedule, stringResource(R.string.mv_reschedule), stringResource(R.string.mv_reschedule_sub), onClick = { page = "reschedule" })
            if (seesMoney && current.money?.priceMinor != null) RowCard(Icons.Rounded.Receipt, stringResource(R.string.mv_invoice), null, onClick = { page = "invoice" })
            if (seat.can("jobs.cancel") && running) RowCard(Icons.Rounded.Cancel, stringResource(R.string.mv_cancel), stringResource(R.string.mv_cancel_sub), onClick = { page = "cancel" })
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/** One change of the job: what it is, its amount or new time, why, where it stands; the customer's new time can be answered here. */
@Composable
private fun ChangeCard(change: ChangeOrderDto, currency: String?, canAnswer: Boolean, busy: Boolean, onAnswer: (Boolean) -> Unit) {
    val fromCustomer = change.proposedBy == "user"
    val open = change.status == "pending"
    val (statusText, statusColor) = when {
        open && fromCustomer -> stringResource(R.string.mv_change_waiting_you) to Amber
        open -> stringResource(R.string.mv_change_waiting_customer) to Amber
        change.status == "awaiting_payment" -> stringResource(R.string.mv_change_waiting_payment) to Amber
        change.status == "approved" -> stringResource(R.string.mv_change_approved) to Green
        change.status == "rejected" -> stringResource(R.string.mv_change_rejected) to Red
        else -> change.status.orEmpty() to Slate
    }
    Card {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(if (change.kind == "reschedule") Icons.Rounded.EventRepeat else Icons.Rounded.Payments, contentDescription = null, tint = if (open) Amber else settingsMut(), modifier = Modifier.size(22.dp))
            Spacer(Modifier.width(8.dp))
            Text(
                stringResource(
                    when {
                        change.kind == "waiting" -> R.string.mv_change_kind_waiting
                        change.kind == "reschedule" && fromCustomer -> R.string.mv_new_time_title
                        change.kind == "reschedule" -> R.string.mv_reschedule
                        else -> R.string.mv_change
                    },
                ),
                color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f),
            )
            StatusPill(statusText, statusColor)
        }
        InfoLine(stringResource(R.string.mv_change_amount_line), change.priceDeltaMinor?.let { formatMinor(it, currency) })
        InfoLine(stringResource(R.string.mv_change_new_time), dateTimeText(change.newScheduledAt))
        InfoLine(stringResource(R.string.mv_workers), change.workersCount?.toString())
        InfoLine(stringResource(R.string.mv_trips), change.tripsCount?.toString())
        // A waiting fee's reason is `waiting:{minutes}`; any other is the words of whoever asked.
        val minutes = change.reason?.takeIf { change.kind == "waiting" }?.removePrefix("waiting:")?.toIntOrNull()
        when {
            minutes != null -> Text(stringResource(R.string.mv_change_waited, minutes), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
            change.kind != "waiting" -> change.reason?.takeIf { it.isNotBlank() }?.let { Text(stringResource(R.string.pd_reason, it), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp) }
        }
        if (open && fromCustomer) {
            if (canAnswer) {
                Text(stringResource(R.string.mv_new_time_body), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
                Row {
                    Text(stringResource(R.string.mv_approve), color = Green, fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(enabled = !busy) { onAnswer(true) })
                    Spacer(Modifier.width(20.dp))
                    Text(stringResource(R.string.mv_refuse), color = Red, fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clickable(enabled = !busy) { onAnswer(false) })
                }
            }
        }
    }
}

/**
 * Handing the job to a driver: the drivers of the establishment (`moving/drivers`), each with how many jobs they hold; one who can't
 * be chosen says why (their licence…). Optionally another approved vehicle: replacing the offer's vehicle needs a reason.
 */
@Composable
private fun AssignPage(move: MovingRequestDto, onBack: () -> Unit, onDone: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val assignedText = stringResource(R.string.mv_assigned_ok)
    var drivers by remember { mutableStateOf<List<DriverOptionDto>?>(null) }
    var vehicles by remember { mutableStateOf<List<VehicleDto>>(emptyList()) }
    var driverUuid by rememberSaveable { mutableStateOf(move.driver?.uuid) }
    var vehicleUuid by rememberSaveable { mutableStateOf(move.vehicle?.uuid) }
    var reason by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) {
        when (val result = apiCall { ApiClient.moving.drivers(move.branchId) }) {
            is ApiResult.Ok -> drivers = result.data
            else -> error = result.errorText(offlineText) ?: genericError
        }
        (apiCall { ApiClient.manage.vehicles() } as? ApiResult.Ok)?.data?.let { list -> vehicles = list.filter { it.status == "approved" } }
    }

    val currentVehicle = move.vehicle?.uuid
    val replacing = vehicleUuid != null && currentVehicle != null && vehicleUuid != currentVehicle
    val ready = driverUuid != null && (!replacing || reason.isNotBlank())

    fun assign() {
        val driver = driverUuid ?: return
        busy = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.moving.assign(move.uuid, AssignBody(driver, vehicleUuid?.takeIf { it != currentVehicle }, reason.trim().ifEmpty { null })) }
            busy = false
            if (result is ApiResult.Ok) onDone(assignedText) else error = result.errorText(offlineText) ?: genericError
        }
    }

    Page(title = stringResource(R.string.mv_assign), onBack = onBack) {
        FormIntro(Icons.Rounded.PersonPin, stringResource(R.string.mv_assign), stringResource(R.string.mv_assign_body))
        FieldLabel(stringResource(R.string.mv_driver))
        val list = drivers
        when {
            list == null -> if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.size(24.dp))
            list.isEmpty() -> Text(stringResource(R.string.mv_no_drivers), color = Red, fontSize = 13.sp, modifier = Modifier.fillMaxWidth())
            else -> Card {
                list.forEach { driver ->
                    PickRow(
                        selected = driverUuid == driver.uuid,
                        title = driver.name?.takeIf { it.isNotBlank() } ?: ("‎" + driver.phone.orEmpty()),
                        subtitle = driver.problem?.let { driverProblemLabel(it) } ?: stringResource(R.string.mv_driver_busy_jobs, driver.busyJobs),
                        enabled = driver.problem == null,
                    ) { driverUuid = driver.uuid }
                }
            }
        }
        if (vehicles.isNotEmpty()) {
            FieldLabel(stringResource(R.string.mv_offer_vehicle))
            Card {
                vehicles.forEach { vehicle ->
                    PickRow(
                        selected = vehicleUuid == vehicle.uuid,
                        title = (vehicle.vehicleType?.name ?: stringResource(R.string.vh_vehicle)) + " · ‎" + vehicle.plate(),
                        subtitle = if (vehicle.uuid == currentVehicle) stringResource(R.string.mv_offer_vehicle_current) else null,
                    ) { vehicleUuid = vehicle.uuid }
                }
            }
        }
        if (replacing) {
            DorrTextField(reason, { reason = it.take(500) }, label = stringResource(R.string.mv_replace_reason), icon = Icons.Rounded.Notes, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
            Text(stringResource(R.string.mv_replace_hint), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp, modifier = Modifier.fillMaxWidth())
        }
        SettingsPrimaryButton(text = stringResource(R.string.mv_assign), enabled = ready, loading = busy, onClick = ::assign)
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/** Why a driver can't be given a job (WorkGate::driverProblem). */
@Composable
private fun driverProblemLabel(problem: String): String = when (problem) {
    "driver_licence" -> stringResource(R.string.mv_problem_licence)
    "driver_inactive" -> stringResource(R.string.mv_problem_inactive)
    "not_a_driver" -> stringResource(R.string.mv_problem_not_driver)
    else -> problem
}

/** A choice among a few (a radio mark, a title and a line under it); one that can't be chosen is greyed, its line in red saying why. */
@Composable
internal fun PickRow(selected: Boolean, title: String, subtitle: String?, enabled: Boolean = true, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable(enabled = enabled, onClick = onClick).padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(
            if (selected) Icons.Rounded.RadioButtonChecked else Icons.Rounded.RadioButtonUnchecked, contentDescription = null,
            tint = if (enabled) settingsAccent() else settingsMut().copy(alpha = 0.4f), modifier = Modifier.size(22.dp),
        )
        Spacer(Modifier.width(8.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = settingsInk().copy(alpha = if (enabled) 1f else 0.5f), fontSize = 13.sp, fontWeight = FontWeight.Bold)
            if (subtitle != null) Text(subtitle, color = if (enabled) settingsMut() else Red, fontSize = 11.sp)
        }
    }
}

/** A price difference for more (or less) load, trips or workers; the customer approves it and pays the difference (B-13). */
@Composable
private fun ChangePage(move: MovingRequestDto, onBack: () -> Unit, onDone: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val sentText = stringResource(R.string.mv_change_sent)
    var increase by rememberSaveable { mutableStateOf(true) }
    var amount by rememberSaveable { mutableStateOf("") }
    var workers by rememberSaveable { mutableStateOf("") }
    var trips by rememberSaveable { mutableStateOf("") }
    var reason by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val minor = parseMinor(amount)
    val ready = minor != null && reason.isNotBlank()

    fun send() {
        val value = minor ?: return
        busy = true
        error = null
        scope.launch {
            val body = ChangeBody(if (increase) value else -value, workers.toIntOrNull(), trips.toIntOrNull(), reason.trim())
            val result = apiCall { ApiClient.moving.proposeChange(move.uuid, body) }
            busy = false
            if (result is ApiResult.Ok) onDone(sentText) else error = result.errorText(offlineText) ?: genericError
        }
    }

    Page(title = stringResource(R.string.mv_change), onBack = onBack) {
        FormIntro(Icons.Rounded.Payments, stringResource(R.string.mv_change), stringResource(R.string.mv_change_body))
        ChipRow(
            options = listOf("up" to stringResource(R.string.mv_change_up), "down" to stringResource(R.string.mv_change_down)),
            selected = setOf(if (increase) "up" else "down"),
            onToggle = { increase = it == "up" },
        )
        FormGroup {
            DorrTextField(
                amount, { amount = it.filter { c -> c.isDigit() || c == '.' || c == ',' }.take(12) }, label = stringResource(R.string.mv_change_amount, move.currencyCode.orEmpty()),
                icon = Icons.Rounded.Payments, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), error = amount.isNotBlank() && minor == null, modifier = Modifier.fillMaxWidth(),
            )
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                DorrTextField(workers, { workers = it.filter(Char::isDigit).take(2) }, label = stringResource(R.string.mv_workers), placeholder = stringResource(R.string.onb_optional), icon = Icons.Rounded.Groups, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.weight(1f))
                DorrTextField(trips, { trips = it.filter(Char::isDigit).take(2) }, label = stringResource(R.string.mv_trips), placeholder = stringResource(R.string.onb_optional), icon = Icons.Rounded.Repeat, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number), modifier = Modifier.weight(1f))
            }
            DorrTextField(reason, { reason = it.take(500) }, label = stringResource(R.string.mv_reason), icon = Icons.Rounded.Notes, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
        }
        SettingsPrimaryButton(text = stringResource(R.string.mv_send_to_customer), enabled = ready, loading = busy, onClick = ::send)
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/** A new time for the job, with the reason; the customer approves or refuses it. */
@Composable
private fun ReschedulePage(move: MovingRequestDto, onBack: () -> Unit, onDone: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val sentText = stringResource(R.string.mv_change_sent)
    var date by rememberSaveable { mutableStateOf("") }
    var time by rememberSaveable { mutableStateOf("") }
    var reason by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val iso = isoAt(date, time)
    val ready = iso != null && reason.isNotBlank()

    fun send() {
        val at = iso ?: return
        busy = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.moving.reschedule(move.uuid, RescheduleBody(at, reason.trim())) }
            busy = false
            if (result is ApiResult.Ok) onDone(sentText) else error = result.errorText(offlineText) ?: genericError
        }
    }

    Page(title = stringResource(R.string.mv_reschedule), onBack = onBack) {
        FormIntro(Icons.Rounded.Schedule, stringResource(R.string.mv_reschedule), stringResource(R.string.mv_reschedule_body))
        Card { InfoLine(stringResource(R.string.mv_when_now), dateTimeText(move.scheduledAt)) }
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            DateField(date, { date = it }, label = stringResource(R.string.mv_date), modifier = Modifier.weight(1f))
            TimeField(time, { time = it }, label = stringResource(R.string.mv_time), modifier = Modifier.weight(1f))
        }
        DorrTextField(reason, { reason = it.take(500) }, label = stringResource(R.string.mv_reason), icon = Icons.Rounded.Notes, singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
        SettingsPrimaryButton(text = stringResource(R.string.mv_send_to_customer), enabled = ready, loading = busy, onClick = ::send)
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/** The provider's cancel reasons (config `moving.cancel_reasons.provider`); the last three open an exception Dorr decides. */
private val CANCEL_REASONS = listOf("vehicle_breakdown", "driver_unavailable", "other", "load_differs", "customer_absent", "unsafe_place")
private val EXCEPTION_REASONS = setOf("load_differs", "customer_absent", "unsafe_place")

@Composable
private fun cancelReasonLabel(reason: String): String = when (reason) {
    "vehicle_breakdown" -> stringResource(R.string.mv_cr_vehicle_breakdown)
    "driver_unavailable" -> stringResource(R.string.mv_cr_driver_unavailable)
    "other" -> stringResource(R.string.mv_cr_other)
    "load_differs" -> stringResource(R.string.mv_cr_load_differs)
    "customer_absent" -> stringResource(R.string.mv_cr_customer_absent)
    "unsafe_place" -> stringResource(R.string.mv_cr_unsafe_place)
    else -> reason
}

/**
 * Cancelling the job with a reason: a reason on the establishment's side ends it and the customer gets the money back; a reason on
 * the customer's side (the load differs, the customer is absent, the place is unsafe) does not cancel — it opens an exception for
 * Dorr to decide. Said before the tap.
 */
@Composable
private fun CancelPage(move: MovingRequestDto, onBack: () -> Unit, onDone: (String) -> Unit) {
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val cancelledText = stringResource(R.string.mv_cancelled_ok)
    val exceptionText = stringResource(R.string.mv_exception_ok)
    var reason by rememberSaveable { mutableStateOf<String?>(null) }
    var details by rememberSaveable { mutableStateOf("") }
    var confirming by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val exception = reason in EXCEPTION_REASONS

    fun send() {
        val chosen = reason ?: return
        busy = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.moving.cancel(move.uuid, CancelBody(chosen, details.trim().ifEmpty { null })) }
            busy = false
            confirming = false
            if (result is ApiResult.Ok) onDone(if (chosen in EXCEPTION_REASONS) exceptionText else cancelledText) else error = result.errorText(offlineText) ?: genericError
        }
    }

    Page(title = stringResource(R.string.mv_cancel), onBack = onBack) {
        FormIntro(Icons.Rounded.Cancel, stringResource(R.string.mv_cancel), stringResource(R.string.mv_cancel_body))
        Card {
            CANCEL_REASONS.forEach { key ->
                PickRow(
                    selected = reason == key,
                    title = cancelReasonLabel(key),
                    subtitle = if (key in EXCEPTION_REASONS) stringResource(R.string.mv_cr_exception_hint) else null,
                ) { reason = key }
            }
        }
        if (reason != null) {
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background((if (exception) Amber else Red).copy(alpha = 0.10f)).padding(12.dp),
                verticalAlignment = Alignment.Top,
            ) {
                Icon(Icons.Rounded.WarningAmber, contentDescription = null, tint = if (exception) Amber else Red, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text(stringResource(if (exception) R.string.mv_cancel_exception_note else R.string.mv_cancel_refund_note), color = settingsInk(), fontSize = 12.sp, lineHeight = 18.sp)
            }
        }
        DorrTextField(details, { details = it.take(1000) }, label = stringResource(R.string.mv_details), placeholder = stringResource(R.string.onb_optional), icon = Icons.Rounded.Notes, singleLine = false, minLines = 3, modifier = Modifier.fillMaxWidth())
        SettingsPrimaryButton(text = stringResource(if (exception) R.string.mv_open_exception else R.string.mv_cancel), enabled = reason != null, loading = busy, onClick = { confirming = true })
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    if (confirming) {
        ConfirmDialog(
            icon = Icons.Rounded.Cancel,
            title = stringResource(if (exception) R.string.mv_open_exception else R.string.mv_cancel),
            message = stringResource(if (exception) R.string.mv_cancel_exception_note else R.string.mv_cancel_refund_note),
            confirmLabel = stringResource(R.string.common_yes),
            loading = busy,
            onConfirm = ::send,
            onDismiss = { if (!busy) confirming = false },
        )
    }
}

/** The job's invoice (B-06): the agreed price, approved differences, tax, total, refunds, Dorr's commission and what the establishment gets. */
@Composable
private fun InvoicePage(move: MovingRequestDto, onBack: () -> Unit) {
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var invoice by remember { mutableStateOf<InvoiceDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(move.uuid) {
        when (val result = apiCall { ApiClient.moving.invoice(move.uuid) }) {
            is ApiResult.Ok -> invoice = result.data
            else -> error = result.errorText(offlineText) ?: genericError
        }
    }
    Page(title = stringResource(R.string.mv_invoice), onBack = onBack) {
        val inv = invoice
        if (inv == null) {
            if (error == null) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.padding(top = 40.dp).size(28.dp))
        } else {
            val c = inv.currencyCode
            Card {
                InfoLine(stringResource(R.string.mv_invoice_number), inv.number)
                InfoLine(stringResource(R.string.mv_invoice_date), dateTimeText(inv.issuedAt))
            }
            Card {
                inv.lines.forEach { line ->
                    InfoLine(
                        when (line.key) {
                            "moving" -> stringResource(R.string.mv_line_moving)
                            "waiting" -> stringResource(R.string.mv_line_waiting)
                            else -> stringResource(R.string.mv_line_change)
                        } + (line.reason?.takeIf { it.isNotBlank() }?.let { " ($it)" } ?: ""),
                        formatMinor(line.amountMinor, c),
                    )
                }
                InfoLine(stringResource(R.string.mv_price), formatMinor(inv.priceMinor, c))
                if (inv.taxMinor > 0) InfoLine(stringResource(R.string.mv_tax), formatMinor(inv.taxMinor, c))
                InfoLine(stringResource(R.string.mv_total), formatMinor(inv.totalMinor, c))
                if (inv.refundedMinor > 0) InfoLine(stringResource(R.string.mv_refunded), formatMinor(inv.refundedMinor, c))
                inv.commissionMinor?.let { InfoLine(stringResource(R.string.mv_commission), formatMinor(it, c)) }
                inv.providerNetMinor?.let { InfoLine(stringResource(R.string.mv_net), formatMinor(it, c)) }
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}
