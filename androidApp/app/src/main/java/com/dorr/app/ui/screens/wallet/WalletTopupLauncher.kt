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
    internal var pending by mutableStateOf<Pair<Long, () -> Unit>?>(null)
        private set

    fun open(minor: Long, onClosed: () -> Unit = {}) {
        pending = minor to onClosed
    }

    internal fun close() {
        val done = pending?.second
        pending = null
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
        val (minor, _) = shown ?: return@AnimatedVisibility
        androidx.compose.runtime.key(shown) {
            val scope = rememberCoroutineScope()
            val host = remember { WalletHost(scope, onExit = { TopupLauncher.close() }, start = WaRoute.TopupAmount(minor)) }
            CompositionLocalProvider(LocalWallet provides host) {
                Box(Modifier.fillMaxSize().background(Wa.Bg).systemBarsPadding().imePadding()) {
                    BackHandler { host.pop() }
                    WalletPagesFor(host)
                    WaSheetHost(host)
                    WaToastHost(host)
                }
            }
        }
    }
}
