package com.dorr.app.ui.screens.profile

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.theme.AppColors

@Composable
fun PrivacyPolicyScreen(onBack: () -> Unit) {
    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(R.string.account_privacy), onBack)
            Column(
                modifier = Modifier
                    .verticalScroll(rememberScrollState())
                    .padding(horizontal = 14.dp)
                    .padding(top = 6.dp, bottom = 16.dp)
                    .fillMaxWidth()
                    .settingsSurface(RoundedCornerShape(18.dp), 8.dp)
                    .padding(16.dp),
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    PinkIcon(Icons.Rounded.Shield)
                    Spacer(Modifier.width(10.dp))
                    Text(
                        stringResource(R.string.privacy_head),
                        color = settingsAccent(),
                        fontSize = 18.sp,
                        fontWeight = FontWeight.ExtraBold,
                    )
                }
                Text(
                    stringResource(R.string.privacy_body),
                    fontSize = 13.sp,
                    lineHeight = 24.sp,
                    color = if (settingsNight()) settingsMut() else AppColors.textSecondary,
                    modifier = Modifier.padding(top = 12.dp),
                )
            }
        }
    }
}
