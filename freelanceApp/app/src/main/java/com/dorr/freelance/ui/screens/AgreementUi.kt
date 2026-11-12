package com.dorr.freelance.ui.screens

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.ui.components.FormIntro
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface
import com.dorr.freelance.ui.locale.LocaleAwareBottomSheet

/** The pieces the agreement page is built from: a form that opens from the bottom, the action buttons and the tab bar. */

/**
 * A form over the page (instead of a dialog): the same head as the offer form - an icon, a title and a line - then its fields.
 * A [footer] (the save button) stays under the fields, outside their scrolling, so it is above the keyboard while a field is being typed in
 * instead of waiting under it at the end of the scroll.
 */
@Composable
internal fun FormSheet(
    icon: ImageVector, title: String, body: String, onDismiss: () -> Unit, fullHeight: Boolean = false,
    footer: (@Composable ColumnScope.() -> Unit)? = null, content: @Composable ColumnScope.() -> Unit,
) {
    LocaleAwareBottomSheet(
        onDismissRequest = onDismiss,
        fullHeight = fullHeight,
        containerColor = if (settingsNight()) AccountDark.card else Color.White,
        shape = RoundedCornerShape(topStart = 28.dp, topEnd = 28.dp),
    ) {
        if (footer == null) {
            Column(
                Modifier.fillMaxWidth().imePadding().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp),
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) {
                FormIntro(icon, title, body)
                content()
            }
        } else {
            // The fields take what is left above the footer (and scroll when the keyboard leaves little); the footer is never pushed off.
            Column(Modifier.fillMaxWidth().weight(1f, fill = false).imePadding()) {
                Column(
                    Modifier.fillMaxWidth().weight(1f, fill = false).verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 12.dp),
                    verticalArrangement = Arrangement.spacedBy(14.dp),
                ) {
                    FormIntro(icon, title, body)
                    content()
                }
                Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(top = 4.dp, bottom = 4.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) { footer() }
            }
        }
    }
}

internal enum class Tone { Primary, Soft, Danger, Warn }

/** A button of the agreement: filled for the main step, tinted for the others (red for stepping back, amber for a dispute). */
@Composable
internal fun ActionButton(text: String, icon: ImageVector?, tone: Tone = Tone.Soft, enabled: Boolean = true, loading: Boolean = false, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val base = when (tone) { Tone.Danger -> Color(0xFFDC2626); Tone.Warn -> Color(0xFFD97706); else -> settingsAccent() }
    val filled = tone == Tone.Primary
    val ink = if (filled) Color.White else base
    Row(
        modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp))
            .background(if (filled) base.copy(alpha = if (enabled) 1f else 0.45f) else base.copy(alpha = if (enabled) 0.10f else 0.05f))
            .clickable(enabled = enabled && !loading, onClick = onClick).padding(vertical = 13.dp, horizontal = 14.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.CenterHorizontally),
    ) {
        if (loading) CircularProgressIndicator(color = ink, strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
        else if (icon != null) Icon(icon, contentDescription = null, tint = ink.copy(alpha = if (enabled) 1f else 0.5f), modifier = Modifier.size(18.dp))
        Text(text, color = ink.copy(alpha = if (enabled) 1f else 0.5f), fontSize = 14.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
    }
}

internal class AgreementTab(val icon: ImageVector, val label: String, val badge: Int = 0)

/** The sections of the agreement, side by side: the chosen one is filled. */
@Composable
internal fun AgreementTabs(tabs: List<AgreementTab>, selected: Int, onSelect: (Int) -> Unit) {
    Row(Modifier.fillMaxWidth().settingsSurface(RoundedCornerShape(18.dp), elevation = 3.dp).padding(4.dp), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
        tabs.forEachIndexed { i, t ->
            val on = i == selected
            val ink = if (on) Color.White else settingsMut()
            Row(
                Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(if (on) settingsAccent() else Color.Transparent).clickable { onSelect(i) }.padding(vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center,
            ) {
                Icon(t.icon, contentDescription = null, tint = ink, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(5.dp))
                Text(t.label, color = ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                if (t.badge > 0) {
                    Spacer(Modifier.width(5.dp))
                    Box(Modifier.clip(RoundedCornerShape(999.dp)).background(if (on) Color.White.copy(alpha = 0.25f) else settingsAccent().copy(alpha = 0.12f)).padding(horizontal = 6.dp, vertical = 1.dp)) {
                        Text(t.badge.toString(), color = if (on) Color.White else settingsAccent(), fontSize = 10.5.sp, fontWeight = FontWeight.ExtraBold)
                    }
                }
            }
        }
    }
}

internal fun milestoneColor(status: String): Color = when (status) {
    "approved" -> Color(0xFF16A34A)
    "in_progress", "funded" -> Color(0xFF2563EB)
    "delivered" -> Color(0xFF7C3AED)
    "revision", "disputed" -> Color(0xFFD97706)
    "cancelled" -> Color(0xFF6B7280)
    else -> Color(0xFF6B7280)
}

internal fun agreementColor(status: String): Color = when (status) {
    "active" -> Color(0xFF16A34A)
    "pending_approval" -> Color(0xFFD97706)
    "completed" -> Color(0xFF2563EB)
    else -> Color(0xFFDC2626)
}
