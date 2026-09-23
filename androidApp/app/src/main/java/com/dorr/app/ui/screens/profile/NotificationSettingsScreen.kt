package com.dorr.app.ui.screens.profile

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import com.dorr.app.R
import com.dorr.app.ui.components.SettingsScaffold
import com.dorr.app.ui.theme.AppColors

private data class NotifToggle(val title: Int, val desc: Int)

private val toggles = listOf(
    NotifToggle(R.string.notif_push_title, R.string.notif_push_desc),
    NotifToggle(R.string.notif_updates_title, R.string.notif_updates_desc),
    NotifToggle(R.string.notif_promo_title, R.string.notif_promo_desc),
    NotifToggle(R.string.notif_email_title, R.string.notif_email_desc),
)

@Composable
fun NotificationSettingsScreen(onBack: () -> Unit) {
    val states = remember { mutableStateOf(List(toggles.size) { true }) }

    SettingsScaffold(title = stringResource(R.string.account_notifications), onBack = onBack) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(vertical = 8.dp),
        ) {
            toggles.forEachIndexed { index, toggle ->
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 20.dp, vertical = 14.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(modifier = Modifier.weight(1f)) {
                        Text(stringResource(toggle.title), style = MaterialTheme.typography.bodyLarge)
                        Text(
                            stringResource(toggle.desc),
                            style = MaterialTheme.typography.bodySmall,
                            color = AppColors.textSecondary,
                        )
                    }
                    Switch(
                        checked = states.value[index],
                        onCheckedChange = { checked ->
                            states.value = states.value.toMutableList().also { it[index] = checked }
                        },
                    )
                }
                if (index != toggles.lastIndex) HorizontalDivider(color = AppColors.divider)
            }
        }
    }
}
