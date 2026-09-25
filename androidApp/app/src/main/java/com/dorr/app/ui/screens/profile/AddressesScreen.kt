package com.dorr.app.ui.screens.profile

import android.content.Context
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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.Home
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.components.SettingsScaffold
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
private val addrGson = Gson()

private fun loadAddresses(context: Context): MutableList<SavedAddress> {
    val prefs = context.getSharedPreferences(ADDR_PREFS, Context.MODE_PRIVATE)
    val raw = prefs.getString(ADDR_KEY, null)
    if (raw != null) {
        val list: List<SavedAddress> = runCatching {
            addrGson.fromJson<List<SavedAddress>>(raw, object : TypeToken<List<SavedAddress>>() {}.type)
        }.getOrDefault(emptyList())
        return list.toMutableList()
    }
    // First run ships the same two demo addresses the preview shows.
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

@Composable
private fun kindIcon(kind: String): ImageVector = when (kind) {
    "home" -> Icons.Rounded.Home
    "work" -> Icons.Rounded.Work
    else -> Icons.Rounded.LocationOn
}

@Composable
fun AddressesScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    var addresses by remember { mutableStateOf(loadAddresses(context)) }
    var query by remember { mutableStateOf("") }
    var editing by remember { mutableStateOf<SavedAddress?>(null) }
    var adding by remember { mutableStateOf(false) }
    var deleting by remember { mutableStateOf<SavedAddress?>(null) }

    fun persist(next: List<SavedAddress>) {
        addresses = next.toMutableList()
        saveAddresses(context, next)
    }

    if (adding || editing != null) {
        AddressEditor(
            initial = editing,
            isFirst = addresses.isEmpty(),
            onBack = { adding = false; editing = null },
            onSave = { draft ->
                if (editing != null) {
                    val id = editing!!.id
                    var next = addresses.map { if (it.id == id) draft.copy(id = id) else it }
                    if (draft.isDefault) next = next.map { it.copy(isDefault = it.id == id) }
                    persist(next)
                } else {
                    val id = (addresses.maxOfOrNull { it.id } ?: 0) + 1
                    var next = addresses + draft.copy(id = id)
                    if (draft.isDefault || addresses.isEmpty()) {
                        next = next.map { it.copy(isDefault = it.id == id) }
                    }
                    persist(next)
                }
                adding = false
                editing = null
            },
        )
        return
    }

    SettingsScaffold(title = stringResource(R.string.addr_title), onBack = onBack) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(horizontal = 20.dp),
        ) {
            Spacer(Modifier.height(12.dp))
            OutlinedTextField(
                value = query,
                onValueChange = { query = it },
                singleLine = true,
                placeholder = { Text(stringResource(R.string.addr_search), color = AppColors.textMuted) },
                leadingIcon = { Icon(Icons.Rounded.Search, contentDescription = null, tint = AppColors.textMuted) },
                shape = RoundedCornerShape(16.dp),
                colors = OutlinedTextFieldDefaults.colors(
                    unfocusedBorderColor = AppColors.border,
                    focusedBorderColor = AppColors.primary,
                ),
                modifier = Modifier.fillMaxWidth(),
            )
            Spacer(Modifier.height(12.dp))
            val q = query.trim()
            val visible = if (q.isEmpty()) {
                addresses
            } else {
                addresses.filter {
                    (it.line + " " + it.building + " " + it.floor + " " + it.landmark + " " + it.label)
                        .contains(q, ignoreCase = true)
                }
            }
            if (addresses.isEmpty()) {
                Text(
                    stringResource(R.string.addr_none),
                    style = MaterialTheme.typography.bodyMedium,
                    color = AppColors.textSecondary,
                    textAlign = TextAlign.Center,
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(vertical = 40.dp),
                )
            } else if (visible.isEmpty()) {
                Text(
                    stringResource(R.string.addr_empty),
                    style = MaterialTheme.typography.bodyMedium,
                    color = AppColors.textSecondary,
                    textAlign = TextAlign.Center,
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(vertical = 40.dp),
                )
            }
            LazyColumn(
                modifier = Modifier.weight(1f),
                contentPadding = PaddingValues(vertical = 4.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                items(visible, key = { it.id }) { item ->
                    AddressCard(
                        item = item,
                        onSelect = { persist(addresses.map { it.copy(isDefault = it.id == item.id) }) },
                        onEdit = { editing = item },
                        onDelete = { deleting = item },
                    )
                }
            }
            Spacer(Modifier.height(12.dp))
            Button(
                onClick = { adding = true },
                colors = ButtonDefaults.buttonColors(
                    containerColor = AppColors.waRed,
                    contentColor = Color.White,
                ),
                shape = RoundedCornerShape(50),
                modifier = Modifier
                    .fillMaxWidth()
                    .height(50.dp)
                    .shadow(8.dp, RoundedCornerShape(50), spotColor = AppColors.waRed.copy(alpha = 0.22f)),
            ) {
                Icon(Icons.Rounded.Add, contentDescription = null, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(8.dp))
                Text(stringResource(R.string.addr_add), fontWeight = FontWeight.SemiBold)
            }
            Spacer(Modifier.height(20.dp))
        }
    }

    deleting?.let { item ->
        AlertDialog(
            onDismissRequest = { deleting = null },
            title = { Text(stringResource(R.string.addr_delete_ask)) },
            confirmButton = {
                TextButton(onClick = {
                    persist(addresses.filter { it.id != item.id })
                    deleting = null
                }) { Text(stringResource(R.string.addr_yes)) }
            },
            dismissButton = {
                TextButton(onClick = { deleting = null }) { Text(stringResource(R.string.common_cancel)) }
            },
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
    ).filter { it.isNotBlank() }.joinToString(" · ")
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .shadow(6.dp, RoundedCornerShape(14.dp), spotColor = AppColors.waRed.copy(alpha = 0.07f))
            .clip(RoundedCornerShape(14.dp))
            .background(Color.White)
            .clickable(onClick = onSelect)
            .padding(10.dp, 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .size(34.dp)
                .clip(RoundedCornerShape(10.dp))
                .background(Color(0xFFFDE8EC)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(kindIcon(item.kind), contentDescription = null, tint = AppColors.waRed, modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(10.dp))
        Column(modifier = Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    kindTitle,
                    fontSize = 13.5.sp,
                    fontWeight = FontWeight.Bold,
                    color = AppColors.textPrimary,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f, fill = false),
                )
                if (item.isDefault) {
                    Spacer(Modifier.width(6.dp))
                    Box(
                        modifier = Modifier
                            .clip(RoundedCornerShape(7.dp))
                            .background(Color(0xFFFDE8EC))
                            .padding(horizontal = 7.dp, vertical = 2.dp),
                    ) {
                        Text(
                            stringResource(R.string.addr_default),
                            fontSize = 10.sp,
                            fontWeight = FontWeight.Bold,
                            color = AppColors.waRed,
                        )
                    }
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
        IconButton(onClick = onEdit) {
            Icon(Icons.Rounded.Edit, contentDescription = stringResource(R.string.addr_edit), tint = AppColors.textSecondary)
        }
        IconButton(onClick = onDelete) {
            Icon(Icons.Rounded.Delete, contentDescription = stringResource(R.string.addr_delete), tint = AppColors.danger)
        }
    }
}

@Composable
private fun AddressEditor(
    initial: SavedAddress?,
    isFirst: Boolean,
    onBack: () -> Unit,
    onSave: (SavedAddress) -> Unit,
) {
    var kind by remember { mutableStateOf(initial?.kind ?: "home") }
    var label by remember { mutableStateOf(initial?.label.orEmpty()) }
    var details by remember { mutableStateOf(initial?.line.orEmpty()) }
    var building by remember { mutableStateOf(initial?.building.orEmpty()) }
    var floor by remember { mutableStateOf(initial?.floor.orEmpty()) }
    var landmark by remember { mutableStateOf(initial?.landmark.orEmpty()) }
    var isDefault by remember { mutableStateOf(initial?.isDefault ?: isFirst) }
    val canSave = details.isNotBlank()

    SettingsScaffold(
        title = stringResource(if (initial == null) R.string.addr_add_title else R.string.addr_edit_title),
        onBack = onBack,
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(horizontal = 20.dp),
        ) {
            Spacer(Modifier.height(12.dp))
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                KindChip(
                    selected = kind == "home",
                    label = stringResource(R.string.addr_home),
                    icon = Icons.Rounded.Home,
                    onClick = { kind = "home" },
                    modifier = Modifier.weight(1f),
                )
                KindChip(
                    selected = kind == "work",
                    label = stringResource(R.string.addr_work),
                    icon = Icons.Rounded.Work,
                    onClick = { kind = "work" },
                    modifier = Modifier.weight(1f),
                )
                KindChip(
                    selected = kind == "other",
                    label = stringResource(R.string.addr_other),
                    icon = Icons.Rounded.LocationOn,
                    onClick = { kind = "other" },
                    modifier = Modifier.weight(1f),
                )
            }
            Spacer(Modifier.height(12.dp))
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .shadow(8.dp, RoundedCornerShape(18.dp), spotColor = AppColors.waRed.copy(alpha = 0.07f))
                    .clip(RoundedCornerShape(18.dp))
                    .background(Color.White)
                    .padding(16.dp),
            ) {
                if (kind == "other") {
                    OutlinedTextField(
                        value = label,
                        onValueChange = { label = it },
                        label = { Text(stringResource(R.string.addr_name_label)) },
                        placeholder = { Text(stringResource(R.string.addr_name_hint), color = AppColors.textMuted) },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    Spacer(Modifier.height(12.dp))
                }
                OutlinedTextField(
                    value = details,
                    onValueChange = { details = it },
                    label = { Text(stringResource(R.string.addr_details_label)) },
                    placeholder = { Text(stringResource(R.string.addr_details_hint), color = AppColors.textMuted) },
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(12.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    OutlinedTextField(
                        value = building,
                        onValueChange = { building = it },
                        label = { Text(stringResource(R.string.addr_building)) },
                        singleLine = true,
                        modifier = Modifier.weight(1f),
                    )
                    OutlinedTextField(
                        value = floor,
                        onValueChange = { floor = it },
                        label = { Text(stringResource(R.string.addr_floor)) },
                        singleLine = true,
                        modifier = Modifier.weight(1f),
                    )
                }
                Spacer(Modifier.height(12.dp))
                OutlinedTextField(
                    value = landmark,
                    onValueChange = { landmark = it },
                    label = { Text(stringResource(R.string.addr_landmark)) },
                    placeholder = { Text(stringResource(R.string.addr_landmark_hint), color = AppColors.textMuted) },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(12.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        stringResource(R.string.addr_set_default),
                        style = MaterialTheme.typography.bodyMedium,
                        modifier = Modifier.weight(1f),
                    )
                    Switch(checked = isDefault, onCheckedChange = { isDefault = it })
                }
            }
            Spacer(Modifier.height(20.dp))
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
                    disabledContainerColor = AppColors.waRed.copy(alpha = 0.4f),
                    contentColor = Color.White,
                    disabledContentColor = Color.White,
                ),
                shape = RoundedCornerShape(50),
                modifier = Modifier
                    .fillMaxWidth()
                    .height(50.dp)
                    .shadow(8.dp, RoundedCornerShape(50), spotColor = AppColors.waRed.copy(alpha = 0.22f)),
            ) {
                Text(
                    stringResource(if (initial == null) R.string.common_save else R.string.addr_update),
                    fontWeight = FontWeight.SemiBold,
                )
            }
            Spacer(Modifier.height(20.dp))
        }
    }
}

@Composable
private fun KindChip(
    selected: Boolean,
    label: String,
    icon: ImageVector,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier
            .clip(RoundedCornerShape(14.dp))
            .background(if (selected) AppColors.waRed else Color.White)
            .clickable(onClick = onClick)
            .padding(vertical = 10.dp),
        horizontalArrangement = Arrangement.Center,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(
            icon,
            contentDescription = null,
            tint = if (selected) Color.White else AppColors.waRed,
            modifier = Modifier.size(18.dp),
        )
        Spacer(Modifier.width(6.dp))
        Text(
            label,
            fontSize = 13.sp,
            fontWeight = FontWeight.Bold,
            color = if (selected) Color.White else AppColors.textPrimary,
        )
    }
}
