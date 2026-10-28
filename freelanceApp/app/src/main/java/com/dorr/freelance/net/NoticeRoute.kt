package com.dorr.freelance.net

import com.dorr.freelance.navigation.Routes

/**
 * Where a notice leads: the screen of what it is about. The same answer serves a card in the notifications list and a push tapped in
 * the phone's tray. `null` means the notice has no screen of its own (it then opens its details, or the list).
 */
object NoticeRoute {
    /** @param event the notice's event (`freelance.offer.viewed`…); @param field a value of the notice's data (`project`, `dispute`…) */
    fun resolve(event: String?, field: (String) -> String?): String? {
        val e = event ?: return null
        val project = field("project")
        return when {
            e == "freelance.message" || e.startsWith("freelance.interview") -> field("conversation_uuid")?.let(Routes::chat)
            e.startsWith("wallet.") || e == "freelance.payment.available" -> Routes.WALLET
            e.startsWith("freelance.verification") -> Routes.VERIFICATION
            e.startsWith("freelance.skill") -> Routes.SKILLS
            e == "freelance.portfolio.hidden" -> Routes.PORTFOLIO
            e == "freelance.quota.low" -> Routes.MY_OFFERS
            e == "freelance.invitation.received" -> Routes.INVITATIONS
            e.startsWith("freelance.dispute") -> {
                val dispute = field("dispute")
                if (project != null && dispute != null) "dispute/$project/$dispute" else project?.let(Routes::agreement)
            }
            // The work itself: the agreement, its parts, their payments and changes.
            e.startsWith("freelance.milestone") || e.startsWith("freelance.report") || e.startsWith("freelance.change") || e.startsWith("freelance.payment") ||
                e == "freelance.agreement.received" || e == "freelance.project.completed" -> project?.let(Routes::agreement)
            // Before the work: the project and my offer on it.
            e.startsWith("freelance.offer") || e.startsWith("freelance.project") || e.startsWith("freelance.agreement") ||
                e.startsWith("freelance.review") -> project?.let(Routes::project)
            else -> null
        }
    }
}
