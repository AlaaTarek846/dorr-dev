package com.dorr.app.ui.screens.profile

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.widget.Toast
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Star
import androidx.compose.material.icons.rounded.Notes
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.components.DorrTextField

private const val RATE_PREFS = "dorr_app_rate"

@Composable
fun RateAppScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val prefs = remember { context.getSharedPreferences(RATE_PREFS, Context.MODE_PRIVATE) }
    var stars by remember { mutableIntStateOf(prefs.getInt("stars", 0)) }
    var comment by remember { mutableStateOf(prefs.getString("comment", "").orEmpty()) }
    val accent = settingsAccent()
    val pickStars = stringResource(R.string.rate_pick_stars)
    val thanks = stringResource(R.string.rate_thanks)

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(R.string.rate_title), onBack)
            Column(
                modifier = Modifier
                    .weight(1f)
                    .verticalScroll(rememberScrollState())
                    .padding(horizontal = 14.dp)
                    .padding(bottom = 24.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Text(
                    stringResource(R.string.rate_headline),
                    color = settingsInk(),
                    fontSize = 22.sp,
                    lineHeight = 28.sp,
                    fontWeight = FontWeight.ExtraBold,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().padding(top = 8.dp, bottom = 6.dp),
                )
                Text(
                    stringResource(R.string.rate_body),
                    color = settingsMut(),
                    fontSize = 14.sp,
                    textAlign = TextAlign.Center,
                    lineHeight = 20.sp,
                    modifier = Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 4.dp),
                )
                Spacer(Modifier.height(18.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    (1..5).forEach { n ->
                        Icon(
                            imageVector = if (n <= stars) Icons.Rounded.Star else Icons.Outlined.Star,
                            contentDescription = null,
                            tint = if (n <= stars) accent else settingsMut().copy(alpha = 0.45f),
                            modifier = Modifier
                                .size(40.dp)
                                .clickable { stars = n },
                        )
                    }
                }
                Text(
                    when (stars) {
                        0 -> stringResource(R.string.rate_hint)
                        1 -> stringResource(R.string.rate_label_1)
                        2 -> stringResource(R.string.rate_label_2)
                        3 -> stringResource(R.string.rate_label_3)
                        4 -> stringResource(R.string.rate_label_4)
                        else -> stringResource(R.string.rate_label_5)
                    },
                    color = settingsMut(),
                    fontSize = 13.sp,
                    modifier = Modifier.padding(top = 10.dp, bottom = 18.dp),
                )
                DorrTextField(
                    value = comment,
                    onValueChange = { if (it.length <= 500) comment = it },
                    label = stringResource(R.string.rate_comment_label),
                    placeholder = stringResource(R.string.rate_comment_hint),
                    icon = Icons.Rounded.Notes,
                    singleLine = false,
                    minLines = 4,
                    minHeight = 120.dp,
                )
                Spacer(Modifier.height(18.dp))
                SettingsPrimaryButton(text = stringResource(R.string.rate_submit)) {
                    if (stars < 1) {
                        Toast.makeText(context, pickStars, Toast.LENGTH_SHORT).show()
                        return@SettingsPrimaryButton
                    }
                    prefs.edit()
                        .putInt("stars", stars)
                        .putString("comment", comment.trim())
                        .apply()
                    Toast.makeText(context, thanks, Toast.LENGTH_SHORT).show()
                    if (stars >= 4) openPlayStore(context)
                }
            }
        }
    }
}

private fun openPlayStore(context: Context) {
    val market = Intent(Intent.ACTION_VIEW, Uri.parse("market://details?id=com.dorr.app"))
        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
    val web = Intent(
        Intent.ACTION_VIEW,
        Uri.parse("https://play.google.com/store/apps/details?id=com.dorr.app"),
    ).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
    runCatching { context.startActivity(market) }.onFailure {
        runCatching { context.startActivity(web) }
    }
}
