package com.dorr.driver.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Radar
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.DispatchCheckDto
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.TileIcon
import com.dorr.driver.ui.theme.Pal

/*
 * «Why don't requests reach me?» (docs/rides_tasks.md O-47): every condition the server checks before
 * sending a captain a request, each passed (✓) or not (✗) with what to do about it — read once when opened.
 */

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DispatchCheckSheet(onClose: () -> Unit) {
    val context = LocalContext.current
    var check by remember { mutableStateOf<DispatchCheckDto?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) {
        when (val r = apiCall { DriverBackend.api.dispatchCheck() }) {
            is ApiResult.Ok -> check = r.data
            is ApiResult.Failed -> error = r.error.displayMessage
            else -> error = context.getString(R.string.error_offline)
        }
    }
    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Pal.card) {
        Column(Modifier.navigationBarsPadding().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 20.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                TileIcon(Icons.Rounded.Radar, Pal.accent, 44.dp)
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.dc_title), color = Pal.ink, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
                    check?.let {
                        Text(stringResource(if (it.ready) R.string.dc_ready else R.string.dc_not_ready), color = if (it.ready) Pal.success else Pal.danger, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }
            Spacer(Modifier.height(14.dp))
            Banner(error)
            val c = check
            if (c == null && error == null) Box(Modifier.fillMaxWidth().padding(30.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Pal.accent) }
            c?.checks?.forEach { item ->
                Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.Top) {
                    Box(Modifier.size(26.dp).clip(CircleShape).background(if (item.ok) Pal.success.copy(alpha = 0.14f) else Pal.danger.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                        Icon(if (item.ok) Icons.Rounded.Check else Icons.Rounded.Close, null, tint = if (item.ok) Pal.success else Pal.danger, modifier = Modifier.size(16.dp))
                    }
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text(checkTitle(item.key), color = Pal.ink, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
                        if (!item.ok) checkFix(item.key)?.let { Text(it, color = Pal.mut, fontSize = 12.sp) }
                        // The categories: which ones reach me, and why not the others.
                        if (item.key == "categories") item.categories.forEach { cat ->
                            Text((if (cat.ok) "✓ " else "✗ ") + (cat.name ?: cat.slug) + (cat.reason?.let { " — " + categoryReason(it) } ?: ""), color = if (cat.ok) Pal.success else Pal.mut, fontSize = 12.sp)
                        }
                    }
                }
            }
            if (c != null) {
                Text(
                    stringResource(R.string.dc_same_phone_tip), color = Pal.mut, fontSize = 12.sp,
                    modifier = Modifier.padding(top = 12.dp).fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(Pal.warning.copy(alpha = 0.1f)).padding(12.dp),
                )
            }
        }
    }
}

@Composable
private fun checkTitle(key: String): String = stringResource(
    when (key) {
        "country" -> R.string.dc_country
        "service_on" -> R.string.dc_service_on
        "account" -> R.string.dc_account
        "online" -> R.string.dc_online
        "gps" -> R.string.dc_gps
        "location" -> R.string.dc_location
        "location_fresh" -> R.string.dc_location_fresh
        "categories" -> R.string.dc_categories
        "free" -> R.string.dc_free
        "offers_room" -> R.string.dc_offers_room
        "balance_for_cash" -> R.string.dc_balance
        "pickup_reach" -> R.string.dc_reach
        "server_dispatch_tick" -> R.string.dc_server_tick
        "server_realtime" -> R.string.dc_server_realtime
        "server_push" -> R.string.dc_server_push
        else -> R.string.dc_other
    },
)

@Composable
private fun checkFix(key: String): String? = when (key) {
    "account" -> stringResource(R.string.dc_account_fix)
    "online" -> stringResource(R.string.dc_online_fix)
    "gps", "location", "location_fresh" -> stringResource(R.string.dc_location_fix)
    "categories" -> stringResource(R.string.dc_categories_fix)
    "balance_for_cash" -> stringResource(R.string.dc_balance_fix)
    "server_dispatch_tick", "server_realtime", "server_push" -> stringResource(R.string.dc_server_fix)
    else -> null
}

@Composable
private fun categoryReason(reason: String): String = when (reason) {
    "switched_off_by_you" -> stringResource(R.string.dc_cat_off)
    "vehicle_body_type" -> stringResource(R.string.dc_cat_body)
    "vehicle_too_old" -> stringResource(R.string.dc_cat_old)
    "vehicle_seats" -> stringResource(R.string.dc_cat_seats)
    "driver_gender" -> stringResource(R.string.dc_cat_gender)
    // O-89: the dashboard didn't open its service to this vehicle here / the captain switched it off.
    "vehicle_kind" -> stringResource(R.string.dc_cat_kind)
    "service_switched_off" -> stringResource(R.string.dc_cat_service_off)
    else -> reason
}
