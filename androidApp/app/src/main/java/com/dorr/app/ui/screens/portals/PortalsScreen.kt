package com.dorr.app.ui.screens.portals

import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
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
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddAPhoto
import androidx.compose.material.icons.rounded.AddBusiness
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Category
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.DeleteOutline
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.RocketLaunch
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.Visibility
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.CategoryDto
import com.dorr.app.network.PackageDto
import com.dorr.app.network.PortalDto
import com.dorr.app.network.PortalGroupDto
import com.dorr.app.network.PortalLanguageDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.chat.compressImage
import com.dorr.app.ui.screens.chat.copyToCache
import com.dorr.app.ui.screens.wallet.CheckoutLauncher
import com.dorr.app.ui.screens.wallet.CheckoutRequest
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaButton
import com.dorr.app.ui.screens.wallet.WaButtonStyle
import com.dorr.app.ui.screens.wallet.WaCard
import com.dorr.app.ui.screens.wallet.WaCircleButton
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaError
import com.dorr.app.ui.screens.wallet.WaIconWell
import com.dorr.app.ui.screens.wallet.WaNote
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.WaSkeleton
import com.dorr.app.ui.screens.wallet.money
import com.dorr.app.ui.screens.wallet.rememberPressScale
import com.dorr.app.ui.screens.wallet.waRise
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.text.DateFormat
import java.time.OffsetDateTime
import java.util.Date

/**
 * Merchant portals (docs/remaining_chat.md ج.3): the page everyone browses (search, grouped by
 * category, most viewed first), my portals, adding one (its name and description per language,
 * the AI filling the other languages), and putting it on the page with a package — paid on the
 * one payment screen.
 */
private sealed interface PortalPage {
    data object Directory : PortalPage
    data object Mine : PortalPage
    data class Edit(val portal: PortalDto?) : PortalPage
    data class Plans(val portal: PortalDto) : PortalPage
}

private fun auth() = "Bearer ${AuthSession.token.orEmpty()}"

@Composable
fun PortalsScreen(onExit: () -> Unit) {
    val stack = remember { mutableStateListOf<PortalPage>(PortalPage.Directory) }
    var forward by remember { mutableStateOf(true) }
    /** Bumped when my portals changed (saved, paid) so the lists reload. */
    var version by remember { mutableStateOf(0) }
    val push: (PortalPage) -> Unit = { forward = true; stack.add(it) }
    val pop: () -> Unit = { if (stack.size <= 1) onExit() else { forward = false; stack.removeAt(stack.lastIndex) } }
    BackHandler { pop() }

    val sign = if (LocalLayoutDirection.current == LayoutDirection.Rtl) -1 else 1
    // Drawn over the whole screen (outside the tab scaffold): keep clear of the system bars and the keyboard.
    Box(Modifier.fillMaxSize().background(Wa.Bg).systemBarsPadding().imePadding()) {
        AnimatedContent(
            targetState = stack.last(),
            transitionSpec = {
                val enter = slideInHorizontally(tween(340)) { full -> (if (forward) sign else -sign) * full / 6 } + fadeIn(tween(300))
                val exit = slideOutHorizontally(tween(300)) { full -> (if (forward) -sign else sign) * full / 10 } + fadeOut(tween(220))
                ContentTransform(enter, exit)
            },
            label = "portalPages",
        ) { page ->
            when (page) {
                PortalPage.Directory -> PortalDirectory(onBack = pop, onMine = { push(PortalPage.Mine) }, onAdd = { push(PortalPage.Edit(null)) })
                PortalPage.Mine -> MyPortals(
                    version = version,
                    onBack = pop,
                    onAdd = { push(PortalPage.Edit(null)) },
                    onEdit = { push(PortalPage.Edit(it)) },
                    onPlans = { push(PortalPage.Plans(it)) },
                )
                is PortalPage.Edit -> PortalEditor(page.portal, onBack = pop) { saved, created ->
                    version++
                    forward = false
                    stack.removeAt(stack.lastIndex)
                    if (stack.last() != PortalPage.Mine) { forward = true; stack.add(PortalPage.Mine) }
                    // A new portal goes straight to choosing how long it shows on the page.
                    if (created) { forward = true; stack.add(PortalPage.Plans(saved)) }
                }
                is PortalPage.Plans -> PortalPlans(page.portal, onBack = pop) {
                    version++
                    pop()
                }
            }
        }
    }
}

// =============================================================================== the page everyone sees

@Composable
private fun PortalDirectory(onBack: () -> Unit, onMine: () -> Unit, onAdd: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var search by remember { mutableStateOf("") }
    var category by remember { mutableStateOf<Int?>(null) }
    var categories by remember { mutableStateOf<List<CategoryDto>>(emptyList()) }
    var groups by remember { mutableStateOf<List<PortalGroupDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) { categories = runCatching { ApiClient.discover.categories(auth()).data }.getOrNull().orEmpty() }
    LaunchedEffect(search, category) {
        if (search.isNotEmpty()) delay(300)
        runCatching { ApiClient.discover.portals(auth(), search.trim().ifEmpty { null }, category).data.orEmpty() }
            .onSuccess { groups = it; error = null }
            .onFailure { error = it.apiFailure().message }
    }

    WaPage(
        title = stringResource(R.string.pt_title),
        onBack = onBack,
        actions = { WaCircleButton(Icons.Rounded.Storefront, onMine, contentDescription = stringResource(R.string.pt_mine)) },
    ) {
        Box(Modifier.waRise(0)) { PortalsHero(onAdd) }
        Spacer(Modifier.height(12.dp))
        DorrTextField(
            value = search, onValueChange = { search = it.take(60) },
            placeholder = stringResource(R.string.pt_search), icon = Icons.Rounded.Search,
            modifier = Modifier.fillMaxWidth().waRise(1),
        )
        if (categories.isNotEmpty()) {
            Spacer(Modifier.height(10.dp))
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp), contentPadding = PaddingValues(vertical = 2.dp), modifier = Modifier.waRise(2)) {
                item { CategoryChip(null, stringResource(R.string.pt_all), selected = category == null) { category = null } }
                items(categories, key = { it.id }) { c -> CategoryChip(c.icon, c.name.orEmpty(), selected = category == c.id) { category = if (category == c.id) null else c.id } }
            }
        }
        WaError(error)
        val list = groups
        when {
            list == null -> repeat(4) { WaSkeleton(Modifier.fillMaxWidth().height(92.dp).padding(top = 12.dp), RoundedCornerShape(22.dp)) }
            list.isEmpty() -> WaCard(Modifier.fillMaxWidth().padding(top = 16.dp)) {
                WaEmpty(
                    Icons.Rounded.Storefront, Tone.Red,
                    stringResource(if (search.isBlank()) R.string.pt_empty_title else R.string.pt_no_results),
                    stringResource(R.string.pt_empty_text),
                    action = { WaButton(stringResource(R.string.pt_add_mine), onAdd, icon = Icons.Rounded.AddBusiness) },
                )
            }
            else -> list.forEachIndexed { gi, group ->
                Spacer(Modifier.height(18.dp))
                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.waRise(3 + gi)) {
                    CategoryIcon(group.category?.icon, 26.dp)
                    Spacer(Modifier.width(8.dp))
                    Text(group.category?.name ?: stringResource(R.string.pt_other), color = Wa.Ink, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f))
                    Text("${group.portals.size}", color = Wa.Mut, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                }
                Spacer(Modifier.height(8.dp))
                group.portals.forEachIndexed { i, portal ->
                    PortalCard(portal, Modifier.padding(bottom = 10.dp).waRise(4 + gi + i)) {
                        scope.launch {
                            val opened = runCatching { ApiClient.discover.openPortal(auth(), portal.id).data }.getOrNull() ?: portal
                            runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(opened.websiteUrl))) }
                            // The count moved: show it.
                            groups = groups?.map { g -> g.copy(portals = g.portals.map { if (it.id == opened.id) it.copy(viewsCount = opened.viewsCount) else it }) }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun PortalsHero(onAdd: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clip(Wa.CardShape).background(Wa.HeroBrush).padding(16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(54.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.18f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.Storefront, null, tint = Color.White, modifier = Modifier.size(30.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.pt_hero), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
            Text(stringResource(R.string.pt_hero_sub), color = Color.White.copy(alpha = 0.85f), fontSize = 12.5.sp)
        }
        Spacer(Modifier.width(8.dp))
        Box(
            Modifier.clip(RoundedCornerShape(14.dp)).background(Color.White).clickable(onClick = onAdd).padding(horizontal = 12.dp, vertical = 9.dp),
        ) { Text(stringResource(R.string.pt_add_short), color = Wa.Red, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp) }
    }
}

@Composable
private fun CategoryChip(icon: String?, label: String, selected: Boolean, onClick: () -> Unit) {
    val bg by animateColorAsState(if (selected) Wa.Red else Wa.Surface, label = "chip")
    val fg by animateColorAsState(if (selected) Color.White else Wa.Ink, label = "chipFg")
    Row(
        Modifier.clip(RoundedCornerShape(50)).background(bg).border(1.dp, if (selected) Color.Transparent else Wa.Line, RoundedCornerShape(50))
            .clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 7.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (icon != null) {
            AsyncImage(ApiClient.mediaUrl(icon), null, Modifier.size(20.dp).clip(RoundedCornerShape(6.dp)))
            Spacer(Modifier.width(6.dp))
        }
        Text(label, color = fg, fontSize = 13.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun CategoryIcon(url: String?, size: androidx.compose.ui.unit.Dp) {
    Box(Modifier.size(size).clip(RoundedCornerShape(size * 0.28f)).background(Wa.Red.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
        if (url != null) AsyncImage(ApiClient.mediaUrl(url), null, Modifier.fillMaxSize()) else Icon(Icons.Rounded.Category, null, tint = Wa.Red, modifier = Modifier.size(size * 0.65f))
    }
}

@Composable
internal fun PortalLogo(url: String?, size: androidx.compose.ui.unit.Dp) {
    Box(Modifier.size(size).clip(RoundedCornerShape(size / 3.5f)).background(Wa.Field), contentAlignment = Alignment.Center) {
        if (url != null) AsyncImage(url, null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
        else Icon(Icons.Rounded.Storefront, null, tint = Wa.Soft, modifier = Modifier.size(size * 0.5f))
    }
}

@Composable
private fun PortalCard(portal: PortalDto, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val scale by rememberPressScale(source, 0.98f)
    Row(
        modifier.fillMaxWidth().scale(scale).clip(RoundedCornerShape(22.dp)).background(Wa.Surface)
            .clickable(interactionSource = source, indication = null, onClick = onClick).padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        PortalLogo(portal.logo, 60.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(portal.name.orEmpty(), color = Wa.Ink, fontSize = 15.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            portal.description?.takeIf { it.isNotBlank() }?.let {
                Text(it, color = Wa.Mut, fontSize = 12.5.sp, maxLines = 2, overflow = TextOverflow.Ellipsis, lineHeight = 17.sp)
            }
            Spacer(Modifier.height(6.dp))
            Row(verticalAlignment = Alignment.CenterVertically) {
                portal.category?.name?.let {
                    Text(it, color = Wa.Red, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(RoundedCornerShape(50)).background(Wa.Red.copy(alpha = 0.1f)).padding(horizontal = 8.dp, vertical = 2.dp))
                    Spacer(Modifier.width(8.dp))
                }
                Icon(Icons.Rounded.Visibility, null, tint = Wa.Soft, modifier = Modifier.size(14.dp))
                Spacer(Modifier.width(3.dp))
                Text(stringResource(R.string.pt_views, compact(portal.viewsCount)), color = Wa.Soft, fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
            }
        }
        Icon(Icons.Rounded.Public, null, tint = Wa.Soft, modifier = Modifier.size(18.dp))
    }
}

private fun compact(n: Long): String = when {
    n >= 1_000_000 -> String.format("%.1fM", n / 1_000_000.0).replace(".0M", "M")
    n >= 1_000 -> String.format("%.1fK", n / 1_000.0).replace(".0K", "K")
    else -> n.toString()
}

private fun shortDate(iso: String?): String = runCatching {
    DateFormat.getDateInstance(DateFormat.MEDIUM).format(Date.from(OffsetDateTime.parse(iso).toInstant()))
}.getOrDefault("")

// =============================================================================== my portals

@Composable
private fun MyPortals(version: Int, onBack: () -> Unit, onAdd: () -> Unit, onEdit: (PortalDto) -> Unit, onPlans: (PortalDto) -> Unit) {
    val scope = rememberCoroutineScope()
    var mine by remember { mutableStateOf<List<PortalDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var deleting by remember { mutableStateOf<PortalDto?>(null) }
    LaunchedEffect(version) {
        runCatching { ApiClient.discover.myPortals(auth()).data.orEmpty() }.onSuccess { mine = it }.onFailure { error = it.apiFailure().message }
    }

    WaPage(
        title = stringResource(R.string.pt_mine),
        onBack = onBack,
        cta = { WaButton(stringResource(R.string.pt_add_new), onAdd, icon = Icons.Rounded.AddBusiness) },
    ) {
        WaError(error)
        val list = mine
        when {
            list == null -> repeat(2) { WaSkeleton(Modifier.fillMaxWidth().height(150.dp).padding(bottom = 12.dp), RoundedCornerShape(22.dp)) }
            list.isEmpty() -> WaCard(Modifier.fillMaxWidth()) {
                WaEmpty(Icons.Rounded.Storefront, Tone.Red, stringResource(R.string.pt_mine_empty), stringResource(R.string.pt_mine_empty_text))
            }
            else -> list.forEachIndexed { i, portal ->
                WaCard(Modifier.fillMaxWidth().padding(bottom = 12.dp).waRise(i), padding = 14.dp) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        PortalLogo(portal.logo, 52.dp)
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f)) {
                            Text(portal.name.orEmpty(), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(portal.websiteUrl, color = Wa.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                        WaCircleButton(Icons.Rounded.DeleteOutline, { deleting = portal }, tint = Wa.Danger)
                    }
                    Spacer(Modifier.height(10.dp))
                    ListingBadge(portal)
                    Spacer(Modifier.height(10.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        WaButton(stringResource(R.string.pt_edit), { onEdit(portal) }, modifier = Modifier.weight(1f), style = WaButtonStyle.Ghost, icon = Icons.Rounded.Edit)
                        WaButton(
                            stringResource(if (portal.isListed == true) R.string.pt_renew else R.string.pt_publish),
                            { onPlans(portal) }, modifier = Modifier.weight(1f), icon = Icons.Rounded.RocketLaunch,
                            enabled = portal.status != false,
                        )
                    }
                }
            }
        }
    }

    deleting?.let { portal ->
        androidx.compose.material3.AlertDialog(
            onDismissRequest = { deleting = null },
            title = { Text(stringResource(R.string.pt_delete_title)) },
            text = { Text(stringResource(R.string.pt_delete_text, portal.name.orEmpty())) },
            confirmButton = {
                androidx.compose.material3.TextButton(onClick = {
                    deleting = null
                    scope.launch {
                        runCatching { ApiClient.discover.deletePortal(auth(), portal.id) }
                            .onSuccess { mine = mine?.filterNot { it.id == portal.id } }
                            .onFailure { error = it.apiFailure().message }
                    }
                }) { Text(stringResource(R.string.pt_delete), color = Wa.Danger) }
            },
            dismissButton = { androidx.compose.material3.TextButton(onClick = { deleting = null }) { Text(stringResource(R.string.wa_cancel)) } },
        )
    }
}

@Composable
private fun ListingBadge(portal: PortalDto) {
    val (icon, tone, text) = when {
        portal.status == false -> Triple(Icons.Rounded.VisibilityOff, Tone.Gray, stringResource(R.string.pt_disabled))
        portal.isListed == true -> Triple(Icons.Rounded.CheckCircle, Tone.Green, stringResource(R.string.pt_listed_until, shortDate(portal.listedUntil)))
        else -> Triple(Icons.Rounded.VisibilityOff, Tone.Amber, stringResource(R.string.pt_not_listed))
    }
    Row(Modifier.clip(RoundedCornerShape(12.dp)).background(tone.bg).padding(horizontal = 10.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, null, tint = tone.fg, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(text, color = tone.fg, fontSize = 12.5.sp, fontWeight = FontWeight.Bold)
        if (portal.isListed == true) {
            Spacer(Modifier.width(8.dp))
            Icon(Icons.Rounded.Visibility, null, tint = tone.fg, modifier = Modifier.size(14.dp))
            Spacer(Modifier.width(3.dp))
            Text(compact(portal.viewsCount), color = tone.fg, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        }
    }
}

// =============================================================================== add / edit

@Composable
private fun PortalEditor(portal: PortalDto?, onBack: () -> Unit, onSaved: (PortalDto, Boolean) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var languages by remember { mutableStateOf<List<PortalLanguageDto>?>(null) }
    var categories by remember { mutableStateOf<List<CategoryDto>>(emptyList()) }
    var active by remember { mutableStateOf("") }
    val names = remember { mutableStateMapOf<String, String>() }
    val descriptions = remember { mutableStateMapOf<String, String>() }
    var website by remember { mutableStateOf(portal?.websiteUrl ?: "https://") }
    var category by remember { mutableStateOf(portal?.category?.id) }
    var logo by remember { mutableStateOf<Uri?>(null) }
    var busy by remember { mutableStateOf(false) }
    var translating by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri -> if (uri != null) logo = uri }

    LaunchedEffect(Unit) {
        portal?.translations?.forEach { t ->
            names[t.locale] = t.name.orEmpty()
            descriptions[t.locale] = t.description.orEmpty()
        }
        categories = runCatching { ApiClient.discover.categories(auth()).data }.getOrNull().orEmpty()
        val langs = runCatching { ApiClient.discover.portalLanguages(auth()).data }.getOrNull().orEmpty()
            .ifEmpty { listOf(PortalLanguageDto(com.dorr.app.network.AppLocale.current, "", null)) }
        languages = langs
        if (active.isEmpty()) active = langs.first().code
    }

    val urlOk = Regex("^https?://[^\\s/$.?#].[^\\s]*$", RegexOption.IGNORE_CASE).matches(website.trim())
    val hasName = names.values.any { it.isNotBlank() }
    val hasLogo = logo != null || portal?.logo != null

    WaPage(
        title = stringResource(if (portal == null) R.string.pt_add_new else R.string.pt_edit),
        onBack = onBack,
        cta = {
            WaButton(
                stringResource(R.string.pt_save), enabled = urlOk && hasName && hasLogo && !busy, loading = busy, icon = Icons.Rounded.CheckCircle,
                onClick = {
                    busy = true
                    error = null
                    scope.launch {
                        try {
                            val text = "text/plain".toMediaTypeOrNull()
                            val fields = mutableMapOf<String, RequestBody>("website_url" to website.trim().toRequestBody(text))
                            category?.let { fields["category_id"] = it.toString().toRequestBody(text) }
                            languages.orEmpty().forEachIndexed { i, lang ->
                                fields["translations[$i][locale]"] = lang.code.toRequestBody(text)
                                fields["translations[$i][name]"] = names[lang.code].orEmpty().trim().toRequestBody(text)
                                fields["translations[$i][description]"] = descriptions[lang.code].orEmpty().trim().toRequestBody(text)
                            }
                            val part = logo?.let { copyToCache(context, it, "logo.jpg") }?.let { compressImage(context, it) }?.let { f ->
                                MultipartBody.Part.createFormData("logo", f.name, f.file.asRequestBody(f.mime.toMediaTypeOrNull()))
                            }
                            val saved = if (portal == null) ApiClient.discover.createPortal(auth(), fields, part).data
                            else ApiClient.discover.updatePortal(auth(), portal.id, fields, part).data
                            if (saved != null) onSaved(saved, portal == null)
                        } catch (e: Exception) {
                            error = e.apiFailure().message ?: context.getString(R.string.wa_error_generic)
                        }
                        busy = false
                    }
                },
            )
        },
    ) {
        // Logo.
        Column(Modifier.fillMaxWidth().waRise(0), horizontalAlignment = Alignment.CenterHorizontally) {
            Box(
                Modifier.size(104.dp).clip(RoundedCornerShape(30.dp)).background(Wa.HeroBrush)
                    .clickable { picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) },
                contentAlignment = Alignment.Center,
            ) {
                when {
                    logo != null -> AsyncImage(logo, null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                    portal?.logo != null -> AsyncImage(portal.logo, null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                    else -> Icon(Icons.Rounded.AddAPhoto, null, tint = Color.White, modifier = Modifier.size(34.dp))
                }
            }
            Text(stringResource(R.string.pt_logo), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.padding(top = 6.dp))
        }

        WaSectionTitle(stringResource(R.string.pt_website), Modifier.waRise(1))
        DorrTextField(
            value = website, onValueChange = { website = it.take(500).trim() }, placeholder = "https://", icon = Icons.Rounded.Language,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri), error = website.length > 8 && !urlOk,
            modifier = Modifier.fillMaxWidth().waRise(1),
        )

        if (categories.isNotEmpty()) {
            WaSectionTitle(stringResource(R.string.pt_category), Modifier.waRise(2))
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.waRise(2)) {
                items(categories, key = { it.id }) { c -> CategoryChip(c.icon, c.name.orEmpty(), selected = category == c.id) { category = if (category == c.id) null else c.id } }
            }
        }

        // Name + description: one tab per language (like the admin's cards), the AI fills the others.
        WaSectionTitle(stringResource(R.string.pt_name_desc), Modifier.waRise(3))
        val langs = languages
        if (langs == null) {
            WaSkeleton(Modifier.fillMaxWidth().height(160.dp), RoundedCornerShape(20.dp))
        } else {
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.waRise(3)) {
                items(langs, key = { it.code }) { lang ->
                    LanguageTab(lang, selected = active == lang.code, filled = names[lang.code].orEmpty().isNotBlank()) { active = lang.code }
                }
            }
            Spacer(Modifier.height(10.dp))
            WaCard(Modifier.fillMaxWidth().waRise(4), padding = 14.dp) {
                val rtl = langs.firstOrNull { it.code == active }?.direction == "rtl"
                androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides if (rtl) LayoutDirection.Rtl else LayoutDirection.Ltr) {
                    Column {
                        DorrTextField(
                            value = names[active].orEmpty(), onValueChange = { names[active] = it.take(120) },
                            label = stringResource(R.string.pt_name), icon = Icons.Rounded.Storefront, modifier = Modifier.fillMaxWidth(),
                        )
                        Spacer(Modifier.height(10.dp))
                        DorrTextField(
                            value = descriptions[active].orEmpty(), onValueChange = { descriptions[active] = it.take(2000) },
                            label = stringResource(R.string.pt_description), singleLine = false, minLines = 3, minHeight = 96.dp, modifier = Modifier.fillMaxWidth(),
                        )
                    }
                }
                if (langs.size > 1) {
                    Spacer(Modifier.height(12.dp))
                    WaButton(
                        stringResource(R.string.pt_translate), style = WaButtonStyle.Ghost, icon = Icons.Rounded.AutoAwesome,
                        enabled = names[active].orEmpty().isNotBlank() && !translating, loading = translating,
                        onClick = {
                            translating = true
                            error = null
                            scope.launch {
                                runCatching {
                                    ApiClient.discover.translatePortal(auth(), mapOf("from" to active, "name" to names[active].orEmpty(), "description" to descriptions[active]?.ifBlank { null })).data.orEmpty()
                                }.onSuccess { result ->
                                    result.forEach { (code, fields) ->
                                        fields["name"]?.takeIf { it.isNotBlank() }?.let { names[code] = it }
                                        fields["description"]?.takeIf { it.isNotBlank() }?.let { descriptions[code] = it }
                                    }
                                }.onFailure { error = it.apiFailure().message ?: context.getString(R.string.wa_error_generic) }
                                translating = false
                            }
                        },
                    )
                    WaNote(stringResource(R.string.pt_translate_note))
                }
            }
        }
        WaError(error)
    }
}

@Composable
private fun LanguageTab(lang: PortalLanguageDto, selected: Boolean, filled: Boolean, onClick: () -> Unit) {
    val bg by animateColorAsState(if (selected) Wa.Red else Wa.Surface, label = "langTab")
    val fg = if (selected) Color.White else Wa.Ink
    Row(
        Modifier.clip(RoundedCornerShape(14.dp)).background(bg).border(1.dp, if (selected) Color.Transparent else Wa.Line, RoundedCornerShape(14.dp))
            .clickable(onClick = onClick).padding(horizontal = 14.dp, vertical = 9.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(lang.name.ifBlank { lang.code.uppercase() }, color = fg, fontSize = 13.sp, fontWeight = FontWeight.Bold)
        if (filled) {
            Spacer(Modifier.width(6.dp))
            Icon(Icons.Rounded.CheckCircle, null, tint = if (selected) Color.White else Wa.Green, modifier = Modifier.size(15.dp))
        }
    }
}

// =============================================================================== putting it on the page

@Composable
private fun PortalPlans(portal: PortalDto, onBack: () -> Unit, onPaid: () -> Unit) {
    var packages by remember { mutableStateOf<List<PackageDto>?>(null) }
    var chosen by remember { mutableStateOf<Int?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    LaunchedEffect(Unit) {
        runCatching { ApiClient.discover.portalPackages(auth()).data.orEmpty() }
            .onSuccess { packages = it; chosen = it.firstOrNull()?.id }
            .onFailure { error = it.apiFailure().message }
    }

    WaPage(
        title = stringResource(R.string.pt_plans_title),
        onBack = onBack,
        cta = {
            WaButton(
                stringResource(R.string.pt_continue_to_pay), enabled = chosen != null, icon = Icons.Rounded.RocketLaunch,
                onClick = {
                    val id = chosen ?: return@WaButton
                    CheckoutLauncher.open(CheckoutRequest("chat_portal_listing", mapOf("portal_id" to portal.id, "package_id" to id))) { onPaid() }
                },
            )
        },
    ) {
        Row(Modifier.waRise(0), verticalAlignment = Alignment.CenterVertically) {
            PortalLogo(portal.logo, 48.dp)
            Spacer(Modifier.width(12.dp))
            Column {
                Text(portal.name.orEmpty(), color = Wa.Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
                Text(stringResource(R.string.pt_plans_sub), color = Wa.Mut, fontSize = 12.5.sp)
            }
        }
        if (portal.isListed == true) WaNote(stringResource(R.string.pt_renew_note, shortDate(portal.listedUntil)))
        WaError(error)
        Spacer(Modifier.height(10.dp))
        val list = packages
        when {
            list == null -> repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(76.dp).padding(bottom = 10.dp), RoundedCornerShape(20.dp)) }
            list.isEmpty() -> WaCard(Modifier.fillMaxWidth()) { WaEmpty(Icons.Rounded.RocketLaunch, Tone.Gray, stringResource(R.string.pt_no_plans), stringResource(R.string.pt_no_plans_text)) }
            else -> list.forEachIndexed { i, p -> PlanCard(p, selected = chosen == p.id, modifier = Modifier.padding(bottom = 10.dp).waRise(1 + i)) { chosen = p.id } }
        }
    }
}

@Composable
internal fun PlanCard(plan: PackageDto, selected: Boolean, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val border by animateColorAsState(if (selected) Wa.Red else Wa.Line, label = "plan")
    val source = remember { MutableInteractionSource() }
    val scale by rememberPressScale(source, 0.98f)
    Row(
        modifier.fillMaxWidth().scale(scale).clip(RoundedCornerShape(20.dp)).background(Wa.Surface)
            .border(if (selected) 2.dp else 1.dp, border, RoundedCornerShape(20.dp))
            .clickable(interactionSource = source, indication = null, onClick = onClick).padding(16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        WaIconWell(Icons.Rounded.RocketLaunch, if (selected) Tone.Red else Tone.Gray)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(plan.name.orEmpty(), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
            Text(periodLabel(plan), color = Wa.Mut, fontSize = 12.5.sp)
            plan.description?.takeIf { it.isNotBlank() }?.let { Text(it, color = Wa.Soft, fontSize = 12.sp, maxLines = 2) }
        }
        androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
            Row(verticalAlignment = Alignment.Bottom) {
                Text(money(plan.amountMinor ?: 0), color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                Spacer(Modifier.width(3.dp))
                Text(plan.currencyCode.orEmpty(), color = Wa.Mut, fontSize = 11.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 2.dp))
            }
        }
    }
}

@Composable
internal fun periodLabel(plan: PackageDto): String {
    val unit = when (plan.period) {
        "week" -> androidx.compose.ui.res.pluralStringResource(R.plurals.pt_weeks, plan.periodCount, plan.periodCount)
        "year" -> androidx.compose.ui.res.pluralStringResource(R.plurals.pt_years, plan.periodCount, plan.periodCount)
        else -> androidx.compose.ui.res.pluralStringResource(R.plurals.pt_months, plan.periodCount, plan.periodCount)
    }
    return unit
}
