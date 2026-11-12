package com.dorr.freelance.ui.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.freelance.net.CountryRefDto
import com.dorr.freelance.ui.kit.settingsMut

/**
 * Where a project or a post comes from — a small flag and the country's name. Freelance work is open across countries (owner,
 * 2026-11-04), so the lists mix countries and this says which. Nothing when the server did not say.
 */
@Composable
fun CountryTag(country: CountryRefDto?, modifier: Modifier = Modifier) {
    val c = country ?: return
    Row(modifier, verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp)) {
        FlagImage(c.code)
        Text(c.name ?: c.code.orEmpty(), color = settingsMut(), fontSize = 11.sp, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}
