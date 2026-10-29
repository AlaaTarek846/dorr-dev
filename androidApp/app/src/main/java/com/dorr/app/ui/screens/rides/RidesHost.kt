package com.dorr.app.ui.screens.rides

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.AccountDark
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.border
import androidx.compose.animation.animateContentSize
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.EventNote
import androidx.compose.material.icons.rounded.MyLocation
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.FavoriteDriverDto
import com.dorr.app.network.RideDto
import com.dorr.app.network.RidePickupPointsDto
import com.dorr.app.network.RidePlacesDto
import com.dorr.app.network.RideFeaturesDto
import com.dorr.app.network.RideQuoteDto
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.theme.AppColors
import com.google.android.gms.maps.model.LatLng
import com.google.gson.Gson
import com.dorr.app.map.DorrCamera
import com.dorr.app.map.DorrMap
import com.dorr.app.map.MapColors
import com.dorr.app.map.MapEngine
import com.dorr.app.map.MapLine
import com.dorr.app.map.MapPin
import com.dorr.app.map.PinKind
import com.dorr.app.map.dpPx
import com.dorr.app.map.rememberDorrCamera
import kotlinx.coroutines.launch

/** Where the booking is: the map (pickup), the place search, picking a point on the map, the prices. */
internal enum class RideStep { Map, Search, PickOnMap, Quotes }

/** Everything the rides screens share while one is open. */
internal class RidesState {
    var me by mutableStateOf<LatLng?>(null)
    var pickup by mutableStateOf<RidePoint?>(null)

    /** O-51: the pickup's address is being worked out (the map moves / the place is looked up). */
    var pickupLoading by mutableStateOf(false)
    var destination by mutableStateOf<RidePoint?>(null)
    val stops = mutableStateListOf<RidePoint>()
    var openDestination by mutableStateOf(false)
    var features by mutableStateOf<RideFeaturesDto?>(null)

    /** Bumped by "try again" on the unavailable card so the service switches are read again. */
    var featuresTick by mutableIntStateOf(0)
    var step by mutableStateOf(RideStep.Map)

    /** Which point the search / the map pin is setting: "pickup", "destination" or "stop:<index>". */
    var editing by mutableStateOf("destination")
    var quotes by mutableStateOf<List<RideQuoteDto>>(emptyList())
    var selected by mutableStateOf<RideQuoteDto?>(null)
    // O-37: cash by default; the wallet's balance goes first when [useWallet] (the rest is cash).
    var payment by mutableStateOf("cash")
    var useWallet by mutableStateOf(true)

    /** O-33: With Me — who may share the car: any / male / female. */
    var shareWith by mutableStateOf("any")

    /** R-45: the promo code priced into the quotes, and why the last one was refused. */
    var couponCode by mutableStateOf<String?>(null)
    var couponError by mutableStateOf<String?>(null)

    /** D-60, D-61: how many ride, and a short note for the captain about the pickup. */
    var passengers by mutableIntStateOf(1)
    /** O-15: the category picked by itself for more riders (the popup says so), then cleared. */
    var categorySwitchedTo by mutableStateOf<String?>(null)
    var pickupNote by mutableStateOf("")

    /** R-13: booking for someone else — their name and number, and the rider's own consent tick (never pre-ticked). */
    var forOther by mutableStateOf(false)
    var beneficiaryName by mutableStateOf("")
    var beneficiaryPhone by mutableStateOf("")
    var beneficiaryConsent by mutableStateOf(false)

    /** Booking for oneself, or everything about the other person is filled in and agreed. */
    fun beneficiaryReady(): Boolean =
        !forOther || (beneficiaryName.trim().length >= 2 && beneficiaryPhone.count { it.isDigit() } >= 8 && beneficiaryConsent)

    /** R-INN-07 DORR Circle: ask my favourite captains first — the ones picked (none picked = all of them). */
    var useCircle by mutableStateOf(false)
    var circleDrivers by mutableStateOf<List<FavoriteDriverDto>>(emptyList())
    val circlePicked = mutableStateListOf<Int>()

    /** R-5, R-174: Home, Work, favourites and recent destinations; `savingPlace` = the search is picking Home / Work. */
    var places by mutableStateOf<RidePlacesDto?>(null)
    var savingPlace by mutableStateOf<String?>(null)

    /** R-16..R-18: the approved points being chosen from ("pickup" | "dropoff"), what is there, and what was picked or declined. */
    var pointsChooser by mutableStateOf<String?>(null)
    var pointChoices by mutableStateOf<RidePickupPointsDto?>(null)
    var pickupPointId by mutableStateOf<Int?>(null)
    var dropoffPointId by mutableStateOf<Int?>(null)
    var decidedPickup by mutableStateOf<String?>(null)
    var decidedDropoff by mutableStateOf<String?>(null)

    /** Bidding: the price the rider offers (starts at the suggested price). */
    var riderPrice by mutableStateOf<Long?>(null)

    /** Block 06: «دُرر يفهم مقصدك» is open; the category it understood (chosen once the prices come); AI-09's suggestion. */
    var smartOpen by mutableStateOf(false)
    var preferCategory by mutableStateOf<String?>(null)
    var suggestion by mutableStateOf<com.dorr.app.network.RideAiSuggestDto?>(null)

    /** Booked ahead (R-12): the chosen pickup time; null = now. */
    var scheduledAt by mutableStateOf<java.time.ZonedDateTime?>(null)
    var scheduleOpen by mutableStateOf(false)
    var myRidesOpen by mutableStateOf(false)

    /** O-22, O-23: the bus booking pages, over the rides map. */
    var busOpen by mutableStateOf(false)

    /** R-146..R-155: DORR points, over the rides map. */
    var loyaltyOpen by mutableStateOf(false)

    /** R-233..R-237, R-NEW-06: what's added to the trip (priced into the quotes), and why the last change was refused. */
    var addons by mutableStateOf(TripOptions())
    var addonsApplied = TripOptions()
    var addonsError by mutableStateOf<String?>(null)

    /** O-25: boats & yachts, over the rides map. */
    var marineOpen by mutableStateOf(false)

    /** O-26c: find a driver, over the rides map. */
    var hireOpen by mutableStateOf(false)

    /** O-26b: driving lessons, over the rides map. */
    var lessonsOpen by mutableStateOf(false)

    /** O-18: the service picked on the first screen (instant ride → the map); false = the picker shows. */
    var servicePicked by mutableStateOf(false)

    /** O-17: by the hour — how many hours, on which category (null = by the distance). */
    var hourlyHours by mutableStateOf<Int?>(null)
    var hourlySlug by mutableStateOf<String?>(null)

    /** Complaints and disputes (R-133..R-140): the page is open, and the ride a new one is about (null = pick). */
    var casesOpen by mutableStateOf(false)
    var caseRideUuid by mutableStateOf<String?>(null)
    var caseStartNew by mutableStateOf(false)

    /** The Safety Center page is open (R-219). */
    var safetyOpen by mutableStateOf(false)

    /** An "Are you OK?" from outside a ride on screen — e.g. the check-in after arriving (R-223). */
    var pendingCheck by mutableStateOf<com.dorr.app.network.SafetyCheckDto?>(null)

    /** Changing the trip during the ride: "destination" or "add_stop" while its page is open (R-91..95). */
    var changing by mutableStateOf<String?>(null)

    /** The ride on screen was opened from "My rides": closing it goes back to booking, not out. */
    var fromHistory by mutableStateOf(false)
    var ride by mutableStateOf<RideDto?>(null)
    var busy by mutableStateOf(false)
    var error by mutableStateOf<String?>(null)

    fun feature(name: String): Boolean = features?.features?.get(name) == true

    fun set(point: RidePoint) {
        when {
            editing == "pickup" -> pickup = point
            editing == "destination" -> { destination = point; openDestination = false }
            editing.startsWith("stop:") -> editing.removePrefix("stop:").toIntOrNull()?.let { i ->
                if (i < stops.size) stops[i] = point else stops.add(point)
            }
        }
    }

    fun resetBooking() {
        hourlyHours = null
        hourlySlug = null
        destination = null
        stops.clear()
        openDestination = false
        quotes = emptyList()
        selected = null
        step = RideStep.Map
        error = null
        scheduledAt = null
        fromHistory = false
        couponCode = null
        couponError = null
        addons = TripOptions()
        addonsApplied = TripOptions()
        addonsError = null
        passengers = 1
        pickupNote = ""
        forOther = false
        beneficiaryName = ""
        beneficiaryPhone = ""
        beneficiaryConsent = false
        savingPlace = null
        useCircle = false
        circlePicked.clear()
        pointsChooser = null
        pointChoices = null
        pickupPointId = null
        dropoffPointId = null
        decidedPickup = null
        decidedDropoff = null
        preferCategory = null
        suggestion = null
    }
}

private val Riyadh = LatLng(24.7136, 46.6753)

/**
 * DORR Rides for the rider — opened from the services (module `passenger_ride`). One screen on a
 * map: set the pickup under the pin, say where to, pick a ride and a way to pay, then follow the
 * captain until the end and rate. An active ride is picked up again when the screen opens.
 */
@Composable
fun RidesHost(onExit: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val state = remember { RidesState() }
    val camera = rememberDorrCamera(Riyadh, 15f)
    var hasLocation by remember {
        mutableStateOf(ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED)
    }
    var resumed by remember { mutableStateOf(false) }
    val askLocation = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { granted ->
        hasLocation = granted.values.any { it }
    }
    // O-48: allowed *and* switched on (the gate below asks for both); `located` = the real position is known.
    var locationOk by remember { mutableStateOf(locationReady(context)) }
    var located by remember { mutableStateOf(false) }
    // O-87: no name or no gender yet — asked first, and the window stays until both are given.
    RidesIdentityGate()

    // An active ride first: the app may have been closed in the middle of one.
    LaunchedEffect(Unit) {
        // O-53: the admin's map engine (already read from the phone at start) — any change, for next time too.
        MapEngine.refresh(context) { ApiClient.rides.mapConfig(ridesAuth()).data?.let { Triple(it.provider, it.mapboxToken, it.mapboxStyle) } }
        runCatching { ApiClient.rides.current(ridesAuth()).data }.getOrNull()?.takeIf { it.isJsonObject }?.let {
            state.ride = Gson().fromJson(it, RideDto::class.java)
        }
        resumed = true
        // A check-in still waiting after a ride that ended (R-223).
        state.pendingCheck = runCatching { ApiClient.rides.safety(ridesAuth()).data?.pendingCheck }.getOrNull()
    }

    // A tapped ride notification: that ride — an ended one shows its receipt and the rating (O-41);
    // `rides/current` alone never returns a finished ride.
    // A booked service's notification: open that service (it opens the booking itself).
    val serviceLinked = ServiceLink.pending
    LaunchedEffect(serviceLinked) {
        when (serviceLinked?.first) {
            "loyalty" -> { state.loyaltyOpen = true; ServiceLink.pending = null }
            "bus" -> state.busOpen = true
            "marine" -> state.marineOpen = true
            "hire" -> state.hireOpen = true
            "lesson" -> state.lessonsOpen = true
        }
    }
    val linked = RidesLink.pending
    LaunchedEffect(linked) {
        val uuid = linked ?: return@LaunchedEffect
        RidesLink.pending = null
        runCatching { ApiClient.rides.ride(ridesAuth(), uuid).data }.getOrNull()?.let { ride ->
            if (state.ride == null || state.ride?.uuid == uuid || ride.status == "completed") state.ride = ride
        }
    }

    // Where am I: the camera goes there, and the service switches for this city are read.
    // O-48, O-50: located again the moment location is allowed and on — and that real place (with its address)
    // becomes the pickup, never the map's starting point.
    LaunchedEffect(hasLocation, locationOk, state.featuresTick) {
        val here = if (hasLocation && locationOk) currentLocation(context) else null
        state.me = here
        if (here != null && state.ride == null) {
            camera.move(here, 16f)
            if (state.step == RideStep.Map) {
                state.pickup = RidePoint(here.latitude, here.longitude, addressOf(context, here) ?: String.format("%.5f, %.5f", here.latitude, here.longitude))
            }
            located = true
        }
        state.features = runCatching { ApiClient.rides.features(ridesAuth(), here?.latitude, here?.longitude).data }.getOrNull()
        loadPlaces(state)
        loadCircle(state)
    }

    // The pin: when the map stops, the point under it becomes the pickup (or the point being picked).
    // O-50: only once the real position is known (or the rider moved the map) — the starting view is no place.
    LaunchedEffect(camera.isMoving, state.step) {
        val byHand = camera.byGesture
        if (camera.isMoving && state.step == RideStep.Map && (located || byHand)) state.pickupLoading = true
        if (!camera.isMoving && state.ride == null && (located || byHand) && (state.step == RideStep.Map || state.step == RideStep.PickOnMap)) {
            val target = camera.target
            val name = addressOf(context, target) ?: String.format("%.5f, %.5f", target.latitude, target.longitude)
            state.pickupLoading = false
            val point = RidePoint(target.latitude, target.longitude, name)
            if (state.step == RideStep.Map) state.pickup = point else state.set(point)
        }
    }

    BackHandler {
        when {
            state.loyaltyOpen -> state.loyaltyOpen = false
            state.busOpen -> state.busOpen = false
            state.marineOpen -> state.marineOpen = false
            state.hireOpen -> state.hireOpen = false
            state.lessonsOpen -> state.lessonsOpen = false
            !state.servicePicked && state.ride == null -> onExit()
            state.scheduleOpen -> state.scheduleOpen = false
            state.safetyOpen -> state.safetyOpen = false
            state.changing != null -> state.changing = null
            state.casesOpen -> state.casesOpen = false
            state.myRidesOpen -> state.myRidesOpen = false
            state.ride != null && state.fromHistory -> { state.ride = null; state.resetBooking() }
            state.ride != null -> onExit()
            state.step == RideStep.Search || state.step == RideStep.PickOnMap -> state.step = if (state.quotes.isNotEmpty()) RideStep.Quotes else RideStep.Map
            state.step == RideStep.Quotes -> state.resetBooking()
            // Back from the map goes back to the services (O-18).
            state.ride == null && state.servicePicked -> state.servicePicked = false
            else -> onExit()
        }
    }

    Box(Modifier.fillMaxSize().background(settingsBackground())) {
        RidesMap(state, camera)

        val pinVisible = state.ride == null && (state.step == RideStep.Map || state.step == RideStep.PickOnMap)
        if (pinVisible) PickupPin(
            lifted = camera.isMoving, color = if (state.step == RideStep.Map) AppColors.brandNavy else AppColors.brandOrange,
            label = stringResource(if (state.step == RideStep.Map) R.string.pk_pin_pickup else R.string.pk_pin_here),
        )

        // Back, and "my location".
        Box(Modifier.fillMaxWidth().statusBarsPadding().padding(16.dp)) {
            RoundButton(Icons.AutoMirrored.Rounded.ArrowBack, Modifier.align(Alignment.TopStart)) {
                if (state.ride == null && state.step != RideStep.Map) state.resetBooking() else onExit()
            }
            Column(Modifier.align(Alignment.TopEnd), horizontalAlignment = Alignment.End) {
                // 🛡️ The safety button, always on top (plan §18.13, R-219).
                RoundButton(Icons.Rounded.Shield) { state.safetyOpen = true }
                Spacer(Modifier.height(10.dp))
                // My rides: current, upcoming and past (R-163..165).
                if (state.ride == null) RoundButton(Icons.Rounded.EventNote) { state.myRidesOpen = true }
                if (state.me != null && state.ride == null) {
                    Spacer(Modifier.height(10.dp))
                    RoundButton(Icons.Rounded.MyLocation) {
                        state.me?.let { scope.launch { camera.animate(it, 16f) } }
                    }
                }
            }
        }

        Box(Modifier.align(Alignment.BottomCenter).fillMaxWidth()) {
            AnimatedContent(
                targetState = when {
                    state.ride != null -> "ride"
                    !resumed -> "loading"
                    state.features != null && !state.features!!.service -> "off"
                    else -> state.step.name
                },
                transitionSpec = { (slideInVertically(tween(320)) { it / 3 } + fadeIn(tween(240))) togetherWith fadeOut(tween(160)) },
                label = "rides-panel",
            ) { panel ->
                when (panel) {
                    "ride" -> RideActivePanel(state, camera, onExit = onExit)
                    "loading" -> PanelCard { Text(stringResource(R.string.rd_locating), color = settingsInk(), modifier = Modifier.padding(8.dp)) }
                    "off" -> RidesUnavailablePanel(
                        reason = state.features?.reason,
                        hasLocation = hasLocation,
                        onAskLocation = { askLocation.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION)) },
                        onRetry = { state.featuresTick++ },
                    )
                    RideStep.Map.name -> WhereToPanel(state, hasLocation, onAskLocation = {
                        askLocation.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
                    })
                    RideStep.PickOnMap.name -> ConfirmPointPanel(state)
                    RideStep.Quotes.name -> QuotesPanel(state, camera)
                    else -> Spacer(Modifier.height(1.dp))
                }
            }
        }

        if (state.step == RideStep.Search && state.ride == null) PlaceSearchPage(state, camera)
        // Block 06: «دُرر يفهم مقصدك» over the map or the search.
        if (state.smartOpen && state.ride == null) SmartDestinationSheet(state, camera) { state.smartOpen = false }

        // O-18: the services first — unless a ride is already on (then straight to it).
        androidx.compose.animation.AnimatedVisibility(
            visible = resumed && state.ride == null && !state.servicePicked,
            enter = fadeIn(tween(220)),
            exit = androidx.compose.animation.slideOutHorizontally(tween(260)) { -it / 3 } + fadeOut(tween(200)),
        ) {
            val ready = buildSet {
                add(RideService.Instant)
                if (state.features?.categories?.get("bus") == true) add(RideService.Bus)
                if (state.features?.categories?.get("marine") == true) add(RideService.Marine)
                if (state.feature("find_driver")) add(RideService.FindDriver)
                if (state.feature("driving_lessons")) add(RideService.Lessons)
            }
            ServicePicker(
                ready = ready,
                onPick = { svc ->
                    when (svc) {
                        RideService.Bus -> state.busOpen = true
                        RideService.Marine -> state.marineOpen = true
                        RideService.FindDriver -> state.hireOpen = true
                        RideService.Lessons -> state.lessonsOpen = true
                        else -> state.servicePicked = true
                    }
                },
                onBack = onExit,
                onLoyalty = if (state.feature("loyalty")) ({ state.loyaltyOpen = true }) else null,
                onMyRides = { state.myRidesOpen = true },
            )
        }

        androidx.compose.animation.AnimatedVisibility(
            visible = state.busOpen,
            enter = androidx.compose.animation.slideInHorizontally(tween(300)) { it } + fadeIn(tween(200)),
            exit = androidx.compose.animation.slideOutHorizontally(tween(260)) { it } + fadeOut(tween(160)),
        ) { BusHost(state, onClose = { state.busOpen = false }) }

        androidx.compose.animation.AnimatedVisibility(
            visible = state.marineOpen,
            enter = androidx.compose.animation.slideInHorizontally(tween(300)) { it } + fadeIn(tween(200)),
            exit = androidx.compose.animation.slideOutHorizontally(tween(260)) { it } + fadeOut(tween(160)),
        ) { MarineHost(state, onClose = { state.marineOpen = false }) }

        androidx.compose.animation.AnimatedVisibility(
            visible = state.loyaltyOpen,
            enter = androidx.compose.animation.slideInVertically(tween(320)) { it / 3 } + fadeIn(tween(220)),
            exit = androidx.compose.animation.slideOutVertically(tween(260)) { it / 3 } + fadeOut(tween(160)),
        ) { LoyaltyScreen(onClose = { state.loyaltyOpen = false }) }

        androidx.compose.animation.AnimatedVisibility(
            visible = state.hireOpen,
            enter = androidx.compose.animation.slideInHorizontally(tween(300)) { it } + fadeIn(tween(200)),
            exit = androidx.compose.animation.slideOutHorizontally(tween(260)) { it } + fadeOut(tween(160)),
        ) { HireHost(state, onClose = { state.hireOpen = false }) }

        androidx.compose.animation.AnimatedVisibility(
            visible = state.lessonsOpen,
            enter = androidx.compose.animation.slideInHorizontally(tween(300)) { it } + fadeIn(tween(200)),
            exit = androidx.compose.animation.slideOutHorizontally(tween(260)) { it } + fadeOut(tween(160)),
        ) { LessonsHost(state, onClose = { state.lessonsOpen = false }) }

        androidx.compose.animation.AnimatedVisibility(
            visible = state.myRidesOpen,
            enter = androidx.compose.animation.slideInHorizontally(tween(300)) { it } + fadeIn(tween(200)),
            exit = androidx.compose.animation.slideOutHorizontally(tween(260)) { it } + fadeOut(tween(160)),
        ) { MyRidesPage(state, onClose = { state.myRidesOpen = false }) }

        androidx.compose.animation.AnimatedVisibility(
            visible = state.casesOpen,
            enter = androidx.compose.animation.slideInHorizontally(tween(300)) { it } + fadeIn(tween(200)),
            exit = androidx.compose.animation.slideOutHorizontally(tween(260)) { it } + fadeOut(tween(160)),
        ) { CasesPage(state, onClose = { state.casesOpen = false }) }

        androidx.compose.animation.AnimatedVisibility(
            visible = state.changing != null && state.ride != null,
            enter = androidx.compose.animation.slideInVertically(tween(300)) { it / 2 } + fadeIn(tween(200)),
            exit = fadeOut(tween(160)),
        ) { ChangeTripPage(state, onClose = { state.changing = null }) }

        androidx.compose.animation.AnimatedVisibility(
            visible = state.safetyOpen,
            enter = androidx.compose.animation.slideInHorizontally(tween(300)) { it } + fadeIn(tween(200)),
            exit = androidx.compose.animation.slideOutHorizontally(tween(260)) { it } + fadeOut(tween(160)),
        ) { SafetyCenterPage(state, onClose = { state.safetyOpen = false }) }

        // O-48: nothing here works without the location — a window that stays until it's allowed and on.
        if (!locationOk || !hasLocation) {
            LocationGate(onReady = { hasLocation = true; locationOk = true }, onLeave = onExit)
        }

        // O-09: the in-app call with the captain, over everything.
        val callContext = androidx.compose.ui.platform.LocalContext.current
        RideCallOverlay(onAccept = {
            val ride = RideCallSession.rideUuid
            val call = RideCallSession.callUuid
            val other = RideCallSession.acceptApi
            if (ride != null && call != null) RideCallSession.accept(callContext) {
                other?.invoke(ride, call) ?: ApiClient.rides.acceptCall(ridesAuth(), ride, call).data!!.let { RideCallJoin(it.uuid, it.join.url, it.join.token) }
            }
        })
    }

    if (state.scheduleOpen) SchedulePickerSheet(state, onClose = { state.scheduleOpen = false })
    // "Are you OK?" — during the ride (from the ride) or after it (the check-in).
    val check = state.ride?.safetyCheck ?: state.pendingCheck
    val checkRide = state.ride?.takeIf { it.safetyCheck != null }?.uuid ?: state.pendingCheck?.rideUuid
    if (check != null && checkRide != null) SafetyCheckDialog(checkRide, check, onDone = {
        state.pendingCheck = null
        state.ride = state.ride?.copy(safetyCheck = null)
    })
}

@Composable
private fun RidesMap(state: RidesState, camera: DorrCamera) {
    val ride = state.ride
    val stopsKey = ride?.stops?.map { it.lat to it.lng } ?: state.stops.map { it.lat to it.lng }
    val route = remember(ride?.route?.polyline, state.selected?.route?.polyline, stopsKey, ride?.destination?.lat, state.destination?.lat) {
        decodePolyline(ride?.route?.polyline ?: state.selected?.route?.polyline).ifEmpty {
            // O-56: no drawn route (e.g. right after a stop is added) — the points joined in order.
            listOfNotNull(
                ride?.origin?.let { LatLng(it.lat, it.lng) } ?: state.pickup?.latLng,
                *stopsKey.map { LatLng(it.first, it.second) }.toTypedArray(),
                ride?.destination?.let { LatLng(it.lat, it.lng) } ?: state.destination?.latLng,
            ).takeIf { it.size >= 2 && (ride != null || state.step == RideStep.Quotes) }.orEmpty()
        }
    }
    val showPoints = ride != null || state.step == RideStep.Quotes
    val nearby = rememberNearbyCars(state.me ?: state.pickup?.latLng, enabled = ride == null)
    val pins = buildList {
        if (showPoints) {
            (ride?.origin?.let { LatLng(it.lat, it.lng) } ?: state.pickup?.latLng)?.let { add(MapPin(it, PinKind.Pickup)) }
            (ride?.stops?.map { LatLng(it.lat, it.lng) } ?: state.stops.map { it.latLng }).forEach { add(MapPin(it, PinKind.Stop)) }
            (ride?.destination?.let { LatLng(it.lat, it.lng) } ?: state.destination?.latLng)?.let { add(MapPin(it, PinKind.Dropoff)) }
        }
        ride?.driver?.location?.let { loc -> add(MapPin(LatLng(loc.lat, loc.lng), PinKind.Car, (loc.heading ?: 0).toFloat(), id = "my-captain")) }
        // O-59: before a ride, the free captains' cars around, moving.
        if (ride == null) nearby.forEach { (id, car) -> add(MapPin(car.at, PinKind.Car, car.heading, id = "car:$id")) }
    }
    val width = dpPx(5f)
    // O-53: the admin's engine (Google or Mapbox) draws it — the pins, the route and the car are the same on both.
    DorrMap(
        camera = camera,
        modifier = Modifier.fillMaxSize(),
        pins = pins,
        lines = if (showPoints && route.size > 1) listOf(MapLine(route, MapColors.route, width)) else emptyList(),
        padding = androidx.compose.foundation.layout.PaddingValues(bottom = 260.dp),
    )

    // Keep the whole trip in view once it is planned or running.
    val fitPadding = dpPx(48f).toInt()
    LaunchedEffect(route.size, ride?.status) {
        val points = route.ifEmpty { listOfNotNull(ride?.origin?.let { LatLng(it.lat, it.lng) }, ride?.destination?.let { LatLng(it.lat, it.lng) }) }
        camera.fit(points, fitPadding)
    }
}

@Composable
internal fun RoundButton(icon: androidx.compose.ui.graphics.vector.ImageVector, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Box(
        modifier.size(44.dp)
            .then(if (settingsNight()) Modifier.border(1.dp, AccountDark.line, CircleShape) else Modifier.shadow(6.dp, CircleShape))
            .clip(CircleShape).background(settingsCard()).pressable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, null, tint = settingsInk()) }
}

/** The white card at the bottom that every step draws into; it grows and shrinks smoothly as a step changes. */
@Composable
internal fun PanelCard(content: @Composable () -> Unit) {
    val shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp)
    Column(
        Modifier.fillMaxWidth().shadow(18.dp, shape)
            .clip(shape).background(settingsCard())
            .then(if (settingsNight()) Modifier.border(1.dp, AccountDark.line, shape) else Modifier)
            .animateContentSize()
            // The card runs under the phone's navigation bar; its content stays above it, like every other
            // screen in the app (O-38d).
            .navigationBarsPadding()
            .padding(horizontal = 18.dp).padding(top = 10.dp, bottom = 24.dp),
    ) {
        Box(Modifier.align(Alignment.CenterHorizontally).size(width = 38.dp, height = 4.dp).clip(CircleShape).background(settingsInk().copy(alpha = 0.15f)))
        Spacer(Modifier.height(12.dp))
        content()
    }
}

/** The main action of a step: rounded, in the app colour, with its own glow by day and a small press-in. */
@Composable
internal fun AccentButton(
    text: String,
    enabled: Boolean = true,
    busy: Boolean = false,
    color: Color = settingsAccent(),
    icon: androidx.compose.ui.graphics.vector.ImageVector? = null,
    onClick: () -> Unit,
) {
    val shape = RoundedCornerShape(18.dp)
    val fill by androidx.compose.animation.animateColorAsState(if (enabled) color else color.copy(alpha = 0.4f), tween(200), label = "accent-button")
    Box(
        Modifier.fillMaxWidth().height(54.dp)
            .then(if (enabled && !busy && !settingsNight()) Modifier.shadow(10.dp, shape, ambientColor = color.copy(alpha = 0.3f), spotColor = color.copy(alpha = 0.38f)) else Modifier)
            .clip(shape).background(fill)
            .pressable(enabled = enabled && !busy, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        if (busy) androidx.compose.material3.CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
        else Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = androidx.compose.foundation.layout.Arrangement.spacedBy(8.dp)) {
            if (icon != null) Icon(icon, null, tint = Color.White, modifier = Modifier.size(20.dp))
            Text(text, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 16.sp)
        }
    }
}

/** A ride to open when the rides screen shows — set by a tapped ride notification (O-41). */
object RidesLink {
    var pending by androidx.compose.runtime.mutableStateOf<String?>(null)

    fun show(uuid: String) {
        pending = uuid
    }
}
