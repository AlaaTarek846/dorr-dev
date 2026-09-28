package com.dorr.app.ui.screens.chat

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.scaleIn
import androidx.compose.animation.scaleOut
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
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
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.ContactDto
import com.dorr.app.network.StoryPrivacyDto
import kotlinx.coroutines.launch

@Composable
fun StoryPrivacyPage() {
    val host = LocalChat.current
    val scope = rememberCoroutineScope()
    var privacy by remember { mutableStateOf<StoryPrivacyDto?>(null) }
    var picking by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) { privacy = runCatching { ApiClient.chat.storyPrivacy(chatAuth()).data }.getOrNull() }

    fun save(audience: String, except: List<Int>? = null, only: List<Int>? = null) = scope.launch {
        val body = buildMap<String, Any> {
            put("audience", audience)
            except?.let { put("except", it) }
            only?.let { put("only", it) }
        }
        runCatching { ApiClient.chat.updateStoryPrivacy(chatAuth(), body).data }.getOrNull()?.let { privacy = it }
    }

    ChPage(stringResource(R.string.st_privacy), onBack = { host.pop() }) {
        val p = privacy
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(14.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Card {
                listOf(
                    Triple("contacts", R.string.st_audience_contacts, null),
                    Triple("except", R.string.st_audience_except, p?.except?.size),
                    Triple("only", R.string.st_audience_only, p?.only?.size),
                ).forEach { (value, label, count) ->
                    AudienceOption(
                        label = stringResource(label),
                        detail = count?.takeIf { it > 0 }?.let { stringResource(R.string.st_people_selected, it) },
                        selected = p?.audience == value,
                    ) {
                        if (value == "contacts") save("contacts") else picking = value
                    }
                }
            }
            Text(stringResource(R.string.st_privacy_note), color = Ch.Mut, fontSize = 12.5.sp, modifier = Modifier.padding(horizontal = 8.dp))
        }
    }

    picking?.let { list ->
        val preselected = (if (list == "except") privacy?.except else privacy?.only).orEmpty().map { it.id }
        PeoplePicker(preselected, onDismiss = { picking = null }) { ids ->
            picking = null
            if (list == "except") save("except", except = ids) else save("only", only = ids)
        }
    }
}

@Composable
private fun AudienceOption(label: String, detail: String?, selected: Boolean, onClick: () -> Unit) {
    val ring by animateColorAsState(if (selected) Ch.Red else Ch.Soft, label = "radio")
    Row(Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 16.dp, vertical = 16.dp), verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(24.dp).border(2.dp, ring, CircleShape), contentAlignment = Alignment.Center) {
            AnimatedContent(selected, label = "dot", transitionSpec = { scaleIn(spring(dampingRatio = 0.4f)) togetherWith scaleOut() }) { on ->
                if (on) Box(Modifier.size(12.dp).background(Ch.Red, CircleShape)) else Spacer(Modifier.size(12.dp))
            }
        }
        Spacer(Modifier.width(14.dp))
        Column(Modifier.weight(1f)) {
            Text(label, color = Ch.Ink, fontWeight = FontWeight.Bold, fontSize = 15.sp)
            detail?.let { Text(it, color = Ch.Red, fontSize = 12.sp, fontWeight = FontWeight.SemiBold) }
        }
    }
}

/** Pick people (my contacts on Dorr). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PeoplePicker(preselected: List<Int>, onDismiss: () -> Unit, onDone: (List<Int>) -> Unit) {
    var contacts by remember { mutableStateOf<List<ContactDto>?>(null) }
    val selected = remember { mutableStateListOf<Int>().apply { addAll(preselected) } }
    LaunchedEffect(Unit) { contacts = runCatching { ApiClient.chat.contacts(chatAuth(), registered = 1).data }.getOrNull().orEmpty() }

    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Ch.Surface, shape = RoundedCornerShape(topStart = 30.dp, topEnd = 30.dp)) {
        Column(Modifier.fillMaxWidth().padding(horizontal = 18.dp).padding(bottom = 24.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(stringResource(R.string.st_choose_people), color = Ch.Ink, fontSize = 18.sp, fontWeight = FontWeight.ExtraBold)
                    Text(stringResource(R.string.st_people_selected, selected.size), color = Ch.Mut, fontSize = 12.5.sp)
                }
                ChPrimaryButton(stringResource(R.string.st_done)) { onDone(selected.toList()) }
            }
            Spacer(Modifier.size(12.dp))
            val list = contacts
            if (list == null) {
                com.dorr.app.ui.screens.wallet.WaSkeleton(Modifier.fillMaxWidth().heightIn(min = 160.dp))
            } else {
                LazyColumn(Modifier.heightIn(max = 460.dp)) {
                    itemsIndexed(list.filter { it.profile != null }, key = { _, c -> c.id }) { i, c ->
                        val id = c.profile!!.id
                        val on = id in selected
                        Row(
                            Modifier.fillMaxWidth().chStagger(i).clip(RoundedCornerShape(16.dp)).clickable { if (on) selected.remove(id) else selected.add(id) }.padding(vertical = 8.dp, horizontal = 6.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            ChAvatar(c.profile.avatar, c.name, c.profile.key, size = 44.dp)
                            Spacer(Modifier.width(12.dp))
                            Text(c.name, color = Ch.Ink, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                            AnimatedContent(on, label = "pick", transitionSpec = { scaleIn(spring(dampingRatio = 0.4f)) togetherWith scaleOut() }) { checked ->
                                Icon(if (checked) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked, null, tint = if (checked) Ch.Red else Ch.Soft, modifier = Modifier.size(26.dp))
                            }
                        }
                    }
                }
            }
        }
    }
}
