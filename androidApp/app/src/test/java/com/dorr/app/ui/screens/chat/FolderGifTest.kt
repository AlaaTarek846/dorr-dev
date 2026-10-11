package com.dorr.app.ui.screens.chat

import com.dorr.app.network.MessageDto
import com.google.gson.Gson
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** A clip sent as a GIF (spec 11) and a folder sent as a zip (spec 10), read from a message — from the server and while sending. */
class FolderGifTest {
    private fun message(json: String): MessageDto = Gson().fromJson(json, MessageDto::class.java)

    @Test
    fun a_gif_is_a_video_flagged_by_the_server_or_while_sending() {
        assertTrue(message("""{"id":"1","conversation_id":"c","type":"video","meta":{"gif":true}}""").isGifVideo())
        assertTrue(message("""{"id":"1","conversation_id":"c","type":"video","meta":{"gif":1,"duration_ms":4000}}""").isGifVideo())
        assertFalse(message("""{"id":"1","conversation_id":"c","type":"video","meta":{"duration_ms":4000}}""").isGifVideo())
        assertFalse(message("""{"id":"1","conversation_id":"c","type":"image","meta":{"gif":true}}""").isGifVideo())
    }

    @Test
    fun a_folder_has_its_name_and_count() {
        assertEquals("Trip" to 42, message("""{"id":"1","conversation_id":"c","type":"document","meta":{"folder":{"name":"Trip","count":42}}}""").folderInfo())
        assertEquals("Trip" to 3, message("""{"id":"1","conversation_id":"c","type":"document","meta":{"folder_name":"Trip","folder_count":3}}""").folderInfo())
        assertNull(message("""{"id":"1","conversation_id":"c","type":"document","meta":null}""").folderInfo())
    }

    @Test
    fun usage_sizes_read_well() {
        assertEquals("512 B", usageBytes(512))
        assertEquals("2 KB", usageBytes(2048))
        assertEquals("1.5 MB", usageBytes(1_572_864))
        assertEquals("2.00 GB", usageBytes(2_147_483_648))
    }
}
