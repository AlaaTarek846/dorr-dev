package com.dorr.app.ui.screens.aichat

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.clickable
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Web
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AiSiteOfferDto
import com.dorr.app.network.AiSiteOffersDto
import com.dorr.app.network.AiSiteProjectDto
import com.dorr.app.network.ApiClient
import com.dorr.app.ui.screens.chat.chatAuth

@Composable
private fun SiteHero(canBuild: Boolean, message: String?, onNew: () -> Unit) {
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(26.dp)).background(siteGradient()).padding(20.dp)) {
        Box(Modifier.size(46.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.2f)), contentAlignment = Alignment.Center) {
            Icon(Icons.Rounded.AutoAwesome, contentDescription = null, tint = Color.White, modifier = Modifier.size(24.dp))
        }
        Spacer(Modifier.height(14.dp))
        Text(stringResource(R.string.ai_sites_hero_title), color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
        Spacer(Modifier.height(4.dp))
        Text(stringResource(R.string.ai_sites_hero_sub), color = Color.White.copy(alpha = 0.88f), fontSize = 13.5.sp, lineHeight = 20.sp)
        Spacer(Modifier.height(16.dp))

        if (canBuild) {
            Row(
                Modifier.fillMaxWidth().height(50.dp).clip(RoundedCornerShape(15.dp)).background(Color.White).clickable(onClick = onNew),
                horizontalArrangement = Arrangement.Center,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(Icons.Rounded.Add, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(20.dp))
                Spacer(Modifier.width(6.dp))
                Text(stringResource(R.string.ai_sites_new), color = Ai.Red, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
            }
        } else if (message != null) {
            Text(message, color = Color.White, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, lineHeight = 19.sp)
        }

        Spacer(Modifier.height(16.dp))
        Column(
            Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Color.White.copy(alpha = 0.1f)).padding(horizontal = 14.dp, vertical = 12.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            listOf(R.string.ai_sites_how_1, R.string.ai_sites_how_2, R.string.ai_sites_how_3).forEachIndexed { index, label ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(24.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.25f)), contentAlignment = Alignment.Center) {
                        Text("${index + 1}", color = Color.White, fontSize = 12.sp, fontWeight = FontWeight.Bold)
                    }
                    Spacer(Modifier.width(10.dp))
                    Text(stringResource(label), color = Color.White.copy(alpha = 0.95f), fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                }
            }
        }
    }
}

@Composable
private fun OfferCard(offer: AiSiteOfferDto, onClick: () -> Unit) {
    val c = sitePalette()
    SiteCard(Modifier.padding(vertical = 5.dp), onClick = onClick) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(offer.name, color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
                Text(stringResource(R.string.ai_sites_offer_gens, offer.generationsIncluded), color = c.mut, fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
                if (!offer.description.isNullOrBlank()) Text(offer.description, color = c.mut, fontSize = 12.sp, modifier = Modifier.padding(top = 4.dp))
            }
            Spacer(Modifier.width(10.dp))
            Box(Modifier.clip(RoundedCornerShape(12.dp)).background(Ai.Red.copy(alpha = 0.12f)).padding(horizontal = 12.dp, vertical = 8.dp)) {
                Text("${offer.price ?: ""} ${offer.currency.orEmpty()}".trim(), color = Ai.Red, fontWeight = FontWeight.ExtraBold, fontSize = 13.sp)
            }
        }
    }
}

@Composable
private fun ProjectCard(project: AiSiteProjectDto, onClick: () -> Unit) {
    val c = sitePalette()
    SiteCard(Modifier.padding(vertical = 5.dp), onClick = onClick, padding = 14.dp) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            SiteAvatar(project.title, project.id)
            Spacer(Modifier.width(12.dp))
            Column(Modifier.weight(1f)) {
                Text(project.title, color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp, maxLines = 1)
                Spacer(Modifier.height(6.dp))
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    SiteStatusChip(project.status, project.isDisabled)
                    if (project.versionList.isNotEmpty()) {
                        Text(stringResource(R.string.ai_sites_versions_count, project.versionList.size), color = c.mut, fontSize = 11.5.sp)
                    }
                }
            }
            Icon(Icons.AutoMirrored.Rounded.KeyboardArrowRight, contentDescription = null, tint = c.mut, modifier = Modifier.size(22.dp))
        }
    }
}

@Composable
internal fun SitesHome(onNew: (AiSiteOfferDto?) -> Unit, onOpen: (Int) -> Unit) {
    val c = sitePalette()
    var loading by remember { mutableStateOf(true) }
    var offers by remember { mutableStateOf<AiSiteOffersDto?>(null) }
    var projects by remember { mutableStateOf<List<AiSiteProjectDto>>(emptyList()) }
    var pickOffer by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        offers = runCatching { ApiClient.aiSites.offers(chatAuth()).data }.getOrNull()
        projects = runCatching { ApiClient.aiSites.projects(chatAuth()).data.orEmpty() }.getOrDefault(emptyList())
        loading = false
    }

    if (loading) {
        Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Ai.Red) }
        return
    }

    val info = offers
    val available = info?.offers.orEmpty().filter { it.available }
    val canBuild = info != null && info.enabled && (info.plan?.canCreate == true || available.isNotEmpty())
    val message = when {
        info != null && !info.enabled -> stringResource(R.string.ai_sites_unavailable)
        !canBuild -> stringResource(R.string.ai_sites_not_included)
        else -> null
    }

    LazyColumn(Modifier.fillMaxSize().padding(horizontal = 16.dp), contentPadding = PaddingValues(top = 6.dp, bottom = 32.dp)) {
        item {
            SiteHero(canBuild, message) {
                if (info?.plan?.canCreate == true) onNew(null) else pickOffer = true
            }
            Spacer(Modifier.height(18.dp))
        }

        if (pickOffer) {
            item {
                SiteSectionTitle(stringResource(R.string.ai_sites_choose_offer))
                Spacer(Modifier.height(6.dp))
            }
            items(available, key = { "offer-${it.id}" }) { offer -> OfferCard(offer) { onNew(offer) } }
            item { Spacer(Modifier.height(14.dp)) }
        }

        if (projects.isEmpty()) {
            item {
                Column(Modifier.fillMaxWidth().padding(vertical = 28.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                    Box(Modifier.size(64.dp).clip(CircleShape).background(Ai.Red.copy(alpha = 0.1f)), contentAlignment = Alignment.Center) {
                        Icon(Icons.Rounded.Web, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(30.dp))
                    }
                    Spacer(Modifier.height(12.dp))
                    Text(stringResource(R.string.ai_sites_empty_title), color = c.ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
                    Text(stringResource(R.string.ai_sites_empty_sub), color = c.mut, fontSize = 12.5.sp, modifier = Modifier.padding(top = 4.dp))
                }
            }
        } else {
            item {
                SiteSectionTitle(stringResource(R.string.ai_sites_my_sites))
                Spacer(Modifier.height(6.dp))
            }
            items(projects, key = { "project-${it.id}" }) { project -> ProjectCard(project) { onOpen(project.id) } }
        }
    }
}
