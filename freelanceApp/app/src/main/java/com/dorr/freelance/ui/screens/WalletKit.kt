package com.dorr.freelance.ui.screens

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.webkit.MimeTypeMap
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.KeyboardType
import androidx.core.content.FileProvider
import com.dorr.freelance.ui.components.DorrTextField
import java.io.File
import java.util.Locale

/**
 * What the wallet pages share. The PIN the freelancer typed to open the wallet is kept **in memory only**, while the wallet is
 * open, and dropped when they leave it (or sign out) — a money-moving action still asks for it again.
 */
object WalletSession {
    var pin: String? by mutableStateOf(null)
        private set

    fun unlock(pin: String) {
        this.pin = pin
    }

    fun lock() {
        pin = null
    }
}

/** "125.50 SAR" from minor units (two decimals) — the digits stay Latin and left-to-right in Arabic text too. */
fun formatMinor(minor: Long, currency: String?): String {
    val sign = if (minor < 0) "-" else ""
    val abs = Math.abs(minor)
    val text = String.format(Locale.US, "%s%d.%02d", sign, abs / 100, abs % 100)
    return "‎" + listOf(text, currency.orEmpty()).filter { it.isNotBlank() }.joinToString(" ")
}

/** "12.5" or "12,5" → 1250 minor units; null when it is not a positive amount with at most two decimals. */
fun parseMinor(text: String): Long? {
    val clean = text.trim().replace(',', '.')
    if (!Regex("""^\d{1,9}(\.\d{1,2})?$""").matches(clean)) return null
    val parts = clean.split('.')
    val whole = parts[0].toLong()
    val cents = parts.getOrNull(1)?.padEnd(2, '0')?.toLong() ?: 0L
    return (whole * 100 + cents).takeIf { it > 0 }
}

/** A 4-digit PIN field: dots, a numeric keyboard, nothing but digits. */
@Composable
fun PinField(value: String, label: String, error: Boolean = false, modifier: Modifier = Modifier, onChange: (String) -> Unit) {
    DorrTextField(
        value = value,
        onValueChange = { onChange(it.filter(Char::isDigit).take(4)) },
        label = label,
        placeholder = "••••",
        icon = Icons.Rounded.Lock,
        password = true,
        error = error,
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword),
        modifier = modifier,
    )
}

/** Opens [url] in the phone's browser (a payment page). */
fun openUrl(context: Context, url: String): Boolean = runCatching {
    context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
}.isSuccess

/** Opens a file from the cache in a viewer (a withdrawal receipt). */
fun openCachedFile(context: Context, file: File): Boolean {
    val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
    val type = MimeTypeMap.getSingleton().getMimeTypeFromExtension(file.extension.lowercase()) ?: "*/*"
    return runCatching {
        context.startActivity(Intent(Intent.ACTION_VIEW).setDataAndType(uri, type).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK))
    }.isSuccess
}
