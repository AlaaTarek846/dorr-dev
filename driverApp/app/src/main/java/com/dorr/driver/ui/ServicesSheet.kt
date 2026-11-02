package com.dorr.driver.ui

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.MutableTransitionState
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.slideInVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Apps
import androidx.compose.material.icons.rounded.DirectionsBoat
import androidx.compose.material.icons.rounded.DirectionsBus
import androidx.compose.material.icons.rounded.DirectionsCar
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.Flight
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.PersonSearch
import androidx.compose.material.icons.rounded.Restaurant
import androidx.compose.material.icons.rounded.School
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Timer
import androidx.compose.material.icons.rounded.TwoWheeler
import androidx.compose.material.icons.rounded.Sailing
import androidx.compose.material.icons.rounded.AirportShuttle
import androidx.compose.material.icons.rounded.PersonOutline
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
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.WorkServiceDto
import com.dorr.driver.net.WorkServicesDto
import com.dorr.driver.ui.kit.DorrToggle
import com.dorr.driver.ui.theme.Pal
import com.google.gson.JsonObject
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * O-89 (owner): «Services» — what the captain's vehicle can work in where they are (set from the dashboard
 * per country and kind of vehicle), each with its switch. Opened from the home screen's services button.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ServicesSheet(onClose: () -> Unit, onChanged: (WorkServicesDto) -> Unit = {}) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var data by remember { mutableStateOf<WorkServicesDto?>(null) }
    var failed by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) {
        when (val r = apiCall { DriverBackend.api.workServices() }) {
            is ApiResult.Ok -> data = r.data
            else -> failed = true
        }
    }

    fun toggle(s: WorkServiceDto, on: Boolean) {
        val before = data ?: return
        data = before.copy(services = before.services.map { if (it.key == s.key) it.copy(on = on) else it })
        error = null
        scope.launch {
            when (val r = apiCall { DriverBackend.api.chooseWorkService(s.key, JsonObject().apply { addProperty("on", on) }) }) {
                is ApiResult.Ok -> { data = r.data; onChanged(r.data) }
                is ApiResult.Failed -> { data = before; error = r.error.displayMessage }
                is ApiResult.Offline -> { data = before; error = context.getString(R.string.error_offline) }
            }
        }
    }

    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Pal.card) {
        Column(Modifier.fillMaxWidth().heightIn(max = 640.dp).verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 22.dp).navigationBarsPadding()) {
            Header(data?.kind)
            Spacer(Modifier.height(14.dp))
            val d = data
            when {
                d == null && !failed -> Box(Modifier.fillMaxWidth().height(180.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Pal.accent) }
                d == null -> Text(stringResource(R.string.error_generic), color = Pal.mut, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(24.dp))
                d.services.isEmpty() -> Text(stringResource(R.string.sv_none), color = Pal.mut, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(24.dp))
                else -> {
                    d.services.forEachIndexed { i, s -> ServiceCard(s, i) { on -> toggle(s, on) } }
                    Spacer(Modifier.height(6.dp))
                    val active = d.services.count { it.on }
                    Text(
                        stringResource(R.string.sv_count, active, d.services.count { !it.comingSoon }), color = Pal.mut, fontSize = 12.sp,
                        textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth(),
                    )
                }
            }
            error?.let { Text(it, color = Pal.danger, fontSize = 12.5.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(top = 8.dp)) }
        }
    }
}

@Composable
private fun Header(kind: String?) {
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(24.dp))
            .background(Brush.linearGradient(listOf(Pal.accent, Pal.accent.copy(alpha = 0.72f))))
            .padding(18.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(44.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.18f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Apps, null, tint = Color.White, modifier = Modifier.size(24.dp))
            }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(stringResource(R.string.sv_title), color = Color.White, fontWeight = FontWeight.Black, fontSize = 20.sp)
                Text(stringResource(R.string.sv_hint), color = Color.White.copy(alpha = 0.85f), fontSize = 12.5.sp)
            }
        }
        kind?.let {
            Spacer(Modifier.height(12.dp))
            Row(
                Modifier.clip(CircleShape).background(Color.White.copy(alpha = 0.18f)).padding(horizontal = 12.dp, vertical = 6.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(kindIcon(it), null, tint = Color.White, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(R.string.sv_vehicle, kindLabel(it)), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 12.5.sp)
            }
        }
    }
}

/** One service: its drawing, name and line, and the switch — sliding in one after the other. */
@Composable
private fun ServiceCard(s: WorkServiceDto, index: Int, onToggle: (Boolean) -> Unit) {
    val tint = runCatching { Color(android.graphics.Color.parseColor(s.color)) }.getOrDefault(Pal.accent)
    val shown = remember { MutableTransitionState(false) }
    LaunchedEffect(Unit) { delay(60L * index); shown.targetState = true }
    val border by animateColorAsState(if (s.on) tint.copy(alpha = 0.55f) else Pal.line, tween(250), label = "svc-border")
    val bg by animateColorAsState(if (s.on) tint.copy(alpha = 0.07f) else Pal.card, tween(250), label = "svc-bg")
    val pop by animateFloatAsState(if (s.on) 1.12f else 1f, spring(dampingRatio = Spring.DampingRatioMediumBouncy), label = "svc-pop")
    AnimatedVisibility(shown, enter = fadeIn(tween(260)) + slideInVertically(tween(320, easing = FastOutSlowInEasing)) { it / 3 }) {
        Row(
            Modifier.fillMaxWidth().padding(vertical = 5.dp).clip(RoundedCornerShape(20.dp)).background(bg)
                .border(1.5.dp, border, RoundedCornerShape(20.dp)).padding(14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                Modifier.size(48.dp).scale(pop).clip(RoundedCornerShape(16.dp))
                    .background(Brush.linearGradient(listOf(tint, tint.copy(alpha = 0.65f)))),
                contentAlignment = Alignment.Center,
            ) { Icon(serviceIcon(s.icon), null, tint = Color.White, modifier = Modifier.size(26.dp)) }
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(s.name.orEmpty(), color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
                s.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = Pal.mut, fontSize = 12.sp, lineHeight = 16.sp) }
            }
            Spacer(Modifier.width(8.dp))
            if (s.comingSoon) {
                Text(
                    stringResource(R.string.sv_soon), color = tint, fontWeight = FontWeight.Bold, fontSize = 11.5.sp,
                    modifier = Modifier.clip(CircleShape).background(tint.copy(alpha = 0.12f)).padding(horizontal = 10.dp, vertical = 5.dp),
                )
            } else {
                DorrToggle(s.on, onToggle)
            }
        }
    }
}

internal fun serviceIcon(key: String?): ImageVector = when (key) {
    "car" -> Icons.Rounded.DirectionsCar
    "timer" -> Icons.Rounded.Timer
    "calendar" -> Icons.Rounded.Event
    "group" -> Icons.Rounded.Groups
    "flight" -> Icons.Rounded.Flight
    "bus" -> Icons.Rounded.DirectionsBus
    "boat" -> Icons.Rounded.DirectionsBoat
    "person" -> Icons.Rounded.PersonSearch
    "school" -> Icons.Rounded.School
    "package" -> Icons.Rounded.Inventory2
    "restaurant" -> Icons.Rounded.Restaurant
    "moto" -> Icons.Rounded.TwoWheeler
    "star" -> Icons.Rounded.Star
    else -> Icons.Rounded.Apps
}

private fun kindIcon(kind: String): ImageVector = when (kind) {
    "motorcycle" -> Icons.Rounded.TwoWheeler
    "minibus" -> Icons.Rounded.AirportShuttle
    "bus" -> Icons.Rounded.DirectionsBus
    "boat" -> Icons.Rounded.DirectionsBoat
    "yacht" -> Icons.Rounded.Sailing
    "none" -> Icons.Rounded.PersonOutline
    else -> Icons.Rounded.DirectionsCar
}

@Composable
private fun kindLabel(kind: String): String = stringResource(
    when (kind) {
        "motorcycle" -> R.string.sv_kind_motorcycle
        "minibus" -> R.string.sv_kind_minibus
        "bus" -> R.string.sv_kind_bus
        "boat" -> R.string.sv_kind_boat
        "yacht" -> R.string.sv_kind_yacht
        "none" -> R.string.sv_kind_none
        else -> R.string.sv_kind_car
    },
)
