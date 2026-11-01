package com.dorr.app.ui.screens.freelance

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue

/**
 * A Freelance page to open when the Freelance screens show — set by a tapped notification (the in-app list or a push).
 * The value is a page of [FreelanceHost] ("show:<project>", "offers:<project>", "agreement:<project>", …).
 */
object FreelanceLink {
    var pending by mutableStateOf<String?>(null)

    fun show(page: String) {
        pending = page
    }

    /**
     * The page a client's freelance notification belongs to. [event] is the delivered one ("freelance.offer.received"); the ids come
     * from the notification's data (`project`, `offer`, `dispute`). Without a project the list opens.
     */
    fun pageFor(event: String?, project: String?, offer: String?, dispute: String?): String {
        val name = event?.removePrefix("freelance.").orEmpty()
        val group = name.substringBefore('.')
        if (project.isNullOrBlank()) return "list"
        return when (group) {
            "offer" -> if (!offer.isNullOrBlank()) "offer:$project:$offer" else "offers:$project"
            "interview" -> "offers:$project"
            "invitation" -> "invites:$project"
            "dispute" -> if (!dispute.isNullOrBlank()) "dispute:$project:$dispute" else "agreement:$project"
            "agreement", "milestone", "change", "report", "review", "payment" -> "agreement:$project"
            "sanction" -> "list"
            else -> "show:$project"
        }
    }
}
