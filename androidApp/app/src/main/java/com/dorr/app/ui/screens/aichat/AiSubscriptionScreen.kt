package com.dorr.app.ui.screens.aichat

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ArrowBackIosNew
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import com.dorr.app.R
import com.dorr.app.network.AiAutoRenewRequestDto
import com.dorr.app.network.AiChangePlanRequestDto
import com.dorr.app.network.AiPlanDto
import com.dorr.app.network.AiSubscribeRequestDto
import com.dorr.app.network.AiSubscriptionDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.screens.wallet.PadResult
import com.dorr.app.ui.screens.wallet.WaPinPad
import com.dorr.app.ui.screens.wallet.walletAuth
import kotlinx.coroutines.launch
import java.text.DecimalFormat
import java.text.DecimalFormatSymbols
import java.time.Instant
import java.time.format.DateTimeFormatter
import java.util.Locale

/**
 * Professional AI subscription system linked to the wallet (2026-09-29):
 * browse plans, see the current subscription (status/auto-renew/grace
 * period), subscribe or switch plans (behind the wallet PIN, exactly like
 * every other money-moving screen — see [AiWalletPinSheet]), and toggle
 * auto-renewal. Reached from AiChatHost, either proactively (the "Manage
 * subscription" entry) or from the usage-denied state in
 * [AiConversationPage] (trial ended / subscription suspended / no plan).
 */
private sealed interface PinAction {
    val plan: AiPlanDto

    data class Subscribe(override val plan: AiPlanDto) : PinAction

    data class ChangePlan(override val plan: AiPlanDto) : PinAction
}

@Composable
internal fun AiSubscriptionScreen(night: Boolean, onExit: () -> Unit) {
    val scope = rememberCoroutineScope()
    val bg = if (night) Ai.bgDark else Ai.bgLight
    val ink = if (night) Ai.inkDark else Ai.inkLight
    val mut = if (night) Ai.mutDark else Ai.mutLight
    val surface = if (night) Ai.surfaceDark else Ai.surfaceLight

    var loading by remember { mutableStateOf(true) }
    var current by remember { mutableStateOf<AiSubscriptionDto?>(null) }
    var plans by remember { mutableStateOf<List<AiPlanDto>>(emptyList()) }
    var togglingAutoRenew by remember { mutableStateOf(false) }
    var pinAction by remember { mutableStateOf<PinAction?>(null) }
    var noPinMessage by remember { mutableStateOf(false) }

    suspend fun load() {
        loading = true
        runCatching { ApiClient.aiChat.currentSubscription(chatAuth()).data }.onSuccess { current = it }
        runCatching { ApiClient.aiChat.subscriptionPlans(chatAuth()).data.orEmpty() }.onSuccess { plans = it }
        loading = false
    }

    LaunchedEffect(Unit) { load() }

    BackHandler { onExit() }

    fun requestAction(target: PinAction) {
        scope.launch {
            val status = runCatching { ApiClient.wallet.pinStatus(walletAuth()).data }.getOrNull()
            if (status?.hasPin == false) {
                noPinMessage = true
            } else {
                pinAction = target
            }
        }
    }

    Column(Modifier.fillMaxSize().background(bg)) {
        Row(
            Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 8.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(Modifier.size(38.dp).clip(CircleShape).clickable { onExit() }, contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.ArrowBackIosNew, contentDescription = null, tint = ink, modifier = Modifier.size(18.dp))
            }
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.ai_subscription_title), color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
        }

        if (loading) {
            Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                CircularProgressIndicator(color = Ai.Red)
            }
        } else {
            LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), contentPadding = androidx.compose.foundation.layout.PaddingValues(bottom = 32.dp)) {
                item {
                    CurrentSubscriptionCard(
                        subscription = current,
                        night = night,
                        ink = ink,
                        mut = mut,
                        surface = surface,
                        togglingAutoRenew = togglingAutoRenew,
                        onToggleAutoRenew = { enabled ->
                            scope.launch {
                                togglingAutoRenew = true
                                runCatching { ApiClient.aiChat.updateAutoRenew(chatAuth(), AiAutoRenewRequestDto(enabled)).data }
                                    .onSuccess { current = it }
                                togglingAutoRenew = false
                            }
                        },
                    )
                    Spacer(Modifier.height(20.dp))
                }

                item {
                    Text(
                        stringResource(R.string.ai_subscription_available_plans),
                        color = ink,
                        fontWeight = FontWeight.Bold,
                        fontSize = 14.sp,
                        modifier = Modifier.padding(bottom = 10.dp),
                    )
                }

                if (plans.isEmpty()) {
                    item {
                        Text(stringResource(R.string.ai_subscription_empty_plans), color = mut, fontSize = 13.sp)
                    }
                }

                items(plans, key = { it.id }) { plan ->
                    val isCurrent = current?.status == "active" && current?.plan?.id == plan.id
                    PlanCard(
                        plan = plan,
                        isCurrent = isCurrent,
                        night = night,
                        ink = ink,
                        mut = mut,
                        surface = surface,
                        onAction = {
                            val hasBillableCurrent = current?.status == "active" && current?.currentPlanPrice != null
                            requestAction(if (hasBillableCurrent) PinAction.ChangePlan(plan) else PinAction.Subscribe(plan))
                        },
                    )
                    Spacer(Modifier.height(12.dp))
                }
            }
        }
    }

    if (noPinMessage) {
        AiInfoDialog(
            night = night,
            message = stringResource(R.string.ai_subscription_no_pin_set),
            onDismiss = { noPinMessage = false },
        )
    }

    val action = pinAction
    if (action != null) {
        AiWalletPinSheet(
            night = night,
            subtitle = stringResource(
                if (action is PinAction.Subscribe) R.string.ai_subscription_pin_subtitle_subscribe else R.string.ai_subscription_pin_subtitle_change,
            ),
            onDismiss = { pinAction = null },
            onSubmit = { pin ->
                val result = runCatching {
                    when (action) {
                        is PinAction.Subscribe -> ApiClient.aiChat.subscribeToPlan(chatAuth(), pin, AiSubscribeRequestDto(action.plan.id, true)).data
                        is PinAction.ChangePlan -> ApiClient.aiChat.changeSubscriptionPlan(chatAuth(), pin, AiChangePlanRequestDto(action.plan.id)).data
                    }
                }
                result.fold(
                    onSuccess = { updated ->
                        current = updated
                        PadResult.Ok
                    },
                    onFailure = { e ->
                        val failure = e.apiFailure()
                        val until = failure.lockedUntil?.let { runCatching { Instant.parse(it).toEpochMilli() }.getOrNull() }
                        if (failure.errorCode == "wallet_pin_locked" && until != null) {
                            PadResult.Locked(until)
                        } else {
                            PadResult.Error(failure.message ?: "")
                        }
                    },
                )
            },
        )
    }
}

@Composable
private fun CurrentSubscriptionCard(
    subscription: AiSubscriptionDto?,
    night: Boolean,
    ink: Color,
    mut: Color,
    surface: Color,
    togglingAutoRenew: Boolean,
    onToggleAutoRenew: (Boolean) -> Unit,
) {
    Column(
        Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(surface).padding(16.dp),
    ) {
        Text(stringResource(R.string.ai_subscription_current), color = mut, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        Spacer(Modifier.height(6.dp))

        if (subscription == null || subscription.status != "active") {
            Text(stringResource(R.string.ai_subscription_no_plan), color = ink, fontSize = 14.sp)

            if (subscription?.status == "suspended") {
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.ai_subscription_suspended_banner), color = Ai.Red, fontSize = 12.5.sp)
            }

            return@Column
        }

        Text(subscription.plan?.name ?: "-", color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)

        val days = subscription.daysRemaining
        if (days != null) {
            Spacer(Modifier.height(4.dp))
            Text(
                if (days <= 0) stringResource(R.string.ai_subscription_ends_today) else stringResource(R.string.ai_subscription_days_remaining, days),
                color = mut,
                fontSize = 12.5.sp,
            )
        }

        if (subscription.inGracePeriod && subscription.graceEndsAt != null) {
            Spacer(Modifier.height(8.dp))
            Text(
                stringResource(R.string.ai_subscription_grace_warning, formatDate(subscription.graceEndsAt)),
                color = Ai.Red,
                fontSize = 12.5.sp,
            )
        }

        Spacer(Modifier.height(12.dp))
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.CenterVertically) {
            Text(stringResource(R.string.ai_subscription_auto_renew), color = ink, fontSize = 13.sp)
            Box(
                Modifier
                    .clip(RoundedCornerShape(20.dp))
                    .background(if (subscription.autoRenew) Ai.Red else (if (night) Ai.lineDark else Ai.lineLight))
                    .clickable(enabled = !togglingAutoRenew) { onToggleAutoRenew(!subscription.autoRenew) }
                    .padding(horizontal = 14.dp, vertical = 6.dp),
            ) {
                Text(
                    if (subscription.autoRenew) "On" else "Off",
                    color = if (subscription.autoRenew) Color.White else mut,
                    fontSize = 12.sp,
                    fontWeight = FontWeight.SemiBold,
                )
            }
        }
    }
}

// Plan prices arrive as plain decimal strings ("249.00") from the API, not
// minor units - a light thousands-separator pass only, no currency symbol
// lookup available here, matching what the admin list already shows.
private val planPriceFormat = DecimalFormat("#,##0.##", DecimalFormatSymbols(Locale.US))

private fun formatPlanPrice(raw: String): String {
    val value = raw.toDoubleOrNull() ?: return raw
    return planPriceFormat.format(value)
}

@Composable
private fun PlanCard(
    plan: AiPlanDto,
    isCurrent: Boolean,
    night: Boolean,
    ink: Color,
    mut: Color,
    surface: Color,
    onAction: () -> Unit,
) {
    val hasDiscount = plan.discountPercent != null && plan.discountPercent > 0 && ! plan.originalPrice.isNullOrBlank()
    Column(
        Modifier.fillMaxWidth()
            .clip(RoundedCornerShape(16.dp))
            .background(surface)
            .then(if (plan.isFeatured) Modifier.border(BorderStroke(1.5.dp, Ai.Red), RoundedCornerShape(16.dp)) else Modifier)
            .padding(16.dp),
    ) {
        if (plan.isFeatured || ! plan.badge.isNullOrBlank()) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                if (plan.isFeatured) {
                    Icon(Icons.Rounded.Star, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(13.dp))
                    Spacer(Modifier.width(4.dp))
                }
                if (! plan.badge.isNullOrBlank()) {
                    Text(plan.badge, color = Ai.Red, fontWeight = FontWeight.Bold, fontSize = 11.sp)
                }
            }
            Spacer(Modifier.height(6.dp))
        }

        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween, verticalAlignment = Alignment.Top) {
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(plan.name, color = ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
                    if (isCurrent) {
                        Spacer(Modifier.width(6.dp))
                        Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(16.dp))
                    }
                }
                if (! plan.description.isNullOrBlank()) {
                    Spacer(Modifier.height(4.dp))
                    Text(plan.description, color = mut, fontSize = 12.sp)
                }
                if (plan.features.isNotEmpty()) {
                    Spacer(Modifier.height(8.dp))
                    plan.features.forEach { feature ->
                        Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(bottom = 2.dp)) {
                            Icon(Icons.Rounded.CheckCircle, contentDescription = null, tint = mut, modifier = Modifier.size(12.dp))
                            Spacer(Modifier.width(6.dp))
                            Text(feature, color = mut, fontSize = 12.sp)
                        }
                    }
                }
            }
            Column(horizontalAlignment = Alignment.End) {
                if (hasDiscount) {
                    Box(
                        Modifier
                            .clip(RoundedCornerShape(6.dp))
                            .background(Ai.Red.copy(alpha = 0.12f))
                            .padding(horizontal = 8.dp, vertical = 3.dp),
                    ) {
                        Text(
                            stringResource(R.string.ai_subscription_discount_badge, plan.discountPercent ?: 0),
                            color = Ai.Red,
                            fontSize = 11.sp,
                            fontWeight = FontWeight.Bold,
                        )
                    }
                    Spacer(Modifier.height(4.dp))
                    Text(
                        "${formatPlanPrice(plan.originalPrice!!)} ${plan.currency}",
                        color = mut,
                        fontSize = 11.sp,
                        textDecoration = TextDecoration.LineThrough,
                    )
                    Spacer(Modifier.height(2.dp))
                }
                Text(
                    "${formatPlanPrice(plan.price)} ${plan.currency}",
                    color = if (hasDiscount) Ai.Red else ink,
                    fontWeight = FontWeight.ExtraBold,
                    fontSize = 16.sp,
                )
                Text(stringResource(R.string.ai_subscription_per_cycle, plan.durationDays), color = mut, fontSize = 11.sp)
            }
        }

        Spacer(Modifier.height(12.dp))

        if (isCurrent) {
            Box(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(if (night) Ai.lineDark else Ai.lineLight).padding(vertical = 10.dp),
                contentAlignment = Alignment.Center,
            ) {
                Text(stringResource(R.string.ai_subscription_current_badge), color = mut, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
            }
        } else {
            Box(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(Ai.Red).clickable { onAction() }.padding(vertical = 10.dp),
                contentAlignment = Alignment.Center,
            ) {
                Text(
                    stringResource(if (plan.isTrial) R.string.ai_subscription_subscribe else R.string.ai_subscription_switch_plan),
                    color = Color.White,
                    fontSize = 13.sp,
                    fontWeight = FontWeight.SemiBold,
                )
            }
        }
    }
}

/**
 * Wraps the app's own reusable [WaPinPad] (wallet PIN keypad) in a plain
 * Dialog, callable from outside the Wallet module's own WalletHost/
 * LocalWallet composition tree - AI chat lives in a separate screen
 * host, so it drives WaPinPad directly rather than through
 * WalletHost.requestPin(). [onSubmit] returns the same PadResult the pad
 * already knows how to render (Ok, Error, Locked); this dismisses the
 * dialog itself the moment a submission actually succeeds.
 */
@Composable
internal fun AiWalletPinSheet(
    night: Boolean,
    subtitle: String,
    onDismiss: () -> Unit,
    onSubmit: suspend (String) -> PadResult,
) {
    Dialog(onDismissRequest = onDismiss) {
        val surface = if (night) Ai.surfaceDark else Ai.surfaceLight
        Column(Modifier.clip(RoundedCornerShape(24.dp)).background(surface).padding(vertical = 24.dp)) {
            WaPinPad(
                title = stringResource(R.string.ai_subscription_pin_title),
                sub = subtitle,
                dark = night,
                onComplete = { pin ->
                    val result = onSubmit(pin)
                    if (result is PadResult.Ok) onDismiss()
                    result
                },
            )
        }
    }
}

@Composable
internal fun AiInfoDialog(night: Boolean, message: String, onDismiss: () -> Unit) {
    val surface = if (night) Ai.surfaceDark else Ai.surfaceLight
    val ink = if (night) Ai.inkDark else Ai.inkLight
    Dialog(onDismissRequest = onDismiss) {
        Column(Modifier.clip(RoundedCornerShape(20.dp)).background(surface).padding(20.dp)) {
            Text(message, color = ink, fontSize = 14.sp)
            Spacer(Modifier.height(16.dp))
            Box(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(12.dp)).background(Ai.Red).clickable { onDismiss() }.padding(vertical = 10.dp),
                contentAlignment = Alignment.Center,
            ) {
                Text(stringResource(R.string.ai_ok), color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

private fun formatDate(iso: String): String = runCatching {
    Instant.parse(iso).let { DateTimeFormatter.ofPattern("d/M").format(it.atZone(java.time.ZoneId.systemDefault())) }
}.getOrDefault(iso)
