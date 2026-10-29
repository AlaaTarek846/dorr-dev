package com.dorr.app.ui.screens.chat

import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Groups
import androidx.compose.material.icons.rounded.School
import androidx.compose.material.icons.rounded.Work
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.chat.ChatFeatures

/**
 * A group's kind (docs/groups_tasks.md GR-001/002): picked on the new-group page, never changed.
 * Educational and work groups are the same group with extra tools; each shows only where the
 * admin left it on (`ChatFeatures`: education_groups / work_groups — the server checks too).
 */
internal enum class GroupKind(val value: String, val icon: ImageVector, val label: Int, val hint: Int, val feature: String?) {
    Normal("normal", Icons.Rounded.Groups, R.string.ch_kind_normal, R.string.ch_kind_normal_hint, null),
    Education("education", Icons.Rounded.School, R.string.ch_kind_education, R.string.ch_kind_education_hint, "education_groups"),
    Work("work", Icons.Rounded.Work, R.string.ch_kind_work, R.string.ch_kind_work_hint, "work_groups");

    companion object {
        fun of(value: String?): GroupKind = entries.firstOrNull { it.value == value } ?: Normal

        /** The kinds I may make here (an ordinary group always). */
        fun available(): List<GroupKind> = entries.filter { it.feature == null || ChatFeatures.allows(it.feature) }
    }
}

/** Three cards side by side; the chosen one fills with the brand colour and pops. */
@Composable
internal fun GroupKindPicker(kinds: List<GroupKind>, selected: GroupKind, onSelect: (GroupKind) -> Unit) {
    Column(Modifier.fillMaxWidth()) {
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            kinds.forEach { kind ->
                val on = kind == selected
                val scale by animateFloatAsState(if (on) 1f else 0.96f, spring(dampingRatio = 0.5f), label = "kindScale")
                val tint by animateColorAsState(if (on) Color.White else Ch.Red, label = "kindTint")
                Column(
                    Modifier.weight(1f).graphicsLayer { scaleX = scale; scaleY = scale }
                        .clip(RoundedCornerShape(16.dp))
                        .then(if (on) Modifier.background(Ch.HeaderBrush) else Modifier.background(Ch.Surface))
                        .border(1.dp, if (on) Color.Transparent else Ch.Line, RoundedCornerShape(16.dp))
                        .clickable { onSelect(kind) }
                        .padding(vertical = 12.dp, horizontal = 6.dp),
                    horizontalAlignment = Alignment.CenterHorizontally,
                ) {
                    Box(
                        Modifier.size(34.dp).clip(RoundedCornerShape(11.dp)).background(if (on) Color.White.copy(alpha = 0.2f) else Ch.Red.copy(alpha = 0.1f)),
                        contentAlignment = Alignment.Center,
                    ) { Icon(kind.icon, null, tint = tint, modifier = Modifier.size(19.dp)) }
                    Spacer(Modifier.height(6.dp))
                    Text(
                        stringResource(kind.label), color = if (on) Color.White else Ch.Ink,
                        fontWeight = FontWeight.ExtraBold, fontSize = 13.sp, textAlign = TextAlign.Center, maxLines = 1,
                    )
                }
            }
        }
        Text(
            stringResource(selected.hint), color = Ch.Mut, fontSize = 12.sp, lineHeight = 16.sp,
            modifier = Modifier.padding(top = 8.dp, start = 4.dp, end = 4.dp),
        )
    }
}

/** "Educational group" / "Work group" under a group's name (nothing for an ordinary group). */
@Composable
internal fun GroupKindChip(kind: String?, modifier: Modifier = Modifier) {
    val k = GroupKind.of(kind)
    if (k == GroupKind.Normal) return
    Row(
        modifier.clip(RoundedCornerShape(50)).background(Color.White.copy(alpha = 0.18f)).padding(horizontal = 10.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(k.icon, null, tint = Color.White, modifier = Modifier.size(14.dp))
        Spacer(Modifier.width(5.dp))
        Text(stringResource(if (k == GroupKind.Education) R.string.ch_kind_education_group else R.string.ch_kind_work_group), color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
    }
}
