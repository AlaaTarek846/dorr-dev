package com.dorr.app.network

import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.google.gson.TypeAdapter
import com.google.gson.stream.JsonReader
import com.google.gson.stream.JsonToken
import com.google.gson.stream.JsonWriter

/**
 * For a free-form `JsonObject?` field (a message's `meta`, a notification's `data`). Gson 2.11 reads
 * `"meta": null` as JsonNull and then fails with "Expected a JsonObject but was JsonNull", which
 * threw away the whole response: every text message (meta is null) and any page holding one.
 * Null, or anything that isn't an object (`[]` from an empty PHP array), becomes null here.
 */
class NullableJsonObjectAdapter : TypeAdapter<JsonObject?>() {
    override fun read(reader: JsonReader): JsonObject? {
        if (reader.peek() == JsonToken.NULL) {
            reader.nextNull()
            return null
        }
        val element = JsonParser.parseReader(reader)
        return if (element.isJsonObject) element.asJsonObject else null
    }

    override fun write(writer: JsonWriter, value: JsonObject?) {
        if (value == null) {
            writer.nullValue()
            return
        }
        writer.jsonValue(value.toString())
    }
}
