package com.dorr.app.ui.components

import android.widget.Toast
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AdminPanelSettings
import androidx.compose.material.icons.rounded.Apps
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Build
import androidx.compose.material.icons.rounded.Cake
import androidx.compose.material.icons.rounded.Calculate
import androidx.compose.material.icons.rounded.Chat
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.CleaningServices
import androidx.compose.material.icons.rounded.CloudOff
import androidx.compose.material.icons.rounded.ContentCut
import androidx.compose.material.icons.rounded.DirectionsCar
import androidx.compose.material.icons.rounded.ElectricalServices
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material.icons.rounded.Fastfood
import androidx.compose.material.icons.rounded.Flight
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.Hotel
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material.icons.rounded.LocalGasStation
import androidx.compose.material.icons.rounded.LocalShipping
import androidx.compose.material.icons.rounded.LocalTaxi
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.MedicalServices
import androidx.compose.material.icons.rounded.Palette
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Print
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.Restaurant
import androidx.compose.material.icons.rounded.RestaurantMenu
import androidx.compose.material.icons.rounded.School
import androidx.compose.material.icons.rounded.SportsSoccer
import androidx.compose.material.icons.rounded.Store
import androidx.compose.material.icons.rounded.WaterDrop
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.ImageLoader
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ServiceChildDto
import com.dorr.app.network.ServiceDto
import com.dorr.app.ui.theme.AppColors

private const val COLLAPSED_COUNT = 6
private const val COLUMNS = 3

private var imageLoader: ImageLoader? = null

// One loader (and disk/memory cache) for every tile. It reuses the API client so
// media requests carry the same dev Host header.
private fun sharedImageLoader(context: android.content.Context): ImageLoader =
    imageLoader ?: ImageLoader.Builder(context.applicationContext)
        .okHttpClient(ApiClient.okHttpClient)
        .build()
        .also { imageLoader = it }

internal sealed interface ServicesState {
    data object Loading : ServicesState
    data object Error : ServicesState
    data class Loaded(val services: List<ServiceDto>) : ServicesState
}

// Cycled by position so neighbouring tiles never share a colour.
internal val palette = listOf(
    AppColors.primary,
    AppColors.accent,
    AppColors.info,
    AppColors.secondary,
    Color(0xFF8B5CF6),
    AppColors.warning,
    Color(0xFF3B82F6),
    AppColors.danger,
)

internal fun serviceIcon(moduleName: String?): ImageVector = when (moduleName) {
    "system_users" -> Icons.Rounded.Person
    "admin" -> Icons.Rounded.AdminPanelSettings
    "admin_permission" -> Icons.Rounded.Lock
    "chat" -> Icons.Rounded.Chat
    "passenger_ride" -> Icons.Rounded.LocalTaxi
    "car_rental" -> Icons.Rounded.DirectionsCar
    "driving_lessons" -> Icons.Rounded.School
    "driver_without_vehicle" -> Icons.Rounded.Person
    "restaurants" -> Icons.Rounded.Restaurant
    "parcels" -> Icons.Rounded.Inventory2
    "moving" -> Icons.Rounded.LocalShipping
    "water_gas" -> Icons.Rounded.WaterDrop
    "fuel" -> Icons.Rounded.LocalGasStation
    "mechanic" -> Icons.Rounded.Build
    "electrician" -> Icons.Rounded.ElectricalServices
    "car_wash", "cleaning" -> Icons.Rounded.CleaningServices
    "printing" -> Icons.Rounded.Print
    "designers" -> Icons.Rounded.Palette
    "stores" -> Icons.Rounded.Store
    "accounting_system" -> Icons.Rounded.Calculate
    "medical_supplies" -> Icons.Rounded.MedicalServices
    "hotels_flights" -> Icons.Rounded.Flight
    "umrah_hajj_trips" -> Icons.Rounded.Public
    "furnished_apartments" -> Icons.Rounded.Home
    "chalets_resorts" -> Icons.Rounded.Hotel
    "salons" -> Icons.Rounded.ContentCut
    "home_meals" -> Icons.Rounded.Fastfood
    "private_chef" -> Icons.Rounded.RestaurantMenu
    "event_catering" -> Icons.Rounded.Cake
    "sports_venues" -> Icons.Rounded.SportsSoccer
    "events" -> Icons.Rounded.Event
    "freelance" -> Icons.Rounded.Work
    "ai_assistant" -> Icons.Rounded.AutoAwesome
    else -> Icons.Rounded.Apps
}

/** Loads the dashboard services; [reload] re-runs the request (used by the retry buttons). */
internal class ServicesLoader(val state: ServicesState, val reload: () -> Unit)

@Composable
internal fun rememberServicesLoader(): ServicesLoader {
    var reloadKey by remember { mutableIntStateOf(0) }
    var state by remember { mutableStateOf<ServicesState>(ServicesState.Loading) }

    LaunchedEffect(reloadKey) {
        state = ServicesState.Loading
        state = runCatching { ApiClient.services.list().data.orEmpty() }
            .fold({ ServicesState.Loaded(it) }, { ServicesState.Error })
    }
    return ServicesLoader(state) { reloadKey++ }
}

/** Home preview of the dashboard services: the first few, with "view all" opening the full page. */
@Composable
fun ServicesSection(onViewAll: () -> Unit, modifier: Modifier = Modifier) {
    val loader = rememberServicesLoader()
    var opened by remember { mutableStateOf<Pair<ServiceDto, Color>?>(null) }
    val context = LocalContext.current
    val comingSoon = stringResource(R.string.services_coming_soon)

    Column(modifier = modifier) {
        SectionHeader(
            total = (loader.state as? ServicesState.Loaded)?.services?.size ?: 0,
            onViewAll = onViewAll,
        )
        Spacer(Modifier.height(12.dp))

        when (val s = loader.state) {
            ServicesState.Loading -> ServicesSkeleton()
            ServicesState.Error -> ServicesError(onRetry = loader.reload)
            is ServicesState.Loaded -> if (s.services.isNotEmpty()) {
                ServiceGrid(
                    services = s.services.take(COLLAPSED_COUNT),
                    onClick = { service, color ->
                        if (service.hasChildren == true) {
                            opened = service to color
                        } else {
                            Toast.makeText(context, comingSoon, Toast.LENGTH_SHORT).show()
                        }
                    },
                )
            }
        }
    }

    opened?.let { (service, color) ->
        ServiceChildrenSheet(service = service, color = color, onDismiss = { opened = null })
    }
}

@Composable
private fun SectionHeader(total: Int, onViewAll: () -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Box(
            modifier = Modifier
                .width(4.dp)
                .height(20.dp)
                .background(AppColors.primary, RoundedCornerShape(2.dp)),
        )
        Spacer(Modifier.width(8.dp))
        Text(
            stringResource(R.string.services_title),
            style = MaterialTheme.typography.titleLarge,
            fontWeight = FontWeight.Bold,
            modifier = Modifier.weight(1f),
        )
        if (total > COLLAPSED_COUNT) {
            TextButton(onClick = onViewAll) {
                Text(
                    stringResource(R.string.services_view_all, total),
                    color = AppColors.primary,
                    fontWeight = FontWeight.SemiBold,
                )
            }
        }
    }
}

@Composable
private fun ServiceGrid(services: List<ServiceDto>, onClick: (ServiceDto, Color) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        services.chunked(COLUMNS).forEachIndexed { rowIndex, row ->
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                row.forEachIndexed { colIndex, service ->
                    val index = rowIndex * COLUMNS + colIndex
                    val color = palette[index % palette.size]
                    ServiceTile(
                        service = service,
                        color = color,
                        index = index,
                        onClick = { onClick(service, color) },
                        modifier = Modifier.weight(1f),
                    )
                }
                // Keep the last row's tiles the same width as full rows.
                repeat(COLUMNS - row.size) { Spacer(Modifier.weight(1f)) }
            }
        }
    }
}

@Composable
private fun ServiceTile(
    service: ServiceDto,
    color: Color,
    index: Int,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val enter = remember(service.id) { Animatable(0f) }
    LaunchedEffect(service.id) {
        enter.animateTo(1f, tween(320, delayMillis = (index % COLUMNS + index / COLUMNS) * 45))
    }

    Column(
        modifier = modifier
            .alpha(enter.value)
            .scale(0.92f + 0.08f * enter.value)
            .clip(RoundedCornerShape(20.dp))
            .background(color.copy(alpha = 0.07f))
            .border(1.dp, color.copy(alpha = 0.18f), RoundedCornerShape(20.dp))
            .clickable(onClick = onClick)
            .padding(horizontal = 8.dp, vertical = 14.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Box {
            ServiceAvatar(image = service.image, icon = serviceIcon(service.moduleName), color = color, size = 56)
            val childCount = service.children?.size ?: 0
            if (childCount > 0) {
                Box(
                    modifier = Modifier
                        .align(Alignment.TopEnd)
                        .size(20.dp)
                        .background(color, CircleShape)
                        .border(1.5.dp, Color.White, CircleShape),
                    contentAlignment = Alignment.Center,
                ) {
                    Text("$childCount", color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
        Spacer(Modifier.height(10.dp))
        Text(
            service.name,
            style = MaterialTheme.typography.bodySmall,
            fontWeight = FontWeight.SemiBold,
            textAlign = TextAlign.Center,
            maxLines = 2,
            minLines = 2,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

/** Uploaded image when the dashboard has one, otherwise a tinted icon tile. */
@Composable
internal fun ServiceAvatar(image: String?, icon: ImageVector, color: Color, size: Int) {
    val context = LocalContext.current
    val loader = sharedImageLoader(context)
    val shape = RoundedCornerShape(16.dp)

    Box(
        modifier = Modifier
            .size(size.dp)
            .clip(shape)
            .background(color.copy(alpha = 0.14f)),
        contentAlignment = Alignment.Center,
    ) {
        if (image.isNullOrBlank()) {
            Icon(icon, contentDescription = null, tint = color, modifier = Modifier.size((size * 0.5f).dp))
        } else {
            AsyncImage(
                model = ApiClient.mediaUrl(image),
                imageLoader = loader,
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.size(size.dp),
            )
        }
    }
}

@Composable
internal fun ServicesSkeleton() {
    val pulse by rememberInfiniteTransition(label = "skeleton").animateFloat(
        initialValue = 0.35f,
        targetValue = 0.9f,
        animationSpec = infiniteRepeatable(tween(800, easing = LinearEasing), RepeatMode.Reverse),
        label = "pulse",
    )
    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        repeat(2) {
            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                repeat(COLUMNS) {
                    Box(
                        modifier = Modifier
                            .weight(1f)
                            .height(112.dp)
                            .alpha(pulse)
                            .clip(RoundedCornerShape(20.dp))
                            .background(AppColors.border),
                    )
                }
            }
        }
    }
}

@Composable
internal fun ServicesError(onRetry: () -> Unit) {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(20.dp))
            .background(AppColors.danger.copy(alpha = 0.06f))
            .border(1.dp, AppColors.danger.copy(alpha = 0.2f), RoundedCornerShape(20.dp))
            .padding(20.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(Icons.Rounded.CloudOff, contentDescription = null, tint = AppColors.textMuted, modifier = Modifier.size(36.dp))
        Spacer(Modifier.height(8.dp))
        Text(stringResource(R.string.services_error), style = MaterialTheme.typography.bodyMedium)
        TextButton(onClick = onRetry) {
            Text(stringResource(R.string.services_retry), color = AppColors.primary, fontWeight = FontWeight.SemiBold)
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ServiceChildrenSheet(service: ServiceDto, color: Color, onDismiss: () -> Unit) {
    val children = service.children.orEmpty()
    val context = LocalContext.current
    val comingSoon = stringResource(R.string.services_coming_soon)

    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(modifier = Modifier.padding(horizontal = 20.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                ServiceAvatar(service.image, serviceIcon(service.moduleName), color, size = 48)
                Spacer(Modifier.width(12.dp))
                Column {
                    Text(service.name, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                    Text(
                        stringResource(R.string.services_sub_count, children.size),
                        style = MaterialTheme.typography.bodySmall,
                        color = AppColors.textSecondary,
                    )
                }
            }
            Spacer(Modifier.height(12.dp))
            children.forEach { child -> ChildRow(child, color) { Toast.makeText(context, comingSoon, Toast.LENGTH_SHORT).show() } }
            Spacer(Modifier.height(24.dp))
        }
    }
}

@Composable
private fun ChildRow(child: ServiceChildDto, color: Color, onClick: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(14.dp))
            .clickable(onClick = onClick)
            .padding(vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        ServiceAvatar(child.image, serviceIcon(child.moduleName), color, size = 40)
        Spacer(Modifier.width(12.dp))
        Text(child.name, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
        Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = AppColors.textMuted)
    }
}
