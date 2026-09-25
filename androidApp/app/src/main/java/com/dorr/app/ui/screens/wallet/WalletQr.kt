package com.dorr.app.ui.screens.wallet

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.net.Uri
import android.provider.MediaStore
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.CameraAlt
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.Download
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.Share
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.TransferLookupRequest
import com.dorr.app.network.apiFailure
import com.google.zxing.BarcodeFormat
import com.google.zxing.BinaryBitmap
import com.google.zxing.DecodeHintType
import com.google.zxing.EncodeHintType
import com.google.zxing.MultiFormatReader
import com.google.zxing.RGBLuminanceSource
import com.google.zxing.common.BitMatrix
import com.google.zxing.common.HybridBinarizer
import com.google.zxing.qrcode.QRCodeWriter
import com.google.zxing.qrcode.decoder.ErrorCorrectionLevel
import com.journeyapps.barcodescanner.ScanContract
import com.journeyapps.barcodescanner.ScanOptions
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

/** The wallet's QR as a module matrix (medium error correction, no quiet zone — we draw our own). */
private fun qrMatrix(text: String): BitMatrix =
    QRCodeWriter().encode(text, BarcodeFormat.QR_CODE, 0, 0, mapOf(EncodeHintType.ERROR_CORRECTION to ErrorCorrectionLevel.M, EncodeHintType.MARGIN to 0))

private fun qrBitmap(text: String, sizePx: Int = 1024): Bitmap {
    val matrix = qrMatrix(text)
    val quiet = 2
    val modules = matrix.width + quiet * 2
    val cell = sizePx / modules
    val bitmap = Bitmap.createBitmap(cell * modules, cell * modules, Bitmap.Config.ARGB_8888)
    bitmap.eraseColor(android.graphics.Color.WHITE)
    val paint = android.graphics.Paint().apply { color = android.graphics.Color.parseColor("#111928"); style = android.graphics.Paint.Style.FILL }
    val canvas = android.graphics.Canvas(bitmap)
    for (x in 0 until matrix.width) for (y in 0 until matrix.height) {
        if (matrix[x, y]) canvas.drawRect(((x + quiet) * cell).toFloat(), ((y + quiet) * cell).toFloat(), ((x + quiet + 1) * cell).toFloat(), ((y + quiet + 1) * cell).toFloat(), paint)
    }
    return bitmap
}

/** "My QR": the wallet's number as a code others scan to send money here. */
@Composable
fun WalletMyQr() {
    val host = LocalWallet.current
    val context = LocalContext.current
    val balance = host.balance
    val payload = balance?.qrPayload
    val number = balance?.walletNumberFormatted ?: groupWalletNumber(balance?.walletNumber.orEmpty())
    val matrix = remember(payload) { payload?.let { runCatching { qrMatrix(it) }.getOrNull() } }
    val copied = stringResource(R.string.wa_number_copied)
    val saved = stringResource(R.string.wa_qr_saved)
    val shareText = stringResource(R.string.wa_qr_share_text, number)
    val country = countryName(balance?.countryCode)

    WaPage(title = stringResource(R.string.wa_my_qr_title), onBack = { host.pop() }) {
        Box(
            Modifier
                .fillMaxWidth()
                .waRise(0)
                .shadow(10.dp, Wa.CardShape, ambientColor = Color(0x1A111928), spotColor = Color(0x26111928))
                .clip(Wa.CardShape)
                .background(Color.White),
        ) {
            Box(Modifier.fillMaxWidth().height(120.dp).background(Brush.linearGradient(listOf(Color(0xFFFDE8EC), Color(0xFFFFF5F6)))))
            Column(Modifier.fillMaxWidth().padding(start = 18.dp, end = 18.dp, top = 22.dp, bottom = 20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                Row(
                    Modifier.clip(RoundedCornerShape(999.dp)).background(Color(0xFFE5F6EC)).padding(horizontal = 12.dp, vertical = 5.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    Icon(Icons.Rounded.AccountBalanceWallet, null, tint = Color(0xFF15803D), modifier = Modifier.size(15.dp))
                    Text(stringResource(R.string.wa_your_wallet_in, country), color = Color(0xFF15803D), fontSize = 11.5.sp, fontWeight = FontWeight.ExtraBold)
                }
                Spacer(Modifier.height(16.dp))
                Box(
                    Modifier
                        .widthIn(max = 250.dp)
                        .fillMaxWidth(0.66f)
                        .aspectRatio(1f)
                        .shadow(16.dp, RoundedCornerShape(26.dp), ambientColor = Color(0x24111928), spotColor = Color(0x24111928))
                        .clip(RoundedCornerShape(26.dp))
                        .background(Color.White)
                        .padding(10.dp),
                ) {
                    if (matrix != null) QrCanvas(matrix) else Text(stringResource(R.string.wa_qr_failed), color = Wa.Mut, fontSize = 13.sp)
                }
                Spacer(Modifier.height(14.dp))
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Text(number, fontSize = 21.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink, letterSpacing = 2.sp)
                }
                Row(Modifier.padding(top = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    WaGlassChip(country, Icons.Rounded.Public, dark = true)
                    WaGlassChip(balance?.currencyCode.orEmpty(), Icons.Rounded.AccountBalanceWallet, dark = true)
                }
                Text(stringResource(R.string.wa_my_qr_hint), color = Wa.Mut, fontSize = 12.5.sp, lineHeight = 21.sp, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 12.dp))
            }
        }

        Row(Modifier.fillMaxWidth().padding(vertical = 14.dp).waRise(1), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Box(Modifier.weight(1f)) {
                WaButton(stringResource(R.string.wa_copy_number), {
                    val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                    clipboard.setPrimaryClip(ClipData.newPlainText("wallet", number))
                    host.showToast(copied)
                }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.ContentCopy)
            }
            Box(Modifier.weight(1f)) {
                WaButton(stringResource(R.string.wa_save_image), {
                    if (payload != null) {
                        val ok = runCatching { MediaStore.Images.Media.insertImage(context.contentResolver, qrBitmap(payload), "dorr-wallet-$number", null) }.getOrNull() != null
                        if (ok) host.showToast(saved)
                    }
                }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Download, enabled = payload != null)
            }
        }
        WaButton(stringResource(R.string.wa_share), {
            val send = Intent(Intent.ACTION_SEND).apply { type = "text/plain"; putExtra(Intent.EXTRA_TEXT, shareText) }
            context.startActivity(Intent.createChooser(send, null))
        }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Share)

        Spacer(Modifier.height(14.dp))
        WaNote(stringResource(R.string.wa_my_qr_safe_note), Modifier.waRise(2), icon = Icons.Rounded.Shield)
    }
}

@Composable
private fun QrCanvas(matrix: BitMatrix) {
    Canvas(Modifier.fillMaxSize()) {
        val quiet = 2
        val modules = matrix.width + quiet * 2
        val cell = size.width / modules
        drawRect(Color.White)
        for (x in 0 until matrix.width) for (y in 0 until matrix.height) {
            if (matrix[x, y]) drawRect(Wa.Ink, topLeft = Offset((x + quiet) * cell, (y + quiet) * cell), size = Size(cell + 0.5f, cell + 0.5f))
        }
    }
}

// ------------------------------------------------------------------------------- scanner

/** Decodes a QR from a picture (gallery). */
private fun decodeQr(context: Context, uri: Uri): String? = runCatching {
    val bitmap = context.contentResolver.openInputStream(uri)?.use { android.graphics.BitmapFactory.decodeStream(it) } ?: return null
    val scaled = if (maxOf(bitmap.width, bitmap.height) > 1200) {
        val ratio = 1200f / maxOf(bitmap.width, bitmap.height)
        Bitmap.createScaledBitmap(bitmap, (bitmap.width * ratio).toInt(), (bitmap.height * ratio).toInt(), true)
    } else bitmap
    val pixels = IntArray(scaled.width * scaled.height)
    scaled.getPixels(pixels, 0, scaled.width, 0, 0, scaled.width, scaled.height)
    val reader = MultiFormatReader().apply { setHints(mapOf(DecodeHintType.POSSIBLE_FORMATS to listOf(BarcodeFormat.QR_CODE), DecodeHintType.TRY_HARDER to true)) }
    reader.decodeWithState(BinaryBitmap(HybridBinarizer(RGBLuminanceSource(scaled.width, scaled.height, pixels)))).text
}.getOrNull()

/**
 * Scan someone's wallet QR (camera) or read one from a picture. A code goes through the same server
 * lookup as typing the number, so it ends on the same "is this who you mean?" page.
 */
@Composable
fun WalletScanner() {
    val host = LocalWallet.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val networkError = stringResource(R.string.wa_error_network)
    val noCodeText = stringResource(R.string.wa_scan_no_code)
    val cameraPrompt = stringResource(R.string.wa_scan_prompt)
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun lookup(text: String) {
        if (busy) return
        busy = true
        error = null
        scope.launch {
            try {
                val who = ApiClient.wallet.transferLookup(walletAuth(), TransferLookupRequest(mode = "qr", qr = text)).data
                if (who != null) {
                    host.pop()
                    host.push(WaRoute.TransferConfirm(who))
                } else error = networkError
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                val failure = e.apiFailure()
                error = if (failure.httpStatus == null) networkError else failure.message ?: networkError
            }
            busy = false
        }
    }

    val camera = rememberLauncherForActivityResult(ScanContract()) { result -> result.contents?.let(::lookup) }
    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null) {
            val text = decodeQr(context, uri)
            if (text != null) lookup(text) else error = noCodeText
        }
    }

    WaPage(title = stringResource(R.string.wa_scan_title), onBack = { host.pop() }) {
        Viewfinder(busy, Modifier.waRise(0))
        Text(
            stringResource(if (busy) R.string.wa_scan_checking else R.string.wa_scan_hint),
            color = Wa.Mut, fontSize = 13.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(top = 14.dp, bottom = 12.dp),
        )
        WaError(error)
        Column(Modifier.waRise(1), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            WaButton(stringResource(R.string.wa_scan_open_camera), {
                camera.launch(ScanOptions().setDesiredBarcodeFormats(ScanOptions.QR_CODE).setPrompt(cameraPrompt).setBeepEnabled(false).setOrientationLocked(false))
            }, icon = Icons.Rounded.CameraAlt, enabled = !busy)
            WaButton(stringResource(R.string.wa_scan_gallery), { gallery.launch("image/*") }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Image, enabled = !busy)
        }
    }
}

/** The dark viewfinder frame with white corner brackets and a red line sweeping up and down. */
@Composable
private fun Viewfinder(busy: Boolean, modifier: Modifier = Modifier) {
    val transition = rememberInfiniteTransition(label = "scan")
    val sweep by transition.animateFloat(0.2f, 0.78f, infiniteRepeatable(tween(2200), RepeatMode.Reverse), label = "sweep")
    Box(modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
        Box(
            Modifier
                .fillMaxWidth(0.78f)
                .widthIn(max = 300.dp)
                .aspectRatio(1f)
                .shadow(18.dp, RoundedCornerShape(30.dp), ambientColor = Color(0x47111928), spotColor = Color(0x47111928))
                .clip(RoundedCornerShape(30.dp))
                .background(Brush.radialGradient(listOf(Color(0xFF2A3140), Color(0xFF0F141C)))),
        ) {
            Column(Modifier.fillMaxSize().padding(28.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                Icon(Icons.Rounded.QrCode2, null, tint = Color.White.copy(alpha = 0.7f), modifier = Modifier.size(54.dp))
            }
            Canvas(Modifier.fillMaxSize()) {
                val arm = 34.dp.toPx()
                val pad = 16.dp.toPx()
                val stroke = 4.dp.toPx()
                val corners = listOf(
                    Triple(Offset(pad, pad), 1f, 1f), Triple(Offset(size.width - pad, pad), -1f, 1f),
                    Triple(Offset(pad, size.height - pad), 1f, -1f), Triple(Offset(size.width - pad, size.height - pad), -1f, -1f),
                )
                corners.forEach { (o, dx, dy) ->
                    drawLine(Color.White, o, Offset(o.x + dx * arm, o.y), strokeWidth = stroke, cap = androidx.compose.ui.graphics.StrokeCap.Round)
                    drawLine(Color.White, o, Offset(o.x, o.y + dy * arm), strokeWidth = stroke, cap = androidx.compose.ui.graphics.StrokeCap.Round)
                }
            }
            if (!busy) {
                Box(
                    Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 26.dp)
                        .height(3.dp)
                        .graphicsLayer { translationY = sweep * 300.dp.toPx() * 0.78f }
                        .clip(RoundedCornerShape(3.dp))
                        .background(Brush.horizontalGradient(listOf(Color.Transparent, Color(0xFFFF4D57), Color.Transparent))),
                )
            }
        }
    }
}
