package com.dorr.app.ui.screens.profile

import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.ui.components.SettingsScaffold

@Composable
fun PrivacyPolicyScreen(onBack: () -> Unit) {
    SettingsScaffold(title = stringResource(R.string.account_privacy), onBack = onBack) { padding ->
        Text(
            text = stringResource(R.string.privacy_body),
            style = MaterialTheme.typography.bodyMedium,
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(20.dp)
                .verticalScroll(rememberScrollState()),
        )
    }
}
