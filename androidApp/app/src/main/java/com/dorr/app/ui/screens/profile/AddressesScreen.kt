package com.dorr.app.ui.screens.profile

import android.widget.Toast
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateDpAsState
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Canvas
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
import androidx.compose.foundation.layout.imePadding
import androidx.compose.ui.layout.boundsInWindow
import androidx.compose.ui.layout.onGloballyPositioned
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AddLocationAlt
import androidx.compose.material.icons.rounded.Article
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.CloudOff
import androidx.compose.material.icons.rounded.DeleteOutline
import androidx.compose.material.icons.rounded.Domain
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.KeyboardArrowUp
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.LocationOff
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.NearMe
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.SearchOff
import androidx.compose.material.icons.rounded.TrendingUp
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AddressDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.SaveAddressRequest
import com.dorr.app.network.SetDefaultRequest
import com.dorr.app.network.serverMessage
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private data class SavedAddress(
    val id: Long,
    val kind: String,
    val label: String = "",
    val line: String = "",
    val building: String = "",
    val floor: String = "",
    val landmark: String = "",
    val isDefault: Boolean = false,
)

private fun AddressDto.toUi(): SavedAddress = SavedAddress(
    id = id,
    kind = type,
    label = title.orEmpty(),
    line = addressDetails.orEmpty(),
    building = buildingNumber.orEmpty(),
    floor = floor.orEmpty(),
    landmark = landmark.orEmpty(),
    isDefault = isDefault,
)

private fun SavedAddress.toRequest(): SaveAddressRequest = SaveAddressRequest(
    type = kind,
    title = label.ifBlank { null },
    buildingNumber = building.ifBlank { null },
    floor = floor.ifBlank { null },
    addressDetails = line.ifBlank { null },
    landmark = landmark.ifBlank { null },
    isDefault = isDefault,
)

private fun authHeader(): String = "Bearer ${AuthSession.token.orEmpty()}"

private enum class AddressListState {
    Loading,
    Error,
    Empty,
    Content,
}

@Composable
fun AddressesScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val genericError = stringResource(R.string.wallet_error_generic)

    var query by remember { mutableStateOf("") }
    var debouncedQuery by remember { mutableStateOf("") }
    var addresses by remember { mutableStateOf<List<SavedAddress>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }
    var loadError by remember { mutableStateOf<String?>(null) }
    var page by remember { mutableIntStateOf(1) }
    var hasMore by remember { mutableStateOf(false) }
    var reloadKey by remember { mutableIntStateOf(0) }
    var editing by remember { mutableStateOf<SavedAddress?>(null) }
    var adding by remember { mutableStateOf(false) }
    var deleting by remember { mutableStateOf<SavedAddress?>(null) }
    var saving by remember { mutableStateOf(false) }

    LaunchedEffect(query) {
        delay(400)
        debouncedQuery = query
        page = 1
    }

    suspend fun load() {
        loading = true
        if (page == 1) loadError = null
        runCatching {
            ApiClient.addresses.list(authHeader(), debouncedQuery.ifBlank { null }, page = page)
        }.onSuccess { envelope ->
            val rows = envelope.data.orEmpty().map { it.toUi() }
            addresses = if (page == 1) rows else addresses + rows
            hasMore = envelope.pagination?.hasMorePages == true
        }.onFailure {
            if (page == 1) loadError = it.serverMessage() ?: genericError
            else Toast.makeText(context, it.serverMessage() ?: genericError, Toast.LENGTH_SHORT).show()
        }
        loading = false
    }

    LaunchedEffect(debouncedQuery, page, reloadKey) { load() }

    fun toastError(error: Throwable) {
        Toast.makeText(context, error.serverMessage() ?: genericError, Toast.LENGTH_SHORT).show()
    }

    fun refreshFirstPage() {
        page = 1
        reloadKey++
    }

    var toast by remember { mutableStateOf<String?>(null) }
    fun showToast(message: String) {
        toast = message
    }
    LaunchedEffect(toast) {
        if (toast != null) {
            delay(2200)
            toast = null
        }
    }
    val toastSaved = stringResource(R.string.addr_toast_saved)
    val toastUpdated = stringResource(R.string.addr_toast_updated)
    val toastDeleted = stringResource(R.string.addr_toast_deleted)
    val toastDefault = stringResource(R.string.addr_toast_default)

    Box(modifier = Modifier.fillMaxSize()) {
    AnimatedContent(
        targetState = adding || editing != null,
        transitionSpec = {
            if (targetState) {
                (slideInHorizontally { width -> width } + fadeIn(tween(300)))
                    .togetherWith(slideOutHorizontally { width -> -width / 3 } + fadeOut(tween(250)))
            } else {
                (slideInHorizontally { width -> -width / 3 } + fadeIn(tween(300)))
                    .togetherWith(slideOutHorizontally { width -> width } + fadeOut(tween(250)))
            }
        },
        label = "screen_switcher",
    ) { inEditor ->
        if (inEditor) {
            AddressEditor(
                initial = editing,
                saving = saving,
                onBack = { adding = false; editing = null },
                onSave = { draft ->
                    scope.launch {
                        saving = true
                        val wasEditing = editing != null
                        val result = runCatching {
                            if (wasEditing) {
                                ApiClient.addresses.update(authHeader(), editing!!.id, draft.toRequest()).data
                            } else {
                                ApiClient.addresses.store(authHeader(), draft.toRequest()).data
                            }
                        }
                        saving = false
                        if (result.isSuccess) {
                            adding = false
                            editing = null
                            refreshFirstPage()
                            showToast(if (wasEditing) toastUpdated else toastSaved)
                        } else {
                            result.exceptionOrNull()?.let(::toastError)
                        }
                    }
                },
            )
        } else {
            Box(
                modifier = Modifier
                    .fillMaxSize()
                    .background(
                        Brush.verticalGradient(
                            colors = listOf(
                                Color(0xFFFFE0E5),
                                Color(0xFFFFF2F4),
                                Color(0xFFF9FAFB),
                                Color(0xFFF9FAFB),
                            ),
                            startY = 0f,
                            endY = 500f,
                        ),
                    ),
            ) {
                Column(
                    modifier = Modifier
                        .fillMaxSize()
                        .padding(horizontal = 20.dp),
                ) {
                    Spacer(Modifier.height(16.dp))

                    // Top Custom Header matching design exactly
                    AddressesTopHeader(
                        title = stringResource(R.string.addr_title),
                        onBack = onBack,
                    )

                    Spacer(Modifier.height(16.dp))

                    // Search Bar
                    CustomSearchPill(
                        query = query,
                        onQueryChange = { query = it },
                        placeholder = stringResource(R.string.addr_search),
                    )

                    Spacer(Modifier.height(16.dp))

                    val listState = when {
                        loading && addresses.isEmpty() -> AddressListState.Loading
                        loadError != null && addresses.isEmpty() -> AddressListState.Error
                        addresses.isEmpty() -> AddressListState.Empty
                        else -> AddressListState.Content
                    }

                    AnimatedContent(
                        targetState = listState,
                        transitionSpec = {
                            fadeIn(tween(260)).togetherWith(fadeOut(tween(200)))
                        },
                        label = "addresses_state_transition",
                        modifier = Modifier
                            .weight(1f)
                            .fillMaxWidth(),
                    ) { state ->
                        when (state) {
                            AddressListState.Loading -> {
                                Box(
                                    modifier = Modifier.fillMaxSize(),
                                    contentAlignment = Alignment.Center,
                                ) {
                                    CircularProgressIndicator(
                                        color = AppColors.waRed,
                                        strokeWidth = 2.5.dp,
                                        modifier = Modifier.size(32.dp),
                                    )
                                }
                            }
                            AddressListState.Error -> {
                                Column(
                                    modifier = Modifier
                                        .fillMaxSize()
                                        .clip(RoundedCornerShape(22.dp))
                                        .background(AppColors.danger.copy(alpha = 0.06f))
                                        .padding(20.dp),
                                    horizontalAlignment = Alignment.CenterHorizontally,
                                    verticalArrangement = Arrangement.Center,
                                ) {
                                    Icon(
                                        Icons.Rounded.CloudOff,
                                        contentDescription = null,
                                        tint = AppColors.textMuted,
                                        modifier = Modifier.size(42.dp),
                                    )
                                    Spacer(Modifier.height(10.dp))
                                    Text(
                                        loadError.orEmpty(),
                                        style = MaterialTheme.typography.bodyMedium,
                                        color = AppColors.textPrimary,
                                        textAlign = TextAlign.Center,
                                    )
                                    Spacer(Modifier.height(6.dp))
                                    TextButton(onClick = { reloadKey++ }) {
                                        Text(
                                            stringResource(R.string.services_retry),
                                            color = AppColors.waRed,
                                            fontWeight = FontWeight.Bold,
                                        )
                                    }
                                }
                            }
                            AddressListState.Empty -> {
                                Box(
                                    modifier = Modifier.fillMaxSize(),
                                    contentAlignment = Alignment.Center,
                                ) {
                                    EmptyAddressView(
                                        isSearching = debouncedQuery.isNotBlank(),
                                        onAddClick = { adding = true },
                                        onClearSearch = { query = "" },
                                    )
                                }
                            }
                            AddressListState.Content -> {
                                val loadingMore = loading && addresses.isNotEmpty()
                                LazyColumn(
                                    modifier = Modifier.fillMaxSize(),
                                    contentPadding = PaddingValues(vertical = 4.dp),
                                    verticalArrangement = Arrangement.spacedBy(14.dp),
                                ) {
                                    items(addresses, key = { it.id }) { item ->
                                        Box(modifier = Modifier.animateItem()) {
                                            AddressCard(
                                                item = item,
                                                onSelect = {
                                                    scope.launch {
                                                        runCatching {
                                                            ApiClient.addresses.setDefault(authHeader(), item.id, SetDefaultRequest(true))
                                                        }.onSuccess { refreshFirstPage(); showToast(toastDefault) }.onFailure(::toastError)
                                                    }
                                                },
                                                onEdit = { editing = item },
                                                onDelete = { deleting = item },
                                            )
                                        }
                                    }
                                    if (hasMore) {
                                        item(key = "show_more_button") {
                                            ShowMoreButton(loading = loadingMore, onClick = { page++ })
                                        }
                                    }
                                }
                            }
                        }
                    }

                    Spacer(Modifier.height(14.dp))

                    // Solid Red Add Address Button — only when addresses exist
                    // (the empty state has its own add action).
                    if (addresses.isNotEmpty()) {
                        Button(
                            onClick = { adding = true },
                            colors = ButtonDefaults.buttonColors(
                                containerColor = AppColors.waRed,
                                contentColor = Color.White,
                            ),
                            shape = RoundedCornerShape(18.dp),
                            modifier = Modifier
                                .fillMaxWidth()
                                .height(54.dp)
                                .shadow(12.dp, RoundedCornerShape(18.dp), spotColor = AppColors.waRed.copy(alpha = 0.38f)),
                        ) {
                            Text(
                                stringResource(R.string.addr_add),
                                fontSize = 16.sp,
                                fontWeight = FontWeight.ExtraBold,
                                color = Color.White,
                            )
                        }

                        Spacer(Modifier.height(20.dp))
                    }
                }
            }
        }
        AddressToastHost(toast = toast)
        }
    }

    deleting?.let { item ->
        AlertDialog(
            onDismissRequest = { deleting = null },
            title = { Text(stringResource(R.string.addr_delete_ask), fontWeight = FontWeight.Bold) },
            confirmButton = {
                TextButton(onClick = {
                    val id = item.id
                    deleting = null
                    scope.launch {
                        runCatching { ApiClient.addresses.delete(authHeader(), id) }
                            .onSuccess { refreshFirstPage(); showToast(toastDeleted) }
                            .onFailure(::toastError)
                    }
                }) {
                    Text(stringResource(R.string.addr_yes), color = AppColors.danger, fontWeight = FontWeight.Bold)
                }
            },
            dismissButton = {
                TextButton(onClick = { deleting = null }) {
                    Text(stringResource(R.string.common_cancel), color = AppColors.textSecondary)
                }
            },
        )
    }
}

/**
 * Top header with bold Red title and circular white back button
 */
@Composable
private fun AddressesTopHeader(
    title: String,
    onBack: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 4.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            text = title,
            fontSize = 24.sp,
            fontWeight = FontWeight.ExtraBold,
            color = AppColors.waRed,
        )

        Box(
            modifier = Modifier
                .size(42.dp)
                .shadow(6.dp, CircleShape, spotColor = Color(0x20000000))
                .clip(CircleShape)
                .background(Color.White)
                .clickable(onClick = onBack),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.AutoMirrored.Rounded.KeyboardArrowRight,
                contentDescription = null,
                tint = AppColors.waRed,
                modifier = Modifier.size(24.dp),
            )
        }
    }
}

/**
 * Search Pill with red search icon and clean shadow
 */
@Composable
private fun CustomSearchPill(
    query: String,
    onQueryChange: (String) -> Unit,
    placeholder: String,
) {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .shadow(6.dp, RoundedCornerShape(50), spotColor = Color(0x12000000))
            .clip(RoundedCornerShape(50))
            .background(Color.White)
            .padding(horizontal = 16.dp, vertical = 12.dp),
    ) {
        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier.fillMaxWidth(),
        ) {
            Icon(
                Icons.Rounded.Search,
                contentDescription = null,
                tint = AppColors.waRed,
                modifier = Modifier.size(20.dp),
            )
            Spacer(Modifier.width(10.dp))
            Box(modifier = Modifier.weight(1f)) {
                if (query.isEmpty()) {
                    Text(
                        placeholder,
                        color = Color(0xFF9CA3AF),
                        fontSize = 14.sp,
                    )
                }
                BasicTextField(
                    value = query,
                    onValueChange = onQueryChange,
                    singleLine = true,
                    textStyle = TextStyle(
                        fontSize = 14.sp,
                        color = Color(0xFF1E293B),
                        fontWeight = FontWeight.Medium,
                    ),
                    cursorBrush = SolidColor(AppColors.waRed),
                    modifier = Modifier.fillMaxWidth(),
                )
            }
            if (query.isNotEmpty()) {
                Icon(
                    Icons.Rounded.Close,
                    contentDescription = null,
                    tint = Color(0xFF9CA3AF),
                    modifier = Modifier
                        .size(18.dp)
                        .clickable { onQueryChange("") },
                )
            }
        }
    }
}

/**
 * In-app success notification — same design language as the wallet toast:
 * dark pill with a check, sliding up from the bottom, auto-dismissed.
 */
@Composable
private fun AddressToastHost(toast: String?) {
    var last by remember { mutableStateOf("") }
    if (toast != null) last = toast
    Box(
        modifier = Modifier
            .fillMaxSize()
            .padding(bottom = 100.dp),
        contentAlignment = Alignment.BottomCenter,
    ) {
        AnimatedVisibility(
            visible = toast != null,
            enter = slideInVertically(tween(300)) { it / 2 } + fadeIn(tween(250)),
            exit = slideOutVertically(tween(250)) { it / 2 } + fadeOut(tween(200)),
        ) {
            Row(
                modifier = Modifier
                    .clip(RoundedCornerShape(16.dp))
                    .background(AppColors.textPrimary)
                    .padding(horizontal = 18.dp, vertical = 11.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = Color.White, modifier = Modifier.size(16.dp))
                Text(last, color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

/**
 * Address card matching screenshot: rounded card with soft pink icon on left, title, default badge, subtitle,
 * soft pink edit button and red outlined delete button.
 */
@Composable
private fun AddressCard(
    item: SavedAddress,
    onSelect: () -> Unit,
    onEdit: () -> Unit,
    onDelete: () -> Unit,
) {
    val kindTitle = when (item.kind) {
        "home" -> stringResource(R.string.addr_home)
        "work" -> stringResource(R.string.addr_work)
        else -> item.label.ifBlank { stringResource(R.string.addr_other) }
    }
    val buildingLabel = stringResource(R.string.addr_building)
    val floorLabel = stringResource(R.string.addr_floor)
    val summary = listOf(
        item.line,
        item.building.takeIf { it.isNotBlank() }?.let { "$buildingLabel $it" }.orEmpty(),
        item.floor.takeIf { it.isNotBlank() }?.let { "$floorLabel $it" }.orEmpty(),
        item.landmark,
    ).filter { it.isNotBlank() }.joinToString("، ")

    Row(
        modifier = Modifier
            .fillMaxWidth()
            .shadow(6.dp, RoundedCornerShape(22.dp), spotColor = Color(0x10000000))
            .clip(RoundedCornerShape(22.dp))
            .background(Color.White)
            .clickable(onClick = onSelect)
            .padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        // Icon Container with soft pink background
        Box(
            modifier = Modifier
                .size(46.dp)
                .clip(RoundedCornerShape(16.dp))
                .background(Color(0xFFFDE8EC)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                kindIcon(item.kind),
                contentDescription = null,
                tint = AppColors.waRed,
                modifier = Modifier.size(22.dp),
            )
        }

        Spacer(Modifier.width(12.dp))

        // Text details
        Column(modifier = Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    kindTitle,
                    fontSize = 15.sp,
                    fontWeight = FontWeight.Bold,
                    color = Color(0xFF0F172A),
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                if (item.isDefault) {
                    Spacer(Modifier.width(8.dp))
                    Box(
                        modifier = Modifier
                            .clip(RoundedCornerShape(50))
                            .background(Color(0xFFFDE8EC))
                            .padding(horizontal = 8.dp, vertical = 2.dp),
                    ) {
                        Text(
                            stringResource(R.string.addr_default),
                            fontSize = 11.sp,
                            fontWeight = FontWeight.Bold,
                            color = AppColors.waRed,
                        )
                    }
                }
            }
            Spacer(Modifier.height(3.dp))
            Text(
                if (summary.isNotBlank()) summary else stringResource(R.string.addr_details_hint),
                fontSize = 12.sp,
                color = Color(0xFF94A3B8),
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }

        Spacer(Modifier.width(8.dp))

        // Edit button (Pink rounded box)
        Box(
            modifier = Modifier
                .size(36.dp)
                .clip(RoundedCornerShape(12.dp))
                .background(Color(0xFFFDE8EC))
                .clickable(onClick = onEdit),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.Rounded.Edit,
                contentDescription = stringResource(R.string.addr_edit),
                tint = AppColors.waRed,
                modifier = Modifier.size(18.dp),
            )
        }

        Spacer(Modifier.width(8.dp))

        // Delete button (Outlined rounded box)
        Box(
            modifier = Modifier
                .size(36.dp)
                .clip(RoundedCornerShape(12.dp))
                .background(Color.White)
                .border(1.dp, Color(0xFFFECDD3), RoundedCornerShape(12.dp))
                .clickable(onClick = onDelete),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.Rounded.DeleteOutline,
                contentDescription = stringResource(R.string.addr_delete),
                tint = AppColors.waRed,
                modifier = Modifier.size(18.dp),
            )
        }
    }
}

/**
 * Address Editor matching Screenshots 1 & 2
 */
@Composable
private fun AddressEditor(
    initial: SavedAddress?,
    saving: Boolean,
    onBack: () -> Unit,
    onSave: (SavedAddress) -> Unit,
) {
    var kind by remember { mutableStateOf(initial?.kind ?: "home") }
    var label by remember { mutableStateOf(initial?.label.orEmpty()) }
    var details by remember { mutableStateOf(initial?.line.orEmpty()) }
    var building by remember { mutableStateOf(initial?.building.orEmpty()) }
    var floor by remember { mutableStateOf(initial?.floor.orEmpty()) }
    var landmark by remember { mutableStateOf(initial?.landmark.orEmpty()) }
    var isDefault by remember { mutableStateOf(initial?.isDefault ?: false) }
    var isDetailsExpanded by remember { mutableStateOf(true) }

    val canSave = !saving

    // Focus-centering: tapping any field scrolls it to the vertical middle
    // of the visible area (not stuck at the top under the keyboard).
    val scrollState = rememberScrollState()
    val centerScope = rememberCoroutineScope()
    var viewportTopWin by remember { mutableFloatStateOf(0f) }
    var viewportH by remember { mutableIntStateOf(0) }
    fun centerOn(getCenterY: () -> Float) {
        centerScope.launch {
            delay(300) // let the keyboard + layout settle, then use fresh geometry
            val contentY = scrollState.value + (getCenterY() - viewportTopWin)
            val target = (contentY - viewportH / 2f).toInt().coerceIn(0, scrollState.maxValue)
            scrollState.animateScrollTo(target)
        }
    }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(
                Brush.verticalGradient(
                    colors = listOf(
                        Color(0xFFFFE0E5),
                        Color(0xFFFFF2F4),
                        Color(0xFFF9FAFB),
                        Color(0xFFF9FAFB),
                    ),
                    startY = 0f,
                    endY = 500f,
                ),
            ),
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(horizontal = 20.dp)
                .verticalScroll(scrollState)
                .imePadding()
                .onGloballyPositioned {
                    viewportTopWin = it.boundsInWindow().top
                    viewportH = it.size.height
                },
        ) {
            Spacer(Modifier.height(16.dp))

            // Header
            AddressesTopHeader(
                title = stringResource(if (initial == null) R.string.addr_add_title else R.string.addr_edit_title),
                onBack = onBack,
            )

            Spacer(Modifier.height(16.dp))

            // Kind Chips Row (Home / Work / Other)
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                EditorKindChip(
                    selected = kind == "home",
                    label = stringResource(R.string.addr_home),
                    icon = Icons.Rounded.Home,
                    onClick = { kind = "home" },
                    modifier = Modifier.weight(1f),
                )
                EditorKindChip(
                    selected = kind == "work",
                    label = stringResource(R.string.addr_work),
                    icon = Icons.Rounded.Work,
                    onClick = { kind = "work" },
                    modifier = Modifier.weight(1f),
                )
                EditorKindChip(
                    selected = kind == "other",
                    label = stringResource(R.string.addr_other),
                    icon = Icons.Rounded.LocationOn,
                    onClick = { kind = "other" },
                    modifier = Modifier.weight(1f),
                )
            }

            Spacer(Modifier.height(16.dp))

            // Map Preview Card matching screenshot exactly
            MapPreviewCard()

            Spacer(Modifier.height(16.dp))

            // Accordion: Address details (Optional)
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .shadow(6.dp, RoundedCornerShape(22.dp), spotColor = Color(0x10000000))
                    .clip(RoundedCornerShape(22.dp))
                    .background(Color.White)
                    .padding(16.dp),
            ) {
                Column(modifier = Modifier.fillMaxWidth()) {
                    // Header Row of accordion
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .clickable { isDetailsExpanded = !isDetailsExpanded },
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(
                            Icons.Rounded.Article,
                            contentDescription = null,
                            tint = AppColors.waRed,
                            modifier = Modifier.size(22.dp),
                        )
                        Spacer(Modifier.width(10.dp))
                        Text(
                            stringResource(R.string.addr_details_section),
                            fontSize = 15.sp,
                            fontWeight = FontWeight.Bold,
                            color = Color(0xFF0F172A),
                        )
                        Spacer(Modifier.width(6.dp))
                        Text(
                            stringResource(R.string.addr_optional),
                            fontSize = 12.sp,
                            color = Color(0xFF94A3B8),
                            modifier = Modifier.weight(1f),
                        )
                        Icon(
                            if (isDetailsExpanded) Icons.Rounded.KeyboardArrowUp else Icons.Rounded.KeyboardArrowDown,
                            contentDescription = null,
                            tint = Color(0xFFFCA5A5),
                            modifier = Modifier.size(22.dp),
                        )
                    }

                    AnimatedVisibility(
                        visible = isDetailsExpanded,
                        enter = expandVertically() + fadeIn(),
                        exit = shrinkVertically() + fadeOut(),
                    ) {
                        Column(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(top = 16.dp),
                        ) {
                            if (kind == "other") {
                                EditorFieldSection(
                                    title = stringResource(R.string.addr_name_label),
                                    value = label,
                                    onValueChange = { label = it },
                                    placeholder = stringResource(R.string.addr_name_hint),
                                    icon = Icons.Rounded.LocationOn,
                                    onCenterField = ::centerOn,
                                )
                                Spacer(Modifier.height(14.dp))
                            }

                            // Building number
                            EditorFieldSection(
                                title = stringResource(R.string.addr_building),
                                value = building,
                                onValueChange = { building = it },
                                    placeholder = stringResource(R.string.addr_building),
                                    icon = Icons.Rounded.Domain,
                                    onCenterField = ::centerOn,
                                )

                            Spacer(Modifier.height(14.dp))

                            // Floor
                            EditorFieldSection(
                                title = stringResource(R.string.addr_floor),
                                value = floor,
                                onValueChange = { floor = it },
                                    placeholder = stringResource(R.string.addr_floor),
                                    icon = Icons.Rounded.TrendingUp,
                                    onCenterField = ::centerOn,
                                )

                            Spacer(Modifier.height(14.dp))

                            // Address details
                            EditorFieldSection(
                                title = stringResource(R.string.addr_details_section),
                                value = details,
                                onValueChange = { details = it },
                                    placeholder = stringResource(R.string.addr_details_hint),
                                    icon = Icons.Rounded.LocationOn,
                                    onCenterField = ::centerOn,
                                )

                            Spacer(Modifier.height(14.dp))

                            // Landmark
                            EditorFieldSection(
                                title = stringResource(R.string.addr_landmark),
                                value = landmark,
                                onValueChange = { landmark = it },
                                    placeholder = stringResource(R.string.addr_landmark_hint),
                                    icon = Icons.Rounded.Flag,
                                    onCenterField = ::centerOn,
                                )
                        }
                    }
                }
            }

            Spacer(Modifier.height(16.dp))

            // Set as default Card
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .shadow(6.dp, RoundedCornerShape(22.dp), spotColor = Color(0x10000000))
                    .clip(RoundedCornerShape(22.dp))
                    .background(Color.White)
                    .padding(horizontal = 16.dp, vertical = 14.dp),
            ) {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Icon(
                        Icons.Rounded.LocalOffer,
                        contentDescription = null,
                        tint = AppColors.waRed,
                        modifier = Modifier.size(22.dp),
                    )
                    Spacer(Modifier.width(10.dp))
                    Text(
                        stringResource(R.string.addr_set_default),
                        fontSize = 15.sp,
                        fontWeight = FontWeight.Bold,
                        color = Color(0xFF0F172A),
                        modifier = Modifier.weight(1f),
                    )
                    Switch(
                        checked = isDefault,
                        onCheckedChange = { isDefault = it },
                        colors = SwitchDefaults.colors(
                            checkedThumbColor = Color.White,
                            checkedTrackColor = AppColors.waRed,
                            uncheckedThumbColor = Color.White,
                            uncheckedTrackColor = Color(0xFFE2E8F0),
                            uncheckedBorderColor = Color.Transparent,
                        ),
                    )
                }
            }

            Spacer(Modifier.height(22.dp))

            // Save Button
            Button(
                onClick = {
                    onSave(
                        SavedAddress(
                            id = initial?.id ?: 0,
                            kind = kind,
                            label = label.trim(),
                            line = details.trim(),
                            building = building.trim(),
                            floor = floor.trim(),
                            landmark = landmark.trim(),
                            isDefault = isDefault,
                        ),
                    )
                },
                enabled = canSave,
                colors = ButtonDefaults.buttonColors(
                    containerColor = AppColors.waRed,
                    disabledContainerColor = AppColors.waRed.copy(alpha = 0.5f),
                    contentColor = Color.White,
                ),
                shape = RoundedCornerShape(18.dp),
                modifier = Modifier
                    .fillMaxWidth()
                    .height(54.dp)
                    .shadow(12.dp, RoundedCornerShape(18.dp), spotColor = AppColors.waRed.copy(alpha = 0.38f)),
            ) {
                if (saving) {
                    CircularProgressIndicator(modifier = Modifier.size(22.dp), strokeWidth = 2.dp, color = Color.White)
                } else {
                    Text(
                        stringResource(if (initial == null) R.string.common_save else R.string.addr_update),
                        fontSize = 16.sp,
                        fontWeight = FontWeight.ExtraBold,
                    )
                }
            }

            Spacer(Modifier.height(30.dp))
        }
    }
}

/**
 * Kind chip with fully rounded pill shape (Home, Work, Other)
 */
@Composable
private fun EditorKindChip(
    selected: Boolean,
    label: String,
    icon: ImageVector,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val bgColor by animateColorAsState(
        targetValue = if (selected) AppColors.waRed else Color.White,
        label = "chip_bg",
    )
    val contentColor by animateColorAsState(
        targetValue = if (selected) Color.White else AppColors.waRed,
        label = "chip_content",
    )

    Row(
        modifier = modifier
            .shadow(if (selected) 6.dp else 3.dp, RoundedCornerShape(50), spotColor = if (selected) AppColors.waRed.copy(alpha = 0.3f) else Color(0x0E000000))
            .clip(RoundedCornerShape(50))
            .background(bgColor)
            .clickable(onClick = onClick)
            .padding(vertical = 12.dp),
        horizontalArrangement = Arrangement.Center,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(
            icon,
            contentDescription = null,
            tint = contentColor,
            modifier = Modifier.size(18.dp),
        )
        Spacer(Modifier.width(6.dp))
        Text(
            label,
            fontSize = 14.sp,
            fontWeight = FontWeight.Bold,
            color = contentColor,
        )
    }
}

/**
 * Styled text field section for Address Details with Pill shape and Icon
 */
@Composable
private fun EditorFieldSection(
    title: String,
    value: String,
    onValueChange: (String) -> Unit,
    placeholder: String,
    icon: ImageVector,
    onCenterField: (() -> Float) -> Unit = {},
) {
    var winCenterY by remember { mutableFloatStateOf(0f) }
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .onGloballyPositioned { winCenterY = it.boundsInWindow().center.y }
            .onFocusChanged { if (it.isFocused) onCenterField { winCenterY } },
    ) {
        Text(
            title,
            fontSize = 14.sp,
            fontWeight = FontWeight.Bold,
            color = Color(0xFF0F172A),
        )
        Spacer(Modifier.height(6.dp))
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .shadow(2.dp, RoundedCornerShape(50), spotColor = Color(0x08000000))
                .clip(RoundedCornerShape(50))
                .background(Color(0xFFFBFBFD))
                .border(1.dp, Color(0xFFF1F5F9), RoundedCornerShape(50))
                .padding(horizontal = 16.dp, vertical = 12.dp),
        ) {
            Row(
                verticalAlignment = Alignment.CenterVertically,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Icon(
                    icon,
                    contentDescription = null,
                    tint = AppColors.waRed,
                    modifier = Modifier.size(20.dp),
                )
                Spacer(Modifier.width(12.dp))
                Box(modifier = Modifier.weight(1f)) {
                    if (value.isEmpty()) {
                        Text(
                            placeholder,
                            color = Color(0xFF94A3B8),
                            fontSize = 14.sp,
                        )
                    }
                    BasicTextField(
                        value = value,
                        onValueChange = onValueChange,
                        singleLine = true,
                        textStyle = TextStyle(
                            fontSize = 14.sp,
                            color = Color(0xFF0F172A),
                            fontWeight = FontWeight.Medium,
                        ),
                        cursorBrush = SolidColor(AppColors.waRed),
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
            }
        }
    }
}

/**
 * Interactive map card visualization matching screenshot 2
 */
@Composable
private fun MapPreviewCard() {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .height(200.dp)
            .shadow(6.dp, RoundedCornerShape(22.dp), spotColor = Color(0x10000000))
            .clip(RoundedCornerShape(22.dp))
            .background(Color(0xFFE8ECEF)),
    ) {
        // Map illustration canvas
        Canvas(modifier = Modifier.fillMaxSize()) {
            val w = size.width
            val h = size.height

            // Background terrain color
            drawRect(Color(0xFFF2EFE9))

            // Main roads / grid lines
            drawLine(Color(0xFFFFC085), Offset(0f, h * 0.35f), Offset(w, h * 0.45f), strokeWidth = 14f)
            drawLine(Color(0xFFFFC085), Offset(0f, h * 0.7f), Offset(w, h * 0.6f), strokeWidth = 12f)
            drawLine(Color(0xFFFF9E79), Offset(w * 0.4f, 0f), Offset(w * 0.6f, h), strokeWidth = 16f)
            drawLine(Color(0xFFFFFFFF), Offset(w * 0.15f, 0f), Offset(w * 0.25f, h), strokeWidth = 8f)
            drawLine(Color(0xFFFFFFFF), Offset(w * 0.8f, 0f), Offset(w * 0.75f, h), strokeWidth = 8f)
            drawLine(Color(0xFFFFFFFF), Offset(0f, h * 0.2f), Offset(w, h * 0.25f), strokeWidth = 8f)
            drawLine(Color(0xFFFFFFFF), Offset(0f, h * 0.85f), Offset(w, h * 0.9f), strokeWidth = 8f)

            // Secondary diagonal streets
            drawLine(Color(0xFFE5E7EB), Offset(w * 0.2f, 0f), Offset(0f, h * 0.5f), strokeWidth = 5f)
            drawLine(Color(0xFFE5E7EB), Offset(w * 0.9f, 0f), Offset(w * 0.4f, h), strokeWidth = 5f)
            drawLine(Color(0xFFE5E7EB), Offset(w, h * 0.3f), Offset(w * 0.5f, h), strokeWidth = 5f)
        }

        // Top Left Map Chip
        Box(
            modifier = Modifier
                .padding(top = 12.dp, start = 12.dp)
                .shadow(4.dp, RoundedCornerShape(50), spotColor = Color(0x18000000))
                .clip(RoundedCornerShape(50))
                .background(Color.White)
                .padding(horizontal = 12.dp, vertical = 6.dp)
                .align(Alignment.TopStart),
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(
                    Icons.Rounded.LocationOn,
                    contentDescription = null,
                    tint = AppColors.waRed,
                    modifier = Modifier.size(16.dp),
                )
                Spacer(Modifier.width(4.dp))
                Text(
                    stringResource(R.string.addr_map),
                    fontSize = 12.sp,
                    fontWeight = FontWeight.Bold,
                    color = Color(0xFF0F172A),
                )
            }
        }

        // Top Right Zoom Controls (+ / -)
        Column(
            modifier = Modifier
                .padding(top = 12.dp, end = 12.dp)
                .shadow(4.dp, RoundedCornerShape(8.dp), spotColor = Color(0x18000000))
                .clip(RoundedCornerShape(8.dp))
                .background(Color.White)
                .align(Alignment.TopEnd),
        ) {
            Box(
                modifier = Modifier
                    .size(32.dp)
                    .clickable { },
                contentAlignment = Alignment.Center,
            ) {
                Text("+", fontSize = 18.sp, fontWeight = FontWeight.Bold, color = Color(0xFF334155))
            }
            Box(
                modifier = Modifier
                    .width(32.dp)
                    .height(1.dp)
                    .background(Color(0xFFE2E8F0)),
            )
            Box(
                modifier = Modifier
                    .size(32.dp)
                    .clickable { },
                contentAlignment = Alignment.Center,
            ) {
                Text("-", fontSize = 18.sp, fontWeight = FontWeight.Bold, color = Color(0xFF334155))
            }
        }

        // Center Location Pin Marker
        Box(
            modifier = Modifier.align(Alignment.Center),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.Rounded.LocationOn,
                contentDescription = null,
                tint = Color(0xFF84CC16),
                modifier = Modifier
                    .size(46.dp)
                    .shadow(8.dp, CircleShape, spotColor = Color(0x40000000)),
            )
        }

        // Bottom OpenStreetMap attribution bar matching screenshot
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .background(Color.White.copy(alpha = 0.85f))
                .padding(horizontal = 8.dp, vertical = 4.dp)
                .align(Alignment.BottomCenter),
        ) {
            Text(
                "Report a problem | © OpenStreetMap contributors ♥ Make a Donation. Website and API terms",
                fontSize = 8.5.sp,
                color = Color(0xFF475569),
                maxLines = 2,
                textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth(),
            )
        }
    }
}

/**
 * Beautifully animated, centered empty state when no addresses exist or no search results match.
 */
@Composable
private fun EmptyAddressView(
    isSearching: Boolean,
    onAddClick: () -> Unit,
    onClearSearch: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val infiniteTransition = rememberInfiniteTransition(label = "empty_anim")

    val dy by infiniteTransition.animateFloat(
        initialValue = 0f,
        targetValue = -9f,
        animationSpec = infiniteRepeatable(
            animation = tween(2000, easing = FastOutSlowInEasing),
            repeatMode = RepeatMode.Reverse,
        ),
        label = "empty_dy",
    )

    val pulseScale by infiniteTransition.animateFloat(
        initialValue = 0.94f,
        targetValue = 1.06f,
        animationSpec = infiniteRepeatable(
            animation = tween(2000, easing = FastOutSlowInEasing),
            repeatMode = RepeatMode.Reverse,
        ),
        label = "pulse_scale",
    )

    val pulseAlpha by infiniteTransition.animateFloat(
        initialValue = 0.35f,
        targetValue = 0.8f,
        animationSpec = infiniteRepeatable(
            animation = tween(2000, easing = FastOutSlowInEasing),
            repeatMode = RepeatMode.Reverse,
        ),
        label = "pulse_alpha",
    )

    Column(
        modifier = modifier
            .fillMaxWidth()
            .padding(horizontal = 24.dp, vertical = 24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Box(
            modifier = Modifier
                .size(130.dp)
                .graphicsLayer { translationY = dy.dp.toPx() },
            contentAlignment = Alignment.Center,
        ) {
            // Outermost soft glowing ring
            Box(
                modifier = Modifier
                    .size(124.dp)
                    .graphicsLayer {
                        scaleX = pulseScale
                        scaleY = pulseScale
                        alpha = pulseAlpha
                    }
                    .clip(CircleShape)
                    .background(
                        Brush.radialGradient(
                            colors = listOf(
                                AppColors.waRed.copy(alpha = 0.20f),
                                AppColors.waRed.copy(alpha = 0.04f),
                                Color.Transparent,
                            ),
                        ),
                    ),
            )

            // Middle layered halo
            Box(
                modifier = Modifier
                    .size(88.dp)
                    .clip(CircleShape)
                    .background(AppColors.waRed.copy(alpha = 0.09f))
                    .border(1.5.dp, AppColors.waRed.copy(alpha = 0.18f), CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                // Inner icon container with rich gradient
                Box(
                    modifier = Modifier
                        .size(58.dp)
                        .shadow(10.dp, CircleShape, spotColor = AppColors.waRed.copy(alpha = 0.38f))
                        .clip(CircleShape)
                        .background(
                            Brush.linearGradient(
                                colors = listOf(
                                    AppColors.waRed,
                                    Color(0xFFBA0912),
                                ),
                            ),
                        ),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(
                        if (isSearching) Icons.Rounded.SearchOff else Icons.Rounded.LocationOff,
                        contentDescription = null,
                        tint = Color.White,
                        modifier = Modifier.size(28.dp),
                    )
                }
            }
        }

        Spacer(Modifier.height(18.dp))

        Text(
            text = stringResource(if (isSearching) R.string.addr_empty else R.string.addr_none),
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.Bold,
            fontSize = 17.sp,
            color = Color(0xFF0F172A),
            textAlign = TextAlign.Center,
        )

        Spacer(Modifier.height(6.dp))

        Text(
            text = stringResource(if (isSearching) R.string.addr_empty_sub else R.string.addr_none_sub),
            style = MaterialTheme.typography.bodyMedium,
            fontSize = 13.sp,
            lineHeight = 21.sp,
            color = Color(0xFF64748B),
            textAlign = TextAlign.Center,
            modifier = Modifier.padding(horizontal = 8.dp),
        )

        Spacer(Modifier.height(18.dp))

        if (isSearching) {
            TextButton(
                onClick = onClearSearch,
                shape = RoundedCornerShape(50),
                colors = ButtonDefaults.textButtonColors(
                    contentColor = AppColors.waRed,
                ),
            ) {
                Icon(Icons.Rounded.Close, contentDescription = null, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(6.dp))
                Text(
                    stringResource(R.string.addr_clear_search),
                    fontWeight = FontWeight.Bold,
                    fontSize = 13.5.sp,
                )
            }
        } else {
            Button(
                onClick = onAddClick,
                colors = ButtonDefaults.buttonColors(
                    containerColor = AppColors.waRed.copy(alpha = 0.10f),
                    contentColor = AppColors.waRed,
                ),
                shape = RoundedCornerShape(50),
                contentPadding = PaddingValues(horizontal = 20.dp, vertical = 10.dp),
            ) {
                Icon(Icons.Rounded.AddLocationAlt, contentDescription = null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text(
                    stringResource(R.string.addr_add),
                    fontWeight = FontWeight.Bold,
                    fontSize = 13.5.sp,
                )
            }
        }
    }
}

@Composable
private fun ShowMoreButton(loading: Boolean, onClick: () -> Unit) {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(50))
            .background(Color.White)
            .border(1.5.dp, AppColors.waRed.copy(alpha = 0.35f), RoundedCornerShape(50))
            .clickable(enabled = !loading, onClick = onClick)
            .padding(vertical = 12.dp),
        contentAlignment = Alignment.Center,
    ) {
        if (loading) {
            CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp, color = AppColors.waRed)
        } else {
            Text(
                stringResource(R.string.wallet_history_more),
                color = AppColors.waRed,
                fontSize = 13.sp,
                fontWeight = FontWeight.Bold,
            )
        }
    }
}

@Composable
private fun kindIcon(kind: String): ImageVector = when (kind) {
    "home" -> Icons.Rounded.Home
    "work" -> Icons.Rounded.Work
    else -> Icons.Rounded.LocationOn
}
