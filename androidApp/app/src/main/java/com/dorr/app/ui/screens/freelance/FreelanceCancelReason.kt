package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.profile.settingsMut

/**
 * FL-607: why work is being called off. The client picks one of a short fixed list (the server turns it into who it points at, for
 * staff only - nothing is charged because of it) and may add a few words; "other" needs the words.
 */
internal val CANCEL_REASONS = listOf(
    "scope_changed" to R.string.fl_cr_scope_changed,
    "client_unresponsive" to R.string.fl_cr_client_unresponsive,
    "not_delivered" to R.string.fl_cr_not_delivered,
    "quality" to R.string.fl_cr_quality,
    "mutual" to R.string.fl_cr_mutual,
    "force_majeure" to R.string.fl_cr_force_majeure,
    "other" to R.string.fl_cr_other,
)

/** The reason may be sent when one is picked and, for "other", a few words are written. */
internal fun cancelReasonReady(code: String?, note: String): Boolean = code != null && (code != "other" || note.trim().length >= 5)

@Composable
internal fun CancelReasonPicker(code: String?, note: String, onCode: (String) -> Unit, onNote: (String) -> Unit) {
    Column(Modifier.fillMaxWidth().heightIn(max = 420.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(stringResource(R.string.fl_cr_title), color = settingsMut(), fontSize = 12.sp)
        CANCEL_REASONS.forEach { (key, label) -> Chip(stringResource(label), code == key) { onCode(key) } }
        DorrTextField(note, { onNote(it.take(500)) }, placeholder = stringResource(R.string.fl_cr_note_hint), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
    }
}
