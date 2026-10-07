package com.dorr.app.ui.screens.aichat

import android.Manifest
import android.content.pm.PackageManager
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ArrowBackIosNew
import androidx.compose.material.icons.rounded.CallEnd
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.MicOff
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import com.dorr.app.R
import com.dorr.app.network.AiRealtimeSessionEndRequestDto
import com.dorr.app.network.AiRealtimeSessionRequestDto
import com.dorr.app.network.ApiClient
import com.dorr.app.network.apiFailure
import com.dorr.app.ui.screens.chat.chatAuth
import com.dorr.app.voice.RealtimeVoiceClient
import com.dorr.app.voice.RealtimeVoiceState
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * Phase 7 (Realtime Voice) live call screen: mints a session from our backend
 * (`user/v1/ai-realtime/session`), opens a direct WebRTC call to OpenAI with
 * [RealtimeVoiceClient], and reports the call's duration back to the backend
 * on exit so it can be billed against the account's usual AI usage budget.
 *
 * See [RealtimeVoiceClient]'s docblock: the WebRTC piece itself has not been
 * exercised on a real device from this environment. This screen is otherwise
 * ordinary Compose/network code.
 */
@Composable
internal fun AiVoiceScreen(night: Boolean, conversationId: Int?, onExit: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    val bg = if (night) Ai.bgDark else Ai.bgLight
    val ink = if (night) Ai.inkDark else Ai.inkLight
    val mut = if (night) Ai.mutDark else Ai.mutLight

    val client = remember { RealtimeVoiceClient(context.applicationContext) }
    var sessionId by rememberSaveable { mutableStateOf<Int?>(null) }
    var startingUp by remember { mutableStateOf(true) }
    var setupError by remember { mutableStateOf<String?>(null) }
    var elapsedSeconds by remember { mutableStateOf(0) }
    var permissionDenied by remember { mutableStateOf(false) }
    var endedByUser by remember { mutableStateOf(false) }

    suspend fun startSession() {
        startingUp = true
        setupError = null
        val result = runCatching {
            ApiClient.aiChat.createRealtimeSession(
                chatAuth(),
                AiRealtimeSessionRequestDto(conversationId = conversationId, instructions = null),
            )
        }
        result.onSuccess { envelope ->
            val data = envelope.data
            if (envelope.success && data != null) {
                sessionId = data.sessionId
                startingUp = false
                client.connect(data.clientSecret, data.webrtcEndpoint)
            } else {
                setupError = envelope.message
                startingUp = false
            }
        }.onFailure {
            setupError = it.apiFailure().message
            startingUp = false
        }
    }

    fun endCall() {
        if (endedByUser) return
        endedByUser = true
        client.disconnect()
        val id = sessionId
        val duration = elapsedSeconds
        if (id != null) {
            scope.launch {
                runCatching {
                    ApiClient.aiChat.endRealtimeSession(chatAuth(), id, AiRealtimeSessionEndRequestDto(durationSeconds = duration))
                }
            }
        }
        onExit()
    }

    val micPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) {
            permissionDenied = false
            scope.launch { startSession() }
        } else {
            permissionDenied = true
            startingUp = false
        }
    }

    LaunchedEffect(Unit) {
        val granted = ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) ==
            PackageManager.PERMISSION_GRANTED
        if (granted) startSession() else micPermission.launch(Manifest.permission.RECORD_AUDIO)
    }

    // Call timer — only counts once the WebRTC leg is actually connected.
    LaunchedEffect(client.state) {
        if (client.state == RealtimeVoiceState.CONNECTED) {
            while (true) {
                delay(1000)
                elapsedSeconds += 1
            }
        }
    }

    DisposableEffect(Unit) {
        onDispose {
            if (!endedByUser) {
                client.disconnect()
                val id = sessionId
                if (id != null) {
                    val duration = elapsedSeconds
                    // Best-effort: the screen is already gone, so this uses its own
                    // short-lived scope rather than one tied to composition.
                    kotlinx.coroutines.CoroutineScope(kotlinx.coroutines.Dispatchers.IO).launch {
                        runCatching {
                            ApiClient.aiChat.endRealtimeSession(chatAuth(), id, AiRealtimeSessionEndRequestDto(durationSeconds = duration))
                        }
                    }
                }
            }
        }
    }

    BackHandler { endCall() }

    val infiniteTransition = rememberInfiniteTransition(label = "voice-pulse")
    val pulse by infiniteTransition.animateFloat(
        initialValue = 1f,
        targetValue = if (client.state == RealtimeVoiceState.CONNECTED) 1.08f else 1f,
        animationSpec = infiniteRepeatable(tween(900), repeatMode = RepeatMode.Reverse),
        label = "voice-pulse-scale",
    )

    Column(Modifier.fillMaxSize().background(bg)) {
        Row(
            Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 8.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(Modifier.size(38.dp).clip(CircleShape).clickable { endCall() }, contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.ArrowBackIosNew, contentDescription = null, tint = ink, modifier = Modifier.size(18.dp))
            }
            Spacer(Modifier.width(8.dp))
            Text(stringResource(R.string.ai_voice_mode), color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 15.sp)
        }

        Column(
            Modifier.weight(1f).fillMaxWidth().padding(24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.Center,
        ) {
            when {
                permissionDenied -> {
                    Text(stringResource(R.string.ai_voice_permission_denied), color = mut, fontSize = 13.sp)
                }
                setupError != null -> {
                    Text(setupError ?: "", color = mut, fontSize = 13.sp)
                    Spacer(Modifier.height(12.dp))
                    Text(
                        stringResource(R.string.ai_voice_retry),
                        color = Ai.Red,
                        fontWeight = FontWeight.SemiBold,
                        modifier = Modifier.clickable { scope.launch { startSession() } },
                    )
                }
                client.state == RealtimeVoiceState.FAILED -> {
                    Text(client.errorMessage ?: stringResource(R.string.ai_voice_error_generic), color = mut, fontSize = 13.sp)
                    Spacer(Modifier.height(12.dp))
                    Text(
                        stringResource(R.string.ai_voice_retry),
                        color = Ai.Red,
                        fontWeight = FontWeight.SemiBold,
                        modifier = Modifier.clickable { scope.launch { startSession() } },
                    )
                }
                else -> {
                    Box(
                        Modifier.size(160.dp).scale(pulse).clip(CircleShape).background(Ai.Red.copy(alpha = 0.15f)),
                        contentAlignment = Alignment.Center,
                    ) {
                        Box(Modifier.size(120.dp).clip(CircleShape).background(Ai.Red.copy(alpha = 0.85f)), contentAlignment = Alignment.Center) {
                            if (startingUp || client.state == RealtimeVoiceState.CONNECTING) {
                                CircularProgressIndicator(color = Color.White, strokeWidth = 2.5.dp, modifier = Modifier.size(28.dp))
                            } else {
                                Icon(
                                    if (client.muted) Icons.Rounded.MicOff else Icons.Rounded.Mic,
                                    contentDescription = null,
                                    tint = Color.White,
                                    modifier = Modifier.size(40.dp),
                                )
                            }
                        }
                    }
                    Spacer(Modifier.height(18.dp))
                    val statusLabel = when {
                        startingUp || client.state == RealtimeVoiceState.CONNECTING -> stringResource(R.string.ai_voice_connecting)
                        client.muted -> stringResource(R.string.ai_voice_muted)
                        client.assistantSpeaking -> stringResource(R.string.ai_voice_speaking)
                        else -> stringResource(R.string.ai_voice_listening)
                    }
                    Text(statusLabel, color = ink, fontWeight = FontWeight.SemiBold, fontSize = 15.sp)
                    if (client.state == RealtimeVoiceState.CONNECTED) {
                        Spacer(Modifier.height(4.dp))
                        val minutes = elapsedSeconds / 60
                        val seconds = elapsedSeconds % 60
                        Text(
                            "%d:%02d".format(minutes, seconds),
                            color = mut,
                            fontSize = 12.sp,
                        )
                    }
                    if (client.transcript.isNotBlank()) {
                        Spacer(Modifier.height(20.dp))
                        Text(
                            client.transcript,
                            color = mut,
                            fontSize = 13.sp,
                            textAlign = androidx.compose.ui.text.style.TextAlign.Center,
                            modifier = Modifier.fillMaxWidth(),
                        )
                    }
                }
            }
        }

        if (client.state == RealtimeVoiceState.CONNECTED || client.state == RealtimeVoiceState.CONNECTING) {
            Row(
                Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 32.dp, vertical = 28.dp),
                horizontalArrangement = Arrangement.SpaceEvenly,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(
                    Modifier.size(56.dp).clip(CircleShape)
                        .background(if (night) Ai.surfaceDark else Ai.surfaceLight)
                        .clickable(enabled = client.state == RealtimeVoiceState.CONNECTED) { client.toggleMute() },
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(
                        if (client.muted) Icons.Rounded.MicOff else Icons.Rounded.Mic,
                        contentDescription = stringResource(if (client.muted) R.string.ai_voice_unmute else R.string.ai_voice_mute),
                        tint = ink,
                        modifier = Modifier.size(24.dp),
                    )
                }
                Box(
                    Modifier.size(64.dp).clip(CircleShape).background(Color(0xFFDC2626)).clickable { endCall() },
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(Icons.Rounded.CallEnd, contentDescription = stringResource(R.string.ai_voice_end_call), tint = Color.White, modifier = Modifier.size(28.dp))
                }
            }
        }
    }
}
