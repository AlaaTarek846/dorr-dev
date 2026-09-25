package com.dorr.app.ui.screens.profile

import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.ui.draw.clip
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Chat
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.ui.theme.AppColors

// Placeholder contact details — replace with the real support channels.
private const val CONTACT_PHONE = "+96522200000"
private const val CONTACT_WHATSAPP = "96522200000"
private const val CONTACT_EMAIL = "support@dorr.app"

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ContactUsSheet(onDismiss: () -> Unit) {
    val context = LocalContext.current

    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(modifier = Modifier.padding(horizontal = 20.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(
                    modifier = Modifier
                        .size(40.dp)
                        .clip(RoundedCornerShape(12.dp))
                        .background(AppColors.primary.copy(alpha = 0.1f)),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(Icons.Rounded.Call, contentDescription = null, tint = AppColors.primary, modifier = Modifier.size(22.dp))
                }
                Spacer(Modifier.width(12.dp))
                Text(stringResource(R.string.contact_title), style = MaterialTheme.typography.headlineMedium)
            }
            Spacer(Modifier.height(16.dp))

            ContactTile(
                icon = Icons.Rounded.Call,
                label = stringResource(R.string.contact_phone),
                value = CONTACT_PHONE,
                onClick = { tryStart(context, Intent(Intent.ACTION_DIAL, Uri.parse("tel:$CONTACT_PHONE"))) },
            )
            ContactTile(
                icon = Icons.Rounded.Chat,
                label = stringResource(R.string.contact_whatsapp),
                value = CONTACT_WHATSAPP,
                onClick = {
                    tryStart(context, Intent(Intent.ACTION_VIEW, Uri.parse("https://wa.me/$CONTACT_WHATSAPP")))
                },
            )
            ContactTile(
                icon = Icons.Rounded.Email,
                label = stringResource(R.string.contact_email),
                value = CONTACT_EMAIL,
                onClick = { tryStart(context, Intent(Intent.ACTION_SENDTO, Uri.parse("mailto:$CONTACT_EMAIL"))) },
            )
            Spacer(Modifier.height(24.dp))
        }
    }
}

@Composable
private fun ContactTile(icon: ImageVector, label: String, value: String, onClick: () -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(onClick = onClick)
            .padding(vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            modifier = Modifier
                .background(AppColors.primary.copy(alpha = 0.1f), RoundedCornerShape(12.dp))
                .padding(10.dp),
        ) {
            Icon(icon, contentDescription = null, tint = AppColors.primary)
        }
        Spacer(Modifier.width(14.dp))
        Column(modifier = Modifier.weight(1f)) {
            Text(label, style = MaterialTheme.typography.bodyLarge)
            Text(value, style = MaterialTheme.typography.bodySmall, color = AppColors.textSecondary)
        }
        Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = AppColors.textMuted, modifier = Modifier.size(20.dp))
    }
}

private fun tryStart(context: android.content.Context, intent: Intent) {
    try {
        context.startActivity(intent)
    } catch (_: ActivityNotFoundException) {
        // No app can handle it (e.g. no dialer on an emulator) — silently ignore,
        // matches the reference app's best-effort external-app launch.
    }
}
