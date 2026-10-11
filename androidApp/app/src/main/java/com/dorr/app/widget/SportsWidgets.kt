package com.dorr.app.widget

import android.appwidget.AppWidgetManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.glance.GlanceId
import androidx.glance.GlanceModifier
import androidx.glance.Image
import androidx.glance.ImageProvider
import androidx.glance.action.clickable
import androidx.glance.appwidget.GlanceAppWidget
import androidx.glance.appwidget.GlanceAppWidgetReceiver
import androidx.glance.appwidget.SizeMode
import androidx.glance.appwidget.action.actionStartActivity
import androidx.glance.appwidget.cornerRadius
import androidx.glance.appwidget.provideContent
import androidx.glance.appwidget.updateAll
import androidx.glance.background
import androidx.glance.layout.Alignment
import androidx.glance.layout.Box
import androidx.glance.layout.Column
import androidx.glance.layout.Row
import androidx.glance.layout.Spacer
import androidx.glance.layout.fillMaxSize
import androidx.glance.layout.fillMaxWidth
import androidx.glance.layout.height
import androidx.glance.layout.padding
import androidx.glance.layout.size
import androidx.glance.layout.width
import androidx.glance.text.FontWeight
import androidx.glance.text.Text
import androidx.glance.text.TextAlign
import androidx.glance.text.TextStyle
import androidx.glance.unit.ColorProvider
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import coil.imageLoader
import coil.request.ImageRequest
import coil.request.SuccessResult
import com.dorr.app.MainActivity
import com.dorr.app.R
import com.dorr.app.network.ApiClient
import com.dorr.app.network.AuthSession
import com.dorr.app.network.SpMatchDto
import com.dorr.app.network.SpWidgetDto
import com.google.gson.Gson
import java.io.File
import java.time.ZoneId
import java.util.concurrent.TimeUnit

/*
 * DORR Sports on the phone's home screen (docs/sports-plan.md §10.3.7): "My team" (my teams'
 * live or next match) and "Live now" (the biggest matches on). The data comes from our server
 * (`sports/widget`) every 15 minutes, right after a sports push, and whenever Sports opens; crests
 * are kept on the phone. Tapping opens the match in the app.
 */

// ------------------------------------------------------------------ data

object SportsWidgetStore {
    private const val PREFS = "sports_widget"
    private val gson = Gson()

    fun save(context: Context, data: SpWidgetDto) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString("data", gson.toJson(data)).putLong("at", System.currentTimeMillis()).apply()
    }

    fun load(context: Context): SpWidgetDto? =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString("data", null)?.let { runCatching { gson.fromJson(it, SpWidgetDto::class.java) }.getOrNull() }

    fun crest(context: Context, teamId: Int): Bitmap? =
        File(dir(context), "$teamId.png").takeIf { it.exists() }?.let { BitmapFactory.decodeFile(it.path) }

    fun dir(context: Context) = File(context.filesDir, "sports_widget_crests").apply { mkdirs() }
}

class SportsWidgetWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val ctx = applicationContext
        if (AuthSession.token.isNullOrBlank()) {
            SportsWidgetStore.save(ctx, SpWidgetDto(signedOut = true))
        } else {
            val data = runCatching { ApiClient.sports.widget("Bearer ${AuthSession.token}", ZoneId.systemDefault().id).data }.getOrNull() ?: return Result.retry()
            SportsWidgetStore.save(ctx, data)
            // Crests, once per team, small.
            (data.mine + data.live).flatMap { listOfNotNull(it.home, it.away) }.distinctBy { it.id }.take(20).forEach { t ->
                val file = File(SportsWidgetStore.dir(ctx), "${t.id}.png")
                if (!file.exists() && t.logo != null) {
                    val r = ctx.imageLoader.execute(ImageRequest.Builder(ctx).data(ApiClient.mediaUrl(t.logo)).size(96).allowHardware(false).build())
                    ((r as? SuccessResult)?.drawable as? android.graphics.drawable.BitmapDrawable)?.bitmap?.let { b -> file.outputStream().use { b.compress(Bitmap.CompressFormat.PNG, 100, it) } }
                }
            }
        }
        MyTeamWidget().updateAll(ctx)
        LiveWidget().updateAll(ctx)
        return Result.success()
    }
}

object SportsWidgets {
    private const val PERIODIC = "sports-widget-periodic"
    private const val NOW = "sports-widget-now"

    /** Whether any sports widget is on the home screen. */
    fun placed(context: Context): Boolean {
        val m = AppWidgetManager.getInstance(context)
        return listOf(MyTeamWidgetReceiver::class.java, LiveWidgetReceiver::class.java).any { m.getAppWidgetIds(ComponentName(context, it)).isNotEmpty() }
    }

    fun schedule(context: Context) {
        val req = PeriodicWorkRequestBuilder<SportsWidgetWorker>(15, TimeUnit.MINUTES)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()).build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(PERIODIC, ExistingPeriodicWorkPolicy.KEEP, req)
    }

    /** Read again now (a goal push, Sports opened…) — only when a widget is placed. */
    fun refreshNow(context: Context) {
        if (!placed(context)) return
        val req = OneTimeWorkRequestBuilder<SportsWidgetWorker>().setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()).build()
        WorkManager.getInstance(context).enqueueUniqueWork(NOW, ExistingWorkPolicy.REPLACE, req)
    }

    fun cancelIfUnused(context: Context) {
        if (!placed(context)) WorkManager.getInstance(context).cancelUniqueWork(PERIODIC)
    }

    /** Open the app on a match (or on Sports). */
    fun openIntent(context: Context, matchId: String?): Intent =
        Intent(context, MainActivity::class.java).apply {
            action = Intent.ACTION_VIEW
            data = Uri.parse("dorr://sports/match/${matchId.orEmpty()}")
            putExtra(MainActivity.EXTRA_SPORTS_MATCH, matchId.orEmpty())
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP
        }
}

// ------------------------------------------------------------------ the look

private val White = ColorProvider(Color.White)
private val Dim = ColorProvider(Color.White.copy(alpha = 0.72f))
private val LiveRed = ColorProvider(Color(0xFFFF4D6D))
private val Gold = ColorProvider(Color(0xFFFACC15))

@Composable
private fun WidgetCrest(context: Context, m: SpMatchDto, home: Boolean, size: Int) {
    val team = if (home) m.home else m.away
    val bmp = team?.let { SportsWidgetStore.crest(context, it.id) }
    Box(GlanceModifier.size(size.dp).cornerRadius((size / 2).dp).background(ColorProvider(Color.White)), contentAlignment = Alignment.Center) {
        if (bmp != null) Image(ImageProvider(bmp), contentDescription = team.name, modifier = GlanceModifier.size((size * 0.74f).dp))
        else Text((team?.code ?: team?.name?.take(2) ?: "?").uppercase(), style = TextStyle(color = ColorProvider(Color(0xFF0B1220)), fontSize = (size * 0.3f).sp, fontWeight = FontWeight.Bold))
    }
}

private fun scoreOrTime(m: SpMatchDto): String = when (m.status) {
    "live", "break", "finished" -> "${m.homeScore ?: 0} - ${m.awayScore ?: 0}"
    else -> m.localTime.orEmpty()
}

private fun clock(context: Context, m: SpMatchDto): String = when (m.status) {
    "live" -> m.minute?.let { "$it'" + (m.minuteExtra?.let { e -> "+$e" } ?: "") } ?: context.getString(R.string.spw_live)
    "break" -> context.getString(R.string.sp_ht)
    "finished" -> context.getString(R.string.sp_ft)
    else -> m.localDate.orEmpty()
}

// ------------------------------------------------------------------ "My team"

class MyTeamWidget : GlanceAppWidget() {
    override val sizeMode = SizeMode.Exact

    override suspend fun provideGlance(context: Context, id: GlanceId) {
        val data = SportsWidgetStore.load(context)
        provideContent { MyTeamContent(context, data) }
    }
}

@Composable
private fun MyTeamContent(context: Context, data: SpWidgetDto?) {
    val m = data?.mine?.firstOrNull()
    Box(
        GlanceModifier.fillMaxSize().background(ImageProvider(R.drawable.sp_widget_bg)).cornerRadius(24.dp).padding(14.dp)
            .clickable(actionStartActivity(SportsWidgets.openIntent(context, m?.id))),
        contentAlignment = Alignment.Center,
    ) {
        when {
            data == null -> Text(context.getString(R.string.spw_loading), style = TextStyle(color = Dim, fontSize = 13.sp))
            data.signedOut -> Text(context.getString(R.string.spw_sign_in), style = TextStyle(color = Dim, fontSize = 13.sp, textAlign = TextAlign.Center))
            m == null -> Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Text("⚽", style = TextStyle(fontSize = 22.sp))
                Text(context.getString(R.string.spw_pick_teams), style = TextStyle(color = Dim, fontSize = 13.sp, textAlign = TextAlign.Center))
            }
            else -> Column(GlanceModifier.fillMaxSize()) {
                Row(GlanceModifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(m.competition?.name.orEmpty(), style = TextStyle(color = Dim, fontSize = 11.sp), maxLines = 1, modifier = GlanceModifier.defaultWeight())
                    val live = m.status == "live" || m.status == "break"
                    Text(if (live) "● " + clock(context, m) else clock(context, m), style = TextStyle(color = if (live) LiveRed else Dim, fontSize = 11.sp, fontWeight = FontWeight.Bold))
                }
                Spacer(GlanceModifier.defaultWeight())
                Row(GlanceModifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Column(GlanceModifier.defaultWeight(), horizontalAlignment = Alignment.CenterHorizontally) {
                        WidgetCrest(context, m, home = true, size = 46)
                        Text(m.home?.name.orEmpty(), style = TextStyle(color = White, fontSize = 12.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center), maxLines = 1)
                    }
                    Column(GlanceModifier.width(96.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                        Text(scoreOrTime(m), style = TextStyle(color = White, fontSize = 26.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center))
                    }
                    Column(GlanceModifier.defaultWeight(), horizontalAlignment = Alignment.CenterHorizontally) {
                        WidgetCrest(context, m, home = false, size = 46)
                        Text(m.away?.name.orEmpty(), style = TextStyle(color = White, fontSize = 12.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center), maxLines = 1)
                    }
                }
                Spacer(GlanceModifier.defaultWeight())
                data.mine.getOrNull(1)?.let { n ->
                    Text(context.getString(R.string.spw_then, n.home?.name.orEmpty(), n.away?.name.orEmpty(), listOfNotNull(n.localDate, n.localTime).joinToString(" ")), style = TextStyle(color = Dim, fontSize = 10.5.sp), maxLines = 1)
                }
            }
        }
    }
}

class MyTeamWidgetReceiver : GlanceAppWidgetReceiver() {
    override val glanceAppWidget: GlanceAppWidget = MyTeamWidget()

    override fun onEnabled(context: Context) {
        super.onEnabled(context)
        SportsWidgets.schedule(context)
        SportsWidgets.refreshNow(context)
    }

    override fun onDisabled(context: Context) {
        super.onDisabled(context)
        SportsWidgets.cancelIfUnused(context)
    }
}

// ------------------------------------------------------------------ "Live now"

class LiveWidget : GlanceAppWidget() {
    override val sizeMode = SizeMode.Exact

    override suspend fun provideGlance(context: Context, id: GlanceId) {
        val data = SportsWidgetStore.load(context)
        provideContent { LiveContent(context, data) }
    }
}

@Composable
private fun LiveContent(context: Context, data: SpWidgetDto?) {
    Column(
        GlanceModifier.fillMaxSize().background(ImageProvider(R.drawable.sp_widget_bg)).cornerRadius(24.dp).padding(12.dp)
            .clickable(actionStartActivity(SportsWidgets.openIntent(context, null))),
    ) {
        Row(GlanceModifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text("● ", style = TextStyle(color = LiveRed, fontSize = 12.sp, fontWeight = FontWeight.Bold))
            Text(context.getString(R.string.spw_live_now), style = TextStyle(color = White, fontSize = 13.sp, fontWeight = FontWeight.Bold), modifier = GlanceModifier.defaultWeight())
            Text("DORR", style = TextStyle(color = Gold, fontSize = 10.sp, fontWeight = FontWeight.Bold))
        }
        Spacer(GlanceModifier.height(6.dp))
        val list = data?.live.orEmpty()
        when {
            data == null -> Text(context.getString(R.string.spw_loading), style = TextStyle(color = Dim, fontSize = 12.sp))
            data.signedOut -> Text(context.getString(R.string.spw_sign_in), style = TextStyle(color = Dim, fontSize = 12.sp))
            list.isEmpty() -> Text(context.getString(R.string.spw_no_live), style = TextStyle(color = Dim, fontSize = 12.sp))
            else -> list.take(4).forEach { m ->
                Row(
                    GlanceModifier.fillMaxWidth().padding(vertical = 4.dp).clickable(actionStartActivity(SportsWidgets.openIntent(context, m.id))),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    WidgetCrest(context, m, home = true, size = 22)
                    Spacer(GlanceModifier.width(6.dp))
                    Text(m.home?.code ?: m.home?.name.orEmpty(), style = TextStyle(color = White, fontSize = 12.sp, fontWeight = FontWeight.Bold), maxLines = 1, modifier = GlanceModifier.defaultWeight())
                    Text(scoreOrTime(m), style = TextStyle(color = White, fontSize = 14.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center), modifier = GlanceModifier.width(54.dp))
                    Text(m.away?.code ?: m.away?.name.orEmpty(), style = TextStyle(color = White, fontSize = 12.sp, fontWeight = FontWeight.Bold, textAlign = TextAlign.End), maxLines = 1, modifier = GlanceModifier.defaultWeight())
                    Spacer(GlanceModifier.width(6.dp))
                    WidgetCrest(context, m, home = false, size = 22)
                    Text(" " + clock(context, m), style = TextStyle(color = LiveRed, fontSize = 10.sp, fontWeight = FontWeight.Bold), modifier = GlanceModifier.width(34.dp))
                }
            }
        }
    }
}

class LiveWidgetReceiver : GlanceAppWidgetReceiver() {
    override val glanceAppWidget: GlanceAppWidget = LiveWidget()

    override fun onEnabled(context: Context) {
        super.onEnabled(context)
        SportsWidgets.schedule(context)
        SportsWidgets.refreshNow(context)
    }

    override fun onDisabled(context: Context) {
        super.onDisabled(context)
        SportsWidgets.cancelIfUnused(context)
    }
}
