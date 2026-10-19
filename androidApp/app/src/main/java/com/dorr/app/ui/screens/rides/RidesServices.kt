package com.dorr.app.ui.screens.rides

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.offset
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
import androidx.compose.material.icons.rounded.DirectionsBoat
import androidx.compose.material.icons.rounded.DirectionsBus
import androidx.compose.material.icons.rounded.EventNote
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.LocalTaxi
import androidx.compose.material.icons.rounded.PersonSearch
import androidx.compose.material.icons.rounded.RestaurantMenu
import androidx.compose.material.icons.rounded.School
import androidx.compose.material.icons.rounded.TwoWheeler
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.delay

/** What the rides service offers (O-18). `ready` = it opens now; the others say «coming soon». */
internal enum class RideService(val icon: ImageVector, val title: Int, val hint: Int, val from: Color, val to: Color) {
    Instant(Icons.Rounded.LocalTaxi, R.string.svc_instant, R.string.svc_instant_hint, Color(0xFF001B53), Color(0xFF2B4C9B)),
    Bus(Icons.Rounded.DirectionsBus, R.string.svc_bus, R.string.svc_bus_hint, Color(0xFF3949AB), Color(0xFF7986CB)),
    Marine(Icons.Rounded.DirectionsBoat, R.string.svc_marine, R.string.svc_marine_hint, Color(0xFF0277BD), Color(0xFF26C6DA)),
    Parcels(Icons.Rounded.Inventory2, R.string.svc_parcels, R.string.svc_parcels_hint, Color(0xFFEF6C00), Color(0xFFFFB74D)),
    Food(Icons.Rounded.RestaurantMenu, R.string.svc_food, R.string.svc_food_hint, Color(0xFFC62828), Color(0xFFFF7043)),
    FindDriver(Icons.Rounded.PersonSearch, R.string.svc_find_driver, R.string.svc_find_driver_hint, Color(0xFF00695C), Color(0xFF4DB6AC)),
    Lessons(Icons.Rounded.School, R.string.svc_lessons, R.string.svc_lessons_hint, Color(0xFF6A1B9A), Color(0xFFBA68C8)),
}

/**
 * The first screen of the rides service (O-18): every way DORR moves you, as big living cards — a gradient
 * each, the icon floating in its glow, the cards landing one after another. What isn't open yet carries a
 * shimmering «coming soon» and answers with a friendly note instead of a page.
 */
@Composable
internal fun ServicePicker(ready: Set<RideService>, onPick: (RideService) -> Unit, onBack: () -> Unit, onMyRides: () -> Unit) {
    val accent = settingsAccent()
    var soon by remember { mutableStateOf<RideService?>(null) }
    LaunchedEffect(soon) { if (soon != null) { delay(2400); soon = null } }

    Box(Modifier.fillMaxSize().background(settingsBackground())) {
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState())) {
            // The header: a deep gradient with drifting rings and the cars of the city passing behind the title.
            val drift = rememberInfiniteTransition(label = "svc-drift")
            val ring by drift.animateFloat(0f, 1f, infiniteRepeatable(tween(5200, easing = LinearEasing)), label = "ring")
            val road by drift.animateFloat(0f, 1f, infiniteRepeatable(tween(3800, easing = LinearEasing)), label = "road")
            Box(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(bottomStart = 36.dp, bottomEnd = 36.dp))
                    .background(Brush.linearGradient(listOf(accent, Color(0xFF2B4C9B), AppColors.brandOrange.copy(alpha = 0.9f)), start = Offset.Zero, end = Offset(1200f, 900f))),
            ) {
                Canvas(Modifier.matchParentSize()) {
                    val c = Offset(size.width * 0.85f, size.height * 0.2f)
                    for (i in 0..2) {
                        val p = (ring + i / 3f) % 1f
                        drawCircle(Color.White.copy(alpha = (1f - p) * 0.14f), radius = size.width * (0.15f + p * 0.6f), center = c)
                    }
                    // The road: dashes sliding along the bottom edge.
                    val y = size.height - 26f
                    var x = -80f + road * 80f
                    while (x < size.width) {
                        drawRoundRect(Color.White.copy(alpha = 0.22f), topLeft = Offset(x, y), size = androidx.compose.ui.geometry.Size(44f, 6f), cornerRadius = androidx.compose.ui.geometry.CornerRadius(3f, 3f))
                        x += 80f
                    }
                }
                Column(Modifier.statusBarsPadding().padding(horizontal = 18.dp).padding(top = 10.dp, bottom = 34.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        RoundGlass(Icons.AutoMirrored.Rounded.ArrowBack, onBack)
                        Spacer(Modifier.weight(1f))
                        RoundGlass(Icons.Rounded.EventNote, onMyRides)
                    }
                    Spacer(Modifier.height(18.dp))
                    var shown by remember { mutableStateOf(false) }
                    LaunchedEffect(Unit) { shown = true }
                    AnimatedVisibility(shown, enter = slideInVertically(tween(520, easing = FastOutSlowInEasing)) { it / 2 } + fadeIn(tween(520))) {
                        Column {
                            Text(stringResource(R.string.svc_hello), color = Color.White.copy(alpha = 0.85f), fontSize = 14.sp)
                            Text(stringResource(R.string.svc_title), color = Color.White, fontWeight = FontWeight.Black, fontSize = 28.sp, lineHeight = 34.sp)
                        }
                    }
                    Spacer(Modifier.height(16.dp))
                    // The hero card: the everyday ride, big and first.
                    HeroService(RideService.Instant, RideService.Instant in ready) { if (RideService.Instant in ready) onPick(RideService.Instant) else soon = RideService.Instant }
                }
            }

            Text(stringResource(R.string.svc_more), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 18.sp, modifier = Modifier.padding(start = 20.dp, end = 20.dp, top = 20.dp, bottom = 10.dp))
            val rest = RideService.entries.filter { it != RideService.Instant }
            rest.chunked(2).forEachIndexed { row, pair ->
                Row(Modifier.fillMaxWidth().padding(horizontal = 14.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    pair.forEachIndexed { col, svc ->
                        Box(Modifier.weight(1f).padding(vertical = 6.dp)) {
                            ServiceCard(svc, svc in ready, index = row * 2 + col) { if (svc in ready) onPick(svc) else soon = svc }
                        }
                    }
                    if (pair.size == 1) Spacer(Modifier.weight(1f))
                }
            }
            Spacer(Modifier.navigationBarsPadding().height(24.dp))
        }

        // «Coming soon»: a friendly card rising from the bottom, gone by itself.
        AnimatedVisibility(
            visible = soon != null, modifier = Modifier.align(Alignment.BottomCenter),
            enter = slideInVertically(spring(dampingRatio = 0.7f)) { it } + fadeIn(), exit = slideOutVertically { it } + fadeOut(),
        ) {
            val s = soon ?: RideService.Parcels
            Row(
                Modifier.navigationBarsPadding().padding(16.dp).fillMaxWidth().shadow(18.dp, RoundedCornerShape(22.dp))
                    .clip(RoundedCornerShape(22.dp)).background(Brush.linearGradient(listOf(s.from, s.to))).padding(16.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(s.icon, null, tint = Color.White, modifier = Modifier.size(34.dp))
                Spacer(Modifier.width(12.dp))
                Column {
                    Text(stringResource(R.string.svc_soon_title, stringResource(s.title)), color = Color.White, fontWeight = FontWeight.Black, fontSize = 15.sp)
                    Text(stringResource(R.string.svc_soon_body), color = Color.White.copy(alpha = 0.9f), fontSize = 12.sp)
                }
            }
        }
    }
}

@Composable
private fun HeroService(svc: RideService, ready: Boolean, onClick: () -> Unit) {
    val float = rememberInfiniteTransition(label = "hero")
    val bob by float.animateFloat(-4f, 4f, infiniteRepeatable(tween(1600, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "bob")
    Row(
        Modifier.fillMaxWidth().shadow(20.dp, RoundedCornerShape(26.dp), spotColor = Color.Black.copy(alpha = 0.3f)).clip(RoundedCornerShape(26.dp))
            .background(Color.White).pressable(onClick = onClick).padding(16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text(stringResource(svc.title), color = svc.from, fontWeight = FontWeight.Black, fontSize = 20.sp)
            Text(stringResource(svc.hint), color = Color(0xFF6B7280), fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
            Spacer(Modifier.height(10.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                listOf(R.string.svc_tag_economy, R.string.svc_tag_vip, R.string.svc_tag_family, R.string.svc_tag_moto).forEach { tag ->
                    Text(stringResource(tag), color = svc.from, fontSize = 10.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(CircleShape).background(svc.from.copy(alpha = 0.08f)).padding(horizontal = 8.dp, vertical = 3.dp))
                }
            }
            if (!ready) SoonBadge(Modifier.padding(top = 8.dp))
        }
        Box(Modifier.size(86.dp).offset(y = bob.dp), contentAlignment = Alignment.Center) {
            Box(Modifier.size(80.dp).clip(CircleShape).background(Brush.radialGradient(listOf(svc.to.copy(alpha = 0.35f), Color.Transparent))))
            Box(Modifier.size(62.dp).clip(CircleShape).background(Brush.linearGradient(listOf(svc.from, svc.to))), contentAlignment = Alignment.Center) {
                Icon(svc.icon, null, tint = Color.White, modifier = Modifier.size(34.dp))
            }
            Icon(Icons.Rounded.TwoWheeler, null, tint = svc.to, modifier = Modifier.size(22.dp).align(Alignment.BottomEnd))
        }
    }
}

/** One service: its gradient, its icon floating in a glow, a gentle tilt when pressed; lands with a spring. */
@Composable
private fun ServiceCard(svc: RideService, ready: Boolean, index: Int, onClick: () -> Unit) {
    var landed by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { delay(90L * index + 120); landed = true }
    val land by animateFloatAsState(if (landed) 1f else 0f, spring(dampingRatio = 0.62f, stiffness = Spring.StiffnessLow), label = "land")
    val float = rememberInfiniteTransition(label = "svc-$index")
    val bob by float.animateFloat(-3f, 3f, infiniteRepeatable(tween(1500 + index * 130, easing = FastOutSlowInEasing), RepeatMode.Reverse), label = "bob")
    Box(
        Modifier.fillMaxWidth().aspectRatio(0.92f)
            .graphicsLayer { alpha = land; scaleX = 0.85f + land * 0.15f; scaleY = 0.85f + land * 0.15f; translationY = (1f - land) * 60f }
            .shadow(12.dp, RoundedCornerShape(24.dp), spotColor = svc.from.copy(alpha = 0.35f)).clip(RoundedCornerShape(24.dp))
            .background(Brush.linearGradient(listOf(svc.from, svc.to), start = Offset.Zero, end = Offset(500f, 600f)))
            .pressable(onClick = onClick).padding(14.dp),
    ) {
        // A soft circle in the corner, like light on the card.
        Box(Modifier.size(120.dp).align(Alignment.TopEnd).offset(x = 40.dp, y = (-40).dp).clip(CircleShape).background(Color.White.copy(alpha = 0.1f)))
        Column(Modifier.fillMaxSize()) {
            Box(Modifier.size(54.dp).offset(y = bob.dp).clip(RoundedCornerShape(18.dp)).background(Color.White.copy(alpha = 0.2f)), contentAlignment = Alignment.Center) {
                Icon(svc.icon, null, tint = Color.White, modifier = Modifier.size(30.dp))
            }
            Spacer(Modifier.weight(1f))
            Text(stringResource(svc.title), color = Color.White, fontWeight = FontWeight.Black, fontSize = 16.sp)
            Text(stringResource(svc.hint), color = Color.White.copy(alpha = 0.85f), fontSize = 11.sp, lineHeight = 14.sp, maxLines = 2)
        }
        if (!ready) SoonBadge(Modifier.align(Alignment.TopEnd))
    }
}

/** «Coming soon», with a light sweeping across it. */
@Composable
private fun SoonBadge(modifier: Modifier) {
    val sweep = rememberInfiniteTransition(label = "soon")
    val x by sweep.animateFloat(-1f, 2f, infiniteRepeatable(tween(1800, easing = LinearEasing)), label = "sweep")
    Box(
        modifier.clip(CircleShape)
            .background(Brush.linearGradient(listOf(Color(0xFFFFC107), Color(0xFFFFF59D), Color(0xFFFFC107)), start = Offset(x * 120f, 0f), end = Offset(x * 120f + 120f, 0f)))
            .padding(horizontal = 9.dp, vertical = 3.dp),
    ) { Text(stringResource(R.string.svc_soon), color = Color(0xFF5D4037), fontSize = 10.sp, fontWeight = FontWeight.Black) }
}

@Composable
private fun RoundGlass(icon: ImageVector, onClick: () -> Unit) {
    Box(Modifier.size(40.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.18f)).pressable(onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, null, tint = Color.White, modifier = Modifier.size(20.dp))
    }
}

