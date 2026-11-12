package com.dorr.provider.ui.screens

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.Business
import androidx.compose.material.icons.rounded.Category
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.MyLocation
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Place
import androidx.compose.material.icons.rounded.Explore
import androidx.compose.material.icons.rounded.Receipt
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import coil.compose.AsyncImage
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.provider.R
import com.dorr.provider.net.ApiClient
import com.dorr.provider.net.BranchRequest
import com.dorr.provider.net.CreateEstablishmentRequest
import com.dorr.provider.net.ServiceOptionDto
import com.dorr.provider.net.Session
import com.dorr.provider.net.Workspace
import com.dorr.provider.net.collectReconnectTick
import com.dorr.provider.net.errorText
import com.dorr.provider.ui.components.Card
import com.dorr.provider.ui.components.DorrTextField
import com.dorr.provider.ui.components.ErrorBanner
import com.dorr.provider.ui.components.FieldLabel
import com.dorr.provider.ui.components.FormGroup
import com.dorr.provider.ui.components.FormIntro
import com.dorr.provider.ui.components.Page
import com.dorr.provider.ui.kit.AccountDark
import com.dorr.provider.ui.kit.SettingsPrimaryButton
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsCard
import com.dorr.provider.ui.kit.settingsInk
import com.dorr.provider.ui.kit.settingsMut
import com.dorr.provider.ui.kit.settingsNight
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.maps.model.LatLng
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlin.coroutines.resume

/**
 * After the sign-in, with no establishment yet (C-02, docs/moving_business.md §12): short steps in the kit's look —
 *  1. individual or company, and the name (a company adds its commercial register, and its tax number if it has one);
 *  2. the service(s) to register in, as tiles with each service's image and name in the admin's order (`workspace/options`);
 *  3. the main branch's place on a real map ([BranchMapScreen]: it opens on the phone's location, the pin in the middle, the
 *     address written from the map and editable) and the range requests reach it from (100 km to start with).
 * Then `POST establishments`. When moving is among the services, a 4th step follows: the first vehicle (the app's
 * `first-vehicle` page), and only then the papers; [onCreated] says which (`true` = the vehicle step comes next).
 */
@Composable
fun OnboardingScreen(onExit: () -> Unit, onCreated: (needsVehicle: Boolean) -> Unit) {
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)

    var step by rememberSaveable { mutableStateOf(1) }
    var type by rememberSaveable { mutableStateOf("individual") }
    var name by rememberSaveable { mutableStateOf("") }
    var register by rememberSaveable { mutableStateOf("") }
    var tax by rememberSaveable { mutableStateOf("") }
    val picked = remember { mutableStateListOf<Int>() }
    var lat by rememberSaveable { mutableStateOf<Double?>(null) }
    var lng by rememberSaveable { mutableStateOf<Double?>(null) }
    var address by rememberSaveable { mutableStateOf("") }
    var radiusKm by rememberSaveable { mutableStateOf(DEFAULT_RADIUS_KM) }

    var services by remember { mutableStateOf<List<ServiceOptionDto>?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    // The person's country decides the services and later the papers; read once (and again when the connection returns).
    LaunchedEffect(reconnect) {
        val me = Session.me.value ?: (apiCall { ApiClient.auth.me() } as? ApiResult.Ok)?.data?.also { Session.setMe(it) }
        when (val result = apiCall { ApiClient.workspace.options(me?.countryId) }) {
            is ApiResult.Ok -> services = result.data.services
            else -> if (services == null) error = result.errorText(offlineText) ?: genericError
        }
    }

    // Moving needs a vehicle before the papers (§17): then registration has a 4th step.
    val needsVehicle = services.orEmpty().any { it.id in picked && it.moduleName.equals(MOVING_MODULE, ignoreCase = true) }
    val totalSteps = if (needsVehicle) 4 else 3
    val step1Ok = name.isNotBlank() && (type == "individual" || register.isNotBlank())
    val step2Ok = picked.isNotEmpty()
    val step3Ok = lat != null && lng != null

    fun submit() {
        val countryId = Session.me.value?.countryId
        val latitude = lat
        val longitude = lng
        if (countryId == null || latitude == null || longitude == null) {
            error = genericError
            return
        }
        busy = true
        error = null
        scope.launch {
            val body = CreateEstablishmentRequest(
                type = type,
                name = name.trim(),
                countryId = countryId,
                commercialRegisterNo = register.trim().takeIf { type == "company" && it.isNotEmpty() },
                taxNumber = tax.trim().takeIf { type == "company" && it.isNotEmpty() },
                serviceIds = picked.toList(),
                branch = BranchRequest(lat = latitude, lng = longitude, radiusKm = radiusKm, address = address.trim().ifEmpty { null }),
            )
            val result = apiCall { ApiClient.workspace.create(body) }
            busy = false
            if (result is ApiResult.Ok) {
                Session.chooseEstablishment(result.data.establishment?.uuid)
                Workspace.reload()
                onCreated(needsVehicle)
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    BackHandler(enabled = step > 1) { step-- }

    AnimatedContent(
        targetState = step,
        label = "onboardingStep",
        transitionSpec = {
            val sign = if (targetState > initialState) 1 else -1
            ContentTransform(
                slideInHorizontally(tween(320, easing = FastOutSlowInEasing)) { sign * it / 6 } + fadeIn(tween(260)),
                slideOutHorizontally(tween(280, easing = FastOutSlowInEasing)) { -sign * it / 6 } + fadeOut(tween(200)),
                sizeTransform = null,
            )
        },
    ) { shown ->
        if (shown >= 3) {
            // Step 3: the whole screen is the map; the address, the range and the button are on the card at the bottom.
            val start = remember {
                val savedLat = lat
                val savedLng = lng
                if (savedLat != null && savedLng != null) LatLng(savedLat, savedLng) else null
            }
            BranchMapScreen(
                title = stringResource(R.string.onb_step3_title),
                start = start,
                onBack = { step = 2 },
                onPlace = {
                    lat = it.latitude
                    lng = it.longitude
                },
                onAddress = { address = it },
                radiusKm = radiusKm,
                top = { OnboardingStepBar(3, totalSteps) },
            ) {
                Text(stringResource(R.string.onb_step3_body), color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp)
                DorrTextField(
                    address, { address = it.take(255) },
                    label = stringResource(R.string.onb_map_address_label), placeholder = stringResource(R.string.onb_map_address_placeholder),
                    icon = Icons.Rounded.Place, modifier = Modifier.fillMaxWidth(),
                )
                Text(stringResource(R.string.onb_map_address_note), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
                RadiusPicker(radiusKm) { radiusKm = it }
                SettingsPrimaryButton(
                    text = stringResource(if (needsVehicle) R.string.onb_next else R.string.onb_create),
                    enabled = step3Ok, loading = busy, onClick = ::submit,
                )
            }
        } else {
            Page(title = stringResource(R.string.onb_title), onBack = { if (shown > 1) step-- else onExit() }) {
                OnboardingStepBar(shown, totalSteps)
                Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                    if (shown == 1) {
                        FormIntro(Icons.Rounded.Storefront, stringResource(R.string.onb_step1_title), stringResource(R.string.onb_step1_body))
                        Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                            TypeCard(Icons.Rounded.Person, stringResource(R.string.est_type_individual), stringResource(R.string.onb_individual_hint), type == "individual", Modifier.weight(1f)) { type = "individual" }
                            TypeCard(Icons.Rounded.Business, stringResource(R.string.est_type_company), stringResource(R.string.onb_company_hint), type == "company", Modifier.weight(1f)) { type = "company" }
                        }
                        FormGroup {
                            DorrTextField(
                                name, { name = it.take(150) },
                                label = stringResource(if (type == "company") R.string.onb_company_name else R.string.onb_individual_name),
                                icon = Icons.Rounded.Storefront, modifier = Modifier.fillMaxWidth(),
                            )
                            if (type == "company") {
                                DorrTextField(
                                    register, { register = it.take(64) }, label = stringResource(R.string.onb_register),
                                    icon = Icons.Rounded.Badge, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii), modifier = Modifier.fillMaxWidth(),
                                )
                                DorrTextField(
                                    tax, { tax = it.take(64) }, label = stringResource(R.string.onb_tax), placeholder = stringResource(R.string.onb_optional),
                                    icon = Icons.Rounded.Receipt, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii), modifier = Modifier.fillMaxWidth(),
                                )
                            }
                        }
                        SettingsPrimaryButton(text = stringResource(R.string.onb_next), enabled = step1Ok, onClick = { step = 2 })
                    } else {
                        FormIntro(Icons.Rounded.Category, stringResource(R.string.onb_step2_title), stringResource(R.string.onb_step2_body))
                        val list = services
                        when {
                            list == null -> CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.align(Alignment.CenterHorizontally).size(28.dp))
                            list.isEmpty() -> Text(stringResource(R.string.onb_no_services), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.fillMaxWidth())
                            // The server's order is the admin's order (§12): kept as it comes, three to a row.
                            else -> ServiceTileGrid(list) { service, tileModifier ->
                                ServiceTile(service.name, service.image, selected = service.id in picked, modifier = tileModifier) {
                                    if (service.id in picked) picked.remove(service.id) else picked.add(service.id)
                                }
                            }
                        }
                        SettingsPrimaryButton(text = stringResource(R.string.onb_next), enabled = step2Ok, onClick = { step = 3 })
                    }
                }
            }
        }
    }
    ErrorBanner(error)
}

/** The `module_name` of the moving service: picking it adds the vehicle step. */
internal const val MOVING_MODULE = "moving"

/**
 * The registration's steps on top: the steps done and the one open in the app colour, and «Step n of total». [total] is 4 when
 * moving is chosen (the 4th = the first vehicle; the vehicle page can show this same bar with `OnboardingStepBar(4, 4)`).
 */
@Composable
internal fun OnboardingStepBar(step: Int, total: Int) {
    val night = settingsNight()
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            (1..total).forEach { index ->
                val color by animateColorAsState(
                    if (index <= step) settingsAccent() else if (night) AccountDark.line else settingsAccent().copy(alpha = 0.15f),
                    tween(300), label = "step$index",
                )
                Box(Modifier.weight(1f).height(5.dp).clip(RoundedCornerShape(50)).background(color))
            }
        }
        Text(stringResource(R.string.onb_step_of, step, total), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun TypeCard(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, hint: String, selected: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val night = settingsNight()
    val accent = settingsAccent()
    val shape = RoundedCornerShape(18.dp)
    Column(
        modifier
            .clip(shape)
            .background(if (selected) accent.copy(alpha = if (night) 0.22f else 0.10f) else settingsCard())
            .border(if (selected) 2.dp else 1.dp, if (selected) accent else if (night) AccountDark.line else Color(0xFFE5E7EB), shape)
            .clickable(onClick = onClick)
            .padding(14.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Box(Modifier.size(44.dp).clip(RoundedCornerShape(14.dp)).background(accent.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = null, tint = accent, modifier = Modifier.size(24.dp))
        }
        Text(title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
        Text(hint, color = settingsMut(), fontSize = 11.sp, lineHeight = 16.sp, textAlign = TextAlign.Center)
    }
}

internal fun hasLocationPermission(context: Context): Boolean =
    ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED

/** The phone's position now, once (the fused provider, like driverApp's `lastPosition`); null when it can't tell. */
@SuppressLint("MissingPermission")
internal suspend fun currentPosition(context: Context): Pair<Double, Double>? = runCatching {
    suspendCancellableCoroutine { cont ->
        LocationServices.getFusedLocationProviderClient(context)
            .getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, null)
            .addOnSuccessListener { loc -> cont.resume(loc?.let { it.latitude to it.longitude }) }
            .addOnFailureListener { cont.resume(null) }
    }
}.getOrNull()
