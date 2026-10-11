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
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
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
import androidx.compose.ui.draw.shadow
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
import com.dorr.app.network.collectReconnectTick
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.theme.AppColors

private const val COLLAPSED_COUNT = 6
private const val COLUMNS = 3

private var imageLoader: ImageLoader? = null

// One loader (and disk/memory cache) for every tile. It reuses the API client so
// media requests carry the same dev Host header.
internal fun sharedServiceImageLoader(context: android.content.Context): ImageLoader =
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
internal fun rememberServicesLoader(
    audience: String = "user",
    homeDashboardOnly: Boolean = false,
): ServicesLoader {
    var reloadKey by remember { mutableIntStateOf(0) }
    var state by remember { mutableStateOf<ServicesState>(ServicesState.Loading) }
    val reconnectTick = collectReconnectTick()

    LaunchedEffect(reloadKey, reconnectTick, audience, homeDashboardOnly) {
        state = ServicesState.Loading
        state = runCatching {
            ApiClient.services.list(
                audience = audience,
                home = if (homeDashboardOnly) 1 else null,
            ).data.orEmpty()
                .sortedWith(serviceDisplayOrder)
                .map { service ->
                    service.copy(
                        children = service.children.orEmpty().sortedWith(childDisplayOrder),
                    )
                }
        }.fold({ ServicesState.Loaded(it) }, { ServicesState.Error })
    }
    return ServicesLoader(state) { reloadKey++ }
}

private val serviceDisplayOrder = compareBy<ServiceDto>({ it.sortOrder }, { it.id })
private val childDisplayOrder = compareBy<ServiceChildDto>({ it.sortOrder }, { it.id })

/**
 * Home preview of the dashboard services: a header ("Services" + a "View all" pill) over one horizontal row of small
 * cards — the service's image when the API sends one, otherwise its icon. The full page opens from "View all".
 */
@Composable
fun ServicesSection(
    onViewAll: () -> Unit,
    onOpenService: (ServiceDto, Color) -> Unit,
    // Real, observed bug fix (2026-10-04): this preview (Home tab's own "services" section) used to route EVERY
    // card - including "AI Assistant" - through onOpenService, landing on the generic ServiceDetailScreen instead
    // of actually opening the assistant. ServicesScreen.kt (the full "Services" tab) special-cases
    // moduleName == "ai_assistant" the same way; this param lets the Home preview do the identical thing.
    // Defaults to null so any other caller of this composable keeps its previous behavior unchanged.
    onOpenAi: (() -> Unit)? = null,
    modifier: Modifier = Modifier,
) {
    val loader = rememberServicesLoader(homeDashboardOnly = true)
    val accent = if (settingsNight()) AccountDark.accent else settingsAccent()

    Column(modifier = modifier) {
        Row(
            Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                stringResource(R.string.services_title),
                fontSize = 20.sp,
                fontWeight = FontWeight.ExtraBold,
                color = settingsInk(),
                modifier = Modifier.weight(1f),
            )
            Row(
                Modifier
                    .clip(RoundedCornerShape(50))
                    .clickable(onClick = onViewAll)
                    .padding(start = 8.dp, end = 4.dp, top = 5.dp, bottom = 5.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(stringResource(R.string.home_stories_all), color = accent, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = accent, modifier = Modifier.size(18.dp))
            }
        }

        when (val s = loader.state) {
            ServicesState.Loading -> ServicesRowSkeleton()
            ServicesState.Error -> Box(Modifier.padding(horizontal = 20.dp)) { ServicesError(onRetry = loader.reload) }
            is ServicesState.Loaded -> if (s.services.isNotEmpty()) {
                LazyRow(
                    contentPadding = PaddingValues(horizontal = 20.dp, vertical = 4.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    itemsIndexed(s.services, key = { _, service -> service.id }) { index, service ->
                        val color = palette[index % palette.size]
                        ServiceChip(
                            service = service,
                            color = color,
                            onClick = {
                                if (service.moduleName == "ai_assistant" && onOpenAi != null) onOpenAi() else onOpenService(service, color)
                            },
                        )
                    }
                }
            }
        }
    }
}

/** One small service card: the image (or the icon when there is none) over its name. */
@Composable
private fun ServiceChip(service: ServiceDto, color: Color, onClick: () -> Unit) {
    val shape = RoundedCornerShape(16.dp)
    Column(
        modifier = Modifier
            .width(88.dp)
            .clip(shape)
            .background(settingsCard())
            .border(1.dp, if (settingsNight()) AccountDark.line else settingsInk().copy(alpha = 0.10f), shape)
            .clickable(onClick = onClick)
            .padding(horizontal = 6.dp, vertical = 10.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        ServiceAvatar(service.image, serviceIcon(service.moduleName), color, size = 46)
        Spacer(Modifier.height(6.dp))
        Text(
            service.name,
            fontSize = 11.5.sp,
            lineHeight = 14.sp,
            fontWeight = FontWeight.Bold,
            textAlign = TextAlign.Center,
            maxLines = 2,
            minLines = 2,
            overflow = TextOverflow.Ellipsis,
            color = settingsInk(),
        )
    }
}

@Composable
private fun ServicesRowSkeleton() {
    val pulse by rememberInfiniteTransition(label = "services-row").animateFloat(
        initialValue = 0.35f,
        targetValue = 0.9f,
        animationSpec = infiniteRepeatable(tween(800, easing = LinearEasing), RepeatMode.Reverse),
        label = "pulse",
    )
    Row(Modifier.padding(horizontal = 20.dp, vertical = 4.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        repeat(4) {
            Box(
                Modifier
                    .width(88.dp)
                    .height(98.dp)
                    .alpha(pulse)
                    .clip(RoundedCornerShape(16.dp))
                    .background(if (settingsNight()) AccountDark.line else AppColors.border),
            )
        }
    }
}

/** Contrast backdrop for dashboard PNG/SVG logos (often white line-art). */
@Composable
internal fun serviceMediaWellColor(): Color {
    return if (settingsNight()) Color(0xFF22262E) else Color(0xFF2A3140)
}

@Composable
private fun serviceIconFallbackWell(): Color {
    return if (settingsNight()) AccountDark.well else settingsAccent().copy(alpha = 0.10f)
}

/** Home grid / list header: full-width media from API, or accent icon when missing. */
@Composable
internal fun ServiceMediaStrip(
    image: String?,
    icon: ImageVector,
    height: androidx.compose.ui.unit.Dp,
    modifier: Modifier = Modifier,
) {
    val context = LocalContext.current
    val loader = sharedServiceImageLoader(context)
    val hasImage = !image.isNullOrBlank()
    val accent = if (settingsNight()) AccountDark.accent else settingsAccent()
    Box(
        modifier = modifier
            .fillMaxWidth()
            .height(height)
            .background(if (hasImage) serviceMediaWellColor() else serviceIconFallbackWell()),
        contentAlignment = Alignment.Center,
    ) {
        if (hasImage) {
            AsyncImage(
                model = ApiClient.mediaUrl(image),
                imageLoader = loader,
                contentDescription = null,
                contentScale = ContentScale.Fit,
                modifier = Modifier
                    .fillMaxWidth()
                    .height(height)
                    .padding(horizontal = 12.dp, vertical = 10.dp),
            )
        } else {
            Icon(icon, contentDescription = null, tint = accent, modifier = Modifier.size(32.dp))
        }
    }
}

/** Compact tile for rows (services list, child rows). */
@Composable
internal fun ServiceAvatar(image: String?, icon: ImageVector, color: Color, size: Int) {
    val context = LocalContext.current
    val loader = sharedServiceImageLoader(context)
    val shape = RoundedCornerShape(16.dp)
    val hasImage = !image.isNullOrBlank()

    Box(
        modifier = Modifier
            .size(size.dp)
            .clip(shape)
            .background(if (hasImage) serviceMediaWellColor() else serviceIconFallbackWell()),
        contentAlignment = Alignment.Center,
    ) {
        if (!hasImage) {
            Icon(
                icon,
                contentDescription = null,
                tint = if (settingsNight()) AccountDark.accent else settingsAccent(),
                modifier = Modifier.size((size * 0.5f).dp),
            )
        } else {
            AsyncImage(
                model = ApiClient.mediaUrl(image),
                imageLoader = loader,
                contentDescription = null,
                contentScale = ContentScale.Fit,
                modifier = Modifier
                    .size(size.dp)
                    .padding(6.dp),
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
                            .height(128.dp)
                            .alpha(pulse)
                            .clip(RoundedCornerShape(20.dp))
                            .background(if (settingsNight()) AccountDark.line else AppColors.border),
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
        Icon(Icons.Rounded.CloudOff, contentDescription = null, tint = if (settingsNight()) AccountDark.mut else AppColors.textMuted, modifier = Modifier.size(36.dp))
        Spacer(Modifier.height(8.dp))
        Text(
            stringResource(R.string.services_error),
            style = MaterialTheme.typography.bodyMedium,
            color = settingsInk(),
        )
        TextButton(onClick = onRetry) {
            Text(stringResource(R.string.services_retry), color = if (settingsNight()) AccountDark.accent else settingsAccent(), fontWeight = FontWeight.SemiBold)
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ServiceChildrenSheet(service: ServiceDto, color: Color, onDismiss: () -> Unit) {
    val children = service.children.orEmpty()
    val context = LocalContext.current
    val comingSoon = stringResource(R.string.services_coming_soon)

    val night = settingsNight()
    ModalBottomSheet(
        onDismissRequest = onDismiss,
        containerColor = if (night) AccountDark.card else MaterialTheme.colorScheme.surface,
    ) {
        Column(modifier = Modifier.padding(horizontal = 20.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                ServiceAvatar(service.image, serviceIcon(service.moduleName), color, size = 48)
                Spacer(Modifier.width(12.dp))
                Column {
                    Text(
                        service.name,
                        style = MaterialTheme.typography.titleMedium,
                        fontWeight = FontWeight.Bold,
                        color = settingsInk(),
                    )
                    Text(
                        stringResource(R.string.services_sub_count, children.size),
                        style = MaterialTheme.typography.bodySmall,
                        color = if (night) AccountDark.mut else AppColors.textSecondary,
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
        Text(
            child.name,
            style = MaterialTheme.typography.bodyLarge,
            color = settingsInk(),
            modifier = Modifier.weight(1f),
        )
        Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = if (settingsNight()) AccountDark.chevron else AppColors.textMuted)
    }
}
