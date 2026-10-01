package com.dorr.app.ui.locale

import android.content.res.Resources
import android.icu.text.PluralRules
import java.util.Locale

/**
 * [Resources] for a downloaded language: string and plural lookups are answered from [strings]
 * (matched by resource entry name), anything missing — or any other resource type — falls back to
 * [base]. There is no values-xx folder for downloaded languages, so the fallback is the bundled
 * English in values/.
 */
@Suppress("DEPRECATION")
internal class DynamicResources(
    private val base: Resources,
    private val strings: Map<String, Any>,
    code: String,
) : Resources(base.assets, base.displayMetrics, base.configuration) {

    private val locale = Locale(code)
    private val pluralRules: PluralRules by lazy { PluralRules.forLocale(locale) }

    override fun getText(id: Int): CharSequence = text(id) ?: base.getText(id)

    override fun getString(id: Int): String = text(id) ?: base.getString(id)

    override fun getString(id: Int, vararg formatArgs: Any?): String =
        text(id)?.let { format(it, formatArgs) } ?: base.getString(id, *formatArgs)

    override fun getQuantityText(id: Int, quantity: Int): CharSequence =
        plural(id, quantity) ?: base.getQuantityText(id, quantity)

    override fun getQuantityString(id: Int, quantity: Int): String =
        plural(id, quantity) ?: base.getQuantityString(id, quantity)

    override fun getQuantityString(id: Int, quantity: Int, vararg formatArgs: Any?): String =
        plural(id, quantity)?.let { format(it, formatArgs) } ?: base.getQuantityString(id, quantity, *formatArgs)

    private fun text(id: Int): String? {
        val name = entry(id, "string") ?: return null
        return strings[name] as? String
    }

    private fun plural(id: Int, quantity: Int): String? {
        val name = entry(id, "plurals") ?: return null
        val forms = strings[name] as? Map<*, *> ?: return null
        val category = pluralRules.select(quantity.toDouble())
        return (forms[category] ?: forms["other"]) as? String
    }

    private fun entry(id: Int, type: String): String? = runCatching {
        if (base.getResourceTypeName(id) == type) base.getResourceEntryName(id) else null
    }.getOrNull()

    /** A translation with a broken placeholder must not crash the screen — it returns null so the caller falls back. */
    private fun format(text: String, args: Array<out Any?>): String? =
        runCatching { String.format(locale, text, *args) }.getOrNull()
}
