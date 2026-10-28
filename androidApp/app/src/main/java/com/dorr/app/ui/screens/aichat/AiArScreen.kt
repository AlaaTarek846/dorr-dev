package com.dorr.app.ui.screens.aichat

import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AiArAssetDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.chatAuth
import kotlinx.coroutines.launch

/**
 * DORR AR: the model library. "View in AR" hands the model to Google's Scene Viewer, which places it at real
 * size when the phone supports ARCore and falls back to a plain 3D viewer when it does not, so no device is
 * left with a dead button. Sizes shown come from the model file itself; nothing here is estimated from a photo.
 */
@Composable
internal fun AiArScreen(night: Boolean, onExit: () -> Unit) {
    BackHandler { onExit() }

    CompositionLocalProvider(LocalSiteNight provides night) {
        val c = sitePalette()
        val context = LocalContext.current
        val scope = rememberCoroutineScope()
        var enabled by remember { mutableStateOf<Boolean?>(null) }
        var notice by remember { mutableStateOf<String?>(null) }
        var assets by remember { mutableStateOf<List<AiArAssetDto>>(emptyList()) }
        var busyId by remember { mutableStateOf<Int?>(null) }
        var error by remember { mutableStateOf<String?>(null) }
        var designsTab by remember { mutableStateOf(false) }
        // The AR editor ships as an optional module; without it the screen still works and simply hides the button.
        val editorClass = remember { runCatching { Class.forName("com.dorr.ar.editor.ArEditorActivity") }.getOrNull() }

        LaunchedEffect(Unit) {
            val info = runCatching { ApiClient.aiAr.info(chatAuth()).data }.getOrNull()
            enabled = info?.enabled == true
            notice = info?.measurementNotice
            if (enabled == true) assets = runCatching { ApiClient.aiAr.assets(chatAuth()).data.orEmpty() }.getOrDefault(emptyList())
        }

        fun open(asset: AiArAssetDto) {
            busyId = asset.id
            error = null
            scope.launch {
                val fresh = runCatching { ApiClient.aiAr.asset(chatAuth(), asset.id) }
                busyId = null
                val url = fresh.getOrNull()?.data?.downloadUrl
                if (url == null) { error = fresh.exceptionOrNull()?.apiFailure()?.message ?: context.getString(R.string.ai_ar_open_failed); return@launch }
                val uri = Uri.parse("https://arvr.google.com/scene-viewer/1.0").buildUpon()
                    .appendQueryParameter("file", url)
                    .appendQueryParameter("mode", "ar_preferred")
                    .appendQueryParameter("resizable", "false")
                    .appendQueryParameter("title", asset.name.orEmpty())
                    .build()
                val intent = Intent(Intent.ACTION_VIEW, uri)
                try { context.startActivity(Intent(intent).setPackage("com.google.android.googlequicksearchbox")) }
                catch (_: ActivityNotFoundException) {
                    try { context.startActivity(intent) } catch (_: ActivityNotFoundException) { error = context.getString(R.string.ai_ar_open_failed) }
                }
            }
        }

        Column(Modifier.fillMaxSize().background(c.bg)) {
            SiteTopBar(stringResource(R.string.ai_ar_title), onBack = onExit)
            if (enabled == null) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ai.Red) }
            } else if (designsTab) {
                Row(Modifier.padding(horizontal = 16.dp, vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    SecondaryButton(stringResource(R.string.ai_ar_tab_library), Modifier, onClick = { designsTab = false })
                }
                AiArDesignsPanel(onOpenEditor = editorClass?.let { cls ->
                    { id: Int ->
                        context.startActivity(
                            Intent(context, cls)
                                .putExtra("experience_id", id)
                                .putExtra("auth", chatAuth())
                                .putExtra("api_base", ApiClient.ORIGIN + "/api/"),
                        )
                    }
                })
            } else {
                Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    SiteCard {
                        Text(stringResource(R.string.ai_ar_hero_title), color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 18.sp)
                        Text(stringResource(R.string.ai_ar_hero_sub), color = c.mut, fontSize = 13.sp, lineHeight = 19.sp, modifier = Modifier.padding(top = 4.dp))
                        if (enabled != true) Text(stringResource(R.string.ai_ar_disabled), color = c.warn, fontWeight = FontWeight.SemiBold, fontSize = 13.sp, modifier = Modifier.padding(top = 10.dp))
                        else {
                            Spacer(Modifier.height(10.dp))
                            SecondaryButton(stringResource(R.string.ai_ar_tab_designs), Modifier, onClick = { designsTab = true })
                        }
                    }
                    error?.let { Text(it, color = c.warn, fontSize = 13.sp) }
                    assets.forEach { a ->
                        SiteCard(padding = 14.dp) {
                            Text(a.name.orEmpty(), color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
                            a.dimensions?.let {
                                Text(stringResource(R.string.ai_ar_dimensions, it.width, it.height, it.depth), color = c.mut, fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
                            }
                            Spacer(Modifier.height(10.dp))
                            PrimaryButton(
                                if (busyId == a.id) stringResource(R.string.ai_ar_opening) else stringResource(R.string.ai_ar_view),
                                enabled = busyId == null,
                                onClick = { open(a) },
                            )
                        }
                    }
                    if (enabled == true && assets.isEmpty()) Text(stringResource(R.string.ai_ar_empty), color = c.mut, fontSize = 13.sp)
                    notice?.let { Text(it, color = c.mut, fontSize = 11.sp, lineHeight = 16.sp) }
                }
            }
        }
    }
}
