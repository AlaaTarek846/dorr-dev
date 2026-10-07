package com.dorr.app.ui.screens.aichat

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AddPhotoAlternate
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.ui.screens.chat.LocalFile

/** Ready colour pairs (main, secondary) - the customer taps one instead of typing hex codes. */
private val PALETTES = listOf(
    "#1D4ED8" to "#0EA5E9",
    "#059669" to "#84CC16",
    "#DC2626" to "#F97316",
    "#7C3AED" to "#EC4899",
    "#EA580C" to "#FACC15",
    "#111827" to "#D4AF37",
    "#0D9488" to "#38BDF8",
    "#DB2777" to "#FB7185",
)

private val SWATCHES = listOf(
    "#1D4ED8", "#0EA5E9", "#0D9488", "#059669", "#84CC16", "#FACC15",
    "#EA580C", "#DC2626", "#DB2777", "#7C3AED", "#111827", "#D4AF37",
)

private fun hex(value: String): Color = runCatching { Color(android.graphics.Color.parseColor(value)) }.getOrDefault(Color.Gray)

@Composable
private fun Swatch(color: String, selected: Boolean, size: Int = 34, onClick: () -> Unit) {
    Box(
        Modifier.size(size.dp).clip(CircleShape).background(hex(color))
            .border(BorderStroke(if (selected) 3.dp else 1.dp, if (selected) Ai.Red else Color.Black.copy(alpha = 0.12f)), CircleShape)
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        if (selected) Icon(Icons.Rounded.Check, contentDescription = null, tint = Color.White, modifier = Modifier.size(18.dp))
    }
}

/** Palette presets + two swatch rows for the main and secondary colour; "automatic" lets the AI decide. */
@Composable
internal fun SiteColorSection(
    ink: Color,
    mut: Color,
    surface: Color,
    primary: String,
    secondary: String,
    onChange: (primary: String, secondary: String) -> Unit,
) {
    Column(Modifier.fillMaxWidth().padding(top = 10.dp)) {
        Text(stringResource(R.string.ai_sites_f_colors), color = ink, fontWeight = FontWeight.Bold, fontSize = 13.sp)
        Text(stringResource(R.string.ai_sites_f_colors_hint), color = mut, fontSize = 11.5.sp)
        Spacer(Modifier.height(8.dp))

        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Box(
                Modifier.clip(RoundedCornerShape(12.dp))
                    .background(if (primary.isBlank() && secondary.isBlank()) Ai.Red.copy(alpha = 0.15f) else surface)
                    .clickable { onChange("", "") }.padding(horizontal = 12.dp, vertical = 10.dp),
                contentAlignment = Alignment.Center,
            ) { Text(stringResource(R.string.ai_sites_f_colors_auto), color = ink, fontSize = 12.sp, fontWeight = FontWeight.SemiBold) }

            PALETTES.forEach { (a, b) ->
                val on = primary.equals(a, true) && secondary.equals(b, true)
                Row(
                    Modifier.clip(RoundedCornerShape(12.dp)).background(if (on) Ai.Red.copy(alpha = 0.15f) else surface)
                        .border(BorderStroke(if (on) 2.dp else 0.dp, if (on) Ai.Red else Color.Transparent), RoundedCornerShape(12.dp))
                        .clickable { onChange(a, b) }.padding(horizontal = 10.dp, vertical = 8.dp),
                    horizontalArrangement = Arrangement.spacedBy((-8).dp),
                ) {
                    Box(Modifier.size(26.dp).clip(CircleShape).background(hex(a)).border(2.dp, Color.White, CircleShape))
                    Box(Modifier.size(26.dp).clip(CircleShape).background(hex(b)).border(2.dp, Color.White, CircleShape))
                }
            }
        }

        Spacer(Modifier.height(12.dp))
        Text(stringResource(R.string.ai_sites_f_color_main), color = mut, fontSize = 12.sp)
        Row(Modifier.horizontalScroll(rememberScrollState()).padding(vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            SWATCHES.forEach { c -> Swatch(c, primary.equals(c, true)) { onChange(c, secondary) } }
        }
        Text(stringResource(R.string.ai_sites_f_color_second), color = mut, fontSize = 12.sp)
        Row(Modifier.horizontalScroll(rememberScrollState()).padding(vertical = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            SWATCHES.forEach { c -> Swatch(c, secondary.equals(c, true)) { onChange(primary, c) } }
        }
    }
}

private fun Modifier.dashedBorder(color: Color): Modifier = drawBehind {
    drawRoundRect(
        color = color,
        cornerRadius = CornerRadius(16.dp.toPx()),
        style = Stroke(width = 1.5.dp.toPx(), pathEffect = PathEffect.dashPathEffect(floatArrayOf(18f, 12f))),
    )
}

@Composable
private fun RemoveBadge(onClick: () -> Unit, modifier: Modifier = Modifier) {
    Box(
        modifier.size(22.dp).clip(CircleShape).background(Color.Black.copy(alpha = 0.65f)).clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) { Icon(Icons.Rounded.Close, contentDescription = null, tint = Color.White, modifier = Modifier.size(14.dp)) }
}

/** Logo: dashed drop-zone card that turns into a preview with a remove button once a file is chosen. */
@Composable
internal fun SiteLogoCard(ink: Color, mut: Color, surface: Color, logo: LocalFile?, onPick: () -> Unit, onClear: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(surface).dashedBorder(Ai.Red.copy(alpha = 0.45f))
            .clickable(onClick = onPick).padding(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(64.dp).clip(RoundedCornerShape(12.dp)).background(Ai.Red.copy(alpha = 0.08f)), contentAlignment = Alignment.Center) {
            if (logo != null) {
                AsyncImage(model = logo.file, contentDescription = null, contentScale = ContentScale.Fit, modifier = Modifier.size(64.dp))
            } else {
                Icon(Icons.Rounded.AddPhotoAlternate, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(28.dp))
            }
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(stringResource(if (logo != null) R.string.ai_sites_f_logo_set else R.string.ai_sites_f_logo), color = ink, fontWeight = FontWeight.Bold, fontSize = 13.sp)
            Text(stringResource(if (logo != null) R.string.ai_sites_f_tap_change else R.string.ai_sites_f_logo_hint), color = mut, fontSize = 11.5.sp)
        }
        if (logo != null) RemoveBadge(onClear)
    }
}

/** Photos: a strip of thumbnails (each removable) with an add tile; up to [max]. */
@Composable
internal fun SiteImagesCard(
    ink: Color,
    mut: Color,
    surface: Color,
    images: List<LocalFile>,
    max: Int,
    onPick: () -> Unit,
    onRemove: (Int) -> Unit,
) {
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(surface).padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.ai_sites_f_images), color = ink, fontWeight = FontWeight.Bold, fontSize = 13.sp, modifier = Modifier.weight(1f))
            Text("${images.size}/$max", color = mut, fontSize = 12.sp)
        }
        Text(stringResource(R.string.ai_sites_f_images_hint), color = mut, fontSize = 11.5.sp)
        Spacer(Modifier.height(10.dp))
        Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            images.forEachIndexed { index, file ->
                Box(Modifier.size(78.dp)) {
                    AsyncImage(
                        model = file.file, contentDescription = null, contentScale = ContentScale.Crop,
                        modifier = Modifier.size(78.dp).clip(RoundedCornerShape(12.dp)),
                    )
                    RemoveBadge({ onRemove(index) }, Modifier.align(Alignment.TopEnd).padding(4.dp))
                }
            }
            if (images.size < max) {
                Box(
                    Modifier.size(78.dp).clip(RoundedCornerShape(12.dp)).dashedBorder(Ai.Red.copy(alpha = 0.45f)).clickable(onClick = onPick),
                    contentAlignment = Alignment.Center,
                ) { Icon(Icons.Rounded.AddPhotoAlternate, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(28.dp)) }
            }
        }
    }
}
