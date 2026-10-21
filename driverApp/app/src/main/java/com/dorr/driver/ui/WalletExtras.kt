package com.dorr.driver.ui

import android.content.Context
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.AcUnit
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.VerifiedUser
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.driver.R
import com.dorr.driver.net.DriverBackend
import com.dorr.driver.net.InvoiceDto
import com.dorr.driver.net.PayoutStatusDto
import com.dorr.driver.net.WalletDto
import com.dorr.driver.ui.kit.Banner
import com.dorr.driver.ui.kit.BannerKind
import com.dorr.driver.ui.kit.DorrField
import com.dorr.driver.ui.kit.surface
import com.dorr.driver.ui.theme.Pal
import com.google.gson.Gson
import com.google.gson.JsonObject
import kotlinx.coroutines.launch

/** D-210: the last balance the server confirmed — shown, with its time, when the wallet can't be reached. */
internal object WalletCache {
    private const val PREFS = "dorr_wallet_cache"

    fun save(context: Context, w: WalletDto) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString("wallet", Gson().toJson(w)).putLong("at", System.currentTimeMillis()).apply()
    }

    /** The cached wallet and when it was confirmed (epoch ms), or null. */
    fun load(context: Context): Pair<WalletDto, Long>? {
        val p = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val json = p.getString("wallet", null) ?: return null
        return runCatching { Gson().fromJson(json, WalletDto::class.java) to p.getLong("at", 0) }.getOrNull()
    }
}

/** D-216, D-223, D-224, D-299: what stands between the captain and a withdrawal, said plainly. */
@Composable
internal fun PayoutBanners(status: PayoutStatusDto?, cachedAt: Long?, onInternational: () -> Unit, onInvoices: () -> Unit) {
    Column(Modifier.fillMaxWidth().padding(horizontal = 16.dp).padding(top = 10.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        cachedAt?.let { at ->
            Banner(stringResource(R.string.wx_cached, android.text.format.DateFormat.format("dd/MM HH:mm", at).toString()), kind = BannerKind.Warning)
        }
        status?.let { s ->
            if (s.frozen) Banner(stringResource(R.string.wx_frozen), kind = BannerKind.Error)
            else if (!s.kycOk) Banner(stringResource(R.string.wx_kyc), kind = BannerKind.Warning)
            when (s.international.status) {
                "pending" -> Banner(stringResource(R.string.wx_intl_pending), kind = BannerKind.Info)
                "rejected" -> Banner(stringResource(R.string.wx_intl_rejected, s.international.answer.orEmpty()), kind = BannerKind.Warning)
            }
        }
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            SmallLink(Icons.Rounded.Description, stringResource(R.string.wx_invoices), Modifier.weight(1f), onInvoices)
            if (status != null && status.international.status in setOf("none", "rejected")) {
                SmallLink(Icons.Rounded.Public, stringResource(R.string.wx_intl), Modifier.weight(1f), onInternational)
            }
        }
    }
}

@Composable
private fun SmallLink(icon: androidx.compose.ui.graphics.vector.ImageVector, text: String, modifier: Modifier, onClick: () -> Unit) {
    Row(
        modifier.clip(RoundedCornerShape(14.dp)).background(Pal.accent.copy(alpha = 0.08f)).clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = Pal.accent, modifier = Modifier.size(18.dp))
        Spacer(Modifier.width(6.dp))
        Text(text, color = Pal.accent, fontWeight = FontWeight.Bold, fontSize = 12.5.sp)
    }
}

/** D-224: ask for payouts to an account abroad. */
@Composable
internal fun InternationalDialog(onClose: () -> Unit, onSent: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var reason by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    AlertDialog(
        onDismissRequest = { if (!busy) onClose() },
        icon = { Icon(Icons.Rounded.Public, null, tint = Pal.accent) },
        title = { Text(stringResource(R.string.wx_intl), fontWeight = FontWeight.Bold) },
        text = {
            Column {
                Text(stringResource(R.string.wx_intl_hint), fontSize = 13.sp, color = Pal.mut)
                Spacer(Modifier.height(8.dp))
                DorrField(reason, { reason = it.take(500) }, placeholder = { Text(stringResource(R.string.wx_intl_reason)) })
                error?.let { Text(it, color = Pal.danger, fontSize = 12.sp) }
            }
        },
        confirmButton = {
            TextButton(enabled = reason.trim().length >= 5 && !busy, onClick = {
                busy = true
                scope.launch {
                    when (val r = apiCall { DriverBackend.api.requestInternationalPayouts(JsonObject().apply { addProperty("reason", reason.trim()) }) }) {
                        is ApiResult.Ok -> onSent()
                        is ApiResult.Failed -> error = r.error.displayMessage
                        is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                    }
                    busy = false
                }
            }) { Text(stringResource(R.string.rk_appeal_send)) }
        },
        dismissButton = { TextButton(onClick = onClose) { Text(stringResource(R.string.cancel)) } },
    )
}

/** D-227, D-228: monthly statements of DORR's commission, with their credit / debit notes. */
@Composable
internal fun InvoicesScreen(wallet: WalletDto?, onClose: () -> Unit) {
    var months by remember { mutableStateOf<List<InvoiceDto>?>(null) }
    var open by remember { mutableStateOf<InvoiceDto?>(null) }
    LaunchedEffect(Unit) { months = (apiCall { DriverBackend.api.invoices() } as? ApiResult.Ok)?.data.orEmpty() }
    Dialog(onDismissRequest = onClose, properties = DialogProperties(usePlatformDefaultWidth = false, decorFitsSystemWindows = false)) {
        Column(Modifier.fillMaxSize().background(Pal.bg).statusBarsPadding()) {
            Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(40.dp).clip(CircleShape).clickable { if (open != null) open = null else onClose() }, contentAlignment = Alignment.Center) {
                    Icon(Icons.AutoMirrored.Rounded.ArrowBack, null, tint = Pal.ink)
                }
                Text(open?.month ?: stringResource(R.string.wx_invoices), color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 19.sp)
            }
            Column(Modifier.weight(1f).verticalScroll(rememberScrollState()).padding(horizontal = 16.dp).navigationBarsPadding(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                val chosen = open
                if (chosen == null) {
                    val list = months
                    when {
                        list == null -> Box(Modifier.fillMaxWidth().padding(30.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Pal.accent) }
                        list.isEmpty() -> Text(stringResource(R.string.wx_no_invoices), color = Pal.mut, modifier = Modifier.padding(24.dp))
                        else -> list.forEach { m -> InvoiceCard(m, wallet) { open = m } }
                    }
                } else {
                    InvoiceDetail(chosen.month, wallet)
                }
            }
        }
    }
}

@Composable
private fun InvoiceCard(m: InvoiceDto, w: WalletDto?, onClick: () -> Unit) {
    Column(Modifier.fillMaxWidth().surface(RoundedCornerShape(18.dp)).clickable(onClick = onClick).padding(14.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Rounded.Description, null, tint = Pal.accent)
            Spacer(Modifier.width(8.dp))
            Column(Modifier.weight(1f)) {
                Text(m.month, color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 16.sp)
                Text(m.number, color = Pal.mut, fontSize = 11.sp)
            }
            Text(stringResource(R.string.wx_trips_n, m.trips), color = Pal.mut, fontSize = 12.sp)
        }
        Spacer(Modifier.height(8.dp))
        InvoiceLine(stringResource(R.string.wx_fares), m.faresMinor, w)
        InvoiceLine(stringResource(R.string.wx_commission), -m.commissionMinor, w)
        if (m.taxMinor > 0) InvoiceLine(stringResource(R.string.wx_tax), -m.taxMinor, w)
        InvoiceLine(stringResource(R.string.wx_net), m.netMinor, w, bold = true)
        if (m.notesCount > 0) Text(stringResource(R.string.wx_notes_n, m.notesCount), color = Pal.accent, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
    }
}

@Composable
private fun InvoiceDetail(month: String, w: WalletDto?) {
    var d by remember { mutableStateOf<InvoiceDto?>(null) }
    LaunchedEffect(month) { d = (apiCall { DriverBackend.api.invoice(month) } as? ApiResult.Ok)?.data }
    val inv = d ?: run { Box(Modifier.fillMaxWidth().padding(30.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Pal.accent) }; return }
    InvoiceCard(inv, w) {}
    Text(stringResource(R.string.wx_lines), color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 15.sp)
    Column(Modifier.fillMaxWidth().surface(RoundedCornerShape(16.dp)).padding(12.dp)) {
        inv.lines.forEach { l ->
            Row(Modifier.fillMaxWidth().padding(vertical = 4.dp)) {
                Column(Modifier.weight(1f)) {
                    Text(l.rideNumber.orEmpty(), color = Pal.ink, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                    Text(l.date?.take(10).orEmpty(), color = Pal.mut, fontSize = 11.sp)
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text(amount(l.fareMinor, w), color = Pal.ink, fontSize = 13.sp)
                    Text("− " + amount(l.commissionMinor + l.taxMinor, w), color = Pal.danger, fontSize = 11.sp)
                }
            }
        }
    }
    if (inv.notes.isNotEmpty()) {
        Text(stringResource(R.string.wx_notes), color = Pal.ink, fontWeight = FontWeight.Black, fontSize = 15.sp)
        Column(Modifier.fillMaxWidth().surface(RoundedCornerShape(16.dp)).padding(12.dp)) {
            inv.notes.forEach { n ->
                val credit = n.kind == "credit"
                Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(stringResource(if (credit) R.string.wx_credit_note else R.string.wx_debit_note), color = if (credit) Pal.success else Pal.danger, fontSize = 11.sp, fontWeight = FontWeight.Bold,
                        modifier = Modifier.clip(CircleShape).background((if (credit) Pal.success else Pal.danger).copy(alpha = 0.12f)).padding(horizontal = 8.dp, vertical = 2.dp))
                    Spacer(Modifier.width(8.dp))
                    Text(n.title.orEmpty(), color = Pal.ink, fontSize = 13.sp, modifier = Modifier.weight(1f))
                    Text((if (credit) "+" else "−") + amount(n.amountMinor, w), color = if (credit) Pal.success else Pal.danger, fontSize = 13.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}

@Composable
private fun InvoiceLine(label: String, minor: Long, w: WalletDto?, bold: Boolean = false) {
    Row(Modifier.fillMaxWidth().padding(vertical = 2.dp)) {
        Text(label, color = if (bold) Pal.ink else Pal.mut, fontSize = 13.sp, fontWeight = if (bold) FontWeight.Bold else FontWeight.Normal, modifier = Modifier.weight(1f))
        Text((if (minor < 0) "− " else "") + amount(kotlin.math.abs(minor), w), color = if (minor < 0) Pal.danger else Pal.ink, fontSize = 13.sp, fontWeight = if (bold) FontWeight.Black else FontWeight.SemiBold)
    }
}

/** D-299: the phone is lost — freeze the money with a code to the number (no sign-in). */
@Composable
internal fun EmergencyFreezeDialog(dialCode: String, onClose: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var phone by remember { mutableStateOf("") }
    var code by remember { mutableStateOf("") }
    var sent by remember { mutableStateOf(false) }
    var done by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    AlertDialog(
        onDismissRequest = { if (!busy) onClose() },
        icon = { Icon(if (done) Icons.Rounded.VerifiedUser else Icons.Rounded.AcUnit, null, tint = if (done) Pal.success else Pal.info) },
        title = { Text(stringResource(R.string.wx_freeze_title), fontWeight = FontWeight.Bold) },
        text = {
            Column {
                if (done) {
                    Text(stringResource(R.string.wx_freeze_done), fontSize = 13.sp)
                } else {
                    Text(stringResource(R.string.wx_freeze_hint), fontSize = 13.sp, color = Pal.mut)
                    Spacer(Modifier.height(8.dp))
                    DorrField(phone, { phone = it.filter(Char::isDigit).take(15) }, placeholder = { Text(stringResource(R.string.wx_freeze_phone, dialCode)) })
                    if (sent) {
                        Spacer(Modifier.height(8.dp))
                        DorrField(code, { code = it.filter(Char::isDigit).take(6) }, placeholder = { Text(stringResource(R.string.wx_freeze_code)) })
                    }
                }
                error?.let { Text(it, color = Pal.danger, fontSize = 12.sp) }
            }
        },
        confirmButton = {
            if (done) {
                TextButton(onClick = onClose) { Text(stringResource(R.string.wx_ok)) }
            } else {
                TextButton(enabled = !busy && phone.length >= 6 && (!sent || code.length >= 4), onClick = {
                    busy = true; error = null
                    scope.launch {
                        val body = JsonObject().apply { addProperty("dial_code", dialCode); addProperty("phone", phone); if (sent) addProperty("code", code) }
                        val r = if (!sent) apiCall { DriverBackend.api.emergencyFreezeRequest(body) } else apiCall { DriverBackend.api.emergencyFreezeConfirm(body) }
                        when (r) {
                            is ApiResult.Ok -> if (!sent) sent = true else done = true
                            is ApiResult.Failed -> error = r.error.displayMessage
                            is ApiResult.Offline -> error = context.getString(R.string.error_offline)
                        }
                        busy = false
                    }
                }) { Text(stringResource(if (sent) R.string.wx_freeze_confirm else R.string.wx_freeze_send)) }
            }
        },
        dismissButton = { if (!done) TextButton(onClick = onClose) { Text(stringResource(R.string.cancel)) } },
    )
}
