package com.dorr.app.ui.screens.profile

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.isSystemInDarkTheme
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
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.LocalOffer
import androidx.compose.material.icons.rounded.Notifications
import androidx.compose.material.icons.rounded.Receipt
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.remember
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
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.theme.AppColors
import com.dorr.app.ui.theme.LocalAppearance
import com.dorr.app.ui.theme.LocalThemeState
import com.dorr.app.ui.theme.appearanceColor

private data class NotifToggle(val title: Int, val desc: Int, val icon: ImageVector, val startsOn: Boolean)

private val toggles = listOf(
    NotifToggle(R.string.notif_push_title, R.string.notif_push_desc, Icons.Rounded.Notifications, true),
    NotifToggle(R.string.notif_updates_title, R.string.notif_updates_desc, Icons.Rounded.Receipt, true),
    NotifToggle(R.string.notif_promo_title, R.string.notif_promo_desc, Icons.Rounded.LocalOffer, true),
    NotifToggle(R.string.notif_email_title, R.string.notif_email_desc, Icons.Rounded.Email, false),
)

@Composable
fun NotificationSettingsScreen(onBack: () -> Unit) {
    val states = remember { mutableStateListOf(*toggles.map { it.startsOn }.toTypedArray()) }
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
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(horizontal = 4.dp, vertical = 12.dp),
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

@Composable
private fun RedToggle(on: Boolean, dark: Boolean, onClick: () -> Unit) {
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

@Composable
internal fun settingsNight(): Boolean = LocalThemeState.current.isDark ?: isSystemInDarkTheme()

@Composable
internal fun settingsInk(): Color = if (settingsNight()) AccountDark.ink else appearanceColor("textPrimary", AppColors.textPrimary, night = false)

@Composable
internal fun settingsMut(): Color = if (settingsNight()) AccountDark.mut else appearanceColor("textMuted", AppColors.textMuted, night = false)

@Composable
internal fun settingsAccent(): Color = if (settingsNight()) {
    AccountDark.accent
} else {
    appearanceColor("primary", AppColors.waRed, night = false)
}

/** The card fill behind a settings row, matching [Modifier.settingsSurface] without its shape. */
@Composable
internal fun settingsCard(): Color = if (settingsNight()) AccountDark.card else appearanceColor("surface", Color.White, night = false)

@Composable
internal fun settingsBackground(): Color =
    if (settingsNight()) AccountDark.bg else appearanceColor("background", Color.White, night = false)

@Composable
internal fun Modifier.settingsSurface(shape: RoundedCornerShape, elevation: androidx.compose.ui.unit.Dp = 6.dp): Modifier {
    val dark = settingsNight()
    return if (dark) {
        this.clip(shape).background(AccountDark.card).border(1.dp, AccountDark.line, shape)
    } else {
        this.shadow(elevation, shape, ambientColor = Color(0x12E50914), spotColor = Color(0x12E50914))
            .clip(shape)
            .background(appearanceColor("surface", Color.White, night = false))
    }
}

@Composable
internal fun SubHeader(title: String, onBack: () -> Unit) {
    val dark = settingsNight()
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 14.dp)
            .padding(top = 14.dp, bottom = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            title,
            color = settingsAccent(),
            fontSize = 22.sp,
            fontWeight = FontWeight.ExtraBold,
            modifier = Modifier.weight(1f),
        )
        Box(
            modifier = Modifier
                .size(34.dp)
                .then(
                    if (dark) Modifier
                    else Modifier.shadow(6.dp, CircleShape, ambientColor = Color(0x14E50914), spotColor = Color(0x14E50914)),
                )
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
                modifier = Modifier.size(16.dp).graphicsLayer { scaleX = -1f },
            )
        }
    }
}

@Composable
internal fun PinkIcon(icon: ImageVector, box: androidx.compose.ui.unit.Dp = 34.dp) {
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
