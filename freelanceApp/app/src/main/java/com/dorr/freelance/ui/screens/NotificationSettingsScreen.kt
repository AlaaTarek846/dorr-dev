package com.dorr.freelance.ui.screens

import android.content.Context
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Receipt
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.R
import com.dorr.freelance.net.Realtime
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.PinkBackdrop
import com.dorr.freelance.ui.kit.PinkIcon
import com.dorr.freelance.ui.kit.RedToggle
import com.dorr.freelance.ui.kit.SubHeader
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsNight
import com.dorr.freelance.ui.kit.settingsSurface

private data class NotifToggle(val key: String, val title: Int, val desc: Int, val icon: ImageVector, val startsOn: Boolean)

/** The settings toggles of the DORR app, worded for the freelancer. The first one is the phone's push switch; the others are kept on this phone. */
private val toggles = listOf(
    NotifToggle("push", R.string.notif_push_title, R.string.notif_push_desc, Icons.Rounded.Notifications, true),
    NotifToggle("updates", R.string.notif_updates_title, R.string.notif_updates_desc, Icons.Rounded.Receipt, true),
    NotifToggle("promo", R.string.notif_promo_title, R.string.notif_promo_desc, Icons.Rounded.LocalOffer, true),
    NotifToggle("email", R.string.notif_email_title, R.string.notif_email_desc, Icons.Rounded.Email, false),
)

private const val PREFS = "freelance_notification_prefs"

private fun saved(context: Context, toggle: NotifToggle): Boolean =
    if (toggle.key == "push") Realtime.pushOptedIn()
    else context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getBoolean(toggle.key, toggle.startsOn)

private fun save(context: Context, toggle: NotifToggle, on: Boolean) {
    if (toggle.key == "push") Realtime.setPushOptedIn(on)
    else context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putBoolean(toggle.key, on).apply()
}

/** Notifications under Settings, as in the DORR app: a card of switches with an icon, a title and a line each. */
@Composable
fun NotificationSettingsScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val states = remember { mutableStateListOf(*toggles.map { saved(context, it) }.toTypedArray()) }
    val dark = settingsNight()
    val cardShape = RoundedCornerShape(18.dp)

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(R.string.account_notifications), onBack)
            Column(
                modifier = Modifier
                    .verticalScroll(rememberScrollState())
                    .padding(horizontal = 14.dp)
                    .padding(top = 6.dp, bottom = 16.dp)
                    .settingsSurface(cardShape, 8.dp)
                    .padding(horizontal = 8.dp, vertical = 4.dp),
            ) {
                toggles.forEachIndexed { index, toggle ->
                    Row(
                        modifier = Modifier.fillMaxWidth().padding(horizontal = 4.dp, vertical = 12.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        PinkIcon(toggle.icon)
                        Spacer(Modifier.width(10.dp))
                        Column(Modifier.weight(1f)) {
                            Text(stringResource(toggle.title), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = settingsInk())
                            Text(
                                stringResource(toggle.desc),
                                fontSize = 11.sp,
                                color = settingsMut(),
                                lineHeight = 15.sp,
                                modifier = Modifier.padding(top = 2.dp),
                            )
                        }
                        RedToggle(states[index], dark) {
                            states[index] = !states[index]
                            save(context, toggle, states[index])
                        }
                    }
                    if (index != toggles.lastIndex) {
                        Box(Modifier.fillMaxWidth().height(1.dp).background(if (dark) AccountDark.line else Color(0xFFF6E4E8)))
                    }
                }
            }
        }
    }
}
