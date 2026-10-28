package com.dorr.freelance.ui.kit

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.R

/** One row of an account menu (the DORR app settings rows): an icon tile, a title, a short line under it, and a chevron. */
data class MenuEntry(
    val icon: ImageVector,
    val label: Int,
    val subtitle: Int? = null,
    val danger: Boolean = false,
    val trailing: (@Composable () -> Unit)? = null,
    val onClick: () -> Unit,
)

@Composable
fun MenuRow(entry: MenuEntry) {
    val dark = settingsNight()
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val shape = RoundedCornerShape(14.dp)
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 4.dp)
            .then(if (dark) Modifier else Modifier.shadow(6.dp, shape, spotColor = settingsAccent().copy(alpha = 0.07f)))
            .clip(shape)
            .background(settingsCard())
            .then(if (dark) Modifier.border(1.dp, AccountDark.line, shape) else Modifier)
            .clickable(onClick = entry.onClick)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        PinkIcon(entry.icon)
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(entry.label), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = if (entry.danger) settingsAccent() else settingsInk())
            entry.subtitle?.let { Text(stringResource(it), fontSize = 11.sp, color = settingsInk().copy(alpha = 0.65f)) }
        }
        if (entry.trailing != null) {
            entry.trailing.invoke()
        } else {
            Icon(
                Icons.Rounded.ChevronRight,
                contentDescription = null,
                tint = if (dark) AccountDark.chevron else settingsAccent().copy(alpha = 0.55f),
                modifier = Modifier.size(16.dp).graphicsLayer { if (rtl) scaleX = -1f },
            )
        }
    }
}

/** A yes / cancel question (sign out, delete the account). */
@Composable
fun ConfirmDialog(
    icon: ImageVector,
    title: String,
    message: String,
    confirmLabel: String,
    loading: Boolean = false,
    onConfirm: () -> Unit,
    onDismiss: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        icon = { Icon(icon, contentDescription = null, tint = settingsAccent()) },
        title = { Text(title, fontWeight = FontWeight.ExtraBold) },
        text = { Text(message) },
        confirmButton = {
            TextButton(onClick = onConfirm, enabled = !loading) {
                if (loading) CircularProgressIndicator(strokeWidth = 2.dp, modifier = Modifier.size(18.dp)) else Text(confirmLabel, color = settingsAccent(), fontWeight = FontWeight.Bold)
            }
        },
        dismissButton = { TextButton(onClick = onDismiss, enabled = !loading) { Text(stringResource(R.string.common_cancel), color = settingsMut()) } },
        containerColor = settingsCard(),
    )
}
