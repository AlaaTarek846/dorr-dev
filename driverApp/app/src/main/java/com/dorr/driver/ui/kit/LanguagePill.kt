package com.dorr.driver.ui.kit

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.driver.R
import com.dorr.driver.ui.theme.DriverPrefs
import com.dorr.driver.ui.theme.Pal
import com.dorr.driver.ui.theme.findActivity

/** What each language is called, in its own words. */
@Composable
fun languageName(code: String): String = when (code) {
    DriverPrefs.LANG_AR -> stringResource(R.string.acct_lang_ar)
    DriverPrefs.LANG_EN -> stringResource(R.string.acct_lang_en)
    else -> stringResource(R.string.acct_lang_system)
}

/** Picks the language and rebuilds the screen in it. */
fun chooseLanguage(context: android.content.Context, code: String) {
    if (DriverPrefs.language == code) return
    DriverPrefs.chooseLanguage(code)
    context.findActivity()?.recreate()
}

/** The language chip of the sign-in screens: a pill that opens the choices. */
@Composable
fun LanguagePill(modifier: Modifier = Modifier) {
    val context = LocalContext.current
    var open by remember { mutableStateOf(false) }
    val shape = RoundedCornerShape(999.dp)
    // The label shows the language actually in use, so "like the phone" still names one.
    val shown = if (DriverPrefs.language.isEmpty()) {
        if (context.resources.configuration.locales[0].language == "ar") DriverPrefs.LANG_AR else DriverPrefs.LANG_EN
    } else DriverPrefs.language

    androidx.compose.foundation.layout.Box(modifier) {
        Row(
            Modifier
                .height(36.dp)
                .then(if (Pal.night) Modifier.border(1.dp, Pal.line, shape) else Modifier.shadow(6.dp, shape, ambientColor = Color(0x14001B53), spotColor = Color(0x14001B53)))
                .clip(shape)
                .background(Pal.card)
                .bounceClick { open = true }
                .padding(horizontal = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Rounded.Language, null, tint = Pal.accent, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(8.dp))
            Text(languageName(shown), color = Pal.ink, fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
        }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }, containerColor = Pal.card, shape = RoundedCornerShape(16.dp)) {
            listOf(DriverPrefs.LANG_AR, DriverPrefs.LANG_EN).forEach { code ->
                val chosen = code == shown
                DropdownMenuItem(
                    text = { Text(languageName(code), color = if (chosen) Pal.accent else Pal.ink, fontWeight = if (chosen) FontWeight.Bold else FontWeight.Medium, fontSize = 13.sp) },
                    trailingIcon = if (chosen) {
                        { Icon(Icons.Rounded.Check, null, tint = Pal.accent, modifier = Modifier.size(16.dp)) }
                    } else null,
                    onClick = {
                        open = false
                        chooseLanguage(context, code)
                    },
                )
            }
        }
    }
}
