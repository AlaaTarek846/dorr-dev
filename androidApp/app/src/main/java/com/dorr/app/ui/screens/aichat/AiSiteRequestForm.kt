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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AttachFile
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.LocalFile
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.screens.chat.copyToCache
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody

private const val REQ_STEPS = 4
private const val MAX_FILES = 6

private fun part(value: String): RequestBody = value.toRequestBody("text/plain".toMediaTypeOrNull())

private val PAGES = listOf("home" to R.string.ai_req_page_home, "about" to R.string.ai_req_page_about, "services" to R.string.ai_req_page_services, "products" to R.string.ai_req_page_products, "gallery" to R.string.ai_req_page_gallery, "blog" to R.string.ai_req_page_blog, "contact" to R.string.ai_req_page_contact, "pricing" to R.string.ai_req_page_pricing, "faq" to R.string.ai_req_page_faq, "booking" to R.string.ai_req_page_booking)
private val FEATURES = listOf("contact_form" to R.string.ai_req_feat_contact_form, "whatsapp" to R.string.ai_req_feat_whatsapp, "booking" to R.string.ai_req_feat_booking, "payments" to R.string.ai_req_feat_payments, "store" to R.string.ai_req_feat_store, "multilang" to R.string.ai_req_feat_multilang, "dashboard" to R.string.ai_req_feat_dashboard, "cms" to R.string.ai_req_feat_cms, "seo" to R.string.ai_req_feat_seo, "analytics" to R.string.ai_req_feat_analytics, "chat" to R.string.ai_req_feat_chat, "maps" to R.string.ai_req_feat_maps, "newsletter" to R.string.ai_req_feat_newsletter, "social" to R.string.ai_req_feat_social)
private val GOALS = listOf("leads" to R.string.ai_req_goal_leads, "sales" to R.string.ai_req_goal_sales, "brand" to R.string.ai_req_goal_brand, "bookings" to R.string.ai_req_goal_bookings, "info" to R.string.ai_req_goal_info, "portfolio" to R.string.ai_req_goal_portfolio)
private val LANGS = listOf("ar" to R.string.ai_req_lang_ar, "en" to R.string.ai_req_lang_en, "fr" to R.string.ai_req_lang_fr, "other" to R.string.ai_req_lang_other)

@Composable
private fun Label(text: String, hint: String? = null) {
    val c = sitePalette()
    Column(Modifier.padding(top = 14.dp, bottom = 8.dp)) {
        Text(text, color = c.ink, fontWeight = FontWeight.Bold, fontSize = 13.5.sp)
        if (hint != null) Text(hint, color = c.mut, fontSize = 11.5.sp, modifier = Modifier.padding(top = 2.dp))
    }
}

@Composable
private fun StepHeader(step: Int, titles: List<String>) {
    val c = sitePalette()
    Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp)) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            repeat(REQ_STEPS) { i -> Box(Modifier.weight(1f).height(5.dp).clip(RoundedCornerShape(50)).background(if (i <= step) Ai.Red else c.line)) }
        }
        Spacer(Modifier.height(10.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(titles[step], color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 17.sp, modifier = Modifier.weight(1f))
            Text(stringResource(R.string.ai_sites_step_of, step + 1, REQ_STEPS), color = c.mut, fontSize = 12.sp)
        }
    }
}

/**
 * The brief for a person-built site: everything a developer needs to quote and start without a first call.
 * Chips store the localized label so the dashboard reads naturally; the files travel in the same request.
 */
@Composable
internal fun SiteRequestForm(night: Boolean, onDone: (Int) -> Unit) {
    val c = sitePalette()
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var step by remember { mutableIntStateOf(0) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    var name by remember { mutableStateOf("") }
    var type by remember { mutableStateOf("business") }
    var description by remember { mutableStateOf("") }
    var audience by remember { mutableStateOf("") }
    var goals by remember { mutableStateOf(setOf<String>()) }
    var pages by remember { mutableStateOf(setOf<String>()) }
    var features by remember { mutableStateOf(setOf<String>()) }
    var languages by remember { mutableStateOf(setOf<String>()) }
    var style by remember { mutableStateOf("") }
    var colors by remember { mutableStateOf("") }
    var refs by remember { mutableStateOf("") }
    var hasLogo by remember { mutableStateOf<Boolean?>(null) }
    var hasDomain by remember { mutableStateOf<Boolean?>(null) }
    var domain by remember { mutableStateOf("") }
    var contentReady by remember { mutableStateOf<String?>(null) }
    var budgetMin by remember { mutableStateOf("") }
    var budgetMax by remember { mutableStateOf("") }
    var deadline by remember { mutableStateOf("") }
    var contactName by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var method by remember { mutableStateOf<String?>(null) }
    var contactTime by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    val files = remember { mutableStateListOf<LocalFile>() }

    val picker = rememberLauncherForActivityResult(ActivityResultContracts.GetMultipleContents()) { uris ->
        uris.take(MAX_FILES - files.size).forEachIndexed { i, uri -> copyToCache(context, uri, "attachment-${files.size + i}")?.let { files.add(it) } }
    }

    BackHandler(enabled = step > 0 && !busy) { step -= 1 }

    val titles = listOf(stringResource(R.string.ai_req_step_1), stringResource(R.string.ai_req_step_2), stringResource(R.string.ai_req_step_3), stringResource(R.string.ai_req_step_4))
    val yes = stringResource(R.string.ai_req_yes)
    val no = stringResource(R.string.ai_req_no)
    val ready = listOf("ready" to stringResource(R.string.ai_req_content_ready), "partial" to stringResource(R.string.ai_req_content_partial), "none" to stringResource(R.string.ai_req_content_none))
    val methods = listOf("call" to stringResource(R.string.ai_req_m_call), "whatsapp" to stringResource(R.string.ai_req_m_whatsapp), "chat" to stringResource(R.string.ai_req_m_chat), "email" to stringResource(R.string.ai_req_m_email))
    val pageLabels = PAGES.map { it.first to stringResource(it.second) }
    val featLabels = FEATURES.map { it.first to stringResource(it.second) }
    val goalLabels = GOALS.map { it.first to stringResource(it.second) }
    val langLabels = LANGS.map { it.first to stringResource(it.second) }

    val canNext = when (step) {
        0 -> name.isNotBlank() && description.trim().length >= 10
        3 -> contactName.isNotBlank() && phone.filter { it.isDigit() }.length >= 7
        else -> true
    }

    fun submit() {
        busy = true
        error = null
        scope.launch {
            val fields = linkedMapOf<String, RequestBody>()
            fun put(key: String, value: String) { if (value.isNotBlank()) fields[key] = part(value.trim()) }
            fun list(key: String, items: Collection<String>) = items.forEachIndexed { i, v -> fields["$key[$i]"] = part(v) }
            put("business_name", name); put("site_type", type); put("business_description", description); put("target_audience", audience)
            list("goals", goals); list("pages", pages); list("features", features); list("languages", languages)
            put("style_notes", style); put("colors", colors)
            list("reference_links", refs.split('\n', ' ', ',').map { it.trim() }.filter { it.isNotEmpty() }.take(5))
            hasLogo?.let { fields["has_logo"] = part(if (it) "1" else "0") }
            hasDomain?.let { fields["has_domain"] = part(if (it) "1" else "0") }
            put("domain_name", domain); contentReady?.let { put("content_ready", it) }
            put("budget_min", budgetMin); put("budget_max", budgetMax); put("deadline_days", deadline)
            put("contact_name", contactName); put("contact_phone", phone); method?.let { put("contact_method", it) }; put("contact_time", contactTime); put("notes", notes)

            val parts = files.map { MultipartBody.Part.createFormData("files[]", it.name, it.file.asRequestBody(it.mime.toMediaTypeOrNull())) }

            runCatching { ApiClient.aiSites.createRequest(chatAuth(), fields, parts).data }.fold(
                onSuccess = { created -> busy = false; if (created != null) onDone(created.id) else error = context.getString(R.string.ai_req_send_failed) },
                onFailure = { e -> busy = false; error = e.apiFailure().message?.takeIf { it.isNotBlank() } ?: context.getString(R.string.ai_req_send_failed) },
            )
        }
    }

    Column(Modifier.fillMaxSize()) {
        StepHeader(step, titles)

        Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp).padding(bottom = 16.dp)) {
            when (step) {
                0 -> {
                    SiteField(stringResource(R.string.ai_req_business_name), name, { name = it }, helper = null)
                    Label(stringResource(R.string.ai_sites_f_type))
                    PillGroup(SITE_TYPES.map { stringResource(siteTypeLabel(it)) }, setOf(stringResource(siteTypeLabel(type))), multi = false) { picked ->
                        SITE_TYPES.firstOrNull { context.getString(siteTypeLabel(it)) in picked }?.let { type = it }
                    }
                    Spacer(Modifier.height(6.dp))
                    SiteField(stringResource(R.string.ai_req_business_desc), description, { description = it }, singleLine = false, minLines = 4, helper = stringResource(R.string.ai_req_business_desc_hint))
                    SiteField(stringResource(R.string.ai_req_audience), audience, { audience = it }, singleLine = false, minLines = 2)
                    Label(stringResource(R.string.ai_req_goals))
                    PillGroup(goalLabels.map { it.second }, goals) { goals = it }
                }
                1 -> {
                    Label(stringResource(R.string.ai_req_pages), stringResource(R.string.ai_req_pick_all))
                    PillGroup(pageLabels.map { it.second }, pages) { pages = it }
                    Label(stringResource(R.string.ai_req_features), stringResource(R.string.ai_req_pick_all))
                    PillGroup(featLabels.map { it.second }, features) { features = it }
                    Label(stringResource(R.string.ai_req_languages))
                    PillGroup(langLabels.map { it.second }, languages) { languages = it }
                    Label(stringResource(R.string.ai_req_content_ready_opt))
                    PillGroup(ready.map { it.second }, setOfNotNull(ready.firstOrNull { it.first == contentReady }?.second), multi = false) { picked ->
                        contentReady = ready.firstOrNull { it.second in picked }?.first
                    }
                }
                2 -> {
                    SiteField(stringResource(R.string.ai_req_style), style, { style = it }, singleLine = false, minLines = 3, helper = stringResource(R.string.ai_req_style_hint))
                    SiteField(stringResource(R.string.ai_req_colors), colors, { colors = it })
                    SiteField(stringResource(R.string.ai_req_refs), refs, { refs = it }, singleLine = false, minLines = 2, keyboardType = KeyboardType.Uri, helper = stringResource(R.string.ai_req_refs_hint))
                    Label(stringResource(R.string.ai_req_has_logo))
                    PillGroup(listOf(yes, no), setOfNotNull(hasLogo?.let { if (it) yes else no }), multi = false) { hasLogo = it.firstOrNull() == yes }
                    Label(stringResource(R.string.ai_req_has_domain))
                    PillGroup(listOf(yes, no), setOfNotNull(hasDomain?.let { if (it) yes else no }), multi = false) { hasDomain = it.firstOrNull() == yes }
                    if (hasDomain == true) SiteField(stringResource(R.string.ai_req_domain_name), domain, { domain = it }, keyboardType = KeyboardType.Uri)
                }
                else -> {
                    SiteField(stringResource(R.string.ai_req_contact_name), contactName, { contactName = it })
                    SiteField(stringResource(R.string.ai_req_contact_phone), phone, { phone = it }, keyboardType = KeyboardType.Phone)
                    Label(stringResource(R.string.ai_req_contact_method))
                    PillGroup(methods.map { it.second }, setOfNotNull(methods.firstOrNull { it.first == method }?.second), multi = false) { picked ->
                        method = methods.firstOrNull { it.second in picked }?.first
                    }
                    Spacer(Modifier.height(6.dp))
                    SiteField(stringResource(R.string.ai_req_contact_time), contactTime, { contactTime = it })
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        Box(Modifier.weight(1f)) { SiteField(stringResource(R.string.ai_req_budget_min), budgetMin, { budgetMin = it.filter { ch -> ch.isDigit() || ch == '.' } }, keyboardType = KeyboardType.Decimal) }
                        Box(Modifier.weight(1f)) { SiteField(stringResource(R.string.ai_req_budget_max), budgetMax, { budgetMax = it.filter { ch -> ch.isDigit() || ch == '.' } }, keyboardType = KeyboardType.Decimal) }
                    }
                    SiteField(stringResource(R.string.ai_req_deadline), deadline, { deadline = it.filter { ch -> ch.isDigit() } }, keyboardType = KeyboardType.Number)
                    SiteField(stringResource(R.string.ai_req_notes), notes, { notes = it }, singleLine = false, minLines = 3)

                    Label(stringResource(R.string.ai_req_attach), stringResource(R.string.ai_req_attach_hint, MAX_FILES))
                    files.forEachIndexed { index, f ->
                        Row(
                            Modifier.fillMaxWidth().padding(bottom = 6.dp).clip(RoundedCornerShape(12.dp)).background(c.surface).border(1.dp, c.line, RoundedCornerShape(12.dp)).padding(horizontal = 12.dp, vertical = 10.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(Icons.Rounded.AttachFile, contentDescription = null, tint = c.mut, modifier = Modifier.size(18.dp))
                            Spacer(Modifier.width(8.dp))
                            Text(f.name, color = c.ink, fontSize = 12.5.sp, maxLines = 1, modifier = Modifier.weight(1f))
                            Icon(Icons.Rounded.Close, contentDescription = null, tint = c.danger, modifier = Modifier.size(18.dp).clickable { files.removeAt(index) })
                        }
                    }
                    if (files.size < MAX_FILES) {
                        SecondaryButton(stringResource(R.string.ai_req_add_file), Modifier.fillMaxWidth()) { picker.launch("*/*") }
                    }
                }
            }
        }

        error?.let {
            Text(
                it, color = c.danger, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, lineHeight = 18.sp,
                modifier = Modifier.fillMaxWidth().background(c.danger.copy(alpha = 0.1f)).padding(horizontal = 16.dp, vertical = 10.dp),
            )
        }

        Row(Modifier.fillMaxWidth().background(c.bg).navigationBarsPadding().imePadding().padding(16.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            if (step > 0) SecondaryButton(stringResource(R.string.ai_sites_back), Modifier.weight(0.4f), color = c.mut, enabled = !busy) { step -= 1 }
            Box(Modifier.weight(if (step > 0) 0.6f else 1f)) {
                if (busy) {
                    Box(Modifier.fillMaxWidth().height(52.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ai.Red, modifier = Modifier.size(26.dp), strokeWidth = 2.5.dp) }
                } else {
                    PrimaryButton(stringResource(if (step == REQ_STEPS - 1) R.string.ai_req_send else R.string.ai_sites_next), enabled = canNext) {
                        if (step == REQ_STEPS - 1) submit() else step += 1
                    }
                }
            }
        }
    }
}
