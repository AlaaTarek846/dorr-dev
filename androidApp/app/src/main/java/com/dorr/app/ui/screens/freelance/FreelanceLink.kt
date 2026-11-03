package com.dorr.app.ui.screens.freelance

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue

/**
 * A Freelance page to open when the Freelance screens show — set by a tapped notification (the in-app list or a push).
 * The value is the key of a page ([FlRoute]: "show:<project>", "offers:<project>", "agreement:<project>", …).
 */
object FreelanceLink {
    var pending by mutableStateOf<String?>(null)

    fun show(page: String) {
        pending = page
    }

    /**
     * The page a client's freelance notification belongs to. [event] is the delivered one ("freelance.offer.received"); the ids come
     * from the notification's data (`project`, `offer`, `dispute`). Without a project the hub opens.
     */
    fun pageFor(event: String?, project: String?, offer: String?, dispute: String?, consultation: String? = null, specialOffer: String? = null): String {
        val name = event?.removePrefix("freelance.").orEmpty()
        val group = name.substringBefore('.')
        // FL-L09: a special offer from a freelancer I worked with opens it (or my special offers).
        if (group == "special_offer") return specialOffer?.takeIf { it.isNotBlank() }?.let { "spo:$it" } ?: "spos"
        // FL-L09: a loyalty step unlocked opens "My discounts".
        if (group == "loyalty") return "loyalty"
        // FL-L07: a consultation has no project: it opens the booking (or my consultations).
        if (group == "consultation") return consultation?.takeIf { it.isNotBlank() }?.let { "consult:$it" } ?: "consults"
        if (project.isNullOrBlank()) return "home"
        return when (group) {
            "offer" -> if (!offer.isNullOrBlank()) "offer:$project:$offer" else "offers:$project"
            "interview" -> "offers:$project"
            "invitation" -> "invites:$project"
            "dispute" -> if (!dispute.isNullOrBlank()) "dispute:$project:$dispute" else "agreement:$project"
            // FL-L13: the rights of a part passed, or the freelancer asks to show a finished work: the agreement (the claim notice has no project).
            "agreement", "milestone", "change", "report", "review", "payment", "rights" -> "agreement:$project"
            "sanction" -> "home"
            else -> "show:$project"
        }
    }
}
