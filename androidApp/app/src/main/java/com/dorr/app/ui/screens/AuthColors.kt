package com.dorr.app.ui.screens

import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.lerp
import com.dorr.app.ui.theme.appearanceColor

/**
 * The colours of the pages before sign-in (onboarding, sign-in, OTP) — every one from the admin's colours (the
 * platform defaults, or the user's own once signed in), by day and by night, so nothing on these pages stays a fixed
 * grey, pink or brown when the colours change. Only the logo (an image) and the splash (it must match the system's)
 * stay fixed. Errors are the `danger` red and successes the `success` green — never the brand navy / orange.
 * iosApp twin: `AuthColors` in UI/Screens/AuthColors.swift.
 */
internal object AuthColors {
    /** Main words: names, the dial code, typed digits. */
    @Composable
    fun ink(night: Boolean): Color = if (night) AccountDark.ink else appearanceColor("textPrimary", Color(0xFF111928), night = false)

    /** Secondary words: descriptions, hints. */
    @Composable
    fun mut(night: Boolean): Color = if (night) AccountDark.mut else appearanceColor("authTextMuted", Color(0xFF6B7280), night = false)

    /** The faintest words: placeholders, a disabled button's label, small icons. */
    @Composable
    fun soft(night: Boolean): Color = if (night) AccountDark.mut else appearanceColor("textMuted", Color(0xFF9CA3AF), night = false)

    /** Frames: fields, the language / skip buttons, the code boxes. */
    @Composable
    fun line(night: Boolean): Color = if (night) AccountDark.line else appearanceColor("border", Color(0xFFE5E7EB), night = false)

    /** Fields and menus. */
    @Composable
    fun card(night: Boolean): Color = if (night) AccountDark.card else appearanceColor("surface", Color.White, night = false)

    /** Something went wrong (frames, the OTP shield). */
    @Composable
    fun danger(night: Boolean): Color = appearanceColor("danger", Color(0xFFF05252), night = night)

    /** It worked (the right code, a restored account). */
    @Composable
    fun success(night: Boolean): Color = appearanceColor("success", Color(0xFF059669), night = night)

    /** A status colour as words / icons: deeper by day, lighter by night — readable on either background. */
    fun onStatus(color: Color, night: Boolean): Color = if (night) lerp(color, Color.White, 0.45f) else lerp(color, Color.Black, 0.25f)

    /** A status box's fill and frame: the status colour, faint. */
    fun statusFill(color: Color, night: Boolean): Color = color.copy(alpha = if (night) 0.16f else 0.08f)
    fun statusFrame(color: Color): Color = color.copy(alpha = 0.35f)
}
