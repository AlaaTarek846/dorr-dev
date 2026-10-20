package com.dorr.app.ui.screens.chat

import android.content.Context
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Layers
import androidx.compose.material.icons.rounded.PhoneInTalk
import androidx.compose.material.icons.rounded.ScreenLockPortrait
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
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

/** On the calls page while something is missing. */
@Composable
internal fun CallPermissionCard() {
    val context = LocalContext.current
    val tick = rememberResumeTick()
    val missing = remember(tick) { CallPermissions.missing(context) }
    if (!missing) return
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Ch.Surface).padding(14.dp)) {
        PermissionBody(context, tick)
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
            PermissionBody(context, tick)
            Spacer(Modifier.height(8.dp))
            Text(
                stringResource(R.string.cpm_later), color = Ch.Mut, fontWeight = FontWeight.Bold, fontSize = 14.sp,
                modifier = Modifier.align(Alignment.CenterHorizontally).clip(RoundedCornerShape(12.dp)).clickable { close() }.padding(horizontal = 18.dp, vertical = 10.dp),
            )
        }
    }
}

@Composable
private fun PermissionBody(context: Context, tick: Int) {
    val overlay = remember(tick) { CallPermissions.canOverlay(context) }
    val fullScreen = remember(tick) { CallPermissions.canFullScreen(context) }
    Row(verticalAlignment = Alignment.CenterVertically) {
        IconBadge(Icons.Rounded.PhoneInTalk, Color(0xFF16A34A))
        Spacer(Modifier.width(12.dp))
        Text(stringResource(R.string.cpm_title), color = Ch.Ink, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
    }
    Text(stringResource(R.string.cpm_text), color = Ch.Mut, fontSize = 13.sp, modifier = Modifier.padding(top = 8.dp, bottom = 10.dp))
    PermissionRow(Icons.Rounded.Layers, stringResource(R.string.cpm_overlay), overlay) { CallPermissions.openOverlay(context) }
    if (android.os.Build.VERSION.SDK_INT >= 34) {
        PermissionRow(Icons.Rounded.ScreenLockPortrait, stringResource(R.string.cpm_full_screen), fullScreen) { CallPermissions.openFullScreen(context) }
    }
}

@Composable
private fun PermissionRow(icon: ImageVector, title: String, done: Boolean, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(vertical = 4.dp).clip(RoundedCornerShape(14.dp)).background(Ch.SurfaceMuted)
            .clickable(enabled = !done, onClick = onClick).padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = Ch.Red, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(10.dp))
        Text(title, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 14.sp, modifier = Modifier.weight(1f))
        if (done) {
            Icon(Icons.Rounded.CheckCircle, null, tint = Ch.Success, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(4.dp))
            Text(stringResource(R.string.cpm_done), color = Ch.Success, fontWeight = FontWeight.Bold, fontSize = 12.5.sp)
        } else {
            Box(Modifier.size(8.dp).clip(RoundedCornerShape(4.dp)).background(Ch.Red))
        }
    }
}

private const val PREFS = "dorr_calls"
private const val ASKED = "call_permissions_asked"
