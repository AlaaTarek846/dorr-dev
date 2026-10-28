package com.dorr.freelance.ui.kit

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.R
import com.dorr.freelance.ui.theme.AppColors
import com.dorr.freelance.ui.theme.CairoFontFamily
import com.dorr.freelance.ui.theme.LocalThemeState
import com.dorr.freelance.ui.theme.appearanceColor

/*
 * The DORR app's design kit (androidApp: ProfileScreen.AccountDark + profile/NotificationSettingsScreen's helpers), under
 * the same names so a screen ported from there needs only its imports changed. Every colour follows the appearance
 * tokens in light and dark mode.
 */

/** The dark palette: the dark appearance tokens once loaded, the app chrome otherwise. */
internal object AccountDark {
    private val fallbackBg = Color(0xFF101216)
    private val fallbackCard = Color(0xFF1A1D24)
    private val fallbackWell = Color(0xFF2C2226)
    private val fallbackLine = Color(0xFF2C313A)
    private val fallbackInk = Color(0xFFF4F5F7)
    private val fallbackMut = Color(0xFF9AA1AC)
    private val fallbackAccent = Color(0xFFFA7552)
    private val fallbackChevron = Color(0xFF8B93A0)

    val bg: Color @Composable get() = appearanceColor("background", fallbackBg, night = true)
    val card: Color @Composable get() = appearanceColor("surface", fallbackCard, night = true)
    val well: Color @Composable get() = fallbackWell
    val line: Color @Composable get() = appearanceColor("border", fallbackLine, night = true)
    val ink: Color @Composable get() = appearanceColor("textPrimary", fallbackInk, night = true)
    val mut: Color @Composable get() = appearanceColor("textSecondary", fallbackMut, night = true)
    val accent: Color @Composable get() = appearanceColor("primary", fallbackAccent, night = true)
    val chevron: Color @Composable get() = fallbackChevron
}

@Composable
internal fun settingsNight(): Boolean = LocalThemeState.current.isDark ?: isSystemInDarkTheme()

@Composable
internal fun settingsInk(): Color = if (settingsNight()) AccountDark.ink else appearanceColor("textPrimary", AppColors.textPrimary, night = false)

@Composable
internal fun settingsMut(): Color = if (settingsNight()) AccountDark.mut else appearanceColor("textMuted", AppColors.textMuted, night = false)

@Composable
internal fun settingsAccent(): Color = if (settingsNight()) AccountDark.accent else appearanceColor("primary", AppColors.waRed, night = false)

/** The card fill behind a row, matching [settingsSurface] without its shape. */
@Composable
internal fun settingsCard(): Color = if (settingsNight()) AccountDark.card else appearanceColor("surface", Color.White, night = false)

@Composable
internal fun settingsBackground(): Color = if (settingsNight()) AccountDark.bg else appearanceColor("background", Color.White, night = false)

/** A card: a soft shadow by day, a hairline at night. */
@Composable
internal fun Modifier.settingsSurface(shape: RoundedCornerShape, elevation: Dp = 6.dp): Modifier {
    val dark = settingsNight()
    return if (dark) {
        this.clip(shape).background(AccountDark.card).border(1.dp, AccountDark.line, shape)
    } else {
        this.shadow(elevation, shape, ambientColor = Color(0x12001B53), spotColor = Color(0x12001B53))
            .clip(shape)
            .background(appearanceColor("surface", Color.White, night = false))
    }
}

@Composable
internal fun SettingsScreenTitle(title: String, modifier: Modifier = Modifier) {
    Text(
        title,
        color = settingsAccent(),
        fontSize = 22.sp,
        fontWeight = FontWeight.Black,
        fontFamily = CairoFontFamily,
        maxLines = 2,
        overflow = TextOverflow.Ellipsis,
        modifier = modifier,
    )
}

/** A page header: the title and a round back button. */
@Composable
internal fun SubHeader(title: String, onBack: () -> Unit) {
    val dark = settingsNight()
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 14.dp)
            .padding(top = 14.dp, bottom = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        SettingsScreenTitle(title, Modifier.weight(1f))
        Box(
            modifier = Modifier
                .size(34.dp)
                .then(if (dark) Modifier else Modifier.shadow(6.dp, CircleShape, ambientColor = Color(0x14001B53), spotColor = Color(0x14001B53)))
                .clip(CircleShape)
                .background(settingsCard())
                .then(if (dark) Modifier.border(1.dp, AccountDark.line, CircleShape) else Modifier)
                .clickable(onClick = onBack),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.AutoMirrored.Rounded.ArrowBack,
                contentDescription = stringResource(R.string.common_back),
                tint = settingsAccent(),
                // Same arrow as the DORR app's headers.
                modifier = Modifier.size(16.dp).graphicsLayer { scaleX = -1f },
            )
        }
    }
}

/** An icon on a soft tile of the app colour. */
@Composable
internal fun PinkIcon(icon: ImageVector, box: Dp = 34.dp) {
    Box(
        modifier = Modifier
            .size(box)
            .clip(RoundedCornerShape(10.dp))
            .background(if (settingsNight()) AccountDark.well else settingsAccent().copy(alpha = 0.14f)),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
    }
}

/** The page background: the base colour with a soft glow of the app colour from the top. */
@Composable
internal fun PinkBackdrop(modifier: Modifier = Modifier) {
    val dark = settingsNight()
    val base = if (dark) AccountDark.bg else appearanceColor("background", Color.White, night = false)
    val glow = if (dark) AccountDark.accent else settingsAccent()
    Canvas(modifier) {
        drawRect(base)
        drawCircle(
            brush = Brush.radialGradient(
                colors = listOf(glow.copy(alpha = 0.35f), glow.copy(alpha = 0.10f), Color.Transparent),
                center = Offset(size.width * 0.5f, size.height * -0.08f),
                radius = size.width * 0.85f,
            ),
            radius = size.width * 0.85f,
            center = Offset(size.width * 0.5f, size.height * -0.08f),
        )
        drawCircle(
            brush = Brush.radialGradient(
                colors = listOf(glow.copy(alpha = 0.20f), Color.Transparent),
                center = Offset(size.width * 1.05f, size.height * 0.02f),
                radius = size.width * 0.55f,
            ),
            radius = size.width * 0.55f,
            center = Offset(size.width * 1.05f, size.height * 0.02f),
        )
    }
}

/** The main button: full width, the app colour, a spinner while [loading]. */
@Composable
internal fun SettingsPrimaryButton(
    text: String,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    loading: Boolean = false,
    onClick: () -> Unit,
) {
    Button(
        onClick = onClick,
        enabled = enabled && !loading,
        colors = ButtonDefaults.buttonColors(
            containerColor = settingsAccent(),
            contentColor = Color.White,
            disabledContainerColor = settingsAccent().copy(alpha = 0.55f),
            disabledContentColor = Color.White,
        ),
        shape = RoundedCornerShape(18.dp),
        modifier = modifier
            .fillMaxWidth()
            .height(54.dp)
            .then(if (settingsNight()) Modifier else Modifier.shadow(12.dp, RoundedCornerShape(18.dp), spotColor = settingsAccent().copy(alpha = 0.38f))),
    ) {
        if (loading) {
            CircularProgressIndicator(color = Color.White, strokeWidth = 2.dp, modifier = Modifier.size(22.dp))
        } else {
            Text(text, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, color = Color.White)
        }
    }
}

/** The on / off switch of the DORR app (its settings and wallet use the same one). */
@Composable
internal fun RedToggle(on: Boolean, dark: Boolean = settingsNight(), onClick: () -> Unit) {
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Box(
            modifier = Modifier
                .size(width = 42.dp, height = 24.dp)
                .clip(RoundedCornerShape(12.dp))
                .background(if (on) settingsAccent() else if (dark) Color(0xFF3A3F48) else Color(0xFFE5E7EB))
                .clickable(onClick = onClick),
        ) {
            Box(
                modifier = Modifier
                    .padding(2.dp)
                    .size(20.dp)
                    .offset(x = if (on) 18.dp else 0.dp)
                    .shadow(1.dp, CircleShape)
                    .clip(CircleShape)
                    .background(Color.White),
            )
        }
    }
}
