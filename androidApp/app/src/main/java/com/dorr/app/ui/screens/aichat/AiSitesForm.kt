package com.dorr.app.ui.screens.aichat

import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Apps
import androidx.compose.material.icons.rounded.Business
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.Restaurant
import androidx.compose.material.icons.rounded.RocketLaunch
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AiSiteOfferDto
import com.dorr.app.network.AiSiteProjectDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.LocalFile
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.screens.chat.copyToCache
import com.dorr.app.ui.screens.wallet.PadResult
import com.dorr.app.ui.screens.wallet.walletAuth
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.time.Instant

private fun textPart(value: String): RequestBody = value.toRequestBody("text/plain".toMediaTypeOrNull())

private fun filePart(name: String, file: LocalFile): MultipartBody.Part =
    MultipartBody.Part.createFormData(name, file.name, file.file.asRequestBody(file.mime.toMediaTypeOrNull()))

private fun typeIcon(type: String): ImageVector = when (type) {
    "portfolio" -> Icons.Rounded.Work
    "business" -> Icons.Rounded.Business
    "store_showcase" -> Icons.Rounded.Storefront
    "restaurant" -> Icons.Rounded.Restaurant
    "personal" -> Icons.Rounded.Person
    "landing" -> Icons.Rounded.RocketLaunch
    else -> Icons.Rounded.Apps
}

private const val STEPS = 4

@Composable
private fun StepBar(step: Int, titles: List<String>) {
    val c = sitePalette()
    Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            repeat(STEPS) { i ->
                Box(
                    Modifier.weight(1f).height(5.dp).clip(RoundedCornerShape(50))
                        .background(if (i <= step) Ai.Red else c.line),
                )
            }
        }
        Spacer(Modifier.height(10.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(titles[step], color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp, modifier = Modifier.weight(1f))
            Text(stringResource(R.string.ai_sites_step_of, step + 1, STEPS), color = c.mut, fontSize = 12.sp)
        }
    }
}

@Composable
private fun TypeTile(type: String, selected: Boolean, modifier: Modifier, onClick: () -> Unit) {
    val c = sitePalette()
    val shape = RoundedCornerShape(16.dp)
    Column(
        modifier.clip(shape)
            .background(if (selected) Ai.Red.copy(alpha = 0.1f) else c.surface)
            .border(if (selected) 1.5.dp else 1.dp, if (selected) Ai.Red else c.line, shape)
            .clickable(onClick = onClick).padding(vertical = 14.dp, horizontal = 6.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(typeIcon(type), contentDescription = null, tint = if (selected) Ai.Red else c.mut, modifier = Modifier.size(26.dp))
        Spacer(Modifier.height(6.dp))
        Text(
            stringResource(siteTypeLabel(type)),
            color = if (selected) Ai.Red else c.ink,
            fontSize = 11.5.sp,
            fontWeight = if (selected) FontWeight.Bold else FontWeight.Medium,
            maxLines = 1,
        )
    }
}

@Composable
internal fun SitesForm(night: Boolean, offer: AiSiteOfferDto?, onDone: (Int) -> Unit) {
    val c = sitePalette()
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    var step by remember { mutableIntStateOf(0) }
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

    BackHandler(enabled = step > 0) { step -= 1 }

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

    val basicsValid = title.isNotBlank() && business.isNotBlank() && activity.isNotBlank()
    val storyValid = description.trim().length >= 10
    val valid = basicsValid && storyValid
    val canNext = when (step) {
        0 -> basicsValid
        1 -> storyValid
        else -> true
    }

    suspend fun submit(pin: String?): Result<AiSiteProjectDto?> = runCatching {
        if (offer == null) {
            ApiClient.aiSites.create(chatAuth(), fields(), logo?.let { filePart("logo", it) }, parts()).data
        } else {
            ApiClient.aiSites.purchase(chatAuth(), pin.orEmpty(), fields(), logo?.let { filePart("logo", it) }, parts()).data
        }
    }

    fun send() {
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
                val status = runCatching { ApiClient.wallet.pinStatus(walletAuth()).data }.getOrNull()
                if (status?.hasPin == false) noPin = true else askPin = true
            }
        }
    }

    val titles = listOf(
        stringResource(R.string.ai_sites_step_basics),
        stringResource(R.string.ai_sites_step_story),
        stringResource(R.string.ai_sites_step_contact),
        stringResource(R.string.ai_sites_step_look),
    )

    Column(Modifier.fillMaxSize().imePadding()) {
        StepBar(step, titles)

        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp).padding(top = 8.dp, bottom = 16.dp)) {
            when (step) {
                0 -> {
                    SiteField(stringResource(R.string.ai_sites_f_title), title, { title = it })
                    SiteField(stringResource(R.string.ai_sites_f_business), business, { business = it })
                    SiteField(stringResource(R.string.ai_sites_f_activity), activity, { activity = it })
                    Spacer(Modifier.height(6.dp))
                    SiteSectionTitle(stringResource(R.string.ai_sites_f_type))
                    Spacer(Modifier.height(10.dp))
                    SITE_TYPES.chunked(3).forEach { rowTypes ->
                        Row(Modifier.fillMaxWidth().padding(bottom = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            rowTypes.forEach { type -> TypeTile(type, type == siteType, Modifier.weight(1f)) { siteType = type } }
                            repeat(3 - rowTypes.size) { Spacer(Modifier.weight(1f)) }
                        }
                    }
                }
                1 -> {
                    SiteField(
                        stringResource(R.string.ai_sites_f_description), description, { description = it },
                        singleLine = false, minLines = 7,
                        helper = stringResource(R.string.ai_sites_f_desc_hint),
                    )
                    Text(
                        "${description.trim().length}",
                        color = if (storyValid) c.success else c.mut,
                        fontSize = 12.sp,
                        modifier = Modifier.padding(top = 4.dp),
                    )
                }
                2 -> {
                    Text(stringResource(R.string.ai_sites_f_contact_hint), color = c.mut, fontSize = 12.5.sp, lineHeight = 19.sp, modifier = Modifier.padding(bottom = 8.dp))
                    SiteField(stringResource(R.string.ai_sites_f_phone), phone, { phone = it }, keyboardType = androidx.compose.ui.text.input.KeyboardType.Phone)
                    SiteField(stringResource(R.string.ai_sites_f_whatsapp), whatsapp, { whatsapp = it }, keyboardType = androidx.compose.ui.text.input.KeyboardType.Phone)
                    SiteField(stringResource(R.string.ai_sites_f_email), email, { email = it }, keyboardType = androidx.compose.ui.text.input.KeyboardType.Email)
                    SiteField(stringResource(R.string.ai_sites_f_address), address, { address = it })
                }
                else -> {
                    SiteColorSection(c.ink, c.mut, c.surface, primary, secondary) { a, b -> primary = a; secondary = b }
                    Spacer(Modifier.height(14.dp))
                    SiteLogoCard(c.ink, c.mut, c.surface, logo, onPick = { logoPicker.launch("image/*") }, onClear = { logo = null })
                    Spacer(Modifier.height(10.dp))
                    SiteImagesCard(c.ink, c.mut, c.surface, images, 8, onPick = { imagesPicker.launch("image/*") }, onRemove = { i -> images = images.filterIndexed { n, _ -> n != i } })
                    error?.let { Text(it, color = c.danger, fontSize = 12.5.sp, modifier = Modifier.padding(top = 12.dp)) }
                }
            }
        }

        Row(
            Modifier.fillMaxWidth().background(c.surface).navigationBarsPadding().padding(horizontal = 16.dp, vertical = 12.dp),
            horizontalArrangement = Arrangement.spacedBy(10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            if (step > 0) {
                SecondaryButton(stringResource(R.string.ai_sites_back), Modifier.weight(0.45f), enabled = !submitting) { step -= 1 }
            }
            Box(Modifier.weight(1f)) {
                if (submitting) {
                    Box(Modifier.fillMaxWidth().height(52.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ai.Red) }
                } else if (step < STEPS - 1) {
                    PrimaryButton(stringResource(R.string.ai_sites_next), enabled = canNext) { step += 1 }
                } else {
                    PrimaryButton(
                        if (offer == null) stringResource(R.string.ai_sites_f_submit) else stringResource(R.string.ai_sites_f_submit_buy),
                        enabled = valid,
                    ) { send() }
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
