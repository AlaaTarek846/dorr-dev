package com.dorr.app.ui.screens.wallet

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier

/**
 * The wallet's top-up, opened straight on its amount page from anywhere in the app (FL-501: "not enough in your wallet" → top up the
 * missing amount): `TopupLauncher.open(shortfallMinor) { /* closed */ }`. It slides up over the current screen (TopupOverlay, in
 * MainScreen); leaving it — after paying or not — calls back, so the screen that opened it reads its numbers once more.
 */
object TopupLauncher {
    /** What is open: the amount to top up (or -1 for the whole wallet), whether it is the freelance international wallet, and the callback. */
    internal data class Open(val minor: Long, val international: Boolean, val onClosed: () -> Unit)

    internal var pending by mutableStateOf<Open?>(null)
        private set

    /** `international`: the freelance wallet in dollars (`mobile/v1/freelance/wallet`), not the local one. */
    fun open(minor: Long, international: Boolean = false, onClosed: () -> Unit = {}) {
        com.dorr.app.network.WalletCountry.international = international
        pending = Open(minor, international, onClosed)
    }

    /** The whole freelance international wallet (balance, top-up, statement, PIN), over the current screen. */
    fun openInternationalWallet(onClosed: () -> Unit = {}) {
        com.dorr.app.network.WalletCountry.international = true
        pending = Open(-1, true, onClosed)
    }

    internal fun close() {
        val done = pending?.onClosed
        pending = null
        com.dorr.app.network.WalletCountry.international = false
        done?.invoke()
    }
}

/** Drawn once, over the main screen: the top-up while one is open. */
@Composable
fun TopupOverlay() {
    val current = TopupLauncher.pending
    var shown by remember { mutableStateOf(current) }
    if (current != null) shown = current
    AnimatedVisibility(
        visible = current != null,
        enter = slideInVertically(tween(420, easing = FastOutSlowInEasing)) { it } + fadeIn(tween(320)),
        exit = slideOutVertically(tween(340, easing = FastOutSlowInEasing)) { it } + fadeOut(tween(260)),
    ) {
        val open = shown ?: return@AnimatedVisibility
        val minor = open.minor
        if (minor < 0) {
            // The whole wallet, behind its PIN gate like the wallet tab.
            androidx.compose.runtime.key(shown) {
                Box(Modifier.fillMaxSize().background(Wa.Bg).systemBarsPadding().imePadding()) {
                    WalletScreen(onExit = { TopupLauncher.close() })
                }
            }
            return@AnimatedVisibility
        }
        androidx.compose.runtime.key(shown) {
            val scope = rememberCoroutineScope()
            val host = remember { WalletHost(scope, onExit = { TopupLauncher.close() }, start = WaRoute.TopupAmount(minor)) }
            // It lands straight on the top-up, so the PIN comes first, exactly like opening the wallet.
            var unlocked by remember { mutableStateOf(false) }
            CompositionLocalProvider(LocalWallet provides host, LocalWalletOrNull provides host) {
                WalletFont {
                    Box(Modifier.fillMaxSize().background(Wa.Bg).systemBarsPadding().imePadding()) {
                        if (!unlocked) {
                            BackHandler { TopupLauncher.close() }
                            WaGate(onUnlocked = { unlocked = true }, onCancel = { TopupLauncher.close() })
                        } else {
                            BackHandler { host.pop() }
                            WalletPagesFor(host)
                            WaSheetHost(host)
                        }
                        WaToastHost(host)
                    }
                }
            }
        }
    }
}
