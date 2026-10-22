package com.dorr.app.ui.screens.chat

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.ChatBubble
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.DarkMode
import androidx.compose.material.icons.rounded.Diversity3
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Inbox
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Mood
import androidx.compose.material.icons.rounded.Palette
import androidx.compose.material.icons.rounded.People
import androidx.compose.material.icons.rounded.Person
import androidx.compose.material.icons.rounded.PriorityHigh
import androidx.compose.material.icons.rounded.PushPin
import androidx.compose.material.icons.rounded.QrCode2
import androidx.compose.material.icons.rounded.Security
import androidx.compose.material.icons.rounded.Shield
import androidx.compose.material.icons.rounded.Speed
import androidx.compose.material.icons.rounded.Star
import androidx.compose.material.icons.rounded.Storefront
import androidx.compose.material.icons.rounded.TaskAlt
import androidx.compose.material.icons.rounded.TextFields
import androidx.compose.material.icons.rounded.Tune
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
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.ui.screens.AccountDark
import com.dorr.app.ui.screens.profile.PinkBackdrop
import com.dorr.app.ui.screens.profile.SubHeader
import com.dorr.app.ui.screens.profile.settingsAccent
import com.dorr.app.ui.screens.profile.settingsCard
import com.dorr.app.ui.screens.profile.settingsInk
import com.dorr.app.ui.screens.profile.settingsNight

internal enum class ChatSetSection { Menu, Account, Privacy, Messages, Org, Look, Data, Calls, Biz }

/** Chat overflow → Settings: Account-style list; each topic opens its own page. */
@Composable
fun ChatSettingsPage() {
    val host = LocalChat.current
    val context = LocalContext.current
    val dark = settingsNight()
    val section = host.chatSettingsSection
    var themeSheet by remember { mutableStateOf(false) }
    var comfort by remember { mutableStateOf(false) }
    var todayOpen by remember { mutableStateOf(false) }
    var importantOpen by remember { mutableStateOf(false) }
    val openAt: (String, String) -> Unit = { c, m -> host.focusRequest = c to m; host.push(ChRoute.Conversation(c)) }

    if (themeSheet) ThemeSheet { themeSheet = false }
    if (comfort) ChatComfortSheet { comfort = false }
    if (todayOpen) AiTodaySheet(onDismiss = { todayOpen = false }, onOpen = openAt)
    if (importantOpen) AiImportantSheet(null, onDismiss = { importantOpen = false }, onOpen = openAt)

    BackHandler(enabled = section != ChatSetSection.Menu) { host.chatSettingsSection = ChatSetSection.Menu }

    val title = when (section) {
        ChatSetSection.Menu -> R.string.ch_chat_settings
        ChatSetSection.Account -> R.string.ch_set_account
        ChatSetSection.Privacy -> R.string.ch_set_privacy
        ChatSetSection.Messages -> R.string.ch_set_messages
        ChatSetSection.Org -> R.string.ch_set_org
        ChatSetSection.Look -> R.string.ch_set_look
        ChatSetSection.Data -> R.string.ch_set_data
        ChatSetSection.Calls -> R.string.ch_set_calls
        ChatSetSection.Biz -> R.string.ch_set_biz
    }

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(title)) {
                if (section == ChatSetSection.Menu) host.pop() else host.chatSettingsSection = ChatSetSection.Menu
            }
            LazyColumn(
                modifier = Modifier.weight(1f).fillMaxWidth(),
                contentPadding = PaddingValues(start = 14.dp, end = 14.dp, bottom = 24.dp),
            ) {
                when (section) {
                    ChatSetSection.Menu -> {
                        item {
                            ChatSettingsCardRow(Icons.Rounded.Person, R.string.ch_set_account, dark) { host.chatSettingsSection = ChatSetSection.Account }
                            ChatSettingsCardRow(Icons.Rounded.Shield, R.string.ch_set_privacy, dark) { host.chatSettingsSection = ChatSetSection.Privacy }
                            ChatSettingsCardRow(Icons.Rounded.ChatBubble, R.string.ch_set_messages, dark) { host.chatSettingsSection = ChatSetSection.Messages }
                            ChatSettingsCardRow(Icons.Rounded.TaskAlt, R.string.ch_set_org, dark) { host.chatSettingsSection = ChatSetSection.Org }
                            ChatSettingsCardRow(Icons.Rounded.Palette, R.string.ch_set_look, dark) { host.chatSettingsSection = ChatSetSection.Look }
                            ChatSettingsCardRow(Icons.Rounded.Speed, R.string.ch_set_data, dark) { host.chatSettingsSection = ChatSetSection.Data }
                            ChatSettingsCardRow(Icons.Rounded.Call, R.string.ch_set_calls, dark) { host.chatSettingsSection = ChatSetSection.Calls }
                            ChatSettingsCardRow(Icons.Rounded.Storefront, R.string.ch_set_biz, dark) { host.chatSettingsSection = ChatSetSection.Biz }
                        }
                    }
                    ChatSetSection.Account -> item {
                        ChatSettingsGroup(dark) {
                            ChatSettingsRow(Icons.Rounded.QrCode2, R.string.ch_my_qr, dark) { host.push(ChRoute.MyQr) }
                            ChatSettingsRow(Icons.Rounded.Mood, R.string.ch_status_title, dark) { host.push(ChRoute.MyStatus) }
                            ChatSettingsRow(Icons.Rounded.People, R.string.ch_my_followers, dark, last = true) { host.push(ChRoute.Channels) }
                        }
                    }
                    ChatSetSection.Privacy -> item {
                        ChatSettingsGroup(dark) {
                            ChatSettingsRow(Icons.Rounded.Shield, R.string.ch_privacy_title, dark) { host.push(ChRoute.Privacy) }
                            ChatSettingsRow(Icons.Rounded.Security, R.string.cp_privacy_center, dark) { host.push(ChRoute.PrivacyCenter) }
                            ChatSettingsRow(Icons.Rounded.Lock, R.string.ch_locked_chats, dark) {
                                ChatLock.unlock(context) {
                                    host.filter = "locked"
                                    host.pop()
                                }
                            }
                            ChatSettingsRow(Icons.Rounded.Tune, R.string.at_title, dark, last = true) { host.push(ChRoute.CommRules) }
                        }
                    }
                    ChatSetSection.Messages -> item {
                        ChatSettingsGroup(dark) {
                            ChatSettingsRow(Icons.Rounded.Star, R.string.ch_starred_title, dark) { host.push(ChRoute.Starred) }
                            ChatSettingsRow(Icons.Rounded.PriorityHigh, R.string.ct_important, dark) { importantOpen = true }
                            ChatSettingsRow(Icons.Rounded.AutoAwesome, R.string.ct_today, dark) { todayOpen = true }
                            ChatSettingsRow(Icons.Rounded.History, R.string.cp_catch_up, dark, last = true) { host.push(ChRoute.CatchUp) }
                        }
                    }
                    ChatSetSection.Org -> item {
                        ChatSettingsGroup(dark) {
                            ChatSettingsRow(Icons.Rounded.TaskAlt, R.string.ct_tasks, dark) { host.push(ChRoute.Tasks) }
                            ChatSettingsRow(Icons.Rounded.PushPin, R.string.lf_my_commitments, dark) { host.push(ChRoute.Commitments) }
                            ChatSettingsRow(Icons.Rounded.Inbox, R.string.li_title, dark, last = true) { host.push(ChRoute.LifeInbox) }
                        }
                    }
                    ChatSetSection.Look -> item {
                        ChatSettingsGroup(dark) {
                            ChatSettingsRow(Icons.Rounded.DarkMode, R.string.ch_theme, dark) { themeSheet = true }
                            ChatSettingsRow(Icons.Rounded.TextFields, R.string.cp_comfort, dark, last = true) { comfort = true }
                        }
                    }
                    ChatSetSection.Data -> item {
                        ChatSettingsGroup(dark) {
                            ChatSettingsRow(Icons.Rounded.Speed, R.string.pf_title, dark, last = true) { host.push(ChRoute.DataPerformance) }
                        }
                    }
                    ChatSetSection.Calls -> item {
                        ChatSettingsGroup(dark) {
                            ChatSettingsRow(Icons.Rounded.Call, R.string.ch_calls_title, dark) { host.push(ChRoute.Calls) }
                            ChatSettingsRow(Icons.Rounded.Diversity3, R.string.cm_title, dark, last = true) { host.push(ChRoute.Communities) }
                        }
                    }
                    ChatSetSection.Biz -> item {
                        ChatSettingsGroup(dark) {
                            ChatSettingsRow(Icons.Rounded.Storefront, R.string.ch_business_title, dark, last = true) { host.push(ChRoute.Business) }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun ChatSettingsCardRow(icon: ImageVector, title: Int, dark: Boolean, onClick: () -> Unit) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val shape = RoundedCornerShape(14.dp)
    Row(
        Modifier
            .fillMaxWidth()
            .padding(vertical = 4.dp)
            .then(if (dark) Modifier else Modifier.shadow(6.dp, shape, spotColor = settingsAccent().copy(alpha = 0.07f)))
            .clip(shape)
            .background(settingsCard())
            .then(if (dark) Modifier.border(1.dp, AccountDark.line, shape) else Modifier)
            .clickable(onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            Modifier
                .size(34.dp)
                .clip(RoundedCornerShape(10.dp))
                .background(if (dark) AccountDark.well else settingsAccent().copy(alpha = 0.14f)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
        }
        Spacer(Modifier.width(10.dp))
        Text(stringResource(title), fontSize = 13.5.sp, fontWeight = FontWeight.Bold, color = settingsInk(), modifier = Modifier.weight(1f))
        Icon(
            Icons.Rounded.ChevronRight,
            null,
            tint = if (dark) AccountDark.chevron else settingsAccent().copy(alpha = 0.55f),
            modifier = Modifier.size(16.dp).graphicsLayer { if (rtl) scaleX = -1f },
        )
    }
}

@Composable
private fun ChatSettingsGroup(dark: Boolean, content: @Composable () -> Unit) {
    val shape = RoundedCornerShape(16.dp)
    Column(
        Modifier
            .fillMaxWidth()
            .padding(top = 6.dp)
            .then(if (dark) Modifier else Modifier.shadow(6.dp, shape, spotColor = settingsAccent().copy(alpha = 0.07f)))
            .clip(shape)
            .background(settingsCard())
            .then(if (dark) Modifier.border(1.dp, AccountDark.line, shape) else Modifier),
    ) { content() }
}

@Composable
private fun ChatSettingsRow(icon: ImageVector, title: Int, dark: Boolean, last: Boolean = false, onClick: () -> Unit) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    Column {
        Row(
            Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 12.dp, vertical = 11.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                Modifier
                    .size(34.dp)
                    .clip(RoundedCornerShape(10.dp))
                    .background(if (dark) AccountDark.well else settingsAccent().copy(alpha = 0.14f)),
                contentAlignment = Alignment.Center,
            ) {
                Icon(icon, null, tint = settingsAccent(), modifier = Modifier.size(18.dp))
            }
            Spacer(Modifier.width(10.dp))
            Text(
                stringResource(title),
                fontSize = 13.5.sp,
                fontWeight = FontWeight.Bold,
                color = settingsInk(),
                modifier = Modifier.weight(1f),
            )
            Icon(
                Icons.Rounded.ChevronRight,
                null,
                tint = if (dark) AccountDark.chevron else settingsAccent().copy(alpha = 0.55f),
                modifier = Modifier.size(16.dp).graphicsLayer { if (rtl) scaleX = -1f },
            )
        }
        if (!last) {
            Box(
                Modifier
                    .fillMaxWidth()
                    .padding(start = 56.dp)
                    .height(1.dp)
                    .background(if (dark) AccountDark.line else settingsAccent().copy(alpha = 0.08f)),
            )
        }
    }
}
