package com.dorr.app.network

import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Real calendar answers (src/test/resources/calendar, recorded from the API step by step: tasks turned off, then the
 * Today sections reordered and one hidden) through the same plain Gson the app's Retrofit uses.
 */
class CalendarJsonParsingTest {
    private val gson = Gson()

    private fun json(name: String) = javaClass.classLoader!!.getResource("calendar/$name")!!.readText()

    private fun prefs(name: String): CalPrefsDto =
        gson.fromJson<ApiEnvelope<CalPrefsDto>>(json(name), object : TypeToken<ApiEnvelope<CalPrefsDto>>() {}.type).data!!

    private fun today(name: String): CalTodayDto =
        gson.fromJson<ApiEnvelope<CalTodayDto>>(json(name), object : TypeToken<ApiEnvelope<CalTodayDto>>() {}.type).data!!

    @Test
    fun preferencesCarryEverySourceAndTheSections() {
        val p = prefs("prefs_initial.json")
        assertEquals(setOf("events", "moments", "personal", "tasks", "reminders", "discover", "sports"), p.sources.keys)
        assertTrue(p.sources.values.all { it })
        assertEquals(listOf("next", "events", "tasks", "reminders", "moments", "around"), p.todaySections?.order)
    }

    @Test
    fun turningASourceOffComesBack() {
        assertEquals(false, prefs("prefs_tasks_off.json").sources["tasks"])
        val t = today("today_tasks_off.json")
        assertTrue(t.tasks.isEmpty())
        assertEquals("Gym", t.next?.title)
    }

    @Test
    fun todayKeepsMyOrderAndHiddenSections() {
        val p = prefs("prefs_sections.json")
        assertEquals("events", p.todaySections?.order?.first())
        assertEquals(listOf("next"), p.todaySections?.hidden)

        val t = today("today_sections.json")
        assertNotNull(t.sections)
        assertEquals("events", t.sections!!.order.first())
        assertEquals(listOf("next"), t.sections!!.hidden)
        assertFalse(t.events.isEmpty())
    }
}
