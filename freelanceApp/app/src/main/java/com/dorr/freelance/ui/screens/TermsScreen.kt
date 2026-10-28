package com.dorr.freelance.ui.screens

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Gavel
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.AcceptTermsRequest
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.Session
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.kit.PinkBackdrop
import com.dorr.freelance.ui.kit.PinkIcon
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import com.dorr.freelance.ui.kit.settingsSurface
import kotlinx.coroutines.launch

/** The terms of use, shown once until accepted (`terms.accepted`): nothing else opens before the freelancer agrees. */
@Composable
fun TermsScreen(version: String, onAccepted: () -> Unit) {
    // Back does not skip the terms.
    BackHandler {}
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)

    fun accept() {
        loading = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.profile.acceptTerms(AcceptTermsRequest(version)) }
            loading = false
            if (result is ApiResult.Ok) {
                Session.setMe(result.data)
                onAccepted()
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.matchParentSize())
        Column(
            modifier = Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding().padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center,
        ) {
            Column(
                modifier = Modifier.widthIn(max = 440.dp).fillMaxWidth().settingsSurface(RoundedCornerShape(24.dp)).padding(20.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                PinkIcon(Icons.Rounded.Gavel, box = 48.dp)
                Spacer(Modifier.height(14.dp))
                Text(stringResource(R.string.terms_title), color = settingsInk(), fontSize = 20.sp, fontWeight = FontWeight.ExtraBold, textAlign = TextAlign.Center)
                Spacer(Modifier.height(8.dp))
                Text(stringResource(R.string.terms_body, version), color = settingsMut(), fontSize = 14.sp, lineHeight = 22.sp, textAlign = TextAlign.Center)
                Spacer(Modifier.height(20.dp))
                SettingsPrimaryButton(text = stringResource(R.string.terms_accept), loading = loading, onClick = ::accept)
                ErrorBanner(error, Modifier.fillMaxWidth())
            }
        }
    }
}
