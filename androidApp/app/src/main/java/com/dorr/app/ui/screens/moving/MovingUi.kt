@file:OptIn(ExperimentalMaterial3Api::class)

package com.dorr.app.ui.screens.moving

import android.content.Context
import android.location.Geocoder
import android.net.Uri
import android.provider.OpenableColumns
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.StarBorder
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.RideCurrencyDto
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.locale.rememberAppLocaleFrame
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.rides.RoundButton
import com.dorr.app.ui.screens.rides.money
import com.dorr.app.ui.screens.rides.parseTime
import com.dorr.app.ui.screens.rides.ridesAuth
import com.dorr.app.ui.theme.AppColors
import com.google.android.gms.maps.model.LatLng
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.drop
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.time.Duration
import java.time.Instant
import java.util.Locale

/*
 * The pieces every moving screen shares. Nothing here is a new design: each one is the rides screens' own look
 * (the page header of "My rides", the cards of the cases page, the chips, the sheet), given one name so that the
 * moving pages stay short.
 */

internal fun mvAuth(): String = ridesAuth()

/** "250.00 SAR" from minor units, with the request's currency code and decimals. */
internal fun mvMoney(minor: Long?, code: String?, decimals: Int): String = money(minor, RideCurrencyDto(symbol = code, decimals = decimals))

/** What is typed in an amount field, kept to digits and one separator, with no more decimals than the currency has. */
internal fun limitAmount(typed: String, decimals: Int): String {
    val out = StringBuilder()
    var separated = false
    var fraction = 0
    typed.forEach { c ->
        if (c.isDigit()) {
            if (!separated) {
                out.append(c)
            } else if (fraction < decimals) {
                out.append(c)
                fraction += 1
            }
        } else if ((c == '.' || c == ',' || c == '٫') && !separated && decimals > 0) {
            separated = true
            out.append(c)
        }
    }
    return out.toString().take(14)
}

/** A typed amount in minor units; null when it isn't a number. Any digits the keyboard gives are understood. */
internal fun parseMinor(typed: String, decimals: Int): Long? {
    val normal = typed.trim().map { c -> if (c.isDigit()) '0' + Character.digit(c, 10) else if (c == ',' || c == '٫') '.' else c }.joinToString("")
    return runCatching { java.math.BigDecimal(normal).movePointRight(decimals).setScale(0, java.math.RoundingMode.DOWN).longValueExact() }.getOrNull()
}

/** The neighbourhood under a point, in the app's language (providers see only this until their offer is accepted). */
@Suppress("DEPRECATION")
internal suspend fun areaOf(context: Context, at: LatLng): String? = withContext(Dispatchers.IO) {
    runCatching {
        Geocoder(context, Locale.getDefault()).getFromLocation(at.latitude, at.longitude, 1)?.firstOrNull()?.let { a ->
            (a.subLocality ?: a.locality ?: a.subAdminArea)?.takeIf { it.isNotBlank() }
        }
    }.getOrNull()
}

/** The size of a picked file, when the phone can tell. */
internal fun uriSize(context: Context, uri: Uri): Long? = runCatching {
    context.contentResolver.query(uri, arrayOf(OpenableColumns.SIZE), null, null, null)?.use { c ->
        if (c.moveToFirst() && !c.isNull(0)) c.getLong(0) else null
    }
}.getOrNull()

/** Set when the moving sheet is drawn on a page's white panel: the cards on it take the page's colour instead. */
internal val LocalMvOnPanel = compositionLocalOf { false }

@Composable
internal fun mvCardColor(): Color = if (LocalMvOnPanel.current) settingsBackground() else settingsCard()

/** The colour of a chip or a field inside a card. */
@Composable
internal fun mvInsetColor(): Color = if (LocalMvOnPanel.current) settingsCard() else settingsBackground()

/** A page: back and the title at the top (as on "My rides"), its content, and an optional action bar at the bottom. */
@Composable
internal fun MvPage(
    title: String,
    onBack: () -> Unit,
    trailing: (@Composable () -> Unit)? = null,
    bottom: (@Composable () -> Unit)? = null,
    content: @Composable () -> Unit,
) {
    Column(Modifier.fillMaxSize().background(settingsBackground()).statusBarsPadding().imePadding()) {
        Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            RoundButton(Icons.AutoMirrored.Rounded.ArrowBack, onClick = onBack)
            Spacer(Modifier.width(10.dp))
            Text(title, color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 20.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            trailing?.invoke()
        }
        Box(Modifier.weight(1f).fillMaxWidth()) { content() }
        bottom?.let {
            Column(Modifier.fillMaxWidth().shadow(18.dp).background(settingsCard()).navigationBarsPadding().padding(16.dp)) { it() }
        }
    }
}

/** The scrolling column of a page's cards. */
@Composable
internal fun MvScroll(content: @Composable ColumnScope.() -> Unit) {
    Column(
        Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 16.dp).padding(bottom = 16.dp).navigationBarsPadding(),
        verticalArrangement = Arrangement.spacedBy(12.dp),
        content = content,
    )
}

@Composable
internal fun MvCard(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Column(modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(mvCardColor()).padding(16.dp), content = content)
}

@Composable
internal fun MvCardTitle(text: String, modifier: Modifier = Modifier) {
    Text(text, color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 15.sp, modifier = modifier)
}

/** A state in words (a colour only helps). */
@Composable
internal fun MvChip(text: String, color: Color) {
    Text(
        text, color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1,
        modifier = Modifier.clip(CircleShape).background(color.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 4.dp),
    )
}

/** A choice among a few (the cases page's pills). */
@Composable
internal fun MvPill(label: String, selected: Boolean, onClick: () -> Unit) {
    val fill by animateColorAsState(if (selected) settingsAccent() else mvInsetColor(), tween(200), label = "mv-pill")
    Text(
        label, color = if (selected) Color.White else settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 13.sp,
        modifier = Modifier.clip(CircleShape).background(fill).clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 8.dp),
    )
}

/** Label on one side, value on the other. */
@Composable
internal fun MvLine(label: String, value: String, bold: Boolean = false, valueColor: Color? = null) {
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = if (bold) settingsInk() else settingsMut(), fontSize = 13.sp, fontWeight = if (bold) FontWeight.ExtraBold else FontWeight.Normal, modifier = Modifier.weight(1f))
        Spacer(Modifier.width(10.dp))
        Text(value, color = valueColor ?: settingsInk(), fontSize = if (bold) 15.sp else 13.sp, fontWeight = if (bold) FontWeight.ExtraBold else FontWeight.SemiBold)
    }
}

/** A row that opens something (the "left something in the car" row). */
@Composable
internal fun MvAction(icon: ImageVector, title: String, sub: String? = null, tint: Color = settingsAccent(), onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(mvCardColor()).clickable(onClick = onClick).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = tint, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 13.5.sp)
            sub?.let { Text(it, color = settingsMut(), fontSize = 11.5.sp) }
        }
        Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = settingsMut(), modifier = Modifier.size(18.dp))
    }
}

/** The quieter of two buttons (the rides cancel look). */
@Composable
internal fun MvOutlineButton(text: String, color: Color = settingsInk(), enabled: Boolean = true, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val shape = RoundedCornerShape(16.dp)
    Box(
        modifier.fillMaxWidth().height(54.dp).clip(shape).border(1.5.dp, color.copy(alpha = 0.35f), shape).clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Text(text, color = color, fontWeight = FontWeight.Bold) }
}

@Composable
internal fun MvError(text: String?) {
    text?.let { Text(it, color = AppColors.danger, fontSize = 13.sp) }
}

/** A note in a soft wash of its colour. */
@Composable
internal fun MvNote(text: String, color: Color = settingsAccent()) {
    Text(
        text, color = settingsInk(), fontSize = 12.5.sp,
        modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(color.copy(alpha = 0.10f)).padding(horizontal = 12.dp, vertical = 10.dp),
    )
}

@Composable
internal fun MvLoading() {
    Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = settingsAccent()) }
}

/** Nothing to show: an icon in its circle and a line (the "My rides" empty state). */
@Composable
internal fun MvEmpty(icon: ImageVector, text: String, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxSize().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
        Box(Modifier.size(72.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
            Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(34.dp))
        }
        Spacer(Modifier.height(10.dp))
        Text(text, color = settingsMut(), fontSize = 14.sp, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
    }
}

/** A row of stars; tappable when [onChange] is given. */
@Composable
internal fun MvStars(value: Int, size: Int = 36, onChange: ((Int) -> Unit)? = null) {
    Row {
        (1..5).forEach { i ->
            Icon(
                if (i <= value) Icons.Rounded.Star else Icons.Rounded.StarBorder, null,
                tint = if (i <= value) AppColors.warning else settingsMut(),
                modifier = Modifier.size(size.dp).clip(CircleShape).then(if (onChange != null) Modifier.clickable { onChange(i) } else Modifier),
            )
        }
    }
}

/** Every moving sheet: Material's sheet in the app's language (a sheet is a window of its own), scrolling. */
@Composable
internal fun MvSheet(onClose: () -> Unit, content: @Composable ColumnScope.() -> Unit) {
    val locale = rememberAppLocaleFrame()
    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = settingsCard()) {
        locale.Provide {
            Column(
                Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 18.dp).navigationBarsPadding().imePadding(),
                verticalArrangement = Arrangement.spacedBy(10.dp),
                content = content,
            )
        }
    }
}

/**
 * The seconds left until a server time, on the device clock: nothing asks the server. [onEnd] runs once when a
 * countdown that was running reaches zero (the screen reloads once, the rides bidding way); a time already over when
 * the page opens stays quiet, so it can't reload in a loop.
 */
@Composable
internal fun rememberSecondsLeft(iso: String?, onEnd: (() -> Unit)? = null): Long? {
    val end by rememberUpdatedState(onEnd)
    val until = remember(iso) { parseTime(iso)?.toInstant() }
    var left by remember(iso) { mutableStateOf(until?.let { Duration.between(Instant.now(), it).seconds }) }
    LaunchedEffect(until) {
        if (until == null) return@LaunchedEffect
        var running = Duration.between(Instant.now(), until).seconds
        left = running
        if (running <= 0) return@LaunchedEffect
        while (running > 0) {
            delay(1000)
            running = Duration.between(Instant.now(), until).seconds
            left = running
        }
        end?.invoke()
    }
    return left
}

/** "02:15:09", with the days before it when there are any. */
@Composable
internal fun countdownText(seconds: Long): String {
    val s = seconds.coerceAtLeast(0)
    val clock = String.format(Locale.US, "%02d:%02d:%02d", (s % 86400) / 3600, (s % 3600) / 60, s % 60)
    return if (s >= 86400) stringResource(R.string.mv_days_and_clock, (s / 86400).toInt(), clock) else clock
}

/**
 * Live, with no timer (the owner's rule): one reload on open and on a reconnect or a pull, one on each `moving.changed`
 * of this request, and the truck moved from `moving.driver_location` itself. MovingHost keeps the private channel open.
 */
@Composable
internal fun MovingLiveEffect(uuid: String, onChanged: suspend () -> Unit, onDriver: ((Double, Double, Float?) -> Unit)? = null) {
    val changed by rememberUpdatedState(onChanged)
    val driver by rememberUpdatedState(onDriver)
    val tick = collectReconnectTick()
    LaunchedEffect(uuid, tick) { changed() }
    LaunchedEffect(uuid) {
        launch { ChatRealtime.connected.drop(1).collect { up -> if (up) changed() } }
        ChatRealtime.events.collect { e ->
            if (e.name == "moving.changed") {
                if (e.data.get("uuid")?.takeIf { it.isJsonPrimitive }?.asString == uuid) changed()
            } else if (e.name == "moving.driver_location") {
                val lat = e.data.get("lat")?.takeIf { it.isJsonPrimitive }?.asDouble
                val lng = e.data.get("lng")?.takeIf { it.isJsonPrimitive }?.asDouble
                val heading = e.data.get("heading")?.takeIf { it.isJsonPrimitive }?.asFloat
                if (lat != null && lng != null) driver?.invoke(lat, lng, heading)
            }
        }
    }
}

/** «وصلك N عرض» in correct Arabic: one, two, three to ten, and more take different words. */
@Composable
internal fun offersCountText(count: Int): String = when {
    count == 1 -> stringResource(R.string.mv_offers_count_one)
    count == 2 -> stringResource(R.string.mv_offers_count_two)
    count in 3..10 -> stringResource(R.string.mv_offers_count_few, count)
    else -> stringResource(R.string.mv_offers_count_many, count)
}

/** The status of a request in words, and its colour. */
@Composable
internal fun movingStatusLook(status: String?): Pair<String, Color> = when (status) {
    "draft" -> stringResource(R.string.mv_st_draft) to settingsMut()
    "open" -> stringResource(R.string.mv_st_open) to AppColors.warning
    "confirmed" -> stringResource(R.string.mv_st_confirmed) to AppColors.brandNavy
    "assigned" -> stringResource(R.string.mv_st_assigned) to AppColors.brandNavy
    "driver_on_way" -> stringResource(R.string.mv_st_driver_on_way) to AppColors.brandNavy
    "arrived" -> stringResource(R.string.mv_st_arrived) to AppColors.brandNavy
    "loading" -> stringResource(R.string.mv_st_loading) to AppColors.brandNavy
    "in_transit" -> stringResource(R.string.mv_st_in_transit) to AppColors.brandNavy
    "unloading" -> stringResource(R.string.mv_st_unloading) to AppColors.brandNavy
    "delivered" -> stringResource(R.string.mv_st_delivered) to AppColors.success
    "completed" -> stringResource(R.string.mv_st_completed) to AppColors.success
    "disputed" -> stringResource(R.string.mv_st_disputed) to AppColors.warning
    "expired" -> stringResource(R.string.mv_st_expired) to settingsMut()
    "withdrawn" -> stringResource(R.string.mv_st_withdrawn) to settingsMut()
    else -> stringResource(R.string.mv_st_cancelled) to AppColors.danger
}

@Composable
internal fun loadTypeLabel(type: String?): String = stringResource(
    when (type) {
        "single_piece" -> R.string.mv_load_single
        "commercial" -> R.string.mv_load_commercial
        else -> R.string.mv_load_home
    },
)

/** The name of a key of `extras` or of an offer's `includes`. */
@Composable
internal fun extraLabel(key: String): String = when (key) {
    "packing" -> stringResource(R.string.mv_extra_packing)
    "disassembly" -> stringResource(R.string.mv_extra_disassembly)
    "crane" -> stringResource(R.string.mv_extra_crane)
    "technician" -> stringResource(R.string.mv_extra_technician)
    "tax" -> stringResource(R.string.mv_extra_tax)
    else -> key
}
