package com.dorr.app.ui.screens.rides

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.Send
import androidx.compose.material.icons.rounded.Inventory2
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.material3.rememberModalBottomSheetState
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
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.LostItemDto
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import com.google.gson.JsonObject
import kotlinx.coroutines.launch

/** D-294..D-297, R-NEW-11: «I left something in the car» on a finished ride — and the report's follow-up. */
@Composable
internal fun LostItemRow(rideUuid: String) {
    var open by remember { mutableStateOf(false) }
    Row(
        Modifier.fillMaxWidth().padding(top = 10.dp).clip(RoundedCornerShape(14.dp)).background(settingsBackground()).clickable { open = true }.padding(12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(Icons.Rounded.Inventory2, null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(8.dp))
        Text(stringResource(R.string.lost_left_something), color = settingsInk(), fontWeight = FontWeight.SemiBold, fontSize = 13.5.sp)
    }
    if (open) LostItemSheet(rideUuid) { open = false }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun LostItemSheet(rideUuid: String, onClose: () -> Unit) {
    val scope = rememberCoroutineScope()
    var item by remember { mutableStateOf<LostItemDto?>(null) }
    var loaded by remember { mutableStateOf(false) }
    var text by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    suspend fun reload() {
        item = runCatching { ApiClient.rides.lostItems(ridesAuth()).data.orEmpty() }.getOrDefault(emptyList()).firstOrNull { it.ride.uuid == rideUuid }
        loaded = true
    }
    LaunchedEffect(rideUuid) {
        reload()
        com.dorr.app.chat.ChatRealtime.events.collect { e -> if (e.name.startsWith("lost.")) reload() }
    }
    ModalBottomSheet(onDismissRequest = onClose, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true), containerColor = settingsCard()) {
        Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 18.dp).navigationBarsPadding().imePadding(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(stringResource(R.string.lost_title), color = settingsInk(), fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
            val i = item
            if (!loaded) return@Column
            if (i == null) {
                Text(stringResource(R.string.lost_hint), color = settingsMut(), fontSize = 13.sp)
                DorrTextField(text, { text = it.take(500) }, placeholder = stringResource(R.string.lost_what))
                error?.let { Text(it, color = AppColors.danger, fontSize = 12.sp) }
                AccentButton(stringResource(R.string.lost_report), enabled = text.trim().length >= 3 && !busy, busy = busy) {
                    busy = true; error = null
                    scope.launch {
                        runCatching { ApiClient.rides.reportLostItem(ridesAuth(), rideUuid, JsonObject().apply { addProperty("description", text.trim()) }).data }
                            .onSuccess { item = it; text = "" }.onFailure { error = it.apiFailure().message }
                        busy = false
                    }
                }
            } else {
                Text(i.description, color = settingsInk(), fontWeight = FontWeight.Bold)
                Text(stringResource(lostStatusText(i.status)), color = settingsAccent(), fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
                i.returnPlan?.takeIf { it.isNotBlank() }?.let { Text("📍 $it", color = settingsInk(), fontSize = 13.sp) }
                // D-296: messages with the captain — never numbers.
                i.messages.forEach { m ->
                    Box(Modifier.fillMaxWidth(), contentAlignment = if (m.mine) Alignment.CenterEnd else Alignment.CenterStart) {
                        Text(m.body, color = if (m.mine) Color.White else settingsInk(), fontSize = 14.sp,
                            modifier = Modifier.widthIn(max = 280.dp).clip(RoundedCornerShape(14.dp)).background(if (m.mine) settingsAccent() else settingsBackground()).padding(10.dp))
                    }
                }
                if (i.status !in setOf("returned", "closed")) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.weight(1f)) { DorrTextField(text, { text = it.take(1000) }, placeholder = stringResource(R.string.lost_message)) }
                        Spacer(Modifier.width(8.dp))
                        Box(Modifier.size(44.dp).clip(CircleShape).background(if (text.isNotBlank()) settingsAccent() else settingsMut().copy(alpha = 0.3f)).clickable(enabled = text.isNotBlank()) {
                            scope.launch {
                                runCatching { ApiClient.rides.lostItemMessage(ridesAuth(), i.uuid, JsonObject().apply { addProperty("body", text.trim()) }).data }.getOrNull()?.let { item = it }
                                text = ""
                            }
                        }, contentAlignment = Alignment.Center) { Icon(Icons.AutoMirrored.Rounded.Send, null, tint = Color.White) }
                    }
                    Text(stringResource(R.string.lost_close), color = settingsMut(), fontSize = 12.5.sp, fontWeight = FontWeight.SemiBold,
                        modifier = Modifier.clip(CircleShape).clickable { scope.launch { runCatching { ApiClient.rides.closeLostItem(ridesAuth(), i.uuid).data }.getOrNull()?.let { item = it } } }.padding(8.dp))
                }
            }
            Spacer(Modifier.height(4.dp))
        }
    }
}

private fun lostStatusText(s: String): Int = when (s) {
    "found" -> R.string.lost_st_found
    "not_found" -> R.string.lost_st_not_found
    "returned" -> R.string.lost_st_returned
    "closed" -> R.string.lost_st_closed
    else -> R.string.lost_st_reported
}
