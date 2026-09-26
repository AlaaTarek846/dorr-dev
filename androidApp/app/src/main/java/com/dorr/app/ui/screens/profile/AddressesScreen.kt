package com.dorr.app.ui.screens.profile

import android.content.Context
import android.webkit.WebView
import android.widget.Toast
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.interaction.MutableInteractionSource
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
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Apartment
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Flag
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Stairs
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.rotate
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import com.dorr.app.R
import com.dorr.app.ui.theme.AppColors
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken

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

private const val ADDR_PREFS = "dorr_addresses"
private const val ADDR_KEY = "list"
private const val ADDR_SEEDED = "seeded"
private const val MAP_URL =
    "https://www.openstreetmap.org/export/embed.html?bbox=46.62%2C24.64%2C46.78%2C24.78&layer=mapnik&marker=24.7136%2C46.6753"
private val addrGson = Gson()
private val Pink = Color(0xFFFDE8EC)
private val PinkBorder = Color(0xFFF3C4CC)
private val Placeholder = Color(0xFFC5CAD3)
private val CardShape = RoundedCornerShape(14.dp)
private val CardShadow = Color(0x12E50914)

private fun loadAddresses(context: Context): MutableList<SavedAddress> {
    val prefs = context.getSharedPreferences(ADDR_PREFS, Context.MODE_PRIVATE)
    val raw = prefs.getString(ADDR_KEY, null)
    if (raw != null) {
        val list: List<SavedAddress> = runCatching {
            addrGson.fromJson<List<SavedAddress>>(raw, object : TypeToken<List<SavedAddress>>() {}.type)
        }.getOrDefault(emptyList())
        return list.toMutableList()
    }
    val seed = mutableListOf(
        SavedAddress(id = 1, kind = "home", line = "حي النرجس، شارع التحلية، الرياض", isDefault = true),
        SavedAddress(id = 2, kind = "work", line = "طريق الملك فهد، العليا، الرياض"),
    )
    prefs.edit()
        .putString(ADDR_KEY, addrGson.toJson(seed))
        .putBoolean(ADDR_SEEDED, true)
        .apply()
    return seed
}

private fun saveAddresses(context: Context, list: List<SavedAddress>) {
    context.getSharedPreferences(ADDR_PREFS, Context.MODE_PRIVATE)
        .edit()
        .putString(ADDR_KEY, addrGson.toJson(list))
        .apply()
}

private fun kindIcon(kind: String): ImageVector = when (kind) {
    "home" -> Icons.Rounded.Home
    "work" -> Icons.Rounded.Work
    else -> Icons.Rounded.LocationOn
}

@Composable
private fun addressTitle(item: SavedAddress): String = when (item.kind) {
    "home" -> stringResource(R.string.addr_home)
    "work" -> stringResource(R.string.addr_work)
    else -> item.label.ifBlank { stringResource(R.string.addr_other) }
}

@Composable
private fun addressSummary(item: SavedAddress): String {
    val buildingLabel = stringResource(R.string.addr_building)
    val floorLabel = stringResource(R.string.addr_floor)
    return listOf(
        item.line,
        item.building.takeIf { it.isNotBlank() }?.let { "$buildingLabel $it" }.orEmpty(),
        item.floor.takeIf { it.isNotBlank() }?.let { "$floorLabel $it" }.orEmpty(),
        item.landmark,
    ).filter { it.isNotBlank() }.joinToString(" · ")
}

@Composable
fun AddressesScreen(onBack: () -> Unit) {
    val context = androidx.compose.ui.platform.LocalContext.current
    var addresses by remember { mutableStateOf(loadAddresses(context)) }
    var query by remember { mutableStateOf("") }
    var editing by remember { mutableStateOf<SavedAddress?>(null) }
    var adding by remember { mutableStateOf(false) }
    var deleting by remember { mutableStateOf<SavedAddress?>(null) }

    fun persist(next: List<SavedAddress>) {
        addresses = next.toMutableList()
        saveAddresses(context, next)
    }

    Box(modifier = Modifier.fillMaxSize()) {
        AddressBackdrop(Modifier.fillMaxSize())
        Column(modifier = Modifier.fillMaxSize()) {
            if (adding || editing != null) {
                AccountHeader(
                    title = stringResource(if (editing == null) R.string.addr_add_title else R.string.addr_edit_title),
                    onBack = { adding = false; editing = null },
                )
                AddressEditor(
                    initial = editing,
                    isFirst = addresses.isEmpty(),
                    onSave = { draft ->
                        if (draft.line.length < 4) return@AddressEditor
                        if (editing != null) {
                            val id = editing!!.id
                            var next = addresses.map { if (it.id == id) draft.copy(id = id) else it }
                            if (draft.isDefault) next = next.map { it.copy(isDefault = it.id == id) }
                            persist(next)
                        } else {
                            val id = (addresses.maxOfOrNull { it.id } ?: 0) + 1
                            val makeDefault = draft.isDefault || addresses.isEmpty()
                            var next = addresses + draft.copy(id = id, isDefault = makeDefault)
                            if (makeDefault) next = next.map { it.copy(isDefault = it.id == id) }
                            persist(next)
                        }
                        adding = false
                        editing = null
                    },
                    modifier = Modifier.weight(1f),
                )
            } else {
                AccountHeader(title = stringResource(R.string.addr_title), onBack = onBack)
                AddressList(
                    addresses = addresses,
                    query = query,
                    onQueryChange = { query = it },
                    onSelect = { item -> persist(addresses.map { it.copy(isDefault = it.id == item.id) }) },
                    onEdit = { editing = it },
                    onDelete = { deleting = it },
                    onAdd = { adding = true },
                    modifier = Modifier.weight(1f),
                )
            }
        }

        deleting?.let { item ->
            DeleteConfirm(
                title = addressTitle(item),
                onYes = {
                    var next = addresses.filter { it.id != item.id }
                    if (item.isDefault && next.isNotEmpty()) {
                        next = next.mapIndexed { index, address -> address.copy(isDefault = index == 0) }
                    }
                    persist(next)
                    deleting = null
                },
                onNo = { deleting = null },
            )
        }
    }
}

@Composable
private fun AddressList(
    addresses: List<SavedAddress>,
    query: String,
    onQueryChange: (String) -> Unit,
    onSelect: (SavedAddress) -> Unit,
    onEdit: (SavedAddress) -> Unit,
    onDelete: (SavedAddress) -> Unit,
    onAdd: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val home = stringResource(R.string.addr_home)
    val work = stringResource(R.string.addr_work)
    val other = stringResource(R.string.addr_other)
    val buildingLabel = stringResource(R.string.addr_building)
    val floorLabel = stringResource(R.string.addr_floor)
    fun titleOf(item: SavedAddress) = when (item.kind) {
        "home" -> home
        "work" -> work
        else -> item.label.ifBlank { other }
    }
    fun summaryOf(item: SavedAddress) = listOf(
        item.line,
        item.building.takeIf { it.isNotBlank() }?.let { "$buildingLabel $it" }.orEmpty(),
        item.floor.takeIf { it.isNotBlank() }?.let { "$floorLabel $it" }.orEmpty(),
        item.landmark,
    ).filter { it.isNotBlank() }.joinToString(" · ")
    val q = query.trim()
    val visible = if (q.isEmpty()) {
        addresses
    } else {
        addresses.filter { item ->
            "${titleOf(item)} ${summaryOf(item)}".contains(q, ignoreCase = true)
        }
    }
    Column(
        modifier = modifier.padding(horizontal = 14.dp),
    ) {
        SearchField(query = query, onQueryChange = onQueryChange)
        Spacer(Modifier.height(2.dp))
        if (visible.isEmpty()) {
            Text(
                text = stringResource(if (addresses.isEmpty()) R.string.addr_none else R.string.addr_empty),
                color = AppColors.textMuted,
                fontSize = 13.sp,
                fontWeight = FontWeight.SemiBold,
                textAlign = TextAlign.Center,
                modifier = Modifier
                    .fillMaxWidth()
                    .shadow(6.dp, CardShape, ambientColor = CardShadow, spotColor = CardShadow)
                    .clip(CardShape)
                    .background(Color.White)
                    .padding(vertical = 28.dp, horizontal = 12.dp),
            )
            Spacer(Modifier.weight(1f))
        } else {
            LazyColumn(
                modifier = Modifier.weight(1f),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                items(visible, key = { it.id }) { item ->
                    AddressCard(
                        item = item,
                        onSelect = { onSelect(item) },
                        onEdit = { onEdit(item) },
                        onDelete = { onDelete(item) },
                    )
                }
            }
        }
        RedButton(
            text = stringResource(R.string.addr_add),
            onClick = onAdd,
            modifier = Modifier.padding(top = 16.dp, bottom = 16.dp),
        )
    }
}

@Composable
private fun AddressCard(
    item: SavedAddress,
    onSelect: () -> Unit,
    onEdit: () -> Unit,
    onDelete: () -> Unit,
) {
    val summary = addressSummary(item)
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .shadow(6.dp, CardShape, ambientColor = CardShadow, spotColor = CardShadow)
            .clip(CardShape)
            .background(Color.White)
            .clickable(onClick = onSelect)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconBox(kindIcon(item.kind))
        Spacer(Modifier.width(10.dp))
        Column(modifier = Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    addressTitle(item),
                    fontSize = 13.5.sp,
                    fontWeight = FontWeight.Bold,
                    color = AppColors.textPrimary,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                if (item.isDefault) {
                    Spacer(Modifier.width(6.dp))
                    Text(
                        stringResource(R.string.addr_default),
                        fontSize = 10.sp,
                        fontWeight = FontWeight.Bold,
                        color = AppColors.waRed,
                        modifier = Modifier
                            .clip(RoundedCornerShape(999.dp))
                            .background(Pink)
                            .padding(horizontal = 8.dp, vertical = 2.dp),
                    )
                }
            }
            if (summary.isNotBlank()) {
                Text(
                    summary,
                    fontSize = 11.sp,
                    color = AppColors.textMuted,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
            }
        }
        Spacer(Modifier.width(6.dp))
        ActionIcon(Icons.Rounded.Edit, stringResource(R.string.addr_edit), filled = true, onClick = onEdit)
        Spacer(Modifier.width(6.dp))
        ActionIcon(Icons.Rounded.Delete, stringResource(R.string.addr_delete), filled = false, onClick = onDelete)
    }
}

@Composable
private fun AddressEditor(
    initial: SavedAddress?,
    isFirst: Boolean,
    onSave: (SavedAddress) -> Unit,
    modifier: Modifier = Modifier,
) {
    var kind by remember { mutableStateOf(initial?.kind ?: "home") }
    var details by remember { mutableStateOf(initial?.line.orEmpty()) }
    var building by remember { mutableStateOf(initial?.building.orEmpty()) }
    var floor by remember { mutableStateOf(initial?.floor.orEmpty()) }
    var landmark by remember { mutableStateOf(initial?.landmark.orEmpty()) }
    var isDefault by remember { mutableStateOf(initial?.isDefault ?: isFirst) }
    var detailsOpen by remember {
        mutableStateOf(
            initial != null && listOf(initial.line, initial.building, initial.floor, initial.landmark).any { it.isNotBlank() },
        )
    }
    var detailsError by remember { mutableStateOf(false) }
    val context = androidx.compose.ui.platform.LocalContext.current
    val detailsErrorText = stringResource(R.string.addr_details_error)

    Column(
        modifier = modifier
            .verticalScroll(rememberScrollState())
            .padding(horizontal = 14.dp)
            .padding(top = 6.dp, bottom = 16.dp),
    ) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            KindChip("home", stringResource(R.string.addr_home), kind == "home", Modifier.weight(1f)) { kind = "home" }
            KindChip("work", stringResource(R.string.addr_work), kind == "work", Modifier.weight(1f)) { kind = "work" }
            KindChip("other", stringResource(R.string.addr_other), kind == "other", Modifier.weight(1f)) { kind = "other" }
        }
        Spacer(Modifier.height(12.dp))
        AddressMap()
        Spacer(Modifier.height(12.dp))
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .shadow(8.dp, RoundedCornerShape(18.dp), ambientColor = CardShadow, spotColor = CardShadow)
                .clip(RoundedCornerShape(18.dp))
                .background(Color.White),
        ) {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .clickable { detailsOpen = !detailsOpen }
                    .padding(horizontal = 16.dp, vertical = 14.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Description, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text(
                    stringResource(R.string.addr_details_section),
                    fontSize = 14.sp,
                    fontWeight = FontWeight.Bold,
                    color = AppColors.textPrimary,
                )
                Spacer(Modifier.width(6.dp))
                Text(
                    stringResource(R.string.addr_optional),
                    fontSize = 12.sp,
                    fontWeight = FontWeight.SemiBold,
                    color = AppColors.textMuted,
                )
                Spacer(Modifier.weight(1f))
                Icon(
                    Icons.Rounded.KeyboardArrowDown,
                    contentDescription = null,
                    tint = Color(0xFFEFA8B4),
                    modifier = Modifier
                        .size(16.dp)
                        .rotate(if (detailsOpen) 180f else 0f),
                )
            }
            if (detailsOpen) {
                Column(Modifier.padding(start = 16.dp, end = 16.dp, bottom = 6.dp)) {
                    AddressField(building, { building = it }, stringResource(R.string.addr_building), stringResource(R.string.addr_building), Icons.Rounded.Apartment)
                    AddressField(floor, { floor = it }, stringResource(R.string.addr_floor), stringResource(R.string.addr_floor), Icons.Rounded.Stairs)
                    AddressField(
                        details,
                        {
                            details = it
                            detailsError = false
                        },
                        stringResource(R.string.addr_details_label),
                        stringResource(R.string.addr_details_hint),
                        Icons.Rounded.LocationOn,
                    )
                    if (detailsError) {
                        Text(
                            detailsErrorText,
                            color = AppColors.waRed,
                            fontSize = 12.sp,
                            fontWeight = FontWeight.SemiBold,
                            modifier = Modifier.padding(bottom = 8.dp),
                        )
                    }
                    AddressField(landmark, { landmark = it }, stringResource(R.string.addr_landmark), stringResource(R.string.addr_landmark_hint), Icons.Rounded.Flag)
                }
            }
        }
        Spacer(Modifier.height(12.dp))
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .shadow(8.dp, RoundedCornerShape(18.dp), ambientColor = CardShadow, spotColor = CardShadow)
                .clip(RoundedCornerShape(18.dp))
                .background(Color.White)
                .clickable { isDefault = !isDefault }
                .padding(horizontal = 16.dp, vertical = 14.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.LocalOffer, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(8.dp))
            Text(
                stringResource(R.string.addr_set_default),
                fontSize = 14.sp,
                fontWeight = FontWeight.Bold,
                color = AppColors.textPrimary,
                modifier = Modifier.weight(1f),
            )
            RedToggle(isDefault)
        }
        RedButton(
            text = stringResource(if (initial == null) R.string.common_save else R.string.addr_update),
            onClick = {
                if (details.trim().length < 4) {
                    detailsOpen = true
                    detailsError = true
                    Toast.makeText(context, detailsErrorText, Toast.LENGTH_SHORT).show()
                    return@RedButton
                }
                onSave(
                    SavedAddress(
                        id = initial?.id ?: 0,
                        kind = kind,
                        label = initial?.label.orEmpty(),
                        line = details.trim(),
                        building = building.trim(),
                        floor = floor.trim(),
                        landmark = landmark.trim(),
                        isDefault = isDefault || isFirst,
                    ),
                )
            },
            modifier = Modifier.padding(top = 16.dp),
        )
    }
}

@Composable
private fun AddressMap() {
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .height(168.dp)
            .shadow(6.dp, RoundedCornerShape(16.dp), ambientColor = CardShadow, spotColor = CardShadow)
            .clip(RoundedCornerShape(16.dp))
            .background(PinkBorder),
    ) {
        AndroidView(
            factory = { context ->
                WebView(context).apply {
                    setBackgroundColor(android.graphics.Color.parseColor("#F3C4CC"))
                    settings.javaScriptEnabled = false
                    loadUrl(MAP_URL)
                }
            },
            modifier = Modifier.fillMaxSize(),
        )
        Row(
            modifier = Modifier
                .align(Alignment.TopStart)
                .padding(10.dp)
                .shadow(4.dp, RoundedCornerShape(999.dp))
                .clip(RoundedCornerShape(999.dp))
                .background(Color.White)
                .padding(horizontal = 10.dp, vertical = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.LocationOn, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(14.dp))
            Spacer(Modifier.width(4.dp))
            Text(stringResource(R.string.addr_map), color = AppColors.waRed, fontSize = 12.sp, fontWeight = FontWeight.Bold)
        }
    }
}

@Composable
private fun KindChip(kind: String, label: String, selected: Boolean, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Row(
        modifier = modifier
            .height(42.dp)
            .then(
                if (selected) {
                    Modifier.shadow(6.dp, RoundedCornerShape(999.dp), ambientColor = Color(0x38E50914), spotColor = Color(0x38E50914))
                } else {
                    Modifier
                },
            )
            .clip(RoundedCornerShape(999.dp))
            .background(if (selected) AppColors.waRed else Color.White)
            .border(1.5.dp, if (selected) AppColors.waRed else PinkBorder, RoundedCornerShape(999.dp))
            .clickable(onClick = onClick),
        horizontalArrangement = Arrangement.Center,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(kindIcon(kind), contentDescription = null, tint = if (selected) Color.White else AppColors.waRed, modifier = Modifier.size(16.dp))
        Spacer(Modifier.width(6.dp))
        Text(label, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = if (selected) Color.White else AppColors.waRed, maxLines = 1)
    }
}

@Composable
private fun AddressField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
    placeholder: String,
    icon: ImageVector,
) {
    Text(
        label,
        fontSize = 13.sp,
        fontWeight = FontWeight.Bold,
        color = AppColors.textPrimary,
        modifier = Modifier.padding(bottom = 8.dp),
    )
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(bottom = 10.dp)
            .height(44.dp)
            .shadow(4.dp, RoundedCornerShape(999.dp), ambientColor = Color(0x0D111928), spotColor = Color(0x0D111928))
            .clip(RoundedCornerShape(999.dp))
            .background(Color.White)
            .padding(horizontal = 14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(8.dp))
        BasicTextField(
            value = value,
            onValueChange = onValueChange,
            singleLine = true,
            textStyle = TextStyle(fontSize = 15.sp, color = AppColors.textPrimary),
            cursorBrush = SolidColor(AppColors.waRed),
            modifier = Modifier.weight(1f),
            decorationBox = { inner ->
                Box(contentAlignment = Alignment.CenterStart) {
                    if (value.isEmpty()) {
                        Text(placeholder, color = Placeholder, fontSize = 15.sp, fontWeight = FontWeight.Medium, maxLines = 1)
                    }
                    inner()
                }
            },
        )
    }
}

@Composable
private fun SearchField(query: String, onQueryChange: (String) -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(bottom = 10.dp)
            .height(46.dp)
            .shadow(6.dp, CardShape, ambientColor = CardShadow, spotColor = CardShadow)
            .clip(CardShape)
            .background(Color.White)
            .padding(horizontal = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.Search, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(8.dp))
        BasicTextField(
            value = query,
            onValueChange = onQueryChange,
            singleLine = true,
            textStyle = TextStyle(fontSize = 14.sp, fontWeight = FontWeight.SemiBold, color = AppColors.textPrimary),
            cursorBrush = SolidColor(AppColors.waRed),
            modifier = Modifier.weight(1f),
            decorationBox = { inner ->
                Box(contentAlignment = Alignment.CenterStart) {
                    if (query.isEmpty()) {
                        Text(stringResource(R.string.addr_search), color = AppColors.textMuted, fontSize = 14.sp, fontWeight = FontWeight.Medium)
                    }
                    inner()
                }
            },
        )
    }
}

@Composable
private fun AccountHeader(title: String, onBack: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 14.dp)
            .padding(top = 14.dp, bottom = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            title,
            color = AppColors.waRed,
            fontSize = 22.sp,
            fontWeight = FontWeight.ExtraBold,
            modifier = Modifier.weight(1f),
            textAlign = TextAlign.Start,
        )
        Box(
            modifier = Modifier
                .size(34.dp)
                .shadow(6.dp, CircleShape, ambientColor = Color(0x14E50914), spotColor = Color(0x14E50914))
                .clip(CircleShape)
                .background(Color.White)
                .clickable(onClick = onBack),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.AutoMirrored.Rounded.ArrowBack,
                contentDescription = stringResource(R.string.common_back),
                tint = AppColors.waRed,
                modifier = Modifier
                    .size(16.dp)
                    .graphicsLayer { scaleX = -1f },
            )
        }
    }
}

@Composable
private fun IconBox(icon: ImageVector) {
    Box(
        modifier = Modifier
            .size(34.dp)
            .clip(RoundedCornerShape(10.dp))
            .background(Pink),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(18.dp))
    }
}

@Composable
private fun ActionIcon(icon: ImageVector, label: String, filled: Boolean, onClick: () -> Unit) {
    Box(
        modifier = Modifier
            .size(32.dp)
            .clip(RoundedCornerShape(10.dp))
            .background(if (filled) Pink else Color.White)
            .border(if (filled) 0.dp else 1.dp, PinkBorder, RoundedCornerShape(10.dp))
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, contentDescription = label, tint = AppColors.waRed, modifier = Modifier.size(16.dp))
    }
}

@Composable
private fun RedButton(text: String, onClick: () -> Unit, modifier: Modifier = Modifier) {
    val shape = RoundedCornerShape(14.dp)
    Box(
        modifier = modifier
            .fillMaxWidth()
            .height(48.dp)
            .shadow(8.dp, shape, ambientColor = Color(0x38E50914), spotColor = Color(0x38E50914))
            .clip(shape)
            .background(AppColors.waRed)
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Text(text, color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun RedToggle(on: Boolean) {
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Box(
            modifier = Modifier
                .size(width = 42.dp, height = 24.dp)
                .clip(RoundedCornerShape(12.dp))
                .background(if (on) AppColors.waRed else AppColors.border),
        ) {
            Box(
                modifier = Modifier
                    .padding(2.dp)
                    .size(20.dp)
                    .offset(x = if (on) 18.dp else 0.dp)
                    .shadow(1.dp, CircleShape)
                    .clip(CircleShape)
                    .background(Color.White),
            )
        }
    }
}

@Composable
private fun DeleteConfirm(title: String, onYes: () -> Unit, onNo: () -> Unit) {
    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(Color.Black.copy(alpha = 0.4f))
            .clickable(onClick = onNo),
        contentAlignment = Alignment.Center,
    ) {
        Box(
            modifier = Modifier
                .padding(horizontal = 24.dp)
                .widthIn(max = 300.dp)
                .fillMaxWidth()
                .shadow(16.dp, RoundedCornerShape(20.dp), ambientColor = Color(0x24E50914), spotColor = Color(0x24E50914))
                .clip(RoundedCornerShape(20.dp))
                .clickable(
                    interactionSource = remember { MutableInteractionSource() },
                    indication = null,
                    onClick = {},
                ),
        ) {
            AddressBackdrop(Modifier.matchParentSize())
            Column(
                modifier = Modifier.padding(horizontal = 16.dp, vertical = 18.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
            IconBoxLarge()
            Text(
                stringResource(R.string.addr_delete_ask),
                color = AppColors.waRed,
                fontSize = 18.sp,
                fontWeight = FontWeight.ExtraBold,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(4.dp))
            Text(
                title,
                color = AppColors.textSecondary,
                fontSize = 13.sp,
                fontWeight = FontWeight.Bold,
                textAlign = TextAlign.Center,
            )
            Spacer(Modifier.height(16.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                RedButton(stringResource(R.string.addr_yes), onYes, Modifier.weight(1f))
                Box(
                    modifier = Modifier
                        .weight(1f)
                        .height(48.dp)
                        .clip(RoundedCornerShape(14.dp))
                        .background(Color.White)
                        .border(1.5.dp, PinkBorder, RoundedCornerShape(14.dp))
                        .clickable(onClick = onNo),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(stringResource(R.string.common_cancel), color = AppColors.waRed, fontSize = 15.sp, fontWeight = FontWeight.Bold)
                }
            }
            }
        }
    }
}

@Composable
private fun IconBoxLarge() {
    Box(
        modifier = Modifier
            .padding(bottom = 12.dp)
            .size(56.dp)
            .shadow(8.dp, RoundedCornerShape(18.dp), ambientColor = Color(0x1AE50914), spotColor = Color(0x1AE50914))
            .clip(RoundedCornerShape(18.dp))
            .background(Color.White),
        contentAlignment = Alignment.Center,
    ) {
        Icon(Icons.Rounded.Delete, contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(26.dp))
    }
}

@Composable
private fun AddressBackdrop(modifier: Modifier = Modifier) {
    Canvas(modifier) {
        drawRect(Color.White)
        fun glow(center: Offset, radius: Float, color: Color) {
            drawCircle(
                brush = Brush.radialGradient(
                    colors = listOf(color, color.copy(alpha = 0.55f), Color.Transparent),
                    center = center,
                    radius = radius,
                ),
                radius = radius,
                center = center,
            )
        }
        glow(Offset(size.width * -0.08f, size.height * -0.12f), size.width * 1.3f, Color(0xFFEFA8B4))
        glow(Offset(size.width * 0.50f, size.height * -0.18f), size.width * 1.1f, Color(0xFFF3C4CC))
        glow(Offset(size.width * 1.12f, size.height * -0.08f), size.width * 0.9f, Color(0xFFF0B8C2))
    }
}
