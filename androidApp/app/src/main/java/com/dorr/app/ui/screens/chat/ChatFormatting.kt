package com.dorr.app.ui.screens.chat

/**
 * WhatsApp-style text marks: *bold*, _italic_, ~strike~, `code`, ```mono block```.
 *
 * The same rules as the web bubble and the push text (ChatPushNotifier::plain on the server): a mark
 * only counts at a word edge and around non-blank text, so `5*3*2` and `snake_case` stay as typed;
 * nothing is formatted inside code or inside a link. Marks nest (`_*both*_`).
 */
data class ChatPiece(
    val text: String,
    val bold: Boolean = false,
    val italic: Boolean = false,
    val strike: Boolean = false,
    val code: Boolean = false,
    val codeBlock: Boolean = false,
    /** A link (or a Dorr invite) — tappable, never formatted. */
    val url: String? = null,
)

private val LinkPattern = Regex("(https?://\\S+|www\\.\\S+|dorr://chat/join/\\S+)")
private val BlockPattern = Regex("```([\\s\\S]+?)```")

private fun markPattern(mark: Char): Regex {
    val m = Regex.escape(mark.toString())
    return Regex("(?<=^|[\\s\\p{P}])$m(?=\\S)([^$m\\n]*?\\S)$m(?=$|[\\s\\p{P}])")
}

private val Marks = listOf('*', '_', '~', '`').associateWith { markPattern(it) }

/** The text cut into styled pieces, marks removed. */
fun parseChatText(body: String): List<ChatPiece> {
    val out = mutableListOf<ChatPiece>()
    var last = 0
    LinkPattern.findAll(body).forEach { link ->
        blocks(body.substring(last, link.range.first), out)
        out += ChatPiece(link.value, url = link.value)
        last = link.range.last + 1
    }
    blocks(body.substring(last), out)
    return out
}

/** The text as it reads, without marks (chat list preview, copy as plain text). */
fun plainChatText(body: String?): String = body?.let { parseChatText(it).joinToString("") { p -> p.text } }.orEmpty()

private fun blocks(text: String, out: MutableList<ChatPiece>) {
    var last = 0
    BlockPattern.findAll(text).forEach { m ->
        marks(text.substring(last, m.range.first), ChatPiece(""), Marks.keys, out)
        out += ChatPiece(m.groupValues[1], codeBlock = true)
        last = m.range.last + 1
    }
    marks(text.substring(last), ChatPiece(""), Marks.keys, out)
}

/** Earliest mark first, so an outer mark wins and its inside is formatted again (nesting). */
private fun marks(text: String, style: ChatPiece, allowed: Set<Char>, out: MutableList<ChatPiece>) {
    if (text.isEmpty()) return
    val first = allowed.mapNotNull { mark -> Marks.getValue(mark).find(text)?.let { mark to it } }.minByOrNull { it.second.range.first }
    if (first == null) {
        out += style.copy(text = text)
        return
    }
    val (mark, match) = first
    marks(text.substring(0, match.range.first), style, allowed, out)
    val inner = match.groupValues[1]
    if (mark == '`') {
        out += style.copy(text = inner, code = true)
    } else {
        val styled = when (mark) {
            '*' -> style.copy(bold = true)
            '_' -> style.copy(italic = true)
            else -> style.copy(strike = true)
        }
        marks(inner, styled, allowed - mark, out)
    }
    marks(text.substring(match.range.last + 1), style, allowed, out)
}
