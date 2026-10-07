package com.dorr.app.ui.screens.aichat

import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.ArrowBackIosNew
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AiSiteOffersDto
import com.dorr.app.network.AiSiteOfferDto
import com.dorr.app.network.AiSiteProjectDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.LocalFile
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.screens.chat.copyToCache
import com.dorr.app.ui.screens.wallet.PadResult
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.time.Instant

private sealed interface SitesView {
    data object Home : SitesView
    data class Form(val offer: AiSiteOfferDto?) : SitesView
    data class Detail(val id: Int) : SitesView
}

private val SITE_TYPES = listOf("portfolio", "business", "store_showcase", "restaurant", "personal", "landing", "other")

private fun siteTypeLabel(type: String): Int = when (type) {
    "portfolio" -> R.string.ai_sites_type_portfolio
    "business" -> R.string.ai_sites_type_business
    "store_showcase" -> R.string.ai_sites_type_store_showcase
    "restaurant" -> R.string.ai_sites_type_restaurant
    "personal" -> R.string.ai_sites_type_personal
    "landing" -> R.string.ai_sites_type_landing
    else -> R.string.ai_sites_type_other
}

private fun siteStatusLabel(status: String): Int = when (status) {
    "generating" -> R.string.ai_sites_status_generating
    "ready" -> R.string.ai_sites_status_ready
    "failed" -> R.string.ai_sites_status_failed
    else -> R.string.ai_sites_status_draft
}

/**
 * The AI website builder: list of the customer's sites, a one-shot brief form (plan allowance or a
 * standalone purchase behind the wallet PIN), and a detail page to preview, ask for changes, restore an
 * older version or retry a failed first build. The generated site itself opens in the browser - it is
 * served by the backend on its own capability link, never rendered inside the app.
 */
@Composable
internal fun AiSitesScreen(night: Boolean, onExit: () -> Unit) {
    val bg = if (night) Ai.bgDark else Ai.bgLight
    val ink = if (night) Ai.inkDark else Ai.inkLight
    val mut = if (night) Ai.mutDark else Ai.mutLight
    val surface = if (night) Ai.surfaceDark else Ai.surfaceLight

    var view by remember { mutableStateOf<SitesView>(SitesView.Home) }

    BackHandler {
        if (view is SitesView.Home) onExit() else view = SitesView.Home
    }

    Column(Modifier.fillMaxSize().background(bg)) {
        Row(
            Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 8.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                Modifier.size(38.dp).clip(CircleShape).clickable { if (view is SitesView.Home) onExit() else view = SitesView.Home },
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Rounded.ArrowBackIosNew, contentDescription = null, tint = ink, modifier = Modifier.size(18.dp))
            }
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.ai_sites_title), color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
        }

        when (val v = view) {
            is SitesView.Home -> SitesHome(night, ink, mut, surface, onNew = { view = SitesView.Form(it) }, onOpen = { view = SitesView.Detail(it) })
            is SitesView.Form -> SitesForm(night, ink, mut, surface, v.offer, onDone = { view = SitesView.Detail(it) })
            is SitesView.Detail -> SitesDetail(night, ink, mut, surface, v.id, onDeleted = { view = SitesView.Home })
        }
    }
}

@Composable
internal fun PrimaryButton(label: String, enabled: Boolean = true, onClick: () -> Unit) {
    Box(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp))
            .background(if (enabled) Ai.Red else Ai.Red.copy(alpha = 0.4f))
            .clickable(enabled = enabled, onClick = onClick)
            .padding(vertical = 12.dp),
        contentAlignment = Alignment.Center,
    ) { Text(label, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 14.sp) }
}

@Composable
private fun SitesHome(
    night: Boolean,
    ink: Color,
    mut: Color,
    surface: Color,
    onNew: (AiSiteOfferDto?) -> Unit,
    onOpen: (Int) -> Unit,
) {
    var loading by remember { mutableStateOf(true) }
    var offers by remember { mutableStateOf<AiSiteOffersDto?>(null) }
    var projects by remember { mutableStateOf<List<AiSiteProjectDto>>(emptyList()) }
    var pickOffer by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        offers = runCatching { ApiClient.aiSites.offers(chatAuth()).data }.getOrNull()
        projects = runCatching { ApiClient.aiSites.projects(chatAuth()).data.orEmpty() }.getOrDefault(emptyList())
        loading = false
    }

    if (loading) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ai.Red) }
        return
    }

    val info = offers
    val available = info?.offers.orEmpty().filter { it.available }
    val canBuild = info != null && info.enabled && (info.plan?.canCreate == true || available.isNotEmpty())

    LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), contentPadding = PaddingValues(top = 6.dp, bottom = 32.dp)) {
        item {
            when {
                info != null && !info.enabled -> Text(stringResource(R.string.ai_sites_unavailable), color = mut, fontSize = 13.sp)
                !canBuild -> Text(stringResource(R.string.ai_sites_not_included), color = mut, fontSize = 13.sp)
                else -> PrimaryButton(stringResource(R.string.ai_sites_new)) {
                    if (info?.plan?.canCreate == true) onNew(null) else pickOffer = true
                }
            }
            Spacer(Modifier.height(14.dp))
        }

        if (pickOffer) {
            item {
                Text(stringResource(R.string.ai_sites_choose_offer), color = ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                Spacer(Modifier.height(6.dp))
            }
            items(available, key = { it.id }) { offer ->
                Column(
                    Modifier.fillMaxWidth().padding(vertical = 4.dp).clip(RoundedCornerShape(16.dp)).background(surface)
                        .clickable { onNew(offer) }.padding(14.dp),
                ) {
                    Text(offer.name, color = ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    Text(
                        stringResource(R.string.ai_sites_offer_line, offer.name, offer.price?.toString().orEmpty(), offer.currency.orEmpty()),
                        color = Ai.Red, fontSize = 13.sp, fontWeight = FontWeight.SemiBold,
                    )
                    Text(stringResource(R.string.ai_sites_offer_gens, offer.generationsIncluded), color = mut, fontSize = 12.sp)
                    if (!offer.description.isNullOrBlank()) Text(offer.description, color = mut, fontSize = 12.sp)
                }
            }
            item { Spacer(Modifier.height(12.dp)) }
        }

        if (projects.isEmpty()) {
            item { Text(stringResource(R.string.ai_sites_empty), color = mut, fontSize = 13.sp) }
        }

        items(projects, key = { it.id }) { project ->
            Row(
                Modifier.fillMaxWidth().padding(vertical = 4.dp).clip(RoundedCornerShape(16.dp)).background(surface)
                    .clickable { onOpen(project.id) }.padding(14.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column(Modifier.weight(1f)) {
                    Text(project.title, color = ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    Text(
                        if (project.isDisabled) stringResource(R.string.ai_sites_disabled) else stringResource(siteStatusLabel(project.status)),
                        color = if (project.status == "failed" || project.isDisabled) Color(0xFFDC2626) else mut,
                        fontSize = 12.sp,
                    )
                }
            }
        }
    }
}

@Composable
internal fun SiteField(label: String, value: String, onChange: (String) -> Unit, singleLine: Boolean = true, minLines: Int = 1) {
    OutlinedTextField(
        value = value,
        onValueChange = onChange,
        label = { Text(label, fontSize = 12.sp) },
        singleLine = singleLine,
        minLines = minLines,
        modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp),
    )
}

private fun textPart(value: String): RequestBody = value.toRequestBody("text/plain".toMediaTypeOrNull())

private fun filePart(name: String, file: LocalFile): MultipartBody.Part =
    MultipartBody.Part.createFormData(name, file.name, file.file.asRequestBody(file.mime.toMediaTypeOrNull()))

@Composable
private fun SitesForm(
    night: Boolean,
    ink: Color,
    mut: Color,
    surface: Color,
    offer: AiSiteOfferDto?,
    onDone: (Int) -> Unit,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    var title by remember { mutableStateOf("") }
    var business by remember { mutableStateOf("") }
    var activity by remember { mutableStateOf("") }
    var siteType by remember { mutableStateOf("business") }
    var description by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var whatsapp by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var address by remember { mutableStateOf("") }
    var primary by remember { mutableStateOf("") }
    var secondary by remember { mutableStateOf("") }
    var logo by remember { mutableStateOf<LocalFile?>(null) }
    var images by remember { mutableStateOf<List<LocalFile>>(emptyList()) }
    var submitting by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var askPin by remember { mutableStateOf(false) }
    var noPin by remember { mutableStateOf(false) }

    val logoPicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null) logo = copyToCache(context, uri, "logo.png")
    }
    val imagesPicker = rememberLauncherForActivityResult(ActivityResultContracts.GetMultipleContents()) { uris ->
        images = (images + uris.mapNotNull { copyToCache(context, it, "photo.jpg") }).take(8)
    }

    fun fields(): Map<String, RequestBody> {
        val map = linkedMapOf(
            "title" to textPart(title.trim()),
            "business_name" to textPart(business.trim()),
            "activity" to textPart(activity.trim()),
            "site_type" to textPart(siteType),
            "description" to textPart(description.trim()),
            "languages[0]" to textPart("ar"),
        )
        fun opt(key: String, value: String) { if (value.isNotBlank()) map[key] = textPart(value.trim()) }
        opt("contact[phone]", phone)
        opt("contact[whatsapp]", whatsapp)
        opt("contact[email]", email)
        opt("contact[address]", address)
        opt("colors[primary]", primary)
        opt("colors[secondary]", secondary)
        if (offer != null) map["offer_id"] = textPart(offer.id.toString())
        return map
    }

    fun parts() = images.map { filePart("images[]", it) }

    val valid = title.isNotBlank() && business.isNotBlank() && activity.isNotBlank() && description.trim().length >= 10

    suspend fun submit(pin: String?): Result<AiSiteProjectDto?> = runCatching {
        if (offer == null) {
            ApiClient.aiSites.create(chatAuth(), fields(), logo?.let { filePart("logo", it) }, parts()).data
        } else {
            ApiClient.aiSites.purchase(chatAuth(), pin.orEmpty(), fields(), logo?.let { filePart("logo", it) }, parts()).data
        }
    }

    Column(Modifier.fillMaxSize().imePadding().verticalScroll(rememberScrollState()).padding(horizontal = 16.dp).padding(bottom = 32.dp)) {
        SiteField(stringResource(R.string.ai_sites_f_title), title, { title = it })
        SiteField(stringResource(R.string.ai_sites_f_business), business, { business = it })
        SiteField(stringResource(R.string.ai_sites_f_activity), activity, { activity = it })

        Text(stringResource(R.string.ai_sites_f_type), color = mut, fontSize = 12.sp, modifier = Modifier.padding(top = 8.dp, bottom = 4.dp))
        SITE_TYPES.chunked(3).forEach { rowTypes ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                rowTypes.forEach { type ->
                    val on = type == siteType
                    Box(
                        Modifier.clip(RoundedCornerShape(10.dp)).background(if (on) Ai.Red else surface)
                            .clickable { siteType = type }.padding(horizontal = 12.dp, vertical = 8.dp),
                    ) { Text(stringResource(siteTypeLabel(type)), color = if (on) Color.White else ink, fontSize = 12.5.sp) }
                }
            }
            Spacer(Modifier.height(6.dp))
        }

        SiteField(stringResource(R.string.ai_sites_f_description), description, { description = it }, singleLine = false, minLines = 4)
        SiteField(stringResource(R.string.ai_sites_f_phone), phone, { phone = it })
        SiteField(stringResource(R.string.ai_sites_f_whatsapp), whatsapp, { whatsapp = it })
        SiteField(stringResource(R.string.ai_sites_f_email), email, { email = it })
        SiteField(stringResource(R.string.ai_sites_f_address), address, { address = it })
        SiteColorSection(ink, mut, surface, primary, secondary) { a, b -> primary = a; secondary = b }

        Spacer(Modifier.height(14.dp))
        SiteLogoCard(ink, mut, surface, logo, onPick = { logoPicker.launch("image/*") }, onClear = { logo = null })
        Spacer(Modifier.height(10.dp))
        SiteImagesCard(ink, mut, surface, images, 8, onPick = { imagesPicker.launch("image/*") }, onRemove = { i -> images = images.filterIndexed { n, _ -> n != i } })

        error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 12.5.sp, modifier = Modifier.padding(top = 10.dp)) }
        Spacer(Modifier.height(14.dp))

        if (submitting) {
            Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ai.Red) }
        } else {
            PrimaryButton(
                if (offer == null) stringResource(R.string.ai_sites_f_submit) else stringResource(R.string.ai_sites_f_submit_buy),
                enabled = valid,
            ) {
                error = null
                if (offer == null) {
                    submitting = true
                    scope.launch {
                        submit(null).fold(
                            onSuccess = { it?.let { p -> onDone(p.id) } },
                            onFailure = { error = it.apiFailure().message },
                        )
                        submitting = false
                    }
                } else {
                    scope.launch {
                        val status = runCatching { ApiClient.wallet.pinStatus(com.dorr.app.ui.screens.wallet.walletAuth()).data }.getOrNull()
                        if (status?.hasPin == false) noPin = true else askPin = true
                    }
                }
            }
        }
    }

    if (noPin) {
        AiInfoDialog(night = night, message = stringResource(R.string.ai_subscription_no_pin_set), onDismiss = { noPin = false })
    }

    if (askPin) {
        AiWalletPinSheet(
            night = night,
            subtitle = stringResource(R.string.ai_sites_pin_sub),
            onDismiss = { askPin = false },
            onSubmit = { pin ->
                submit(pin).fold(
                    onSuccess = { project ->
                        project?.let { onDone(it.id) }
                        PadResult.Ok
                    },
                    onFailure = { e ->
                        val failure = e.apiFailure()
                        val until = failure.lockedUntil?.let { runCatching { Instant.parse(it).toEpochMilli() }.getOrNull() }
                        if (failure.errorCode == "wallet_pin_locked" && until != null) PadResult.Locked(until) else PadResult.Error(failure.message ?: "")
                    },
                )
            },
        )
    }
}

@Composable
private fun SitesDetail(night: Boolean, ink: Color, mut: Color, surface: Color, id: Int, onDeleted: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    var project by remember { mutableStateOf<AiSiteProjectDto?>(null) }
    var instruction by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var confirmDelete by remember { mutableStateOf(false) }

    suspend fun refresh() {
        runCatching { ApiClient.aiSites.project(chatAuth(), id).data }.onSuccess { project = it }
    }

    fun act(block: suspend () -> AiSiteProjectDto?) {
        busy = true
        error = null
        scope.launch {
            runCatching { block() }.fold(
                onSuccess = { updated -> if (updated != null) project = updated },
                onFailure = { error = it.apiFailure().message },
            )
            busy = false
        }
    }

    LaunchedEffect(id) {
        refresh()
        // A build runs in the background on the server; keep checking until it settles.
        while (project?.status == "generating" || project == null) {
            delay(4000)
            refresh()
        }
    }

    val p = project
    if (p == null) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ai.Red) }
        return
    }

    Column(Modifier.fillMaxSize().imePadding().verticalScroll(rememberScrollState()).padding(horizontal = 16.dp).padding(bottom = 32.dp)) {
        Text(p.title, color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
        Text(
            if (p.isDisabled) stringResource(R.string.ai_sites_disabled) else stringResource(siteStatusLabel(p.status)),
            color = if (p.status == "failed" || p.isDisabled) Color(0xFFDC2626) else mut,
            fontSize = 13.sp,
            modifier = Modifier.padding(top = 2.dp, bottom = 8.dp),
        )
        p.generationsLeft?.let { Text(stringResource(R.string.ai_sites_generations_left, it), color = mut, fontSize = 12.sp) }

        if (p.status == "generating") {
            Spacer(Modifier.height(8.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                CircularProgressIndicator(color = Ai.Red, modifier = Modifier.size(18.dp), strokeWidth = 2.dp)
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.ai_sites_building), color = mut, fontSize = 12.5.sp)
            }
        }

        (p.lastErrorMessage ?: p.lastError)?.let { Text(it, color = Color(0xFFDC2626), fontSize = 12.5.sp, modifier = Modifier.padding(top = 8.dp)) }
        error?.let { Text(it, color = Color(0xFFDC2626), fontSize = 12.5.sp, modifier = Modifier.padding(top = 8.dp)) }

        Spacer(Modifier.height(12.dp))

        p.previewUrl?.let { url ->
            PrimaryButton(stringResource(R.string.ai_sites_open)) {
                runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }
            }
            Spacer(Modifier.height(10.dp))
        }

        if (p.status == "failed" && p.versions.none { it.isCurrent }) {
            PrimaryButton(stringResource(R.string.ai_sites_retry), enabled = !busy) { act { ApiClient.aiSites.retry(chatAuth(), id).data } }
            Spacer(Modifier.height(10.dp))
        }

        if (p.versions.any { it.isCurrent } && !p.isDisabled && p.status != "generating") {
            SiteField(stringResource(R.string.ai_sites_edit_hint), instruction, { instruction = it }, singleLine = false, minLines = 3)
            PrimaryButton(stringResource(R.string.ai_sites_edit_send), enabled = !busy && instruction.trim().length >= 3) {
                val text = instruction.trim()
                instruction = ""
                act { ApiClient.aiSites.edit(chatAuth(), id, text).data }
            }
            Spacer(Modifier.height(14.dp))
        }

        if (p.versions.isNotEmpty()) {
            Text(stringResource(R.string.ai_sites_versions), color = ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
            Spacer(Modifier.height(6.dp))
            p.versions.forEach { v ->
                Row(
                    Modifier.fillMaxWidth().padding(vertical = 3.dp).clip(RoundedCornerShape(12.dp)).background(surface).padding(12.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f)) {
                        Text(
                            stringResource(R.string.ai_sites_version_n, v.number) + if (v.isCurrent) " - " + stringResource(R.string.ai_sites_current) else "",
                            color = ink, fontWeight = FontWeight.SemiBold, fontSize = 13.sp,
                        )
                        v.instruction?.let { Text(it, color = mut, fontSize = 11.5.sp, maxLines = 2) }
                        if (v.status == "failed") Text(stringResource(R.string.ai_sites_status_failed), color = Color(0xFFDC2626), fontSize = 11.5.sp)
                    }
                    if (!v.isCurrent && v.status == "completed" && !p.isDisabled && p.status != "generating") {
                        Box(
                            Modifier.clip(RoundedCornerShape(10.dp)).clickable(enabled = !busy) {
                                act { ApiClient.aiSites.restore(chatAuth(), id, v.number).data }
                            }.padding(horizontal = 10.dp, vertical = 6.dp),
                        ) { Text(stringResource(R.string.ai_sites_restore), color = Ai.Red, fontWeight = FontWeight.Bold, fontSize = 12.sp) }
                    }
                }
            }
        }

        if (p.versions.any { it.isCurrent } && !p.isDisabled) {
            Spacer(Modifier.height(18.dp))
            SiteHostingCard(night, ink, mut, surface, p.id)
        }

        Spacer(Modifier.height(20.dp))
        Box(
            Modifier.clip(RoundedCornerShape(12.dp)).clickable { confirmDelete = true }.padding(horizontal = 12.dp, vertical = 8.dp),
        ) { Text(stringResource(R.string.ai_sites_delete), color = Color(0xFFDC2626), fontWeight = FontWeight.Bold, fontSize = 13.sp) }
    }

    if (confirmDelete) {
        AiConfirmDialog(
            night = night,
            title = stringResource(R.string.ai_sites_delete),
            message = stringResource(R.string.ai_sites_delete_confirm),
            onConfirm = {
                confirmDelete = false
                scope.launch {
                    runCatching { ApiClient.aiSites.delete(chatAuth(), id) }.onSuccess { onDeleted() }
                }
            },
            onDismiss = { confirmDelete = false },
        )
    }
}
