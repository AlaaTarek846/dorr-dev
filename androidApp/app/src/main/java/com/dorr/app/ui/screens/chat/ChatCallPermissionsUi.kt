package com.dorr.app.ui.screens.chat

import android.content.Context
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Layers
import androidx.compose.material.icons.rounded.PhoneInTalk
import androidx.compose.material.icons.rounded.ScreenLockPortrait
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.dorr.app.R
import com.dorr.app.chat.CallPermissions
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.screens.profile.settingsSurface

/*
 * Calls over other apps and the lock screen, like Messenger: "display over other apps" (the
 * ringing page opens on top of whatever is on screen) and, on Android 14+, full-screen calls.
 * Asked once in the chat, and offered on the calls page for as long as either is missing.
 */

/** Re-read when I come back from the phone's settings. */
@Composable
private fun rememberResumeTick(): Int {
    val owner = LocalLifecycleOwner.current
    var tick by remember { mutableIntStateOf(0) }
    DisposableEffect(owner) {
        val observer = LifecycleEventObserver { _, event -> if (event == Lifecycle.Event.ON_RESUME) tick++ }
        owner.lifecycle.addObserver(observer)
        onDispose { owner.lifecycle.removeObserver(observer) }
    }
    return tick
}

/** On the calls page: overlay permission (while missing) and call-link rows in one card. */
@Composable
internal fun CallPermissionCard() {
    val context = LocalContext.current
    val tick = rememberResumeTick()
    val missing = remember(tick) { CallPermissions.missing(context) }
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        if (missing) PermissionIntro()
        Column(
            Modifier
                .fillMaxWidth()
                .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
                .padding(horizontal = 8.dp, vertical = 4.dp),
        ) {
            if (missing) PermissionRows(context, tick, last = false)
            CallLinksCard()
        }
    }
}

/** Once, the first time the chat opens with something missing. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun CallPermissionPrompt() {
    val context = LocalContext.current
    val prefs = remember { context.getSharedPreferences(PREFS, Context.MODE_PRIVATE) }
    var open by remember { mutableStateOf(!prefs.getBoolean(ASKED, false) && CallPermissions.missing(context)) }
    if (!open) return
    val tick = rememberResumeTick()
    fun close() {
        prefs.edit().putBoolean(ASKED, true).apply()
        open = false
    }
    ChSheet(onDismissRequest = { close() }, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 20.dp).padding(bottom = 26.dp)) {
            PermissionIntro()
            Spacer(Modifier.height(12.dp))
            PermissionRows(context, tick, wrap = true)
            Spacer(Modifier.height(8.dp))
            Text(
                stringResource(R.string.cpm_later), color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 14.sp,
                modifier = Modifier.align(Alignment.CenterHorizontally).clip(RoundedCornerShape(12.dp)).clickable { close() }.padding(horizontal = 18.dp, vertical = 10.dp),
            )
        }
    }
}

@Composable
private fun PermissionIntro() {
    Column(Modifier.fillMaxWidth().padding(horizontal = 4.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconBadge(Icons.Rounded.PhoneInTalk, Color(0xFF16A34A))
            Spacer(Modifier.width(12.dp))
            Text(stringResource(R.string.cpm_title), color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.ExtraBold)
        }
        Text(
            stringResource(R.string.cpm_text),
            color = settingsMut(),
            fontSize = 12.5.sp,
            lineHeight = 18.sp,
            modifier = Modifier.padding(top = 4.dp),
        )
    }
}

@Composable
private fun PermissionRows(context: Context, tick: Int, wrap: Boolean = false, last: Boolean = true) {
    val overlay = remember(tick) { CallPermissions.canOverlay(context) }
    val fullScreen = remember(tick) { CallPermissions.canFullScreen(context) }
    val showFullScreen = android.os.Build.VERSION.SDK_INT >= 34
    val rows = @Composable {
        PermissionSettingsRow(Icons.Rounded.Layers, stringResource(R.string.cpm_overlay), overlay, last = if (showFullScreen) false else last) {
            CallPermissions.openOverlay(context)
        }
        if (showFullScreen) {
            PermissionSettingsRow(Icons.Rounded.ScreenLockPortrait, stringResource(R.string.cpm_full_screen), fullScreen, last = last) {
                CallPermissions.openFullScreen(context)
            }
        }
    }
    if (wrap) {
        Column(
            Modifier
                .fillMaxWidth()
                .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
                .padding(horizontal = 8.dp, vertical = 4.dp),
        ) { rows() }
    } else {
        rows()
    }
}

@Composable
private fun PermissionSettingsRow(icon: ImageVector, title: String, done: Boolean, last: Boolean, onAsk: () -> Unit) {
    PrivacySettingsRow(
        icon,
        title,
        value = if (done) stringResource(R.string.cpm_done) else null,
        last = last,
        onClick = if (done) null else onAsk,
    )
}

private const val PREFS = "dorr_calls"
private const val ASKED = "call_permissions_asked"
