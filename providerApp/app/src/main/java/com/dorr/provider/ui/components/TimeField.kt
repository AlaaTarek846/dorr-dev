package com.dorr.provider.ui.components

import androidx.compose.foundation.clickable
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Schedule
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TimePicker
import androidx.compose.material3.rememberTimePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import com.dorr.provider.R
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsMut
import java.time.LocalDate
import java.time.LocalTime
import java.time.ZoneId

/**
 * A time as a field that opens the time picker — the same pill and the same dialog buttons as [DateField]. [value] and
 * [onChange] use `HH:mm` (24-hour digits whatever the app language); an empty string means no time. The X clears it.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TimeField(
    value: String,
    onChange: (String) -> Unit,
    label: String,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
) {
    var open by remember { mutableStateOf(false) }
    DorrTextField(
        value = value,
        onValueChange = {},
        label = label,
        placeholder = "HH:MM",
        icon = Icons.Rounded.Schedule,
        enabled = enabled,
        onClick = { open = true },
        trailing = if (value.isNotEmpty() && enabled) {
            { Icon(Icons.Rounded.Close, contentDescription = null, tint = settingsMut(), modifier = Modifier.clickable { onChange("") }) }
        } else null,
        modifier = modifier,
    )
    if (open) {
        val start = runCatching { LocalTime.parse(value) }.getOrNull() ?: LocalTime.now()
        val state = rememberTimePickerState(initialHour = start.hour, initialMinute = start.minute, is24Hour = true)
        AlertDialog(
            onDismissRequest = { open = false },
            confirmButton = {
                TextButton(onClick = {
                    onChange(String.format(java.util.Locale.US, "%02d:%02d", state.hour, state.minute))
                    open = false
                }) { Text(stringResource(R.string.common_ok), color = settingsAccent()) }
            },
            dismissButton = { TextButton(onClick = { open = false }) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
            text = { TimePicker(state = state) },
        )
    }
}

/** `2026-11-20` + `14:30` → `2026-11-20T14:30+02:00` in the phone's zone (what the server's `date` rule takes); null if either is missing. */
fun isoAt(date: String, time: String): String? = runCatching {
    LocalDate.parse(date).atTime(LocalTime.parse(time)).atZone(ZoneId.systemDefault()).toOffsetDateTime().withNano(0).toString()
}.getOrNull()
