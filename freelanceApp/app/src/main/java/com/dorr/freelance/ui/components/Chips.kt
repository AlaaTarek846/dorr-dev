package com.dorr.freelance.ui.components

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.ui.kit.AccountDark
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsNight

/** A wrapping row of pills to pick from (one or several): [selected] holds the chosen values. */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun ChipRow(options: List<Pair<String, String>>, selected: Set<String>, onToggle: (String) -> Unit, modifier: Modifier = Modifier) {
    val night = settingsNight()
    FlowRow(modifier, horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        options.forEach { (value, label) ->
            val on = value in selected
            val shape = RoundedCornerShape(999.dp)
            Text(
                text = label,
                color = if (on) Color.White else settingsInk(),
                fontSize = 13.sp,
                fontWeight = FontWeight.SemiBold,
                modifier = Modifier
                    .clip(shape)
                    .background(if (on) settingsAccent() else Color.Transparent)
                    .border(1.dp, if (on) settingsAccent() else if (night) AccountDark.line else Color(0xFFE5E7EB), shape)
                    .clickable { onToggle(value) }
                    .padding(horizontal = 14.dp, vertical = 8.dp),
            )
        }
    }
}
