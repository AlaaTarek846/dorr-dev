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
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlin.coroutines.resume

/**
 * After the sign-in, with no establishment yet (C-02, docs/moving_business.md §12): three short steps in the kit's look —
 *  1. individual or company, and the name (a company adds its commercial register, and its tax number if it has one);
 *  2. the service(s) to register in, as a grid with each service's image in the admin's order (`workspace/options`);
 *  3. the main branch's place: the phone's current location, an address line and the area served (km).
 * Then `POST establishments`, and the establishment's papers page opens. A map to place the branch by hand comes later.
 */
@Composable
fun OnboardingScreen(onExit: () -> Unit, onCreated: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val locationFailed = stringResource(R.string.onb_location_failed)

    var step by rememberSaveable { mutableStateOf(1) }
    var type by rememberSaveable { mutableStateOf("individual") }
    var name by rememberSaveable { mutableStateOf("") }
    var register by rememberSaveable { mutableStateOf("") }
    var tax by rememberSaveable { mutableStateOf("") }
    val picked = remember { mutableStateListOf<Int>() }
    var lat by rememberSaveable { mutableStateOf<Double?>(null) }
    var lng by rememberSaveable { mutableStateOf<Double?>(null) }
    var address by rememberSaveable { mutableStateOf("") }
    var radius by rememberSaveable { mutableStateOf("") }

    var services by remember { mutableStateOf<List<ServiceOptionDto>?>(null) }
    var locating by remember { mutableStateOf(false) }
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

    fun locate() {
        locating = true
        error = null
        scope.launch {
            val here = currentPosition(context)
            locating = false
            if (here == null) {
                error = locationFailed
            } else {
                lat = here.first
                lng = here.second
            }
        }
    }

    val askLocation = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted ->
        if (granted.values.any { it }) locate() else error = locationFailed
    }

    fun useMyLocation() {
        if (hasLocationPermission(context)) locate()
        else askLocation.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
    }

    val radiusValue = radius.replace(',', '.').toDoubleOrNull()
    val radiusOk = radius.isBlank() || (radiusValue != null && radiusValue in 0.5..500.0)
    val step1Ok = name.isNotBlank() && (type == "individual" || register.isNotBlank())
    val step2Ok = picked.isNotEmpty()
    val step3Ok = lat != null && lng != null && radiusOk

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
                branch = BranchRequest(lat = latitude, lng = longitude, radiusKm = radiusValue, address = address.trim().ifEmpty { null }),
            )
            val result = apiCall { ApiClient.workspace.create(body) }
            busy = false
            if (result is ApiResult.Ok) {
                Session.chooseEstablishment(result.data.establishment?.uuid)
                Workspace.reload()
                onCreated()
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    BackHandler(enabled = step > 1) { step-- }

    Page(title = stringResource(R.string.onb_title), onBack = { if (step > 1) step-- else onExit() }) {
        StepBar(step)
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
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                when (shown) {
                    1 -> {
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
                    }
                    2 -> {
                        FormIntro(Icons.Rounded.Category, stringResource(R.string.onb_step2_title), stringResource(R.string.onb_step2_body))
                        val list = services
                        when {
                            list == null -> CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.5.dp, modifier = Modifier.align(Alignment.CenterHorizontally).size(28.dp))
                            list.isEmpty() -> Text(stringResource(R.string.onb_no_services), color = settingsMut(), fontSize = 13.sp, modifier = Modifier.fillMaxWidth())
                            // The server's order is the admin's order (§12): kept as it comes, three to a row.
                            else -> list.chunked(3).forEach { row ->
                                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                    row.forEach { service ->
                                        ServiceTile(service, service.id in picked, Modifier.weight(1f)) {
                                            if (service.id in picked) picked.remove(service.id) else picked.add(service.id)
                                        }
                                    }
                                    repeat(3 - row.size) { Spacer(Modifier.weight(1f)) }
                                }
                            }
                        }
                        SettingsPrimaryButton(text = stringResource(R.string.onb_next), enabled = step2Ok, onClick = { step = 3 })
                    }
                    else -> {
                        FormIntro(Icons.Rounded.Place, stringResource(R.string.onb_step3_title), stringResource(R.string.onb_step3_body))
                        Card {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Icon(Icons.Rounded.MyLocation, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
                                Spacer(Modifier.width(10.dp))
                                Column(Modifier.weight(1f)) {
                                    Text(
                                        stringResource(if (lat != null) R.string.onb_location_set else R.string.onb_location_none),
                                        color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold,
                                    )
                                    if (lat != null && lng != null) {
                                        // Coordinates keep their left-to-right order inside Arabic text.
                                        Text("‎%.5f, %.5f".format(java.util.Locale.US, lat, lng), color = settingsMut(), fontSize = 12.sp)
                                    }
                                }
                                if (locating) CircularProgressIndicator(color = settingsAccent(), strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
                            }
                            Text(
                                stringResource(if (lat != null) R.string.onb_location_again else R.string.onb_location_use),
                                color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                                modifier = Modifier.clickable(enabled = !locating && !busy, onClick = ::useMyLocation),
                            )
                            Text(stringResource(R.string.onb_map_later), color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
                        }
                        FormGroup {
                            DorrTextField(
                                address, { address = it.take(255) }, label = stringResource(R.string.onb_address), placeholder = stringResource(R.string.onb_optional),
                                icon = Icons.Rounded.Place, modifier = Modifier.fillMaxWidth(),
                            )
                            DorrTextField(
                                radius, { radius = it.filter { c -> c.isDigit() || c == '.' || c == ',' }.take(5) },
                                label = stringResource(R.string.onb_radius), placeholder = stringResource(R.string.onb_radius_hint),
                                icon = Icons.Rounded.Explore, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                                error = !radiusOk, modifier = Modifier.fillMaxWidth(),
                            )
                            if (!radiusOk) Text(stringResource(R.string.onb_radius_invalid), color = Color(0xFFDC2626), fontSize = 12.sp)
                        }
                        SettingsPrimaryButton(text = stringResource(R.string.onb_create), enabled = step3Ok, loading = busy, onClick = ::submit)
                    }
                }
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

/** Three segments on top: the steps done and the one open in the app colour. */
@Composable
private fun StepBar(step: Int) {
    val night = settingsNight()
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            (1..3).forEach { index ->
                val color by animateColorAsState(
                    if (index <= step) settingsAccent() else if (night) AccountDark.line else settingsAccent().copy(alpha = 0.15f),
                    tween(300), label = "step$index",
                )
                Box(Modifier.weight(1f).height(5.dp).clip(RoundedCornerShape(50)).background(color))
            }
        }
        Text(stringResource(R.string.onb_step_of, step, 3), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
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

/** One service: its image from the admin (a soft tile when there is none), its name, and a tick once chosen. */
@Composable
private fun ServiceTile(service: ServiceOptionDto, selected: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val night = settingsNight()
    val accent = settingsAccent()
    val shape = RoundedCornerShape(18.dp)
    Box(modifier) {
        Column(
            Modifier
                .fillMaxWidth()
                .clip(shape)
                .background(if (selected) accent.copy(alpha = if (night) 0.22f else 0.10f) else settingsCard())
                .border(if (selected) 2.dp else 1.dp, if (selected) accent else if (night) AccountDark.line else Color(0xFFE5E7EB), shape)
                .clickable(onClick = onClick)
                .padding(horizontal = 8.dp, vertical = 12.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Box(Modifier.fillMaxWidth(0.72f).aspectRatio(1f), contentAlignment = Alignment.Center) {
                val image = ApiClient.mediaUrl(service.image)
                if (image != null) {
                    AsyncImage(model = image, contentDescription = null, contentScale = ContentScale.Fit, modifier = Modifier.fillMaxWidth().aspectRatio(1f))
                } else {
                    Box(Modifier.fillMaxWidth().aspectRatio(1f).clip(RoundedCornerShape(14.dp)).background(accent.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Category, contentDescription = null, tint = accent, modifier = Modifier.size(26.dp))
                    }
                }
            }
            Text(
                service.name.orEmpty(), color = settingsInk(), fontSize = 12.sp, fontWeight = FontWeight.Bold, lineHeight = 16.sp,
                textAlign = TextAlign.Center, maxLines = 2, overflow = TextOverflow.Ellipsis,
            )
        }
        if (selected) {
            Box(
                Modifier.align(Alignment.TopEnd).padding(6.dp).size(20.dp).clip(CircleShape).background(accent),
                contentAlignment = Alignment.Center,
            ) { Icon(Icons.Rounded.Check, contentDescription = null, tint = Color.White, modifier = Modifier.size(14.dp)) }
        }
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
