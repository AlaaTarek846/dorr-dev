package com.dorr.freelance.ui.components

import androidx.compose.ui.draw.clip
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.size
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsMut
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

/**
 * A page of the app: the soft backdrop, the title with its back button, and a scrolling column of cards. [onNearEnd] is for a paged
 * list: it is called when the bottom comes near (see [NearEndEffect]).
 */
@Composable
fun Page(title: String, onBack: () -> Unit, onNearEnd: (() -> Unit)? = null, content: @Composable () -> Unit) {
    val scroll = rememberScrollState()
    NearEndEffect(scroll, onNearEnd)
    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize().statusBarsPadding().navigationBarsPadding().imePadding()) {
            SubHeader(title = title, onBack = onBack)
            Column(
                modifier = Modifier.fillMaxSize().verticalScroll(scroll).padding(horizontal = 18.dp).padding(bottom = 28.dp),
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

/** A page with nothing to show yet, as in the DORR app: a big icon on a soft disc in the middle, a title and a line under it. */
@Composable
fun EmptyState(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, body: String, modifier: Modifier = Modifier) {
    val night = com.dorr.freelance.ui.kit.settingsNight()
    Column(
        modifier = modifier.fillMaxWidth().padding(horizontal = 32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Box(
            modifier = Modifier.size(100.dp).background(if (night) com.dorr.freelance.ui.kit.AccountDark.well else settingsAccent().copy(alpha = 0.08f), androidx.compose.foundation.shape.CircleShape),
            contentAlignment = Alignment.Center,
        ) { androidx.compose.material3.Icon(icon, contentDescription = null, tint = settingsMut(), modifier = Modifier.size(48.dp)) }
        androidx.compose.foundation.layout.Spacer(Modifier.height(24.dp))
        Text(title, fontSize = 16.sp, fontWeight = FontWeight.SemiBold, color = settingsInk(), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
        androidx.compose.foundation.layout.Spacer(Modifier.height(8.dp))
        Text(body, fontSize = 13.sp, lineHeight = 19.sp, color = settingsMut(), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
    }
}

/** A tab page: its title (no back arrow) over the content, which scrolls and asks for more near the bottom. */
@Composable
fun TabPage(title: String, onBack: (() -> Unit)? = null, onNearEnd: (() -> Unit)? = null, content: @Composable () -> Unit) {
    val scroll = rememberScrollState()
    NearEndEffect(scroll, onNearEnd)
    Column(Modifier.fillMaxSize().verticalScroll(scroll).padding(bottom = 16.dp)) {
        // A tab with a back button goes back to the Home tab, like the pages over the tabs.
        if (onBack != null) SubHeader(title = title, onBack = onBack)
        else Column(Modifier.padding(horizontal = 14.dp).padding(top = 14.dp)) { com.dorr.freelance.ui.kit.SettingsScreenTitle(title) }
        Column(Modifier.padding(horizontal = 14.dp).padding(top = 12.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) { content() }
    }
}

/** The head of a form (as in the user app): an icon on a soft tile, a title and a line saying what the form is for. */
@Composable
fun FormIntro(icon: androidx.compose.ui.graphics.vector.ImageVector, title: String, body: String) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(52.dp).clip(androidx.compose.foundation.shape.RoundedCornerShape(16.dp)).background(settingsAccent().copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
            androidx.compose.material3.Icon(icon, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(26.dp))
        }
        androidx.compose.foundation.layout.Spacer(Modifier.size(12.dp))
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
            Text(title, color = settingsInk(), fontSize = 19.sp, fontWeight = FontWeight.ExtraBold)
            if (body.isNotBlank()) Text(body, color = settingsMut(), fontSize = 13.sp, lineHeight = 19.sp)
        }
    }
}

/** The fields of a form laid straight on the page, not boxed in a card. */
@Composable
fun FormGroup(content: @Composable () -> Unit) {
    Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(14.dp)) { content() }
}
