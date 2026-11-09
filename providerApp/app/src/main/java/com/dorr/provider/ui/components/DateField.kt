package com.dorr.provider.ui.components

import androidx.compose.foundation.clickable
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Event
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDefaults
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.SelectableDates
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.dorr.provider.R
import com.dorr.provider.ui.kit.settingsAccent
import com.dorr.provider.ui.kit.settingsMut
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneOffset

/**
 * A date as a field that opens the date picker. [value] and [onChange] use `YYYY-MM-DD` (what the server takes, in digits
 * whatever the app language); an empty string means no date. The X clears it. [minDate]: the first day the picker lets you
 * choose (the days before it are greyed out), e.g. tomorrow for an expiry the server wants `after:today`.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DateField(
    value: String,
    onChange: (String) -> Unit,
    label: String,
    modifier: androidx.compose.ui.Modifier = androidx.compose.ui.Modifier,
    enabled: Boolean = true,
    minDate: LocalDate? = null,
    error: Boolean = false,
) {
    var open by remember { mutableStateOf(false) }
    DorrTextField(
        value = value,
        onValueChange = {},
        label = label,
        placeholder = stringResource(R.string.profile_dob_hint),
        icon = Icons.Rounded.Event,
        enabled = enabled,
        error = error,
        onClick = { open = true },
        trailing = if (value.isNotEmpty() && enabled) {
            { Icon(Icons.Rounded.Close, contentDescription = null, tint = settingsMut(), modifier = androidx.compose.ui.Modifier.clickable { onChange("") }.then(androidx.compose.ui.Modifier)) }
        } else null,
        modifier = modifier,
    )
    if (open) {
        // The picker works in UTC midnights; the first allowed day as one.
        val floor = minDate?.atStartOfDay()?.toInstant(ZoneOffset.UTC)?.toEpochMilli()
        val minYear = minDate?.year ?: 0
        val initial = runCatching { LocalDate.parse(value).atStartOfDay().toInstant(ZoneOffset.UTC).toEpochMilli() }.getOrNull()
            ?.takeIf { floor == null || it >= floor }
        val state = rememberDatePickerState(
            initialSelectedDateMillis = initial,
            initialDisplayedMonthMillis = initial ?: floor,
            selectableDates = if (floor == null) DatePickerDefaults.AllDates else object : SelectableDates {
                override fun isSelectableDate(utcTimeMillis: Long): Boolean = utcTimeMillis >= floor
                override fun isSelectableYear(year: Int): Boolean = year >= minYear
            },
        )
        DatePickerDialog(
            onDismissRequest = { open = false },
            confirmButton = {
                TextButton(onClick = {
                    state.selectedDateMillis?.takeIf { floor == null || it >= floor }?.let { onChange(Instant.ofEpochMilli(it).atZone(ZoneOffset.UTC).toLocalDate().toString()) }
                    open = false
                }) { Text(stringResource(R.string.common_ok), color = settingsAccent()) }
            },
            dismissButton = { TextButton(onClick = { open = false }) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
        ) { DatePicker(state = state) }
    }
}
