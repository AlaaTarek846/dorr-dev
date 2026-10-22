package com.dorr.freelance.ui.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.ui.kit.PinkBackdrop
import com.dorr.freelance.ui.kit.SubHeader
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsSurface

/** A page of the app: the soft backdrop, the title with its back button, and a scrolling column of cards. */
@Composable
fun Page(title: String, onBack: () -> Unit, content: @Composable () -> Unit) {
    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding().imePadding()) {
            SubHeader(title = title, onBack = onBack)
            Column(
                modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 18.dp).padding(bottom = 28.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(14.dp),
            ) { content() }
        }
    }
}

/** A white card (a soft shadow by day, a hairline at night) holding a group of fields. */
@Composable
fun Card(content: @Composable () -> Unit) {
    Column(
        modifier = Modifier.fillMaxWidth().widthIn(max = 440.dp).settingsSurface(RoundedCornerShape(20.dp)).padding(16.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) { content() }
}

@Composable
fun FieldLabel(text: String) {
    Text(text, fontSize = 13.sp, fontWeight = FontWeight.Bold, color = settingsInk())
}
