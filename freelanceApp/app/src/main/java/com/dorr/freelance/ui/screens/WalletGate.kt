package com.dorr.freelance.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Cake
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Email
import androidx.compose.material.icons.rounded.Fingerprint
import androidx.compose.material.icons.rounded.Key
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.PhoneAndroid
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.text.style.TextAlign
import com.dorr.freelance.ui.wallet.birthDateOrNull
import com.dorr.freelance.ui.wallet.waRise
import com.dorr.freelance.ui.wallet.WaBirthPicker
import com.dorr.freelance.ui.wallet.WaOtpBoxes
import com.dorr.freelance.ui.wallet.WaPillField
import com.dorr.freelance.ui.wallet.WaNote
import com.dorr.freelance.ui.wallet.WaError
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.LockReset
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.ChangePinRequest
import com.dorr.freelance.net.CodeBody
import com.dorr.freelance.net.CreatePinRequest
import com.dorr.freelance.net.PinStatusDto
import com.dorr.freelance.net.PinVerifyDto
import com.dorr.freelance.net.RecoverPinRequest
import com.dorr.freelance.net.RecoverySetupRequest
import com.dorr.freelance.net.WalletBiometric
import com.dorr.freelance.net.apiDone
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.net.findFragmentActivity
import com.dorr.freelance.ui.components.DateField
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.theme.ProvideAppFont
import com.dorr.freelance.ui.wallet.LocalWalletOrNull
import com.dorr.freelance.ui.wallet.OtpField
import com.dorr.freelance.ui.wallet.PadResult
import com.dorr.freelance.ui.wallet.Tone
import com.dorr.freelance.ui.wallet.Wa
import com.dorr.freelance.ui.wallet.WaButton
import com.dorr.freelance.ui.wallet.WaButtonStyle
import com.dorr.freelance.ui.wallet.WaCard
import com.dorr.freelance.ui.wallet.WaIconWell
import com.dorr.freelance.ui.wallet.WaPage
import com.dorr.freelance.ui.wallet.WaPinPad
import com.dorr.freelance.ui.wallet.WaPulse
import com.dorr.freelance.ui.wallet.WaSeal
import com.dorr.freelance.ui.wallet.WaSkeleton
import com.dorr.freelance.ui.wallet.WaStatusColumn
import com.dorr.freelance.ui.wallet.WaStatusText
import com.dorr.freelance.ui.wallet.WaStatusTitle
import com.dorr.freelance.ui.wallet.WaToastHost
import com.dorr.freelance.ui.wallet.WalletHost
import com.dorr.freelance.ui.wallet.walletNight
import kotlinx.coroutines.launch

/**
 * The wallet (FL-005), in the DORR app's wallet look (its `Wa` kit, the same colours): first a way to get the PIN back and the
 * PIN itself (the four-dot pad), then — each time the wallet is opened — the pad (or the fingerprint / face when it is on), and on
 * a phone the wallet has not seen before, a code sent to the number on file. Only then the wallet home ([WalletHomeHost]).
 * Leaving the wallet drops the PIN from memory. Errors come as the wallet's toast at the top.
 */
@Composable
fun WalletRoute(onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    val host = remember { WalletHost(scope) }
    CompositionLocalProvider(LocalWalletOrNull provides host) {
        ProvideAppFont {
            Box(Modifier.fillMaxSize().background(Wa.Bg).navigationBarsPadding().imePadding()) {
                WalletStages(onBack, host)
                WaToastHost(host)
            }
        }
    }
}

@Composable
private fun WalletStages(onBack: () -> Unit, host: WalletHost) {
    val reconnect = collectReconnectTick()
    val offlineText = stringResource(R.string.wa_error_network)
    val genericError = stringResource(R.string.wa_error_generic)
    var status by remember { mutableStateOf<PinStatusDto?>(null) }
    var trusted by rememberSaveable { mutableStateOf(false) }
    var forgot by rememberSaveable { mutableStateOf(false) }
    // A reset PIN (`must_change`) has to be replaced before the wallet opens.
    var mustChange by rememberSaveable { mutableStateOf(false) }

    // One read when the wallet opens (and when the connection comes back).
    LaunchedEffect(reconnect) {
        val result = apiCall { ApiClient.wallet.pinStatus() }
        if (result is ApiResult.Ok) status = result.data
        else if (status == null) host.showError(result.errorText(offlineText) ?: genericError)
    }
    // Leaving the wallet forgets the PIN.
    DisposableEffect(Unit) { onDispose { WalletSession.lock() } }

    val current = status
    val pin = WalletSession.pin
    BackHandler(enabled = forgot) { forgot = false }

    when {
        current == null -> WaPage(title = stringResource(R.string.wa_my_wallet), onBack = onBack) {
            WaSkeleton(Modifier.fillMaxWidth().height(172.dp), RoundedCornerShape(24.dp))
        }
        current.isFrozen -> WaPage(title = stringResource(R.string.wa_my_wallet), onBack = onBack, scroll = false) {
            WaStatusColumn {
                WaSeal(bad = true)
                WaStatusTitle(stringResource(R.string.wa_frozen_title))
                WaStatusText(stringResource(R.string.wl_frozen))
            }
        }
        !current.hasPin -> WalletSetup(current, onBack, onUnlocked = { createdPin, verify ->
            mustChange = verify.mustChange
            trusted = verify.deviceTrusted
            WalletSession.unlock(createdPin)
        }, onStatus = { status = it })
        forgot -> WalletRecover(current, onBack = { forgot = false }) {
            status = it
            forgot = false
        }
        pin == null -> WalletGate(current, onBack, onForgot = { forgot = true }) { unlockedPin, verify ->
            mustChange = verify.mustChange
            trusted = verify.deviceTrusted
            WalletSession.unlock(unlockedPin)
        }
        mustChange -> WalletChangePin(pin, forced = true, onBack = onBack) { mustChange = false }
        !trusted -> WalletDeviceTrust(pin, onBack) { trusted = true }
        else -> WalletHomeHost(onBack = onBack)
    }
}

/** A failed call as the pad reports it: a temporary lock becomes the pad's countdown, anything else its message. */
internal fun padFailure(result: ApiResult<*>, offline: String, generic: String): PadResult {
    if (result is ApiResult.Failed && result.error.errorCode == "wallet_pin_locked") {
        val until = runCatching { result.error.data?.asJsonObject?.get("locked_until")?.asString }.getOrNull()
            ?.let { runCatching { java.time.OffsetDateTime.parse(it).toInstant().toEpochMilli() }.getOrNull() }
        if (until != null) return PadResult.Locked(until)
    }
    return PadResult.Error(result.errorText(offline) ?: generic)
}

/** The PIN typed twice on the pad: [first], then the same again; a mismatch starts over. */
@Composable
private fun PinTwice(firstTitle: String, firstSub: String, onBoth: suspend (String) -> PadResult) {
    var step by rememberSaveable { mutableStateOf(0) }
    var first by rememberSaveable { mutableStateOf("") }
    val confirmTitle = stringResource(R.string.wa_pin_confirm_title)
    val confirmSub = stringResource(R.string.wa_pin_confirm_sub)
    val mismatch = stringResource(R.string.wa_pin_mismatch)
    key(step) {
        if (step == 0) {
            WaPinPad(title = firstTitle, sub = firstSub, onComplete = { entered ->
                first = entered
                step = 1
                PadResult.Reset
            })
        } else {
            WaPinPad(title = confirmTitle, sub = confirmSub, onComplete = { entered ->
                if (entered != first) {
                    step = 0
                    first = ""
                    PadResult.Error(mismatch)
                } else onBoth(entered)
            })
        }
    }
}

// ---------------------------------------------------------------- setting up: recovery first, then the PIN (as in the DORR app)

private val SETUP_METHODS = listOf("password", "birth_date", "email")

private fun methodIcon(method: String) = when (method) {
    "password" -> Icons.Rounded.Key
    "birth_date" -> Icons.Rounded.Cake
    else -> Icons.Rounded.Email
}

private fun methodTone(method: String) = when (method) {
    "password" -> Tone.Red
    "birth_date" -> Tone.Pink
    else -> Tone.Green
}

private fun methodTitle(method: String) = when (method) {
    "password" -> R.string.wa_rec_m_password
    "birth_date" -> R.string.wa_rec_m_birth_date
    else -> R.string.wa_rec_m_email
}

private fun methodDesc(method: String) = when (method) {
    "password" -> R.string.wa_rec_m_password_d
    "birth_date" -> R.string.wa_rec_m_birth_date_d
    else -> R.string.wa_rec_m_email_d
}

private val EMAIL_SHAPE = Regex("^[^@\\s]+@[^@\\s]+\\.[^@\\s]+$")

@Composable
private fun StepHeader(icon: ImageVector, tone: Tone, title: String, sub: String) {
    Column(Modifier.fillMaxWidth().padding(top = 8.dp, bottom = 18.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        WaIconWell(icon, tone, size = 58.dp, iconSize = 28.dp)
        Spacer(Modifier.height(10.dp))
        Text(title, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp, color = Wa.Ink, textAlign = TextAlign.Center)
        if (sub.isNotEmpty()) {
            Spacer(Modifier.height(4.dp))
            Text(sub, color = Wa.Mut, fontSize = 13.sp, lineHeight = 21.sp, textAlign = TextAlign.Center)
        }
    }
}

@Composable
private fun MethodRow(method: String, index: Int, onClick: () -> Unit) {
    WaCard(Modifier.fillMaxWidth().waRise(index).clickable(onClick = onClick), padding = 14.dp) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            WaIconWell(methodIcon(method), methodTone(method))
            Column(Modifier.weight(1f)) {
                Text(stringResource(methodTitle(method)), fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, color = Wa.Ink)
                Text(stringResource(methodDesc(method)), fontSize = 12.5.sp, color = Wa.Mut, lineHeight = 19.sp)
            }
            Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, null, tint = Wa.Soft)
        }
    }
}

/**
 * First-time PIN, step by step like the DORR app: choose a way to get the PIN back → its details (an e-mail is confirmed with a
 * 4-digit code) → the PIN twice → "saved". The wallet opens from "saved" with the PIN just created (not asked for again).
 */
@Composable
internal fun WalletSetup(
    status: PinStatusDto,
    onBack: () -> Unit,
    onUnlocked: (String, PinVerifyDto) -> Unit,
    onStatus: (PinStatusDto) -> Unit,
    /** Set when the freelancer already has a PIN and only switches the recovery method (proven with that PIN): it ends after the method. */
    existingPin: String? = null,
    onChanged: () -> Unit = {},
) {
    val changing = existingPin != null
    val pageTitle = stringResource(if (changing) R.string.wa_rec_change_title else R.string.wa_pin_page_title)
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.wa_error_network)
    val genericError = stringResource(R.string.wa_error_generic)
    val shortPassword = stringResource(R.string.wa_rec_password_short)
    val passwordMismatch = stringResource(R.string.wa_rec_password_mismatch)
    val birthInvalid = stringResource(R.string.wa_rec_birth_invalid)
    val emailInvalid = stringResource(R.string.wa_rec_email_invalid)
    val codeInvalid = stringResource(R.string.wa_rec_code_invalid)
    val codeSentText = stringResource(R.string.wa_rec_code_sent)

    val recovery = status.recovery
    var step by rememberSaveable {
        mutableStateOf(
            when {
                changing -> "method"
                recovery?.ready == true -> "pin"
                recovery?.method == "email" -> "code"
                else -> "method"
            },
        )
    }
    var method by rememberSaveable { mutableStateOf(recovery?.method?.takeIf { it in SETUP_METHODS } ?: "password") }
    var password by rememberSaveable { mutableStateOf("") }
    var passwordAgain by rememberSaveable { mutableStateOf("") }
    var day by rememberSaveable { mutableStateOf("") }
    var month by rememberSaveable { mutableStateOf("") }
    var year by rememberSaveable { mutableStateOf("") }
    var email by rememberSaveable { mutableStateOf(recovery?.email.orEmpty()) }
    var code by rememberSaveable { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var notice by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    // Seconds until a new code may be asked for: counted on the phone's clock, nothing is asked of the server meanwhile.
    var countdown by remember { mutableIntStateOf(0) }
    LaunchedEffect(countdown) {
        if (countdown > 0) {
            kotlinx.coroutines.delay(1000)
            countdown -= 1
        }
    }
    // The PIN is created and "saved" is showing: the wallet opens (and the status updates) only from its button.
    var created by remember { mutableStateOf<Pair<String, PinVerifyDto>?>(null) }
    var createdStatus by remember { mutableStateOf<PinStatusDto?>(null) }

    fun back() {
        error = null
        notice = null
        when (step) {
            "method" -> onBack()
            "detail", "pin" -> step = "method"
            "code" -> step = "detail"
        }
    }
    BackHandler(enabled = step != "saved") { back() }

    fun submitDetail() {
        error = when (method) {
            "password" -> when {
                password.length < 6 -> shortPassword
                password != passwordAgain -> passwordMismatch
                else -> null
            }
            "birth_date" -> if (birthDateOrNull(day, month, year) == null) birthInvalid else null
            else -> if (!EMAIL_SHAPE.matches(email.trim())) emailInvalid else null
        }
        if (error != null) return
        busy = true
        scope.launch {
            val body = when (method) {
                "password" -> RecoverySetupRequest("password", password = password, passwordConfirmation = passwordAgain)
                "birth_date" -> RecoverySetupRequest("birth_date", birthDate = birthDateOrNull(day, month, year))
                else -> RecoverySetupRequest("email", email = email.trim())
            }
            val saved = apiCall { ApiClient.wallet.setRecovery(body, existingPin) }
            if (saved !is ApiResult.Ok) {
                error = saved.errorText(offlineText) ?: genericError
            } else if (method == "email") {
                val sent = apiDone(offlineText, genericError) { ApiClient.wallet.sendRecoveryEmailCode(if (changing) true else null) }
                if (sent != null) error = sent else {
                    countdown = 60
                    step = "code"
                }
            } else {
                step = if (changing) "saved" else "pin"
            }
            busy = false
        }
    }

    fun confirmCode() {
        if (code.length != 4) {
            error = codeInvalid
            return
        }
        busy = true
        scope.launch {
            val result = apiCall { ApiClient.wallet.confirmRecoveryEmail(CodeBody(code.trim())) }
            if (result is ApiResult.Ok) {
                error = null
                step = if (changing) "saved" else "pin"
            } else error = result.errorText(offlineText) ?: genericError
            busy = false
        }
    }

    fun resendCode() {
        scope.launch {
            val sent = apiDone(offlineText, genericError) { ApiClient.wallet.sendRecoveryEmailCode(if (changing) true else null) }
            if (sent != null) error = sent else {
                error = null
                notice = codeSentText
                countdown = 60
            }
        }
    }

    when (step) {
        "method" -> WaPage(title = pageTitle, onBack = { back() }) {
            StepHeader(Icons.Rounded.Shield, Tone.Red, stringResource(R.string.wa_rec_choose_title), stringResource(R.string.wa_rec_choose_sub))
            SETUP_METHODS.forEachIndexed { index, item ->
                MethodRow(item, index) {
                    method = item
                    error = null
                    step = "detail"
                }
                Spacer(Modifier.height(10.dp))
            }
        }

        "detail" -> WaPage(
            title = pageTitle,
            onBack = { back() },
            cta = {
                WaError(error)
                WaButton(stringResource(if (method == "email") R.string.pd_send_code else R.string.wa_continue), { submitDetail() }, loading = busy)
            },
        ) {
            StepHeader(methodIcon(method), methodTone(method), stringResource(methodTitle(method)), "")
            when (method) {
                "password" -> WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
                    DorrTextField(password, { password = it; error = null }, label = stringResource(R.string.wa_rec_password), icon = Icons.Rounded.Lock, password = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password), modifier = Modifier.fillMaxWidth())
                    Spacer(Modifier.height(12.dp))
                    DorrTextField(passwordAgain, { passwordAgain = it; error = null }, label = stringResource(R.string.wa_rec_password_confirm), icon = Icons.Rounded.LockReset, password = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password), modifier = Modifier.fillMaxWidth())
                    Text(stringResource(R.string.wa_rec_password_hint), color = Wa.Mut, fontSize = 12.sp, lineHeight = 19.sp, modifier = Modifier.padding(top = 12.dp, start = 2.dp, end = 2.dp))
                }
                "birth_date" -> {
                    WaBirthPicker(day, month, year, { d, m, y -> day = d; month = m; year = y; error = null }, error = error != null)
                    Spacer(Modifier.height(12.dp))
                    WaNote(stringResource(R.string.wa_rec_birth_hint))
                }
                else -> WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
                    Text(stringResource(R.string.wa_rec_email), fontSize = 14.sp, fontWeight = FontWeight.ExtraBold, color = Wa.Ink, modifier = Modifier.padding(bottom = 10.dp))
                    WaPillField(
                        email, { email = it; error = null }, placeholder = "name@example.com", icon = Icons.Rounded.Email,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email), error = error != null,
                    )
                    Text(stringResource(R.string.wa_rec_email_hint), color = Wa.Mut, fontSize = 12.sp, lineHeight = 19.sp, modifier = Modifier.padding(top = 10.dp, start = 2.dp, end = 2.dp))
                }
            }
        }

        "code" -> WaPage(
            title = pageTitle,
            onBack = { back() },
            cta = {
                WaError(error)
                WaButton(stringResource(R.string.profile_otp_confirm), { confirmCode() }, loading = busy, enabled = code.length == 4)
            },
        ) {
            WaCard(Modifier.fillMaxWidth(), padding = 18.dp) {
                Text(
                    stringResource(R.string.wa_rec_code_sub, email.trim()),
                    color = Wa.Mut, fontSize = 13.sp, lineHeight = 22.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(18.dp))
                WaOtpBoxes(code, { code = it.filter(Char::isDigit).take(4); error = null }, error = error != null)
                Spacer(Modifier.height(16.dp))
                if (countdown > 0) {
                    Text(stringResource(R.string.profile_otp_timer, countdown), color = Wa.Mut, fontSize = 13.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth())
                } else {
                    Text(
                        stringResource(R.string.profile_otp_resend),
                        color = Wa.Red, fontSize = 13.5.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center,
                        modifier = Modifier.fillMaxWidth().clickable { resendCode() }.padding(vertical = 4.dp),
                    )
                }
                notice?.let { Text(it, color = Wa.Green, fontSize = 12.5.sp, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth().padding(top = 8.dp)) }
            }
        }

        "pin" -> WaPage(title = stringResource(R.string.wa_pin_create_title_page), onBack = { back() }, scroll = false) {
            PinTwice(stringResource(R.string.wa_gate_create_title), stringResource(R.string.wa_gate_create_sub)) { pin ->
                val result = apiCall { ApiClient.wallet.createPin(CreatePinRequest(pin, pin)) }
                if (result is ApiResult.Ok) {
                    // Typed twice just now: the wallet opens with it from "saved" instead of asking for it a third time.
                    val verified = (apiCall { ApiClient.wallet.verifyPin(pin) } as? ApiResult.Ok)?.data
                    if (verified != null) created = pin to verified
                    createdStatus = result.data
                    step = "saved"
                    PadResult.Ok
                } else padFailure(result, offlineText, genericError)
            }
        }

        else -> WaPage(title = pageTitle, onBack = {}, scroll = false) {
            WaStatusColumn {
                WaSeal()
                WaStatusTitle(stringResource(if (changing) R.string.wa_rec_changed_title else R.string.wa_pin_saved_title))
                WaStatusText(stringResource(if (changing) R.string.wa_rec_changed_text else R.string.wa_pin_saved_text))
                Spacer(Modifier.height(12.dp))
                WaButton(stringResource(R.string.wa_done), {
                    if (changing) onChanged() else {
                        created?.let { (pin, verify) -> onUnlocked(pin, verify) }
                        createdStatus?.let(onStatus)
                    }
                }, modifier = Modifier.padding(horizontal = 20.dp))
            }
        }
    }
}

// ---------------------------------------------------------------- the gate: the pad, or fingerprint / face

@Composable
private fun WalletGate(status: PinStatusDto, onBack: () -> Unit, onForgot: () -> Unit, onUnlocked: (String, PinVerifyDto) -> Unit) {
    val context = LocalContext.current
    val host = LocalWalletOrNull.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.wa_error_network)
    val genericError = stringResource(R.string.wa_error_generic)
    val bioFailed = stringResource(R.string.wa_biometric_unlock_failed)
    val biometric = remember { WalletBiometric.isEnabled(context) && WalletBiometric.isAvailable(context) }
    val lockedUntil = remember(status.lockedUntil) {
        status.lockedUntil?.let { runCatching { java.time.OffsetDateTime.parse(it).toInstant().toEpochMilli() }.getOrNull() }
    }

    suspend fun verify(pin: String): PadResult {
        val result = apiCall { ApiClient.wallet.verifyPin(pin) }
        if (result is ApiResult.Ok) {
            onUnlocked(pin, result.data)
            return PadResult.Ok
        }
        return padFailure(result, offlineText, genericError)
    }

    fun unlockWithBiometric() {
        val activity = context.findFragmentActivity() ?: return
        WalletBiometric.unlock(activity) { stored ->
            if (stored == null) return@unlock
            scope.launch {
                val outcome = verify(stored)
                if (outcome is PadResult.Error) host?.showError(bioFailed)
            }
        }
    }

    WaPage(title = stringResource(R.string.wa_my_wallet), onBack = onBack, scroll = false) {
        Column(Modifier.weight(1f)) {
            WaPinPad(
                title = stringResource(R.string.wa_gate_enter_title),
                sub = stringResource(R.string.wa_gate_enter_sub),
                onComplete = { verify(it) },
                lockedUntil = lockedUntil,
                modifier = Modifier.weight(1f),
            )
        }
        Row(Modifier.fillMaxWidth().padding(vertical = 8.dp), horizontalArrangement = Arrangement.SpaceEvenly, verticalAlignment = Alignment.CenterVertically) {
            Text(
                stringResource(R.string.wa_forgot_link), color = Wa.Red, fontSize = 13.5.sp, fontWeight = FontWeight.Bold,
                modifier = Modifier.clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClick = onForgot).padding(8.dp),
            )
            if (biometric) {
                Row(
                    Modifier.clickable(interactionSource = remember { MutableInteractionSource() }, indication = null) { unlockWithBiometric() }.padding(8.dp),
                    verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    Icon(Icons.Rounded.Fingerprint, null, tint = Wa.Red, modifier = Modifier.size(20.dp))
                    Text(stringResource(R.string.wa_biometric_unlock_button), color = Wa.Red, fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
                }
            }
        }
    }

    // With the fingerprint on, the prompt opens by itself once.
    LaunchedEffect(Unit) { if (biometric && lockedUntil == null) unlockWithBiometric() }
}

// ---------------------------------------------------------------- forgot the PIN

@Composable
internal fun WalletRecover(status: PinStatusDto, onBack: () -> Unit, onDone: (PinStatusDto) -> Unit) {
    val host = LocalWalletOrNull.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.wa_error_network)
    val genericError = stringResource(R.string.wa_error_generic)
    val sentToast = stringResource(R.string.wa_rec_code_sent)
    val method = status.recovery?.method.orEmpty()
    var password by rememberSaveable { mutableStateOf("") }
    var birth by rememberSaveable { mutableStateOf("") }
    var code by rememberSaveable { mutableStateOf("") }
    var codeSent by rememberSaveable { mutableStateOf(false) }
    // The secret first, then the new PIN on the pad, then done.
    var stage by rememberSaveable { mutableStateOf("secret") }
    var busy by remember { mutableStateOf(false) }
    var finished by remember { mutableStateOf<PinStatusDto?>(null) }
    val supported = method in listOf("password", "birth_date", "email")

    fun sendCode() {
        busy = true
        scope.launch {
            val failed = apiDone(offlineText, genericError) { ApiClient.wallet.sendRecoveryEmailCode() }
            busy = false
            if (failed == null) {
                codeSent = true
                host?.showToast(sentToast)
            } else host?.showError(failed)
        }
    }

    val done = finished
    if (done != null) {
        WaPage(title = stringResource(R.string.wa_forgot_title), onBack = { onDone(done) }, scroll = false) {
            WaStatusColumn {
                WaSeal()
                WaStatusTitle(stringResource(R.string.wa_forgot_done_title))
                WaStatusText(stringResource(R.string.wa_forgot_done_text))
                Spacer(Modifier.height(14.dp))
                WaButton(stringResource(R.string.wa_done), { onDone(done) })
            }
        }
        return
    }

    if (stage == "pin") {
        BackHandler { stage = "secret" }
        WaPage(title = stringResource(R.string.wa_forgot_title), onBack = { stage = "secret" }, scroll = false) {
            PinTwice(stringResource(R.string.wa_forgot_new_pin_title), stringResource(R.string.wa_pin_digits_sub)) { pin ->
                val body = RecoverPinRequest(
                    password = password.takeIf { method == "password" },
                    birthDate = birth.takeIf { method == "birth_date" },
                    code = code.takeIf { method == "email" },
                    pin = pin, pinConfirmation = pin,
                )
                val result = apiCall { ApiClient.wallet.recoverPin(body) }
                if (result is ApiResult.Ok) {
                    finished = result.data
                    PadResult.Ok
                } else {
                    // A wrong secret: back to it, with the server's message.
                    stage = "secret"
                    host?.showError(result.errorText(offlineText) ?: genericError)
                    PadResult.Reset
                }
            }
        }
        return
    }

    val ready = when (method) {
        "password" -> password.isNotEmpty()
        "birth_date" -> birth.isNotEmpty()
        "email" -> codeSent && code.length >= 4
        else -> false
    }
    WaPage(
        title = stringResource(R.string.wa_forgot_title),
        onBack = onBack,
        cta = if (supported) {
            {
                if (method == "email" && !codeSent) WaButton(stringResource(R.string.wl_send_code), ::sendCode, loading = busy)
                else WaButton(stringResource(R.string.wa_continue), { stage = "pin" }, enabled = ready)
            }
        } else null,
    ) {
        WaCard(Modifier.fillMaxWidth(), padding = 16.dp) {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                when (method) {
                    "password" -> {
                        Text(stringResource(R.string.wa_forgot_password_sub), color = Wa.Mut, fontSize = 13.sp)
                        DorrTextField(password, { password = it }, label = stringResource(R.string.wa_rec_password), password = true, modifier = Modifier.fillMaxWidth())
                    }
                    "birth_date" -> {
                        Text(stringResource(R.string.wa_forgot_birth_sub), color = Wa.Mut, fontSize = 13.sp)
                        DateField(birth, { birth = it }, label = stringResource(R.string.wa_rec_birth_label), modifier = Modifier.fillMaxWidth())
                    }
                    "email" -> {
                        Text(stringResource(R.string.wa_forgot_email_sub, status.recovery?.email.orEmpty()), color = Wa.Mut, fontSize = 13.sp)
                        if (codeSent) Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) { OtpField(code) { code = it.filter(Char::isDigit).take(8) } }
                    }
                    "" -> Text(stringResource(R.string.wa_forgot_no_method), color = Wa.Ink, fontSize = 14.sp)
                    else -> Text(stringResource(R.string.wl_recover_photo), color = Wa.Ink, fontSize = 14.sp)
                }
            }
        }
    }
}

// ---------------------------------------------------------------- a phone the wallet has not seen

@Composable
private fun WalletDeviceTrust(pin: String, onBack: () -> Unit, onTrusted: () -> Unit) {
    val host = LocalWalletOrNull.current
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.wa_error_network)
    val genericError = stringResource(R.string.wa_error_generic)
    var code by rememberSaveable { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }

    // One code is sent when this page opens.
    LaunchedEffect(Unit) { apiDone(offlineText, genericError) { ApiClient.wallet.sendDeviceCode(pin) }?.let { host?.showError(it) } }

    WaPage(title = stringResource(R.string.wa_device_trust_title), onBack = onBack, scroll = false) {
        WaStatusColumn {
            WaPulse(Icons.Rounded.PhoneAndroid)
            WaStatusTitle(stringResource(R.string.wa_device_trust_title))
            WaStatusText(stringResource(R.string.wa_device_trust_sub))
            Spacer(Modifier.height(16.dp))
            OtpField(code) { code = it.filter(Char::isDigit).take(8) }
            Spacer(Modifier.height(12.dp))
            WaButton(
                stringResource(R.string.wl_confirm_code),
                {
                    busy = true
                    scope.launch {
                        // The confirm answers without data on success (apiCall would read that as a failure), so apiDone.
                        val failed = apiDone(offlineText, genericError) { ApiClient.wallet.confirmDevice(pin, CodeBody(code.trim())) }
                        busy = false
                        if (failed == null) onTrusted() else host?.showError(failed)
                    }
                },
                enabled = code.length >= 4, loading = busy,
            )
        }
    }
}

// ---------------------------------------------------------------- changing the PIN (also forced after a reset)

/** The current PIN, then the new one twice, on the pad. After a reset (`must_change`) the current one is already known. */
@Composable
fun WalletChangePin(currentPin: String, forced: Boolean, onBack: () -> Unit, onDone: () -> Unit) {
    val context = LocalContext.current
    val host = LocalWalletOrNull.current
    val offlineText = stringResource(R.string.wa_error_network)
    val genericError = stringResource(R.string.wa_error_generic)
    val savedToast = stringResource(R.string.wa_pin_saved_title)
    var current by rememberSaveable { mutableStateOf(if (forced) currentPin else "") }

    WaPage(title = stringResource(R.string.wa_pin_page_title), onBack = { if (!forced) onBack() }, scroll = false) {
        if (forced) Text(stringResource(R.string.wl_must_change), color = Wa.Mut, fontSize = 13.sp, modifier = Modifier.fillMaxWidth().padding(bottom = 4.dp))
        if (current.isEmpty()) {
            WaPinPad(title = stringResource(R.string.wa_pin_current_title), sub = stringResource(R.string.wa_pin_current_sub), icon = Icons.Rounded.Lock, onComplete = { entered ->
                current = entered
                PadResult.Reset
            })
        } else {
            PinTwice(stringResource(R.string.wa_pin_new_title), stringResource(R.string.wa_pin_digits_sub)) { pin ->
                val result = apiCall { ApiClient.wallet.changePin(ChangePinRequest(current, pin, pin)) }
                if (result is ApiResult.Ok) {
                    // The fingerprint holds the old PIN: it is turned off, and turned on again with the new one.
                    if (WalletBiometric.isEnabled(context)) WalletBiometric.disable(context)
                    WalletSession.unlock(pin)
                    host?.showToast(savedToast)
                    onDone()
                    PadResult.Ok
                } else {
                    // A wrong current PIN: ask for it again.
                    if (!forced) current = ""
                    host?.showError(result.errorText(offlineText) ?: genericError)
                    PadResult.Reset
                }
            }
        }
    }
}
