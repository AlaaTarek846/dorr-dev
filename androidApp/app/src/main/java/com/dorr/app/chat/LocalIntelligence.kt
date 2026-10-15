package com.dorr.app.chat

import android.content.Context
import androidx.core.app.NotificationCompat
import com.dorr.app.R

/**
 * Local private intelligence (spec 156): a notification's text is looked at on this phone only —
 * nothing of it goes anywhere — to show what kind of message it is and to show less of it:
 *  - my own important words (kept on the phone only) make it stand out;
 *  - a code, money, an appointment or a question gets its label;
 *  - codes and money can stay off the lock screen (only the label shows there).
 * The phone is never the authority: the server already decided whether to send the notification
 * and what it may contain; this only changes how it's shown.
 */
object LocalIntelligence {
    private const val PREFS = "dorr_local_intelligence"

    enum class Kind(val emoji: String, val label: Int, val sensitive: Boolean) {
        Code("🔑", R.string.lp_kind_code, true),
        Money("💸", R.string.lp_kind_money, true),
        Appointment("🗓️", R.string.lp_kind_appointment, false),
        Question("❓", R.string.lp_kind_question, false),
    }

    private val codeWords = Regex("(?i)(code|otp|pin|verification|verify|كود|رمز|الرمز|تحقق|التحقق|كلمة السر)")
    private val digits = Regex("(?<!\\d)\\d{4,8}(?!\\d)")
    private val money = Regex("(?i)(\\d[\\d.,]*\\s?(sar|egp|aed|usd|\\$|€|£|ريال|ر\\.س|جنيه|ج\\.م|درهم|دولار))|((sar|egp|aed|usd|ريال|جنيه|درهم)\\s?\\d)")
    private val appointment = Regex("(?i)(\\b\\d{1,2}[:.]\\d{2}\\b|tomorrow|tonight|today at|بكره|بكرة|النهارده الساعة|الساعة \\d|يوم (السبت|الأحد|الاثنين|الإثنين|التلات|الثلاثاء|الأربع|الأربعاء|الخميس|الجمعة)|موعد|ميعاد)")
    private val question = Regex("(?i)([?؟]\\s*$|^(هل|ممكن|امتى|إمتى|فين|ليه|إزاي|ازاي|مين|كام|what|when|where|why|how|who|can you|could you|do you)\\b)")

    fun enabled(context: Context): Boolean = prefs(context).getBoolean("on", false)

    fun hideSensitive(context: Context): Boolean = prefs(context).getBoolean("hide_sensitive", true)

    fun keywords(context: Context): List<String> = prefs(context).getStringSet("keywords", emptySet()).orEmpty().sorted()

    fun setEnabled(context: Context, on: Boolean) = prefs(context).edit().putBoolean("on", on).apply()

    fun setHideSensitive(context: Context, on: Boolean) = prefs(context).edit().putBoolean("hide_sensitive", on).apply()

    fun setKeywords(context: Context, words: List<String>) =
        prefs(context).edit().putStringSet("keywords", words.map { it.trim() }.filter { it.length >= 2 }.take(50).toSet()).apply()

    /** What kind of message this is, judged here (first match wins: a code before money…). */
    fun kindOf(text: String): Kind? = when {
        codeWords.containsMatchIn(text) && digits.containsMatchIn(text) -> Kind.Code
        money.containsMatchIn(text) -> Kind.Money
        appointment.containsMatchIn(text) -> Kind.Appointment
        question.containsMatchIn(text.trim()) -> Kind.Question
        else -> null
    }

    fun matchesKeyword(context: Context, text: String): String? = keywords(context).firstOrNull { text.contains(it, ignoreCase = true) }

    /** Label, highlight and hide — on the notification about to be shown. */
    fun apply(context: Context, builder: NotificationCompat.Builder, title: String?, text: String?): NotificationCompat.Builder {
        val body = text.orEmpty()
        if (body.isBlank()) return builder
        val kind = kindOf(body)
        val keyword = matchesKeyword(context, body)
        val label = listOfNotNull(
            keyword?.let { "⭐ ${context.getString(R.string.lp_kind_keyword, it)}" },
            kind?.let { "${it.emoji} ${context.getString(it.label)}" },
        ).joinToString(" · ")
        if (label.isNotEmpty()) builder.setSubText(label)
        if (keyword != null) {
            builder.setPriority(NotificationCompat.PRIORITY_HIGH).setCategory(NotificationCompat.CATEGORY_MESSAGE)
        }
        if (kind?.sensitive == true && hideSensitive(context)) {
            // On the lock screen: only the label. Unlocked: the label instead of the text too.
            val shown = "${kind.emoji} ${context.getString(R.string.lp_hidden_body, context.getString(kind.label))}"
            builder.setContentText(shown).setStyle(NotificationCompat.BigTextStyle().bigText(shown))
            builder.setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            builder.setPublicVersion(
                NotificationCompat.Builder(context, "dorr_private_public").setSmallIcon(com.dorr.app.R.drawable.ic_stat_onesignal_default)
                    .setContentTitle(context.getString(R.string.app_name)).setContentText(shown).build(),
            )
        }
        return builder
    }

    private fun prefs(context: Context) = context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
}
