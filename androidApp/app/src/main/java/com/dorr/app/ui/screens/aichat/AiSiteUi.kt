package com.dorr.app.ui.screens.aichat

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ArrowBackIosNew
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.compositionLocalOf
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.lerp
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R

/** Whether the AI screens are in dark mode; provided once by [AiSitesScreen] so shared pieces need no parameters. */
internal val LocalSiteNight = compositionLocalOf { false }

internal class SitePalette(night: Boolean) {
    val bg = if (night) Ai.bgDark else Ai.bgLight
    val surface = if (night) Ai.surfaceDark else Ai.surfaceLight
    val ink = if (night) Ai.inkDark else Ai.inkLight
    val mut = if (night) Ai.mutDark else Ai.mutLight
    val line = if (night) Ai.lineDark else Ai.lineLight
    val field = if (night) Color(0xFF111A2B) else Color(0xFFF9FAFB)
    val fieldBorder = if (night) Color(0xFF334155) else Color(0xFFE2E8F0)
    val success = Color(0xFF16A34A)
    val warn = Color(0xFFF59E0B)
    val danger = Color(0xFFDC2626)
}

@Composable
internal fun sitePalette(): SitePalette {
    val night = LocalSiteNight.current
    return remember(night) { SitePalette(night) }
}

/** The brand accent as a soft diagonal gradient (hero cards, primary buttons). */
@Composable
internal fun siteGradient(): Brush = Brush.linearGradient(listOf(Ai.Red, lerp(Ai.Red, Color.Black, 0.38f)))

@Composable
internal fun SiteCard(
    modifier: Modifier = Modifier,
    onClick: (() -> Unit)? = null,
    padding: Dp = 16.dp,
    content: @Composable ColumnScope.() -> Unit,
) {
    val c = sitePalette()
    val shape = RoundedCornerShape(20.dp)
    Column(
        modifier.fillMaxWidth().clip(shape).background(c.surface).border(1.dp, c.line, shape)
            .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier)
            .padding(padding),
        content = content,
    )
}

@Composable
internal fun SiteSectionTitle(title: String, subtitle: String? = null) {
    val c = sitePalette()
    Text(title, color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
    if (!subtitle.isNullOrBlank()) Text(subtitle, color = c.mut, fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
}

@Composable
internal fun SiteTopBar(title: String, onBack: () -> Unit) {
    val c = sitePalette()
    Row(
        Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 16.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            Modifier.size(40.dp).clip(CircleShape).background(c.surface).border(1.dp, c.line, CircleShape).clickable(onClick = onBack),
            contentAlignment = Alignment.Center,
        ) { Icon(Icons.Rounded.ArrowBackIosNew, contentDescription = null, tint = c.ink, modifier = Modifier.size(16.dp)) }
        Spacer(Modifier.width(12.dp))
        Text(title, color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
    }
}

@Composable
internal fun PrimaryButton(label: String, enabled: Boolean = true, onClick: () -> Unit) {
    val shape = RoundedCornerShape(16.dp)
    Box(
        Modifier.fillMaxWidth().height(52.dp).clip(shape)
            .then(if (enabled) Modifier.background(siteGradient()) else Modifier.background(Ai.Red.copy(alpha = 0.35f)))
            .clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Text(label, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 15.sp) }
}

@Composable
internal fun SecondaryButton(label: String, modifier: Modifier = Modifier, color: Color = Ai.Red, enabled: Boolean = true, onClick: () -> Unit) {
    val shape = RoundedCornerShape(16.dp)
    Box(
        modifier.height(52.dp).clip(shape).background(color.copy(alpha = 0.1f)).border(1.dp, color.copy(alpha = 0.35f), shape)
            .clickable(enabled = enabled, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Text(label, color = color, fontWeight = FontWeight.Bold, fontSize = 14.sp) }
}

@Composable
internal fun SiteField(
    label: String,
    value: String,
    onChange: (String) -> Unit,
    singleLine: Boolean = true,
    minLines: Int = 1,
    leadingIcon: ImageVector? = null,
    keyboardType: KeyboardType = KeyboardType.Text,
    helper: String? = null,
) {
    val c = sitePalette()
    OutlinedTextField(
        value = value,
        onValueChange = onChange,
        label = { Text(label, fontSize = 12.5.sp) },
        singleLine = singleLine,
        minLines = minLines,
        shape = RoundedCornerShape(14.dp),
        leadingIcon = leadingIcon?.let { icon -> { Icon(icon, contentDescription = null, tint = c.mut, modifier = Modifier.size(20.dp)) } },
        supportingText = helper?.let { text -> { Text(text, fontSize = 11.5.sp) } },
        keyboardOptions = KeyboardOptions(keyboardType = keyboardType),
        colors = OutlinedTextFieldDefaults.colors(
            focusedTextColor = c.ink,
            unfocusedTextColor = c.ink,
            focusedContainerColor = c.surface,
            unfocusedContainerColor = c.field,
            cursorColor = Ai.Red,
            focusedBorderColor = Ai.Red,
            unfocusedBorderColor = c.fieldBorder,
            focusedLabelColor = Ai.Red,
            unfocusedLabelColor = c.mut,
            focusedSupportingTextColor = c.mut,
            unfocusedSupportingTextColor = c.mut,
        ),
        modifier = Modifier.fillMaxWidth().padding(vertical = 5.dp),
    )
}

@Composable
internal fun SiteStatusChip(status: String, disabled: Boolean, onDark: Boolean = false) {
    val c = sitePalette()
    val color = when {
        disabled || status == "failed" -> c.danger
        status == "ready" -> c.success
        status == "generating" -> c.warn
        else -> c.mut
    }
    val label = if (disabled) stringResource(R.string.ai_sites_disabled) else stringResource(siteStatusLabel(status))
    val fg = if (onDark) Color.White else color
    val tint = if (onDark) Color.White.copy(alpha = 0.2f) else color.copy(alpha = 0.12f)
    Row(
        Modifier.clip(RoundedCornerShape(50)).background(tint).padding(horizontal = 10.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        if (status == "generating" && !disabled) {
            CircularProgressIndicator(color = fg, strokeWidth = 1.5.dp, modifier = Modifier.size(11.dp))
        } else {
            Box(Modifier.size(7.dp).clip(CircleShape).background(fg))
        }
        Text(label, color = fg, fontSize = 11.5.sp, fontWeight = FontWeight.SemiBold)
    }
}

private val AVATARS = listOf(
    Color(0xFF1D4ED8) to Color(0xFF38BDF8),
    Color(0xFF7C3AED) to Color(0xFFEC4899),
    Color(0xFF059669) to Color(0xFF84CC16),
    Color(0xFFEA580C) to Color(0xFFFACC15),
    Color(0xFF0D9488) to Color(0xFF38BDF8),
    Color(0xFFDB2777) to Color(0xFFFB7185),
)

/** Colourful letter tile standing in for a thumbnail of the site. */
@Composable
internal fun SiteAvatar(title: String, seed: Int, size: Dp = 52.dp) {
    val (a, b) = AVATARS[Math.floorMod(seed, AVATARS.size)]
    Box(
        Modifier.size(size).clip(RoundedCornerShape(16.dp)).background(Brush.linearGradient(listOf(a, b))),
        contentAlignment = Alignment.Center,
    ) {
        Text(title.trim().take(1).uppercase(), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = (size.value * 0.42f).sp)
    }
}
