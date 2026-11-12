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
import androidx.compose.material.icons.rounded.CalendarMonth
import androidx.compose.material.icons.automirrored.rounded.ReceiptLong
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.material.icons.rounded.MoreVert
import androidx.compose.material.icons.rounded.Autorenew
import androidx.compose.material.icons.rounded.Link
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.ui.layout.boundsInWindow
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.heightIn
import androidx.compose.material.icons.rounded.ExpandMore
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.ui.text.style.TextAlign
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
import androidx.compose.material.icons.rounded.HideImage
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.RocketLaunch
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Apps
import androidx.compose.material.icons.automirrored.rounded.Notes
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
import com.dorr.app.ui.screens.wallet.WaOutlineCircleButton
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
                is PortalPage.Edit -> PortalEditor(page.portal, onBack = pop, onChanged = { version++ }) { saved, created ->
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
    // The categories picked in the filter (none = all) — sent to the server with the search.
    var selected by remember { mutableStateOf<Set<Int>>(emptySet()) }
    var filtering by remember { mutableStateOf(false) }
    var categories by remember { mutableStateOf<List<CategoryDto>>(emptyList()) }
    // One list, 15 at a time from the server (most viewed first); the next page as its end comes into view.
    var portals by remember { mutableStateOf<List<PortalDto>?>(null) }
    var page by remember { mutableStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var loadingMore by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    suspend fun fetch(p: Int) = ApiClient.discover.portals(auth(), p, search.trim().ifEmpty { null }, selected.sorted().ifEmpty { null })

    LaunchedEffect(Unit) { categories = runCatching { ApiClient.discover.categories(auth()).data }.getOrNull().orEmpty() }
    // A new search or filter starts again from the first page (the search waits until typing stops).
    LaunchedEffect(search, selected) {
        if (search.isNotEmpty()) delay(300)
        runCatching { fetch(1) }
            .onSuccess { r -> portals = r.data.orEmpty(); page = 1; hasMore = r.pagination?.hasMorePages == true; error = null }
            .onFailure { error = it.apiFailure().message }
    }
    fun loadMore() {
        if (loadingMore || !hasMore) return
        loadingMore = true
        val asked = search to selected
        scope.launch {
            runCatching { fetch(page + 1) }.onSuccess { r ->
                // Dropped if the search or filter changed meanwhile (that load starts from page 1).
                if (asked == (search to selected)) {
                    portals = (portals.orEmpty() + r.data.orEmpty()).distinctBy { it.id }
                    page += 1
                    hasMore = r.pagination?.hasMorePages == true
                }
            }
            loadingMore = false
        }
    }

    WaPage(
        title = stringResource(R.string.pt_title),
        onBack = onBack,
        // «My portals» — the same round button as back.
        actions = { WaOutlineCircleButton(Icons.Rounded.Storefront, onMine, contentDescription = stringResource(R.string.pt_mine)) },
    ) {
        Box(Modifier.waRise(0)) { PortalsHero(onAdd) }
        Spacer(Modifier.height(12.dp))
        // The search, and beside it the filter (by category, several at once).
        Row(Modifier.fillMaxWidth().waRise(1), verticalAlignment = Alignment.CenterVertically) {
            DorrTextField(
                value = search, onValueChange = { search = it.take(60) },
                placeholder = stringResource(R.string.pt_search), icon = Icons.Rounded.Search,
                minHeight = 40.dp, modifier = Modifier.weight(1f),
            )
            if (categories.isNotEmpty()) {
                Spacer(Modifier.width(8.dp))
                FilterButton(selected.size) { filtering = true }
            }
        }
        // What's picked, in the chips' place — each with × to take it off.
        val picked = categories.filter { it.id in selected }
        if (picked.isNotEmpty()) {
            Spacer(Modifier.height(10.dp))
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp), contentPadding = PaddingValues(vertical = 2.dp), modifier = Modifier.waRise(2)) {
                items(picked, key = { it.id }) { c -> SelectedCategoryChip(c.icon, c.name.orEmpty()) { selected = selected - c.id } }
            }
        }
        WaError(error)
        Spacer(Modifier.height(12.dp))
        val list = portals
        when {
            list == null -> repeat(4) { WaSkeleton(Modifier.fillMaxWidth().height(92.dp).padding(bottom = 8.dp), RoundedCornerShape(18.dp)) }
            list.isEmpty() -> WaCard(Modifier.fillMaxWidth().padding(top = 4.dp)) {
                WaEmpty(
                    Icons.Rounded.Storefront, Tone.Red,
                    stringResource(if (search.isBlank() && selected.isEmpty()) R.string.pt_empty_title else R.string.pt_no_results),
                    stringResource(R.string.pt_empty_text),
                    action = { WaButton(stringResource(R.string.pt_add_mine), onAdd, icon = Icons.Rounded.AddBusiness) },
                )
            }
            else -> {
                list.forEachIndexed { i, portal ->
                    PortalCard(portal, Modifier.padding(bottom = 8.dp).waRise(3 + i.coerceAtMost(12))) {
                        scope.launch {
                            val opened = runCatching { ApiClient.discover.openPortal(auth(), portal.id).data }.getOrNull() ?: portal
                            runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(opened.websiteUrl))) }
                            // The count moved: show it.
                            portals = portals?.map { if (it.id == opened.id) it.copy(viewsCount = opened.viewsCount) else it }
                        }
                    }
                }
                if (hasMore) {
                    val screen = with(LocalDensity.current) { LocalConfiguration.current.screenHeightDp.dp.toPx() }
                    Box(Modifier.fillMaxWidth().height(1.dp).onGloballyPositioned { if (it.boundsInWindow().top < screen) loadMore() })
                    if (loadingMore) repeat(2) { WaSkeleton(Modifier.fillMaxWidth().height(92.dp).padding(bottom = 8.dp), RoundedCornerShape(18.dp)) }
                }
            }
        }
    }

    if (filtering) CategoryFilterSheet(categories, selected, onDismiss = { filtering = false }) {
        selected = it
        filtering = false
    }
}

/** The filter beside the search: the same height, a count on it while categories are picked. */
@Composable
private fun FilterButton(count: Int, onClick: () -> Unit) {
    val on = count > 0
    Box {
        Box(
            Modifier.size(40.dp).clip(CircleShape).background(if (on) Wa.Red.copy(alpha = 0.12f) else Wa.Field)
                .border(1.dp, if (on) Wa.Red.copy(alpha = 0.5f) else Wa.Line, CircleShape).clickable(onClick = onClick),
            contentAlignment = Alignment.Center,
        ) { Icon(Icons.Rounded.Tune, stringResource(R.string.pt_filter), tint = Wa.Red, modifier = Modifier.size(19.dp)) }
        if (on) Box(
            Modifier.align(Alignment.TopEnd).offset(x = 3.dp, y = (-3).dp).size(16.dp).clip(CircleShape).background(Wa.Red).border(1.5.dp, Wa.Bg, CircleShape),
            contentAlignment = Alignment.Center,
        ) { Text("$count", color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.Bold) }
    }
}

/** A picked category, in the chips' place: its icon, its name, × to take it off. */
@Composable
private fun SelectedCategoryChip(icon: String?, label: String, onRemove: () -> Unit) {
    Row(
        Modifier.clip(RoundedCornerShape(50)).background(Wa.Red.copy(alpha = 0.12f)).border(1.dp, Wa.Red.copy(alpha = 0.4f), RoundedCornerShape(50))
            .clickable(onClick = onRemove).padding(start = 6.dp, end = 10.dp, top = 6.dp, bottom = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        CategoryIcon(icon, 24.dp)
        Spacer(Modifier.width(6.dp))
        Text(label, color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1)
        Spacer(Modifier.width(6.dp))
        Icon(Icons.Rounded.Close, stringResource(R.string.pt_filter_remove), tint = Wa.Red, modifier = Modifier.size(16.dp))
    }
}

/**
 * Filter by category: «All» first (picking it clears the rest), then the categories — several can be ticked — each a
 * soft card with a checkbox at the other end; «Done» applies. Opens at full height.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CategoryFilterSheet(categories: List<CategoryDto>, selected: Set<Int>, onDismiss: () -> Unit, onApply: (Set<Int>) -> Unit) {
    var picked by remember { mutableStateOf(selected) }
    PortalSheet(onDismiss) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 20.dp).padding(top = 6.dp, bottom = 20.dp)) {
            SheetTitle(stringResource(R.string.pt_filter_title))
            Spacer(Modifier.height(12.dp))
            androidx.compose.foundation.lazy.LazyColumn(Modifier.fillMaxWidth().heightIn(max = 520.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                item(key = "all") { CategoryCheckRow(null, stringResource(R.string.pt_all), picked.isEmpty()) { picked = emptySet() } }
                items(categories, key = { it.id }) { c ->
                    CategoryCheckRow(c.icon, c.name.orEmpty(), c.id in picked) { picked = if (c.id in picked) picked - c.id else picked + c.id }
                }
            }
            Spacer(Modifier.height(14.dp))
            WaButton(stringResource(R.string.st_done), { onApply(picked) }, modifier = Modifier.fillMaxWidth())
        }
    }
}

/** A category to tick: a soft card, the icon and the name at the start, a checkbox at the end. */
@Composable
private fun CategoryCheckRow(icon: String?, name: String, checked: Boolean, onClick: () -> Unit) {
    val bg by animateColorAsState(if (checked) Wa.Red.copy(alpha = 0.1f) else Wa.Field, label = "checkRow")
    val edge by animateColorAsState(if (checked) Wa.Red.copy(alpha = 0.5f) else Wa.Line, label = "checkEdge")
    val shape = RoundedCornerShape(16.dp)
    Row(
        Modifier.fillMaxWidth().clip(shape).background(bg).border(1.dp, edge, shape).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (icon == null) {
            Box(Modifier.size(34.dp).clip(RoundedCornerShape(10.dp)).background(Wa.Red.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Apps, null, tint = Wa.Red, modifier = Modifier.size(20.dp))
            }
        } else CategoryIcon(icon, 34.dp)
        Spacer(Modifier.width(12.dp))
        Text(name, color = Wa.Ink, fontSize = 15.sp, fontWeight = if (checked) FontWeight.ExtraBold else FontWeight.SemiBold, modifier = Modifier.weight(1f), maxLines = 1)
        Spacer(Modifier.width(8.dp))
        Box(
            Modifier.size(22.dp).clip(RoundedCornerShape(6.dp)).background(if (checked) Wa.Red else Color.Transparent)
                .border(2.dp, if (checked) Wa.Red else Wa.Soft, RoundedCornerShape(6.dp)),
            contentAlignment = Alignment.Center,
        ) { if (checked) Icon(Icons.Rounded.Check, null, tint = Color.White, modifier = Modifier.size(16.dp)) }
    }
}

@Composable
private fun PortalsHero(onAdd: () -> Unit) {
    // The icon and the title at the start, «Add your portal» at the end; a line; then the note.
    Column(Modifier.fillMaxWidth().clip(Wa.CardShape).background(Wa.HeroBrush).padding(16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(44.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.18f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Storefront, null, tint = Color.White, modifier = Modifier.size(24.dp))
            }
            Spacer(Modifier.width(10.dp))
            Text(
                stringResource(R.string.pt_hero), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 15.5.sp,
                lineHeight = 20.sp, maxLines = 2, modifier = Modifier.weight(1f),
            )
            Spacer(Modifier.width(10.dp))
            // The same soft white highlight as the icon, white text.
            // Compact (＋ and the words), so the title keeps to one line beside it.
            Row(
                Modifier.clip(RoundedCornerShape(12.dp)).background(Color.White.copy(alpha = 0.18f)).border(1.dp, Color.White.copy(alpha = 0.3f), RoundedCornerShape(12.dp))
                    .clickable(onClick = onAdd).padding(start = 8.dp, end = 11.dp, top = 6.dp, bottom = 6.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Add, null, tint = Color.White, modifier = Modifier.size(17.dp))
                Spacer(Modifier.width(3.dp))
                Text(stringResource(R.string.pt_add_short), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 12.5.sp, maxLines = 1)
            }
        }
        Spacer(Modifier.height(14.dp))
        Box(Modifier.fillMaxWidth().height(1.dp).background(Color.White.copy(alpha = 0.22f)))
        Spacer(Modifier.height(12.dp))
        // The note: only its icon highlighted (the same soft white circle), the text plain white.
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(30.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.18f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Info, null, tint = Color.White, modifier = Modifier.size(17.dp))
            }
            Spacer(Modifier.width(10.dp))
            Text(stringResource(R.string.pt_hero_sub), color = Color.White, fontSize = 12.5.sp, lineHeight = 18.sp)
        }
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

/**
 * A portal's logo — its full address (as the category icons), and the shop icon on a soft red tile while it loads,
 * when it fails, or when there's none (never an empty gap beside the name).
 */
@Composable
internal fun PortalLogo(url: String?, size: androidx.compose.ui.unit.Dp) {
    var loaded by remember(url) { mutableStateOf(false) }
    Box(Modifier.size(size).clip(RoundedCornerShape(size / 3.5f)).background(Wa.Red.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
        if (!loaded) Icon(Icons.Rounded.Storefront, null, tint = Wa.Red, modifier = Modifier.size(size * 0.5f))
        if (url != null) AsyncImage(
            ApiClient.mediaUrl(url), null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize(),
            onSuccess = { loaded = true }, onError = { loaded = false },
        )
    }
}

@Composable
private fun PortalCard(portal: PortalDto, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val scale by rememberPressScale(source, 0.98f)
    // Top: the logo, the name and the description beside it, 🌐 at the other end. Under them: the category (under
    // the logo) and, across from it, the views.
    Column(
        modifier.fillMaxWidth().scale(scale).clip(RoundedCornerShape(18.dp)).background(Wa.Surface)
            .clickable(interactionSource = source, indication = null, onClick = onClick).padding(horizontal = 12.dp, vertical = 10.dp),
    ) {
        Row(verticalAlignment = Alignment.Top) {
            PortalLogo(portal.logo, 46.dp)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(portal.name.orEmpty(), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                portal.description?.takeIf { it.isNotBlank() }?.let {
                    Text(it, color = Wa.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis, lineHeight = 16.sp)
                }
            }
            Spacer(Modifier.width(8.dp))
            Icon(Icons.Rounded.Public, null, tint = Wa.Soft, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.height(8.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            portal.category?.name?.let {
                Text(it, color = Wa.Red, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1, modifier = Modifier.clip(RoundedCornerShape(50)).background(Wa.Red.copy(alpha = 0.1f)).padding(horizontal = 9.dp, vertical = 2.dp))
            }
            Spacer(Modifier.weight(1f))
            Icon(Icons.Rounded.Visibility, null, tint = Wa.Soft, modifier = Modifier.size(14.dp))
            Spacer(Modifier.width(4.dp))
            Text(stringResource(R.string.pt_views, compact(portal.viewsCount)), color = Wa.Soft, fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
        }
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
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var mine by remember { mutableStateOf<List<PortalDto>?>(null) }
    var error by remember { mutableStateOf<String?>(null) }
    var deleting by remember { mutableStateOf<PortalDto?>(null) }
    var menuFor by remember { mutableStateOf<PortalDto?>(null) }
    var limit by remember { mutableStateOf(15) }
    // The state chips: null = all.
    var stateFilter by remember { mutableStateOf<PortalState?>(null) }
    var historyFor by remember { mutableStateOf<PortalDto?>(null) }
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
            list == null -> repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(96.dp).padding(bottom = 10.dp), RoundedCornerShape(18.dp)) }
            list.isEmpty() -> WaCard(Modifier.fillMaxWidth()) {
                WaEmpty(Icons.Rounded.Storefront, Tone.Red, stringResource(R.string.pt_mine_empty), stringResource(R.string.pt_mine_empty_text))
            }
            else -> {
                // At a glance: how many, how many showing now, all their views.
                Row(Modifier.fillMaxWidth().waRise(0), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    StatTile(Icons.Rounded.Storefront, "${list.size}", stringResource(R.string.pt_stat_portals), Wa.Ink, Modifier.weight(1f))
                    StatTile(Icons.Rounded.CheckCircle, "${list.count { it.isListed == true }}", stringResource(R.string.pt_stat_active), Wa.Green, Modifier.weight(1f))
                    StatTile(Icons.Rounded.Visibility, compact(list.sumOf { it.viewsCount }), stringResource(R.string.pt_stat_views), Wa.Ink, Modifier.weight(1f))
                }
                // By state — each with its count; an empty one isn't shown.
                val counts = list.groupingBy { portalState(it) }.eachCount()
                Spacer(Modifier.height(10.dp))
                LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp), contentPadding = PaddingValues(vertical = 2.dp), modifier = Modifier.waRise(0)) {
                    item { StateChip(stringResource(R.string.pt_f_all), list.size, stateFilter == null) { stateFilter = null } }
                    listOf(
                        PortalState.Active to R.string.pt_f_active, PortalState.Ending to R.string.pt_f_ending, PortalState.Ended to R.string.pt_f_ended,
                        PortalState.Never to R.string.pt_f_never, PortalState.Off to R.string.pt_f_off,
                    ).forEach { (state, label) ->
                        val n = counts[state] ?: 0
                        if (n > 0) item(key = state.name) { StateChip(stringResource(label), n, stateFilter == state) { stateFilter = if (stateFilter == state) null else state } }
                    }
                }
                Spacer(Modifier.height(12.dp))
                val shown = stateFilter?.let { f -> list.filter { portalState(it) == f } } ?: list
                // 15 at a time, the next 15 as the end comes into view.
                shown.take(limit).forEachIndexed { i, portal ->
                    MyPortalCard(portal, Modifier.padding(bottom = 8.dp).waRise(1 + i), onMenu = { menuFor = portal }, onRenew = { onPlans(portal) })
                }
                if (limit < shown.size) {
                    val screen = with(LocalDensity.current) { LocalConfiguration.current.screenHeightDp.dp.toPx() }
                    Box(Modifier.fillMaxWidth().height(1.dp).onGloballyPositioned { if (it.boundsInWindow().top < screen) limit += 15 })
                }
            }
        }
    }

    // ⋮ — what can be done with it.
    menuFor?.let { portal ->
        PortalMenuSheet(
            portal, onDismiss = { menuFor = null },
            onEdit = { menuFor = null; onEdit(portal) },
            onRenew = { menuFor = null; onPlans(portal) },
            onHistory = { menuFor = null; historyFor = portal },
            onOpen = { menuFor = null; runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(portal.websiteUrl))) } },
            onCopy = {
                menuFor = null
                (context.getSystemService(android.content.Context.CLIPBOARD_SERVICE) as android.content.ClipboardManager)
                    .setPrimaryClip(android.content.ClipData.newPlainText("portal", portal.websiteUrl))
                android.widget.Toast.makeText(context, context.getString(R.string.pt_copied), android.widget.Toast.LENGTH_SHORT).show()
            },
            onDelete = { menuFor = null; deleting = portal },
        )
    }

    historyFor?.let { portal -> HistorySheet(portal) { historyFor = null } }

    deleting?.let { portal ->
        PortalConfirmDialog(
            icon = Icons.Rounded.DeleteOutline,
            title = stringResource(R.string.pt_delete_title),
            text = stringResource(R.string.pt_delete_text, portal.name.orEmpty()),
            confirm = stringResource(R.string.pt_delete),
            onDismiss = { deleting = null },
        ) {
            deleting = null
            scope.launch {
                runCatching { ApiClient.discover.deletePortal(auth(), portal.id) }
                    .onSuccess { mine = mine?.filterNot { it.id == portal.id } }
                    .onFailure { error = it.apiFailure().message }
            }
        }
    }
}

/**
 * A yes / no question in the portals' own look (not the system's dialog): the app's surface, a round icon in the
 * danger colour, the title and the text, then «Cancel» (ghost) and the red action side by side.
 */
@Composable
private fun PortalConfirmDialog(icon: ImageVector, title: String, text: String, confirm: String, onDismiss: () -> Unit, onConfirm: () -> Unit) {
    com.dorr.app.ui.locale.LocaleAwareDialog(onDismissRequest = onDismiss) {
        Column(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(26.dp)).background(Wa.Surface).border(1.dp, Wa.Line, RoundedCornerShape(26.dp)).padding(22.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Box(Modifier.size(56.dp).clip(CircleShape).background(Wa.Danger.copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(icon, null, tint = Wa.Danger, modifier = Modifier.size(26.dp))
            }
            Spacer(Modifier.height(14.dp))
            Text(title, color = Wa.Ink, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
            Text(text, color = Wa.Mut, fontSize = 13.5.sp, lineHeight = 21.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 8.dp, bottom = 20.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                WaButton(stringResource(R.string.wa_cancel), onDismiss, style = WaButtonStyle.Ghost, compact = true, modifier = Modifier.weight(1f))
                Box(
                    Modifier.weight(1f).height(40.dp).clip(RoundedCornerShape(18.dp)).background(Wa.Danger).clickable(onClick = onConfirm),
                    contentAlignment = Alignment.Center,
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Rounded.DeleteOutline, null, tint = Color.White, modifier = Modifier.size(16.dp))
                        Spacer(Modifier.width(6.dp))
                        Text(confirm, color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
                    }
                }
            }
        }
    }
}

/**
 * Every portals sheet, the way the chat's sheets look: the handle in the middle and a round × beside it (at the
 * end), the app's surface colour, the app's language (a sheet is a window of its own). Always opened to its full content
 * height — never stopped half-way, which cut the ⋮ menu short.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PortalSheet(onDismiss: () -> Unit, content: @Composable ColumnScope.() -> Unit) {
    val state = androidx.compose.material3.rememberModalBottomSheetState(skipPartiallyExpanded = true)
    val scope = rememberCoroutineScope()
    val locale = com.dorr.app.ui.locale.rememberAppLocaleFrame()
    val muted = androidx.compose.ui.graphics.lerp(Wa.Surface, Wa.Ink, 0.08f)
    androidx.compose.material3.ModalBottomSheet(
        onDismissRequest = onDismiss, sheetState = state, containerColor = Wa.Surface,
        shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp),
        dragHandle = {
            locale.Provide {
                Box(Modifier.fillMaxWidth().padding(start = 14.dp, end = 14.dp, top = 10.dp, bottom = 2.dp)) {
                    Box(Modifier.align(Alignment.Center).size(width = 36.dp, height = 4.dp).clip(CircleShape).background(Wa.Soft.copy(alpha = 0.45f)))
                    Box(
                        Modifier.align(Alignment.CenterEnd).size(32.dp).clip(CircleShape).background(muted)
                            .clickable { scope.launch { state.hide() }.invokeOnCompletion { onDismiss() } },
                        contentAlignment = Alignment.Center,
                    ) { Icon(Icons.Rounded.Close, stringResource(R.string.ch_close_sheet), tint = Wa.Mut, modifier = Modifier.size(18.dp)) }
                }
            }
        },
    ) { locale.Provide { content() } }
}

/** A portals sheet's title. */
@Composable
private fun SheetTitle(title: String, @Suppress("UNUSED_PARAMETER") onClose: () -> Unit = {}) {
    Text(title, color = Wa.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
}

/** A number at a glance with its icon and name (the top of «My portals»). */
@Composable
private fun StatTile(icon: ImageVector, value: String, label: String, valueColor: Color, modifier: Modifier = Modifier) {
    Column(modifier.clip(RoundedCornerShape(14.dp)).background(Wa.Surface).border(1.dp, Wa.Line, RoundedCornerShape(14.dp)).padding(horizontal = 10.dp, vertical = 8.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(icon, null, tint = Wa.Red, modifier = Modifier.size(14.dp))
            Spacer(Modifier.width(5.dp))
            Text(value, color = valueColor, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
        }
        Text(label, color = Wa.Mut, fontSize = 11.sp, maxLines = 1)
    }
}

/** Every period paid for a portal, newest first: the package and what was paid, from → to, and its state. */
@Composable
private fun HistorySheet(portal: PortalDto, onDismiss: () -> Unit) {
    var history by remember { mutableStateOf<List<com.dorr.app.network.PortalSubscriptionDto>?>(null) }
    LaunchedEffect(portal.id) { history = runCatching { ApiClient.discover.portal(auth(), portal.id).data?.history }.getOrNull().orEmpty() }
    PortalSheet(onDismiss) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 20.dp).padding(top = 6.dp, bottom = 20.dp)) {
            SheetTitle(stringResource(R.string.pt_history_title))
            Text(portal.name.orEmpty(), color = Wa.Mut, fontSize = 12.5.sp, maxLines = 1)
            Spacer(Modifier.height(12.dp))
            val list = history
            when {
                list == null -> repeat(2) { WaSkeleton(Modifier.fillMaxWidth().height(70.dp).padding(bottom = 8.dp), RoundedCornerShape(16.dp)) }
                list.isEmpty() -> Text(stringResource(R.string.pt_history_empty), color = Wa.Mut, fontSize = 13.5.sp, modifier = Modifier.fillMaxWidth().padding(vertical = 28.dp), textAlign = TextAlign.Center)
                else -> androidx.compose.foundation.lazy.LazyColumn(Modifier.fillMaxWidth().heightIn(max = 460.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(list.size) { i -> HistoryRow(list[i]) }
                }
            }
        }
    }
}

@Composable
private fun HistoryRow(s: com.dorr.app.network.PortalSubscriptionDto) {
    val (color, label) = when (s.state) {
        "running" -> Wa.Green to stringResource(R.string.pt_sub_running)
        "upcoming" -> Color(0xFF2563EB) to stringResource(R.string.pt_sub_upcoming)
        else -> Wa.Soft to stringResource(R.string.pt_sub_ended)
    }
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Wa.Field).border(1.dp, Wa.Line, RoundedCornerShape(16.dp)).padding(horizontal = 12.dp, vertical = 10.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(s.`package` ?: "—", color = Wa.Ink, fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.weight(1f), maxLines = 1)
            Text(money(s.amountMinor) + " " + s.currencyCode.orEmpty(), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold)
        }
        Spacer(Modifier.height(6.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.CalendarMonth, null, tint = Wa.Soft, modifier = Modifier.size(14.dp))
            Spacer(Modifier.width(4.dp))
            Text(dayMonth(s.startsAt) + " ← " + dayMonth(s.endsAt), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.weight(1f), maxLines = 1)
            Text(
                label, color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(50)).background(color.copy(alpha = 0.14f)).padding(horizontal = 8.dp, vertical = 2.dp),
            )
        }
    }
}

/** A state chip: its name and count; the one picked in the app's red. */
@Composable
private fun StateChip(label: String, count: Int, selected: Boolean, onClick: () -> Unit) {
    val bg by animateColorAsState(if (selected) Wa.Red else Wa.Surface, label = "stateChip")
    Row(
        Modifier.clip(RoundedCornerShape(50)).background(bg).border(1.dp, if (selected) Color.Transparent else Wa.Line, RoundedCornerShape(50))
            .clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, color = if (selected) Color.White else Wa.Ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
        Spacer(Modifier.width(6.dp))
        Text("$count", color = if (selected) Color.White.copy(alpha = 0.85f) else Wa.Mut, fontSize = 12.sp, fontWeight = FontWeight.Bold)
    }
}

/** Where a portal stands: off by the admin · showing until · ending within 3 days · ended · never shown. */
private enum class PortalState { Off, Active, Ending, Ended, Never }

private fun portalState(p: PortalDto): PortalState {
    if (p.status == false) return PortalState.Off
    val until = runCatching { OffsetDateTime.parse(p.listedUntil) }.getOrNull() ?: return PortalState.Never
    val now = OffsetDateTime.now()
    return when {
        until.isBefore(now) -> PortalState.Ended
        until.isBefore(now.plusDays(3)) -> PortalState.Ending
        else -> PortalState.Active
    }
}

/** «14 ديسمبر» / «14 December», in the app's language. */
private fun dayMonth(iso: String?): String = runCatching {
    OffsetDateTime.parse(iso).format(java.time.format.DateTimeFormatter.ofPattern("d MMMM", java.util.Locale(com.dorr.app.network.AppLocale.current)))
}.getOrDefault("")

/** The state as a soft pill (a dot and the words) in its colour. */
@Composable
private fun PortalStatePill(p: PortalDto) {
    val (color, text) = when (portalState(p)) {
        PortalState.Off -> Wa.Soft to stringResource(R.string.pt_disabled)
        PortalState.Active -> Wa.Green to stringResource(R.string.pt_active_until, dayMonth(p.listedUntil))
        PortalState.Ending -> Color(0xFFD97706) to stringResource(R.string.pt_ends_on, dayMonth(p.listedUntil))
        PortalState.Ended -> Wa.Danger to stringResource(R.string.pt_ended_on, dayMonth(p.listedUntil))
        PortalState.Never -> Color(0xFFD97706) to stringResource(R.string.pt_not_listed)
    }
    Row(Modifier.clip(RoundedCornerShape(50)).background(color.copy(alpha = 0.14f)).padding(horizontal = 8.dp, vertical = 3.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(6.dp).clip(CircleShape).background(color))
        Spacer(Modifier.width(5.dp))
        Text(text, color = color, fontSize = 11.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

/**
 * One of my portals: the logo, the name and its site, ⋮ at the end; under them its state and, across, its views.
 * Ending soon → «Renew now» (quiet); ended or never shown → «Renew to show again» / «Publish» (filled).
 */
@Composable
private fun MyPortalCard(p: PortalDto, modifier: Modifier, onMenu: () -> Unit, onRenew: () -> Unit) {
    val state = portalState(p)
    Column(modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Wa.Surface).border(1.dp, Wa.Line, RoundedCornerShape(16.dp)).padding(horizontal = 10.dp, vertical = 8.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            PortalLogo(p.logo, 38.dp)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text(p.name.orEmpty(), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(siteOf(p.websiteUrl), color = Wa.Mut, fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            Box(Modifier.size(30.dp).clip(CircleShape).clickable(onClick = onMenu), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.MoreVert, stringResource(R.string.pt_more), tint = Wa.Mut, modifier = Modifier.size(20.dp))
            }
        }
        Spacer(Modifier.height(6.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            PortalStatePill(p)
            Spacer(Modifier.weight(1f))
            Icon(Icons.Rounded.Visibility, null, tint = Wa.Soft, modifier = Modifier.size(13.dp))
            Spacer(Modifier.width(3.dp))
            Text(stringResource(R.string.pt_views, compact(p.viewsCount)), color = Wa.Soft, fontSize = 11.sp, fontWeight = FontWeight.SemiBold)
        }
        when (state) {
            PortalState.Ending -> {
                Spacer(Modifier.height(8.dp))
                WaButton(stringResource(R.string.pt_renew_now), onRenew, modifier = Modifier.fillMaxWidth(), style = WaButtonStyle.Ghost, icon = Icons.Rounded.Autorenew)
            }
            PortalState.Ended, PortalState.Never -> {
                Spacer(Modifier.height(8.dp))
                WaButton(
                    stringResource(if (state == PortalState.Never) R.string.pt_publish else R.string.pt_renew_relist), onRenew,
                    modifier = Modifier.fillMaxWidth(), icon = Icons.Rounded.Autorenew,
                )
            }
            else -> Unit
        }
    }
}

/** «jarir.com» from «https://www.jarir.com/…». */
private fun siteOf(url: String): String = runCatching { Uri.parse(url).host?.removePrefix("www.") }.getOrNull()?.takeIf { it.isNotBlank() } ?: url

/** ⋮ on one of my portals: who it is and its state, then edit · renew · open the site · copy the link · delete. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PortalMenuSheet(
    p: PortalDto, onDismiss: () -> Unit, onEdit: () -> Unit, onRenew: () -> Unit, onHistory: () -> Unit, onOpen: () -> Unit, onCopy: () -> Unit, onDelete: () -> Unit,
) {
    PortalSheet(onDismiss) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 20.dp).padding(top = 6.dp, bottom = 16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                PortalLogo(p.logo, 46.dp)
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(p.name.orEmpty(), color = Wa.Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    Spacer(Modifier.height(4.dp))
                    PortalStatePill(p)
                }
            }
            Spacer(Modifier.height(14.dp))
            Box(Modifier.fillMaxWidth().height(1.dp).background(Wa.Line))
            Spacer(Modifier.height(8.dp))
            MenuRow(Icons.Rounded.Edit, stringResource(R.string.pt_menu_edit), onClick = onEdit)
            if (p.status != false) MenuRow(Icons.Rounded.Autorenew, stringResource(if (portalState(p) == PortalState.Never) R.string.pt_publish else R.string.pt_menu_renew), onClick = onRenew)
            MenuRow(Icons.AutoMirrored.Rounded.ReceiptLong, stringResource(R.string.pt_menu_history), onClick = onHistory)
            MenuRow(Icons.Rounded.Public, stringResource(R.string.pt_menu_open), onClick = onOpen)
            MenuRow(Icons.Rounded.Link, stringResource(R.string.pt_menu_copy), onClick = onCopy)
            MenuRow(Icons.Rounded.DeleteOutline, stringResource(R.string.pt_menu_delete), danger = true, onClick = onDelete)
        }
    }
}

@Composable
private fun MenuRow(icon: ImageVector, text: String, danger: Boolean = false, onClick: () -> Unit) {
    val tint = if (danger) Wa.Danger else Wa.Ink
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).clickable(onClick = onClick).padding(vertical = 8.dp, horizontal = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(if (danger) Wa.Danger.copy(alpha = 0.12f) else Wa.Field),
            contentAlignment = Alignment.Center,
        ) { Icon(icon, null, tint = if (danger) Wa.Danger else Wa.Red, modifier = Modifier.size(19.dp)) }
        Spacer(Modifier.width(12.dp))
        Text(text, color = tint, fontSize = 15.sp, fontWeight = FontWeight.SemiBold)
    }
}

// =============================================================================== add / edit

@Composable
private fun PortalEditor(portal: PortalDto?, onBack: () -> Unit, onChanged: () -> Unit, onSaved: (PortalDto, Boolean) -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var languages by remember { mutableStateOf<List<PortalLanguageDto>?>(null) }
    var categories by remember { mutableStateOf<List<CategoryDto>>(emptyList()) }
    var active by remember { mutableStateOf("") }
    val names = remember { mutableStateMapOf<String, String>() }
    val descriptions = remember { mutableStateMapOf<String, String>() }
    // Empty to start: «https://» is only the hint (and added on save when it's left out).
    var website by remember { mutableStateOf(portal?.websiteUrl.orEmpty()) }
    var category by remember { mutableStateOf(portal?.category?.id) }
    // A new logo picked here (sent on save), and the portal's saved one (deleted at once, after asking).
    var logo by remember { mutableStateOf<Uri?>(null) }
    var savedLogo by remember { mutableStateOf(portal?.logo) }
    var confirmingLogoDelete by remember { mutableStateOf(false) }
    var pickingCategory by remember { mutableStateOf(false) }
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

    // «shop.com» is taken as «https://shop.com» — nothing has to be typed before the address.
    val url = website.trim().let { if (it.isNotEmpty() && !it.startsWith("http://", true) && !it.startsWith("https://", true)) "https://$it" else it }
    val urlOk = Regex("^https?://[^\\s/$.?#].[^\\s]*$", RegexOption.IGNORE_CASE).matches(url)
    val hasName = names.values.any { it.isNotBlank() }
    // The logo is optional: without one, the page shows the shop icon.
    val shownLogo: Any? = logo ?: savedLogo?.let { ApiClient.mediaUrl(it) }

    WaPage(
        title = stringResource(if (portal == null) R.string.pt_add_new else R.string.pt_edit),
        onBack = onBack,
        cta = {
            WaButton(
                stringResource(R.string.pt_save), enabled = urlOk && hasName && !busy, loading = busy, icon = Icons.Rounded.CheckCircle,
                onClick = {
                    busy = true
                    error = null
                    scope.launch {
                        try {
                            val text = "text/plain".toMediaTypeOrNull()
                            val fields = mutableMapOf<String, RequestBody>("website_url" to url.toRequestBody(text))
                            // Always sent: empty = «No category» (an edited portal's category can be taken off too).
                            fields["category_id"] = category?.toString().orEmpty().toRequestBody(text)
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
        // Logo (optional): a smaller tile with badges outside its corners — ＋ to add one (adding or editing); with a
        // logo, ✎ to change it and 🗑 to delete it.
        Column(Modifier.fillMaxWidth().waRise(0), horizontalAlignment = Alignment.CenterHorizontally) {
            val pick = { picker.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }
            Box {
                Box(
                    Modifier.size(84.dp).clip(RoundedCornerShape(24.dp)).background(Wa.HeroBrush).clickable(onClick = pick),
                    contentAlignment = Alignment.Center,
                ) {
                    if (shownLogo != null) AsyncImage(shownLogo, null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                    else Icon(Icons.Rounded.Storefront, null, tint = Color.White, modifier = Modifier.size(32.dp))
                }
                val hasOne = shownLogo != null
                LogoBadge(
                    if (hasOne) Icons.Rounded.Edit else Icons.Rounded.Add,
                    stringResource(if (hasOne) R.string.pt_logo_change else R.string.pt_logo_add),
                    Wa.Red, Modifier.align(Alignment.BottomEnd).offset(x = 6.dp, y = 6.dp), onClick = pick,
                )
                if (hasOne) LogoBadge(
                    Icons.Rounded.DeleteOutline, stringResource(R.string.pt_logo_remove),
                    Wa.Danger, Modifier.align(Alignment.BottomStart).offset(x = (-6).dp, y = 6.dp),
                ) {
                    // A logo picked here and not saved yet just goes; the saved one is deleted for good, after asking.
                    if (logo != null) logo = null else confirmingLogoDelete = true
                }
            }
            Text(stringResource(R.string.pt_logo_optional), color = Wa.Mut, fontSize = 12.sp, modifier = Modifier.padding(top = 10.dp))
        }

        Spacer(Modifier.height(14.dp))
        // The category first: a field that opens a list with a search.
        // The portal's own category shows even before the list loads (or when the admin has switched it off).
        val picked = categories.firstOrNull { it.id == category } ?: portal?.category?.takeIf { category != null && it.id == category }
        DorrTextField(
            value = picked?.name.orEmpty(), onValueChange = {}, placeholder = stringResource(R.string.pt_category_pick),
            leadingIcon = { if (picked != null) CategoryIcon(picked.icon, 26.dp) else Icon(Icons.Rounded.Category, null, tint = Wa.Soft, modifier = Modifier.size(20.dp)) },
            trailing = { Icon(Icons.Rounded.ExpandMore, null, tint = Wa.Soft, modifier = Modifier.size(22.dp)) },
            onClick = { pickingCategory = true },
            modifier = Modifier.fillMaxWidth().waRise(1),
        )
        Spacer(Modifier.height(12.dp))

        // The website: its name and «https://…» only as the hint.
        DorrTextField(
            value = website, onValueChange = { website = it.take(500).trim() }, placeholder = stringResource(R.string.pt_website_hint), icon = Icons.Rounded.Language,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri), error = website.isNotBlank() && !urlOk,
            modifier = Modifier.fillMaxWidth().waRise(2),
        )

        // Name + description: one tab per language (like the admin's cards), the AI fills the others.
        Spacer(Modifier.height(16.dp))
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
                            placeholder = stringResource(R.string.pt_name), icon = Icons.Rounded.Storefront, modifier = Modifier.fillMaxWidth(),
                        )
                        Spacer(Modifier.height(10.dp))
                        DorrTextField(
                            value = descriptions[active].orEmpty(), onValueChange = { descriptions[active] = it.take(2000) },
                            placeholder = stringResource(R.string.pt_description), icon = Icons.AutoMirrored.Rounded.Notes,
                            singleLine = false, minLines = 3, minHeight = 96.dp, modifier = Modifier.fillMaxWidth(),
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

    if (pickingCategory) CategorySheet(categories, category, onDismiss = { pickingCategory = false }) {
        category = it
        pickingCategory = false
    }

    // Deleting the saved logo: for good, right away (not on save) — then a short message.
    if (confirmingLogoDelete && portal != null) {
        PortalConfirmDialog(
            icon = Icons.Rounded.HideImage,
            title = stringResource(R.string.pt_logo_delete_title),
            text = stringResource(R.string.pt_logo_delete_text),
            confirm = stringResource(R.string.pt_delete),
            onDismiss = { confirmingLogoDelete = false },
        ) {
            confirmingLogoDelete = false
            scope.launch {
                val text = "text/plain".toMediaTypeOrNull()
                runCatching { ApiClient.discover.updatePortal(auth(), portal.id, mapOf("remove_logo" to "1".toRequestBody(text)), null) }
                    .onSuccess {
                        savedLogo = null
                        onChanged()
                        android.widget.Toast.makeText(context, context.getString(R.string.pt_logo_deleted), android.widget.Toast.LENGTH_SHORT).show()
                    }
                    .onFailure { error = it.apiFailure().message ?: context.getString(R.string.wa_error_generic) }
            }
        }
    }
}

/** A round badge outside a corner of the logo tile (＋ / ✎ / 🗑). */
@Composable
private fun LogoBadge(icon: ImageVector, label: String, color: Color, modifier: Modifier, onClick: () -> Unit) {
    Box(
        modifier.size(30.dp).clip(CircleShape).background(color).border(2.5.dp, Wa.Bg, CircleShape).clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, label, tint = Color.White, modifier = Modifier.size(16.dp)) }
}

/**
 * Pick the category: a search, then the list — 15 at a time, the next 15 as it scrolls — each row a soft card with
 * its icon, its name and a radio at the other end; «No category» first. Opens at full height.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun CategorySheet(categories: List<CategoryDto>, selected: Int?, onDismiss: () -> Unit, onPick: (Int?) -> Unit) {
    var query by remember { mutableStateOf("") }
    var limit by remember(query) { mutableStateOf(15) }
    val shown = categories.filter { query.isBlank() || it.name.orEmpty().contains(query.trim(), ignoreCase = true) }
    val listState = androidx.compose.foundation.lazy.rememberLazyListState()
    // Near the end → 15 more (the list is already here; it's drawn a page at a time).
    LaunchedEffect(listState, shown.size, limit) {
        androidx.compose.runtime.snapshotFlow { (listState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: -1) >= listState.layoutInfo.totalItemsCount - 3 }
            .collect { near -> if (near && limit < shown.size) limit += 15 }
    }
    PortalSheet(onDismiss) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 20.dp).padding(top = 6.dp, bottom = 24.dp)) {
            SheetTitle(stringResource(R.string.pt_category_pick))
            Spacer(Modifier.height(12.dp))
            DorrTextField(
                value = query, onValueChange = { query = it.take(60) }, placeholder = stringResource(R.string.pt_category_search),
                icon = Icons.Rounded.Search, modifier = Modifier.fillMaxWidth(),
            )
            Spacer(Modifier.height(12.dp))
            androidx.compose.foundation.lazy.LazyColumn(
                Modifier.fillMaxWidth().heightIn(max = 520.dp), state = listState, verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                if (query.isBlank()) item(key = "none") {
                    CategoryRow(null, stringResource(R.string.pt_category_none), selected == null) { onPick(null) }
                }
                items(shown.take(limit), key = { it.id }) { c -> CategoryRow(c.icon, c.name.orEmpty(), selected == c.id) { onPick(c.id) } }
                if (shown.isEmpty()) item(key = "empty") {
                    Text(stringResource(R.string.ch_no_results), color = Wa.Mut, fontSize = 13.5.sp, modifier = Modifier.fillMaxWidth().padding(vertical = 24.dp), textAlign = TextAlign.Center)
                }
            }
        }
    }
}

/** A category as a soft card: the icon and the name at the start, a radio at the end (filled on the one picked). */
@Composable
private fun CategoryRow(icon: String?, name: String, selected: Boolean, onClick: () -> Unit) {
    val bg by animateColorAsState(if (selected) Wa.Red.copy(alpha = 0.1f) else Wa.Field, label = "catRow")
    val edge by animateColorAsState(if (selected) Wa.Red.copy(alpha = 0.5f) else Wa.Line, label = "catEdge")
    val shape = RoundedCornerShape(16.dp)
    Row(
        Modifier.fillMaxWidth().clip(shape).background(bg).border(1.dp, edge, shape).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        CategoryIcon(icon, 34.dp)
        Spacer(Modifier.width(12.dp))
        Text(name, color = Wa.Ink, fontSize = 15.sp, fontWeight = if (selected) FontWeight.ExtraBold else FontWeight.SemiBold, modifier = Modifier.weight(1f), maxLines = 1)
        Spacer(Modifier.width(8.dp))
        val ring by animateColorAsState(if (selected) Wa.Red else Wa.Soft, label = "catRadio")
        Box(Modifier.size(22.dp).border(2.dp, ring, CircleShape), contentAlignment = Alignment.Center) {
            if (selected) Box(Modifier.size(11.dp).background(Wa.Red, CircleShape))
        }
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
        WaError(error)
        Spacer(Modifier.height(12.dp))
        val list = packages
        // First, what paying does: until when the portal will show (for the plan picked below).
        list?.firstOrNull { it.id == chosen }?.let { plan -> NewPeriodLine(portal, plan); Spacer(Modifier.height(12.dp)) }
        when {
            list == null -> repeat(3) { WaSkeleton(Modifier.fillMaxWidth().height(64.dp).padding(bottom = 8.dp), RoundedCornerShape(16.dp)) }
            list.isEmpty() -> WaCard(Modifier.fillMaxWidth()) { WaEmpty(Icons.Rounded.RocketLaunch, Tone.Gray, stringResource(R.string.pt_no_plans), stringResource(R.string.pt_no_plans_text)) }
            else -> {
                list.forEachIndexed { i, p -> PlanCard(p, selected = chosen == p.id, modifier = Modifier.padding(bottom = 8.dp).waRise(1 + i)) { chosen = p.id } }
            }
        }
    }
}

/**
 * Before paying: the portal will show until … — a renewal while it's still up adds the period after the current end
 * (no day lost), otherwise it starts now. The same sum the server does (`ChatPackage::endFrom`).
 */
@Composable
private fun NewPeriodLine(portal: PortalDto, plan: PackageDto) {
    val current = runCatching { OffsetDateTime.parse(portal.listedUntil) }.getOrNull()?.takeIf { it.isAfter(OffsetDateTime.now()) }
    val start = current ?: OffsetDateTime.now()
    val end = when (plan.period) {
        "week" -> start.plusWeeks(plan.periodCount.toLong())
        "year" -> start.plusYears(plan.periodCount.toLong())
        else -> start.plusMonths(plan.periodCount.toLong())
    }
    val shape = RoundedCornerShape(14.dp)
    Row(
        Modifier.fillMaxWidth().clip(shape).background(Wa.Red.copy(alpha = 0.08f)).border(1.dp, Wa.Red.copy(alpha = 0.25f), shape).padding(horizontal = 12.dp, vertical = 9.dp),
        verticalAlignment = Alignment.Top,
    ) {
        Icon(Icons.Rounded.CalendarMonth, null, tint = Wa.Red, modifier = Modifier.padding(top = 1.dp).size(17.dp))
        Spacer(Modifier.width(8.dp))
        Column {
            Text(stringResource(R.string.pt_new_until, dayMonthYear(end.toString())), color = Wa.Ink, fontSize = 13.sp, fontWeight = FontWeight.ExtraBold)
            if (current != null) Text(stringResource(R.string.pt_new_until_sub, dayMonth(portal.listedUntil)), color = Wa.Mut, fontSize = 11.5.sp, lineHeight = 16.sp)
        }
    }
}

/** «14 يناير 2027», in the app's language. */
private fun dayMonthYear(iso: String?): String = runCatching {
    OffsetDateTime.parse(iso).format(java.time.format.DateTimeFormatter.ofPattern("d MMMM yyyy", java.util.Locale(com.dorr.app.network.AppLocale.current)))
}.getOrDefault("")

@Composable
internal fun PlanCard(plan: PackageDto, selected: Boolean, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val border by animateColorAsState(if (selected) Wa.Red else Wa.Line, label = "plan")
    val source = remember { MutableInteractionSource() }
    val scale by rememberPressScale(source, 0.98f)
    // Top: the icon and the name beside it, across from them the price and the period under it. Under all that, the
    // description across the whole card (starting under the icon).
    Column(
        modifier.fillMaxWidth().scale(scale).clip(RoundedCornerShape(16.dp)).background(Wa.Surface)
            .border(if (selected) 1.5.dp else 1.dp, border, RoundedCornerShape(16.dp))
            .clickable(interactionSource = source, indication = null, onClick = onClick).padding(horizontal = 12.dp, vertical = 10.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            WaIconWell(Icons.Rounded.RocketLaunch, if (selected) Tone.Red else Tone.Gray, size = 34.dp, iconSize = 17.dp)
            Spacer(Modifier.width(10.dp))
            Text(plan.name.orEmpty(), color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            Spacer(Modifier.width(8.dp))
            Column(horizontalAlignment = Alignment.End) {
                androidx.compose.runtime.CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Row(verticalAlignment = Alignment.Bottom) {
                        Text(money(plan.amountMinor ?: 0), color = Wa.Ink, fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                        Spacer(Modifier.width(3.dp))
                        Text(plan.currencyCode.orEmpty(), color = Wa.Mut, fontSize = 10.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(bottom = 1.dp))
                    }
                }
                Text(periodLabel(plan), color = if (selected) Wa.Red else Wa.Mut, fontSize = 11.sp, fontWeight = FontWeight.Bold)
            }
        }
        // The radio sits in the room the description leaves at the other end, beside its last line.
        Row(Modifier.fillMaxWidth().padding(top = 6.dp), verticalAlignment = Alignment.Bottom) {
            Text(
                plan.description?.takeIf { it.isNotBlank() }.orEmpty(), color = Wa.Soft, fontSize = 11.5.sp, lineHeight = 17.sp,
                maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f),
            )
            Spacer(Modifier.width(8.dp))
            PlanRadio(selected)
        }
    }
}

/** The plan's radio: an empty ring, or the app's red with a white dot on the chosen one. */
@Composable
private fun PlanRadio(selected: Boolean) {
    val fill by animateColorAsState(if (selected) Wa.Red else Color.Transparent, label = "planRadio")
    Box(
        Modifier.size(20.dp).clip(CircleShape).background(fill).border(1.5.dp, if (selected) Wa.Red else Wa.Soft, CircleShape),
        contentAlignment = Alignment.Center,
    ) { if (selected) Box(Modifier.size(8.dp).clip(CircleShape).background(Color.White)) }
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
