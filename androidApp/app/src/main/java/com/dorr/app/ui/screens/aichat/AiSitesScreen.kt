package com.dorr.app.ui.screens.aichat

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import com.dorr.app.R
import com.dorr.app.network.AiSiteOfferDto

private sealed interface SitesView {
    data object Home : SitesView
    data class Form(val offer: AiSiteOfferDto?) : SitesView
    data class Detail(val id: Int) : SitesView
}

internal val SITE_TYPES = listOf("portfolio", "business", "store_showcase", "restaurant", "personal", "landing", "other")

internal fun siteTypeLabel(type: String): Int = when (type) {
    "portfolio" -> R.string.ai_sites_type_portfolio
    "business" -> R.string.ai_sites_type_business
    "store_showcase" -> R.string.ai_sites_type_store_showcase
    "restaurant" -> R.string.ai_sites_type_restaurant
    "personal" -> R.string.ai_sites_type_personal
    "landing" -> R.string.ai_sites_type_landing
    else -> R.string.ai_sites_type_other
}

internal fun siteStatusLabel(status: String): Int = when (status) {
    "generating" -> R.string.ai_sites_status_generating
    "ready" -> R.string.ai_sites_status_ready
    "failed" -> R.string.ai_sites_status_failed
    else -> R.string.ai_sites_status_draft
}

/**
 * The AI website builder: list of the customer's sites, a one-shot brief form (plan allowance or a
 * standalone purchase behind the wallet PIN), and a detail page to preview, ask for changes, restore an
 * older version or retry a failed first build. The generated site itself opens in the browser - it is
 * served by the backend on its own capability link, never rendered inside the app.
 */

@Composable
internal fun AiSitesScreen(night: Boolean, onExit: () -> Unit) {
    var view by remember { mutableStateOf<SitesView>(SitesView.Home) }

    BackHandler {
        if (view is SitesView.Home) onExit() else view = SitesView.Home
    }

    CompositionLocalProvider(LocalSiteNight provides night) {
        val c = sitePalette()
        Column(Modifier.fillMaxSize().background(c.bg)) {
            val form = view is SitesView.Form
            SiteTopBar(
                title = stringResource(if (form) R.string.ai_sites_new else R.string.ai_sites_title),
                onBack = { if (view is SitesView.Home) onExit() else view = SitesView.Home },
            )
            when (val v = view) {
                is SitesView.Home -> SitesHome(onNew = { view = SitesView.Form(it) }, onOpen = { view = SitesView.Detail(it) })
                is SitesView.Form -> SitesForm(night, v.offer, onDone = { view = SitesView.Detail(it) })
                is SitesView.Detail -> SitesDetail(night, v.id, onDeleted = { view = SitesView.Home })
            }
        }
    }
}
