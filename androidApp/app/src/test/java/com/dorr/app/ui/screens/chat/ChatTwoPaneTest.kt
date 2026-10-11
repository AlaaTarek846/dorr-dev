package com.dorr.app.ui.screens.chat

import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

/** The chat side by side on a wide screen (spec 89): the list stays, one page stack opens beside it. */
class ChatTwoPaneTest {
    // A cancelled scope: the host's real-time listener never starts in a plain JVM test.
    private fun host() = ChatHost(CoroutineScope(Job().apply { cancel() }), onExit = {}, openWalletQr = {})

    @Test
    fun one_pane_stacks_every_page() {
        val h = host()
        h.push(ChRoute.Conversation("a"))
        h.push(ChRoute.Conversation("b"))
        assertEquals(listOf(ChRoute.List, ChRoute.Conversation("a"), ChRoute.Conversation("b")), h.stack.toList())
    }

    @Test
    fun side_by_side_a_chat_takes_the_place_of_the_one_open() {
        val h = host().apply { twoPane = true }
        assertNull(h.detail)
        h.push(ChRoute.Conversation("a"))
        h.push(ChRoute.Info("a"))
        h.push(ChRoute.Conversation("b"))
        assertEquals(listOf(ChRoute.List, ChRoute.Conversation("b")), h.stack.toList())
        assertEquals("b", h.openConversationId)

        // Already open under its info page: back to it, never a second copy.
        h.push(ChRoute.Info("b"))
        h.push(ChRoute.Conversation("b"))
        assertEquals(listOf(ChRoute.List, ChRoute.Conversation("b")), h.stack.toList())

        // Closing it leaves the list with nothing beside it.
        h.pop()
        assertNull(h.detail)
        assertNull(h.openConversationId)
    }

    @Test
    fun a_new_chat_becomes_its_conversation_beside_the_list() {
        val h = host().apply { twoPane = true }
        h.push(ChRoute.Conversation("a"))
        h.push(ChRoute.NewChat)
        h.replace(ChRoute.Conversation("c"))
        assertEquals(listOf(ChRoute.List, ChRoute.Conversation("c")), h.stack.toList())
    }
}
