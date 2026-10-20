package com.dorr.app.ui.screens.calendar

import com.dorr.app.network.CalItemDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** Today's list is grouped by kind; a kind shows while one of its sources is on. */
class TodaySectionsTest {
    private fun item(type: String) = CalItemDto(id = "$type:1", type = type, ref = "1", title = "x")

    @Test
    fun anItemBelongsToTheKindOfItsType() {
        assertEquals("events", sectionOf(item("event")))
        assertEquals("events", sectionOf(item("discover")))
        assertEquals("events", sectionOf(item("sports")))
        assertEquals("tasks", sectionOf(item("task")))
        assertEquals("reminders", sectionOf(item("reminder")))
        assertEquals("moments", sectionOf(item("moment")))
        assertEquals("moments", sectionOf(item("personal")))
    }

    @Test
    fun anOlderServerWithoutSourcesShowsEverything() {
        DefaultSections.forEach { assertTrue(it, sectionSourcesOn(it, null)) }
    }

    @Test
    fun aKindGoesWhenAllItsSourcesAreOff() {
        val noTasks = listOf("events", "moments", "personal", "reminders", "discover", "sports")
        assertFalse(sectionSourcesOn("tasks", noTasks))
        assertTrue(sectionSourcesOn("events", noTasks))
        assertTrue(sectionSourcesOn("events", listOf("sports")))
        assertTrue(sectionSourcesOn("moments", listOf("personal")))
        assertFalse(sectionSourcesOn("moments", listOf("tasks")))
    }
}
