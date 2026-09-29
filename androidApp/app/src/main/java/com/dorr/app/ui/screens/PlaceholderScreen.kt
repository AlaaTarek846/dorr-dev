package com.dorr.app.ui.screens

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Construction
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.settingsNight
import com.dorr.app.ui.theme.AppColors

/** Stand-in for tabs outside this request's scope (Items / History). */
@Composable
fun PlaceholderScreen() {
    val night = settingsNight()
    Box(Modifier.fillMaxSize()) {
    PinkBackdrop(Modifier.matchParentSize())
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Icon(
            Icons.Outlined.Construction,
            contentDescription = null,
            tint = if (night) AccountDark.mut else AppColors.textMuted,
            modifier = Modifier.size(40.dp),
        )
        Spacer(Modifier.height(12.dp))
        Text(
            stringResource(R.string.placeholder_title),
            style = MaterialTheme.typography.titleMedium,
            color = if (night) AccountDark.ink else AppColors.textPrimary,
        )
        Spacer(Modifier.height(4.dp))
        Text(
            stringResource(R.string.placeholder_body),
            style = MaterialTheme.typography.bodySmall,
            color = if (night) AccountDark.mut else AppColors.textSecondary,
            textAlign = TextAlign.Center,
        )
    }
    }
}
