package com.dorr.app.ui.screens.profile

import android.graphics.Color as AndroidColor
import android.widget.Toast
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.gestures.drag
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.DarkMode
import androidx.compose.material.icons.rounded.LightMode
import androidx.compose.material.icons.rounded.SettingsBrightness
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.toArgb
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AppearanceDto
import com.dorr.app.network.AppearanceUpdateRequest
import com.dorr.app.network.AuthSession
import com.dorr.app.network.serverMessage
import com.dorr.app.ui.theme.LocalAppearance
import com.dorr.app.ui.theme.hexToColor
import kotlinx.coroutines.launch

@Composable
fun AppearanceScreen(onBack: () -> Unit) {
    val appearance = LocalAppearance.current
    val snap = appearance.snapshot
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var busy by remember { mutableStateOf(false) }
    val lightStored = snap?.takeIf { it.usesDefaultColors == false }?.customLightTokens
    val darkStored = snap?.takeIf { it.usesDefaultColors == false }?.customDarkTokens
    val darkChosen = !darkStored.isNullOrEmpty() && !samePalette(lightStored, darkStored)
    var lightPrimary by remember(snap) { mutableStateOf(pickedOrDefault(lightStored, snap, "primary", night = false, "#E50914")) }
    var lightBackground by remember(snap) { mutableStateOf(pickedOrDefault(lightStored, snap, "background", night = false, "#FFFFFF")) }
    var lightText by remember(snap) { mutableStateOf(pickedOrDefault(lightStored, snap, "textPrimary", night = false, "#111928")) }
    var darkPrimary by remember(snap) { mutableStateOf(pickedOrDefault(if (darkChosen) darkStored else null, snap, "primary", night = true, "#FF4D57")) }
    var darkBackground by remember(snap) { mutableStateOf(pickedOrDefault(if (darkChosen) darkStored else null, snap, "background", night = true, "#101216")) }
    var darkText by remember(snap) { mutableStateOf(pickedOrDefault(if (darkChosen) darkStored else null, snap, "textPrimary", night = true, "#F4F5F7")) }
    var lightEdited by remember(snap) { mutableStateOf(!lightStored.isNullOrEmpty()) }
    var darkEdited by remember(snap) { mutableStateOf(darkChosen) }
    val saved = stringResource(R.string.appearance_saved)
    val failed = stringResource(R.string.appearance_failed)
    val mode = snap?.darkMode ?: "system"
    val usingDefaults = snap?.usesDefaultColors != false

    fun push(body: AppearanceUpdateRequest, onDone: (() -> Unit)? = null) {
        val token = AuthSession.token
        if (token.isNullOrBlank() || busy) return
        busy = true
        scope.launch {
            runCatching {
                ApiClient.appearance.update("Bearer $token", body).data
            }.onSuccess { dto ->
                if (dto != null && AuthSession.token == token) {
                    appearance.apply(dto)
                    onDone?.invoke()
                }
            }.onFailure { error ->
                Toast.makeText(context, error.serverMessage() ?: failed, Toast.LENGTH_SHORT).show()
            }
            busy = false
        }
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(R.string.appearance_title), onBack)
            Column(
                modifier = Modifier
                    .verticalScroll(rememberScrollState())
                    .padding(horizontal = 14.dp)
                    .padding(bottom = 24.dp),
            ) {
                Text(stringResource(R.string.appearance_mode), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 15.sp)
                Spacer(Modifier.height(8.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    ModeChip(stringResource(R.string.appearance_mode_system), Icons.Rounded.SettingsBrightness, mode == "system", Modifier.weight(1f)) {
                        push(AppearanceUpdateRequest(darkMode = "system"))
                    }
                    ModeChip(stringResource(R.string.appearance_mode_light), Icons.Rounded.LightMode, mode == "light", Modifier.weight(1f)) {
                        push(AppearanceUpdateRequest(darkMode = "light"))
                    }
                    ModeChip(stringResource(R.string.appearance_mode_dark), Icons.Rounded.DarkMode, mode == "dark", Modifier.weight(1f)) {
                        push(AppearanceUpdateRequest(darkMode = "dark"))
                    }
                }
                Spacer(Modifier.height(8.dp))
                Text(
                    if (usingDefaults) stringResource(R.string.appearance_using_defaults) else stringResource(R.string.appearance_custom),
                    color = settingsMut(),
                    fontSize = 13.sp,
                )
                Spacer(Modifier.height(18.dp))
                val deviceNight = settingsNight()
                val showLight = mode == "light" || mode == "system"
                val showDark = mode == "dark" || mode == "system"
                val darkFirst = mode == "system" && deviceNight

                @Composable
                fun LightColorsBlock() {
                    Text(stringResource(R.string.appearance_light_colors), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 15.sp)
                    Spacer(Modifier.height(12.dp))
                    ColorField(stringResource(R.string.appearance_primary), lightPrimary) {
                        lightPrimary = it
                        lightEdited = true
                    }
                    Spacer(Modifier.height(12.dp))
                    ColorField(stringResource(R.string.appearance_background), lightBackground) {
                        lightBackground = it
                        lightEdited = true
                    }
                    Spacer(Modifier.height(12.dp))
                    ColorField(stringResource(R.string.appearance_text), lightText) {
                        lightText = it
                        lightEdited = true
                    }
                }

                @Composable
                fun DarkColorsBlock() {
                    Text(stringResource(R.string.appearance_dark_colors), color = settingsInk(), fontWeight = FontWeight.Bold, fontSize = 15.sp)
                    Spacer(Modifier.height(12.dp))
                    ColorField(stringResource(R.string.appearance_primary), darkPrimary) {
                        darkPrimary = it
                        darkEdited = true
                    }
                    Spacer(Modifier.height(12.dp))
                    ColorField(stringResource(R.string.appearance_background), darkBackground) {
                        darkBackground = it
                        darkEdited = true
                    }
                    Spacer(Modifier.height(12.dp))
                    ColorField(stringResource(R.string.appearance_text), darkText) {
                        darkText = it
                        darkEdited = true
                    }
                }

                when {
                    showLight && showDark && darkFirst -> {
                        DarkColorsBlock()
                        Spacer(Modifier.height(18.dp))
                        LightColorsBlock()
                    }
                    showLight && showDark -> {
                        LightColorsBlock()
                        Spacer(Modifier.height(18.dp))
                        DarkColorsBlock()
                    }
                    showLight -> LightColorsBlock()
                    showDark -> DarkColorsBlock()
                }
                if (showLight && showDark) {
                    Spacer(Modifier.height(8.dp))
                    Text(stringResource(R.string.appearance_separate_modes), color = settingsMut(), fontSize = 12.sp)
                }
                Spacer(Modifier.height(16.dp))
                val shape = RoundedCornerShape(14.dp)
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .clip(shape)
                        .background(settingsAccent())
                        .clickable(enabled = !busy) {
                            push(
                                AppearanceUpdateRequest(
                                    usesDefaultColors = when {
                                        lightEdited || darkEdited -> false
                                        else -> null
                                    },
                                    customLightTokens = if (lightEdited) {
                                        palette(lightPrimary, lightBackground, lightText)
                                    } else {
                                        null
                                    },
                                    customDarkTokens = if (darkEdited) {
                                        palette(darkPrimary, darkBackground, darkText)
                                    } else {
                                        null
                                    },
                                ),
                            ) {
                                Toast.makeText(context, saved, Toast.LENGTH_SHORT).show()
                            }
                        }
                        .padding(vertical = 14.dp),
                    contentAlignment = Alignment.Center,
                ) {
                    if (busy) {
                        CircularProgressIndicator(color = Color.White, modifier = Modifier.size(18.dp), strokeWidth = 2.dp)
                    } else {
                        Text(stringResource(R.string.appearance_save), color = Color.White, fontWeight = FontWeight.Bold)
                    }
                }
                if (!usingDefaults) {
                    Spacer(Modifier.height(10.dp))
                    Box(
                        modifier = Modifier
                            .fillMaxWidth()
                            .clip(shape)
                            .settingsSurface(shape)
                            .clickable(enabled = !busy) {
                                push(AppearanceUpdateRequest(usesDefaultColors = true)) {
                                    Toast.makeText(context, saved, Toast.LENGTH_SHORT).show()
                                }
                            }
                            .padding(vertical = 14.dp),
                        contentAlignment = Alignment.Center,
                    ) {
                        Text(stringResource(R.string.appearance_reset), color = settingsAccent(), fontWeight = FontWeight.Bold)
                    }
                }
            }
        }
    }
}

@Composable
private fun ModeChip(
    label: String,
    icon: ImageVector,
    selected: Boolean,
    modifier: Modifier = Modifier,
    onClick: () -> Unit,
) {
    val shape = RoundedCornerShape(12.dp)
    val tint = if (selected) Color.White else settingsInk()
    Box(
        modifier = modifier
            .clip(shape)
            .then(
                if (selected) Modifier.background(settingsAccent())
                else Modifier.settingsSurface(shape),
            )
            .clickable(onClick = onClick)
            .padding(horizontal = 6.dp, vertical = 12.dp),
        contentAlignment = Alignment.Center,
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.Center) {
            Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(16.dp))
            Spacer(Modifier.width(4.dp))
            Text(label, color = tint, fontSize = 12.sp, fontWeight = FontWeight.Bold, maxLines = 1)
        }
    }
}

@Composable
private fun ColorField(label: String, value: String, onValueChange: (String) -> Unit) {
    var open by remember { mutableStateOf(false) }
    val swatch = hexToColor(value) ?: Color(0xFF001B53)
    val night = settingsNight()
    val shape = RoundedCornerShape(999.dp)

    Text(label, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = settingsInk(), modifier = Modifier.padding(bottom = 8.dp))
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .height(44.dp)
            .clip(shape)
            .background(if (night) AccountDark.bg else Color(0xFFFBF7F8))
            .border(1.dp, if (night) AccountDark.line else Color(0xFFF3D5DB), shape)
            .clickable { open = true }
            .padding(horizontal = 14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            Modifier
                .size(22.dp)
                .clip(CircleShape)
                .background(swatch)
                .border(1.dp, if (night) AccountDark.line else Color(0xFFE5E7EB), CircleShape),
        )
    }
    if (open) {
        ColorPickerDialog(
            initial = value,
            onConfirm = {
                onValueChange(it)
                open = false
            },
            onDismiss = { open = false },
        )
    }
}

@Composable
private fun ColorPickerDialog(initial: String, onConfirm: (String) -> Unit, onDismiss: () -> Unit) {
    val start = colorToHsv(hexToColor(initial) ?: Color(0xFF001B53))
    var hue by remember { mutableStateOf(start[0]) }
    var sat by remember { mutableStateOf(start[1]) }
    var value by remember { mutableStateOf(start[2]) }
    val color = hsvColor(hue, sat, value)
    val rgb = color.toRgb()

    Dialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(Color.Black.copy(alpha = 0.45f))
                .clickable(onClick = onDismiss),
            contentAlignment = Alignment.Center,
        ) {
            Column(
                modifier = Modifier
                    .padding(horizontal = 24.dp)
                    .widthIn(max = 340.dp)
                    .fillMaxWidth()
                    .clip(RoundedCornerShape(16.dp))
                    .shadow(16.dp, RoundedCornerShape(16.dp))
                    .background(settingsCard())
                    .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClick = {})
                    .padding(12.dp),
            ) {
                SaturationPlane(hue, sat, value) { s, v ->
                    sat = s
                    value = v
                }
                Spacer(Modifier.height(12.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(
                        Modifier
                            .size(36.dp)
                            .clip(CircleShape)
                            .background(color)
                            .border(1.dp, Color(0xFFE5E7EB), CircleShape),
                    )
                    Spacer(Modifier.width(12.dp))
                    HueSlider(hue, { hue = it }, Modifier.weight(1f))
                }
                Spacer(Modifier.height(12.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    RgbBox("R", rgb[0], Modifier.weight(1f)) { channel ->
                        val next = hsvColor(hue, sat, value).toRgb()
                        next[0] = channel
                        val picked = colorToHsv(rgbColor(next))
                        hue = picked[0]
                        sat = picked[1]
                        value = picked[2]
                    }
                    RgbBox("G", rgb[1], Modifier.weight(1f)) { channel ->
                        val next = hsvColor(hue, sat, value).toRgb()
                        next[1] = channel
                        val picked = colorToHsv(rgbColor(next))
                        hue = picked[0]
                        sat = picked[1]
                        value = picked[2]
                    }
                    RgbBox("B", rgb[2], Modifier.weight(1f)) { channel ->
                        val next = hsvColor(hue, sat, value).toRgb()
                        next[2] = channel
                        val picked = colorToHsv(rgbColor(next))
                        hue = picked[0]
                        sat = picked[1]
                        value = picked[2]
                    }
                }
                Spacer(Modifier.height(12.dp))
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(44.dp)
                        .clip(RoundedCornerShape(12.dp))
                        .background(color)
                        .clickable { onConfirm(hsvToHex(hue, sat, value)) },
                    contentAlignment = Alignment.Center,
                ) {
                    Text(stringResource(R.string.common_save), color = Color.White, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

@Composable
private fun SaturationPlane(hue: Float, saturation: Float, value: Float, onChange: (Float, Float) -> Unit) {
    val current = rememberUpdatedState(onChange)
    val hueNow = rememberUpdatedState(hue)
    Box(
        Modifier
            .fillMaxWidth()
            .height(160.dp)
            .clip(RoundedCornerShape(8.dp))
            .pointerInput(Unit) {
                awaitEachGesture {
                    val down = awaitFirstDown()
                    down.consume()
                    fun emit(pos: Offset) {
                        val s = (pos.x / size.width).coerceIn(0f, 1f)
                        val v = (1f - pos.y / size.height).coerceIn(0f, 1f)
                        current.value(s, v)
                    }
                    emit(down.position)
                    drag(down.id) { change ->
                        emit(change.position)
                        change.consume()
                    }
                }
            },
    ) {
        Canvas(Modifier.fillMaxSize()) {
            val pure = Color(AndroidColor.HSVToColor(floatArrayOf(hueNow.value, 1f, 1f)))
            drawRect(Brush.horizontalGradient(listOf(Color.White, pure)))
            drawRect(Brush.verticalGradient(listOf(Color.Transparent, Color.Black)))
            val center = Offset(saturation * size.width, (1f - value) * size.height)
            drawCircle(Color.White, 11.dp.toPx(), center, style = androidx.compose.ui.graphics.drawscope.Stroke(2.dp.toPx()))
            drawCircle(Color.Black.copy(alpha = 0.35f), 11.dp.toPx(), center, style = androidx.compose.ui.graphics.drawscope.Stroke(1.dp.toPx()))
        }
    }
}

@Composable
private fun HueSlider(hue: Float, onChange: (Float) -> Unit, modifier: Modifier = Modifier) {
    val current = rememberUpdatedState(onChange)
    Box(
        modifier
            .height(18.dp)
            .clip(CircleShape)
            .pointerInput(Unit) {
                awaitEachGesture {
                    val down = awaitFirstDown()
                    down.consume()
                    fun emit(x: Float) {
                        current.value((x / size.width).coerceIn(0f, 1f) * 360f)
                    }
                    emit(down.position.x)
                    drag(down.id) { change ->
                        emit(change.position.x)
                        change.consume()
                    }
                }
            },
    ) {
        Canvas(Modifier.fillMaxSize()) {
            drawRect(
                Brush.horizontalGradient(
                    listOf(0f, 60f, 120f, 180f, 240f, 300f, 360f).map { degrees ->
                        Color(AndroidColor.HSVToColor(floatArrayOf(degrees, 1f, 1f)))
                    },
                ),
            )
            val center = Offset((hue / 360f).coerceIn(0f, 1f) * size.width, size.height / 2f)
            drawCircle(Color.White, size.height / 2f, center)
            drawCircle(Color(AndroidColor.HSVToColor(floatArrayOf(hue, 1f, 1f))), size.height / 2f - 3.dp.toPx(), center)
        }
    }
}

@Composable
private fun RgbBox(label: String, channel: Int, modifier: Modifier, onChannel: (Int) -> Unit) {
    var text by remember { mutableStateOf(channel.toString()) }
    var focused by remember { mutableStateOf(false) }
    LaunchedEffect(channel, focused) {
        if (!focused) text = channel.toString()
    }
    Column(modifier, horizontalAlignment = Alignment.CenterHorizontally) {
        BasicTextField(
            value = text,
            onValueChange = { raw ->
                val digits = raw.filter { it.isDigit() }.take(3)
                text = digits
                digits.toIntOrNull()?.coerceIn(0, 255)?.let(onChannel)
            },
            singleLine = true,
            textStyle = TextStyle(color = settingsInk(), fontSize = 16.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
            cursorBrush = SolidColor(settingsAccent()),
            modifier = Modifier
                .fillMaxWidth()
                .height(40.dp)
                .clip(RoundedCornerShape(8.dp))
                .border(1.dp, if (settingsNight()) AccountDark.line else Color(0xFFE5E7EB), RoundedCornerShape(8.dp))
                .padding(vertical = 8.dp)
                .onFocusChanged { focused = it.isFocused },
        )
        Text(label, color = settingsMut(), fontSize = 12.sp)
    }
}

private fun palette(primary: String, background: String, text: String): Map<String, String> = mapOf(
    "primary" to primary,
    "background" to background,
    "textPrimary" to text,
)

private fun pickedOrDefault(
    custom: Map<String, String>?,
    snap: AppearanceDto?,
    key: String,
    night: Boolean,
    fallback: String,
): String {
    val picked = custom?.get(key)?.let(::normalizeBrandHex)
    if (picked != null) return picked
    val defaults = if (night) snap?.default?.darkTokens?.get(key) else snap?.default?.lightTokens?.get(key)
    return normalizeBrandHex(defaults ?: fallback) ?: fallback
}

private fun samePalette(light: Map<String, String>?, dark: Map<String, String>?): Boolean {
    if (light.isNullOrEmpty() || dark.isNullOrEmpty()) return false
    return listOf("primary", "background", "textPrimary").all { key ->
        val left = light[key]?.let(::normalizeBrandHex)
        val right = dark[key]?.let(::normalizeBrandHex)
        left != null && left == right
    }
}

private fun brandHex(snap: AppearanceDto?, key: String, night: Boolean, fallback: String): String {
    val custom = if (night) snap?.customDarkTokens?.get(key) else snap?.customLightTokens?.get(key)
    val resolved = if (night) snap?.resolved?.darkTokens?.get(key) else snap?.resolved?.lightTokens?.get(key)
    val raw = if (snap?.usesDefaultColors == false) custom ?: resolved else resolved
    return normalizeBrandHex(raw ?: fallback) ?: fallback
}

private fun normalizeBrandHex(raw: String): String? {
    val hex = raw.trim().removePrefix("#")
    if (hex.length != 6 || hex.any { it !in '0'..'9' && it !in 'a'..'f' && it !in 'A'..'F' }) return null
    return "#${hex.uppercase()}"
}

private fun colorToHsv(color: Color): FloatArray {
    val hsv = FloatArray(3)
    AndroidColor.colorToHSV(color.toArgb(), hsv)
    return hsv
}

private fun hsvColor(hue: Float, saturation: Float, value: Float): Color =
    Color(AndroidColor.HSVToColor(floatArrayOf(hue.coerceIn(0f, 360f), saturation.coerceIn(0f, 1f), value.coerceIn(0f, 1f))))

private fun hsvToHex(hue: Float, saturation: Float, value: Float): String {
    val argb = AndroidColor.HSVToColor(
        floatArrayOf(hue.coerceIn(0f, 360f), saturation.coerceIn(0f, 1f), value.coerceIn(0f, 1f)),
    )
    return "#%06X".format(argb and 0xFFFFFF)
}

private fun Color.toRgb(): IntArray = intArrayOf(
    (red * 255f).toInt().coerceIn(0, 255),
    (green * 255f).toInt().coerceIn(0, 255),
    (blue * 255f).toInt().coerceIn(0, 255),
)

private fun rgbColor(channels: IntArray): Color =
    Color(channels[0] / 255f, channels[1] / 255f, channels[2] / 255f)
