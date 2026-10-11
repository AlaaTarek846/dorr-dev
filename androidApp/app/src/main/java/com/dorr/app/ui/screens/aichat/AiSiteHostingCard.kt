package com.dorr.app.ui.screens.aichat

import androidx.compose.foundation.border
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material3.Icon
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.unit.LayoutDirection
import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AiSiteHostingDto
import com.dorr.app.network.AiSiteHostingPlanDto
import com.dorr.app.network.AiSiteHostingPlansDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.screens.wallet.PadResult
import com.dorr.app.ui.screens.wallet.walletAuth
import kotlinx.coroutines.launch
import java.time.Instant

private sealed interface HostingPin {
    data class Subscribe(val plan: AiSiteHostingPlanDto, val name: String) : HostingPin
    data object Renew : HostingPin
}

/**
 * Hosting of a finished site on its own address (monthly/yearly, price per country set in the admin
 * panel): pick a plan and a name, pay from the wallet (PIN), then publish the version the public sees,
 * switch auto-renew, or renew by hand. Editing the site never changes the public version by itself.
 */
@Composable
internal fun SiteHostingCard(night: Boolean, ink: Color, mut: Color, surface: Color, projectId: Int) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    var loading by remember { mutableStateOf(true) }
    var plans by remember { mutableStateOf<AiSiteHostingPlansDto?>(null) }
    var hosting by remember { mutableStateOf<AiSiteHostingDto?>(null) }
    var selected by remember { mutableStateOf<AiSiteHostingPlanDto?>(null) }
    var name by remember { mutableStateOf("") }
    var nameCheck by remember { mutableStateOf<String?>(null) }
    var nameOk by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var pin by remember { mutableStateOf<HostingPin?>(null) }
    var noPin by remember { mutableStateOf(false) }

    suspend fun load() {
        plans = runCatching { ApiClient.aiSites.hostingPlans(chatAuth()).data }.getOrNull()
        hosting = runCatching { ApiClient.aiSites.hosting(chatAuth(), projectId).data?.hosting }.getOrNull()
        loading = false
    }

    LaunchedEffect(projectId) { load() }

    fun askPin(target: HostingPin) {
        scope.launch {
            val status = runCatching { ApiClient.wallet.pinStatus(walletAuth()).data }.getOrNull()
            if (status?.hasPin == false) noPin = true else pin = target
        }
    }

    fun act(block: suspend () -> AiSiteHostingDto?) {
        busy = true
        error = null
        scope.launch {
            runCatching { block() }.fold(
                onSuccess = { if (it != null) hosting = it },
                onFailure = { error = it.apiFailure().message },
            )
            busy = false
        }
    }

    val c = sitePalette()
    SiteCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(36.dp).clip(RoundedCornerShape(12.dp)).background(Ai.Red.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Language, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(20.dp))
            }
            Spacer(Modifier.width(10.dp))
            Text(stringResource(R.string.ai_sites_hosting_title), color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
        }
        Spacer(Modifier.height(12.dp))

        val info = plans
        val current = hosting

        when {
            loading -> CircularProgressIndicator(color = Ai.Red, modifier = Modifier.size(18.dp), strokeWidth = 2.dp)

            current != null -> {
                Text(current.url, color = Ai.Red, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                Text(
                    stringResource(
                        if (current.isLive) R.string.ai_sites_hosting_live else R.string.ai_sites_hosting_offline,
                    ),
                    color = if (current.isLive) mut else Color(0xFFDC2626), fontSize = 12.5.sp,
                )
                current.endsAt?.let { Text(stringResource(R.string.ai_sites_hosting_until, it.take(10)), color = mut, fontSize = 12.sp) }
                Text(
                    stringResource(if (current.autoRenew) R.string.ai_sites_hosting_auto_on else R.string.ai_sites_hosting_auto_off),
                    color = mut, fontSize = 12.sp,
                )
                if (current.status == "grace") Text(stringResource(R.string.ai_sites_hosting_grace), color = Color(0xFFDC2626), fontSize = 12.sp)

                Spacer(Modifier.height(10.dp))
                if (current.isLive) {
                    PrimaryButton(stringResource(R.string.ai_sites_hosting_open)) {
                        runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(current.url))) }
                    }
                    Spacer(Modifier.height(8.dp))
                }
                if (current.isLive && current.hasUnpublishedChanges) {
                    Text(stringResource(R.string.ai_sites_hosting_unpublished), color = mut, fontSize = 12.sp)
                    PrimaryButton(stringResource(R.string.ai_sites_hosting_publish), enabled = !busy) {
                        act { ApiClient.aiSites.hostingPublish(chatAuth(), projectId).data }
                    }
                    Spacer(Modifier.height(8.dp))
                }
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Box(
                        Modifier.weight(1f).clip(RoundedCornerShape(12.dp)).background(Ai.Red.copy(alpha = 0.12f))
                            .clickable(enabled = !busy) { askPin(HostingPin.Renew) }.padding(10.dp),
                        contentAlignment = Alignment.Center,
                    ) { Text(stringResource(R.string.ai_sites_hosting_renew), color = Ai.Red, fontWeight = FontWeight.Bold, fontSize = 12.5.sp) }
                    Box(
                        Modifier.weight(1f).clip(RoundedCornerShape(12.dp)).background(Ai.Red.copy(alpha = 0.12f))
                            .clickable(enabled = !busy) {
                                act { ApiClient.aiSites.hostingAutoRenew(chatAuth(), projectId, if (current.autoRenew) 0 else 1).data }
                            }.padding(10.dp),
                        contentAlignment = Alignment.Center,
                    ) {
                        Text(
                            stringResource(if (current.autoRenew) R.string.ai_sites_hosting_stop_auto else R.string.ai_sites_hosting_start_auto),
                            color = Ai.Red, fontWeight = FontWeight.Bold, fontSize = 12.5.sp,
                        )
                    }
                }
            }

            info == null || !info.enabled || info.plans.none { it.available } ->
                Text(stringResource(R.string.ai_sites_hosting_unavailable), color = mut, fontSize = 12.5.sp)

            else -> {
                Text(stringResource(R.string.ai_sites_hosting_intro), color = mut, fontSize = 12.5.sp, lineHeight = 19.sp)
                Spacer(Modifier.height(12.dp))

                val available = info.plans.filter { it.available }
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    available.forEach { plan ->
                        val on = selected?.id == plan.id
                        val shape = RoundedCornerShape(16.dp)
                        Column(
                            Modifier.weight(1f).clip(shape)
                                .background(if (on) Ai.Red.copy(alpha = 0.1f) else c.field)
                                .border(if (on) 1.5.dp else 1.dp, if (on) Ai.Red else c.line, shape)
                                .clickable { selected = plan }.padding(14.dp),
                        ) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Box(
                                    Modifier.size(18.dp).clip(CircleShape).border(2.dp, if (on) Ai.Red else c.mut, CircleShape),
                                    contentAlignment = Alignment.Center,
                                ) { if (on) Box(Modifier.size(8.dp).clip(CircleShape).background(Ai.Red)) }
                                Spacer(Modifier.width(8.dp))
                                Text(plan.name, color = ink, fontWeight = FontWeight.Bold, fontSize = 13.sp, maxLines = 1)
                            }
                            Spacer(Modifier.height(10.dp))
                            Text("${plan.price ?: ""}", color = if (on) Ai.Red else ink, fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
                            Text(
                                "${plan.currency.orEmpty()} / " +
                                    stringResource(if (plan.period == "yearly") R.string.ai_sites_hosting_yearly else R.string.ai_sites_hosting_monthly),
                                color = mut, fontSize = 11.5.sp,
                            )
                        }
                    }
                }

                Spacer(Modifier.height(14.dp))
                SiteField(
                    stringResource(R.string.ai_sites_hosting_name), name,
                    { name = it.lowercase().trim(); nameOk = false; nameCheck = null },
                    keyboardType = androidx.compose.ui.text.input.KeyboardType.Uri,
                )

                // The address is always Latin: force LTR so the slashes and dots keep their order inside an RTL screen.
                CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                    Row(
                        Modifier.fillMaxWidth().padding(top = 4.dp).clip(RoundedCornerShape(12.dp)).background(c.field).padding(horizontal = 12.dp, vertical = 10.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Icon(Icons.Rounded.Language, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(18.dp))
                        Spacer(Modifier.width(8.dp))
                        Text(
                            if (info.mode == "subdomain") "${name.ifBlank { "name" }}.${info.domain.orEmpty()}" else "sites/${name.ifBlank { "name" }}",
                            color = ink, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, maxLines = 1,
                        )
                    }
                }

                nameCheck?.let { Text(it, color = if (nameOk) c.success else c.danger, fontSize = 12.sp, modifier = Modifier.padding(top = 8.dp)) }
                if (nameOk) Text(stringResource(R.string.ai_sites_hosting_name_ok), color = c.success, fontSize = 12.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 8.dp))
                Spacer(Modifier.height(14.dp))

                val plan = selected
                if (!nameOk) {
                    PrimaryButton(stringResource(R.string.ai_sites_hosting_check), enabled = !busy && name.length >= 3) {
                        busy = true
                        scope.launch {
                            runCatching { ApiClient.aiSites.hostingCheck(chatAuth(), name).data }.onSuccess {
                                nameOk = it?.available == true
                                nameCheck = if (nameOk) null else it?.message
                                if (nameOk) nameCheck = ""
                            }.onFailure { nameCheck = it.apiFailure().message }
                            busy = false
                        }
                    }
                } else {
                    PrimaryButton(stringResource(R.string.ai_sites_hosting_subscribe), enabled = !busy && plan != null) {
                        if (plan != null) askPin(HostingPin.Subscribe(plan, name))
                    }
                    if (plan == null) Text(stringResource(R.string.ai_sites_hosting_pick_plan), color = mut, fontSize = 11.5.sp, modifier = Modifier.padding(top = 6.dp))
                }
            }
        }

        error?.let { Text(it, color = c.danger, fontSize = 12.5.sp, modifier = Modifier.padding(top = 8.dp)) }
    }

    if (noPin) {
        AiInfoDialog(night = night, message = stringResource(R.string.ai_subscription_no_pin_set), onDismiss = { noPin = false })
    }

    val action = pin
    if (action != null) {
        AiWalletPinSheet(
            night = night,
            subtitle = stringResource(R.string.ai_sites_hosting_pin_sub),
            onDismiss = { pin = null },
            onSubmit = { code ->
                runCatching {
                    when (action) {
                        is HostingPin.Subscribe -> ApiClient.aiSites.hostingSubscribe(chatAuth(), code, projectId, action.plan.id, action.name).data
                        is HostingPin.Renew -> ApiClient.aiSites.hostingRenew(chatAuth(), code, projectId).data
                    }
                }.fold(
                    onSuccess = { updated ->
                        hosting = updated
                        PadResult.Ok
                    },
                    onFailure = { e ->
                        val failure = e.apiFailure()
                        val until = failure.lockedUntil?.let { runCatching { Instant.parse(it).toEpochMilli() }.getOrNull() }
                        if (failure.errorCode == "wallet_pin_locked" && until != null) PadResult.Locked(until) else PadResult.Error(failure.message ?: "")
                    },
                )
            },
        )
    }
}
