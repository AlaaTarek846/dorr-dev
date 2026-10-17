package com.dorr.driver.ui

import androidx.compose.runtime.Composable
import com.dorr.driver.ui.kit.DorrDatePickerDialog
import com.dorr.driver.ui.kit.prettyDate

/** The app's calendar (the same one as everywhere in the app); answers the picked day as `yyyy-MM-dd` (the server's `from` / `to`). */
@Composable
internal fun DatePickDialog(onPicked: (String) -> Unit, onDismiss: () -> Unit) {
    DorrDatePickerDialog(initial = null, max = java.time.LocalDate.now(), onDismiss = onDismiss, onPick = { onPicked(it.toString()); onDismiss() })
}

/** "Oct 15, 2026" in the app's language; the text as it is if it isn't a date. */
internal fun dateLabel(iso: String): String = prettyDate(iso)
