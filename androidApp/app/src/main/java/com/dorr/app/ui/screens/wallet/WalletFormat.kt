package com.dorr.app.ui.screens.wallet

import com.dorr.app.network.AuthSession
import java.math.BigDecimal
import java.math.RoundingMode
import java.text.DecimalFormat
import java.text.DecimalFormatSymbols
import java.util.Locale

/** `Authorization` header value for the wallet endpoints. */
internal fun walletAuth(): String = "Bearer ${AuthSession.token.orEmpty()}"

private val moneyFormat = DecimalFormat("#,##0.00", DecimalFormatSymbols(Locale.US))

/**
 * Minor units (halalas/piastres) → "1,234.50 SAR". Pure integer→decimal
 * conversion through BigDecimal: no Float/Double, so a balance can never show
 * a rounding artefact. The backend uses 2 decimals for every currency today.
 */
fun formatMinor(minor: Long, currency: String?): String {
    val text = moneyFormat.format(BigDecimal(minor).movePointLeft(2))
    return if (currency.isNullOrBlank()) text else "$text $currency"
}

/**
 * What the user typed ("100", "100.5", "100.50") → minor units, or null when
 * it isn't a positive amount with at most 2 decimals. Never rounds silently:
 * "10.555" is rejected instead of quietly becoming 10.56.
 */
fun parseAmountToMinor(text: String): Long? {
    val value = text.trim().replace(',', '.').toBigDecimalOrNull() ?: return null
    if (value.signum() <= 0 || value.scale() > 2) return null
    val minor = value.setScale(2, RoundingMode.UNNECESSARY).movePointRight(2)
    return runCatching { minor.longValueExact() }.getOrNull()
}
