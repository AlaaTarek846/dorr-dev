package com.dorr.freelance.net

import com.google.gson.TypeAdapter
import com.google.gson.stream.JsonReader
import com.google.gson.stream.JsonWriter

/**
 * Reads a currency code from the API as the sign people know: "SAR" shows as «﷼» everywhere a price is printed. A code without an
 * entry in [SIGNS] stays as the code (add the country's sign there to change that). A price is never sent back, so this only reads.
 */
class CurrencyLabelAdapter : TypeAdapter<String>() {
    override fun write(out: JsonWriter, value: String?) {
        out.value(value)
    }

    override fun read(reader: JsonReader): String {
        val code = reader.nextString()
        return SIGNS[code.trim().uppercase()] ?: code
    }

    private companion object {
        val SIGNS = mapOf("SAR" to "﷼", "USD" to "$")
    }
}
