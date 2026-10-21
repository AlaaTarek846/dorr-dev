package com.dorr.driver.ui

import android.content.Context
import android.util.Patterns
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowForward
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Commute
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.DirectionsCar
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.EventSeat
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.HowToReg
import androidx.compose.material.icons.rounded.LocationCity
import androidx.compose.material.icons.rounded.Numbers
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.Sync
import androidx.compose.material.icons.rounded.Tag
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.TextFields
import androidx.compose.material.icons.rounded.Verified
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.ColorOptionDto
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.OnboardingDto
import com.dorr.driver.net.OnboardingOptionsDto
import com.dorr.driver.net.RegulatorDto
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.BannerKind
import com.dorr.driver.ui.kit.Chip
import com.dorr.driver.ui.kit.Choice
import com.dorr.driver.ui.kit.DateField
import com.dorr.driver.ui.kit.DorrTextField
import com.dorr.driver.ui.kit.HeroBadge
import com.dorr.driver.ui.kit.OutlineButton
import com.dorr.driver.ui.kit.PrimaryButton
import com.dorr.driver.ui.kit.ScreenScaffold
import com.dorr.driver.ui.kit.Segmented
import com.dorr.driver.ui.kit.SelectField
import com.dorr.driver.ui.kit.Swatch
import com.dorr.driver.ui.kit.TileIcon
import com.dorr.driver.ui.kit.bounceClick
import com.dorr.driver.ui.kit.prettyDate
import com.dorr.driver.ui.kit.staggerIn
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.Pal
import com.google.gson.JsonObject
import kotlinx.coroutines.launch
import java.time.LocalDate

private const val STEP_DETAILS = 0
private const val STEP_VEHICLE = 1
private const val STEP_DOCUMENTS = 2
private const val STEP_REVIEW = 3

/**
 * Signing up as a captain, one step at a time: details → vehicle → the documents the server says this driver needs
 * (their country, citizen or resident) → review and send — then Wasl where there is one. A bar on top shows where you
 * are and what is done; it opens on the first step still to do; each step checks its fields before saving.
 */
@Composable
fun SignupScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var state by remember { mutableStateOf<OnboardingDto?>(null) }
    var options by remember { mutableStateOf(OnboardingOptionsDto()) }
    var regulator by remember { mutableStateOf<RegulatorDto?>(null) }
    var loadError by remember { mutableStateOf<String?>(null) }
    var step by rememberSaveable { mutableIntStateOf(-1) }

    suspend fun load() {
        loadError = null
        when (val r = apiCall { DriverBackend.api.onboarding() }) {
            is ApiResult.Ok -> state = r.data
            is ApiResult.Failed -> loadError = r.error.displayMessage
            is ApiResult.Offline -> loadError = context.getString(R.string.error_offline)
        }
        (apiCall { DriverBackend.api.regulator() } as? ApiResult.Ok)?.let { regulator = it.data }
    }
    LaunchedEffect(Unit) {
        load()
        (apiCall { DriverBackend.api.onboardingOptions() } as? ApiResult.Ok)?.let { options = it.data }
    }
    // O-66: reviewed from the admin (a document, the vehicle, the account) — the page follows at once.
    LaunchedEffect(Unit) { com.dorr.driver.live.DriverLive.events.collect { e -> if (e.name.startsWith("driver.")) load() } }
    // Opens on the first step still to do (once; after that the driver moves).
    LaunchedEffect(state != null) {
        val s = state ?: return@LaunchedEffect
        if (step < 0) step = firstOpenStep(s)
    }

    fun goBack() {
        if (step > 0) step -= 1 else onBack()
    }
    BackHandler { goBack() }

    ScreenScaffold(stringResource(R.string.ob_title), onBack = { goBack() }) {
        val s = state
        if (s == null || step < 0) {
            Box(Modifier.fillMaxWidth().weight(1f).padding(24.dp), contentAlignment = Alignment.Center) {
                if (loadError == null) {
                    CircularProgressIndicator(color = Pal.accent)
                } else {
                    Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
                        Banner(loadError)
                        OutlineButton(stringResource(R.string.retry)) { scope.launch { load() } }
                    }
                }
            }
        } else {
            StepBar(step, s) { step = it }
            val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
            AnimatedContent(
                targetState = step,
                transitionSpec = {
                    // Forward slides in from the reading end (the right in Arabic), back from the other side.
                    val sign = (if (targetState > initialState) 1 else -1) * (if (rtl) -1 else 1)
                    (slideInHorizontally(tween(320)) { it * sign / 3 } + fadeIn(tween(320))) togetherWith
                        (slideOutHorizontally(tween(260)) { -it * sign / 3 } + fadeOut(tween(200)))
                },
                modifier = Modifier.fillMaxWidth().weight(1f),
                label = "signup-step",
            ) { current ->
                when (current) {
                    STEP_DETAILS -> DetailsStep(s, options) { state = it; step = STEP_VEHICLE }
                    STEP_VEHICLE -> VehicleStep(s, options, onSaved = { state = it; step = STEP_DOCUMENTS }, onNext = { step = STEP_DOCUMENTS })
                    STEP_DOCUMENTS -> DocumentsStep(s, onUpdated = { state = it }, onNeedVehicle = { step = STEP_VEHICLE }, onNext = { step = STEP_REVIEW })
                    else -> ReviewStep(
                        s, options, regulator,
                        onRegulator = { regulator = it }, onState = { state = it }, onEdit = { step = it }, onClose = onBack,
                    )
                }
            }
        }
    }
}

private fun firstOpenStep(s: OnboardingDto): Int = when {
    !s.steps.profile -> STEP_DETAILS
    !s.steps.vehicle -> STEP_VEHICLE
    !s.steps.documents -> STEP_DOCUMENTS
    else -> STEP_REVIEW
}

// ---------------------------------------------------------------- the bar of steps

@Composable
private fun StepBar(current: Int, s: OnboardingDto, onStep: (Int) -> Unit) {
    val labels = listOf(R.string.ob_details, R.string.ob_vehicle, R.string.ob_documents, R.string.ob_review)
    val icons = listOf(Icons.Rounded.Person, Icons.Rounded.DirectionsCar, Icons.Rounded.Description, Icons.Rounded.TaskAlt)
    val done = listOf(s.steps.profile, s.steps.vehicle, s.steps.documents, s.driver.status != "pending_documents")
    Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp).padding(top = 2.dp, bottom = 10.dp), verticalAlignment = Alignment.Top) {
        labels.forEachIndexed { i, label ->
            if (i > 0) {
                // The line between two steps fills once the one before is done.
                val fill by animateFloatAsState(if (done[i - 1]) 1f else 0f, tween(450), label = "step-line")
                Box(Modifier.weight(1f).padding(top = 19.dp).height(3.dp).clip(CircleShape).background(Pal.line)) {
                    Box(Modifier.fillMaxHeight().fillMaxWidth(fill).background(Pal.success))
                }
            }
            StepDot(icons[i], stringResource(label), active = i == current, done = done[i]) { onStep(i) }
        }
    }
}

@Composable
private fun StepDot(icon: ImageVector, label: String, active: Boolean, done: Boolean, onClick: () -> Unit) {
    val fill by animateColorAsState(
        when {
            active -> Pal.accent
            done -> Pal.success
            else -> Pal.card
        },
        tween(250), label = "step-fill",
    )
    val scale by animateFloatAsState(if (active) 1.1f else 1f, spring(dampingRatio = Spring.DampingRatioMediumBouncy), label = "step-scale")
    Column(Modifier.width(66.dp).bounceClick(onClick = onClick), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(
            Modifier.size(40.dp).scale(scale).clip(CircleShape).background(fill)
                .border(1.dp, if (active || done) Color.Transparent else Pal.line, CircleShape),
            contentAlignment = Alignment.Center,
        ) {
            Icon(if (done && !active) Icons.Rounded.Check else icon, null, tint = if (active || done) Color.White else Pal.mut, modifier = Modifier.size(20.dp))
        }
        Text(
            label, color = if (active) Pal.accent else Pal.mut, fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
            fontWeight = if (active) FontWeight.ExtraBold else FontWeight.Medium, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 5.dp),
        )
    }
}

// ---------------------------------------------------------------- the frame of one step

/** A step: its fields scrolling, the action pinned under them (above the keyboard). */
@Composable
private fun StepFrame(title: String, hint: String, footer: @Composable ColumnScope.() -> Unit, content: @Composable ColumnScope.() -> Unit) {
    Column(Modifier.fillMaxSize().imePadding()) {
        Column(
            Modifier.weight(1f).fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 16.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            Column(Modifier.staggerIn(0)) {
                Text(title, color = Pal.ink, fontSize = 22.sp, fontWeight = FontWeight.ExtraBold)
                Text(hint, color = Pal.mut, fontSize = 13.sp, lineHeight = 19.sp, modifier = Modifier.padding(top = 2.dp))
            }
            content()
        }
        Column(
            Modifier.fillMaxWidth().background(Pal.card).padding(horizontal = 18.dp, vertical = 12.dp).navigationBarsPadding(),
            verticalArrangement = Arrangement.spacedBy(8.dp),
            content = footer,
        )
    }
}

/** What is wrong with a field, under it — sliding in when there is something to say. */
@Composable
private fun FieldHint(text: String?) {
    // The last words stay while it slides out.
    val last = remember { arrayOfNulls<String>(1) }
    if (text != null) last[0] = text
    AnimatedVisibility(text != null, enter = fadeIn(tween(160)) + expandVertically(tween(200)), exit = fadeOut(tween(120)) + shrinkVertically(tween(160))) {
        Row(Modifier.padding(start = 14.dp, top = 4.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
            Icon(Icons.Rounded.WarningAmber, null, tint = Pal.danger, modifier = Modifier.size(14.dp))
            Text(last[0].orEmpty(), color = Pal.danger, fontSize = 12.sp, fontWeight = FontWeight.Medium)
        }
    }
}

/** The field's own check once the driver tried to save, else what the server said about it. */
@Composable
private fun problem(tried: Boolean, local: Int?, server: Map<String, List<String>>, key: String): String? =
    if (tried && local != null) stringResource(local) else server[key]?.firstOrNull()

@Composable
private fun Caption(text: String) {
    Text(text, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = Pal.ink, modifier = Modifier.padding(bottom = 8.dp))
}

// ---------------------------------------------------------------- 1. details

@Composable
private fun DetailsStep(s: OnboardingDto, options: OnboardingOptionsDto, onSaved: (OnboardingDto) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val p = s.profile
    val locked = p.locked
    var name by rememberSaveable { mutableStateOf(p.name.orEmpty()) }
    var email by rememberSaveable { mutableStateOf(p.email.orEmpty()) }
    var gender by rememberSaveable { mutableStateOf(p.gender) }
    var idType by rememberSaveable { mutableStateOf(p.idType ?: "national_id") }
    var nationalId by rememberSaveable { mutableStateOf(p.nationalId.orEmpty()) }
    var dob by rememberSaveable { mutableStateOf(p.dateOfBirth) }
    var nationality by rememberSaveable { mutableStateOf(p.nationalityCountryId) }
    var city by rememberSaveable { mutableStateOf(p.cityId) }
    var tried by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var server by remember { mutableStateOf<Map<String, List<String>>>(emptyMap()) }

    // The server's own rules: a name, an ID number (max 20), a date of birth over 18, a valid email if given.
    val latestBirth = LocalDate.now().minusYears(18).minusDays(1)
    val nameLocal = if (name.isBlank()) R.string.ob_err_required else null
    val emailLocal = if (email.isNotBlank() && !Patterns.EMAIL_ADDRESS.matcher(email.trim()).matches()) R.string.ob_err_email else null
    val idLocal = if (!locked && nationalId.isBlank()) R.string.ob_err_required else null
    val dobLocal = when {
        locked -> null
        dob.isNullOrBlank() -> R.string.ob_err_required
        runCatching { LocalDate.parse(dob!!.take(10)).isAfter(latestBirth) }.getOrDefault(false) -> R.string.ob_err_adult
        else -> null
    }
    val valid = listOfNotNull(nameLocal, emailLocal, idLocal, dobLocal).isEmpty()

    fun save() {
        tried = true
        if (!valid || busy) return
        busy = true
        error = null
        val body = JsonObject().apply {
            addProperty("name", name.trim())
            if (email.isNotBlank()) addProperty("email", email.trim())
            city?.let { addProperty("city_id", it) }
            if (!locked) {
                addProperty("id_type", idType)
                addProperty("national_id", nationalId.trim())
                dob?.let { addProperty("date_of_birth", it) }
                gender?.let { addProperty("gender", it) }
                nationality?.let { addProperty("nationality_country_id", it) }
            }
        }
        scope.launch {
            when (val r = apiCall { DriverBackend.api.updateProfile(body) }) {
                is ApiResult.Ok -> onSaved(r.data)
                is ApiResult.Failed -> { server = r.error.fieldErrors; error = r.error.displayMessage }
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }

    StepFrame(
        stringResource(R.string.ob_details), stringResource(R.string.ob_details_hint),
        footer = {
            Banner(error)
            PrimaryButton(stringResource(R.string.ob_next), busy = busy, icon = Icons.AutoMirrored.Rounded.ArrowForward, onClick = { save() })
        },
    ) {
        if (locked) Banner(stringResource(R.string.ob_locked), kind = BannerKind.Info)

        Column(Modifier.staggerIn(1)) {
            DorrTextField(
                value = name, onValueChange = { name = it.take(150); server = server - "name" }, label = stringResource(R.string.ob_name),
                placeholder = stringResource(R.string.ob_name_hint), icon = Icons.Rounded.Person, error = problem(tried, nameLocal, server, "name") != null,
                keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Words, imeAction = ImeAction.Next),
            )
            FieldHint(problem(tried, nameLocal, server, "name"))
        }

        Column(Modifier.staggerIn(2).alpha(if (locked) 0.6f else 1f)) {
            Caption(stringResource(R.string.ob_gender))
            Segmented(
                options = options.genders.ifEmpty { listOf("male", "female") }.map { it to genderLabel(context, it) },
                selected = gender.orEmpty(), onSelect = { if (!locked) gender = it },
            )
        }

        Column(Modifier.staggerIn(3).alpha(if (locked) 0.6f else 1f)) {
            Caption(stringResource(R.string.ob_id_type))
            Segmented(
                options = options.idTypes.ifEmpty { listOf("national_id", "iqama", "passport") }.map { it to idLabel(context, it) },
                selected = idType, onSelect = { if (!locked) { idType = it; server = server - "national_id" } },
            )
        }

        Column(Modifier.staggerIn(4)) {
            val passport = idType == "passport"
            DorrTextField(
                value = nationalId,
                onValueChange = { input ->
                    // A national ID / iqama is digits; a passport also has letters.
                    nationalId = (if (passport) input.filter(Char::isLetterOrDigit).uppercase() else input.filter(Char::isDigit)).take(20)
                    server = server - "national_id"
                },
                label = stringResource(R.string.ob_national_id), placeholder = stringResource(R.string.ob_national_id_hint), icon = Icons.Rounded.Badge,
                enabled = !locked, error = problem(tried, idLocal, server, "national_id") != null,
                keyboardOptions = KeyboardOptions(keyboardType = if (passport) KeyboardType.Ascii else KeyboardType.Number, imeAction = ImeAction.Next),
            )
            FieldHint(problem(tried, idLocal, server, "national_id"))
        }

        Column(Modifier.staggerIn(5)) {
            DateField(
                stringResource(R.string.ob_dob), dob, onPick = { dob = it; server = server - "date_of_birth" },
                max = latestBirth, min = LocalDate.now().minusYears(90), enabled = !locked,
                error = problem(tried, dobLocal, server, "date_of_birth") != null,
            )
            FieldHint(problem(tried, dobLocal, server, "date_of_birth"))
        }

        SelectField(
            label = stringResource(R.string.ob_nationality), icon = Icons.Rounded.Public,
            choices = options.nationalities.map { Choice(it.id.toString(), it.name ?: it.code ?: "#${it.id}", flag = it.code) },
            selectedId = nationality?.toString(), onPick = { nationality = it.id.toInt() }, enabled = !locked,
            modifier = Modifier.staggerIn(6),
        )

        if (options.cities.isNotEmpty()) {
            SelectField(
                label = stringResource(R.string.ob_city), icon = Icons.Rounded.LocationCity,
                choices = options.cities.map { Choice(it.id.toString(), it.name ?: "#${it.id}") },
                selectedId = city?.toString(), onPick = { city = it.id.toInt() }, modifier = Modifier.staggerIn(7),
            )
        }

        Column(Modifier.staggerIn(8)) {
            DorrTextField(
                value = email, onValueChange = { email = it.trim().take(191); server = server - "email" }, label = stringResource(R.string.ob_email),
                placeholder = "name@example.com", icon = Icons.Rounded.Email, error = problem(tried, emailLocal, server, "email") != null,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email, imeAction = ImeAction.Done),
            )
            FieldHint(problem(tried, emailLocal, server, "email"))
        }
    }
}

// ---------------------------------------------------------------- 2. vehicle

@OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
@Composable
private fun VehicleStep(s: OnboardingDto, options: OnboardingOptionsDto, onSaved: (OnboardingDto) -> Unit, onNext: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val v = s.vehicle
    var make by rememberSaveable { mutableStateOf(v?.makeId) }
    var model by rememberSaveable { mutableStateOf(v?.modelId) }
    var color by rememberSaveable { mutableStateOf(v?.colorId) }
    var year by rememberSaveable { mutableStateOf(v?.year) }
    var letters by rememberSaveable { mutableStateOf(v?.plateLetters.orEmpty()) }
    var numbers by rememberSaveable { mutableStateOf(v?.plateNumber.orEmpty()) }
    var sequence by rememberSaveable { mutableStateOf(v?.sequenceNumber.orEmpty()) }
    // O-64: what kind of vehicle first (the makes follow it) — or none at all.
    var kind by rememberSaveable { mutableStateOf(if (s.noVehicle && v == null) "none" else vehicleKind(v?.bodyType)) }
    var tried by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var server by remember { mutableStateOf<Map<String, List<String>>>(emptyMap()) }

    val makes = options.makes.filter { m -> m.models.any { vehicleKind(it.bodyType) == kind } }
    val models = options.makes.firstOrNull { it.id == make }?.models.orEmpty().filter { vehicleKind(it.bodyType) == kind }
    val chosenModel = models.firstOrNull { it.id == model }
    val water = kind in setOf("boat", "yacht")

    // An approved vehicle can't be edited here (the server refuses it): show it, and move on.
    if (v != null && v.status == "approved") {
        StepFrame(
            stringResource(R.string.ob_vehicle), stringResource(R.string.ob_vehicle_hint),
            footer = { PrimaryButton(stringResource(R.string.ob_continue), icon = Icons.AutoMirrored.Rounded.ArrowForward, onClick = onNext) },
        ) {
            Banner(stringResource(R.string.ob_vehicle_locked), kind = BannerKind.Success)
            VehicleSummary(context, v.make, v.model, v.year, v.color, v.colorHex, v.plateLetters, v.plateNumber, Modifier.staggerIn(1))
        }
        return
    }

    val missing = R.string.ob_err_required
    val makeLocal = if (make == null) missing else null
    val modelLocal = if (model == null) missing else null
    val colorLocal = if (color == null) missing else null
    val yearLocal = if (year == null) missing else null
    val lettersLocal = if (letters.isBlank()) missing else null
    val numbersLocal = if (numbers.isBlank()) missing else null
    // O-64: the sequence number is required (a boat or a yacht has none).
    val sequenceLocal = if (!water && sequence.isBlank()) missing else null
    val valid = listOfNotNull(makeLocal, modelLocal, colorLocal, yearLocal, lettersLocal, numbersLocal, sequenceLocal).isEmpty()

    // O-64: no vehicle — the step is done without one.
    fun noVehicle() {
        if (busy) return
        busy = true
        error = null
        scope.launch {
            when (val r = apiCall { DriverBackend.api.noVehicle(JsonObject().apply { addProperty("on", true) }) }) {
                is ApiResult.Ok -> onSaved(r.data)
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }

    fun save() {
        tried = true
        if (!valid || busy) return
        busy = true
        error = null
        val body = JsonObject().apply {
            addProperty("make_id", make)
            addProperty("model_id", model)
            addProperty("color_id", color)
            addProperty("year", year)
            // The model says what it is and how many it seats.
            addProperty("body_type", chosenModel?.bodyType ?: v?.bodyType ?: "sedan")
            addProperty("seats", chosenModel?.seats ?: v?.seats ?: 4)
            addProperty("plate_letters", letters.trim())
            addProperty("plate_number", numbers.trim())
            if (sequence.isNotBlank()) addProperty("sequence_number", sequence.trim())
        }
        scope.launch {
            if (s.noVehicle) apiCall { DriverBackend.api.noVehicle(JsonObject().apply { addProperty("on", false) }) }
            val r = if (v == null) apiCall { DriverBackend.api.addVehicle(body) } else apiCall { DriverBackend.api.updateVehicle(v.id, body) }
            when (r) {
                is ApiResult.Ok -> onSaved(r.data)
                is ApiResult.Failed -> { server = r.error.fieldErrors; error = r.error.displayMessage }
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }

    StepFrame(
        stringResource(R.string.ob_vehicle), stringResource(R.string.ob_vehicle_hint),
        footer = {
            Banner(error)
            PrimaryButton(stringResource(R.string.ob_next), busy = busy, icon = Icons.AutoMirrored.Rounded.ArrowForward, onClick = { if (kind == "none") noVehicle() else save() })
        },
    ) {
        if (v?.status == "rejected") Banner(stringResource(R.string.ob_vehicle_rejected), kind = BannerKind.Warning)

        // O-64: the kind of vehicle — a car, a motorcycle, a bus, a boat, a yacht — or none.
        Column(Modifier.staggerIn(0)) {
            Caption(stringResource(R.string.ob_vehicle_kind))
            androidx.compose.foundation.layout.FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("car" to R.string.vk_car, "motorcycle" to R.string.vk_motorcycle, "bus" to R.string.vk_bus, "boat" to R.string.vk_boat, "yacht" to R.string.vk_yacht, "none" to R.string.vk_none).forEach { (k, label) ->
                    Chip(stringResource(label), selected = kind == k) { if (kind != k) { kind = k; make = null; model = null } }
                }
            }
        }
        if (kind == "none") {
            Banner(stringResource(R.string.ob_no_vehicle_hint), kind = BannerKind.Info)
            return@StepFrame
        }

        Column(Modifier.staggerIn(1)) {
            SelectField(
                label = stringResource(R.string.ob_make), icon = Icons.Rounded.DirectionsCar,
                choices = makes.map { Choice(it.id.toString(), it.name ?: "#${it.id}") },
                selectedId = make?.toString(), searchable = true,
                onPick = { picked -> if (picked.id.toInt() != make) { make = picked.id.toInt(); model = null }; server = server - "make_id" },
                error = problem(tried, makeLocal, server, "make_id") != null,
            )
            FieldHint(problem(tried, makeLocal, server, "make_id"))
        }

        Column(Modifier.staggerIn(2)) {
            SelectField(
                label = stringResource(R.string.ob_model), icon = Icons.Rounded.Commute,
                choices = models.map { m ->
                    Choice(m.id.toString(), m.name ?: "#${m.id}", sub = listOfNotNull(m.bodyType?.let { bodyLabel(context, it) }, m.seats?.let { context.getString(R.string.ob_seats_count, it) }).joinToString(" · ").ifBlank { null })
                },
                selectedId = model?.toString(), searchable = models.size > 8, enabled = make != null,
                placeholder = if (make == null) stringResource(R.string.ob_make_first) else null,
                onPick = { model = it.id.toInt(); server = server - "model_id" },
                error = problem(tried, modelLocal, server, "model_id") != null,
            )
            FieldHint(problem(tried, modelLocal, server, "model_id"))
            AnimatedVisibility(chosenModel != null, enter = fadeIn() + expandVertically(), exit = fadeOut() + shrinkVertically()) {
                Row(Modifier.padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    chosenModel?.bodyType?.let { Chip(bodyLabel(context, it)) }
                    chosenModel?.seats?.let {
                        Icon(Icons.Rounded.EventSeat, null, tint = Pal.mut, modifier = Modifier.size(16.dp))
                        Text(stringResource(R.string.ob_seats_count, it), color = Pal.mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    }
                }
            }
        }

        Column(Modifier.staggerIn(3)) {
            Caption(stringResource(R.string.ob_color))
            ColorPicker(options.colors, color, error = problem(tried, colorLocal, server, "color_id") != null) { color = it; server = server - "color_id" }
            FieldHint(problem(tried, colorLocal, server, "color_id"))
        }

        Column(Modifier.staggerIn(4)) {
            val thisYear = LocalDate.now().year
            SelectField(
                label = stringResource(R.string.ob_year), icon = Icons.Rounded.CalendarMonth,
                choices = (thisYear + 1 downTo 1980).map { Choice(it.toString(), it.toString()) },
                selectedId = year?.toString(), searchable = false,
                onPick = { year = it.id.toInt(); server = server - "year" },
                error = problem(tried, yearLocal, server, "year") != null,
            )
            FieldHint(problem(tried, yearLocal, server, "year"))
        }

        Column(Modifier.staggerIn(5), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(stringResource(R.string.ob_plate), fontSize = 13.sp, fontWeight = FontWeight.Bold, color = Pal.ink)
            // The plate as it will look, while it is typed.
            PlatePreview(letters, numbers, Modifier.align(Alignment.CenterHorizontally))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Column(Modifier.weight(1f)) {
                    DorrTextField(
                        value = letters, onValueChange = { letters = it.filter { c -> c.isLetter() || c == ' ' }.uppercase().take(8); server = server - "plate_letters" },
                        label = stringResource(R.string.ob_plate_letters), placeholder = "ABC", icon = Icons.Rounded.TextFields,
                        error = problem(tried, lettersLocal, server, "plate_letters") != null,
                        keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Characters, imeAction = ImeAction.Next),
                    )
                    FieldHint(problem(tried, lettersLocal, server, "plate_letters"))
                }
                Column(Modifier.weight(1f)) {
                    DorrTextField(
                        value = numbers, onValueChange = { numbers = it.filter(Char::isDigit).take(8); server = server - "plate_number" },
                        label = stringResource(R.string.ob_plate_number), placeholder = "1234", icon = Icons.Rounded.Numbers,
                        error = problem(tried, numbersLocal, server, "plate_number") != null,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = ImeAction.Next),
                    )
                    FieldHint(problem(tried, numbersLocal, server, "plate_number"))
                }
            }
        }

        // O-64: the plate type isn't asked any more (a private car = 1 for Wasl); the sequence number is required.
        if (!water) Column(Modifier.staggerIn(6)) {
            DorrTextField(
                value = sequence, onValueChange = { sequence = it.filter(Char::isDigit).take(16); server = server - "sequence_number" },
                label = stringResource(R.string.ob_sequence), icon = Icons.Rounded.Numbers,
                error = problem(tried, sequenceLocal, server, "sequence_number") != null,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = ImeAction.Done),
            )
            FieldHint(problem(tried, sequenceLocal, server, "sequence_number"))
        }
    }
}

/** The colours as swatches to tap — the chosen one ringed and ticked. */
@Composable
private fun ColorPicker(colors: List<ColorOptionDto>, selected: Int?, error: Boolean, onPick: (Int) -> Unit) {
    val shape = RoundedCornerShape(18.dp)
    LazyRow(
        Modifier.fillMaxWidth().clip(shape).background(Pal.fieldFill).border(if (error) 1.5.dp else 1.dp, if (error) Pal.danger else Pal.fieldLine, shape),
        contentPadding = PaddingValues(horizontal = 8.dp, vertical = 10.dp),
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        items(colors, key = { it.id }) { c ->
            val on = c.id == selected
            val ring by animateColorAsState(if (on) Pal.accent else Color.Transparent, tween(200), label = "swatch-ring")
            val scale by animateFloatAsState(if (on) 1.08f else 1f, spring(dampingRatio = Spring.DampingRatioMediumBouncy), label = "swatch-scale")
            Column(Modifier.width(62.dp).bounceClick { onPick(c.id) }, horizontalAlignment = Alignment.CenterHorizontally) {
                Box(Modifier.size(44.dp).scale(scale).border(2.dp, ring, CircleShape).padding(4.dp), contentAlignment = Alignment.Center) {
                    Swatch(c.hex, 36)
                    if (on) Icon(Icons.Rounded.Check, null, tint = if (hexColor(c.hex).luminance() > 0.6f) Color(0xFF111928) else Color.White, modifier = Modifier.size(18.dp))
                }
                Text(
                    c.name.orEmpty(), color = if (on) Pal.accent else Pal.mut, fontSize = 11.sp, maxLines = 1, overflow = TextOverflow.Ellipsis,
                    fontWeight = if (on) FontWeight.Bold else FontWeight.Medium, modifier = Modifier.padding(top = 4.dp),
                )
            }
        }
    }
}

private fun hexColor(hex: String?): Color =
    hex?.trim()?.removePrefix("#")?.takeIf { it.length == 6 }?.toLongOrNull(16)?.let { Color(0xFF000000 or it) } ?: Color.Gray

/** A number plate: the numbers and the letters on white, as on the car — placeholder digits until they are typed. */
@Composable
private fun PlatePreview(letters: String, numbers: String, modifier: Modifier = Modifier, small: Boolean = false) {
    val ink = Color(0xFF111928)
    val shape = RoundedCornerShape(if (small) 8.dp else 12.dp)
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Row(
            modifier.widthIn(max = if (small) 190.dp else 290.dp).fillMaxWidth().height(if (small) 44.dp else 66.dp)
                .clip(shape).background(Color.White).border(2.dp, ink, shape).padding(4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            PlateHalf(numbers, "1234", small, Modifier.weight(1f))
            Box(Modifier.width(2.dp).fillMaxHeight().background(ink))
            PlateHalf(letters, "ABC", small, Modifier.weight(1f))
        }
    }
}

@Composable
private fun PlateHalf(value: String, placeholder: String, small: Boolean, modifier: Modifier) {
    val shown = value.trim().ifBlank { placeholder }.toList().joinToString(" ")
    Box(modifier.fillMaxHeight(), contentAlignment = Alignment.Center) {
        Text(
            shown, color = if (value.isBlank()) Color(0xFFB8BEC8) else Color(0xFF111928), maxLines = 1,
            fontSize = if (small) 15.sp else 22.sp, fontWeight = FontWeight.ExtraBold, letterSpacing = 1.sp,
        )
    }
}

@Composable
private fun VehicleSummary(
    context: Context, make: String?, model: String?, year: Int?, color: String?, colorHex: String?,
    letters: String?, numbers: String?, modifier: Modifier = Modifier,
) {
    Column(modifier.fillMaxWidth().surface(RoundedCornerShape(20.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            TileIcon(Icons.Rounded.DirectionsCar, Pal.accent, 44.dp)
            Column(Modifier.weight(1f)) {
                Text(listOfNotNull(make, model).joinToString(" ").ifBlank { context.getString(R.string.ob_vehicle) }, color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    if (colorHex != null) Swatch(colorHex, 12)
                    Text(listOfNotNull(color, year?.toString()).joinToString(" · "), color = Pal.mut, fontSize = 12.sp)
                }
            }
        }
        PlatePreview(letters.orEmpty(), numbers.orEmpty(), Modifier.align(Alignment.CenterHorizontally), small = true)
    }
}

// ---------------------------------------------------------------- 3. documents

@Composable
private fun DocumentsStep(s: OnboardingDto, onUpdated: (OnboardingDto) -> Unit, onNeedVehicle: () -> Unit, onNext: () -> Unit) {
    var open by remember { mutableStateOf<Int?>(null) }
    val docs = s.requiredDocuments
    val sent = docs.count { it.document != null && it.document.status != "rejected" }
    val shown by animateFloatAsState(if (docs.isEmpty()) 1f else sent / docs.size.toFloat(), tween(500), label = "docs-progress")
    val needsVehicle = s.vehicle == null && docs.any { it.type.appliesTo == "vehicle" }

    StepFrame(
        stringResource(R.string.ob_documents), stringResource(R.string.ob_documents_hint),
        footer = { PrimaryButton(stringResource(R.string.ob_continue), icon = Icons.AutoMirrored.Rounded.ArrowForward, onClick = onNext) },
    ) {
        if (docs.isNotEmpty()) {
            Column(Modifier.staggerIn(1), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(stringResource(R.string.ob_docs_progress, sent, docs.size), color = Pal.ink, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.weight(1f))
                    if (sent == docs.size) Icon(Icons.Rounded.Verified, null, tint = Pal.success, modifier = Modifier.size(18.dp))
                }
                LinearProgressIndicator(
                    progress = { shown }, color = if (sent == docs.size) Pal.success else Pal.accent, trackColor = Pal.well,
                    modifier = Modifier.fillMaxWidth().height(6.dp).clip(CircleShape),
                )
            }
        }
        if (needsVehicle) Banner(stringResource(R.string.ob_docs_need_vehicle), kind = BannerKind.Warning)
        if (docs.isEmpty()) {
            Column(Modifier.fillMaxWidth().padding(vertical = 32.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                HeroBadge(Icons.Rounded.TaskAlt, tint = Pal.success)
                Text(stringResource(R.string.ob_docs_none), color = Pal.mut, fontSize = 14.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 12.dp))
            }
        }
        docs.forEachIndexed { i, doc ->
            DocumentCard(doc, Modifier.staggerIn(i + 2)) {
                if (doc.type.appliesTo == "vehicle" && s.vehicle == null) onNeedVehicle() else { open = doc.type.id }
            }
        }
    }

    open?.let { id ->
        docs.firstOrNull { it.type.id == id }?.let { doc ->
            DocumentSheet(doc, s.vehicle?.id, onDismiss = { open = null }) { updated ->
                onUpdated(updated)
                open = null
            }
        }
    }
}

// ---------------------------------------------------------------- 4. review and send

@Composable
private fun ReviewStep(
    s: OnboardingDto,
    options: OnboardingOptionsDto,
    regulator: RegulatorDto?,
    onRegulator: (RegulatorDto) -> Unit,
    onState: (OnboardingDto) -> Unit,
    onEdit: (Int) -> Unit,
    onClose: () -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val status = s.driver.status

    fun <T> act(call: suspend () -> ApiResult<T>, done: (T) -> Unit) {
        if (busy) return
        busy = true
        error = null
        scope.launch {
            when (val r = call()) {
                is ApiResult.Ok -> done(r.data)
                is ApiResult.Failed -> error = r.error.displayMessage
                is ApiResult.Offline -> error = context.getString(R.string.error_offline)
            }
            busy = false
        }
    }

    StepFrame(
        stringResource(R.string.ob_review), stringResource(R.string.ob_review_hint),
        footer = {
            Banner(error)
            if (status == "pending_documents") {
                PrimaryButton(stringResource(R.string.ob_submit), enabled = s.canSubmit, busy = busy, icon = Icons.Rounded.HowToReg) {
                    act({ apiCall { DriverBackend.api.submitOnboarding() } }) { onState(it) }
                }
            } else {
                PrimaryButton(stringResource(R.string.ob_done), onClick = onClose)
            }
        },
    ) {
        when (status) {
            "under_review" -> WaitingHero()
            "active" -> Column(Modifier.fillMaxWidth().staggerIn(1), horizontalAlignment = Alignment.CenterHorizontally) {
                HeroBadge(Icons.Rounded.Verified, tint = Pal.success)
                Text(stringResource(R.string.ob_status_approved), color = Pal.success, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp, modifier = Modifier.padding(top = 10.dp))
            }
        }
        s.workProblem?.let { Banner(problemText(context, it), kind = BannerKind.Warning) }

        if (status == "pending_documents" && s.missing.isNotEmpty()) {
            val names = s.missing.map { missingLabel(context, s, it) }.distinct()
            Banner(stringResource(R.string.ob_missing, names.joinToString(stringResource(R.string.list_sep))), kind = BannerKind.Warning)
        }

        val p = s.profile
        SummaryCard(Icons.Rounded.Person, stringResource(R.string.ob_details), s.steps.profile, Modifier.staggerIn(2), onEdit = { onEdit(STEP_DETAILS) }) {
            SummaryLine(stringResource(R.string.ob_name), p.name)
            SummaryLine(p.idType?.let { idLabel(context, it) } ?: stringResource(R.string.ob_national_id), p.nationalId)
            SummaryLine(stringResource(R.string.ob_dob), prettyDate(p.dateOfBirth).ifBlank { null })
            SummaryLine(stringResource(R.string.ob_nationality), options.nationalities.firstOrNull { it.id == p.nationalityCountryId }?.name)
        }

        val v = s.vehicle
        SummaryCard(Icons.Rounded.DirectionsCar, stringResource(R.string.ob_vehicle), s.steps.vehicle, Modifier.staggerIn(3), onEdit = { onEdit(STEP_VEHICLE) }) {
            if (v == null) {
                Text(stringResource(R.string.ob_status_missing), color = Pal.warning, fontSize = 13.sp, fontWeight = FontWeight.Bold)
            } else {
                SummaryLine(stringResource(R.string.ob_model), listOfNotNull(v.make, v.model, v.year?.toString()).joinToString(" "))
                SummaryLine(stringResource(R.string.ob_status), docStatus(context, v.status))
                PlatePreview(v.plateLetters.orEmpty(), v.plateNumber.orEmpty(), Modifier.padding(top = 4.dp), small = true)
            }
        }

        val docs = s.requiredDocuments
        SummaryCard(Icons.Rounded.Description, stringResource(R.string.ob_documents), s.steps.documents, Modifier.staggerIn(4), onEdit = { onEdit(STEP_DOCUMENTS) }) {
            docs.forEach { doc ->
                val look = docStatus(context, doc.document?.status)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(doc.type.name ?: doc.type.code, color = Pal.ink, fontSize = 13.sp, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Text(look, color = statusColor(doc.document?.status), fontSize = 12.sp, fontWeight = FontWeight.Bold)
                }
            }
        }

        // Wasl, only where there is a regulator.
        regulator?.takeIf { it.required }?.let { reg ->
            val valid = reg.driverStatus == "valid" && reg.vehicleStatus == "valid"
            SummaryCard(Icons.Rounded.Verified, stringResource(R.string.ob_wasl), valid, Modifier.staggerIn(5), onEdit = null) {
                Text(
                    when {
                        reg.blockedUntil != null -> stringResource(R.string.ob_wasl_blocked, prettyDate(reg.blockedUntil).ifBlank { reg.blockedUntil })
                        valid -> stringResource(R.string.ob_wasl_valid)
                        else -> stringResource(R.string.ob_wasl_pending)
                    },
                    color = if (valid) Pal.success else Pal.ink, fontSize = 13.sp, fontWeight = FontWeight.Bold,
                )
                reg.reasons.forEach { Text("• ${it.message ?: it.code}", fontSize = 12.sp, color = Pal.danger) }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(top = 4.dp)) {
                    SmallButton(Icons.Rounded.Sync, stringResource(R.string.ob_wasl_check), Modifier.weight(1f)) {
                        act({ apiCall { DriverBackend.api.checkRegulator() } }) { onRegulator(it) }
                    }
                    if (reg.canRegister && reg.driverStatus != "valid") {
                        SmallButton(Icons.Rounded.HowToReg, stringResource(R.string.ob_wasl_register), Modifier.weight(1f)) {
                            act({ apiCall { DriverBackend.api.registerRegulator() } }) { onRegulator(it) }
                        }
                    }
                }
            }
        }
    }
}

/** Sent and waiting: an hourglass that breathes, and the words. */
@Composable
private fun WaitingHero() {
    val pulse = rememberInfiniteTransition(label = "waiting")
    val scale by pulse.animateFloat(0.94f, 1.06f, infiniteRepeatable(tween(1100), RepeatMode.Reverse), label = "waiting-scale")
    Column(Modifier.fillMaxWidth().padding(vertical = 8.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        HeroBadge(Icons.Rounded.HourglassTop, tint = Pal.info, size = 84.dp, scale = scale)
        Text(stringResource(R.string.ob_submitted), color = Pal.ink, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 12.dp))
        Text(stringResource(R.string.ob_waiting_hint), color = Pal.mut, fontSize = 13.sp, textAlign = TextAlign.Center, lineHeight = 19.sp, modifier = Modifier.padding(top = 4.dp))
    }
}

@Composable
private fun SummaryCard(icon: ImageVector, title: String, done: Boolean, modifier: Modifier, onEdit: (() -> Unit)?, content: @Composable ColumnScope.() -> Unit) {
    Column(modifier.fillMaxWidth().surface(RoundedCornerShape(20.dp)).padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp), modifier = Modifier.padding(bottom = 4.dp)) {
            TileIcon(icon, if (done) Pal.success else Pal.warning, 38.dp)
            Text(title, color = Pal.ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, modifier = Modifier.weight(1f))
            if (onEdit != null) {
                Row(
                    Modifier.clip(RoundedCornerShape(10.dp)).bounceClick(onClick = onEdit).padding(horizontal = 8.dp, vertical = 4.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp),
                ) {
                    Icon(Icons.Rounded.Edit, null, tint = Pal.accent, modifier = Modifier.size(15.dp))
                    Text(stringResource(R.string.ob_edit), color = Pal.accent, fontWeight = FontWeight.Bold, fontSize = 12.sp)
                }
            }
        }
        content()
    }
}

@Composable
private fun SummaryLine(label: String, value: String?) {
    Row {
        Text(label, color = Pal.mut, fontSize = 13.sp, modifier = Modifier.weight(1f))
        Text(value?.ifBlank { null } ?: "—", color = Pal.ink, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun SmallButton(icon: ImageVector, label: String, modifier: Modifier, onClick: () -> Unit) {
    Row(
        modifier.clip(RoundedCornerShape(14.dp)).background(Pal.well).bounceClick(onClick = onClick).padding(vertical = 10.dp),
        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = Pal.accent, modifier = Modifier.size(16.dp))
        Text(" $label", color = Pal.accent, fontSize = 13.sp, fontWeight = FontWeight.Bold)
    }
}

// ---------------------------------------------------------------- words

/** A "missing" code from the server ("national_id", "vehicle", "document:licence") in words. */
private fun missingLabel(context: Context, s: OnboardingDto, code: String): String = when {
    code == "name" -> context.getString(R.string.ob_name)
    code == "national_id" -> context.getString(R.string.ob_national_id)
    code == "date_of_birth" -> context.getString(R.string.ob_dob)
    code == "vehicle" -> context.getString(R.string.ob_vehicle)
    code.startsWith("document:") -> code.removePrefix("document:").let { c -> s.requiredDocuments.firstOrNull { it.type.code == c }?.type?.name ?: c }
    else -> code
}

private fun genderLabel(context: Context, value: String): String = when (value) {
    "male" -> context.getString(R.string.ob_gender_male)
    "female" -> context.getString(R.string.ob_gender_female)
    else -> value
}

/** O-64: the vehicle kinds the sign-up offers, from a body type. */
private fun vehicleKind(body: String?): String = when (body) {
    "motorcycle" -> "motorcycle"
    "bus", "minibus" -> "bus"
    "boat" -> "boat"
    "yacht" -> "yacht"
    else -> "car"
}

private fun bodyLabel(context: Context, value: String): String = when (value) {
    "sedan" -> context.getString(R.string.body_sedan)
    "suv" -> context.getString(R.string.body_suv)
    "van" -> context.getString(R.string.body_van)
    "minibus" -> context.getString(R.string.body_minibus)
    "bus" -> context.getString(R.string.body_bus)
    "motorcycle" -> context.getString(R.string.body_motorcycle)
    "boat" -> context.getString(R.string.body_boat)
    "yacht" -> context.getString(R.string.body_yacht)
    else -> value
}

private fun idLabel(context: Context, type: String): String = context.getString(
    when (type) { "iqama" -> R.string.ob_id_iqama; "passport" -> R.string.ob_id_passport; else -> R.string.ob_id_national_id },
)

private fun docStatus(context: Context, status: String?): String = context.getString(
    when (status) {
        "approved" -> R.string.ob_status_approved
        "rejected" -> R.string.ob_status_rejected
        null -> R.string.ob_status_missing
        else -> R.string.ob_status_pending
    },
)

private fun statusColor(status: String?): Color = when (status) {
    "approved" -> Pal.success
    "rejected" -> Pal.danger
    null -> Pal.warning
    else -> Pal.info
}
