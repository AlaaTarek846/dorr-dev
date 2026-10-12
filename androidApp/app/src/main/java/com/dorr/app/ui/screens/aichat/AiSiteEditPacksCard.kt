package com.dorr.app.ui.screens.aichat

import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AutoAwesome
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AiSiteEditPacksDto
import com.dorr.app.network.AiSiteOfferDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.ui.screens.wallet.PadResult
import com.dorr.app.ui.screens.wallet.walletAuth
import kotlinx.coroutines.launch
import java.time.Instant

/**
 * Edit packs: more AI edits for this one site once its own allowance is used up, paid from the wallet
 * behind the PIN. [reloadKey] changes after every build/edit so the "edits left" figure stays current;
 * [onState] tells the page whether the site is out of edits (to warn above the edit box).
 */
@Composable
internal fun SiteEditPacksCard(night: Boolean, projectId: Int, reloadKey: Any, onBought: () -> Unit, onState: (blocked: Boolean) -> Unit) {
    val c = sitePalette()
    val scope = rememberCoroutineScope()

    var data by remember { mutableStateOf<AiSiteEditPacksDto?>(null) }
    var picked by remember { mutableStateOf<AiSiteOfferDto?>(null) }
    var noPin by remember { mutableStateOf(false) }
    var bought by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(true) }

    LaunchedEffect(projectId, reloadKey) {
        runCatching { ApiClient.aiSites.editPacks(chatAuth(), projectId).data }.onSuccess {
            data = it
            onState(it?.edits?.blocked == true)
        }
        loading = false
    }

    val packs = data?.packs.orEmpty().filter { it.available }
    val edits = data?.edits
    val blocked = edits?.blocked == true

    if (loading) return
    if (packs.isEmpty() && !blocked) return

    SiteCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(36.dp).clip(RoundedCornerShape(12.dp)).background(Ai.Red.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.AutoAwesome, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(20.dp))
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                SiteSectionTitle(stringResource(R.string.ai_sites_packs_title))
                Text(
                    if (blocked) stringResource(R.string.ai_sites_packs_empty) else stringResource(R.string.ai_sites_packs_left, edits?.total ?: 0),
                    color = if (blocked) c.danger else c.mut,
                    fontSize = 12.sp,
                    fontWeight = if (blocked) FontWeight.SemiBold else FontWeight.Normal,
                )
            }
        }

        if (bought) {
            Text(stringResource(R.string.ai_sites_packs_bought), color = c.success, fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 10.dp))
        }

        Spacer(Modifier.height(12.dp))
        packs.forEach { pack ->
            val shape = RoundedCornerShape(14.dp)
            Row(
                Modifier.fillMaxWidth().padding(vertical = 4.dp).clip(shape).background(c.field).border(1.dp, c.line, shape)
                    .clickable {
                        bought = false
                        scope.launch {
                            val status = runCatching { ApiClient.wallet.pinStatus(walletAuth()).data }.getOrNull()
                            if (status?.hasPin == false) noPin = true else picked = pack
                        }
                    }.padding(14.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column(Modifier.weight(1f)) {
                    Text(pack.name, color = c.ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    Text(stringResource(R.string.ai_sites_packs_edits, pack.generationsIncluded), color = c.mut, fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
                }
                Box(Modifier.clip(RoundedCornerShape(12.dp)).background(Ai.Red.copy(alpha = 0.12f)).padding(horizontal = 12.dp, vertical = 8.dp)) {
                    Text("${pack.price ?: ""} ${pack.currency.orEmpty()}".trim(), color = Ai.Red, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp)
                }
            }
        }
    }

    if (noPin) {
        AiInfoDialog(night = night, message = stringResource(R.string.ai_subscription_no_pin_set), onDismiss = { noPin = false })
    }

    val target = picked
    if (target != null) {
        AiWalletPinSheet(
            night = night,
            subtitle = stringResource(R.string.ai_sites_packs_pin_sub, target.name),
            onDismiss = { picked = null },
            onSubmit = { pin ->
                runCatching { ApiClient.aiSites.buyEditPack(chatAuth(), pin, projectId, target.id).data }.fold(
                    onSuccess = { updated ->
                        data = updated
                        onState(updated?.edits?.blocked == true)
                        bought = true
                        onBought()
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
