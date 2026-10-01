package com.dorr.app.ui.screens.profile

import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Chat
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.ui.locale.LocaleAwareDialog
import androidx.compose.ui.window.DialogProperties
import com.dorr.app.R
import com.dorr.app.ui.theme.AppColors

private const val CONTACT_PHONE = "+96522200000"
private const val CONTACT_WHATSAPP = "96522200000"
private const val CONTACT_EMAIL = "support@dorr.app"

@Composable
fun ContactUsSheet(onDismiss: () -> Unit) {
    val context = LocalContext.current
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl

    LocaleAwareDialog(
        onDismissRequest = onDismiss,
        properties = DialogProperties(usePlatformDefaultWidth = false),
    ) {
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(Color.Black.copy(alpha = 0.45f))
                .clickable(onClick = onDismiss),
            contentAlignment = Alignment.BottomCenter,
        ) {
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .clip(RoundedCornerShape(topStart = 22.dp, topEnd = 22.dp))
                    .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClick = {}),
            ) {
                PinkBackdrop(Modifier.matchParentSize())
                Column(
                    modifier = Modifier
                        .padding(horizontal = 14.dp)
                        .padding(top = 18.dp, bottom = 24.dp),
                ) {
                Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(bottom = 12.dp)) {
                    PinkIcon(Icons.Rounded.Call)
                    Spacer(Modifier.width(10.dp))
                    Text(stringResource(R.string.contact_title), color = settingsAccent(), fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                }
                ContactTile(Icons.Rounded.Call, stringResource(R.string.contact_phone), CONTACT_PHONE, rtl) {
                    tryStart(context, Intent(Intent.ACTION_DIAL, Uri.parse("tel:$CONTACT_PHONE")))
                }
                Spacer(Modifier.size(8.dp))
                ContactTile(Icons.Rounded.Chat, stringResource(R.string.contact_whatsapp), CONTACT_WHATSAPP, rtl) {
                    tryStart(context, Intent(Intent.ACTION_VIEW, Uri.parse("https://wa.me/$CONTACT_WHATSAPP")))
                }
                Spacer(Modifier.size(8.dp))
                ContactTile(Icons.Rounded.Email, stringResource(R.string.contact_email), CONTACT_EMAIL, rtl) {
                    tryStart(context, Intent(Intent.ACTION_SENDTO, Uri.parse("mailto:$CONTACT_EMAIL")))
                }
                }
            }
        }
    }
}

@Composable
private fun ContactTile(icon: ImageVector, label: String, value: String, rtl: Boolean, onClick: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .settingsSurface(RoundedCornerShape(14.dp))
            .clickable(onClick = onClick)
            .padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        PinkIcon(icon)
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(label, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = settingsInk())
            Text(value, fontSize = 12.sp, color = settingsMut())
        }
        Icon(
            Icons.Rounded.ChevronRight,
            contentDescription = null,
            tint = if (settingsNight()) com.dorr.app.ui.screens.AccountDark.chevron else Color(0xFFEFA8B4),
            modifier = Modifier.size(16.dp).graphicsLayer { if (rtl) scaleX = -1f },
        )
    }
}

private fun tryStart(context: android.content.Context, intent: Intent) {
    try {
        context.startActivity(intent)
    } catch (_: ActivityNotFoundException) {
    }
}
