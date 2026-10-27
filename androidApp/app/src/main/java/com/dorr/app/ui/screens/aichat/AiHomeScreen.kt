package com.dorr.app.ui.screens.aichat

import androidx.activity.compose.BackHandler
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ArrowBackIosNew
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.ChevronRight
import androidx.compose.material.icons.rounded.Code
import androidx.compose.material.icons.rounded.Collections
import androidx.compose.material.icons.rounded.Description
import androidx.compose.material.icons.rounded.DocumentScanner
import androidx.compose.material.icons.rounded.Folder
import androidx.compose.material.icons.rounded.GraphicEq
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.Language
import androidx.compose.material.icons.rounded.Lock
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.Movie
import androidx.compose.material.icons.rounded.Psychology
import androidx.compose.material.icons.rounded.Public
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.Settings
import androidx.compose.material.icons.rounded.Translate
import androidx.compose.material.icons.rounded.ViewInAr
import androidx.compose.material.icons.rounded.Web
import androidx.compose.material.icons.rounded.WorkspacePremium
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
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.scale
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.dorr.app.R
import com.dorr.app.network.AiConversationDto
import com.dorr.app.network.AiStatusDto
import com.dorr.app.network.AuthSession
import com.dorr.app.ui.components.DorrLogo
import com.dorr.app.ui.screens.chat.timeAgo

/** Everything the DORR AI home can open — AiChatHost decides what each one does. */
internal enum class AiHomeAction {
    NEW_CHAT, VOICE, IMAGE, VIDEO, DOCUMENT, TRANSLATE, CODE, ANALYZE,
    SITES, WORLD, AR,
    HISTORY, SEARCH, PROJECTS, OUTPUTS, CONTINUE,
    MEMORY, LANGUAGE, SUBSCRIPTION, SETTINGS,
}

/** One service on the home: what it is, in one line, and its own colours. */
private data class AiService(
    val action: AiHomeAction,
    val icon: ImageVector,
    val title: Int,
    val sub: Int,
    val colors: List<Color>,
)

private val Violet = listOf(Color(0xFFA78BFA), Color(0xFF6D28D9))
private val Rose = listOf(Color(0xFFFB7185), Color(0xFFBE123C))
private val Ocean = listOf(Color(0xFF60A5FA), Color(0xFF1D4ED8))
private val Teal = listOf(Color(0xFF2DD4BF), Color(0xFF0F766E))
private val Amber = listOf(Color(0xFFFBBF24), Color(0xFFC2410C))
private val Indigo = listOf(Color(0xFF22D3EE), Color(0xFF4338CA))
private val Emerald = listOf(Color(0xFF34D399), Color(0xFF047857))
private val Sky = listOf(Color(0xFF38BDF8), Color(0xFF0369A1))
private val Slate = listOf(Color(0xFF94A3B8), Color(0xFF334155))
private val Navy = Color(0xFF001B53)

/**
 * The DORR AI home (spec 147–152, 310): what the assistant offers, at a glance, in the order a
 * person reaches for it —
 *
 * 1. Ask: the hero with the ask bar, a voice call, and one-tap starters (image, video, file,
 *    translate, code, analyse a file) that open a new chat already saying what to do.
 * 2. Continue: the latest conversations.
 * 3. Create: the studio — images, videos, documents, websites, DORR World, AR models.
 * 4. Your work: projects, creations, search, continue earlier chats, all chats.
 * 5. Make it yours: memory, language & dialect, plan.
 *
 * The plan chip says where the person stands (trial, their plan, or "subscribe") without starting
 * anything; when DORR AI isn't available here, everything stays visible but says so.
 */
@Composable
internal fun AiHomeScreen(
    night: Boolean,
    status: AiStatusDto?,
    loading: Boolean,
    errorMessage: String?,
    conversations: List<AiConversationDto>,
    onExit: () -> Unit,
    onRetry: () -> Unit,
    onAction: (AiHomeAction) -> Unit,
    onOpenConversation: (Int) -> Unit,
) {
    val bg = if (night) Ai.bgDark else Ai.bgLight
    val ink = if (night) Ai.inkDark else Ai.inkLight
    val mut = if (night) Ai.mutDark else Ai.mutLight
    val surface = if (night) Ai.surfaceDark else Ai.surfaceLight
    val line = if (night) Ai.lineDark else Ai.lineLight
    val unavailable = status != null && !status.available
    val brand = status?.brandName ?: "DORR AI"

    BackHandler { onExit() }

    // Sections float in one after another the first time the home opens.
    var shown by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { shown = true }

    val create = listOf(
        AiService(AiHomeAction.IMAGE, Icons.Rounded.Image, R.string.ai_home_image, R.string.ai_home_image_sub, Violet),
        AiService(AiHomeAction.VIDEO, Icons.Rounded.Movie, R.string.ai_home_video, R.string.ai_home_video_sub, Rose),
        AiService(AiHomeAction.DOCUMENT, Icons.Rounded.Description, R.string.ai_home_document, R.string.ai_home_document_sub, Ocean),
        AiService(AiHomeAction.SITES, Icons.Rounded.Web, R.string.ai_sites_title, R.string.ai_home_sites_sub, Teal),
        AiService(AiHomeAction.WORLD, Icons.Rounded.Public, R.string.ai_world_title, R.string.ai_home_world_sub, Amber),
        AiService(AiHomeAction.AR, Icons.Rounded.ViewInAr, R.string.ai_ar_title, R.string.ai_home_ar_sub, Indigo),
    )
    val work = listOf(
        AiService(AiHomeAction.PROJECTS, Icons.Rounded.Folder, R.string.ai_tools_projects_title, R.string.ai_tools_projects_sub, Ocean),
        AiService(AiHomeAction.OUTPUTS, Icons.Rounded.Collections, R.string.ai_tools_outputs_title, R.string.ai_tools_outputs_sub, Violet),
        AiService(AiHomeAction.SEARCH, Icons.Rounded.Search, R.string.ai_tools_search_title, R.string.ai_home_search_sub, Sky),
        AiService(AiHomeAction.CONTINUE, Icons.Rounded.History, R.string.ai_tools_continue, R.string.ai_home_continue_sub, Teal),
    )
    val yours = listOf(
        AiService(AiHomeAction.MEMORY, Icons.Rounded.Psychology, R.string.ai_tools_memory_title, R.string.ai_tools_memory_sub, Violet),
        AiService(AiHomeAction.LANGUAGE, Icons.Rounded.Language, R.string.ai_language_title, R.string.ai_settings_language_sub, Emerald),
        AiService(AiHomeAction.SUBSCRIPTION, Icons.Rounded.WorkspacePremium, R.string.ai_home_plans, R.string.ai_settings_subscription_sub, Amber),
    )
    val act: (AiHomeAction) -> Unit = { if (!unavailable || it in setOf(AiHomeAction.SUBSCRIPTION, AiHomeAction.HISTORY, AiHomeAction.SETTINGS)) onAction(it) }

    Column(Modifier.fillMaxSize().background(bg)) {
        // ---------------------------------------------------------------- top bar
        Row(
            Modifier.fillMaxWidth().statusBarsPadding().padding(horizontal = 8.dp, vertical = 6.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            RoundIcon(Icons.Rounded.ArrowBackIosNew, ink, onClick = onExit, size = 18.dp)
            Spacer(Modifier.width(6.dp))
            DorrLogo(width = 58.dp, onDark = night)
            Spacer(Modifier.width(6.dp))
            Box(Modifier.clip(RoundedCornerShape(7.dp)).background(Ai.Red).padding(horizontal = 6.dp, vertical = 1.dp)) {
                Text("AI", color = Color.White, fontWeight = FontWeight.Black, fontSize = 11.sp)
            }
            Spacer(Modifier.weight(1f))
            if (!unavailable) RoundIcon(Icons.Rounded.Search, mut, onClick = { act(AiHomeAction.SEARCH) })
            RoundIcon(Icons.Rounded.Settings, mut, onClick = { onAction(AiHomeAction.SETTINGS) })
        }

        LazyColumn(
            Modifier.fillMaxSize(),
            contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 4.dp, bottom = 32.dp),
            verticalArrangement = Arrangement.spacedBy(22.dp),
        ) {
            // ------------------------------------------------------------ 1. ask
            item(key = "hero") {
                Appear(shown, 0) { Hero(status, brand, unavailable, onAction = act, onSubscribe = { onAction(AiHomeAction.SUBSCRIPTION) }) }
            }
            item(key = "starters") {
                Appear(shown, 1) { Starters(surface, ink, line, enabled = !unavailable, onAction = act) }
            }
            if (loading && conversations.isEmpty() && status == null) {
                item(key = "loading") {
                    Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(color = Ai.Red, strokeWidth = 2.5.dp, modifier = Modifier.size(26.dp))
                    }
                }
            }
            errorMessage?.takeIf { conversations.isEmpty() }?.let { message ->
                item(key = "error") {
                    Row(
                        Modifier.fillMaxWidth().clip(RoundedCornerShape(16.dp)).background(Color(0xFFFEE2E2)).clickable(onClick = onRetry).padding(14.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(message, color = Color(0xFFB91C1C), fontSize = 12.5.sp, modifier = Modifier.weight(1f))
                        Text(stringResource(R.string.ai_retry), color = Color(0xFFB91C1C), fontWeight = FontWeight.Bold, fontSize = 12.5.sp)
                    }
                }
            }

            // ------------------------------------------------------------ 2. continue
            if (conversations.isNotEmpty()) {
                item(key = "recent") {
                    Appear(shown, 2) {
                        Column {
                            SectionTitle(stringResource(R.string.ai_home_recent), ink, mut, action = stringResource(R.string.ai_home_all_chats)) { onAction(AiHomeAction.HISTORY) }
                            Spacer(Modifier.height(10.dp))
                            LazyRow(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                                items(conversations.take(8), key = { it.id }) { conversation ->
                                    RecentCard(conversation, surface, ink, mut, line) { onOpenConversation(conversation.id) }
                                }
                            }
                        }
                    }
                }
            }

            // ------------------------------------------------------------ 3. create
            item(key = "create") {
                Appear(shown, 3) {
                    Column {
                        SectionTitle(stringResource(R.string.ai_home_create), ink, mut, sub = stringResource(R.string.ai_home_create_sub))
                        Spacer(Modifier.height(12.dp))
                        ServiceGrid(create, surface, ink, mut, line, enabled = !unavailable, onAction = act)
                    }
                }
            }

            // ------------------------------------------------------------ 4. your work
            item(key = "work") {
                Appear(shown, 4) {
                    Column {
                        SectionTitle(stringResource(R.string.ai_home_work), ink, mut, sub = stringResource(R.string.ai_home_work_sub))
                        Spacer(Modifier.height(12.dp))
                        ServiceList(work, surface, ink, mut, line, enabled = !unavailable, onAction = act)
                    }
                }
            }

            // ------------------------------------------------------------ 5. make it yours
            item(key = "yours") {
                Appear(shown, 5) {
                    Column {
                        SectionTitle(stringResource(R.string.ai_home_yours), ink, mut, sub = stringResource(R.string.ai_home_yours_sub))
                        Spacer(Modifier.height(12.dp))
                        ServiceList(yours, surface, ink, mut, line, enabled = true, onAction = act)
                    }
                }
            }
            item(key = "footer") {
                Text(
                    stringResource(R.string.ai_home_footer, brand), color = mut, fontSize = 11.sp,
                    textAlign = androidx.compose.ui.text.style.TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().navigationBarsPadding().padding(top = 4.dp),
                )
            }
        }
    }
}

// ==================================================================== hero

@Composable
private fun Hero(status: AiStatusDto?, brand: String, unavailable: Boolean, onAction: (AiHomeAction) -> Unit, onSubscribe: () -> Unit) {
    val accent = Ai.Red
    val firstName = AuthSession.user?.name?.trim()?.substringBefore(' ')?.takeIf { it.isNotBlank() }
    val shape = RoundedCornerShape(28.dp)
    Box(
        Modifier.fillMaxWidth()
            .shadow(18.dp, shape, spotColor = accent.copy(alpha = 0.35f))
            .clip(shape)
            .background(Brush.linearGradient(listOf(Navy, Color(0xFF0B2E7A), accent), start = Offset(0f, 0f), end = Offset(1100f, 900f))),
    ) {
        // Soft light in the corner, and the logo as a faint watermark (opacity only — spec 341).
        Canvas(Modifier.matchParentSize()) {
            drawCircle(Color.White.copy(alpha = 0.07f), radius = size.width * 0.45f, center = Offset(size.width * 0.95f, -size.height * 0.05f))
            drawCircle(Color.White.copy(alpha = 0.05f), radius = size.width * 0.28f, center = Offset(size.width * 0.05f, size.height * 1.05f))
        }
        DorrLogo(width = 150.dp, onDark = true, modifier = Modifier.align(Alignment.BottomEnd).padding(end = 10.dp, bottom = 70.dp).alpha(0.07f))

        Column(Modifier.padding(20.dp)) {
            PlanChip(status, onSubscribe)
            Spacer(Modifier.height(14.dp))
            Text(
                if (firstName != null) stringResource(R.string.ai_home_hello_name, firstName) else stringResource(R.string.ai_home_hello),
                color = Color.White.copy(alpha = 0.85f), fontSize = 14.sp, fontWeight = FontWeight.Medium,
            )
            Text(stringResource(R.string.ai_home_headline), color = Color.White, fontSize = 23.sp, fontWeight = FontWeight.ExtraBold, lineHeight = 30.sp)
            Spacer(Modifier.height(16.dp))
            if (unavailable) {
                Text(stringResource(R.string.ai_unavailable, brand), color = Color.White, fontSize = 13.sp, modifier = Modifier.clip(RoundedCornerShape(14.dp)).background(Color.White.copy(alpha = 0.14f)).padding(12.dp))
            } else {
                // The ask bar: a tap starts a new chat; the mic is a live voice call.
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Pressable(Modifier.weight(1f), onClick = { onAction(AiHomeAction.NEW_CHAT) }) {
                        Row(
                            Modifier.fillMaxWidth().clip(RoundedCornerShape(18.dp)).background(Color.White).padding(horizontal = 14.dp, vertical = 14.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(Icons.Rounded.AutoAwesome, contentDescription = null, tint = accent, modifier = Modifier.size(18.dp))
                            Spacer(Modifier.width(10.dp))
                            Text(stringResource(R.string.ai_home_ask, brand), color = Color(0xFF6B7280), fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                    }
                    Spacer(Modifier.width(10.dp))
                    Pressable(onClick = { onAction(AiHomeAction.VOICE) }) {
                        Box(
                            Modifier.size(50.dp).clip(RoundedCornerShape(18.dp)).background(Color.White.copy(alpha = 0.16f)).border(1.dp, Color.White.copy(alpha = 0.35f), RoundedCornerShape(18.dp)),
                            contentAlignment = Alignment.Center,
                        ) { Icon(Icons.Rounded.Mic, contentDescription = stringResource(R.string.ai_voice_mode), tint = Color.White, modifier = Modifier.size(22.dp)) }
                    }
                }
                Spacer(Modifier.height(10.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Rounded.GraphicEq, contentDescription = null, tint = Color.White.copy(alpha = 0.75f), modifier = Modifier.size(14.dp))
                    Spacer(Modifier.width(6.dp))
                    Text(stringResource(R.string.ai_home_voice_hint), color = Color.White.copy(alpha = 0.75f), fontSize = 11.5.sp)
                }
            }
        }
    }
}

/** Where the person stands with DORR AI — read-only; a tap opens the plans. */
@Composable
private fun PlanChip(status: AiStatusDto?, onClick: () -> Unit) {
    val plan = status?.plan ?: return
    val (icon, text) = when {
        !plan.allowed && plan.reason == "blocked" -> Icons.Rounded.Lock to stringResource(R.string.ai_home_plan_blocked)
        !plan.allowed -> Icons.Rounded.Lock to stringResource(R.string.ai_home_plan_subscribe)
        plan.isTrial -> Icons.Rounded.AutoAwesome to stringResource(R.string.ai_home_plan_trial)
        else -> Icons.Rounded.WorkspacePremium to (plan.planName ?: stringResource(R.string.ai_home_plans))
    }
    Row(
        Modifier.clip(RoundedCornerShape(50)).background(if (plan.allowed) Color.White.copy(alpha = 0.16f) else Color.White).clickable(onClick = onClick).padding(horizontal = 10.dp, vertical = 5.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, contentDescription = null, tint = if (plan.allowed) Color.White else Ai.Red, modifier = Modifier.size(13.dp))
        Spacer(Modifier.width(5.dp))
        Text(text, color = if (plan.allowed) Color.White else Ai.Red, fontSize = 11.5.sp, fontWeight = FontWeight.Bold)
    }
}

// ==================================================================== starters

/** One tap: a new chat that already says what to do (the server recognises each one). */
@Composable
private fun Starters(surface: Color, ink: Color, line: Color, enabled: Boolean, onAction: (AiHomeAction) -> Unit) {
    val starters = listOf(
        Triple(AiHomeAction.IMAGE, Icons.Rounded.Image, R.string.ai_home_start_image) to Violet,
        Triple(AiHomeAction.VIDEO, Icons.Rounded.Movie, R.string.ai_home_start_video) to Rose,
        Triple(AiHomeAction.DOCUMENT, Icons.Rounded.Description, R.string.ai_home_start_document) to Ocean,
        Triple(AiHomeAction.ANALYZE, Icons.Rounded.DocumentScanner, R.string.ai_home_start_analyze) to Sky,
        Triple(AiHomeAction.TRANSLATE, Icons.Rounded.Translate, R.string.ai_home_start_translate) to Emerald,
        Triple(AiHomeAction.CODE, Icons.Rounded.Code, R.string.ai_home_start_code) to Slate,
    )
    LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        items(starters, key = { it.first.first }) { (data, colors) ->
            val (action, icon, label) = data
            Pressable(onClick = { if (enabled) onAction(action) }) {
                Row(
                    Modifier.clip(RoundedCornerShape(50)).background(surface).border(1.dp, line, RoundedCornerShape(50)).padding(start = 6.dp, end = 14.dp, top = 6.dp, bottom = 6.dp).alpha(if (enabled) 1f else 0.45f),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Box(Modifier.size(26.dp).clip(CircleShape).background(Brush.linearGradient(colors)), contentAlignment = Alignment.Center) {
                        Icon(icon, contentDescription = null, tint = Color.White, modifier = Modifier.size(14.dp))
                    }
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(label), color = ink, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}

// ==================================================================== sections

@Composable
private fun SectionTitle(title: String, ink: Color, mut: Color, sub: String? = null, action: String? = null, onAction: (() -> Unit)? = null) {
    Row(verticalAlignment = Alignment.Bottom) {
        Column(Modifier.weight(1f)) {
            Text(title, color = ink, fontSize = 17.sp, fontWeight = FontWeight.ExtraBold)
            sub?.let { Text(it, color = mut, fontSize = 12.sp, modifier = Modifier.padding(top = 1.dp)) }
        }
        if (action != null && onAction != null) {
            Text(action, color = Ai.Red, fontSize = 12.5.sp, fontWeight = FontWeight.Bold, modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable(onClick = onAction).padding(4.dp))
        }
    }
}

@Composable
private fun RecentCard(conversation: AiConversationDto, surface: Color, ink: Color, mut: Color, line: Color, onClick: () -> Unit) {
    Pressable(onClick = onClick) {
        Column(
            Modifier.width(200.dp).heightIn(min = 112.dp).clip(RoundedCornerShape(20.dp)).background(surface).border(1.dp, line, RoundedCornerShape(20.dp)).padding(14.dp),
        ) {
            Box(Modifier.size(30.dp).clip(RoundedCornerShape(10.dp)).background(Ai.Red.copy(alpha = 0.12f)), contentAlignment = Alignment.Center) {
                Icon(Icons.Rounded.AutoAwesome, contentDescription = null, tint = Ai.Red, modifier = Modifier.size(15.dp))
            }
            Spacer(Modifier.height(10.dp))
            Text(
                conversation.title?.takeIf { it.isNotBlank() } ?: stringResource(R.string.ai_new_chat),
                color = ink, fontWeight = FontWeight.Bold, fontSize = 13.5.sp, maxLines = 2, overflow = TextOverflow.Ellipsis,
            )
            Spacer(Modifier.weight(1f, fill = false))
            Text(timeAgo(conversation.updatedAt ?: conversation.createdAt), color = mut, fontSize = 11.sp, modifier = Modifier.padding(top = 6.dp))
        }
    }
}

/** The studio: two cards a row, each with its own colour. */
@Composable
private fun ServiceGrid(services: List<AiService>, surface: Color, ink: Color, mut: Color, line: Color, enabled: Boolean, onAction: (AiHomeAction) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        services.chunked(2).forEach { row ->
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                row.forEach { service ->
                    Pressable(Modifier.weight(1f), onClick = { if (enabled) onAction(service.action) }) {
                        Column(
                            Modifier.fillMaxWidth().heightIn(min = 138.dp).clip(RoundedCornerShape(22.dp)).background(surface).border(1.dp, line, RoundedCornerShape(22.dp))
                                .alpha(if (enabled) 1f else 0.45f).padding(14.dp),
                        ) {
                            Box(
                                Modifier.size(44.dp).shadow(8.dp, RoundedCornerShape(14.dp), spotColor = service.colors.last().copy(alpha = 0.5f))
                                    .clip(RoundedCornerShape(14.dp)).background(Brush.linearGradient(service.colors)),
                                contentAlignment = Alignment.Center,
                            ) { Icon(service.icon, contentDescription = null, tint = Color.White, modifier = Modifier.size(22.dp)) }
                            Spacer(Modifier.height(12.dp))
                            Text(stringResource(service.title), color = ink, fontWeight = FontWeight.ExtraBold, fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                            Text(stringResource(service.sub), color = mut, fontSize = 11.5.sp, lineHeight = 15.sp, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.padding(top = 3.dp))
                        }
                    }
                }
                if (row.size == 1) Spacer(Modifier.weight(1f))
            }
        }
    }
}

/** Quieter rows in one card — the person's own space and settings. */
@Composable
private fun ServiceList(services: List<AiService>, surface: Color, ink: Color, mut: Color, line: Color, enabled: Boolean, onAction: (AiHomeAction) -> Unit) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(22.dp)).background(surface).border(1.dp, line, RoundedCornerShape(22.dp))) {
        services.forEachIndexed { index, service ->
            Row(
                Modifier.fillMaxWidth().clickable(enabled = enabled) { onAction(service.action) }.alpha(if (enabled) 1f else 0.45f).padding(horizontal = 14.dp, vertical = 12.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(Modifier.size(38.dp).clip(RoundedCornerShape(12.dp)).background(Brush.linearGradient(service.colors)), contentAlignment = Alignment.Center) {
                    Icon(service.icon, contentDescription = null, tint = Color.White, modifier = Modifier.size(19.dp))
                }
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(stringResource(service.title), color = ink, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                    Text(stringResource(service.sub), color = mut, fontSize = 11.5.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                Icon(Icons.Rounded.ChevronRight, contentDescription = null, tint = mut, modifier = Modifier.size(18.dp).graphicsLayer { if (rtl) scaleX = -1f })
            }
            if (index < services.lastIndex) Box(Modifier.fillMaxWidth().padding(start = 64.dp, end = 14.dp).height(1.dp).background(line))
        }
    }
}

// ==================================================================== small pieces

@Composable
private fun RoundIcon(icon: ImageVector, tint: Color, onClick: () -> Unit, size: Dp = 20.dp) {
    Box(Modifier.size(40.dp).clip(CircleShape).clickable(onClick = onClick), contentAlignment = Alignment.Center) {
        Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(size))
    }
}

/** Gives under the finger a little, like a real button. */
@Composable
private fun Pressable(modifier: Modifier = Modifier, onClick: () -> Unit, content: @Composable () -> Unit) {
    val interaction = remember { MutableInteractionSource() }
    val pressed by interaction.collectIsPressedAsState()
    val scale by animateFloatAsState(if (pressed) 0.96f else 1f, spring(dampingRatio = Spring.DampingRatioMediumBouncy), label = "press")
    Box(modifier.scale(scale).clickable(interactionSource = interaction, indication = null, onClick = onClick)) { content() }
}

/** Fades and rises into place, a beat after the section before it. */
@Composable
private fun Appear(shown: Boolean, order: Int, content: @Composable () -> Unit) {
    val alpha by animateFloatAsState(if (shown) 1f else 0f, tween(420, delayMillis = order * 70), label = "appear")
    val lift by animateFloatAsState(if (shown) 0f else 24f, tween(460, delayMillis = order * 70), label = "lift")
    Box(Modifier.graphicsLayer { this.alpha = alpha; translationY = lift }) { content() }
}
