package com.dorr.app.ui.screens.moving

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.map.MapEngine
import com.dorr.app.network.ApiClient
import com.dorr.app.network.MovingInventoryItemDto
import com.dorr.app.network.MovingOfferDto
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.rides.ridesAuth

/** A request to open when the moving screen shows: set by a tapped `moving_*` notification. */
object MovingLink {
    var pending by mutableStateOf<String?>(null)

    fun show(uuid: String) {
        pending = uuid
    }
}

/** The moving pages. [requestUuid]: the request a page is about, which keeps its live channel open. */
internal sealed interface MvRoute {
    val requestUuid: String? get() = null

    data object Home : MvRoute
    /** [atReview]: a request published again opens on its review, with what it still needs marked. */
    data class Wizard(val uuid: String?, val atReview: Boolean = false) : MvRoute
    data class Request(override val requestUuid: String) : MvRoute
    data class Offers(override val requestUuid: String, val decimals: Int) : MvRoute
    data class OfferDetails(override val requestUuid: String, val offer: MovingOfferDto, val decimals: Int) : MvRoute
    data class Changes(override val requestUuid: String) : MvRoute
    data class Report(override val requestUuid: String, val currency: String?, val decimals: Int) : MvRoute
    data class Inventory(override val requestUuid: String, val items: List<MovingInventoryItemDto>) : MvRoute
    data class Invoice(override val requestUuid: String, val decimals: Int) : MvRoute
    data class Tickets(override val requestUuid: String) : MvRoute
    data class NewTicket(override val requestUuid: String) : MvRoute
    data class Ticket(override val requestUuid: String, val ticket: String) : MvRoute
}

/** The page stack, and whether the service turned out to be off in this country. */
@Stable
internal class MovingNav {
    val stack = mutableStateListOf<MvRoute>(MvRoute.Home)
    var forward by mutableStateOf(true)
    var serviceOff by mutableStateOf(false)

    val top: MvRoute get() = stack.last()

    fun push(route: MvRoute) {
        forward = true
        stack.add(route)
    }

    fun pop(): Boolean {
        if (stack.size <= 1) return false
        forward = false
        stack.removeAt(stack.lastIndex)
        return true
    }

    /** This page gives way to another (a draft published, a request still being a draft). */
    fun replace(route: MvRoute) {
        forward = true
        stack[stack.lastIndex] = route
    }

    /** From anywhere (a notification): the home page with the request on it. */
    fun openRequest(uuid: String) {
        forward = true
        stack.clear()
        stack.add(MvRoute.Home)
        stack.add(MvRoute.Request(uuid))
    }

    /** Back to the request's own page once paid or done (the page reloads when it shows). */
    fun backToRequest(uuid: String) {
        forward = false
        while (stack.size > 1 && stack.last() != MvRoute.Request(uuid)) stack.removeAt(stack.lastIndex)
        if (stack.last() != MvRoute.Request(uuid)) stack.add(MvRoute.Request(uuid))
    }
}

/**
 * Furniture moving for the customer, opened from the services (module `moving`) or a notification: «طلباتي», the new
 * request's steps, the request page that follows it by status, and what comes after. The payment screen opens over it
 * (MainScreen draws it above). No page asks the server on a timer: see [MovingLiveEffect].
 */
@Composable
fun MovingHost(onExit: () -> Unit) {
    val context = LocalContext.current
    val nav = remember { MovingNav() }

    // The admin's map engine, as on the rides screen.
    LaunchedEffect(Unit) {
        MapEngine.refresh(context) { ApiClient.rides.mapConfig(ridesAuth()).data?.let { Triple(it.provider, it.mapboxToken, it.mapboxStyle) } }
    }

    val linked = MovingLink.pending
    LaunchedEffect(linked) {
        val uuid = linked ?: return@LaunchedEffect
        MovingLink.pending = null
        nav.openRequest(uuid)
    }

    // One private channel at a time: the request the open pages are about. Kept here, above the pages, so that moving
    // from one page to another of the same request never drops it.
    val live = nav.stack.lastOrNull { it.requestUuid != null }?.requestUuid
    DisposableEffect(live) {
        live?.let { ChatRealtime.watchMoving(it) }
        onDispose { live?.let { ChatRealtime.unwatchMoving(it) } }
    }

    BackHandler { if (!nav.pop()) onExit() }

    Box(Modifier.fillMaxSize().background(settingsBackground())) {
        AnimatedContent(
            targetState = nav.top,
            transitionSpec = { val side = if (nav.forward) 1 else -1; (slideInHorizontally(tween(320)) { side * it / 4 } + fadeIn(tween(260))) togetherWith fadeOut(tween(180)) },
            label = "moving-pages",
        ) { route ->
            when (route) {
                MvRoute.Home -> MovingHomePage(nav, onExit)
                is MvRoute.Wizard -> MovingWizard(route.uuid, nav, route.atReview)
                is MvRoute.Request -> MovingRequestPage(route.requestUuid, nav)
                is MvRoute.Offers -> MovingOffersPage(route.requestUuid, route.decimals, nav)
                is MvRoute.OfferDetails -> MovingOfferDetailsPage(route.requestUuid, route.offer, route.decimals, nav)
                is MvRoute.Changes -> MovingChangesPage(route.requestUuid, nav)
                is MvRoute.Report -> MovingReportPage(route.requestUuid, route.currency, route.decimals, nav)
                is MvRoute.Inventory -> MovingInventoryPage(route.items, nav)
                is MvRoute.Invoice -> MovingInvoicePage(route.requestUuid, route.decimals, nav)
                is MvRoute.Tickets -> MovingTicketsPage(route.requestUuid, nav)
                is MvRoute.NewTicket -> MovingNewTicketPage(route.requestUuid, nav)
                is MvRoute.Ticket -> MovingTicketPage(route.ticket, nav)
            }
        }
    }
}
