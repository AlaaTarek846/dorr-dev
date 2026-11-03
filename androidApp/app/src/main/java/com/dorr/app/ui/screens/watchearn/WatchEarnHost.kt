package com.dorr.app.ui.screens.watchearn

import android.content.Context
import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.slideOutVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.network.WeFeedDto
import com.dorr.app.ui.screens.wallet.Wa
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** The pages of the Watch & Earn section (docs/modules/watch-earn/APP.md). */
sealed interface WeRoute {
    data object Home : WeRoute

    /** The player over the feed loaded on the landing page. */
    data class Player(val feed: WeFeedDto) : WeRoute

    data object Rewards : WeRoute

    /** Create an ad, or edit one (`form.edit`). The form rides in the route so a trip to fund the ad wallet keeps it. */
    data class Wizard(val form: AdForm) : WeRoute

    data class MyAds(val status: String? = null) : WeRoute

    data class Stats(val adId: String) : WeRoute

    /** The ad wallet; [fundMinor] opens the fund sheet with that amount (from the wizard or a budget top-up) and comes back. */
    data class AdWallet(val fundMinor: Long? = null) : WeRoute

    /** One ad for a viewer who already saw it (from a push): the media, the text and the live comments. */
    data class AdPage(val adId: String) : WeRoute
}

/**
 * Opens the section from anywhere (wallet card, chat list card, Home banner, Account row, a tapped push).
 * [open] drives the overlay in MainScreen; [pending] is a page to go to once the section shows.
 */
object WatchEarnLink {
    var open by mutableStateOf(false)
        private set
    var pending by mutableStateOf<WeRoute?>(null)

    /** Last thing the feed said about this country: null = not known yet; false hides the entry cards. */
    var knownEnabled by mutableStateOf<Boolean?>(null)

    fun show(route: WeRoute? = null) {
        pending = route
        open = true
    }

    fun close() {
        open = false
        pending = null
    }

    /**
     * A tapped Watch & Earn push: `watch_earn.ad.*` → that ad's stats; `watch_earn.rewards` → my rewards;
     * a comment report's answer, by [role]: `reporter` (the advertiser) → the ad's stats, `author` (who wrote the
     * comment) → the ad's page with its comments. Older pushes without a role fall back to the event's suffix.
     */
    fun openFromPush(event: String, adId: String?, role: String? = null) {
        val reportRole = role ?: when {
            event.endsWith("_reporter") -> "reporter"
            event.endsWith("_author") -> "author"
            else -> null
        }
        val route = when {
            event.startsWith("watch_earn.ad.") && adId != null -> WeRoute.Stats(adId)
            event == "watch_earn.rewards" -> WeRoute.Rewards
            event.startsWith("watch_earn.comment_report_") && adId != null && reportRole == "reporter" -> WeRoute.Stats(adId)
            event.startsWith("watch_earn.comment_report_") && adId != null && reportRole == "author" -> WeRoute.AdPage(adId)
            else -> WeRoute.Home
        }
        show(route)
    }
}

/** Phone-only preferences: the slim chat-list card can be hidden, and turned back on from the chat settings. */
object WatchEarnPrefs {
    private const val PREFS = "dorr_watch_earn"
    private var loaded = false

    var chatCardHidden by mutableStateOf(false)
        private set

    fun load(context: Context) {
        if (loaded) return
        chatCardHidden = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getBoolean("chat_card_hidden", false)
        loaded = true
    }

    fun setChatCardHidden(context: Context, hidden: Boolean) {
        chatCardHidden = hidden
        loaded = true
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putBoolean("chat_card_hidden", hidden).apply()
    }
}

data class WeToast(val text: String, val error: Boolean, val id: Long = System.nanoTime())

/** The section's page stack and its toast — one per visit. */
@Stable
class WeHost(val scope: CoroutineScope, var onExit: () -> Unit) {
    val stack = mutableStateListOf<WeRoute>(WeRoute.Home)
    val current: WeRoute get() = stack.last()

    var forward by mutableStateOf(true)
        private set
    var toast by mutableStateOf<WeToast?>(null)
        private set
    private var toastJob: Job? = null

    fun push(route: WeRoute) {
        forward = true
        stack.add(route)
    }

    /** Swaps the page on top (the wizard → my ads after sending, the player → my rewards). */
    fun replace(route: WeRoute) {
        forward = true
        stack[stack.lastIndex] = route
    }

    fun pop() {
        if (stack.size <= 1) {
            onExit()
            return
        }
        forward = false
        stack.removeAt(stack.lastIndex)
    }

    /** A page asked for from outside (a push): on top of the landing page. */
    fun open(route: WeRoute) {
        forward = true
        while (stack.size > 1) stack.removeAt(stack.lastIndex)
        if (route != WeRoute.Home) stack.add(route)
    }

    fun showToast(text: String, error: Boolean = false) {
        if (text.isBlank()) return
        toast = WeToast(text, error)
        toastJob?.cancel()
        toastJob = scope.launch {
            delay(if (error) 3200 else 2300)
            toast = null
        }
    }
}

val LocalWe = staticCompositionLocalOf<WeHost> { error("WeHost missing — wrap Watch & Earn pages in WatchEarnHost") }

/** The section: its pages slide in and out like the wallet's, a toast floats on top, back walks the stack. */
@Composable
fun WatchEarnHost(onExit: () -> Unit) {
    val scope = rememberCoroutineScope()
    val host = remember { WeHost(scope, onExit) }
    host.onExit = onExit

    // A page asked for by a push or an entry point (also while the section is already open).
    val pending = WatchEarnLink.pending
    LaunchedEffect(pending) {
        if (pending != null) {
            host.open(pending)
            WatchEarnLink.pending = null
        }
    }

    BackHandler { host.pop() }

    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val current = host.current
    val dark = current is WeRoute.Player
    CompositionLocalProvider(LocalWe provides host) {
        Box(
            Modifier
                .fillMaxSize()
                .background(if (dark) Color.Black else Wa.Bg)
                // Nothing behind the section reacts to touches.
                .pointerInput(Unit) {}
                .systemBarsPadding()
                .imePadding(),
        ) {
            AnimatedContent(
                targetState = current,
                transitionSpec = {
                    val sign = if (host.forward != rtl) 1 else -1
                    (slideInHorizontally(tween(320, easing = FastOutSlowInEasing)) { it * sign / 3 } + fadeIn(tween(260))) togetherWith
                        (slideOutHorizontally(tween(280, easing = FastOutSlowInEasing)) { -it * sign / 4 } + fadeOut(tween(200)))
                },
                label = "weRoute",
            ) { route ->
                when (route) {
                    WeRoute.Home -> WatchEarnHome()
                    is WeRoute.Player -> WatchEarnPlayer(route.feed)
                    WeRoute.Rewards -> WatchEarnRewards()
                    is WeRoute.Wizard -> AdWizard(route.form)
                    is WeRoute.MyAds -> MyAds(route.status)
                    is WeRoute.Stats -> AdStats(route.adId)
                    is WeRoute.AdWallet -> AdWallet(route.fundMinor)
                    is WeRoute.AdPage -> WatchEarnAdPage(route.adId)
                }
            }

            val toast = host.toast
            AnimatedVisibility(
                visible = toast != null,
                enter = slideInVertically(tween(260)) { -it } + fadeIn(tween(200)),
                exit = slideOutVertically(tween(220)) { -it } + fadeOut(tween(180)),
                modifier = Modifier.align(Alignment.TopCenter),
            ) {
                var last by remember { mutableStateOf(toast) }
                if (toast != null) last = toast
                last?.let { WeToastView(it) }
            }
        }
    }
}

@Composable
private fun WeToastView(toast: WeToast) {
    val shape = RoundedCornerShape(18.dp)
    val color = if (toast.error) Wa.Danger else We.Withdrawable
    Row(
        Modifier
            .padding(horizontal = 16.dp, vertical = 10.dp)
            .fillMaxWidth()
            .shadow(14.dp, shape, ambientColor = Color(0x33111928), spotColor = Color(0x44111928))
            .clip(shape)
            .background(Wa.Surface)
            .padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(if (toast.error) Icons.Rounded.ErrorOutline else Icons.Rounded.CheckCircle, null, tint = color, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(10.dp))
        Text(toast.text, color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
    }
}
