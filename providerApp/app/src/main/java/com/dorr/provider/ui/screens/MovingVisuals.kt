package com.dorr.provider.ui.screens

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Construction
import androidx.compose.material.icons.rounded.Elevator
import androidx.compose.material.icons.rounded.Engineering
import androidx.compose.material.icons.rounded.Build
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.Layers
import androidx.compose.material.icons.rounded.LocalShipping
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Remove
import androidx.compose.material.icons.rounded.Straighten
import androidx.compose.material.icons.rounded.Timer
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.MovingEndDto
import com.dorr.provider.net.MovingOfferDto
import com.dorr.provider.net.MovingRequestDto
import com.dorr.provider.net.MyVehicleDto
import com.dorr.provider.net.OfferBreakdown
import com.dorr.provider.net.VehicleTypeDto
import com.dorr.provider.net.included
import com.dorr.provider.ui.kit.AccountDark
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsCard
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import com.dorr.provider.ui.kit.settingsNight
import com.dorr.provider.ui.kit.settingsSurface
import com.dorr.provider.ui.wallet.timeOf

/*
 * The look of the moving pages (requests, a request, the offer form, my offers) — built only from the kit (settingsSurface cards,
 * the app colour, the same type sizes and soft tiles), in the style of the customer's moving pages: the route with its two dots,
 * chips for the facts, tiles to tick, a stepper for numbers.
 */

/** The soft background of a chip or a tile that isn't picked. */
@Composable
internal fun mvWell(): Color = if (settingsNight()) AccountDark.well else Color(0xFFF3F4F6)

/** A titled block of a page: the icon on a soft tile, the title, and an optional line under it. */
@Composable
internal fun MvSection(icon: ImageVector, title: String, hint: String? = null) {
    Row(Modifier.fillMaxWidth().padding(top = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(28.dp).clip(RoundedCornerShape(9.dp)).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(16.dp))
        }
        Spacer(Modifier.width(8.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
            if (!hint.isNullOrBlank()) Text(hint, color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
        }
    }
}

/** A small fact as a chip: an icon and a short text in [color]. */
@Composable
internal fun MvChip(icon: ImageVector?, text: String, color: Color = settingsAccent(), modifier: Modifier = Modifier) {
    Row(
        modifier.clip(CircleShape).background(color.copy(alpha = 0.10f)).padding(horizontal = 10.dp, vertical = 5.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (icon != null) {
            Icon(icon, null, tint = color, modifier = Modifier.size(14.dp))
            Spacer(Modifier.width(4.dp))
        }
        Text(text, color = color, fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

/**
 * The route as the customer's pages draw it: a green dot for the pickup, a dashed line, the pin of the drop-off — each with its
 * neighbourhood (the exact address only once the job is ours). Under it the time, distance and when offers close.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun MvRouteCard(
    move: MovingRequestDto,
    badge: (@Composable () -> Unit)? = null,
    compact: Boolean = false,
    onClick: (() -> Unit)? = null,
    footer: (@Composable () -> Unit)? = null,
) {
    val accent = settingsAccent()
    Column(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(22.dp))
            .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier)
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Row(verticalAlignment = Alignment.Top) {
            Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.padding(top = 4.dp)) {
                Box(Modifier.size(12.dp).clip(CircleShape).background(Green.copy(alpha = 0.2f)), contentAlignment = Alignment.Center) {
                    Box(Modifier.size(6.dp).clip(CircleShape).background(Green))
                }
                Canvas(Modifier.width(2.dp).height(if (compact) 26.dp else 34.dp)) {
                    drawLine(
                        color = Color.Gray.copy(alpha = 0.45f), start = Offset(size.width / 2, 0f), end = Offset(size.width / 2, size.height),
                        strokeWidth = size.width, pathEffect = PathEffect.dashPathEffect(floatArrayOf(6f, 6f)),
                    )
                }
                Icon(Icons.Rounded.LocationOn, null, tint = accent, modifier = Modifier.size(16.dp))
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(if (compact) 10.dp else 16.dp)) {
                MvEndLine(stringResource(R.string.mv_from), move.pickup?.area)
                MvEndLine(stringResource(R.string.mv_to), move.dropoff?.area)
            }
            badge?.invoke()
        }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            dateTimeText(move.scheduledAt).takeIf { it.isNotBlank() }?.let { MvChip(Icons.Rounded.CalendarMonth, it) }
            move.distanceKm?.let { MvChip(Icons.Rounded.Straighten, stringResource(R.string.mv_distance, formatKm(it)), Blue) }
            if (move.status == "open") {
                timeOf(move.offersDeadlineAt).takeIf { it.isNotBlank() }?.let { MvChip(Icons.Rounded.Timer, stringResource(R.string.mv_offers_close_at, it), Amber) }
            }
        }
        footer?.invoke()
    }
}

@Composable
private fun MvEndLine(label: String, area: String?) {
    Column {
        Text(label, color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
        Text(
            area?.takeIf { it.isNotBlank() } ?: stringResource(R.string.mv_area_unknown),
            color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis,
        )
    }
}

/** A fact as a tile: the icon on a soft square, the label small and the value bold — two to a row. */
@Composable
internal fun MvFactTile(icon: ImageVector, label: String, value: String, modifier: Modifier = Modifier) {
    Row(
        modifier.clip(RoundedCornerShape(16.dp)).background(settingsCard())
            .border(1.dp, if (settingsNight()) AccountDark.line else Color(0xFFEDEEF1), RoundedCornerShape(16.dp))
            .padding(10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(34.dp).clip(RoundedCornerShape(10.dp)).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(8.dp))
        Column(Modifier.weight(1f)) {
            Text(label, color = settingsMut(), fontSize = 11.sp, maxLines = 1)
            Text(value, color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
        }
    }
}

/** Tiles two to a row. */
@Composable
internal fun MvFactGrid(facts: List<Triple<ImageVector, String, String>>) {
    if (facts.isEmpty()) return
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        facts.chunked(2).forEach { row ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                row.forEach { (icon, label, value) -> MvFactTile(icon, label, value, Modifier.weight(1f)) }
                if (row.size == 1) Spacer(Modifier.weight(1f))
            }
        }
    }
}

/** The vehicle types the customer accepts, the first one marked as their first choice. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun MvAskedTypes(types: List<VehicleTypeDto>) {
    if (types.isEmpty()) return
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        types.forEachIndexed { index, type ->
            Row(
                Modifier.clip(RoundedCornerShape(14.dp))
                    .background(if (index == 0) settingsAccent().copy(alpha = 0.10f) else mvWell())
                    .border(1.dp, if (index == 0) settingsAccent().copy(alpha = 0.5f) else Color.Transparent, RoundedCornerShape(14.dp))
                    .padding(horizontal = 10.dp, vertical = 7.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                MvVehicleImage(type.image, 26)
                Spacer(Modifier.width(6.dp))
                Column {
                    Text(type.name.orEmpty(), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                    if (index == 0 && types.size > 1) Text(stringResource(R.string.mv_first_choice), color = settingsAccent(), fontSize = 10.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

/** The admin's picture of a vehicle type, or the truck icon. */
@Composable
internal fun MvVehicleImage(image: String?, size: Int) {
    val url = ApiClient.mediaUrl(image)
    if (url != null) {
        AsyncImage(model = url, contentDescription = null, contentScale = ContentScale.Fit, modifier = Modifier.size(size.dp))
    } else {
        Icon(Icons.Rounded.LocalShipping, null, tint = settingsAccent(), modifier = Modifier.size((size * 0.8f).dp))
    }
}

/** The two ends of the move, each with its floor and lift as chips (the address only once the job is ours). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun MvEndsCard(move: MovingRequestDto) {
    Column(
        Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(20.dp)).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        MvEndBlock(stringResource(R.string.mv_pickup), move.pickup, Green)
        Box(Modifier.fillMaxWidth().height(1.dp).background(if (settingsNight()) AccountDark.line else Color(0xFFEDEEF1)))
        MvEndBlock(stringResource(R.string.mv_dropoff), move.dropoff, settingsAccent())
        if (move.pickup?.address == null) Text(stringResource(R.string.mv_address_hidden), color = settingsMut(), fontSize = 11.sp, lineHeight = 16.sp)
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun MvEndBlock(title: String, end: MovingEndDto?, dot: Color) {
    if (end == null) return
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(10.dp).clip(CircleShape).background(dot))
            Spacer(Modifier.width(8.dp))
            Text(title, color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
        }
        Text(end.area?.takeIf { it.isNotBlank() } ?: stringResource(R.string.mv_area_unknown), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
        end.address?.takeIf { it.isNotBlank() }?.let { Text(it, color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp) }
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            end.floor?.toIntOrNull()?.let { floor ->
                MvChip(Icons.Rounded.Layers, if (floor == 0) stringResource(R.string.mv_floor_ground) else stringResource(R.string.mv_floor_n, floor), Slate)
            }
            end.elevator?.let { MvChip(Icons.Rounded.Elevator, stringResource(if (it) R.string.mv_elevator_yes else R.string.mv_elevator_no), if (it) Green else Amber) }
            end.entrance?.takeIf { it.isNotBlank() }?.let { MvChip(null, it, Slate) }
        }
    }
}

/** The photos the customer added, side by side. */
@Composable
internal fun MvPhotos(urls: List<String>) {
    if (urls.isEmpty()) return
    Row(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        urls.forEach { url ->
            AsyncImage(
                model = ApiClient.mediaUrl(url), contentDescription = null, contentScale = ContentScale.Crop,
                modifier = Modifier.size(104.dp).clip(RoundedCornerShape(16.dp)),
            )
        }
    }
}

/** The plate as written on it, in a framed box. */
@Composable
internal fun MvPlate(text: String) {
    if (text.isBlank()) return
    Text(
        "‎$text", color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Black, fontFamily = FontFamily.Monospace, maxLines = 1,
        modifier = Modifier.clip(RoundedCornerShape(6.dp)).border(1.5.dp, settingsInk().copy(alpha = 0.6f), RoundedCornerShape(6.dp))
            .padding(horizontal = 7.dp, vertical = 2.dp),
    )
}

/**
 * One of my vehicles to carry the offer: its type picture, name, plate and make; one that fits the request says so, one that
 * can't be offered says why (and can't be picked).
 */
@Composable
internal fun MvVehicleOption(vehicle: MyVehicleDto, selected: Boolean, onClick: () -> Unit) {
    val accent = settingsAccent()
    val edge by animateColorAsState(if (selected) accent else if (settingsNight()) AccountDark.line else Color(0xFFE9EBEF), tween(200), label = "mv-vehicle-edge")
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp))
            .background(if (selected) accent.copy(alpha = if (settingsNight()) 0.18f else 0.06f) else settingsCard())
            .border(if (selected) 2.dp else 1.dp, edge, RoundedCornerShape(18.dp))
            .clickable(enabled = vehicle.usable, onClick = onClick)
            .padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            Modifier.size(52.dp).clip(RoundedCornerShape(14.dp)).background(accent.copy(alpha = if (vehicle.usable) 0.10f else 0.04f)),
            contentAlignment = Alignment.Center,
        ) { MvVehicleImage(vehicle.vehicleType?.image, 40) }
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    vehicle.vehicleType?.name ?: stringResource(R.string.vh_vehicle), color = settingsInk().copy(alpha = if (vehicle.usable) 1f else 0.55f),
                    fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f, fill = false),
                )
                Spacer(Modifier.width(8.dp))
                MvPlate(vehicle.plate())
            }
            listOfNotNull(vehicle.make, vehicle.model, vehicle.year?.toString(), vehicle.color).filter { it.isNotBlank() }.joinToString(" · ").takeIf { it.isNotBlank() }?.let {
                Text(it, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            when {
                !vehicle.usable -> Text(vehicle.problemText ?: vehicleStatusLabel(vehicle.status), color = if (vehicle.problem == "vehicle_type_not_asked") Slate else Amber, fontSize = 11.sp, lineHeight = 15.sp)
                vehicle.fits -> Text(stringResource(R.string.mv_vehicle_fits), color = Green, fontSize = 11.sp, fontWeight = FontWeight.Bold)
            }
        }
        Spacer(Modifier.width(8.dp))
        Box(
            Modifier.size(24.dp).clip(CircleShape).background(if (selected) accent else Color.Transparent)
                .border(2.dp, if (selected) accent else settingsMut().copy(alpha = if (vehicle.usable) 0.5f else 0.2f), CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            AnimatedVisibility(selected, enter = scaleIn() + fadeIn(), exit = fadeOut()) {
                Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(15.dp))
            }
        }
    }
}

/** A number with − and + (workers, trips), the number sliding as it changes. */
@Composable
internal fun MvStepper(icon: ImageVector, label: String, value: Int, range: IntRange, hint: String? = null, onChange: (Int) -> Unit) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(settingsCard())
            .border(1.dp, if (settingsNight()) AccountDark.line else Color(0xFFEDEEF1), RoundedCornerShape(16.dp))
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(label, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
            if (!hint.isNullOrBlank()) Text(hint, color = settingsMut(), fontSize = 11.sp, lineHeight = 15.sp)
        }
        MvStepCircle(Icons.Rounded.Remove, value > range.first) { onChange(value - 1) }
        AnimatedContent(
            targetState = value,
            transitionSpec = { (slideInVertically { it / 2 } + fadeIn(tween(160))) togetherWith (slideOutVertically { -it / 2 } + fadeOut(tween(120))) },
            label = "mv-stepper",
            modifier = Modifier.width(44.dp),
        ) { n -> Text("$n", color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 17.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth()) }
        MvStepCircle(Icons.Rounded.Add, value < range.last) { onChange(value + 1) }
    }
}

@Composable
private fun MvStepCircle(icon: ImageVector, enabled: Boolean, onClick: () -> Unit) {
    Box(
        Modifier.size(34.dp).clip(CircleShape).background(if (enabled) settingsAccent().copy(alpha = 0.12f) else mvWell()).clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = if (enabled) settingsAccent() else settingsMut().copy(alpha = 0.4f), modifier = Modifier.size(18.dp)) }
}

/** The icon of an `includes` / extras key. */
internal fun includeIcon(key: String): ImageVector = when (key) {
    "packing" -> Icons.Rounded.Inventory2
    "disassembly" -> Icons.Rounded.Build
    "crane" -> Icons.Rounded.Construction
    "technician" -> Icons.Rounded.Engineering
    else -> Icons.Rounded.Check
}

/** What the offer includes, as a tile to tick: the icon, the name, and «طلبه العميل» when the customer asked for it. */
@Composable
internal fun MvIncludeTile(key: String, on: Boolean, asked: Boolean, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val accent = settingsAccent()
    val bg by animateColorAsState(if (on) accent.copy(alpha = 0.10f) else settingsCard(), tween(200), label = "mv-include")
    Column(
        modifier.clip(RoundedCornerShape(16.dp)).background(bg)
            .border(if (on) 1.5.dp else 1.dp, if (on) accent else if (settingsNight()) AccountDark.line else Color(0xFFE9EBEF), RoundedCornerShape(16.dp))
            .clickable(onClick = onClick).padding(10.dp),
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(includeIcon(key), null, tint = if (on) accent else settingsMut(), modifier = Modifier.size(20.dp))
            Spacer(Modifier.weight(1f))
            Box(
                Modifier.size(18.dp).clip(RoundedCornerShape(5.dp)).background(if (on) accent else Color.Transparent)
                    .border(1.5.dp, if (on) accent else settingsMut().copy(alpha = 0.5f), RoundedCornerShape(5.dp)),
                contentAlignment = Alignment.Center,
            ) { if (on) Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(13.dp)) }
        }
        Text(includeLabel(key), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
        Text(
            stringResource(if (asked) R.string.mv_customer_asked else R.string.mv_customer_not_asked),
            color = if (asked) settingsAccent() else settingsMut(), fontSize = 10.sp, fontWeight = FontWeight.SemiBold,
        )
    }
}

/** What the price means, as it is typed: what the customer pays (with the tax) and what reaches the establishment. */
@Composable
internal fun MvPriceBreakdown(b: OfferBreakdown, currency: String?) {
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(mvWell()).padding(12.dp),
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        MvMoneyLine(stringResource(R.string.mv_bd_price), formatMinor(b.price, currency))
        if (b.tax > 0) MvMoneyLine(stringResource(R.string.mv_bd_tax), "+ " + formatMinor(b.tax, currency))
        MvMoneyLine(stringResource(R.string.mv_bd_customer_pays), formatMinor(b.total, currency), strong = true)
        Box(Modifier.fillMaxWidth().height(1.dp).background(settingsMut().copy(alpha = 0.2f)))
        if (b.commission > 0) MvMoneyLine(stringResource(R.string.mv_bd_commission), "− " + formatMinor(b.commission, currency))
        MvMoneyLine(stringResource(R.string.mv_bd_you_get), formatMinor(b.net, currency), strong = true, color = Green)
    }
}

@Composable
private fun MvMoneyLine(label: String, value: String, strong: Boolean = false, color: Color = settingsInk()) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = if (strong) settingsInk() else settingsMut(), fontSize = 13.sp, fontWeight = if (strong) FontWeight.Bold else FontWeight.Normal, modifier = Modifier.weight(1f))
        Text(value, color = color, fontSize = if (strong) 15.sp else 13.sp, fontWeight = if (strong) FontWeight.Black else FontWeight.SemiBold)
    }
}

/**
 * The establishment's own offer on a request: the price big, its status, vehicle, arrival and how long it holds, what it
 * includes; changing or taking it back while the customer hasn't chosen.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun MvMyOfferCard(offer: MovingOfferDto, canManage: Boolean, busy: Boolean, onEdit: () -> Unit, onWithdraw: () -> Unit) {
    val color = offerStatusColor(offer.status)
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp))
            .background(Brush.verticalGradient(listOf(color.copy(alpha = 0.12f), settingsCard())))
            .border(1.dp, color.copy(alpha = 0.35f), RoundedCornerShape(22.dp))
            .padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.CheckCircle, null, tint = color, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(6.dp))
            Text(stringResource(R.string.mv_your_offer), color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
            StatusPill(offerStatusLabel(offer.status), color)
        }
        Text(formatMinor(offer.priceMinor, offer.currencyCode), color = settingsInk(), fontSize = 26.sp, fontWeight = FontWeight.Black)
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            offer.vehicle?.type?.name?.let { MvChip(Icons.Rounded.LocalShipping, it) }
            offer.workersCount?.takeIf { it > 0 }?.let { MvChip(null, stringResource(R.string.mv_workers_n, it), Slate) }
            offer.tripsCount?.takeIf { it > 1 }?.let { MvChip(null, stringResource(R.string.mv_trips_n, it), Slate) }
            offer.included().filter { it != "tax" }.forEach { MvChip(includeIcon(it), includeLabel(it), Slate) }
        }
        InfoLine(stringResource(R.string.mv_arrival), dateTimeText(offer.arrivalAt))
        InfoLine(stringResource(R.string.mv_valid_until), dateTimeText(offer.validUntil))
        if (offer.status == "awaiting_approval") Text(stringResource(R.string.mv_awaiting_approval_hint), color = Amber, fontSize = 12.sp, lineHeight = 18.sp)
        if (canManage && offer.status in setOf("sent", "awaiting_approval")) {
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                MvOutlineButton(stringResource(R.string.mv_edit_offer), settingsAccent(), busy, Modifier.weight(1f), onEdit)
                MvOutlineButton(stringResource(R.string.mv_withdraw), Red, busy, Modifier.weight(1f), onWithdraw)
            }
        }
    }
}

@Composable
internal fun MvOutlineButton(text: String, color: Color, busy: Boolean, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Box(
        modifier.clip(RoundedCornerShape(14.dp)).border(1.5.dp, color.copy(alpha = 0.6f), RoundedCornerShape(14.dp))
            .clickable(enabled = !busy, onClick = onClick).padding(vertical = 11.dp),
        contentAlignment = Alignment.Center,
    ) { Text(text, color = color, fontSize = 14.sp, fontWeight = FontWeight.Bold) }
}

/** A green line that pops in: «أُرسل عرضك إلى العميل.» and the like. */
@Composable
internal fun MvDoneBanner(text: String?) {
    AnimatedVisibility(text != null, enter = scaleIn(initialScale = 0.9f) + fadeIn(), exit = fadeOut()) {
        Row(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Green.copy(alpha = 0.12f)).padding(12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.CheckCircle, null, tint = Green, modifier = Modifier.size(22.dp))
            Spacer(Modifier.width(8.dp))
            Text(text.orEmpty(), color = Green, fontSize = 13.sp, fontWeight = FontWeight.Bold, lineHeight = 19.sp)
        }
    }
}
