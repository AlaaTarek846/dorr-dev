package com.dorr.freelance.ui.kit

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Info
import androidx.compose.material3.Icon
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.LocalTextStyle
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.DialogProperties
import com.dorr.freelance.ui.locale.LocaleAwareDialog

/**
 * The app's confirm / form dialog — same call shape as Material's AlertDialog, drawn like the app's own popups
 * (Language, FAQ…): dimmed screen, 20dp card on the soft app-colour backdrop with a shadow, a tinted icon tile and an
 * accent title on top, the body below, the buttons in a row at the end. Screens import this one, so every dialog looks
 * the same and follows the appearance / night settings.
 */
@Composable
fun AlertDialog(
    onDismissRequest: () -> Unit,
    confirmButton: @Composable () -> Unit,
    modifier: Modifier = Modifier,
    dismissButton: @Composable (() -> Unit)? = null,
    title: @Composable (() -> Unit)? = null,
    text: @Composable (() -> Unit)? = null,
    icon: ImageVector = Icons.Rounded.Info,
) {
    val night = settingsNight()
    val shape = RoundedCornerShape(20.dp)
    LocaleAwareDialog(onDismissRequest = onDismissRequest, properties = DialogProperties(usePlatformDefaultWidth = false, decorFitsSystemWindows = false)) {
        // decorFitsSystemWindows = false is what lets imePadding() see the keyboard: before it, the keyboard covered the dialog's buttons.
        Box(
            modifier = Modifier.fillMaxSize().background(Color.Black.copy(alpha = 0.45f)).clickable(onClick = onDismissRequest).imePadding(),
            contentAlignment = Alignment.Center,
        ) {
            Box(
                modifier = modifier
                    .padding(horizontal = 24.dp)
                    .widthIn(max = 340.dp)
                    .fillMaxWidth()
                    .shadow(16.dp, shape, ambientColor = Color(0x24001B53), spotColor = Color(0x24001B53))
                    .clip(shape)
                    .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClick = {}),
            ) {
                PinkBackdrop(Modifier.matchParentSize())
                Column(Modifier.padding(horizontal = 18.dp, vertical = 18.dp)) {
                    Column(Modifier.fillMaxWidth().padding(bottom = 14.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        Box(
                            Modifier.size(64.dp).clip(RoundedCornerShape(20.dp))
                                .background(if (night) AccountDark.well else settingsAccent().copy(alpha = 0.14f)),
                            contentAlignment = Alignment.Center,
                        ) { Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(34.dp)) }
                        if (title != null) {
                            Spacer(Modifier.height(12.dp))
                            CompositionLocalProvider(
                                LocalContentColor provides (if (night) AccountDark.accent else settingsAccent()),
                                LocalTextStyle provides LocalTextStyle.current.copy(fontSize = 18.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center),
                            ) { title() }
                        }
                    }
                    if (text != null) {
                        Box(Modifier.fillMaxWidth().heightIn(max = 440.dp).verticalScrollIfNeeded(), contentAlignment = Alignment.Center) {
                            CompositionLocalProvider(
                                LocalContentColor provides (if (title == null) settingsInk() else settingsMut()),
                                LocalTextStyle provides LocalTextStyle.current.copy(
                                    fontSize = if (title == null) 16.sp else 14.sp,
                                    fontWeight = if (title == null) FontWeight.Bold else FontWeight.Normal,
                                    textAlign = TextAlign.Center,
                                ),
                            ) { text() }
                        }
                    }
                    Row(
                        Modifier.fillMaxWidth().padding(top = 14.dp),
                        horizontalArrangement = Arrangement.spacedBy(10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Box(Modifier.weight(1f)) { CompositionLocalProvider(LocalDialogButtonPrimary provides true) { confirmButton() } }
                        if (dismissButton != null) Box(Modifier.weight(1f)) { CompositionLocalProvider(LocalDialogButtonPrimary provides false) { dismissButton() } }
                    }
                }
            }
        }
    }
}

@Composable
private fun Modifier.verticalScrollIfNeeded(): Modifier = this.verticalScroll(rememberScrollState())

private val LocalDialogButtonPrimary = androidx.compose.runtime.compositionLocalOf { true }

/**
 * A dialog's button, drawn like the popups' own close button: the main one (confirm) is a filled accent pill with
 * white text, the other a soft grey pill. Drop-in for TextButton inside a dialog's confirm / dismiss slot.
 */
@Composable
fun DialogButton(onClick: () -> Unit, modifier: Modifier = Modifier, enabled: Boolean = true, content: @Composable androidx.compose.foundation.layout.RowScope.() -> Unit) {
    val primary = LocalDialogButtonPrimary.current
    val shape = RoundedCornerShape(14.dp)
    Box(
        modifier = modifier
            .fillMaxWidth()
            .heightIn(min = 44.dp)
            .alpha(if (enabled) 1f else 0.45f)
            .then(if (primary) Modifier.shadow(8.dp, shape, ambientColor = Color(0x38001B53), spotColor = Color(0x38001B53)) else Modifier)
            .clip(shape)
            .background(if (primary) settingsAccent() else settingsMut().copy(alpha = 0.16f))
            .clickable(enabled = enabled, onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 8.dp),
        contentAlignment = Alignment.Center,
    ) {
        CompositionLocalProvider(
            LocalContentColor provides (if (primary) Color.White else settingsInk()),
            LocalTextStyle provides LocalTextStyle.current.copy(fontSize = 15.sp, fontWeight = FontWeight.Bold),
        ) { Row(verticalAlignment = Alignment.CenterVertically, content = content) }
    }
}
