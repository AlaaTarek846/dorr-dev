package com.dorr.app.ui.screens.calendar

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.File
import java.time.DayOfWeek
import java.time.LocalDate
import java.time.YearMonth
import java.util.Locale

/**
 * The calendar speaks one language: the app's. Days, months and times follow the locale they are given (the app's,
 * not the phone's), and the English and Arabic strings stay in step.
 */
class CalendarTranslationTest {
    private val ar = Locale.forLanguageTag("ar")
    private val en = Locale.forLanguageTag("en")
    private val monday = LocalDate.of(2026, 10, 12)

    private fun hasArabic(s: String) = s.any { it in '؀'..'ۿ' }

    private fun hasLatinLetters(s: String) = s.any { it in 'A'..'Z' || it in 'a'..'z' }

    @Test
    fun daysAndMonthsFollowTheGivenLanguage() {
        assertTrue(dayTitle(monday, ar), hasArabic(dayTitle(monday, ar)) && !hasLatinLetters(dayTitle(monday, ar)))
        assertTrue(dayTitle(monday, ar).contains("،"))
        assertTrue(dayTitle(monday, en), hasLatinLetters(dayTitle(monday, en)) && !hasArabic(dayTitle(monday, en)))
        assertTrue(dayTitle(monday, en).contains("Monday, "))

        assertTrue(hasArabic(shortDay(monday, ar)))
        assertTrue(shortDay(monday, en).contains("Oct"))
        assertTrue(hasArabic(monthTitle(YearMonth.of(2026, 10), ar)))
        assertTrue(monthTitle(YearMonth.of(2026, 10), en).startsWith("October"))
        assertTrue(hasArabic(weekdayShort(DayOfWeek.MONDAY, ar)))
        assertEquals("Mon", weekdayShort(DayOfWeek.MONDAY, en))
    }

    @Test
    fun timesFollowTheGivenLanguage() {
        val time = java.time.LocalTime.of(17, 5)
        assertTrue(hasArabic(time.format(timeFormatter(ar))))
        assertFalse(hasArabic(time.format(timeFormatter(en))))
        assertTrue(time.format(timeFormatter(en)).contains("5:05"))
    }

    // ------------------------------------------------------------------ the two string files stay in step

    private fun entries(file: String, tag: String): Map<String, String> {
        val xml = File(file).readText()
        return Regex("""<$tag name="([^"]+)"[^>]*>([\s\S]*?)</$tag>""").findAll(xml).associate { it.groupValues[1] to it.groupValues[2] }
    }

    private val placeholder = Regex("""%\d\$[ds]""")

    private fun placeholders(text: String) = placeholder.findAll(text).map { it.value }.toSortedSet()

    @Test
    fun everyEnglishCalendarStringHasAnArabicOneWithTheSamePlaceholders() {
        val en = entries("src/main/res/values/calendar_strings.xml", "string")
        val ar = entries("src/main/res/values-ar/calendar_strings.xml", "string")
        assertTrue("some strings expected", en.size > 100)
        assertEquals("missing in Arabic", emptySet<String>(), en.keys - ar.keys)
        assertEquals("missing in English", emptySet<String>(), ar.keys - en.keys)
        en.forEach { (key, text) -> assertEquals("placeholders of $key", placeholders(text), placeholders(ar.getValue(key))) }
    }

    @Test
    fun countsHaveEveryArabicPluralForm() {
        val en = entries("src/main/res/values/calendar_strings.xml", "plurals")
        val ar = entries("src/main/res/values-ar/calendar_strings.xml", "plurals")
        assertEquals(en.keys, ar.keys)
        val forms = listOf("zero", "one", "two", "few", "many", "other")
        ar.forEach { (key, body) -> forms.forEach { assertTrue("$key lacks $it", body.contains("quantity=\"$it\"")) } }
        en.forEach { (key, body) -> assertTrue("$key lacks other", body.contains("quantity=\"other\"")) }
    }

    @Test
    fun noEnglishWordsInArabicTextsAndNoArabicInEnglish() {
        val en = entries("src/main/res/values/calendar_strings.xml", "string")
        val ar = entries("src/main/res/values-ar/calendar_strings.xml", "string")
        en.forEach { (key, text) -> assertFalse("Arabic in English text $key", hasArabic(text)) }
        // Arabic texts may keep a product name or a time like 9:00, but never a spoken English word.
        val allowed = setOf("DORR", "AI")
        ar.forEach { (key, text) ->
            val words = Regex("[A-Za-z]{2,}").findAll(text.replace(placeholder, "")).map { it.value }.filter { it !in allowed }.toList()
            assertTrue("English words in Arabic text $key: $words", words.isEmpty())
        }
    }
}
