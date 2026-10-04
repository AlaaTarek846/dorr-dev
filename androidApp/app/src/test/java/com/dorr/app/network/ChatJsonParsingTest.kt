package com.dorr.app.network

import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Test

/**
 * Real API answers (src/test/resources/chat) through the same plain Gson the app's Retrofit and
 * ChatStore use. Text messages carry `"meta": null`, which Gson 2.11 used to reject for a
 * JsonObject field, losing the whole page (a message request wouldn't open, sent texts "failed").
 */
class ChatJsonParsingTest {
    private val gson = Gson()

    private fun json(name: String) = javaClass.classLoader!!.getResource("chat/$name")!!.readText()

    private fun page(raw: String): MessagePageDto =
        gson.fromJson<ApiEnvelope<MessagePageDto>>(raw, object : TypeToken<ApiEnvelope<MessagePageDto>>() {}.type).data!!

    @Test
    fun conversationsParse() {
        for (name in listOf("req_conv.json", "accepted_conv.json")) {
            val env: ApiEnvelope<ConversationDto> = gson.fromJson(json(name), object : TypeToken<ApiEnvelope<ConversationDto>>() {}.type)
            assertNotNull(name, env.data)
        }
    }

    @Test
    fun aMessageRequestWithOnlyTextOpens() {
        val messages = page(json("req_msgs.json")).messages
        assertEquals(1, messages.size)
        assertEquals("text", messages[0].type)
        assertNull(messages[0].meta)
    }

    @Test
    fun aPageMixingTextAndCardsKeepsEveryMessage() {
        val messages = page(json("accepted_msgs.json")).messages
        assertEquals(8, messages.size)
        assertNull(messages.first { it.type == "text" }.meta)
        assertNotNull(messages.first { it.type == "wallet_qr" }.meta?.get("wallet_number"))
    }

    @Test
    fun anEmptyPhpArrayAsMetaIsReadAsNull() {
        val raw = json("req_msgs.json").replace("\"meta\":null", "\"meta\":[]")
        assertNull(page(raw).messages[0].meta)
    }

    @Test
    fun savedMessagesReadBackTheSame() {
        // ChatStore writes the list with Gson and reads it back on the next open.
        val original = page(json("accepted_msgs.json")).messages
        val restored: List<MessageDto> = gson.fromJson(gson.toJson(original), object : TypeToken<List<MessageDto>>() {}.type)
        assertEquals(original, restored)
    }
}
