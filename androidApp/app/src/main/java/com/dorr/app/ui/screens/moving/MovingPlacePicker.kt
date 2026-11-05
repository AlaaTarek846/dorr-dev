package com.dorr.app.ui.screens.moving

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Map
import androidx.compose.material.icons.rounded.MyLocation
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import com.dorr.app.R
import com.dorr.app.map.DorrMap
import com.dorr.app.map.rememberDorrCamera
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.rides.AccentButton
import com.dorr.app.ui.screens.rides.PanelCard
import com.dorr.app.ui.screens.rides.PickupPin
import com.dorr.app.ui.screens.rides.RidePoint
import com.dorr.app.ui.screens.rides.RoundButton
import com.dorr.app.ui.screens.rides.WheelColumn
import com.dorr.app.ui.screens.rides.addressOf
import com.dorr.app.ui.screens.rides.currentLocation
import com.dorr.app.ui.screens.rides.popularPlaces
import com.dorr.app.ui.screens.rides.rememberWhenLabels
import com.dorr.app.ui.screens.rides.searchPlaces
import com.dorr.app.ui.screens.rides.whenText
import com.dorr.app.ui.theme.AppColors
import com.google.android.gms.maps.model.LatLng
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale

private val Riyadh = LatLng(24.7136, 46.6753)

/**
 * Setting one end of the move, the rides' two ways: the search page (the same pill field, popular places, the server's
 * suggestions) and, from it, the map with the centre pin. Location is asked for but not required: without it the map
 * and the search still work.
 */
@Composable
internal fun MovingPlacePicker(title: String, start: RidePoint?, near: LatLng?, onClose: () -> Unit, onPick: (RidePoint) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var onMap by remember { mutableStateOf(false) }
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<RidePoint>>(emptyList()) }
    var popular by remember { mutableStateOf<List<RidePoint>>(emptyList()) }
    var searching by remember { mutableStateOf(false) }
    var me by remember { mutableStateOf<LatLng?>(near) }
    var hasLocation by remember { mutableStateOf(ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED) }
    val askLocation = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted -> hasLocation = granted.values.any { it } }
    val camera = rememberDorrCamera(start?.latLng ?: near ?: Riyadh, 15f)
    val focus = remember { FocusRequester() }
    var pinned by remember { mutableStateOf(start) }

    LaunchedEffect(Unit) {
        if (!hasLocation) askLocation.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
        runCatching { focus.requestFocus() }
    }
    LaunchedEffect(hasLocation) {
        if (hasLocation) {
            val here = currentLocation(context)
            if (here != null) {
                me = here
                if (start == null && near == null) camera.move(here, 16f)
            }
        }
        popular = popularPlaces(me)
    }
    LaunchedEffect(query) {
        if (query.trim().length < 2) { results = emptyList(); return@LaunchedEffect }
        delay(250)
        searching = true
        results = searchPlaces(context, query, me)
        searching = false
    }
    // The pin: when the map stops, the point under it is the place.
    LaunchedEffect(camera.isMoving, onMap) {
        if (onMap && !camera.isMoving) {
            val target = camera.target
            pinned = RidePoint(target.latitude, target.longitude, addressOf(context, target) ?: String.format(Locale.US, "%.5f, %.5f", target.latitude, target.longitude))
        }
    }

    BackHandler { if (onMap) { onMap = false } else { onClose() } }

    if (onMap) {
        Box(Modifier.fillMaxSize().background(settingsBackground())) {
            DorrMap(camera = camera, modifier = Modifier.fillMaxSize(), padding = PaddingValues(bottom = 260.dp))
            PickupPin(lifted = camera.isMoving, color = AppColors.brandOrange, label = stringResource(R.string.pk_pin_here))
            Box(Modifier.fillMaxWidth().statusBarsPadding().padding(16.dp)) {
                RoundButton(Icons.AutoMirrored.Rounded.ArrowBack, Modifier.align(Alignment.TopStart)) { onMap = false }
                me?.let { here ->
                    RoundButton(Icons.Rounded.MyLocation, Modifier.align(Alignment.TopEnd)) { scope.launch { camera.animate(here, 16f) } }
                }
            }
            Box(Modifier.align(Alignment.BottomCenter).fillMaxWidth()) {
                PanelCard {
                    Text(stringResource(R.string.rd_moving_pin), color = settingsMut(), fontSize = 12.sp)
                    Text(pinned?.address ?: "…", color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 16.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    Spacer(Modifier.height(14.dp))
                    AccentButton(stringResource(R.string.rd_confirm_point), enabled = pinned != null) { pinned?.let(onPick) }
                }
            }
        }
        return
    }

    Column(Modifier.fillMaxSize().background(settingsBackground()).statusBarsPadding().imePadding()) {
        Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            RoundButton(Icons.AutoMirrored.Rounded.ArrowBack, onClick = onClose)
            Spacer(Modifier.width(10.dp))
            Text(title, color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 20.sp)
        }
        DorrTextField(
            value = query, onValueChange = { query = it },
            placeholder = stringResource(R.string.rd_search_hint), icon = Icons.Rounded.Search,
            trailing = if (searching) ({ CircularProgressIndicator(strokeWidth = 2.dp, color = settingsAccent(), modifier = Modifier.size(18.dp)) }) else null,
            focusRequester = focus, minHeight = 52.dp,
            modifier = Modifier.padding(horizontal = 16.dp).fillMaxWidth(),
        )
        LazyColumn(Modifier.weight(1f).padding(horizontal = 16.dp, vertical = 8.dp)) {
            item {
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable {
                        onMap = true
                        (start?.latLng ?: me)?.let { scope.launch { camera.animate(it, 16f) } }
                    }.padding(vertical = 12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(Icons.Rounded.Map, null, tint = settingsAccent())
                    Spacer(Modifier.width(10.dp))
                    Text(stringResource(R.string.rd_pick_on_map), color = settingsAccent(), fontWeight = FontWeight.SemiBold)
                }
            }
            val typed = query.trim().length >= 2
            if (typed && !searching && results.isEmpty()) item {
                Text(stringResource(R.string.rd_no_results), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.padding(vertical = 8.dp))
            }
            if (!typed && popular.isNotEmpty()) item {
                Text(stringResource(R.string.rd_popular_near), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.padding(top = 10.dp, bottom = 4.dp))
            }
            items(if (typed) results else popular) { place ->
                Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).clickable { onPick(place) }.padding(vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(38.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                        Icon(if (typed) Icons.Rounded.LocationOn else Icons.Rounded.Star, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
                    }
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text(place.name ?: place.address, color = settingsInk(), fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        if (place.name != null && place.name != place.address) Text(place.address, color = settingsMut(), fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
        }
    }
}

private const val MINUTE_STEP = 5L

/** Picking the day and the time: the rides' day strip and rolling hour and minutes. The server checks it is still ahead. */
@Composable
internal fun MovingTimeSheet(initial: ZonedDateTime?, title: String, onClose: () -> Unit, onPick: (ZonedDateTime) -> Unit) {
    val first = remember {
        val t = ZonedDateTime.now().plusMinutes(MINUTE_STEP).withSecond(0).withNano(0)
        t.plusMinutes((MINUTE_STEP - t.minute % MINUTE_STEP) % MINUTE_STEP)
    }
    val last = remember { first.plusDays(90) }
    var picked by remember { mutableStateOf(initial?.takeIf { it >= first && it <= last } ?: first) }
    val days = remember { generateSequence(first.toLocalDate()) { it.plusDays(1) }.takeWhile { !it.isAfter(last.toLocalDate()) }.toList() }
    val (today, tomorrow) = rememberWhenLabels()
    val locale = Locale.getDefault()

    fun clamp(t: ZonedDateTime): ZonedDateTime = when {
        t.isBefore(first) -> first
        t.isAfter(last) -> last
        else -> t
    }

    MvSheet(onClose) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(40.dp).clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Event, null, tint = settingsAccent())
            }
            Spacer(Modifier.width(10.dp))
            Text(title, color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 19.sp)
        }
        Spacer(Modifier.height(6.dp))
        LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            items(days) { day ->
                val on = picked.toLocalDate() == day
                val bg by animateColorAsState(if (on) settingsAccent() else settingsBackground(), tween(220), label = "mv-day")
                val scale by animateFloatAsState(if (on) 1.06f else 1f, spring(dampingRatio = Spring.DampingRatioMediumBouncy), label = "mv-day-scale")
                Column(
                    Modifier.scale(scale).width(64.dp).clip(RoundedCornerShape(16.dp)).background(bg)
                        .clickable { picked = clamp(ZonedDateTime.of(day, picked.toLocalTime(), picked.zone)) }.padding(vertical = 10.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    val top = when (day) {
                        LocalDate.now() -> today
                        LocalDate.now().plusDays(1) -> tomorrow
                        else -> day.dayOfWeek.getDisplayName(java.time.format.TextStyle.SHORT, locale)
                    }
                    Text(top, color = if (on) Color.White.copy(alpha = 0.85f) else settingsMut(), fontSize = 11.sp, maxLines = 1)
                    Text(day.dayOfMonth.toString(), color = if (on) Color.White else settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
                    Text(day.month.getDisplayName(java.time.format.TextStyle.SHORT, locale), color = if (on) Color.White.copy(alpha = 0.85f) else settingsMut(), fontSize = 11.sp)
                }
            }
        }
        Spacer(Modifier.height(6.dp))
        Text(stringResource(R.string.mv_time_label), color = settingsMut(), fontSize = 12.sp, modifier = Modifier.fillMaxWidth(), textAlign = TextAlign.Center)
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically) {
            WheelColumn(picked.format(DateTimeFormatter.ofPattern("HH")), onUp = { picked = clamp(picked.plusHours(1)) }, onDown = { picked = clamp(picked.minusHours(1)) })
            Text(":", color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 40.sp, modifier = Modifier.padding(horizontal = 6.dp))
            WheelColumn(picked.format(DateTimeFormatter.ofPattern("mm")), onUp = { picked = clamp(picked.plusMinutes(MINUTE_STEP)) }, onDown = { picked = clamp(picked.minusMinutes(MINUTE_STEP)) })
        }
        Text(whenText(picked, today, tomorrow), color = settingsAccent(), fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.fillMaxWidth(), textAlign = TextAlign.Center)
        Spacer(Modifier.height(4.dp))
        AccentButton(stringResource(R.string.mv_confirm_time)) { onPick(picked) }
    }
}
