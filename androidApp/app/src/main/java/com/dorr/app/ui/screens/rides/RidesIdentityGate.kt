package com.dorr.app.ui.screens.rides

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Badge
import androidx.compose.material.icons.rounded.Person
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
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.UpdateIdentityBody
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsBackground
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsMut
import com.dorr.app.ui.theme.AppColors
import kotlinx.coroutines.launch

/**
 * O-87: opening the rides service without a name or a gender — a window that can't be closed until both
 * are given (the gender decides For Her, O-73). The account is read fresh once on opening, no polling.
 */
@Composable
internal fun RidesIdentityGate() {
    var need by remember { mutableStateOf(false) }
    var name by remember { mutableStateOf("") }
    var gender by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(Unit) {
        val me = runCatching { ApiClient.mobileAuth.me(ridesAuth()).data }.getOrNull() ?: AuthSession.user ?: return@LaunchedEffect
        AuthSession.user = me
        name = me.name.orEmpty().trim()
        gender = me.gender?.takeIf { it == "male" || it == "female" }
        need = name.length < 2 || gender == null
    }
    if (!need) return

    val ready = name.trim().length >= 2 && gender != null && !busy
    Dialog(onDismissRequest = {}, properties = DialogProperties(dismissOnBackPress = false, dismissOnClickOutside = false)) {
        Column(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(28.dp)).background(settingsCard()).padding(22.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            Box(Modifier.size(64.dp).clip(CircleShape).background(settingsAccent().copy(alpha = 0.14f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.Badge, null, tint = settingsAccent(), modifier = Modifier.size(32.dp))
            }
            Text(stringResource(R.string.idg_title), color = settingsInk(), fontWeight = FontWeight.Black, fontSize = 19.sp, textAlign = TextAlign.Center)
            Text(stringResource(R.string.idg_hint), color = settingsMut(), fontSize = 13.sp, textAlign = TextAlign.Center)
            com.dorr.app.ui.components.DorrTextField(
                value = name, onValueChange = { name = it.take(50); error = null },
                placeholder = stringResource(R.string.idg_name), icon = Icons.Rounded.Person, modifier = Modifier.fillMaxWidth(),
            )
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                listOf("male" to R.string.idg_male, "female" to R.string.idg_female).forEach { (value, label) ->
                    val on = gender == value
                    Text(
                        stringResource(label), color = if (on) Color.White else settingsInk(), fontWeight = FontWeight.Bold, textAlign = TextAlign.Center,
                        modifier = Modifier.weight(1f).clip(RoundedCornerShape(16.dp)).background(if (on) settingsAccent() else settingsBackground())
                            .clickable { gender = value; error = null }.padding(vertical = 14.dp),
                    )
                }
            }
            error?.let { Text(it, color = AppColors.danger, fontSize = 12.5.sp, textAlign = TextAlign.Center) }
            AccentButton(stringResource(R.string.idg_save), busy = busy, enabled = ready) {
                val g = gender ?: return@AccentButton
                busy = true
                scope.launch {
                    runCatching { ApiClient.profile.updateIdentity(ridesAuth(), UpdateIdentityBody(name = name.trim(), gender = g)).data }
                        .onSuccess { user -> user?.let { AuthSession.user = it }; need = false }
                        .onFailure { error = it.apiFailure().message }
                    busy = false
                }
            }
        }
    }
}
