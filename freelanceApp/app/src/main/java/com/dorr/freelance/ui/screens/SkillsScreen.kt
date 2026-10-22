package com.dorr.freelance.ui.screens

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.core.network.ApiResult
import com.dorr.core.network.apiCall
import com.dorr.freelance.R
import com.dorr.freelance.net.ApiClient
import com.dorr.freelance.net.AppLocale
import com.dorr.freelance.net.CatalogSkillDto
import com.dorr.freelance.net.MySkillDto
import com.dorr.freelance.net.SetSkillsRequest
import com.dorr.freelance.net.SkillRow
import com.dorr.freelance.net.SuggestSkillRequest
import com.dorr.freelance.net.collectReconnectTick
import com.dorr.freelance.net.errorText
import com.dorr.freelance.ui.components.Card
import com.dorr.freelance.ui.components.ChipRow
import com.dorr.freelance.ui.components.DorrTextField
import com.dorr.freelance.ui.components.ErrorBanner
import com.dorr.freelance.ui.components.Page
import com.dorr.freelance.ui.kit.SettingsPrimaryButton
import com.dorr.freelance.ui.kit.settingsAccent
import com.dorr.freelance.ui.kit.settingsInk
import com.dorr.freelance.ui.kit.settingsMut
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

private const val MAX_SKILLS = 30

/**
 * The freelancer's skills: their own list with a level each, and a search over the catalog to add more. A skill that is not in
 * the catalog can be suggested — it joins the list as "under review" until the admin decides. The catalog is asked when the
 * page opens and when the search text settles (400 ms after the last key), never on a timer; the save is one request with the
 * whole list.
 */
@Composable
fun SkillsScreen(onBack: () -> Unit) {
    val reconnect = collectReconnectTick()
    val scope = rememberCoroutineScope()
    val offlineText = stringResource(R.string.login_error_offline)
    val genericError = stringResource(R.string.login_error_generic)
    val limitText = stringResource(R.string.skills_limit, MAX_SKILLS)
    val suggestedText = stringResource(R.string.skills_suggested)

    val mine = remember { mutableStateListOf<MySkillDto>() }
    var results by remember { mutableStateOf<List<CatalogSkillDto>>(emptyList()) }
    var query by remember { mutableStateOf("") }
    var loaded by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var info by remember { mutableStateOf<String?>(null) }
    var saving by remember { mutableStateOf(false) }
    var suggesting by remember { mutableStateOf(false) }

    LaunchedEffect(reconnect) {
        val result = apiCall { ApiClient.lists.mySkills() }
        if (result is ApiResult.Ok) {
            mine.clear()
            mine.addAll(result.data)
            loaded = true
            error = null
        } else {
            error = result.errorText(offlineText) ?: genericError
        }
    }

    // The search: the first page when the page opens, then whenever the text settles.
    LaunchedEffect(query, reconnect) {
        if (query.isNotEmpty()) delay(400)
        val result = apiCall { ApiClient.lists.skillCatalog(query.trim().ifEmpty { null }) }
        if (result is ApiResult.Ok) results = result.data
    }

    fun add(skill: CatalogSkillDto) {
        info = null
        if (mine.any { it.id == skill.id }) return
        if (mine.size >= MAX_SKILLS) {
            error = limitText
            return
        }
        error = null
        mine.add(MySkillDto(skill.id, skill.name, level = null, pending = skill.pending))
    }

    fun suggest() {
        suggesting = true
        error = null
        info = null
        scope.launch {
            val result = apiCall { ApiClient.lists.suggestSkill(SuggestSkillRequest(query.trim(), AppLocale.current)) }
            suggesting = false
            if (result is ApiResult.Ok) {
                add(result.data)
                if (result.data.created == true) info = suggestedText
            } else {
                error = result.errorText(offlineText) ?: genericError
            }
        }
    }

    fun save() {
        saving = true
        error = null
        scope.launch {
            val result = apiCall { ApiClient.lists.setSkills(SetSkillsRequest(mine.map { SkillRow(it.id, it.level) })) }
            saving = false
            if (result is ApiResult.Ok) onBack() else error = result.errorText(offlineText) ?: genericError
        }
    }

    val levels = listOf(
        "beginner" to stringResource(R.string.skills_level_beginner),
        "intermediate" to stringResource(R.string.skills_level_intermediate),
        "advanced" to stringResource(R.string.skills_level_advanced),
        "expert" to stringResource(R.string.skills_level_expert),
    )

    Page(title = stringResource(R.string.skills_title), onBack = onBack) {
        if (loaded) {
            Card {
                Text(stringResource(R.string.skills_mine) + " (${mine.size}/$MAX_SKILLS)", color = settingsInk(), fontSize = 15.sp, fontWeight = FontWeight.ExtraBold)
                if (mine.isEmpty()) Text(stringResource(R.string.common_empty), color = settingsMut(), fontSize = 13.sp)
                mine.forEachIndexed { index, skill ->
                    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Text(skill.name, color = settingsInk(), fontSize = 14.sp, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                            if (skill.pending) Text(stringResource(R.string.skills_pending), color = settingsMut(), fontSize = 12.sp)
                            Icon(Icons.Rounded.Close, contentDescription = stringResource(R.string.common_remove), tint = settingsMut(), modifier = Modifier.size(20.dp).clickable { mine.removeAt(index) })
                        }
                        ChipRow(
                            options = levels,
                            selected = setOfNotNull(skill.level),
                            onToggle = { level -> mine[index] = skill.copy(level = if (skill.level == level) null else level) },
                        )
                    }
                }
            }

            Card {
                DorrTextField(query, { query = it.take(80) }, placeholder = stringResource(R.string.skills_search), icon = Icons.Rounded.Search, modifier = Modifier.fillMaxWidth())
                val clean = query.trim()
                val exact = results.any { it.name.equals(clean, ignoreCase = true) }
                results.filter { r -> mine.none { it.id == r.id } }.forEach { skill ->
                    Row(
                        Modifier.fillMaxWidth().clickable { add(skill) }.padding(vertical = 4.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        Icon(Icons.Rounded.Add, contentDescription = null, tint = settingsAccent(), modifier = Modifier.size(20.dp))
                        Text(skill.name, color = settingsInk(), fontSize = 14.sp, modifier = Modifier.weight(1f))
                        if (skill.pending) Text(stringResource(R.string.skills_pending), color = settingsMut(), fontSize = 12.sp)
                    }
                }
                if (clean.length >= 2 && !exact) {
                    SettingsPrimaryButton(text = stringResource(R.string.skills_suggest, clean), loading = suggesting, onClick = ::suggest)
                }
            }
            SettingsPrimaryButton(text = stringResource(R.string.profile_save), loading = saving, onClick = ::save)
        }
        info?.let { Text(it, color = settingsMut(), fontSize = 12.sp) }
        ErrorBanner(error, Modifier.fillMaxWidth())
    }
}
