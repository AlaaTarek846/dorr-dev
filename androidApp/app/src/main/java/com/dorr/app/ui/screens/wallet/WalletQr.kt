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
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AccountBalanceWallet
import androidx.compose.material.icons.rounded.CameraAlt
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.Download
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.QrCodeScanner
import androidx.compose.material.icons.rounded.Share
import androidx.compose.material.icons.rounded.Verified
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
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
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

    val currency = balance?.currencyCode.orEmpty()
    val pill = RoundedCornerShape(50)

    WaPage(title = stringResource(R.string.wa_my_qr_title), onBack = { host.pop() }) {
        val hero = RoundedCornerShape(28.dp)
        Column(
            Modifier
                .fillMaxWidth()
                .waRise(0)
                .shadow(16.dp, hero, ambientColor = Wa.Red.copy(alpha = 0.28f), spotColor = Wa.Red.copy(alpha = 0.36f))
                .clip(hero)
                .background(Wa.HeroBrush)
                .padding(top = 12.dp, bottom = 16.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Row(Modifier.fillMaxWidth().padding(horizontal = 14.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(
                    Modifier.size(28.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.16f)),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(Icons.Rounded.AccountBalanceWallet, null, tint = Color.White, modifier = Modifier.size(14.dp))
                }
                Column(Modifier.weight(1f).padding(horizontal = 8.dp)) {
                    Text(stringResource(R.string.wa_qr_digital_title), color = Color.White.copy(alpha = 0.7f), fontSize = 10.sp, fontWeight = FontWeight.Medium)
                    Text(stringResource(R.string.wa_qr_digital_sub), color = Color.White, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 1.dp))
                }
                Row(
                    Modifier.clip(pill).background(Color.White.copy(alpha = 0.16f)).padding(horizontal = 8.dp, vertical = 4.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(4.dp),
                ) {
                    Icon(Icons.Rounded.Verified, null, tint = Color.White, modifier = Modifier.size(12.dp))
                    Text(stringResource(R.string.wa_qr_verified), color = Color.White, fontSize = 10.sp, fontWeight = FontWeight.Bold)
                }
            }
            Spacer(Modifier.height(14.dp))
            Box(
                Modifier
                    .widthIn(max = 196.dp)
                    .fillMaxWidth(0.56f)
                    .aspectRatio(1f)
                    .clip(RoundedCornerShape(18.dp))
                    .background(Color.White)
                    .padding(10.dp),
                contentAlignment = Alignment.Center,
            ) {
                if (matrix != null) {
                    QrCanvas(matrix)
                } else {
                    Text(stringResource(R.string.wa_qr_failed), color = Wa.Mut, fontSize = 13.sp)
                }
            }
            Spacer(Modifier.height(12.dp))
            Text(stringResource(R.string.wa_wallet_number), color = Color.White.copy(alpha = 0.62f), fontSize = 11.sp)
            if (number.isNotBlank()) {
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Text(number, color = Color.White, fontSize = 16.sp, fontWeight = FontWeight.ExtraBold, modifier = Modifier.padding(top = 4.dp))
                }
            }
            Box(Modifier.fillMaxWidth().padding(top = 12.dp, bottom = 12.dp).height(18.dp)) {
                Canvas(Modifier.fillMaxWidth().padding(horizontal = 16.dp).height(1.dp).align(Alignment.Center)) {
                    drawLine(
                        Color.White.copy(alpha = 0.32f),
                        Offset(0f, size.height / 2),
                        Offset(size.width, size.height / 2),
                        strokeWidth = 1.5.dp.toPx(),
                        pathEffect = PathEffect.dashPathEffect(floatArrayOf(5f, 7f)),
                    )
                }
                Box(
                    Modifier
                        .size(18.dp)
                        .offset(x = (-9).dp)
                        .align(Alignment.CenterStart)
                        .clip(CircleShape)
                        .background(Wa.Bg),
                )
                Box(
                    Modifier
                        .size(18.dp)
                        .offset(x = 9.dp)
                        .align(Alignment.CenterEnd)
                        .clip(CircleShape)
                        .background(Wa.Bg),
                )
            }
            Row(Modifier.fillMaxWidth().padding(horizontal = 14.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                QrMetaPill(Icons.Rounded.Public, country.ifBlank { "—" }, Modifier.weight(1f))
                QrMetaPill(Icons.Rounded.AccountBalanceWallet, currency.ifBlank { "—" }, Modifier.weight(1f))
            }
        }

        Row(
            Modifier
                .fillMaxWidth()
                .padding(top = 14.dp)
                .waRise(1)
                .clip(RoundedCornerShape(18.dp))
                .background(Wa.Surface)
                .border(1.dp, Wa.Line, RoundedCornerShape(18.dp))
                .padding(horizontal = 14.dp, vertical = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                Modifier.padding(end = 10.dp).size(36.dp).clip(CircleShape).background(Wa.Red.copy(alpha = 0.12f)),
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Rounded.QrCodeScanner, null, tint = Wa.Red, modifier = Modifier.size(18.dp))
            }
            Text(stringResource(R.string.wa_my_qr_hint), color = Wa.Ink, fontSize = 12.5.sp, lineHeight = 20.sp, modifier = Modifier.weight(1f))
        }

        QrPillButton(stringResource(R.string.wa_copy_number), Icons.Rounded.ContentCopy, filled = true, modifier = Modifier.padding(top = 14.dp).waRise(2)) {
            val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
            clipboard.setPrimaryClip(ClipData.newPlainText("wallet", number))
            host.showToast(copied)
        }
        Row(Modifier.fillMaxWidth().padding(top = 10.dp).waRise(3), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            QrPillButton(stringResource(R.string.wa_save_download), Icons.Rounded.Download, filled = false, modifier = Modifier.weight(1f), enabled = payload != null) {
                if (payload != null) {
                    val ok = runCatching { MediaStore.Images.Media.insertImage(context.contentResolver, qrBitmap(payload), "dorr-wallet-$number", null) }.getOrNull() != null
                    if (ok) host.showToast(saved)
                }
            }
            QrPillButton(stringResource(R.string.wa_share), Icons.Rounded.Share, filled = false, modifier = Modifier.weight(1f)) {
                val send = Intent(Intent.ACTION_SEND).apply { type = "text/plain"; putExtra(Intent.EXTRA_TEXT, shareText) }
                context.startActivity(Intent.createChooser(send, null))
            }
        }
    }
}

@Composable
private fun QrMetaPill(icon: ImageVector, value: String, modifier: Modifier = Modifier) {
    Row(
        modifier
            .clip(RoundedCornerShape(18.dp))
            .background(Color.White.copy(alpha = 0.14f))
            .padding(horizontal = 12.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.Center,
    ) {
        Icon(icon, null, tint = Color.White.copy(alpha = 0.9f), modifier = Modifier.size(15.dp))
        Spacer(Modifier.size(7.dp))
        Text(value, color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.Bold, maxLines = 1)
    }
}

@Composable
private fun QrPillButton(text: String, icon: ImageVector, filled: Boolean, modifier: Modifier = Modifier, enabled: Boolean = true, onClick: () -> Unit) {
    val source = remember { MutableInteractionSource() }
    val shape = RoundedCornerShape(50)
    Row(
        modifier
            .fillMaxWidth()
            .height(52.dp)
            .clip(shape)
            .then(if (filled) Modifier.background(Wa.HeroBrush) else Modifier.background(Wa.Surface).border(1.dp, Wa.Ink.copy(alpha = 0.18f), shape))
            .clickable(interactionSource = source, indication = null, enabled = enabled, onClick = onClick)
            .padding(horizontal = 16.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.Center,
    ) {
        Text(text, color = if (filled) Color.White else Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.ExtraBold)
        Spacer(Modifier.size(8.dp))
        Icon(icon, null, tint = if (filled) Color.White else Wa.Ink, modifier = Modifier.size(18.dp))
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
            if (matrix[x, y]) drawRect(Color(0xFF111928), topLeft = Offset((x + quiet) * cell, (y + quiet) * cell), size = Size(cell + 0.5f, cell + 0.5f))
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
        // The sentence that used to sit under "Scan a QR code" on the transfer form.
        Text(
            stringResource(R.string.wa_scan_cta_text),
            color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center, lineHeight = 21.sp,
            modifier = Modifier.fillMaxWidth().padding(bottom = 14.dp).waRise(0),
        )
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
                        .background(Brush.horizontalGradient(listOf(Color.Transparent, Wa.Red, Color.Transparent))),
                )
            }
        }
    }
}
