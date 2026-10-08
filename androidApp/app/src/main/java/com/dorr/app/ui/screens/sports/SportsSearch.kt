package com.dorr.app.ui.screens.sports

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.TravelExplore
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.SpSearchDto
import com.dorr.app.ui.components.DorrTextField
import com.dorr.app.ui.screens.wallet.Tone
import com.dorr.app.ui.screens.wallet.Wa
import com.dorr.app.ui.screens.wallet.WaEmpty
import com.dorr.app.ui.screens.wallet.WaPage
import com.dorr.app.ui.screens.wallet.WaSectionTitle
import com.dorr.app.ui.screens.wallet.waRise
import kotlinx.coroutines.delay

/** One search for everything: competitions, teams (ours and the provider's), players. */
@Composable
internal fun SportsSearchPage(onBack: () -> Unit, go: (SpPage) -> Unit) {
    var query by remember { mutableStateOf("") }
    var result by remember { mutableStateOf<SpSearchDto?>(null) }
    var busy by remember { mutableStateOf(false) }
    LaunchedEffect(query) {
        val q = query.trim()
        if (q.length < 2) { result = null; return@LaunchedEffect }
        delay(450)
        busy = true
        result = runCatching { ApiClient.sports.search(spAuth(), q).data }.getOrNull()
        busy = false
    }

    WaPage(title = stringResource(R.string.spx_search), onBack = onBack) {
        DorrTextField(query, { query = it.take(60) }, placeholder = stringResource(R.string.spx_search_hint), icon = Icons.Rounded.Search, modifier = Modifier.fillMaxWidth().waRise(0))
        val r = result
        when {
            query.trim().length < 2 -> WaEmpty(Icons.Rounded.TravelExplore, Tone.Blue, stringResource(R.string.spx_search), stringResource(R.string.spx_search_sub))
            busy && r == null -> { Spacer(Modifier.height(12.dp)); SkeletonRows(5) }
            r == null || (r.competitions.isEmpty() && r.teams.isEmpty() && r.players.isEmpty()) -> { Spacer(Modifier.height(12.dp)); StateNote(null, empty = R.string.spx_nothing_found) }
            else -> {
                if (r.teams.isNotEmpty()) {
                    WaSectionTitle(stringResource(R.string.sp_tab_teams))
                    r.teams.forEachIndexed { i, t ->
                        ResultRow(i, { Crest(t, 40.dp) }, t.name.orEmpty(), t.country) { go(SpPage.Team(t.id)) }
                    }
                }
                if (r.competitions.isNotEmpty()) {
                    WaSectionTitle(stringResource(R.string.spx_competitions))
                    r.competitions.forEachIndexed { i, c ->
                        ResultRow(i, {
                            Box(Modifier.size(40.dp).clip(RoundedCornerShape(12.dp)).background(Wa.Field), contentAlignment = Alignment.Center) {
                                if (c.logo != null) AsyncImage(model = ApiClient.mediaUrl(c.logo), contentDescription = null, contentScale = ContentScale.Fit, modifier = Modifier.size(30.dp)) else Text("🏆")
                            }
                        }, c.name.orEmpty(), c.country) { go(SpPage.Competition(c.id)) }
                    }
                }
                if (r.players.isNotEmpty()) {
                    WaSectionTitle(stringResource(R.string.spx_players))
                    r.players.forEachIndexed { i, p ->
                        ResultRow(i, { PhotoAvatar(p.photo, p.name, 40.dp) }, p.name.orEmpty(), listOfNotNull(p.position, p.nationality, p.age?.let { stringResource(R.string.spx_age, it) }).joinToString(" · ")) {
                            p.id?.let { go(SpPage.Player(it)) }
                        }
                    }
                }
            }
        }
        Spacer(Modifier.height(24.dp))
    }
}

@Composable
private fun ResultRow(index: Int, icon: @Composable () -> Unit, title: String, sub: String?, onClick: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(bottom = 6.dp).waRise(index.coerceAtMost(8)).clip(RoundedCornerShape(18.dp)).background(Wa.Surface).clickable(onClick = onClick).padding(10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        icon()
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(title, color = Wa.Ink, fontSize = 14.sp, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (!sub.isNullOrBlank()) Text(sub, color = Wa.Mut, fontSize = 12.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Text("›", color = Wa.Soft, fontSize = 22.sp)
    }
}
