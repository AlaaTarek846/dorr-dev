package com.dorr.app.ui.screens.marketing

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
import androidx.compose.runtime.mutableIntStateOf
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
import com.dorr.app.chat.ChatRealtime
import com.dorr.app.network.MkStatusDto
import com.dorr.app.ui.screens.wallet.Wa
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** The pages of the AI marketing director (docs/marketing_tasks.md). */
sealed interface MkRoute {
    data object Home : MkRoute

    data object Packages : MkRoute

    data class Project(val id: String) : MkRoute

    data class Discovery(val projectId: String) : MkRoute

    data class Research(val runId: String) : MkRoute

    data class Plan(val planId: String) : MkRoute

    data class Finance(val planId: String) : MkRoute

    data class Team(val projectId: String) : MkRoute

    // Phase B.
    data class Calendar(val projectId: String) : MkRoute

    data class Content(val projectId: String, val itemId: String) : MkRoute

    data class Studio(val projectId: String) : MkRoute

    data class Budgets(val projectId: String) : MkRoute

    data class Results(val projectId: String) : MkRoute

    data class Report(val projectId: String) : MkRoute

    // Phase C.
    data class Platforms(val projectId: String) : MkRoute

    data class Campaigns(val projectId: String) : MkRoute

    data class Campaign(val projectId: String, val campaignId: String) : MkRoute

    // Phase D.
    data class Optimise(val projectId: String) : MkRoute
}

/** Opens the section from anywhere (Account row, the Services tab's `marketing` tile). */
object MarketingLink {
    var open by mutableStateOf(false)
        private set
    var pending by mutableStateOf<MkRoute?>(null)

    fun show(route: MkRoute? = null) {
        pending = route
        open = true
    }

    fun close() {
        open = false
        pending = null
    }
}

data class MkToast(val text: String, val error: Boolean, val id: Long = System.nanoTime())

/**
 * The section's page stack, its toast, and two shared signals: [status] (the package, read once on open and after a
 * purchase) and [changes] — bumped by every `marketing.job.changed` push so open pages reload once (no polling).
 */
@Stable
class MkHost(val scope: CoroutineScope, var onExit: () -> Unit) {
    val stack = mutableStateListOf<MkRoute>(MkRoute.Home)
    val current: MkRoute get() = stack.last()

    var forward by mutableStateOf(true)
        private set
    var toast by mutableStateOf<MkToast?>(null)
        private set
    private var toastJob: Job? = null

    var status by mutableStateOf<MkStatusDto?>(null)
    var statusTick by mutableIntStateOf(0)
        private set
    var changes by mutableIntStateOf(0)
        private set

    /** Project id of the last push (pages of another project ignore it). */
    var changedProject by mutableStateOf<String?>(null)
        private set

    fun push(route: MkRoute) {
        forward = true
        stack.add(route)
    }

    fun replace(route: MkRoute) {
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

    fun open(route: MkRoute) {
        forward = true
        while (stack.size > 1) stack.removeAt(stack.lastIndex)
        if (route != MkRoute.Home) stack.add(route)
    }

    fun reloadStatus() {
        statusTick++
    }

    fun changed(projectId: String?) {
        changedProject = projectId
        changes++
    }

    fun showToast(text: String, error: Boolean = false) {
        if (text.isBlank()) return
        toast = MkToast(text, error)
        toastJob?.cancel()
        toastJob = scope.launch {
            delay(if (error) 3400 else 2300)
            toast = null
        }
    }
}

val LocalMk = staticCompositionLocalOf<MkHost> { error("MkHost missing — wrap marketing pages in MarketingHost") }

@Composable
fun MarketingHost(onExit: () -> Unit) {
    val scope = rememberCoroutineScope()
    val host = remember { MkHost(scope, onExit) }
    host.onExit = onExit

    val pending = MarketingLink.pending
    LaunchedEffect(pending) {
        if (pending != null) {
            host.open(pending)
            MarketingLink.pending = null
        }
    }

    // One reload per job move, for every open page of that project.
    LaunchedEffect(Unit) {
        ChatRealtime.events.collect { event ->
            if (event.name == "marketing.job.changed") {
                host.changed(event.data.get("project_id")?.takeIf { it.isJsonPrimitive }?.asString)
            }
            // A budget crossed its alert line or its cap (the notification's `data` carries the project).
            // A platform connected (OAuth came back) or a campaign moved.
            if (event.name == "marketing.integration.changed" || event.name == "marketing.campaign.changed") {
                host.changed(event.data.get("project_id")?.takeIf { it.isJsonPrimitive }?.asString)
            }
            if (event.name == "marketing.budget.alert") {
                val inner = event.data.get("data")?.takeIf { it.isJsonObject }?.asJsonObject ?: event.data
                host.changed(inner.get("project_id")?.takeIf { it.isJsonPrimitive }?.asString)
            }
        }
    }

    BackHandler { host.pop() }

    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    CompositionLocalProvider(LocalMk provides host) {
        Box(
            Modifier
                .fillMaxSize()
                .background(Wa.Bg)
                .pointerInput(Unit) {}
                .systemBarsPadding()
                .imePadding(),
        ) {
            AnimatedContent(
                targetState = host.current,
                transitionSpec = {
                    val sign = if (host.forward != rtl) 1 else -1
                    (slideInHorizontally(tween(320, easing = FastOutSlowInEasing)) { it * sign / 3 } + fadeIn(tween(260))) togetherWith
                        (slideOutHorizontally(tween(280, easing = FastOutSlowInEasing)) { -it * sign / 4 } + fadeOut(tween(200)))
                },
                label = "mkRoute",
            ) { route ->
                when (route) {
                    MkRoute.Home -> MarketingHome()
                    MkRoute.Packages -> MarketingPackages()
                    is MkRoute.Project -> MarketingProjectPage(route.id)
                    is MkRoute.Discovery -> MarketingDiscovery(route.projectId)
                    is MkRoute.Research -> MarketingResearchPage(route.runId)
                    is MkRoute.Plan -> MarketingPlanPage(route.planId)
                    is MkRoute.Finance -> MarketingFinance(route.planId)
                    is MkRoute.Team -> MarketingTeam(route.projectId)
                    is MkRoute.Calendar -> MarketingCalendar(route.projectId)
                    is MkRoute.Content -> MarketingContentPage(route.projectId, route.itemId)
                    is MkRoute.Studio -> MarketingStudio(route.projectId)
                    is MkRoute.Budgets -> MarketingBudgets(route.projectId)
                    is MkRoute.Results -> MarketingResultsPage(route.projectId)
                    is MkRoute.Report -> MarketingReport(route.projectId)
                    is MkRoute.Platforms -> MarketingPlatforms(route.projectId)
                    is MkRoute.Campaigns -> MarketingCampaigns(route.projectId)
                    is MkRoute.Campaign -> MarketingCampaignPage(route.projectId, route.campaignId)
                    is MkRoute.Optimise -> MarketingOptimise(route.projectId)
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
                last?.let { MkToastView(it) }
            }
        }
    }
}

@Composable
private fun MkToastView(toast: MkToast) {
    val shape = RoundedCornerShape(18.dp)
    val color = if (toast.error) Wa.Danger else Wa.Green
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
        Text(toast.text, color = Wa.Ink, fontSize = 13.5.sp, fontWeight = FontWeight.Bold, lineHeight = 20.sp)
    }
}
