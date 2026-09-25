package com.dorr.app.ui.screens.wallet

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.Warning
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ChangePinRequest
import com.dorr.app.network.CreatePinRequest
import com.dorr.app.network.apiFailure
import kotlinx.coroutines.CancellationException

/**
 * Create the wallet PIN, or change it (current → new → confirm). Does its own verification: a wrong
 * current PIN sends the person back to the first step. Ends on a "saved" seal.
 */
@Composable
fun WalletPinSettings() {
    val host = LocalWallet.current
    val networkError = stringResource(R.string.wa_error_network)
    val mismatch = stringResource(R.string.wa_pin_mismatch)
    val textCurrent = stringResource(R.string.wa_pin_current_title) to stringResource(R.string.wa_pin_current_sub)
    val textNew = stringResource(R.string.wa_pin_new_title) to stringResource(R.string.wa_pin_digits_sub)
    val textCreate = stringResource(R.string.wa_pin_create_title_page) to stringResource(R.string.wa_pin_digits_sub)
    val textConfirm = stringResource(R.string.wa_pin_confirm_title) to stringResource(R.string.wa_pin_confirm_sub)

    var changing by remember { mutableStateOf<Boolean?>(null) }
    var failed by remember { mutableStateOf<String?>(null) }
    var attempt by remember { mutableIntStateOf(0) }
    var step by remember { mutableStateOf("current") }
    var current by remember { mutableStateOf("") }
    var fresh by remember { mutableStateOf("") }
    var saved by remember { mutableStateOf(false) }

    LaunchedEffect(attempt) {
        failed = null
        changing = null
        try {
            val status = ApiClient.wallet.pinStatus(walletAuth()).data
            changing = status?.hasPin
            step = if (status?.hasPin == true) "current" else "new"
            if (status == null) failed = networkError
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            failed = e.apiFailure().message ?: networkError
        }
    }

    WaPage(title = stringResource(R.string.wa_pin_page_title), onBack = { host.pop() }, scroll = false) {
        when {
            failed != null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                WaEmpty(
                    Icons.Rounded.Warning, Tone.Gray, stringResource(R.string.wa_load_failed), failed.orEmpty(),
                    action = { WaButton(stringResource(R.string.wa_retry), { attempt++ }, style = WaButtonStyle.Ghost, icon = Icons.Rounded.Refresh, modifier = Modifier.padding(horizontal = 60.dp)) },
                )
            }
            changing == null -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                WaSkeleton(Modifier.fillMaxWidth().padding(24.dp).size(300.dp), RoundedCornerShape(24.dp))
            }
            saved -> WaStatusColumn {
                WaSeal()
                WaStatusTitle(stringResource(R.string.wa_pin_saved_title))
                WaStatusText(stringResource(R.string.wa_pin_saved_text))
                androidx.compose.foundation.layout.Spacer(Modifier.padding(top = 12.dp))
                WaButton(stringResource(R.string.wa_done), { host.pop() }, modifier = Modifier.padding(horizontal = 20.dp))
            }
            else -> Box(Modifier.fillMaxSize().padding(horizontal = 8.dp)) {
                val isChange = changing == true
                val (title, sub) = when (step) {
                    "current" -> textCurrent
                    "new" -> if (isChange) textNew else textCreate
                    else -> textConfirm
                }
                WaPinPad(
                    title = title, sub = sub, icon = Icons.Rounded.Shield,
                    modifier = Modifier.fillMaxSize().waRise(0),
                    onComplete = { pin ->
                        when (step) {
                            "current" -> {
                                current = pin
                                step = "new"
                                PadResult.Reset
                            }
                            "new" -> {
                                fresh = pin
                                step = "confirm"
                                PadResult.Reset
                            }
                            else -> {
                                if (pin != fresh) {
                                    step = "new"
                                    return@WaPinPad PadResult.Error(mismatch)
                                }
                                try {
                                    if (isChange) ApiClient.wallet.changePin(walletAuth(), ChangePinRequest(current, fresh, pin))
                                    else ApiClient.wallet.createPin(walletAuth(), CreatePinRequest(fresh, pin))
                                } catch (e: CancellationException) {
                                    throw e
                                } catch (e: Exception) {
                                    // A wrong current PIN sends the person back to the start; anything else retries.
                                    step = if (isChange) "current" else "new"
                                    val failure = e.apiFailure()
                                    return@WaPinPad PadResult.Error(if (failure.httpStatus == null) networkError else failure.message ?: networkError)
                                }
                                saved = true
                                PadResult.Ok
                            }
                        }
                    },
                )
            }
        }
    }
}
