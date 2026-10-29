package com.dorr.freelance.ui.kit

import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.layout.fillMaxSize
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
    val dark = settingsNight()
    val ink = if (dark) AccountDark.accent else settingsAccent()
    com.dorr.freelance.ui.locale.LocaleAwareDialog(
        onDismissRequest = { if (!loading) onDismiss() },
        properties = androidx.compose.ui.window.DialogProperties(usePlatformDefaultWidth = false),
    ) {
        androidx.compose.foundation.layout.Box(
            Modifier.fillMaxSize().background(androidx.compose.ui.graphics.Color.Black.copy(alpha = 0.45f)).clickable(enabled = !loading, onClick = onDismiss),
            contentAlignment = Alignment.Center,
        ) {
            androidx.compose.foundation.layout.Box(
                Modifier.padding(horizontal = 24.dp).widthIn(max = 300.dp).fillMaxWidth()
                    .shadow(16.dp, RoundedCornerShape(20.dp), ambientColor = androidx.compose.ui.graphics.Color(0x24001B53), spotColor = androidx.compose.ui.graphics.Color(0x24001B53))
                    .clip(RoundedCornerShape(20.dp))
                    .clickable(interactionSource = androidx.compose.runtime.remember { androidx.compose.foundation.interaction.MutableInteractionSource() }, indication = null, onClick = {}),
            ) {
                PinkBackdrop(Modifier.matchParentSize())
                Column(Modifier.padding(horizontal = 16.dp, vertical = 18.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    androidx.compose.foundation.layout.Box(
                        Modifier.padding(bottom = 12.dp).size(56.dp)
                            .then(if (dark) Modifier else Modifier.shadow(8.dp, RoundedCornerShape(18.dp), ambientColor = androidx.compose.ui.graphics.Color(0x1A001B53), spotColor = androidx.compose.ui.graphics.Color(0x1A001B53)))
                            .clip(RoundedCornerShape(18.dp)).background(settingsCard())
                            .then(if (dark) Modifier.border(1.dp, AccountDark.line, RoundedCornerShape(18.dp)) else Modifier),
                        contentAlignment = Alignment.Center,
                    ) { Icon(icon, contentDescription = null, tint = ink, modifier = Modifier.size(26.dp)) }
                    Text(title, color = ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
                    Spacer(Modifier.height(4.dp))
                    Text(message, color = settingsMut(), fontSize = 13.sp, fontWeight = FontWeight.Bold, textAlign = androidx.compose.ui.text.style.TextAlign.Center, lineHeight = 20.sp)
                    Spacer(Modifier.height(16.dp))
                    Row(Modifier.fillMaxWidth(), horizontalArrangement = androidx.compose.foundation.layout.Arrangement.spacedBy(8.dp)) {
                        androidx.compose.foundation.layout.Box(
                            Modifier.weight(1f).height(46.dp)
                                .shadow(8.dp, RoundedCornerShape(14.dp), ambientColor = androidx.compose.ui.graphics.Color(0x38001B53), spotColor = androidx.compose.ui.graphics.Color(0x38001B53))
                                .clip(RoundedCornerShape(14.dp)).background(settingsAccent().copy(alpha = if (loading) 0.6f else 1f))
                                .clickable(enabled = !loading, onClick = onConfirm),
                            contentAlignment = Alignment.Center,
                        ) {
                            if (loading) CircularProgressIndicator(color = androidx.compose.ui.graphics.Color.White, strokeWidth = 2.dp, modifier = Modifier.size(17.dp))
                            else Text(confirmLabel, color = androidx.compose.ui.graphics.Color.White, fontSize = 15.sp, fontWeight = FontWeight.Bold)
                        }
                        androidx.compose.foundation.layout.Box(
                            Modifier.weight(1f).height(46.dp).clip(RoundedCornerShape(14.dp)).background(settingsCard())
                                .border(1.5.dp, if (dark) AccountDark.line else ink.copy(alpha = 0.25f), RoundedCornerShape(14.dp))
                                .clickable(enabled = !loading, onClick = onDismiss),
                            contentAlignment = Alignment.Center,
                        ) { Text(stringResource(R.string.common_cancel), color = ink.copy(alpha = if (loading) 0.5f else 1f), fontSize = 15.sp, fontWeight = FontWeight.Bold) }
                    }
                }
            }
        }
    }
}
