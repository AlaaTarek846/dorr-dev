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

    /** The freelance screens are open over the main screen. Held here, not in the main screen: that one is rebuilt when the notifications list closes, and a flag kept in it was lost. */
    var open by mutableStateOf(false)

    /** The open pages (see FlStack), also held here for the same reason: the notification that opened a page set it in the copy of the screen that was then replaced. */
    var stack by mutableStateOf("home")

    fun show(page: String) {
        pending = page
    }

    /**
     * The page a client's freelance notification belongs to. [event] is the delivered one ("freelance.offer.received"); the ids come
     * from the notification's data (`project`, `offer`, `dispute`). Without a project the hub opens.
     */
    fun pageFor(event: String?, project: String?, offer: String?, dispute: String?, consultation: String? = null, specialOffer: String? = null, service: String? = null): String {
        val name = event?.removePrefix("freelance.").orEmpty()
        val group = name.substringBefore('.')
        // FL-L09: a special offer from a freelancer I worked with opens it (or my special offers).
        if (group == "special_offer") return specialOffer?.takeIf { it.isNotBlank() }?.let { "spo:$it" } ?: "spos"
        // FL-L09: a loyalty step unlocked opens "My discounts".
        if (group == "loyalty") return "loyalty"
        // A monthly service: a renewal or a cancel request opens my orders; my turn on the waiting list opens the service.
        if (group == "retainer") return "orders"
        if (group == "waitlist") return service?.takeIf { it.isNotBlank() }?.let { "svc:$it" } ?: "market"
        // FL-L07: a consultation has no project: it opens the booking (or my consultations).
        if (group == "consultation") return consultation?.takeIf { it.isNotBlank() }?.let { "consult:$it" } ?: "consults"
        // A message whose room is not known (an older notice): my projects list, where each project shows its unread chat — not the hub.
        if (project.isNullOrBlank()) return if (group == "message") "list" else "home"
        return when (group) {
            // A message from the freelancer: their offer page (its chat button carries the unread count).
            "message" -> if (!offer.isNullOrBlank()) "offer:$project:$offer" else "offers:$project"
            "offer" -> if (!offer.isNullOrBlank()) "offer:$project:$offer" else "offers:$project"
            "interview" -> "offers:$project"
            "invitation" -> "invites:$project"
            "dispute" -> if (!dispute.isNullOrBlank()) "dispute:$project:$dispute" else "agreement:$project"
            // FL-L13: the rights of a part passed, or the freelancer asks to show a finished work: the agreement (the claim notice has no project).
            "agreement", "milestone", "change", "report", "review", "payment", "rights", "health" -> "agreement:$project"
            "sanction" -> "home"
            else -> "show:$project"
        }
    }
}
