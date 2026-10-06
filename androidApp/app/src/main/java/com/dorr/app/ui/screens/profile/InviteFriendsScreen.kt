package com.dorr.app.ui.screens.profile

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.CardGiftcard
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.Favorite
import androidx.compose.material.icons.rounded.PersonAdd
import androidx.compose.material.icons.rounded.PhoneIphone
import androidx.compose.material.icons.rounded.Share
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AuthSession
import com.dorr.app.network.UserDto
import com.dorr.app.ui.screens.AccountDark
import kotlinx.coroutines.delay

internal fun inviteCodeFor(user: UserDto?): String {
    val id = user?.id ?: 0
    val slug = user?.name.orEmpty()
        .uppercase()
        .filter { it in 'A'..'Z' }
        .take(8)
    return if (slug.isNotEmpty()) "$slug-$id" else "DORR-$id"
}

@Composable
fun InviteFriendsScreen(onBack: () -> Unit) {
    val context = LocalContext.current
    val code = inviteCodeFor(AuthSession.user)
    val storeUrl = "https://play.google.com/store/apps/details?id=com.dorr.app"
    val shareBody = stringResource(R.string.invite_share_body, code, storeUrl)
    val copiedToast = stringResource(R.string.invite_copied)

    Box(Modifier.fillMaxSize()) {
        PinkBackdrop(Modifier.fillMaxSize())
        Column(Modifier.fillMaxSize()) {
            SubHeader(stringResource(R.string.invite_title), onBack)
            Column(
                modifier = Modifier
                    .weight(1f)
                    .verticalScroll(rememberScrollState())
                    .padding(horizontal = 14.dp)
                    .padding(bottom = 24.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                InviteHero()
                Text(
                    stringResource(R.string.invite_headline),
                    color = settingsInk(),
                    fontSize = 22.sp,
                    lineHeight = 28.sp,
                    fontWeight = FontWeight.ExtraBold,
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().padding(horizontal = 8.dp),
                )
                Spacer(Modifier.height(16.dp))
                InviteStep(Icons.Rounded.Share, R.string.invite_step1_title, R.string.invite_step1_body)
                InviteStep(Icons.Rounded.PersonAdd, R.string.invite_step2_title, R.string.invite_step2_body)
                InviteStep(Icons.Rounded.CardGiftcard, R.string.invite_step3_title, R.string.invite_step3_body)
                Spacer(Modifier.height(14.dp))
                InviteCodeButton(
                    code = code,
                    onCopy = {
                        val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
                        clipboard.setPrimaryClip(ClipData.newPlainText("invite", code))
                        Toast.makeText(context, copiedToast, Toast.LENGTH_SHORT).show()
                    },
                )
                Spacer(Modifier.height(14.dp))
                SettingsPrimaryButton(text = stringResource(R.string.invite_share_now)) {
                    val send = Intent(Intent.ACTION_SEND).apply {
                        type = "text/plain"
                        putExtra(Intent.EXTRA_TEXT, shareBody)
                    }
                    context.startActivity(Intent.createChooser(send, context.getString(R.string.invite_share_now)))
                }
            }
        }
    }
}

@Composable
private fun InviteCodeButton(code: String, onCopy: () -> Unit) {
    var copied by remember { mutableStateOf(false) }
    LaunchedEffect(copied) {
        if (copied) {
            delay(1600)
            copied = false
        }
    }
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .then(Modifier.settingsSurface(RoundedCornerShape(18.dp)))
            .clickable {
                onCopy()
                copied = true
            }
            .padding(horizontal = 14.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        PinkIcon(Icons.Rounded.Share, 40.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(
                stringResource(R.string.invite_code_label),
                color = settingsMut(),
                fontSize = 12.sp,
                fontWeight = FontWeight.SemiBold,
            )
            Spacer(Modifier.height(2.dp))
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Text(
                    code,
                    color = settingsInk(),
                    fontSize = 18.sp,
                    fontWeight = FontWeight.ExtraBold,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    letterSpacing = 0.4.sp,
                )
            }
        }
        Spacer(Modifier.width(8.dp))
        Box(
            modifier = Modifier
                .size(40.dp)
                .clip(CircleShape)
                .background(if (settingsNight()) AccountDark.well else settingsAccent().copy(alpha = 0.14f)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                if (copied) Icons.Rounded.Check else Icons.Rounded.ContentCopy,
                contentDescription = stringResource(R.string.invite_copy),
                tint = settingsAccent(),
                modifier = Modifier.size(18.dp),
            )
        }
    }
}

@Composable
private fun InviteHero() {
    val accent = settingsAccent()
    Box(
        modifier = Modifier
            .fillMaxWidth()
            .height(118.dp),
        contentAlignment = Alignment.Center,
    ) {
        PhoneBadge(Modifier.offset(x = (-22).dp, y = 6.dp), accent.copy(alpha = 0.72f))
        PhoneBadge(Modifier.offset(x = 22.dp, y = 6.dp), accent)
        Box(
            modifier = Modifier
                .offset(y = (-22).dp)
                .size(32.dp)
                .clip(CircleShape)
                .background(if (settingsNight()) AccountDark.well else accent.copy(alpha = 0.16f)),
            contentAlignment = Alignment.Center,
        ) {
            Icon(Icons.Rounded.Favorite, contentDescription = null, tint = accent, modifier = Modifier.size(16.dp))
        }
    }
}

@Composable
private fun PhoneBadge(modifier: Modifier, tint: Color) {
    Box(
        modifier = modifier
            .size(56.dp, 76.dp)
            .shadow(6.dp, RoundedCornerShape(16.dp), spotColor = tint.copy(alpha = 0.22f))
            .clip(RoundedCornerShape(16.dp))
            .background(tint)
            .border(2.dp, Color.White.copy(alpha = 0.28f), RoundedCornerShape(16.dp)),
        contentAlignment = Alignment.Center,
    ) {
        Icon(Icons.Rounded.PhoneIphone, contentDescription = null, tint = Color.White, modifier = Modifier.size(26.dp))
    }
}

@Composable
private fun InviteStep(icon: ImageVector, title: Int, body: Int) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 10.dp),
        verticalAlignment = Alignment.Top,
    ) {
        PinkIcon(icon, 36.dp)
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(
                stringResource(title),
                color = settingsInk(),
                fontSize = 14.sp,
                fontWeight = FontWeight.Bold,
            )
            Spacer(Modifier.height(2.dp))
            Text(
                stringResource(body),
                color = settingsMut(),
                fontSize = 12.sp,
                lineHeight = 17.sp,
            )
        }
    }
}
