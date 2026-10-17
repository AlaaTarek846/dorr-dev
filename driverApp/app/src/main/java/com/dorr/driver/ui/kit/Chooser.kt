package com.dorr.driver.ui.kit

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.driver.R
import com.dorr.driver.ui.theme.Pal

/** One choice of a [ChooserSheet]: what is sent, what is shown, and an optional flag or colour swatch. */
data class Choice(val id: String, val label: String, val sub: String? = null, val flag: String? = null, val colorHex: String? = null)

/**
 * A pill field that opens a list in a sheet (make, model, colour, nationality, city…): the same look as a text field,
 * with the chosen value and a chevron. Never a cramped drop-down.
 */
@Composable
fun SelectField(
    label: String?,
    icon: ImageVector,
    choices: List<Choice>,
    selectedId: String?,
    onPick: (Choice) -> Unit,
    modifier: Modifier = Modifier,
    placeholder: String? = null,
    searchable: Boolean = choices.size > 8,
    enabled: Boolean = true,
    error: Boolean = false,
) {
    var open by remember { mutableStateOf(false) }
    val chosen = choices.firstOrNull { it.id == selectedId }
    DorrTextField(
        value = chosen?.label.orEmpty(), onValueChange = {}, label = label, placeholder = placeholder ?: stringResource(R.string.pick_choose),
        icon = icon, onClick = { if (choices.isNotEmpty()) open = true }, enabled = enabled, error = error, modifier = modifier,
        leading = when {
            chosen?.flag != null -> ({ FlagImage(chosen.flag) })
            chosen?.colorHex != null -> ({ Swatch(chosen.colorHex, 16) })
            else -> null
        },
        trailing = { Icon(Icons.Rounded.KeyboardArrowDown, null, tint = Pal.faint, modifier = Modifier.size(20.dp)) },
    )
    if (open) {
        ChooserSheet(label.orEmpty(), choices, selectedId, searchable, onDismiss = { open = false }) {
            onPick(it)
            open = false
        }
    }
}

/** The list behind a [SelectField]: an optional search, the choices with their flag / swatch, the chosen one ticked. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ChooserSheet(title: String, choices: List<Choice>, selectedId: String?, searchable: Boolean, onDismiss: () -> Unit, onPick: (Choice) -> Unit) {
    var query by remember { mutableStateOf("") }
    val shown = choices.filter { query.isBlank() || it.label.contains(query.trim(), ignoreCase = true) || it.sub?.contains(query.trim(), ignoreCase = true) == true }
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = Pal.card) {
        Column(Modifier.padding(horizontal = 16.dp).padding(bottom = 12.dp).navigationBarsPadding().imePadding()) {
            if (title.isNotBlank()) Text(title, color = Pal.ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(bottom = 10.dp))
            if (searchable) {
                DorrTextField(value = query, onValueChange = { query = it }, placeholder = stringResource(R.string.pick_search), icon = Icons.Rounded.Search, modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp))
            }
            if (shown.isEmpty()) {
                Text(stringResource(R.string.pick_nothing), color = Pal.mut, fontSize = 14.sp, modifier = Modifier.padding(vertical = 24.dp).align(Alignment.CenterHorizontally))
            }
            LazyColumn(Modifier.fillMaxWidth().heightIn(max = 460.dp)) {
                itemsIndexed(shown, key = { _, c -> c.id }) { index, c ->
                    val on = c.id == selectedId
                    Row(
                        Modifier.fillMaxWidth().staggerIn(index).clip(RoundedCornerShape(14.dp))
                            .background(if (on) Pal.well else Color.Transparent)
                            .bounceClick { onPick(c) }
                            .padding(horizontal = 12.dp, vertical = 13.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(12.dp),
                    ) {
                        when {
                            c.flag != null -> FlagImage(c.flag)
                            c.colorHex != null -> Swatch(c.colorHex, 22)
                        }
                        Column(Modifier.weight(1f)) {
                            Text(c.label, color = if (on) Pal.accent else Pal.ink, fontSize = 15.sp, fontWeight = if (on) FontWeight.Bold else FontWeight.Medium)
                            if (c.sub != null) Text(c.sub, color = Pal.mut, fontSize = 12.sp)
                        }
                        if (on) Icon(Icons.Rounded.Check, null, tint = Pal.accent, modifier = Modifier.size(20.dp))
                    }
                }
            }
        }
    }
}

/** A round dot of a colour ("#1A1A1A"); a hairline so white and black both show. */
@Composable
fun Swatch(hex: String?, sizeDp: Int) {
    val color = hex?.trim()?.removePrefix("#")?.takeIf { it.length == 6 }?.toLongOrNull(16)?.let { Color(0xFF000000 or it) } ?: Pal.line
    Box(Modifier.size(sizeDp.dp).clip(CircleShape).background(color).border(1.dp, Pal.line, CircleShape))
}
