package com.dorr.app.ui.theme

import androidx.compose.material3.Typography
import androidx.compose.ui.text.ExperimentalTextApi
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontVariation
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import com.dorr.app.R

/**
 * Cairo is a single variable font file (weights 200-1000). Each entry below
 * asks the text engine for one weight via [FontVariation] rather than
 * shipping a separate static file per weight — same font file the Flutter
 * app bundles, see app/src/main/res/font/cairo.ttf.
 */
@OptIn(ExperimentalTextApi::class)
private fun cairoWeight(weight: Int) = Font(
    resId = R.font.cairo,
    weight = FontWeight(weight),
    variationSettings = FontVariation.Settings(FontVariation.weight(weight)),
)

/** The bundled Cairo — used until (or instead of) a font chosen in the appearance settings. */
val CairoBuiltIn = FontFamily(
    cairoWeight(400),
    cairoWeight(500),
    cairoWeight(600),
    cairoWeight(700),
    cairoWeight(900),
)

/**
 * The app font **as chosen in the appearance settings** ([AppFont]), Cairo by default. Kept under
 * this name so every screen that sets it explicitly (text fields, custom text styles) follows
 * the setting without being touched.
 */
val CairoFontFamily: FontFamily get() = AppFont.family

/** The type scale, in the current app font. */
fun dorrTypography(family: FontFamily = AppFont.family): Typography = Typography(
    displayLarge = TextStyle(fontFamily = family, fontWeight = FontWeight.Bold, fontSize = 32.sp),
    displayMedium = TextStyle(fontFamily = family, fontWeight = FontWeight.Bold, fontSize = 28.sp),
    headlineLarge = TextStyle(fontFamily = family, fontWeight = FontWeight.SemiBold, fontSize = 24.sp),
    headlineMedium = TextStyle(fontFamily = family, fontWeight = FontWeight.SemiBold, fontSize = 20.sp),
    titleLarge = TextStyle(fontFamily = family, fontWeight = FontWeight.SemiBold, fontSize = 18.sp),
    titleMedium = TextStyle(fontFamily = family, fontWeight = FontWeight.Medium, fontSize = 16.sp),
    titleSmall = TextStyle(fontFamily = family, fontWeight = FontWeight.Medium, fontSize = 14.sp),
    bodyLarge = TextStyle(fontFamily = family, fontWeight = FontWeight.Normal, fontSize = 16.sp),
    bodyMedium = TextStyle(fontFamily = family, fontWeight = FontWeight.Normal, fontSize = 14.sp),
    bodySmall = TextStyle(fontFamily = family, fontWeight = FontWeight.Normal, fontSize = 12.sp),
    labelLarge = TextStyle(fontFamily = family, fontWeight = FontWeight.SemiBold, fontSize = 14.sp),
    labelMedium = TextStyle(fontFamily = family, fontWeight = FontWeight.Medium, fontSize = 12.sp),
    labelSmall = TextStyle(fontFamily = family, fontWeight = FontWeight.Medium, fontSize = 11.sp),
)

val DorrTypography = dorrTypography(CairoFontFamily)
