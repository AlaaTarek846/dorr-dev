package com.dorr.app.ui.screens.chat

import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Paint
import android.graphics.PorterDuff
import android.graphics.PorterDuffXfermode
import android.graphics.Rect
import android.graphics.RectF
import android.net.Uri
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Directions
import androidx.compose.material.icons.rounded.MyLocation
import androidx.compose.material.icons.rounded.StopCircle
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.DialogProperties
import coil.request.ImageRequest
import coil.request.SuccessResult
import com.dorr.app.R
import com.dorr.app.map.DorrMap
import com.dorr.app.map.MapPin
import com.dorr.app.map.MapZone
import com.dorr.app.map.PinKind
import com.dorr.app.map.rememberDorrCamera
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ProfileDto
import com.dorr.app.ui.locale.LocaleAwareDialog
import com.google.android.gms.maps.model.LatLng
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/*
 * Live location, as in WhatsApp but in Dorr's colours: the sharer's photo is the pin (in the app's red ring, a red
 * pulse around it while live), and a tap on the bubble opens «View live location» — a big map that follows them.
 * Every move arrives with the `chat.location.moved` event (ConversationState updates the message): nothing here
 * asks the server.
 */

/** The sharer's photo in the app's red ring, with a red pulse around it while the location is live (the bubble). */
@Composable
fun LiveAvatarPin(sender: ProfileDto?, active: Boolean, size: Dp = 46.dp) {
    val pulse = rememberInfiniteTransition(label = "livePin")
    val ring by pulse.animateFloat(0f, 1f, infiniteRepeatable(tween(1800, easing = LinearEasing)), label = "livePinRing")
    val color = if (active) Ch.Red else Ch.Mut
    Box(
        Modifier.size(size).drawBehind {
            if (active) {
                drawCircle(color.copy(alpha = 0.4f * (1f - ring)), radius = this.size.minDimension / 2 + 26.dp.toPx() * ring)
                drawCircle(color.copy(alpha = 0.2f), radius = this.size.minDimension / 2 + 6.dp.toPx())
            }
        },
        contentAlignment = Alignment.Center,
    ) {
        Box(Modifier.size(size).clip(CircleShape).background(color).padding(3.dp)) {
            Box(Modifier.fillMaxSize().clip(CircleShape).border(2.dp, Color.White, CircleShape)) {
                ChAvatar(sender?.avatar, sender?.name, sender?.key, size = size - 6.dp)
            }
        }
    }
}

/**
 * «View live location»: the map follows the sharer's photo pin (until I move the map; 🎯 brings it back), with a
 * red halo for how precise the fix is. Under it, in a card: who, «Live · N min left» (or ended), when it last moved,
 * «Directions» (the phone's maps app) and — mine, still live — «Stop sharing».
 */
@Composable
fun LiveLocationPage(state: ConversationState, messageId: String, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val density = LocalDensity.current
    // The message as it moves (the event updates it in the conversation's list).
    val m = state.messages.firstOrNull { it.id == messageId }
    val meta = m?.dto?.meta
    val at = LatLng(meta?.dbl("latitude") ?: 0.0, meta?.dbl("longitude") ?: 0.0)
    val accuracy = meta?.dbl("accuracy")
    val live = m?.dto?.liveLocation
    val sender = m?.dto?.sender
    val mine = m?.isMine == true
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    // The labels come from the device clock (redrawn every 15 s) — no server calls.
    LaunchedEffect(Unit) { while (true) { delay(15_000); now = System.currentTimeMillis() } }
    val until = parseInstant(live?.liveUntil)?.toEpochMilli() ?: 0L
    val active = live?.active == true && until > now
    val updatedAt = parseInstant(live?.updatedAt)?.toEpochMilli()

    val camera = rememberDorrCamera(at, 16.5f)
    var following by remember { mutableStateOf(true) }
    // My finger moved the map: stop following until 🎯.
    LaunchedEffect(camera.isMoving, camera.byGesture) { if (camera.isMoving && camera.byGesture) following = false }
    LaunchedEffect(at, following) { if (following) camera.animate(at, camera.zoom.coerceAtLeast(15f)) }

    // The photo, drawn once as the map pin (a red ring, white edge) — the initial on red until it has loaded.
    val photo = rememberPinBitmap(context, sender)
    var cardHeight by remember { mutableStateOf(0.dp) }

    LocaleAwareDialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false, decorFitsSystemWindows = false)) {
        Box(Modifier.fillMaxSize().background(Ch.Bg)) {
            DorrMap(
                camera, Modifier.fillMaxSize(),
                pins = listOf(MapPin(at, PinKind.Stop, id = "live:${photo.second}", image = photo.first)),
                zones = if (accuracy != null && accuracy > 5) listOf(MapZone(at, accuracy.coerceAtMost(300.0), Ch.Red.copy(alpha = 0.14f))) else emptyList(),
                showMe = true, night = Ch.dark, googleNightJson = com.dorr.app.map.GoogleDarkStyle,
                padding = PaddingValues(bottom = cardHeight),
            )
            // Back.
            RoundMapButton(Icons.AutoMirrored.Rounded.ArrowBack, stringResource(R.string.ch_close_sheet), Modifier.align(Alignment.TopStart).statusBarsPadding().padding(12.dp), onDismiss)
            // Back to them (and follow again).
            RoundMapButton(
                Icons.Rounded.MyLocation, stringResource(R.string.ch_live_recenter),
                Modifier.align(Alignment.BottomEnd).padding(end = 14.dp, bottom = cardHeight + 14.dp),
            ) {
                following = true
                scope.launch { camera.animate(at, 16.5f) }
            }

            // ------------------------------------------------ who · live / ended · last update · directions · stop
            Column(
                Modifier.align(Alignment.BottomCenter).fillMaxWidth()
                    .onSizeChanged { cardHeight = with(density) { it.height.toDp() } }
                    .shadow(12.dp, RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp))
                    .clip(RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp)).background(Ch.Surface)
                    .navigationBarsPadding().padding(start = 18.dp, end = 18.dp, top = 16.dp, bottom = 14.dp),
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    LiveAvatarPin(sender, active, size = 48.dp)
                    Spacer(Modifier.width(14.dp))
                    Column(Modifier.weight(1f)) {
                        Text(
                            if (mine) stringResource(R.string.ch_live_you) else sender?.name.orEmpty(),
                            color = Ch.Ink, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1,
                        )
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Box(Modifier.size(7.dp).clip(CircleShape).background(if (active) Ch.Red else Ch.Mut))
                            Spacer(Modifier.width(6.dp))
                            Text(
                                if (active) liveLeft(until, now) else stringResource(R.string.ch_live_ended_long),
                                color = if (active) Ch.Red else Ch.Mut, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1,
                            )
                        }
                        if (updatedAt != null) Text(updatedLabel(updatedAt, now), color = Ch.Mut, fontSize = 12.sp, maxLines = 1)
                    }
                }
                Spacer(Modifier.height(14.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    ActionButton(
                        Icons.Rounded.Directions, stringResource(R.string.ch_live_directions), filled = true, modifier = Modifier.weight(1f),
                    ) {
                        runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("google.navigation:q=${at.latitude},${at.longitude}"))) }
                            .onFailure { runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("geo:${at.latitude},${at.longitude}?q=${at.latitude},${at.longitude}"))) } }
                    }
                    if (mine && active && m != null) ActionButton(
                        Icons.Rounded.StopCircle, stringResource(R.string.ch_live_stop), filled = false, modifier = Modifier.weight(1f),
                    ) { state.stopLive(m) }
                }
            }
        }
    }
}

@Composable
private fun liveLeft(until: Long, now: Long): String {
    val minutes = ((until - now) / 60_000L).coerceAtLeast(1)
    return if (minutes < 60) stringResource(R.string.ch_live_minutes_left, minutes.toInt())
    else stringResource(R.string.ch_live_until, java.time.Instant.ofEpochMilli(until).atZone(java.time.ZoneId.systemDefault()).toLocalTime().format(java.time.format.DateTimeFormatter.ofPattern("h:mm a")))
}

@Composable
private fun updatedLabel(at: Long, now: Long): String {
    val minutes = ((now - at) / 60_000L).toInt()
    return if (minutes < 1) stringResource(R.string.ch_live_updated_now) else stringResource(R.string.ch_live_updated_ago, minutes)
}

/** A round button on the map (the app's surface, a soft shadow). */
@Composable
private fun RoundMapButton(icon: ImageVector, description: String, modifier: Modifier, onClick: () -> Unit) {
    Box(
        modifier.size(46.dp).shadow(5.dp, CircleShape).clip(CircleShape).background(Ch.Surface).clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(icon, description, tint = Ch.Ink, modifier = Modifier.size(22.dp)) }
}

/** The card's buttons: «Directions» filled in the app's red, «Stop sharing» quiet with red text. */
@Composable
private fun ActionButton(icon: ImageVector, label: String, filled: Boolean, modifier: Modifier, onClick: () -> Unit) {
    Row(
        modifier.clip(RoundedCornerShape(16.dp)).background(if (filled) Ch.Red else Ch.SurfaceMuted).clickable(onClick = onClick).padding(vertical = 13.dp),
        horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = if (filled) Color.White else Ch.Red, modifier = Modifier.size(19.dp))
        Spacer(Modifier.width(7.dp))
        Text(label, color = if (filled) Color.White else Ch.Red, fontSize = 14.5.sp, fontWeight = FontWeight.ExtraBold, maxLines = 1)
    }
}

/**
 * The sharer's photo as a map pin bitmap: a circle in the app's red ring with a white edge — their initial on red
 * until (or when there's no) photo. Second: whether the photo is in (a new key, so the marker is redrawn).
 */
@Composable
private fun rememberPinBitmap(context: Context, sender: ProfileDto?): Pair<Bitmap, Boolean> {
    val red = Ch.Red.toArgb()
    val px = with(LocalDensity.current) { 52.dp.toPx() }.toInt()
    val initial = sender?.name?.trim()?.firstOrNull()?.uppercase() ?: "•"
    var result by remember(sender?.avatar, red) { mutableStateOf(pinBitmap(px, red, null, initial) to false) }
    LaunchedEffect(sender?.avatar, red) {
        val url = sender?.avatar ?: return@LaunchedEffect
        val image = runCatching {
            (chatImages(context).execute(ImageRequest.Builder(context).data(ApiClient.mediaUrl(url)).allowHardware(false).size(px).build()) as? SuccessResult)
                ?.drawable?.let { (it as? android.graphics.drawable.BitmapDrawable)?.bitmap }
        }.getOrNull() ?: return@LaunchedEffect
        result = pinBitmap(px, red, image, initial) to true
    }
    return result
}

private fun pinBitmap(px: Int, ringColor: Int, photo: Bitmap?, initial: String): Bitmap {
    val out = Bitmap.createBitmap(px, px, Bitmap.Config.ARGB_8888)
    val c = Canvas(out)
    val paint = Paint(Paint.ANTI_ALIAS_FLAG)
    val r = px / 2f
    paint.color = ringColor
    c.drawCircle(r, r, r, paint)
    paint.color = android.graphics.Color.WHITE
    val ring = px * 0.07f
    c.drawCircle(r, r, r - ring, paint)
    val inner = r - ring - px * 0.045f
    if (photo != null) {
        val layer = Bitmap.createBitmap(px, px, Bitmap.Config.ARGB_8888)
        val lc = Canvas(layer)
        val p = Paint(Paint.ANTI_ALIAS_FLAG)
        lc.drawCircle(r, r, inner, p)
        p.xfermode = PorterDuffXfermode(PorterDuff.Mode.SRC_IN)
        val side = minOf(photo.width, photo.height)
        val src = Rect((photo.width - side) / 2, (photo.height - side) / 2, (photo.width + side) / 2, (photo.height + side) / 2)
        lc.drawBitmap(photo, src, RectF(r - inner, r - inner, r + inner, r + inner), p)
        c.drawBitmap(layer, 0f, 0f, null)
    } else {
        paint.color = ringColor
        c.drawCircle(r, r, inner, paint)
        paint.color = android.graphics.Color.WHITE
        paint.textSize = inner
        paint.textAlign = Paint.Align.CENTER
        paint.isFakeBoldText = true
        c.drawText(initial, r, r - (paint.descent() + paint.ascent()) / 2, paint)
    }
    return out
}
