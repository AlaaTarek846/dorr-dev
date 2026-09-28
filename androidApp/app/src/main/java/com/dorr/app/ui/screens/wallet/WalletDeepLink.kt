package com.dorr.app.ui.screens.wallet

/**
 * Something another part of the app wants the wallet to do once it's unlocked — today: a wallet
 * QR tapped in a chat ("Send money"), which goes straight to the transfer confirmation, exactly as
 * if it had been scanned. Consumed once.
 */
object WalletDeepLink {
    @Volatile
    private var pendingQr: String? = null

    fun openQr(payload: String) {
        pendingQr = payload
    }

    fun consumeQr(): String? = pendingQr.also { pendingQr = null }
}
