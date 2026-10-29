package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.R
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut

/**
 * FL-607: why I am stepping back from a part. One of a short fixed list (the server turns it into who it points at, for staff only -
 * nothing is charged because of it) and, if I want, a few words; "other" needs the words.
 */
internal val CANCEL_REASONS = listOf(
    "scope_changed" to R.string.cr_scope_changed,
    "client_unresponsive" to R.string.cr_client_unresponsive,
    "not_delivered" to R.string.cr_not_delivered,
    "quality" to R.string.cr_quality,
    "mutual" to R.string.cr_mutual,
    "force_majeure" to R.string.cr_force_majeure,
    "other" to R.string.cr_other,
)

/** The reason may be sent when one is picked and, for "other", a few words are written. */
internal fun cancelReasonReady(code: String?, note: String): Boolean = code != null && (code != "other" || note.trim().length >= 5)

@Composable
internal fun CancelReasonPicker(code: String?, note: String, onCode: (String) -> Unit, onNote: (String) -> Unit) {
    Column(Modifier.fillMaxWidth().heightIn(max = 420.dp).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(stringResource(R.string.cr_title), color = settingsMut(), fontSize = 12.sp)
        CANCEL_REASONS.forEach { (key, label) ->
            val on = code == key
            Text(
                stringResource(label), color = if (on) Color.White else settingsInk(), fontSize = 13.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(if (on) settingsAccent() else settingsAccent().copy(alpha = 0.08f))
                    .clickable { onCode(key) }.padding(horizontal = 14.dp, vertical = 7.dp),
            )
        }
        DorrTextField(note, { onNote(it.take(500)) }, placeholder = stringResource(R.string.cr_note_hint), singleLine = false, minLines = 2, modifier = Modifier.fillMaxWidth())
    }
}
