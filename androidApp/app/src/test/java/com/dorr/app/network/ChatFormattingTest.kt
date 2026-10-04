package com.dorr.app.network

import com.dorr.app.ui.screens.chat.parseChatText
import com.dorr.app.ui.screens.chat.plainChatText
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** The same cases as the server (ChatEssentialsTest) and the web formatter. */
class ChatFormattingTest {
    @Test
    fun marksBecomeStylesAndDisappear() {
        val pieces = parseChatText("*bold* and _it_ ~st~ `c`")
        assertEquals("bold and it st c", pieces.joinToString("") { it.text })
        assertTrue(pieces.first { it.text == "bold" }.bold)
        assertTrue(pieces.first { it.text == "it" }.italic)
        assertTrue(pieces.first { it.text == "st" }.strike)
        assertTrue(pieces.first { it.text == "c" }.code)
    }

    @Test
    fun wordsAndMathsStayAsTyped() {
        assertEquals("price 5*3*2 = 30", plainChatText("price 5*3*2 = 30"))
        assertEquals("snake_case_name", plainChatText("snake_case_name"))
        assertEquals("* spaced *", plainChatText("* spaced *"))
        assertEquals("*line\nbreak*", plainChatText("*line\nbreak*"))
        assertEquals("(bold)", plainChatText("(*bold*)"))
    }

    @Test
    fun marksNestAndCodeIsLeftAlone() {
        val both = parseChatText("_*both*_").single()
        assertTrue(both.bold && both.italic)
        assertEquals("*not bold*", parseChatText("`*not bold*`").single().text)
        val block = parseChatText("```x = *1*```").single()
        assertTrue(block.codeBlock)
        assertEquals("x = *1*", block.text)
    }

    @Test
    fun linksAreNeverFormatted() {
        val pieces = parseChatText("see https://a.com/_x_ now")
        assertEquals("https://a.com/_x_", pieces.first { it.url != null }.text)
        assertEquals("see https://a.com/_x_ now", pieces.joinToString("") { it.text })
    }

    @Test
    fun arabicWorks() {
        val pieces = parseChatText("*مرحبا* يا _صاحبي_")
        assertEquals("مرحبا يا صاحبي", pieces.joinToString("") { it.text })
        assertTrue(pieces.first { it.text == "مرحبا" }.bold)
    }
}
