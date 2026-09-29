package com.dorr.app.ui.screens.wallet

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.List
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AccountBalance
import androidx.compose.material.icons.rounded.CardGiftcard
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.NorthEast
import androidx.compose.material.icons.rounded.Receipt
import androidx.compose.material.icons.rounded.ShoppingBag
import androidx.compose.material.icons.rounded.SouthWest
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.ui.graphics.vector.ImageVector
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import java.time.temporal.ChronoUnit
import java.util.Locale

/** Icon + tint for each ledger type, the same table as the preview's `TX_META`. */
internal fun txMeta(type: String): Pair<ImageVector, Tone> = when (type) {
    "topup" -> Icons.Rounded.Add to Tone.Green
    "topup_fee" -> Icons.Rounded.Receipt to Tone.Amber
    "topup_bonus" -> Icons.Rounded.CardGiftcard to Tone.Pink
    "transfer_out" -> Icons.Rounded.NorthEast to Tone.Red
    "transfer_in" -> Icons.Rounded.SouthWest to Tone.Green
    "withdrawal" -> Icons.Rounded.AccountBalance to Tone.Blue
    "refund" -> Icons.Rounded.Undo to Tone.Blue
    "penalty" -> Icons.Rounded.ErrorOutline to Tone.Red
    "service_payment" -> Icons.Rounded.ShoppingBag to Tone.Red
    "service_earning" -> Icons.Rounded.ShoppingBag to Tone.Green
    "manual_adjustment" -> Icons.Rounded.Tune to Tone.Gray
    "reversal" -> Icons.Rounded.Undo to Tone.Gray
    else -> Icons.AutoMirrored.Rounded.List to Tone.Gray
}

private fun instantOf(iso: String?): Instant? = runCatching { Instant.parse(iso) }.getOrNull()
    ?: runCatching { java.time.OffsetDateTime.parse(iso).toInstant() }.getOrNull()

/** "14:05" in the phone's own time zone. */
internal fun timeOf(iso: String?): String {
    val instant = instantOf(iso) ?: return ""
    return DateTimeFormatter.ofPattern("HH:mm", Locale.US).format(instant.atZone(ZoneId.systemDefault()))
}

/** Which calendar day a row belongs to — used to group the statement. */
internal fun dayKey(iso: String?): LocalDate? = instantOf(iso)?.atZone(ZoneId.systemDefault())?.toLocalDate()

/** 0 = today, 1 = yesterday, else null (the caller prints a date). */
internal fun daysAgo(iso: String?): Long? {
    val day = dayKey(iso) ?: return null
    return ChronoUnit.DAYS.between(day, LocalDate.now())
}

/** "24 سبتمبر 2026" / "24 September 2026" in the app's language. */
internal fun longDate(iso: String?): String {
    val day = dayKey(iso) ?: return ""
    return DateTimeFormatter.ofLocalizedDate(FormatStyle.LONG).withLocale(Locale.getDefault()).format(day)
}

/** Minor units → "1,234.50" (no currency) — the currency is shown next to it by the caller. */
internal fun money(minor: Long): String = formatMinor(minor, null)

/** "12345678901" → "123 4567 8901". */
internal fun groupWalletNumber(digits: String): String =
    if (digits.length == 11) "${digits.substring(0, 3)} ${digits.substring(3, 7)} ${digits.substring(7)}" else digits
