package com.dorr.driver.ui

import android.Manifest
import android.app.Activity
import android.content.BroadcastReceiver
import android.content.Context
import android.content.ContextWrapper
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.location.LocationManager
import android.net.Uri
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.IntentSenderRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.LocationOn
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.core.content.ContextCompat
import androidx.core.location.LocationManagerCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.dorr.driver.R
import com.google.android.gms.common.api.ResolvableApiException
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.LocationSettingsRequest
import com.google.android.gms.location.Priority

/*
 * The location gate (docs/rides_tasks.md O-49, as O-48 in the DORR app): requests can't reach a captain whose place isn't known, so a
 * window that can't be dismissed asks first for the permission, then for the phone's location to be on —
 * the system's own «turn on location» dialog, right here, without leaving the app. It checks again every
 * time the app comes back (from the settings) and the moment the location switch flips — no restart needed.
 */

/** Location is allowed and switched on. */
internal fun driverLocationReady(context: Context): Boolean =
    ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED &&
        LocationManagerCompat.isLocationEnabled(context.getSystemService(LocationManager::class.java))

@Composable
internal fun LocationGate(onReady: () -> Unit, onLeave: (() -> Unit)? = null) {
    val context = LocalContext.current
    fun permitted() = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
    fun switchedOn() = LocationManagerCompat.isLocationEnabled(context.getSystemService(LocationManager::class.java))
    var allowed by remember { mutableStateOf(permitted()) }
    var on by remember { mutableStateOf(switchedOn()) }
    var askedOnce by remember { mutableStateOf(false) }

    // Back from the settings (or any other app): check again.
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    DisposableEffect(lifecycle) {
        val observer = LifecycleEventObserver { _, event -> if (event == Lifecycle.Event.ON_RESUME) { allowed = permitted(); on = switchedOn() } }
        lifecycle.addObserver(observer)
        onDispose { lifecycle.removeObserver(observer) }
    }
    // The location switch flipped (from the quick settings): at once.
    DisposableEffect(Unit) {
        val receiver = object : BroadcastReceiver() {
            override fun onReceive(c: Context, intent: Intent) { on = switchedOn() }
        }
        ContextCompat.registerReceiver(context, receiver, IntentFilter(LocationManager.PROVIDERS_CHANGED_ACTION), ContextCompat.RECEIVER_NOT_EXPORTED)
        onDispose { runCatching { context.unregisterReceiver(receiver) } }
    }
    LaunchedEffect(allowed, on) { if (allowed && on) onReady() }

    val askPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        allowed = permitted()
        askedOnce = true
    }
    val turnOn = rememberLauncherForActivityResult(ActivityResultContracts.StartIntentSenderForResult()) { on = switchedOn() }
    if (allowed && on) return

    val denyForever = askedOnce && !allowed && context.findActivity()?.shouldShowRequestPermissionRationale(Manifest.permission.ACCESS_FINE_LOCATION) == false
    val bob = rememberInfiniteTransition(label = "pin").animateFloat(0f, 1f, infiniteRepeatable(tween(900), RepeatMode.Reverse), label = "bob")

    Dialog(onDismissRequest = {}, properties = DialogProperties(dismissOnBackPress = false, dismissOnClickOutside = false, usePlatformDefaultWidth = false)) {
        Column(
            Modifier.padding(24.dp).fillMaxWidth().clip(RoundedCornerShape(28.dp)).background(Color.White).padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Box(Modifier.size(96.dp).clip(CircleShape).background(Brush.linearGradient(listOf(Color(0xFF001B53), Color(0xFF2B4C9B)))), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.LocationOn, null, tint = Color.White, modifier = Modifier.size(52.dp).graphicsLayer { translationY = -bob.value * 10f })
            }
            Spacer(Modifier.height(18.dp))
            Text(stringResource(if (!allowed) R.string.lg_title_permission else R.string.lg_title_off), color = Color(0xFF111928), fontWeight = FontWeight.Black, fontSize = 20.sp, textAlign = TextAlign.Center)
            Spacer(Modifier.height(8.dp))
            Text(stringResource(if (!allowed) R.string.lg_body_permission else R.string.lg_body_off), color = Color(0xFF6B7280), fontSize = 14.sp, textAlign = TextAlign.Center)
            Spacer(Modifier.height(22.dp))
            Text(
                stringResource(if (!allowed) (if (denyForever) R.string.lg_open_settings else R.string.lg_allow) else R.string.lg_turn_on),
                color = Color.White, fontWeight = FontWeight.Bold, fontSize = 16.sp, textAlign = TextAlign.Center,
                modifier = Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Color(0xFF001B53)).clickable {
                    when {
                        !allowed && denyForever -> context.startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", context.packageName, null)))
                        !allowed -> askPermission.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
                        else -> {
                            val request = LocationSettingsRequest.Builder().addLocationRequest(LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, 10_000).build()).setAlwaysShow(true).build()
                            LocationServices.getSettingsClient(context).checkLocationSettings(request)
                                .addOnSuccessListener { on = switchedOn() }
                                .addOnFailureListener { e ->
                                    if (e is ResolvableApiException) runCatching { turnOn.launch(IntentSenderRequest.Builder(e.resolution).build()) }
                                    else context.startActivity(Intent(Settings.ACTION_LOCATION_SOURCE_SETTINGS))
                                }
                        }
                    }
                }.padding(vertical = 15.dp),
            )
            onLeave?.let {
                Text(
                    stringResource(R.string.lg_leave), color = Color(0xFF6B7280), fontSize = 14.sp, textAlign = TextAlign.Center,
                    modifier = Modifier.padding(top = 10.dp).fillMaxWidth().clip(RoundedCornerShape(16.dp)).clickable(onClick = it).padding(vertical = 10.dp),
                )
            }
        }
    }
}

private fun Context.findActivity(): Activity? {
    var c: Context? = this
    while (c is ContextWrapper) {
        if (c is Activity) return c
        c = c.baseContext
    }
    return null
}
