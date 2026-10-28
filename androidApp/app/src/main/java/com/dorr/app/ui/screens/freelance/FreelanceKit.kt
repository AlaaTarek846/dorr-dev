package com.dorr.app.ui.screens.freelance

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
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
import com.dorr.app.ui.locale.LocaleAwareBottomSheet
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.screens.wallet.WaSkeleton

/**
 * The pieces the freelance screens share, in the look of the organizer form of the events: an icon tile with a title and a line,
 * fields laid on the page, tinted buttons, a form that opens from the bottom, and skeletons while a page is read.
 */

/** The head of a page or a form: an icon on a soft tile, a title and a line saying what it is for. */
@Composable
internal fun FlIntro(icon: ImageVector, title: String, body: String = "", modifier: Modifier = Modifier) {
    Row(modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(52.dp).clip(RoundedCornerShape(16.dp)).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(26.dp))
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(title, color = settingsInk(), fontSize = 19.sp, fontWeight = FontWeight.ExtraBold)
            if (body.isNotBlank()) Text(body, color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp)
        }
    }
}

/** A heading inside a form: a small icon, the name of the group, and an optional line under it. */
@Composable
internal fun FlHeading(icon: ImageVector, text: String, hint: String? = null) {
    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(7.dp)) {
            Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(17.dp))
            Text(text, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
        }
        if (!hint.isNullOrBlank()) Text(hint, color = settingsMut(), fontSize = 12.sp, lineHeight = 18.sp)
    }
}

enum class FlTone { Primary, Soft, Danger, Warn }

/** A button: filled for the main step, tinted for the others (red to step back, amber for a dispute). */
@Composable
internal fun FlAction(text: String, icon: ImageVector? = null, tone: FlTone = FlTone.Soft, enabled: Boolean = true, loading: Boolean = false, modifier: Modifier = Modifier, onClick: () -> Unit) {
    val base = when (tone) { FlTone.Danger -> Color(0xFFDC2626); FlTone.Warn -> Color(0xFFD97706); else -> settingsAccent() }
    val filled = tone == FlTone.Primary
    val ink = if (filled) Color.White else base
    Row(
        modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp))
            .background(if (filled) base.copy(alpha = if (enabled) 1f else 0.45f) else base.copy(alpha = if (enabled) 0.10f else 0.05f))
            .clickable(enabled = enabled && !loading, onClick = onClick).padding(vertical = 13.dp, horizontal = 14.dp),
        verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp, Alignment.CenterHorizontally),
    ) {
        if (loading) CircularProgressIndicator(color = ink, strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
        else if (icon != null) Icon(icon, null, tint = ink.copy(alpha = if (enabled) 1f else 0.5f), modifier = Modifier.size(18.dp))
        Text(text, color = ink.copy(alpha = if (enabled) 1f else 0.5f), fontSize = 14.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
    }
}

/** A small line of colour with an icon (a hint, a warning, a result). */
@Composable
internal fun FlNotice(text: String, icon: ImageVector, color: Color, modifier: Modifier = Modifier) {
    Row(
        modifier.fillMaxWidth().clip(RoundedCornerShape(14.dp)).background(color.copy(alpha = 0.10f)).padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(9.dp),
    ) {
        Icon(icon, null, tint = color, modifier = Modifier.padding(top = 1.dp).size(17.dp))
        Text(text, color = if (settingsNight()) color.copy(alpha = 0.95f) else color, fontSize = 12.5.sp, lineHeight = 19.sp, fontWeight = FontWeight.SemiBold)
    }
}

/** A figure with its name, in a tile (the project's budget, the days, the offers). */
@Composable
internal fun FlStat(icon: ImageVector, label: String, value: String, modifier: Modifier = Modifier) {
    Column(
        modifier.clip(RoundedCornerShape(14.dp)).background(if (settingsNight()) AccountDark.well else settingsAccent().copy(alpha = 0.07f)).padding(horizontal = 10.dp, vertical = 9.dp),
        verticalArrangement = Arrangement.spacedBy(3.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp)) {
            Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(14.dp))
            Text(label, color = settingsMut(), fontSize = 11.sp, maxLines = 1)
        }
        Text(value, color = settingsInk(), fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
    }
}

/** A form over the page (not a dialog): the same head as the organizer form, then its fields. */
@Composable
internal fun FlSheet(icon: ImageVector, title: String, body: String, onDismiss: () -> Unit, content: @Composable ColumnScope.() -> Unit) {
    LocaleAwareBottomSheet(
        onDismissRequest = onDismiss,
        containerColor = if (settingsNight()) AccountDark.card else Color.White,
        shape = RoundedCornerShape(topStart = 28.dp, topEnd = 28.dp),
    ) {
        Column(
            Modifier.fillMaxWidth().imePadding().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            FlIntro(icon, title, body)
            content()
        }
    }
}

/** Grey blocks that stand for a card while the page is read (instead of a spinner). */
@Composable
internal fun FlSkeletonList(count: Int = 4, height: androidx.compose.ui.unit.Dp = 104.dp) {
    Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
        repeat(count) { WaSkeleton(Modifier.fillMaxWidth().height(height), RoundedCornerShape(18.dp)) }
    }
}

@Composable
internal fun FlSkeletonDetail() {
    Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        WaSkeleton(Modifier.fillMaxWidth().height(120.dp), RoundedCornerShape(20.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) { repeat(3) { WaSkeleton(Modifier.weight(1f).height(56.dp), RoundedCornerShape(14.dp)) } }
        WaSkeleton(Modifier.fillMaxWidth().height(150.dp), RoundedCornerShape(18.dp))
        WaSkeleton(Modifier.fillMaxWidth().height(90.dp), RoundedCornerShape(18.dp))
    }
}

/** What a page shows until it is read: the skeleton, or the failure when the read failed. */
@Composable
internal fun FlLoading(error: String?) {
    if (error != null) Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { Text(error, color = settingsMut(), fontSize = 14.sp, textAlign = TextAlign.Center) }
    else FlSkeletonDetail()
}

internal class FlTab(val icon: ImageVector, val label: String, val badge: Int = 0)

/** The sections of a long page, side by side: the chosen one is filled. */
@Composable
internal fun FlTabs(tabs: List<FlTab>, selected: Int, onSelect: (Int) -> Unit) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(if (settingsNight()) AccountDark.card else Color.White).padding(4.dp),
        horizontalArrangement = Arrangement.spacedBy(4.dp),
    ) {
        tabs.forEachIndexed { i, t ->
            val on = i == selected
            val ink = if (on) Color.White else settingsMut()
            Row(
                Modifier.weight(1f).clip(RoundedCornerShape(14.dp)).background(if (on) settingsAccent() else Color.Transparent).clickable { onSelect(i) }.padding(vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center,
            ) {
                Icon(t.icon, null, tint = ink, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(5.dp))
                Text(t.label, color = ink, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, maxLines = 1)
                if (t.badge > 0) {
                    Spacer(Modifier.width(5.dp))
                    Text(
                        t.badge.toString(), color = if (on) Color.White else settingsAccent(), fontSize = 10.5.sp, fontWeight = FontWeight.ExtraBold,
                        modifier = Modifier.clip(RoundedCornerShape(999.dp)).background(if (on) Color.White.copy(alpha = 0.25f) else settingsAccent().copy(alpha = 0.12f)).padding(horizontal = 6.dp, vertical = 1.dp),
                    )
                }
            }
        }
    }
}
