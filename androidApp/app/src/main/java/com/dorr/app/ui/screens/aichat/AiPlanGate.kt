package com.dorr.app.ui.screens.aichat

import android.os.Handler
import android.os.Looper
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
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
import androidx.compose.material.icons.rounded.WorkspacePremium
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import com.dorr.app.R
import com.google.gson.JsonParser

/**
 * Every AI tool needs a DORR AI plan (decision 2026-10-15). Whichever screen asked — a chat tool,
 * an AI image, Discover's AI search — a plan refusal from the server lands here once, through the
 * API client (see [inspect]), and one prompt offers the DORR AI subscription.
 */
object AiPlanGate {
    /** The server's message for the refusal, while the prompt is up. */
    var prompt by mutableStateOf<String?>(null)
        private set

    /** Set by "Subscribe": the DORR AI screens open straight on the subscription page. */
    var openSubscription by mutableStateOf(false)

    /** Error codes that mean "this needs (a bigger) DORR AI plan". */
    private fun isPlanCode(code: String): Boolean =
        code.startsWith("chat_ai_plan_") || code == "chat_ai_image_not_in_plan" || code == "chat_ai_image_daily_limit" || code == "ai_plan_required"

    /** Called by the HTTP client with a 402/429 answer's body. */
    fun inspect(body: String) {
        val root = runCatching { JsonParser.parseString(body).asJsonObject }.getOrNull() ?: return
        val code = root.get("error_code")?.takeIf { it.isJsonPrimitive }?.asString ?: return
        if (code == "chat_ai_plan_blocked" || !isPlanCode(code)) return
        val message = root.get("message")?.takeIf { it.isJsonPrimitive }?.asString ?: return
        Handler(Looper.getMainLooper()).post { prompt = message }
    }

    /** Offers the plans from a screen that already knows it is locked (e.g. the AI image sheet). */
    fun offer(message: String) {
        prompt = message
    }

    fun dismiss() {
        prompt = null
    }

    fun subscribe() {
        prompt = null
        openSubscription = true
    }
}

@Composable
fun AiPlanPromptDialog(night: Boolean, onSubscribe: () -> Unit) {
    val text = AiPlanGate.prompt ?: return
    val surface = if (night) Ai.surfaceDark else Ai.surfaceLight
    val ink = if (night) Ai.inkDark else Ai.inkLight
    val mut = if (night) Ai.mutDark else Ai.mutLight

    Dialog(onDismissRequest = { AiPlanGate.dismiss() }) {
        Column(Modifier.clip(RoundedCornerShape(22.dp)).background(surface).padding(20.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            Box(Modifier.size(52.dp).clip(RoundedCornerShape(16.dp)).background(Ai.Red.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.WorkspacePremium, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(28.dp))
            }
            Spacer(Modifier.height(12.dp))
            Text(stringResource(R.string.ai_plan_required_title), color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 16.sp)
            Spacer(Modifier.height(6.dp))
            Text(text, color = mut, fontSize = 13.5.sp, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
            Spacer(Modifier.height(18.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End, verticalAlignment = Alignment.CenterVertically) {
                Text(
                    stringResource(R.string.ai_cancel), color = mut, fontWeight = FontWeight.Bold, fontSize = 13.sp,
                    modifier = Modifier.clip(RoundedCornerShape(10.dp)).clickable { AiPlanGate.dismiss() }.padding(10.dp),
                )
                Spacer(Modifier.width(6.dp))
                Box(
                    Modifier.clip(RoundedCornerShape(12.dp)).background(Ai.Red).clickable { AiPlanGate.subscribe(); onSubscribe() }.padding(horizontal = 16.dp, vertical = 10.dp),
                ) { Text(stringResource(R.string.ai_plan_required_subscribe), color = Color.White, fontWeight = FontWeight.Bold, fontSize = 13.sp) }
            }
        }
    }
}
