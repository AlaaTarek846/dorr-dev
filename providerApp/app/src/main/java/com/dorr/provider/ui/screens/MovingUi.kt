package com.dorr.provider.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
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
import com.dorr.provider.R
import com.dorr.provider.net.MovingRequestDto
import com.dorr.provider.ui.kit.AccountDark
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsCard
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import com.dorr.provider.ui.kit.settingsNight
import com.dorr.provider.ui.kit.settingsSurface
import com.dorr.provider.ui.wallet.longDate
import com.dorr.provider.ui.wallet.timeOf

/*
 * What the moving and management screens share: the labels of every server value in the app language, the status pill, a
 * label/value line, a card row that opens a page, and dates. All built from the copied kit (settingsSurface cards, the app
 * colour, the same type sizes) — nothing new in the look.
 */

internal val Green = Color(0xFF16A34A)
internal val Amber = Color(0xFFD97706)
internal val Red = Color(0xFFDC2626)
internal val Slate = Color(0xFF64748B)
internal val Blue = Color(0xFF2563EB)

/** "24 نوفمبر 2026 · 14:30" in the app language and the phone's zone; "" when there is no time. */
internal fun dateTimeText(iso: String?): String {
    val day = longDate(iso)
    if (day.isBlank()) return ""
    return day + " · ‎" + timeOf(iso)
}

/** A small coloured pill: a status, "my offer", "exclusive"… */
@Composable
internal fun StatusPill(text: String, color: Color, modifier: Modifier = Modifier) {
    Text(
        text, color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1,
        modifier = modifier.clip(RoundedCornerShape(50)).background(color.copy(alpha = 0.12f)).padding(horizontal = 9.dp, vertical = 3.dp),
    )
}

/** A label and its value on one line (the value takes the room it needs, from the trailing side). */
@Composable
internal fun InfoLine(label: String, value: String?) {
    if (value.isNullOrBlank()) return
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Top) {
        Text(label, color = settingsMut(), fontSize = 13.sp, modifier = Modifier.weight(1f))
        Spacer(Modifier.width(10.dp))
        Text(value, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
    }
}

/** A card row that opens a page: an icon on a soft tile, a title, a line under it, an optional pill and the chevron. */
@Composable
internal fun RowCard(icon: ImageVector, title: String, subtitle: String?, pill: (@Composable () -> Unit)? = null, onClick: (() -> Unit)?) {
    Row(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp))
            .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier)
            .padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(40.dp).clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(21.dp))
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(title, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
            if (!subtitle.isNullOrBlank()) Text(subtitle, color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp, maxLines = 3, overflow = TextOverflow.Ellipsis)
        }
        pill?.invoke()
        if (onClick != null) Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(20.dp))
    }
}

/** A number tile of the home (the DORR app's quick cards): the count big, the label under it. */
@Composable
internal fun CountTile(icon: ImageVector, count: Int, label: String, modifier: Modifier, onClick: () -> Unit) {
    val night = settingsNight()
    val shape = RoundedCornerShape(14.dp)
    Column(
        modifier
            .clip(shape).background(settingsCard())
            .then(if (night) Modifier.border(1.dp, AccountDark.line, shape) else Modifier.border(1.dp, settingsAccent().copy(alpha = 0.10f), shape))
            .clickable(onClick = onClick)
            .padding(12.dp)
            .heightIn(min = 84.dp),
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(32.dp).clip(RoundedCornerShape(10.dp)).background(if (night) AccountDark.well else settingsAccent().copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(17.dp))
            }
            Spacer(Modifier.weight(1f))
            Text("$count", color = settingsInk(), fontSize = 20.sp, fontWeight = FontWeight.ExtraBold)
        }
        Text(label, color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold, lineHeight = 16.sp)
    }
}

/** The extras the customer asked for, as pills. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun ExtrasRow(move: MovingRequestDto) {
    val extras = move.extras ?: return
    val shown = listOfNotNull(
        R.string.mv_extra_packing.takeIf { extras.packing },
        R.string.mv_extra_disassembly.takeIf { extras.disassembly },
        R.string.mv_extra_crane.takeIf { extras.crane },
        R.string.mv_extra_technician.takeIf { extras.technician },
    )
    if (shown.isEmpty()) return
    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        shown.forEach { StatusPill(stringResource(it), settingsAccent()) }
    }
}

/** "النزهة ← الملقا" (the arrow follows the language): the neighbourhoods only (the server never sends the exact address before the job is ours). */
@Composable
internal fun routeText(move: MovingRequestDto): String {
    val from = move.pickup?.area?.takeIf { it.isNotBlank() } ?: stringResource(R.string.mv_area_unknown)
    val to = move.dropoff?.area?.takeIf { it.isNotBlank() } ?: stringResource(R.string.mv_area_unknown)
    return stringResource(R.string.mv_route, from, to)
}

@Composable
internal fun loadTypeLabel(type: String?): String = when (type) {
    "single_piece" -> stringResource(R.string.mv_load_single)
    "home" -> stringResource(R.string.mv_load_home)
    "commercial" -> stringResource(R.string.mv_load_commercial)
    else -> type.orEmpty()
}

@Composable
internal fun moveStatusLabel(status: String?): String = when (status) {
    "open" -> stringResource(R.string.mv_status_open)
    "expired" -> stringResource(R.string.mv_status_expired)
    "withdrawn" -> stringResource(R.string.mv_status_withdrawn)
    "confirmed" -> stringResource(R.string.mv_status_confirmed)
    "assigned" -> stringResource(R.string.mv_status_assigned)
    "driver_on_way" -> stringResource(R.string.mv_status_driver_on_way)
    "arrived" -> stringResource(R.string.mv_status_arrived)
    "loading" -> stringResource(R.string.mv_status_loading)
    "in_transit" -> stringResource(R.string.mv_status_in_transit)
    "unloading" -> stringResource(R.string.mv_status_unloading)
    "delivered" -> stringResource(R.string.mv_status_delivered)
    "completed" -> stringResource(R.string.mv_status_completed)
    "cancelled" -> stringResource(R.string.mv_status_cancelled)
    "disputed" -> stringResource(R.string.mv_status_disputed)
    else -> status.orEmpty()
}

internal fun moveStatusColor(status: String?): Color = when (status) {
    "open", "confirmed" -> Amber
    "assigned", "driver_on_way", "arrived", "loading", "in_transit", "unloading" -> Blue
    "delivered", "completed" -> Green
    "cancelled", "disputed", "expired", "withdrawn" -> Red
    else -> Slate
}

@Composable
internal fun offerStatusLabel(status: String?): String = when (status) {
    "awaiting_approval" -> stringResource(R.string.mv_offer_awaiting_approval)
    "sent" -> stringResource(R.string.mv_offer_sent)
    "withdrawn" -> stringResource(R.string.mv_offer_withdrawn)
    "accepted" -> stringResource(R.string.mv_offer_accepted)
    "declined" -> stringResource(R.string.mv_offer_declined)
    "expired" -> stringResource(R.string.mv_offer_expired)
    else -> status.orEmpty()
}

internal fun offerStatusColor(status: String?): Color = when (status) {
    "awaiting_approval" -> Amber
    "sent" -> Blue
    "accepted" -> Green
    else -> Slate
}

@Composable
internal fun ticketStatusLabel(status: String?): String = when (status) {
    "new" -> stringResource(R.string.mv_ticket_new)
    "awaiting_company" -> stringResource(R.string.mv_ticket_awaiting_company)
    "company_replied" -> stringResource(R.string.mv_ticket_company_replied)
    "escalated" -> stringResource(R.string.mv_ticket_escalated)
    "resolved" -> stringResource(R.string.mv_ticket_resolved)
    "unresolved" -> stringResource(R.string.mv_ticket_unresolved)
    else -> status.orEmpty()
}

internal fun ticketStatusColor(status: String?): Color = when (status) {
    "new", "awaiting_company" -> Amber
    "company_replied" -> Blue
    "escalated" -> Red
    "resolved" -> Green
    else -> Slate
}

@Composable
internal fun vehicleStatusLabel(status: String?): String = when (status) {
    "pending" -> stringResource(R.string.vh_status_pending)
    "approved" -> stringResource(R.string.vh_status_approved)
    "rejected" -> stringResource(R.string.vh_status_rejected)
    "inactive" -> stringResource(R.string.vh_status_inactive)
    else -> status.orEmpty()
}

internal fun vehicleStatusColor(status: String?): Color = when (status) {
    "approved" -> Green
    "pending" -> Amber
    "rejected" -> Red
    else -> Slate
}

/** The name of an `includes` / extras key of an offer. */
@Composable
internal fun includeLabel(key: String): String = when (key) {
    "packing" -> stringResource(R.string.mv_extra_packing)
    "disassembly" -> stringResource(R.string.mv_extra_disassembly)
    "crane" -> stringResource(R.string.mv_extra_crane)
    "technician" -> stringResource(R.string.mv_extra_technician)
    "tax" -> stringResource(R.string.mv_include_tax)
    else -> key
}

// ---------------------------------------------------------------- permissions (§17)

/** The name of a permission key (config `provider.permissions`); an unknown key shows as it is. */
@Composable
internal fun permissionLabel(key: String): String = when (key) {
    "requests.view" -> stringResource(R.string.perm_requests_view)
    "offers.send" -> stringResource(R.string.perm_offers_send)
    "offers.manage" -> stringResource(R.string.perm_offers_manage)
    "offers.approve" -> stringResource(R.string.perm_offers_approve)
    "requests.questions" -> stringResource(R.string.perm_requests_questions)
    "jobs.assign" -> stringResource(R.string.perm_jobs_assign)
    "jobs.track" -> stringResource(R.string.perm_jobs_track)
    "jobs.change_price" -> stringResource(R.string.perm_jobs_change_price)
    "jobs.cancel" -> stringResource(R.string.perm_jobs_cancel)
    "driving.work" -> stringResource(R.string.perm_driving_work)
    "driving.instant" -> stringResource(R.string.perm_driving_instant)
    "fleet.vehicles" -> stringResource(R.string.perm_fleet_vehicles)
    "fleet.drivers" -> stringResource(R.string.perm_fleet_drivers)
    "wallet.view" -> stringResource(R.string.perm_wallet_view)
    "wallet.withdraw" -> stringResource(R.string.perm_wallet_withdraw)
    "tickets.handle" -> stringResource(R.string.perm_tickets_handle)
    "ratings.view" -> stringResource(R.string.perm_ratings_view)
    "branches.manage" -> stringResource(R.string.perm_branches_manage)
    "members.manage" -> stringResource(R.string.perm_members_manage)
    "reports.view" -> stringResource(R.string.perm_reports_view)
    else -> key
}

/** One short line saying what the permission lets the person do. */
@Composable
internal fun permissionHint(key: String): String = when (key) {
    "requests.view" -> stringResource(R.string.perm_requests_view_hint)
    "offers.send" -> stringResource(R.string.perm_offers_send_hint)
    "offers.manage" -> stringResource(R.string.perm_offers_manage_hint)
    "offers.approve" -> stringResource(R.string.perm_offers_approve_hint)
    "requests.questions" -> stringResource(R.string.perm_requests_questions_hint)
    "jobs.assign" -> stringResource(R.string.perm_jobs_assign_hint)
    "jobs.track" -> stringResource(R.string.perm_jobs_track_hint)
    "jobs.change_price" -> stringResource(R.string.perm_jobs_change_price_hint)
    "jobs.cancel" -> stringResource(R.string.perm_jobs_cancel_hint)
    "driving.work" -> stringResource(R.string.perm_driving_work_hint)
    "driving.instant" -> stringResource(R.string.perm_driving_instant_hint)
    "fleet.vehicles" -> stringResource(R.string.perm_fleet_vehicles_hint)
    "fleet.drivers" -> stringResource(R.string.perm_fleet_drivers_hint)
    "wallet.view" -> stringResource(R.string.perm_wallet_view_hint)
    "wallet.withdraw" -> stringResource(R.string.perm_wallet_withdraw_hint)
    "tickets.handle" -> stringResource(R.string.perm_tickets_handle_hint)
    "ratings.view" -> stringResource(R.string.perm_ratings_view_hint)
    "branches.manage" -> stringResource(R.string.perm_branches_manage_hint)
    "members.manage" -> stringResource(R.string.perm_members_manage_hint)
    "reports.view" -> stringResource(R.string.perm_reports_view_hint)
    else -> ""
}

@Composable
internal fun permissionGroupLabel(group: String): String = when (group) {
    "requests" -> stringResource(R.string.perm_group_requests)
    "jobs" -> stringResource(R.string.perm_group_jobs)
    "driving" -> stringResource(R.string.perm_group_driving)
    "fleet" -> stringResource(R.string.perm_group_fleet)
    "money" -> stringResource(R.string.perm_group_money)
    "customers" -> stringResource(R.string.perm_group_customers)
    "management" -> stringResource(R.string.perm_group_management)
    else -> group
}

@Composable
internal fun templateLabel(template: String): String = when (template) {
    "branch_manager" -> stringResource(R.string.perm_template_branch_manager)
    "quoter" -> stringResource(R.string.perm_template_quoter)
    "driver" -> stringResource(R.string.perm_template_driver)
    else -> template
}
