package com.dorr.freelance.ui.screens

import android.Manifest
import android.content.pm.PackageManager
import android.net.Uri
import android.widget.Toast
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AddPhotoAlternate
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.DeleteOutline
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.Inbox
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.PauseCircle
import androidx.compose.material.icons.rounded.PhotoCamera
import androidx.compose.material.icons.rounded.PlayCircle
import androidx.compose.material.icons.rounded.QuestionAnswer
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.Title
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.Saver
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import coil.compose.AsyncImage
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import androidx.compose.material.icons.rounded.EventRepeat
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.MyServicesDto
import com.dorr.freelance.net.MySpecialtyDto
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.net.ServiceAddonRequest
import com.dorr.freelance.net.ServiceDto
import com.dorr.freelance.net.ServiceRequest
import com.dorr.freelance.net.ServiceTierRequest
import com.dorr.freelance.net.apiDone
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.ChipRow
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.EmptyState
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.components.SkeletonDetail
import com.dorr.freelance.ui.components.SkeletonList
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.ConfirmDialog
import com.dorr.freelance.ui.kit.RedToggle
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface
import com.google.gson.Gson
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import java.io.File

private const val MIN_DESCRIPTION = 30

// ==================================================================== my services

/**
 * FL-L04, "My services": a head card with what is live and what waits, a banner to the orders waiting for an answer, the statuses to
 * filter by (with their counts), and one card per service — its cover, status, starting price, days, levels and orders. A first-time
 * freelancer gets the three steps of how it works. Read when the page opens, when the connection returns and when a notice arrives.
 */
@Composable
fun MyServicesScreen(onBack: () -> Unit, onOpenService: (String?) -> Unit, onOpenOrders: () -> Unit, onSwitchStore: ((String) -> Unit)? = null) {
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    var data by remember { mutableStateOf<MyServicesDto?>(null) }
    var waiting by remember { mutableStateOf(0) }
    var error by remember { mutableStateOf<String?>(null) }
    var closed by remember { mutableStateOf(false) }
    var filter by rememberSaveable { mutableStateOf("all") }

    suspend fun load() {
        when (val result = apiCall { ApiClient.services.mine() }) {
            is ApiResult.Ok -> { data = result.data; error = null }
            is ApiResult.Failed -> if (result.error.errorCode == "freelance_feature_off") closed = true else error = result.errorText(offlineText) ?: genericError
            else -> error = result.errorText(offlineText) ?: genericError
        }
        (apiCall { ApiClient.services.orders(status = "awaiting", perPage = 50) } as? ApiResult.Ok)?.data?.let { waiting = it.size }
    }
    LaunchedEffect(reconnect) { load() }
    LaunchedEffect(Unit) { Realtime.changed.collect { load() } }

    Page(title = stringResource(R.string.svc_title), onBack = onBack) {
        onSwitchStore?.let { com.dorr.freelance.ui.kit.StoreSwitcher("services", it) }
        val d = data
        when {
            closed -> EmptyState(Icons.Rounded.Storefront, stringResource(R.string.svc_title), stringResource(R.string.svc_unavailable), Modifier.padding(top = 80.dp))
            d == null && error == null -> SkeletonList()
            d != null -> {
                val counts = d.counts
                val live = (counts["published"] ?: 0) + (counts["pending_review"] ?: 0)
                HeroCard(d, live, waiting, onNew = { onOpenService(null) })
                if (waiting > 0) OrdersBanner(waiting, onOpenOrders)

                if (d.services.isEmpty()) {
                    HowItWorks(d.review)
                } else {
                    val statuses = listOf("all", "published", "pending_review", "draft", "paused", "rejected", "suspended")
                        .filter { it == "all" || (counts[it] ?: 0) > 0 }
                    if (statuses.size > 2) com.dorr.freelance.ui.kit.FilterChips(statuses.map { st -> com.dorr.freelance.ui.kit.FilterOption(st, (if (st == "all") stringResource(R.string.svc_all) else serviceStatusLabel(st)) + "  " + (if (st == "all") d.services.size else counts[st] ?: 0)) }, filter, { filter = it })
                    val shown = d.services.filter { filter == "all" || it.status == filter }
                    if (shown.isEmpty()) Text(stringResource(R.string.svc_none_filtered), color = settingsMut(), fontSize = 13.sp)
                    shown.forEach { s -> ServiceCard(s) { onOpenService(s.uuid) } }
                }
            }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}

@Composable
private fun HeroCard(d: MyServicesDto, live: Int, waiting: Int, onNew: () -> Unit) {
    com.dorr.freelance.ui.kit.StoreHero(
        Icons.Rounded.Storefront, stringResource(R.string.svc_hero_title), stringResource(R.string.svc_hero_body),
        listOf(
            com.dorr.freelance.ui.kit.HeroNumber("${d.counts["published"] ?: 0}", stringResource(R.string.svc_stat_published)),
            com.dorr.freelance.ui.kit.HeroNumber("${d.counts["pending_review"] ?: 0}", stringResource(R.string.svc_stat_review)),
            com.dorr.freelance.ui.kit.HeroNumber("$waiting", stringResource(R.string.svc_stat_waiting)),
        ),
        action = stringResource(R.string.svc_new), actionIcon = Icons.Rounded.Add, onAction = onNew,
        footnote = stringResource(R.string.svc_hero_live, live, d.limits.maxActive),
    )
}

@Composable
internal fun OrdersBanner(count: Int, onOpen: () -> Unit) {
    val tone = serviceTone("awaiting")
    Row(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(18.dp)).clickable(onClick = onOpen).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(Modifier.size(42.dp).clip(CircleShape).background(tone.fill), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Inbox, contentDescription = null, tint = tone.ink, modifier = Modifier.size(22.dp))
        }
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.svc_orders_banner, count), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
            Text(stringResource(R.string.svc_orders_banner_sub), color = settingsMut(), fontSize = 12.sp)
        }
        Box(Modifier.clip(RoundedCornerShape(999.dp)).background(tone.ink).padding(horizontal = 10.dp, vertical = 4.dp)) {
            Text("$count", color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
        }
    }
}

@Composable
private fun HowItWorks(review: Boolean) {
    InfoCard(title = stringResource(R.string.svc_how_title), icon = Icons.Rounded.Info) {
        listOf(R.string.svc_how_1, if (review) R.string.svc_how_2 else R.string.svc_how_2_direct, R.string.svc_how_3).forEachIndexed { i, text ->
            Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                Box(Modifier.size(26.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                    Text("${i + 1}", color = settingsAccent(), fontSize = 12.sp, fontWeight = FontWeight.ExtraBold)
                }
                Text(stringResource(text), color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp, modifier = Modifier.weight(1f).padding(top = 3.dp))
            }
        }
    }
    EmptyState(Icons.Rounded.Storefront, stringResource(R.string.svc_empty_title), stringResource(R.string.svc_empty_body), Modifier.padding(top = 12.dp))
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ServiceCard(s: ServiceDto, onClick: () -> Unit) {
    val tone = serviceTone(s.status)
    Column(Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(20.dp)).clickable(onClick = onClick)) {
        Box(Modifier.fillMaxWidth().height(136.dp).background(settingsAccent().copy(alpha = 0.10f))) {
            val cover = s.images.firstOrNull()?.path
            if (cover != null) AsyncImage(model = ApiClient.url(cover), contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
            else Icon(Icons.Rounded.Image, contentDescription = null, tint = settingsAccent().copy(alpha = 0.6f), modifier = Modifier.align(Alignment.Center).size(40.dp))
            SvcPill(serviceStatusLabel(s.status), tone, Modifier.align(Alignment.TopStart).padding(10.dp), solid = true)
        }
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(s.title, color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
            s.specialty?.name?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
            Row(verticalAlignment = Alignment.CenterVertically) {
                s.startingPriceMinor?.let {
                    Text(stringResource(R.string.svc_from, svcMoney(it, s.decimals, s.currency)), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                }
                s.tiers.firstOrNull()?.let {
                    Icon(Icons.Rounded.Schedule, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(15.dp))
                    Spacer(Modifier.size(4.dp))
                    Text(daysLabel(it.deliveryDays), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                }
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                SERVICE_LEVELS.forEach { level ->
                    val on = s.tiers.any { it.level == level }
                    Text(
                        levelLabel(level), fontSize = 11.sp, fontWeight = FontWeight.Bold,
                        color = if (on) settingsAccent() else settingsMut().copy(alpha = 0.5f),
                        modifier = Modifier.clip(RoundedCornerShape(8.dp)).background(if (on) settingsAccent().copy(alpha = 0.10f) else Color.Transparent).padding(horizontal = 8.dp, vertical = 3.dp),
                    )
                }
                if (s.orders.awaiting > 0) SvcPill(stringResource(R.string.svc_waiting_orders, s.orders.awaiting), serviceTone("awaiting"))
                if (s.orders.active > 0) SvcPill(stringResource(R.string.svc_active_orders, s.orders.active), serviceTone("active"))
                if (s.orders.completed > 0) SvcPill(stringResource(R.string.svc_done_orders, s.orders.completed), serviceTone("paused"))
            }
            if ((s.status == "rejected" || s.status == "suspended") && !s.statusReason.isNullOrBlank()) {
                Text(stringResource(R.string.svc_reason, s.statusReason), color = tone.ink, fontSize = 12.sp, lineHeight = 17.sp,
                    modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(tone.fill).padding(10.dp))
            }
        }
    }
}

// ==================================================================== the form

/** One level as typed: the price stays text until it is sent, so a half-typed amount is never rounded. */
private data class TierForm(
    val level: String,
    val enabled: Boolean,
    val name: String = "",
    val description: String = "",
    val price: String = "",
    val days: Int = 3,
    val revisions: Int = 1,
    val features: List<String> = emptyList(),
    val featureDraft: String = "",
)

/** FL-L05: a month of a monthly service (freelance.subscriptions.period_days). */
private const val MONTH_DAYS = 30

private data class AddonForm(val key: Long, val title: String = "", val price: String = "", val days: Int = 0)

/** The whole form, kept through the app being closed behind the gallery or the camera. */
private data class ServiceForm(
    val title: String = "",
    val description: String = "",
    val requirements: String = "",
    val specialtyId: Int? = null,
    /** FL-L05: once · monthly */
    val billing: String = "once",
    /** FL-L13: the terms every order of it starts from. */
    val rights: RightsForm = RightsForm(),
    val tiers: List<TierForm> = SERVICE_LEVELS.map { TierForm(it, enabled = it == "basic") },
    val addons: List<AddonForm> = emptyList(),
    val pending: List<String> = emptyList(),
    val tab: String = "basic",
    val filled: Boolean = false,
)

private val formSaver = Saver<ServiceForm, String>(save = { Gson().toJson(it) }, restore = { runCatching { Gson().fromJson(it, ServiceForm::class.java) }.getOrNull() })

private fun ServiceForm.fill(s: ServiceDto): ServiceForm = copy(
    title = s.title, description = s.description, requirements = s.requirements.orEmpty(), specialtyId = s.specialty?.id, billing = s.billing, rights = s.rights?.toForm() ?: RightsForm(),
    tiers = SERVICE_LEVELS.map { level ->
        val t = s.tiers.firstOrNull { it.level == level }
        if (t == null) TierForm(level, enabled = false)
        else TierForm(level, true, t.name, t.description.orEmpty(), minorToInput(t.priceMinor, s.decimals), t.deliveryDays, t.revisions, t.features)
    },
    addons = s.addons.mapIndexed { i, a -> AddonForm(i.toLong() + 1, a.title, minorToInput(a.priceMinor, s.decimals), a.extraDays) },
    filled = true,
)

/**
 * One service: new ([uuid] null) or mine. Four numbered steps — about it, its levels (a segmented picker for basic / standard / premium,
 * each with its price in my currency, days, revisions and what it includes), the extras, the images — then a preview of the card
 * clients see. The text and the levels go in one request; the images go up one by one after that. A draft or one sent back is saved
 * and sent for review in one tap (or kept as a draft); an edit of a live one says it goes back to review. Pause, turn on and delete
 * sit under the form. A suspended one is shown read-only with the reason.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun ServiceEditorScreen(uuid: String?, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)

    var currentUuid by rememberSaveable { mutableStateOf(uuid) }
    var form by rememberSaveable(stateSaver = formSaver) { mutableStateOf(ServiceForm()) }
    var service by remember { mutableStateOf<ServiceDto?>(null) }
    var meta by remember { mutableStateOf<MyServicesDto?>(null) }
    // FL-L13: null options = the feature is off in my country.
    val rightsOptions = rememberRightsOptions()
    var specialties by remember { mutableStateOf<List<MySpecialtyDto>>(emptyList()) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var confirmDelete by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        (apiCall { ApiClient.lists.mySpecialties() } as? ApiResult.Ok)?.data?.let { specialties = it.filter { s -> s.isActive } }
        when (val m = apiCall { ApiClient.services.mine() }) {
            is ApiResult.Ok -> meta = m.data
            else -> error = m.errorText(offlineText) ?: genericError
        }
        currentUuid?.let { id ->
            when (val r = apiCall { ApiClient.services.show(id) }) {
                is ApiResult.Ok -> {
                    service = r.data
                    // A form restored after the app was killed keeps what was typed; only a fresh one is filled from the server.
                    if (!form.filled) form = form.fill(r.data)
                }
                else -> error = r.errorText(offlineText) ?: genericError
            }
        }
    }

    val m = meta
    val decimals = service?.decimals ?: m?.decimals ?: 2
    val currency = service?.currency ?: m?.currency
    val status = service?.status ?: "draft"
    val readOnly = status == "suspended"
    val maxImages = m?.limits?.maxImages ?: 5
    val existing = service?.images.orEmpty()
    val imageCount = existing.size + form.pending.size

    // ---- checks, as the server makes them, before anything is sent
    val enabledTiers = form.tiers.filter { it.enabled }
    val tierPrices = enabledTiers.associate { it.level to inputToMinor(it.price, decimals) }
    fun priceProblem(t: TierForm): String? {
        val price = tierPrices[t.level] ?: return null
        m?.price?.minMinor?.let { if (price < it) return context.getString(R.string.svc_price_min, svcMoney(it, decimals, currency)) }
        m?.price?.maxMinor?.let { if (price > it) return context.getString(R.string.svc_price_max, svcMoney(it, decimals, currency)) }
        val before = enabledTiers.takeWhile { it.level != t.level }.lastOrNull()?.let { tierPrices[it.level] }
        if (before != null && price <= before) return context.getString(R.string.svc_price_order, svcMoney(before, decimals, currency))
        return null
    }
    val problem: String? = when {
        form.title.trim().length < 5 -> context.getString(R.string.svc_fix_title)
        form.description.trim().length < MIN_DESCRIPTION -> context.getString(R.string.svc_fix_description, MIN_DESCRIPTION)
        enabledTiers.any { tierPrices[it.level] == null } -> context.getString(R.string.svc_fix_price)
        // FL-L05: every month is delivered within the month.
        form.billing == "monthly" && (enabledTiers.maxOfOrNull { it.days } ?: 0) + (form.addons.maxOfOrNull { it.days } ?: 0) > MONTH_DAYS ->
            context.getString(R.string.svc_fix_monthly_days, MONTH_DAYS)
        else -> enabledTiers.firstNotNullOfOrNull { priceProblem(it) }
    }

    // ---- images
    fun take(uri: Uri) {
        scope.launch {
            val file = withContext(Dispatchers.IO) { preparePortfolioImage(context, uri, "service_${System.currentTimeMillis()}") }
            if (file == null) error = genericError else if (imageCount < maxImages) form = form.copy(pending = form.pending + file.absolutePath)
        }
    }
    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri -> uri?.let(::take) }
    var cameraUriText by rememberSaveable { mutableStateOf<String?>(null) }
    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { taken -> if (taken) cameraUriText?.let { take(Uri.parse(it)) } }
    fun launchCamera() {
        val file = File(File(context.cacheDir, "camera").apply { mkdirs() }, "service_camera.jpg")
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
        cameraUriText = uri.toString()
        camera.launch(uri)
    }
    val cameraPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted -> if (granted) launchCamera() }
    fun openCamera() {
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) launchCamera() else cameraPermission.launch(Manifest.permission.CAMERA)
    }
    fun deleteExisting(media: Int) {
        val id = currentUuid ?: return
        busy = true
        scope.launch {
            val r = apiCall { ApiClient.services.deleteImage(id, media) }
            busy = false
            if (r is ApiResult.Ok) service = r.data else error = r.errorText(offlineText) ?: genericError
        }
    }

    // ---- save (then the images, then send if asked)
    fun save(send: Boolean) {
        if (problem != null) { error = problem; return }
        busy = true
        error = null
        val body = ServiceRequest(
            title = form.title.trim(), description = form.description.trim(), requirements = form.requirements.trim().ifEmpty { null }, specialtyId = form.specialtyId,
            billing = form.billing,
            tiers = enabledTiers.map { t ->
                ServiceTierRequest(t.level, t.name.trim().ifEmpty { null }, t.description.trim().ifEmpty { null }, tierPrices[t.level] ?: 0, t.days, t.revisions, t.features)
            },
            addons = form.addons.filter { it.title.isNotBlank() && inputToMinor(it.price, decimals) != null }
                .map { ServiceAddonRequest(it.title.trim(), inputToMinor(it.price, decimals) ?: 0, it.days) },
            rights = if (rightsOptions != null) form.rights.toInput() else null,
        )
        scope.launch {
            val id = currentUuid
            val saved = if (id == null) apiCall { ApiClient.services.create(body) } else apiCall { ApiClient.services.update(id, body) }
            if (saved !is ApiResult.Ok) { busy = false; error = saved.errorText(offlineText) ?: genericError; return@launch }
            // From here the service exists: a failed image no longer loses the rest.
            currentUuid = saved.data.uuid
            service = saved.data
            for (path in form.pending.toList()) {
                val file = File(path)
                if (!file.exists()) { form = form.copy(pending = form.pending - path); continue }
                val part = MultipartBody.Part.createFormData("image", file.name, file.asRequestBody("image/jpeg".toMediaTypeOrNull()))
                val up = apiCall { ApiClient.services.addImage(saved.data.uuid, part) }
                if (up is ApiResult.Ok) {
                    service = up.data
                    form = form.copy(pending = form.pending - path)
                    file.delete()
                } else {
                    busy = false
                    error = up.errorText(offlineText) ?: genericError
                    return@launch
                }
            }
            var message = context.getString(R.string.svc_saved)
            if (send && service?.can?.submit == true) {
                val sent = apiCall { ApiClient.services.act(saved.data.uuid, "submit") }
                if (sent !is ApiResult.Ok) { busy = false; error = sent.errorText(offlineText) ?: genericError; return@launch }
                service = sent.data
                message = context.getString(if (sent.data.status == "published") R.string.svc_published_now else R.string.svc_sent)
            }
            busy = false
            Toast.makeText(context, message, Toast.LENGTH_SHORT).show()
            onBack()
        }
    }

    fun act(action: String) {
        val id = currentUuid ?: return
        busy = true
        scope.launch {
            val r = apiCall { ApiClient.services.act(id, action) }
            busy = false
            if (r is ApiResult.Ok) service = r.data else error = r.errorText(offlineText) ?: genericError
        }
    }

    fun delete() {
        val id = currentUuid ?: return
        busy = true
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.services.delete(id) }
            busy = false
            confirmDelete = false
            if (failed == null) onBack() else error = failed
        }
    }

    Page(title = stringResource(if (currentUuid == null) R.string.svc_new_title else R.string.svc_edit_title), onBack = onBack) {
        if (m == null || (currentUuid != null && service == null)) {
            if (error == null) SkeletonDetail()
        } else {
            if (currentUuid != null) StateBanner(service!!)

            // 1 — about it
            StepCard(1, stringResource(R.string.svc_step_about), stringResource(R.string.svc_step_about_sub)) {
                DorrTextField(form.title, { form = form.copy(title = it.take(120)) }, label = stringResource(R.string.svc_f_title), placeholder = stringResource(R.string.svc_f_title_ph), icon = Icons.Rounded.Title, enabled = !readOnly, modifier = Modifier.fillMaxWidth())
                Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    DorrTextField(form.description, { form = form.copy(description = it.take(5000)) }, label = stringResource(R.string.svc_f_description), icon = Icons.Rounded.Notes, singleLine = false, minLines = 4, enabled = !readOnly, modifier = Modifier.fillMaxWidth())
                    Row {
                        Text(stringResource(R.string.svc_f_description_hint, MIN_DESCRIPTION), color = settingsMut(), fontSize = 11.sp, modifier = Modifier.weight(1f))
                        Text("${form.description.trim().length}", color = if (form.description.trim().length >= MIN_DESCRIPTION) Color(0xFF16A34A) else settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.Bold)
                    }
                }
                if (specialties.isNotEmpty()) {
                    Text(stringResource(R.string.svc_f_specialty), color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
                    ChipRow(
                        options = specialties.map { it.id.toString() to it.name },
                        selected = setOfNotNull(form.specialtyId?.toString()),
                        onToggle = { v -> if (!readOnly) form = form.copy(specialtyId = if (form.specialtyId?.toString() == v) null else v.toInt()) },
                    )
                }
                Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    DorrTextField(form.requirements, { form = form.copy(requirements = it.take(1000)) }, label = stringResource(R.string.svc_f_requirements), placeholder = stringResource(R.string.svc_f_requirements_ph), icon = Icons.Rounded.QuestionAnswer, enabled = !readOnly, modifier = Modifier.fillMaxWidth())
                    Text(stringResource(R.string.svc_f_requirements_hint), color = settingsMut(), fontSize = 11.sp)
                }
            }

            // 2 — levels
            StepCard(2, stringResource(R.string.svc_step_levels), stringResource(R.string.svc_step_levels_sub)) {
                // FL-L05: sold once, or every month (the client confirms and pays each month; it lasts until either side ends it with notice).
                Row(
                    Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(settingsAccent().copy(alpha = if (form.billing == "monthly") 0.10f else 0.05f)).padding(12.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    Icon(Icons.Rounded.EventRepeat, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
                    Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text(stringResource(R.string.svc_monthly), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                        Text(stringResource(R.string.svc_monthly_hint, MONTH_DAYS), color = settingsMut(), fontSize = 12.sp, lineHeight = 17.sp)
                    }
                    RedToggle(on = form.billing == "monthly", onClick = { if (!readOnly) form = form.copy(billing = if (form.billing == "monthly") "once" else "monthly") })
                }
                Segmented(SERVICE_LEVELS.map { it to levelLabel(it) }, form.tab, marks = enabledTiers.map { it.level }.toSet()) { form = form.copy(tab = it) }
                val index = SERVICE_LEVELS.indexOf(form.tab).coerceAtLeast(0)
                val tier = form.tiers[index]
                fun put(t: TierForm) { form = form.copy(tiers = form.tiers.toMutableList().also { it[index] = t }) }
                if (tier.level != "basic") {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(R.string.svc_level_on), color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
                            if (!tier.enabled) Text(stringResource(R.string.svc_level_off_hint), color = settingsMut(), fontSize = 12.sp)
                        }
                        RedToggle(on = tier.enabled, onClick = { if (!readOnly) put(tier.copy(enabled = !tier.enabled)) })
                    }
                }
                if (tier.enabled) TierFields(tier, currency, priceProblem(tier), m.limits.maxFeatures, readOnly, ::put)
            }

            // 3 — extras
            StepCard(3, stringResource(R.string.svc_step_extras), stringResource(R.string.svc_step_extras_sub)) {
                if (form.addons.isEmpty()) Text(stringResource(R.string.svc_addons_none), color = settingsMut(), fontSize = 13.sp)
                form.addons.forEach { a ->
                    AddonFields(a, currency, readOnly,
                        onChange = { changed -> form = form.copy(addons = form.addons.map { if (it.key == a.key) changed else it }) },
                        onRemove = { form = form.copy(addons = form.addons.filterNot { it.key == a.key }) })
                }
                if (!readOnly && form.addons.size < m.limits.maxAddons) {
                    OutlineAction(Icons.Rounded.Add, stringResource(R.string.svc_addon_add)) {
                        form = form.copy(addons = form.addons + AddonForm(System.nanoTime()))
                    }
                }
            }

            // 4 — images
            StepCard(4, stringResource(R.string.svc_step_images), stringResource(R.string.svc_step_images_sub)) {
                Text(stringResource(R.string.svc_images_count, imageCount, maxImages), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    var first = true
                    existing.forEach { img ->
                        ServiceThumb(img.path?.let { ApiClient.url(it) }, cover = first, enabled = !busy && !readOnly) { deleteExisting(img.id) }
                        first = false
                    }
                    form.pending.forEach { path ->
                        ServiceThumb(File(path), cover = first, enabled = !busy) { form = form.copy(pending = form.pending - path); File(path).delete() }
                        first = false
                    }
                    if (!readOnly && imageCount < maxImages) {
                        AddTile(Icons.Rounded.AddPhotoAlternate, stringResource(R.string.profile_photo_gallery)) { gallery.launch("image/*") }
                        AddTile(Icons.Rounded.PhotoCamera, stringResource(R.string.profile_photo_camera)) { openCamera() }
                    }
                }
            }

            // FL-L13: who owns the work once an order is paid.
            rightsOptions?.let { RightsEditor(form.rights, it, readOnly) { r -> form = form.copy(rights = r) } }

            // How clients will see it.
            Preview(form, existing.firstOrNull()?.path?.let { ApiClient.url(it) } ?: form.pending.firstOrNull()?.let { File(it) }, tierPrices, decimals, currency)

            // The buttons.
            if (!readOnly) {
                Column(Modifier.fillMaxWidth().widthIn(max = 520.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    val canSend = status == "draft" || status == "rejected"
                    // The floating banner is easy to miss: the reason also stays above the buttons.
                    error?.let { Text(it, color = serviceTone("rejected").ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, lineHeight = 19.sp) }
                    if (canSend) {
                        SettingsPrimaryButton(text = stringResource(if (m.review) R.string.svc_save_send else R.string.svc_save_publish), loading = busy, onClick = { save(send = true) })
                        Text(
                            stringResource(R.string.svc_save_draft), color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.Bold,
                            modifier = Modifier.align(Alignment.CenterHorizontally).clip(RoundedCornerShape(12.dp)).clickable(enabled = !busy) { save(send = false) }.padding(10.dp),
                        )
                    } else {
                        if (m.review && (status == "published" || status == "paused")) Text(stringResource(R.string.svc_edit_rereview), color = serviceTone("pending_review").ink, fontSize = 12.sp, lineHeight = 17.sp)
                        SettingsPrimaryButton(text = stringResource(R.string.svc_save_changes), loading = busy, onClick = { save(send = false) })
                    }
                }
            }
            service?.let { s -> ManageCard(s, busy, onPause = { act("pause") }, onResume = { act("resume") }, onDelete = { confirmDelete = true }) }
        }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }

    if (confirmDelete) {
        ConfirmDialog(
            icon = Icons.Rounded.DeleteOutline, title = stringResource(R.string.svc_delete_title), message = stringResource(R.string.svc_delete_body),
            confirmLabel = stringResource(R.string.common_delete), loading = busy, onConfirm = ::delete, onDismiss = { confirmDelete = false },
        )
    }
}

@Composable
private fun StateBanner(s: ServiceDto) {
    val tone = serviceTone(s.status)
    val text = when (s.status) {
        "pending_review" -> R.string.svc_state_pending_review
        "published" -> R.string.svc_state_published
        "paused" -> R.string.svc_state_paused
        "rejected" -> R.string.svc_state_rejected
        "suspended" -> R.string.svc_state_suspended
        else -> R.string.svc_state_draft
    }
    Column(
        Modifier.fillMaxWidth().widthIn(max = 520.dp).clip(RoundedCornerShape(18.dp)).background(tone.fill).padding(14.dp),
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        SvcPill(serviceStatusLabel(s.status), tone, solid = true)
        Text(stringResource(text), color = settingsInk(), fontSize = 13.sp, lineHeight = 19.sp)
        if (!s.statusReason.isNullOrBlank() && (s.status == "rejected" || s.status == "suspended")) {
            Text(stringResource(R.string.svc_reason, s.statusReason), color = tone.ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, lineHeight = 19.sp)
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun TierFields(tier: TierForm, currency: String?, priceProblem: String?, maxFeatures: Int, readOnly: Boolean, put: (TierForm) -> Unit) {
    DorrTextField(tier.name, { put(tier.copy(name = it.take(60))) }, label = stringResource(R.string.svc_f_level_name), placeholder = levelLabel(tier.level), enabled = !readOnly, modifier = Modifier.fillMaxWidth())
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        DorrTextField(
            tier.price, { put(tier.copy(price = it.filter { c -> c.isDigit() || c == '.' || c == ',' }.take(14))) },
            label = stringResource(R.string.svc_f_price), placeholder = "0",
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), error = priceProblem != null, enabled = !readOnly,
            trailing = { Text(currency.orEmpty(), color = settingsMut(), fontSize = 13.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(end = 12.dp)) },
            modifier = Modifier.fillMaxWidth(),
        )
        priceProblem?.let { Text(it, color = serviceTone("rejected").ink, fontSize = 12.sp, fontWeight = FontWeight.SemiBold) }
    }
    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        Stepper(stringResource(R.string.svc_f_days), tier.days, 1..365, enabled = !readOnly, modifier = Modifier.weight(1f)) { put(tier.copy(days = it)) }
        Stepper(stringResource(R.string.svc_f_revisions), tier.revisions, 0..50, enabled = !readOnly, modifier = Modifier.weight(1f)) { put(tier.copy(revisions = it)) }
    }
    DorrTextField(tier.description, { put(tier.copy(description = it.take(500))) }, label = stringResource(R.string.svc_f_level_desc), singleLine = false, minLines = 2, enabled = !readOnly, modifier = Modifier.fillMaxWidth())

    Text(stringResource(R.string.svc_f_features) + " (${tier.features.size}/$maxFeatures)", color = settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold)
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        tier.features.forEachIndexed { i, f ->
            Row(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(settingsAccent().copy(alpha = 0.06f)).padding(horizontal = 10.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = Color(0xFF16A34A), modifier = Modifier.size(16.dp))
                Text(f, color = settingsInk(), fontSize = 13.sp, modifier = Modifier.weight(1f))
                if (!readOnly) Icon(Icons.Rounded.Close, contentDescription = stringResource(R.string.common_remove), tint = settingsMut(),
                    modifier = Modifier.size(18.dp).clickable { put(tier.copy(features = tier.features.filterIndexed { j, _ -> j != i })) })
            }
        }
    }
    if (!readOnly && tier.features.size < maxFeatures) {
        fun add() {
            val text = tier.featureDraft.trim()
            if (text.isNotEmpty()) put(tier.copy(features = tier.features + text.take(80), featureDraft = ""))
        }
        DorrTextField(
            tier.featureDraft, { put(tier.copy(featureDraft = it.take(80))) }, placeholder = stringResource(R.string.svc_f_feature_ph),
            trailing = {
                Text(stringResource(R.string.svc_f_add), color = if (tier.featureDraft.isBlank()) settingsMut() else settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold,
                    modifier = Modifier.padding(end = 6.dp).clip(RoundedCornerShape(10.dp)).clickable(enabled = tier.featureDraft.isNotBlank()) { add() }.padding(8.dp))
            },
            modifier = Modifier.fillMaxWidth(),
        )
    }
}

@Composable
private fun AddonFields(a: AddonForm, currency: String?, readOnly: Boolean, onChange: (AddonForm) -> Unit, onRemove: () -> Unit) {
    val night = settingsNight()
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).border(1.dp, if (night) AccountDark.line else Color(0xFFE5E7EB), RoundedCornerShape(16.dp)).padding(12.dp),
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            DorrTextField(a.title, { onChange(a.copy(title = it.take(80))) }, label = stringResource(R.string.svc_addon_title), placeholder = stringResource(R.string.svc_addon_title_ph), enabled = !readOnly, modifier = Modifier.weight(1f))
            if (!readOnly) Icon(Icons.Rounded.DeleteOutline, contentDescription = stringResource(R.string.common_remove), tint = settingsMut(),
                modifier = Modifier.padding(start = 8.dp, top = 18.dp).size(22.dp).clickable(onClick = onRemove))
        }
        Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.Bottom) {
            DorrTextField(
                a.price, { onChange(a.copy(price = it.filter { c -> c.isDigit() || c == '.' || c == ',' }.take(14))) }, label = stringResource(R.string.svc_f_price), placeholder = "0",
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal), enabled = !readOnly,
                trailing = { Text(currency.orEmpty(), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(end = 10.dp)) },
                modifier = Modifier.weight(1f),
            )
            Stepper(stringResource(R.string.svc_addon_days), a.days, 0..60, enabled = !readOnly, modifier = Modifier.weight(1f)) { onChange(a.copy(days = it)) }
        }
    }
}

@Composable
private fun OutlineAction(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().height(46.dp).clip(RoundedCornerShape(14.dp)).border(1.5.dp, settingsAccent().copy(alpha = 0.45f), RoundedCornerShape(14.dp)).clickable(onClick = onClick),
        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        Spacer(Modifier.size(6.dp))
        Text(label, color = settingsAccent(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
    }
}

@Composable
private fun ServiceThumb(model: Any?, cover: Boolean, enabled: Boolean, onRemove: () -> Unit) {
    Box(Modifier.size(width = 104.dp, height = 78.dp).clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.10f))) {
        AsyncImage(model = model, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
        if (cover) Text(stringResource(R.string.svc_cover), color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.Bold,
            modifier = Modifier.align(Alignment.BottomStart).padding(5.dp).clip(RoundedCornerShape(6.dp)).background(Color.Black.copy(alpha = 0.55f)).padding(horizontal = 6.dp, vertical = 2.dp))
        if (enabled) Box(
            Modifier.align(Alignment.TopEnd).padding(4.dp).size(22.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.55f)).clickable(onClick = onRemove),
            contentAlignment = Alignment.Center,
        ) { Icon(Icons.Rounded.Close, contentDescription = stringResource(R.string.common_remove), tint = Color.White, modifier = Modifier.size(14.dp)) }
    }
}

@Composable
private fun AddTile(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, onClick: () -> Unit) {
    Column(
        Modifier.size(width = 104.dp, height = 78.dp).clip(RoundedCornerShape(14.dp)).border(1.5.dp, settingsAccent().copy(alpha = 0.35f), RoundedCornerShape(14.dp)).clickable(onClick = onClick),
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center,
    ) {
        Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(22.dp))
        Spacer(Modifier.height(4.dp))
        Text(label, color = settingsAccent(), fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

/** The card a client will see in the list, drawn from what is typed now. */
@Composable
private fun Preview(form: ServiceForm, cover: Any?, prices: Map<String, Long?>, decimals: Int, currency: String?) {
    val basic = form.tiers.first()
    Column(Modifier.fillMaxWidth().widthIn(max = 520.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(stringResource(R.string.svc_step_preview), color = settingsMut(), fontSize = 12.sp, fontWeight = FontWeight.Bold)
        Row(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp)).padding(10.dp), horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(78.dp).clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = 0.10f)), contentAlignment = Alignment.Center) {
                if (cover != null) AsyncImage(model = cover, contentDescription = null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                else Icon(Icons.Rounded.Image, contentDescription = null, tint = settingsAccent().copy(alpha = 0.6f))
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(form.title.ifBlank { stringResource(R.string.svc_f_title_ph) }, color = if (form.title.isBlank()) settingsMut() else settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Text(stringResource(R.string.svc_preview_by), color = settingsMut(), fontSize = 11.sp)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(prices["basic"]?.let { stringResource(R.string.svc_from, svcMoney(it, decimals, currency)) } ?: "—", color = settingsAccent(), fontSize = 13.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                    Text(daysLabel(basic.days), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}

@Composable
private fun ManageCard(s: ServiceDto, busy: Boolean, onPause: () -> Unit, onResume: () -> Unit, onDelete: () -> Unit) {
    if (!s.can.pause && !s.can.resume && s.status == "suspended") return
    Column(Modifier.fillMaxWidth().widthIn(max = 520.dp).settingsSurface(RoundedCornerShape(20.dp)).padding(6.dp)) {
        if (s.can.pause) ManageRow(Icons.Rounded.PauseCircle, stringResource(R.string.svc_pause), null, !busy, false, onPause)
        if (s.can.resume) ManageRow(Icons.Rounded.PlayCircle, stringResource(R.string.svc_resume), null, !busy, false, onResume)
        ManageRow(Icons.Rounded.DeleteOutline, stringResource(R.string.svc_delete), if (s.can.delete) null else stringResource(R.string.svc_delete_blocked), !busy && s.can.delete, true, onDelete)
    }
}

@Composable
private fun ManageRow(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, hint: String?, enabled: Boolean, danger: Boolean, onClick: () -> Unit) {
    val ink = if (danger) serviceTone("rejected").ink else settingsInk()
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable(enabled = enabled, onClick = onClick).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Icon(icon, contentDescription = null, tint = if (enabled) ink else settingsMut(), modifier = Modifier.size(22.dp))
        Column(Modifier.weight(1f)) {
            Text(label, color = if (enabled) ink else settingsMut(), fontSize = 14.sp, fontWeight = FontWeight.Bold)
            hint?.let { Text(it, color = settingsMut(), fontSize = 11.sp) }
        }
    }
}
